<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Service;

use Doctrine\DBAL\Exception\RetryableException;
use Ibexa\Contracts\Core\Repository\Repository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Wizhippo\IbexaContentScheduler\Domain\ScheduleException;
use Wizhippo\IbexaContentScheduler\Persistence\ScheduleStore;

final class ScheduleRunner
{
    public function __construct(
        private readonly ScheduleStore $store,
        private readonly Repository $repository,
        private readonly ScheduleService $service,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Batch limit must be between 1 and 500.');
        }
        $counts = ['completed' => 0, 'conflict' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
        foreach ($this->store->due($this->clock->now()->getTimestamp(), $limit) as $candidate) {
            try {
                $result = $this->store->locked((int) $candidate['content_id'], function () use ($candidate): string {
                    $row = $this->store->get($candidate['id']);
                    if (!$this->eligible($row, $candidate)) {
                        return 'skipped';
                    }
                    $permission = $this->repository->getPermissionResolver();
                    $previous = $permission->getCurrentUserReference();
                    try {
                        // Resolving an existing user needs no administrator impersonation.
                        $user = $this->repository->getUserService()->loadUser((int) $row['requested_by']);
                        if (!$user->enabled) {
                            throw new ScheduleException('The requesting account is disabled.');
                        }
                        $permission->setCurrentUserReference($user);
                        $languages = json_decode($row['languages'], true, flags: JSON_THROW_ON_ERROR);
                        $snapshot = $this->service->snapshot((int) $row['content_id'], $row['action'], (int) $row['version_no'], $languages);
                        if ($snapshot['fingerprint'] !== $row['fingerprint'] || $snapshot['baseline_version'] !== (int) $row['baseline_version']) {
                            throw new ScheduleException('Content, schema, or current publication changed. Reschedule to confirm the saved content.');
                        }
                        $contentService = $this->repository->getContentService();
                        if ($row['action'] === 'publish') {
                            $version = $contentService->loadVersionInfo($contentService->loadContentInfo((int) $row['content_id']), (int) $row['version_no']);
                            $contentService->publishVersion($version, $languages);
                        } elseif ($row['action'] === 'hide') {
                            $contentService->hideContent($contentService->loadContentInfo((int) $row['content_id']));
                        } else {
                            $contentService->revealContent($contentService->loadContentInfo((int) $row['content_id']));
                        }
                        $now = $this->clock->now()->getTimestamp();
                        $this->store->update($row, ['status' => 'completed', 'error' => '', 'attempts' => (int) $row['attempts'] + 1, 'updated_at' => $now]);
                        $this->store->audit($row, 'completed', (int) $row['requested_by'], [], $now);

                        return 'completed';
                    } finally {
                        $permission->setCurrentUserReference($previous);
                    }
                });
            } catch (\Throwable $error) {
                $retry = false;
                for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                    $retry = $retry || $cause instanceof RetryableException;
                }
                $result = $this->store->locked((int) $candidate['content_id'], function () use ($candidate, $error, $retry): string {
                    $row = $this->store->get($candidate['id']);
                    if (!$this->eligible($row, $candidate)) {
                        return 'skipped';
                    }
                    $attempts = (int) $row['attempts'] + 1;
                    $state = $error instanceof ScheduleException ? 'conflict' : 'failed';
                    if ($retry && $attempts < 3) {
                        $state = 'pending';
                    }
                    $now = $this->clock->now()->getTimestamp();
                    $message = $error instanceof ScheduleException ? $error->getMessage() : 'Execution failed. Check content access and validation; consult the scheduler log.';
                    $this->store->update($row, ['status' => $state, 'attempts' => $attempts, 'error' => $message, 'next_attempt_at' => $now + 60 * $attempts, 'updated_at' => $now]);
                    $this->store->audit($row, $state === 'pending' ? 'retry' : $state, (int) $row['requested_by'], ['error' => $message], $now);

                    return $state === 'pending' ? 'retry' : $state;
                });
                // No field values, exception messages, stack arguments, or credentials in logs.
                $this->logger->warning('Content schedule execution did not complete.', ['schedule_id' => $candidate['id'], 'exception_class' => $error::class, 'outcome' => $result]);
            }
            ++$counts[$result];
        }

        return $counts;
    }

    private function eligible(array $row, array $candidate): bool
    {
        $now = $this->clock->now()->getTimestamp();

        return $row['status'] === 'pending' && (int) $row['revision'] === (int) $candidate['revision']
            && (int) $row['run_at'] <= $now && (int) $row['next_attempt_at'] <= $now;
    }
}
