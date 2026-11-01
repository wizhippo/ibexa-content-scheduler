<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ibexa\Contracts\Core\Repository\Repository;
use Wizhippo\IbexaContentScheduler\Domain\ScheduleException;

final class ScheduleStore
{
    public function __construct(public readonly Connection $connection, private readonly ?Repository $repository = null)
    {
    }

    public function ensureLock(int $contentId): void
    {
        // Outside the transaction: a duplicate INSERT must not abort PostgreSQL's transaction.
        try {
            $this->connection->insert('wizhippo_schedule_lock', ['content_id' => $contentId, 'revision' => 0]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    /** Database row locks are held until commit and released automatically on crash/rollback. */
    public function locked(int $contentId, callable $operation): mixed
    {
        $lockedOperation = function () use ($contentId, $operation): mixed {
            if (1 !== $this->connection->executeStatement('UPDATE wizhippo_schedule_lock SET revision = revision + 1 WHERE content_id = ?', [$contentId])) {
                throw new \LogicException('Missing content scheduling lock.');
            }

            return $operation();
        };
        if ($this->repository === null) {
            return $this->connection->transactional($lockedOperation);
        }
        // Repository transactions coordinate both DBAL and Ibexa's persistence caches.
        // Using only a DBAL outer transaction would commit cache effects too early.
        $this->repository->beginTransaction();
        try {
            $result = $lockedOperation();
            $this->repository->commit();

            return $result;
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
    }

    public function get(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM wizhippo_schedule WHERE id = ?', [$id]);
        if ($row === false) {
            throw new ScheduleException('Schedule not found.');
        }

        return $row;
    }

    public function insert(array $row): void
    {
        $this->connection->insert('wizhippo_schedule', $row);
    }

    public function active(int $contentId, string $group): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT id FROM wizhippo_schedule WHERE content_id = ? AND status = 'pending' AND ".($group === 'publish' ? "action = 'publish'" : "action IN ('hide', 'reveal')"), [$contentId]
        );
    }

    public function update(array $row, array $changes): array
    {
        $changes['revision'] = (int) $row['revision'] + 1;
        if (1 !== $this->connection->update('wizhippo_schedule', $changes, ['id' => $row['id'], 'revision' => $row['revision']])) {
            throw new ScheduleException('This schedule changed. Reload it before continuing.');
        }

        return array_replace($row, $changes);
    }

    public function audit(array $row, string $event, int $actor, array $details, int $now): void
    {
        $this->connection->insert('wizhippo_schedule_audit', [
            'id' => bin2hex(random_bytes(16)), 'schedule_id' => $row['id'], 'event' => $event,
            'actor_id' => $actor, 'occurred_at' => $now,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    public function history(string $id): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM wizhippo_schedule_audit WHERE schedule_id = ? ORDER BY occurred_at, id', [$id]);
    }

    public function due(int $now, int $limit): array
    {
        return $this->connection->fetchAllAssociative($this->connection->getDatabasePlatform()->modifyLimitQuery(
            "SELECT * FROM wizhippo_schedule WHERE status = 'pending' AND run_at <= ? AND next_attempt_at <= ? ORDER BY run_at, created_at, id", $limit
        ), [$now, $now]);
    }

    /** Offset pagination remains bounded even when many rows are permission-filtered. */
    public function page(int $offset, ?int $owner, ?int $contentId, string $status): array
    {
        $conditions = ['1 = 1'];
        $params = [];
        foreach (['requested_by' => $owner, 'content_id' => $contentId, 'status' => $status !== '' ? $status : null] as $name => $value) {
            if ($value !== null) {
                $conditions[] = $name.' = ?';
                $params[] = $value;
            }
        }

        return $this->connection->fetchAllAssociative($this->connection->getDatabasePlatform()->modifyLimitQuery(
            'SELECT * FROM wizhippo_schedule WHERE '.implode(' AND ', $conditions).' ORDER BY created_at DESC, id', 25, $offset
        ), $params);
    }
}
