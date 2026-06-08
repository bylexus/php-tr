<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Queue\Db;

use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\Queue\QueueConfiguration;

final class SqlitePlatform extends AbstractDatabasePlatform {
    public function getName(): string {
        return 'sqlite';
    }

    public function formatDateTime(\DateTimeInterface $dateTime): string {
        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    public function supportsForUpdate(): bool {
        return false;
    }

    public function supportsSkipLocked(): bool {
        return false;
    }

    public function supportsInsertReturning(): bool {
        return true;
    }

    public function supportsUpdateReturning(): bool {
        return true;
    }

    public function validateConfiguration(QueueConfiguration $configuration): void {
        if ($configuration->getSchemaName() !== null) {
            throw new ConfigurationException('SQLite queue configuration does not support schema names.');
        }
    }

    public function tableExists(\PDO $connection, QueueConfiguration $configuration, string $tableName): bool {
        $statement = $connection->prepare(
            'SELECT EXISTS (SELECT 1 FROM sqlite_master WHERE type = :type AND name = :table_name)',
        );
        $statement->execute([
            'type' => 'table',
            'table_name' => $tableName,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function fetchColumnNames(\PDO $connection, QueueConfiguration $configuration, string $tableName): array {
        $statement = $connection->query(sprintf('PRAGMA table_info(%s)', $this->quoteIdentifier($tableName)));

        if (!$statement instanceof \PDOStatement) {
            return [];
        }

        $columnNames = [];

        while (true) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                break;
            }

            if (isset($row['name']) && is_string($row['name'])) {
                $columnNames[] = $row['name'];
            }
        }

        return $columnNames;
    }

    protected function queueTableStatement(QueueConfiguration $configuration): string {
        return sprintf(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS %s (
    task_id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_class TEXT NOT NULL,
    step_class TEXT NULL,
    task_status TEXT NOT NULL,
    priority INTEGER NOT NULL DEFAULT 3,
    task_created_at TEXT NOT NULL,
    task_started_at TEXT NULL,
    task_finished_at TEXT NULL,
    cleanup_at TEXT NULL,
    step_status TEXT NULL,
    step_attempt INTEGER NOT NULL DEFAULT 0,
    step_started_at TEXT NULL,
    step_finished_at TEXT NULL,
    payload_json TEXT NULL,
    result_json TEXT NULL,
    error_json TEXT NULL,
    available_at TEXT NOT NULL,
    claimed_at TEXT NULL,
    claimed_by TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    cancel_requested INTEGER NOT NULL DEFAULT 0,
    cancel_reason TEXT NULL,
    updated_at TEXT NOT NULL,
    log TEXT NULL
)
SQL,
            $this->queueTableName($configuration),
        );
    }

    protected function blobTableStatement(QueueConfiguration $configuration): string {
        return sprintf(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS %s (
    blob_id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    content BLOB NOT NULL,
    size_bytes INTEGER NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    created_at TEXT NOT NULL,
    CONSTRAINT %s FOREIGN KEY (task_id) REFERENCES %s (task_id) ON DELETE CASCADE
)
SQL,
            $this->blobTableName($configuration),
            $this->quoteIdentifier($this->derivedName($configuration, 'blob_task_id_fk')),
            $this->queueTableName($configuration),
        );
    }

    protected function priorityMigrationStatement(QueueConfiguration $configuration): ?string {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN priority INTEGER NOT NULL DEFAULT 3',
            $this->queueTableName($configuration),
        );
    }

    protected function fetchIndexNames(\PDO $connection, QueueConfiguration $configuration, string $tableName): array {
        $statement = $connection->query(sprintf('PRAGMA index_list(%s)', $this->quoteIdentifier($tableName)));

        if (!$statement instanceof \PDOStatement) {
            return [];
        }

        $indexNames = [];

        while (true) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                break;
            }

            if (isset($row['name']) && is_string($row['name'])) {
                $indexNames[] = $row['name'];
            }
        }

        return $indexNames;
    }
}
