<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Queue\Db;

use ByLexus\TaskRunner\Queue\QueueConfiguration;

class MySqlPlatform extends AbstractDatabasePlatform {
    public function getName(): string {
        return 'mysql';
    }

    public function formatDateTime(\DateTimeInterface $dateTime): string {
        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    public function quoteIdentifier(string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function queueTableStatement(QueueConfiguration $configuration): string {
        return sprintf(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS %s (
    task_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_class TEXT NOT NULL,
    step_class TEXT NULL,
    task_status VARCHAR(32) NOT NULL,
    priority INTEGER NOT NULL DEFAULT 3,
    task_created_at DATETIME(6) NOT NULL,
    task_started_at DATETIME(6) NULL,
    task_finished_at DATETIME(6) NULL,
    cleanup_at DATETIME(6) NULL,
    step_status VARCHAR(32) NULL,
    step_attempt INTEGER NOT NULL DEFAULT 0,
    step_started_at DATETIME(6) NULL,
    step_finished_at DATETIME(6) NULL,
    payload_json JSON NULL,
    result_json JSON NULL,
    error_json JSON NULL,
    available_at DATETIME(6) NOT NULL,
    claimed_at DATETIME(6) NULL,
    claimed_by TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    cancel_requested BOOLEAN NOT NULL DEFAULT FALSE,
    cancel_reason TEXT NULL,
    updated_at DATETIME(6) NOT NULL,
    log LONGTEXT NULL
) ENGINE=InnoDB
SQL,
            $this->queueTableName($configuration),
        );
    }

    protected function blobTableStatement(QueueConfiguration $configuration): string {
        return sprintf(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS %s (
    blob_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT NOT NULL,
    content LONGBLOB NOT NULL,
    size_bytes BIGINT NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    CONSTRAINT %s FOREIGN KEY (task_id) REFERENCES %s (task_id) ON DELETE CASCADE
) ENGINE=InnoDB
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

    protected function logMigrationStatement(QueueConfiguration $configuration): ?string {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN log LONGTEXT NULL',
            $this->queueTableName($configuration),
        );
    }

    public function appendLogExpression(string $parameterName): string {
        return sprintf('log = CONCAT(COALESCE(log, \'\'), :%s)', $parameterName);
    }

    protected function supportsCreateIndexIfNotExists(): bool {
        return false;
    }

    protected function defaultNamespaceExpression(\ByLexus\TaskRunner\Queue\QueueConfiguration $configuration): string {
        return $configuration->getSchemaName() === null ? 'DATABASE()' : ':schema_name';
    }

    protected function defaultNamespaceName(): string {
        return '';
    }
}
