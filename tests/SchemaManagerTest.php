<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\Queue\SchemaManager;
use ByLexus\TaskRunner\TaskEnvironment;
use PHPUnit\Framework\TestCase;

final class SchemaManagerTest extends TestCase
{
    public function testExportDdlUsesConfiguredTableNameAndIncludesCleanupColumn(): void {
        $ddl = (new SchemaManager(
            $this->createTaskEnvironment($this->mockPdo('pgsql'), new QueueConfiguration('custom_queue')),
        ))
            ->exportDdl();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "custom_queue"', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "custom_queue_blob_data"', $ddl);
        self::assertStringContainsString('cleanup_at TIMESTAMPTZ NULL', $ddl);
        self::assertStringContainsString('priority INTEGER NOT NULL DEFAULT 3', $ddl);
        self::assertStringContainsString('payload_json JSONB NULL', $ddl);
        self::assertStringContainsString('content BYTEA NOT NULL', $ddl);
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS "custom_queue_cleanup_at_idx"', $ddl);
        self::assertStringContainsString(
            'CREATE INDEX IF NOT EXISTS "custom_queue_task_status_available_at_idx"'
            . ' ON "custom_queue" (task_status, priority, available_at, task_created_at)',
            $ddl,
        );
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS "custom_queue_blob_task_id_idx"', $ddl);
        self::assertStringNotContainsString('ALTER TABLE', $ddl);
    }

    public function testConstructingSchemaManagerDoesNotExecuteBootstrap(): void {
        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exec'])
            ->getMock();
        $pdo->expects(self::never())->method('exec');

        $schemaManager = new SchemaManager(
            $this->createTaskEnvironment($pdo, new QueueConfiguration('custom_queue')),
        );

        self::assertInstanceOf(SchemaManager::class, $schemaManager);
    }

    public function testExportDdlUsesConfiguredSchemaWhenProvided(): void {
        $ddl = (new SchemaManager(
            $this->createTaskEnvironment($this->mockPdo('pgsql'), new QueueConfiguration('custom_queue', 'custom_schema')),
        ))->exportDdl();

        self::assertStringContainsString('CREATE SCHEMA IF NOT EXISTS "custom_schema"', $ddl);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS "custom_schema"."custom_queue"',
            $ddl,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS "custom_schema"."custom_queue_blob_data"',
            $ddl,
        );
        self::assertStringNotContainsString('ALTER TABLE', $ddl);
    }

    public function testExportDdlUsesMysqlSyntaxAndConfiguredDatabaseWhenProvided(): void {
        $ddl = (new SchemaManager(
            $this->createTaskEnvironment(
                $this->mockPdo('mysql', '8.4.5'),
                new QueueConfiguration('custom_queue', 'custom_app'),
            ),
        ))->exportDdl();

        self::assertStringNotContainsString('CREATE SCHEMA', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `custom_app`.`custom_queue`', $ddl);
        self::assertStringContainsString('task_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY', $ddl);
        self::assertStringContainsString('payload_json JSON NULL', $ddl);
        self::assertStringContainsString('content LONGBLOB NOT NULL', $ddl);
        self::assertStringContainsString('CREATE INDEX `custom_queue_cleanup_at_idx`', $ddl);
        self::assertStringNotContainsString('CREATE INDEX IF NOT EXISTS', $ddl);
        self::assertStringNotContainsString('ALTER TABLE', $ddl);
    }

    public function testExportDdlUsesSqliteCompatibleTypes(): void {
        $ddl = (new SchemaManager(
            $this->createTaskEnvironment($this->mockPdo('sqlite'), new QueueConfiguration('custom_queue')),
        ))->exportDdl();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "custom_queue"', $ddl);
        self::assertStringContainsString('task_id INTEGER PRIMARY KEY AUTOINCREMENT', $ddl);
        self::assertStringContainsString('payload_json TEXT NULL', $ddl);
        self::assertStringContainsString('content BLOB NOT NULL', $ddl);
        self::assertStringContainsString('cancel_requested INTEGER NOT NULL DEFAULT 0', $ddl);
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS "custom_queue_cleanup_at_idx"', $ddl);
        self::assertStringNotContainsString('ALTER TABLE', $ddl);
    }

    public function testBootstrapCreatesAndValidatesSqliteSchema(): void {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is not available.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $schemaManager = new SchemaManager(
            $this->createTaskEnvironment($pdo, new QueueConfiguration('custom_queue')),
        );

        $schemaManager->bootstrap();

        self::assertTrue($schemaManager->tableExists());
        self::assertTrue($schemaManager->blobTableExists());
    }

    private function mockPdo(string $driverName, ?string $serverVersion = null): \PDO {
        $pdo = $this->createStub(\PDO::class);

        $pdo->method('getAttribute')->willReturnCallback(
            static function (int $attribute) use ($driverName, $serverVersion): mixed {
                return match ($attribute) {
                    \PDO::ATTR_DRIVER_NAME => $driverName,
                    \PDO::ATTR_SERVER_VERSION => $serverVersion,
                    default => null,
                };
            },
        );

        return $pdo;
    }

    private function createTaskEnvironment(\PDO $pdo, ?QueueConfiguration $configuration = null): TaskEnvironment {
        return new TaskEnvironment($pdo, $configuration);
    }
}
