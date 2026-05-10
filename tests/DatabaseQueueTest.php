<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\QueueContext;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowTaskFixture;
use PHPUnit\Framework\TestCase;

final class DatabaseQueueTest extends TestCase
{
    public function testNotificationChannelStaysWithinPostgresLimit(): void {
        $queue = new DatabaseQueue(
            $this->createStub(\PDO::class),
            new QueueConfiguration(str_repeat('table_name_', 8), str_repeat('schema_name_', 6)),
        );

        self::assertLessThanOrEqual(63, strlen($queue->getNotificationChannel()));
    }

    public function testNotificationChannelRemainsDistinctForLongSimilarConfigurations(): void {
        $firstQueue = new DatabaseQueue(
            $this->createStub(\PDO::class),
            new QueueConfiguration(
                'customer_background_jobs_for_important_process_variant_alpha_suffix',
                'customer_installation_with_a_really_long_schema_name_segment',
            ),
        );
        $secondQueue = new DatabaseQueue(
            $this->createStub(\PDO::class),
            new QueueConfiguration(
                'customer_background_jobs_for_important_process_variant_beta_suffix',
                'customer_installation_with_a_really_long_schema_name_segment',
            ),
        );

        self::assertLessThanOrEqual(63, strlen($firstQueue->getNotificationChannel()));
        self::assertLessThanOrEqual(63, strlen($secondQueue->getNotificationChannel()));
        self::assertNotSame($firstQueue->getNotificationChannel(), $secondQueue->getNotificationChannel());
    }

    public function testClaimSelectSqlPlacesLimitBeforeSkipLockedForMysql(): void {
        $queue = new DatabaseQueue(
            $this->stubPdoWithDriver('mysql', '8.4.5'),
            new QueueConfiguration('phptr_task_queue', 'tr_test'),
        );

        /** @var string $sql */
        $sql = $this->invokePrivateMethod($queue, 'claimSelectSql', true);

        self::assertStringContainsString('FROM `tr_test`.`phptr_task_queue`', $sql);
        self::assertStringContainsString("LIMIT 1\nFOR UPDATE SKIP LOCKED", $sql);
        self::assertStringNotContainsString("FOR UPDATE SKIP LOCKED\nLIMIT 1", $sql);
    }

    public function testRunningTaskSelectSqlQualifiesMysqlCatalogAndUsesSkipLocked(): void {
        $queue = new DatabaseQueue(
            $this->stubPdoWithDriver('mysql', '8.4.5'),
            new QueueConfiguration('phptr_task_queue', 'tr_test'),
        );

        /** @var string $sql */
        $sql = $this->invokePrivateMethod($queue, 'runningTaskSelectSql', "\n  AND claimed_by = :claimed_by");

        self::assertStringContainsString('FROM `tr_test`.`phptr_task_queue`', $sql);
        self::assertStringContainsString('AND claimed_by = :claimed_by', $sql);
        self::assertStringContainsString("\nFOR UPDATE SKIP LOCKED", $sql);
    }

    public function testEnqueueCommitsSuccessfullyOnSqlite(): void {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is not available.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $configuration = new QueueConfiguration('task_queue');
        $queueContext = new QueueContext($pdo, $configuration);

        $queueContext->getSchemaManager()->bootstrap();

        $queue = new DatabaseQueue($pdo, $configuration);
        $task = new QueueWorkflowTaskFixture();
        $record = $queueContext->enqueue($task);

        self::assertNotNull($record->taskId);
        self::assertTrue($pdo->query('SELECT COUNT(*) FROM "task_queue"') instanceof \PDOStatement);
        self::assertSame($record->taskId, $queue->get((int) $record->taskId)->taskId);
    }

    public function testRunningTaskSelectorsReturnRunningRecords(): void {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is not available.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $configuration = new QueueConfiguration('task_queue');
        $queueContext = new QueueContext($pdo, $configuration);

        $queueContext->getSchemaManager()->bootstrap();

        $queue = new DatabaseQueue($pdo, $configuration);
        $task = new QueueWorkflowTaskFixture();
        $record = $queueContext->enqueue($task);
        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $pdo->beginTransaction();
        $queue->update((int) $record->taskId, [
            'task_status' => TaskStatus::RUNNING,
            'step_status' => StepStatus::RUNNING,
            'task_started_at' => $startedAt,
            'step_started_at' => $startedAt,
            'claimed_at' => $startedAt,
            'claimed_by' => 'runner-1',
        ]);
        $pdo->commit();

        $pdo->beginTransaction();
        $startedRunning = $queue->findStartedRunningTasks();
        $claimedRunning = $queue->findClaimedRunningTasks('runner-1');
        $pdo->rollBack();

        self::assertCount(1, $startedRunning);
        self::assertCount(1, $claimedRunning);
        self::assertSame($record->taskId, $startedRunning[0]->taskId);
        self::assertSame($record->taskId, $claimedRunning[0]->taskId);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed {
        $reflection = new \ReflectionMethod($object, $methodName);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function stubPdoWithDriver(string $driverName, ?string $serverVersion = null): \PDO {
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
}
