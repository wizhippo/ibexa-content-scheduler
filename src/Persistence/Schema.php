<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema as DbalSchema;

final class Schema
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function definition(): DbalSchema
    {
        $schema = new DbalSchema();
        $lock = $schema->createTable('wizhippo_schedule_lock');
        $lock->addColumn('content_id', 'integer');
        $lock->addColumn('revision', 'integer');
        $lock->setPrimaryKey(['content_id']);

        $table = $schema->createTable('wizhippo_schedule');
        foreach (['id' => 32, 'action' => 10, 'status' => 12, 'timezone' => 64, 'fingerprint' => 64] as $name => $length) {
            $table->addColumn($name, 'string', ['length' => $length]);
        }
        foreach (['content_id', 'version_no', 'baseline_version', 'requested_by', 'revision', 'attempts'] as $name) {
            $table->addColumn($name, 'integer');
        }
        foreach (['run_at', 'next_attempt_at', 'created_at', 'updated_at'] as $name) {
            $table->addColumn($name, 'bigint');
        }
        $table->addColumn('languages', 'text');
        $table->addColumn('error', 'string', ['length' => 255, 'default' => '']);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status', 'next_attempt_at', 'run_at'], 'wizhippo_schedule_due');
        $table->addIndex(['content_id', 'status'], 'wizhippo_schedule_content');
        $table->addIndex(['requested_by', 'run_at'], 'wizhippo_schedule_requester');

        $audit = $schema->createTable('wizhippo_schedule_audit');
        $audit->addColumn('id', 'string', ['length' => 32]);
        $audit->addColumn('schedule_id', 'string', ['length' => 32]);
        $audit->addColumn('event', 'string', ['length' => 32]);
        $audit->addColumn('actor_id', 'integer');
        $audit->addColumn('occurred_at', 'bigint');
        $audit->addColumn('details', 'text');
        $audit->setPrimaryKey(['id']);
        $audit->addIndex(['schedule_id', 'occurred_at'], 'wizhippo_schedule_audit_event');

        return $schema;
    }

    /** Only creates missing bundle tables; no core or application schema changes. */
    public function install(bool $execute): array
    {
        $existing = $this->connection->createSchemaManager()->listTableNames();
        $sql = [];
        foreach (self::definition()->getTables() as $table) {
            if (!in_array($table->getName(), $existing, true)) {
                array_push($sql, ...$this->connection->getDatabasePlatform()->getCreateTableSQL($table));
                continue;
            }
            // Recover an interrupted initial install that created a table before its indexes.
            $indexes = $this->connection->createSchemaManager()->listTableIndexes($table->getName());
            foreach ($table->getIndexes() as $index) {
                if (!$index->isPrimary() && !isset($indexes[strtolower($index->getName())])) {
                    $sql[] = $this->connection->getDatabasePlatform()->getCreateIndexSQL($index, $table->getName());
                }
            }
        }
        if ($execute) {
            foreach ($sql as $statement) {
                $this->connection->executeStatement($statement);
            }
        }

        return $sql;
    }
}
