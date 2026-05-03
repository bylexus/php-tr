<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Queue;

/**
 * Configures the queue table.
 *
 * Defines the PostgreSQL table name and related settings used by the durable task queue.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class QueueConfiguration
{
    public const DEFAULT_TABLE_NAME = 'durable_task_queue';

    private string $tableName;

    public function __construct(string $tableName = self::DEFAULT_TABLE_NAME) {
        $this->tableName = $tableName;
    }

    public function getTableName(): string {
        return $this->tableName;
    }
}
