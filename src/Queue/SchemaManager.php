<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Queue;

use ByLexus\DurableTask\Exception\ConfigurationException;

/**
 * Manages the queue schema.
 *
 * Creates and validates the PostgreSQL schema required by the durable task queue.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class SchemaManager {
    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'task_id',
        'task_class',
        'step_class',
        'task_status',
        'task_attempt',
        'priority',
        'task_created_at',
        'task_started_at',
        'task_finished_at',
        'cleanup_at',
        'step_status',
        'step_attempt',
        'step_started_at',
        'step_finished_at',
        'payload_json',
        'result_json',
        'error_json',
        'available_at',
        'claimed_at',
        'claimed_by',
        'last_error_code',
        'last_error_message',
        'cancel_requested',
        'cancel_reason',
        'updated_at',
    ];

    /** @var list<string> */
    private const REQUIRED_BLOB_COLUMNS = [
        'blob_id',
        'task_id',
        'content',
        'size_bytes',
        'sha256',
        'created_at',
    ];

    private \PDO $connection;
    private QueueConfiguration $configuration;

    public function __construct(\PDO $connection, ?QueueConfiguration $configuration = null) {
        $this->connection = $connection;
        $this->configuration = $configuration ?? new QueueConfiguration();
    }

    /** @return list<string> */
    private static function bootstrapStatements(?QueueConfiguration $configuration = null): array {
        $configuration ??= new QueueConfiguration();

        return array_merge(
            [
                self::tableStatement($configuration),
                self::priorityMigrationStatement($configuration),
                self::blobTableStatement($configuration),
            ],
            self::indexStatementsFor($configuration),
        );
    }

    public static function exportDdl(?QueueConfiguration $configuration = null): string {
        return implode(";\n\n", self::bootstrapStatements($configuration)) . ";\n";
    }

    public function bootstrap(): void {
        foreach (self::bootstrapStatements($this->configuration) as $statement) {
            $this->connection->exec($statement);
        }

        $this->validate();
    }

    public function validate(): void {
        $columns = $this->fetchColumnNames($this->configuration->getTableName());
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));

        if ($missingColumns !== []) {
            throw new ConfigurationException(
                sprintf(
                    'Queue table %s is missing required columns: %s',
                    $this->configuration->getTableName(),
                    implode(', ', $missingColumns),
                ),
            );
        }

        $blobColumns = $this->fetchColumnNames($this->configuration->getBlobTableName());
        $missingBlobColumns = array_values(array_diff(self::REQUIRED_BLOB_COLUMNS, $blobColumns));

        if ($missingBlobColumns !== []) {
            throw new ConfigurationException(
                sprintf(
                    'Attachment blob table %s is missing required columns: %s',
                    $this->configuration->getBlobTableName(),
                    implode(', ', $missingBlobColumns),
                ),
            );
        }
    }

    public function tableExists(): bool {
        return $this->schemaTableExists($this->configuration->getTableName());
    }

    public function blobTableExists(): bool {
        return $this->schemaTableExists($this->configuration->getBlobTableName());
    }

    private function schemaTableExists(string $tableName): bool {
        $statement = $this->connection->prepare(
            'SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = current_schema()
                  AND table_name = :table_name
            )',
        );
        $statement->execute(['table_name' => $tableName]);

        return (bool) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function fetchColumnNames(string $tableName): array {
        $statement = $this->connection->prepare(
            'SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :table_name',
        );
        $statement->execute(['table_name' => $tableName]);

        /** @var list<string> $columnNames */
        $columnNames = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $columnNames;
    }

    private static function tableStatement(QueueConfiguration $configuration): string {
        return sprintf(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS %s (
    task_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_class TEXT NOT NULL,
    step_class TEXT NULL,
    task_status TEXT NOT NULL,
    task_attempt INTEGER NOT NULL DEFAULT 0,
    priority INTEGER NOT NULL DEFAULT 3,
    task_created_at TIMESTAMPTZ NOT NULL,
    task_started_at TIMESTAMPTZ NULL,
    task_finished_at TIMESTAMPTZ NULL,
    cleanup_at TIMESTAMPTZ NULL,
    step_status TEXT NULL,
    step_attempt INTEGER NOT NULL DEFAULT 0,
    step_started_at TIMESTAMPTZ NULL,
    step_finished_at TIMESTAMPTZ NULL,
    payload_json JSONB NULL,
    result_json JSONB NULL,
    error_json JSONB NULL,
    available_at TIMESTAMPTZ NOT NULL,
    claimed_at TIMESTAMPTZ NULL,
    claimed_by TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    cancel_requested BOOLEAN NOT NULL DEFAULT FALSE,
    cancel_reason TEXT NULL,
    updated_at TIMESTAMPTZ NOT NULL
)
SQL,
            self::quotedTableName($configuration),
        );
    }

    private static function priorityMigrationStatement(QueueConfiguration $configuration): string {
        return sprintf(
            'ALTER TABLE %s ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 3',
            self::quotedTableName($configuration),
        );
    }

    private static function blobTableStatement(QueueConfiguration $configuration): string {
        return sprintf(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS %s (
    blob_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_id BIGINT NOT NULL,
    content BYTEA NOT NULL,
    size_bytes BIGINT NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT %s FOREIGN KEY (task_id) REFERENCES %s (task_id) ON DELETE CASCADE
)
SQL,
            self::quotedBlobTableName($configuration),
            self::quotedIdentifier(self::derivedName($configuration, 'blob_task_id_fk')),
            self::quotedTableName($configuration),
        );
    }

    /** @return list<string> */
    private static function indexStatementsFor(QueueConfiguration $configuration): array {
        $tableName = self::quotedTableName($configuration);

        return [
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (task_status, priority, available_at, task_created_at)',
                self::quotedIdentifier(self::derivedName($configuration, 'task_status_available_at_idx')),
                $tableName,
            ),
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (cleanup_at)',
                self::quotedIdentifier(self::derivedName($configuration, 'cleanup_at_idx')),
                $tableName,
            ),
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (claimed_at)',
                self::quotedIdentifier(self::derivedName($configuration, 'claimed_at_idx')),
                $tableName,
            ),
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (updated_at)',
                self::quotedIdentifier(self::derivedName($configuration, 'updated_at_idx')),
                $tableName,
            ),
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (task_id)',
                self::quotedIdentifier(self::derivedName($configuration, 'blob_task_id_idx')),
                self::quotedBlobTableName($configuration),
            ),
        ];
    }

    private static function quotedTableName(QueueConfiguration $configuration): string {
        return self::quotedIdentifier($configuration->getTableName());
    }

    private static function quotedBlobTableName(QueueConfiguration $configuration): string {
        return self::quotedIdentifier($configuration->getBlobTableName());
    }

    private static function derivedName(QueueConfiguration $configuration, string $suffix): string {
        $sanitizedTableName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $configuration->getTableName()) ?? 'queue';

        return sprintf('%s_%s', trim($sanitizedTableName, '_'), $suffix);
    }

    private static function quotedIdentifier(string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
