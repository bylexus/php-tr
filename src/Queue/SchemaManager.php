<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Queue;

use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatform;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatformResolver;
use ByLexus\TaskRunner\TaskEnvironment;

/**
 * Manages the queue schema.
 *
 * Creates and validates the queue schema required by the task runner.
 *
 * This file is part of bylexus/php-tr
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

    private TaskEnvironment $taskEnvironment;
    private DatabasePlatform $platform;

    public function __construct(TaskEnvironment $taskEnvironment) {
        $this->taskEnvironment = $taskEnvironment;
        $this->platform = DatabasePlatformResolver::resolve($this->taskEnvironment->getConnection());
        $this->platform->validateConfiguration($this->taskEnvironment->getQueueConfiguration());
    }

    /** @return list<string> */
    private function bootstrapStatements(): array {
        return $this->platform->bootstrapSchemaStatements(
            $this->taskEnvironment->getConnection(),
            $this->taskEnvironment->getQueueConfiguration(),
        );
    }

    /** @return list<string> */
    private function exportStatements(): array {
        return $this->platform->exportSchemaStatements($this->taskEnvironment->getQueueConfiguration());
    }

    public function exportDdl(): string {
        return implode(";\n\n", $this->exportStatements()) . ";\n";
    }

    public function bootstrap(): void {
        foreach ($this->bootstrapStatements() as $statement) {
            $this->taskEnvironment->getConnection()->exec($statement);
        }

        $this->validate();
    }

    public function validate(): void {
        $columns = $this->fetchColumnNames($this->taskEnvironment->getQueueConfiguration()->getTableName());
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));

        if ($missingColumns !== []) {
            throw new ConfigurationException(
                sprintf(
                    'Queue table %s is missing required columns: %s',
                    $this->taskEnvironment->getQueueConfiguration()->getTableName(),
                    implode(', ', $missingColumns),
                ),
            );
        }

        $blobColumns = $this->fetchColumnNames($this->taskEnvironment->getQueueConfiguration()->getBlobTableName());
        $missingBlobColumns = array_values(array_diff(self::REQUIRED_BLOB_COLUMNS, $blobColumns));

        if ($missingBlobColumns !== []) {
            throw new ConfigurationException(
                sprintf(
                    'Attachment blob table %s is missing required columns: %s',
                    $this->taskEnvironment->getQueueConfiguration()->getBlobTableName(),
                    implode(', ', $missingBlobColumns),
                ),
            );
        }
    }

    public function tableExists(): bool {
        return $this->schemaTableExists($this->taskEnvironment->getQueueConfiguration()->getTableName());
    }

    public function blobTableExists(): bool {
        return $this->schemaTableExists($this->taskEnvironment->getQueueConfiguration()->getBlobTableName());
    }

    private function schemaTableExists(string $tableName): bool {
        return $this->platform->tableExists(
            $this->taskEnvironment->getConnection(),
            $this->taskEnvironment->getQueueConfiguration(),
            $tableName,
        );
    }

    /** @return list<string> */
    private function fetchColumnNames(string $tableName): array {
        return $this->platform->fetchColumnNames(
            $this->taskEnvironment->getConnection(),
            $this->taskEnvironment->getQueueConfiguration(),
            $tableName,
        );
    }
}
