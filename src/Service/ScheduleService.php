<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Service;

use Ibexa\Contracts\Core\Repository\Repository;
use Psr\Clock\ClockInterface;
use Wizhippo\IbexaContentScheduler\Domain\ScheduleException;
use Wizhippo\IbexaContentScheduler\Persistence\ScheduleStore;

final class ScheduleService
{
    public function __construct(
        private readonly ScheduleStore $store,
        private readonly Repository $repository,
        private readonly ContentAccess $access,
        private readonly Fingerprint $fingerprint,
        private readonly ClockInterface $clock,
    ) {
    }

    public function snapshot(int $contentId, string $action, int $version, array $languages): array
    {
        if (!in_array($action, ['publish', 'hide', 'reveal'], true)) {
            throw new ScheduleException('Unknown scheduling action.');
        }
        if ($action === 'publish' && ($languages === [] || count($languages) > 50
            || count(array_filter($languages, static fn (mixed $language): bool => is_string($language) && strlen($language) <= 32)) !== count($languages))) {
            throw new ScheduleException('Choose valid draft translations.');
        }
        $content = $this->access->read($contentId, $action === 'publish' ? $version : 0);
        $info = $this->repository->getContentService()->loadContentInfo($contentId);
        $this->access->authorize($content, $action, $languages);
        if ($action === 'publish') {
            if ($version < 1 || !$content->getVersionInfo()->isDraft()) {
                throw new ScheduleException('Choose a saved draft version.');
            }
            if (count($languages) !== count(array_unique($languages))
                || array_diff($languages, $content->getVersionInfo()->getLanguageCodes()) !== []) {
                throw new ScheduleException('Choose translations present in this draft.');
            }
            if ($this->repository->getContentService()->validate($content->getVersionInfo(), ['content' => $content, 'translations' => $languages]) !== []) {
                throw new ScheduleException('The draft must pass publication validation before scheduling.');
            }
            $hash = $this->fingerprint->of($content);
        } else {
            if (!$info->isPublished()) {
                throw new ScheduleException('Visibility scheduling requires published content.');
            }
            if (($action === 'hide' && $info->isHidden) || ($action === 'reveal' && !$info->isHidden)) {
                throw new ScheduleException('The content already has the requested visibility.');
            }
            $hash = $info->isHidden ? 'hidden' : 'visible';
        }

        return ['fingerprint' => $hash, 'baseline_version' => $action === 'publish' && $info->isPublished() ? (int) $info->currentVersionNo : 0];
    }

    public function create(int $contentId, string $action, int $version, array $languages, \DateTimeImmutable $time, string $timezone): string
    {
        $this->future($time, $timezone);
        $this->store->ensureLock($contentId);

        return $this->store->locked($contentId, function () use ($contentId, $action, $version, $languages, $time, $timezone): string {
            $snapshot = $this->snapshot($contentId, $action, $version, $languages);
            if ($this->store->active($contentId, $action)) {
                throw new ScheduleException('An action of this kind is already scheduled for this content. Reschedule or cancel it first.');
            }
            $now = $this->clock->now()->getTimestamp();
            $row = $snapshot + [
                'id' => bin2hex(random_bytes(16)), 'content_id' => $contentId, 'action' => $action,
                'version_no' => $action === 'publish' ? $version : 0,
                'languages' => json_encode($action === 'publish' ? array_values($languages) : [], JSON_THROW_ON_ERROR),
                'requested_by' => $this->access->actor(), 'run_at' => $time->getTimestamp(),
                'next_attempt_at' => $time->getTimestamp(), 'timezone' => $timezone,
                'status' => 'pending', 'revision' => 1, 'attempts' => 0, 'error' => '',
                'created_at' => $now, 'updated_at' => $now,
            ];
            $this->store->insert($row);
            $this->store->audit($row, 'created', $this->access->actor(), ['run_at' => $row['run_at'], 'timezone' => $timezone, 'action' => $action, 'version' => $row['version_no'], 'languages' => $languages, 'baseline_version' => $snapshot['baseline_version'], 'confirmed_fingerprint' => $snapshot['fingerprint']], $now);

            return $row['id'];
        });
    }

    /** Rescheduling explicitly confirms the currently saved version and publication baseline. */
    public function reschedule(string $id, int $revision, \DateTimeImmutable $time, string $timezone): void
    {
        $this->future($time, $timezone);
        $initial = $this->store->get($id);
        $this->store->locked((int) $initial['content_id'], function () use ($id, $revision, $time, $timezone): void {
            $row = $this->store->get($id);
            $this->access->manage($row);
            $this->revision($row, $revision);
            if (!in_array($row['status'], ['pending', 'failed', 'conflict'], true)) {
                throw new ScheduleException('Only pending, failed, or conflicting schedules can be rescheduled.');
            }
            if ($row['status'] !== 'pending' && $this->store->active((int) $row['content_id'], $row['action'])) {
                throw new ScheduleException('Another action of this kind is already scheduled.');
            }
            $snapshot = $this->snapshot((int) $row['content_id'], $row['action'], (int) $row['version_no'], json_decode($row['languages'], true, flags: JSON_THROW_ON_ERROR));
            $now = $this->clock->now()->getTimestamp();
            $this->store->update($row, $snapshot + ['run_at' => $time->getTimestamp(), 'next_attempt_at' => $time->getTimestamp(), 'timezone' => $timezone, 'status' => 'pending', 'attempts' => 0, 'error' => '', 'updated_at' => $now]);
            $this->store->audit($row, 'rescheduled', $this->access->actor(), ['old_run_at' => (int) $row['run_at'], 'run_at' => $time->getTimestamp(), 'timezone' => $timezone, 'confirmed_fingerprint' => $snapshot['fingerprint'], 'baseline_version' => $snapshot['baseline_version']], $now);
        });
    }

    public function cancel(string $id, int $revision): void
    {
        $initial = $this->store->get($id);
        $this->store->locked((int) $initial['content_id'], function () use ($id, $revision): void {
            $row = $this->store->get($id);
            $this->access->manage($row);
            $this->revision($row, $revision);
            if (!in_array($row['status'], ['pending', 'failed', 'conflict'], true)) {
                throw new ScheduleException('This schedule can no longer be cancelled.');
            }
            $now = $this->clock->now()->getTimestamp();
            $this->store->update($row, ['status' => 'cancelled', 'updated_at' => $now]);
            $this->store->audit($row, 'cancelled', $this->access->actor(), [], $now);
        });
    }

    private function revision(array $row, int $revision): void
    {
        if ((int) $row['revision'] !== $revision) {
            throw new ScheduleException('This schedule changed. Reload it before continuing.');
        }
    }

    private function future(\DateTimeImmutable $time, string $timezone): void
    {
        new \DateTimeZone($timezone);
        if ($time <= $this->clock->now()) {
            throw new ScheduleException('Choose a future time.');
        }
    }
}
