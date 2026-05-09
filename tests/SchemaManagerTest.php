<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Queue\QueueConfiguration;
use ByLexus\DurableTask\Queue\SchemaManager;
use PHPUnit\Framework\TestCase;

final class SchemaManagerTest extends TestCase
{
    public function testExportDdlUsesConfiguredTableNameAndIncludesCleanupColumn(): void {
        $ddl = SchemaManager::exportDdl(new QueueConfiguration('custom_queue'));

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "custom_queue"', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "custom_queue_blob_data"', $ddl);
        self::assertStringContainsString('cleanup_at TIMESTAMPTZ NULL', $ddl);
        self::assertStringContainsString('priority INTEGER NOT NULL DEFAULT 3', $ddl);
        self::assertStringContainsString('payload_json JSONB NULL', $ddl);
        self::assertStringContainsString('content BYTEA NOT NULL', $ddl);
        self::assertStringContainsString(
            'ALTER TABLE "custom_queue" ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 3',
            $ddl,
        );
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS "custom_queue_cleanup_at_idx"', $ddl);
        self::assertStringContainsString(
            'CREATE INDEX IF NOT EXISTS "custom_queue_task_status_available_at_idx"'
            . ' ON "custom_queue" (task_status, priority, available_at, task_created_at)',
            $ddl,
        );
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS "custom_queue_blob_task_id_idx"', $ddl);
    }

    public function testConstructingSchemaManagerDoesNotExecuteBootstrap(): void {
        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exec'])
            ->getMock();
        $pdo->expects(self::never())->method('exec');

        $schemaManager = new SchemaManager($pdo, new QueueConfiguration('custom_queue'));

        self::assertInstanceOf(SchemaManager::class, $schemaManager);
    }
}
