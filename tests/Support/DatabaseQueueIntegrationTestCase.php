<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Support;

use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\FileAttachment;
use ByLexus\TaskRunner\Exception\QueueException;
use ByLexus\TaskRunner\Queue\AttachmentBlobStore;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowTaskFixture;

abstract class DatabaseQueueIntegrationTestCase extends AbstractDatabaseIntegrationTestCase
{
    public function testEnqueueCreatesQueuedRecordAndEmitsNotification(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        DatabaseIntegrationConnection::requireNotificationSupport($this, $pdo);
        $listener = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $queue = new DatabaseQueue($pdo, $configuration);
            $listener->exec(sprintf('LISTEN "%s"', $queue->getNotificationChannel()));

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'alpha']);
            $record = $task->enqueue($taskEnvironment);

            self::assertNotNull($record->taskId);
            self::assertSame(Task::PRIO_NORMAL, $record->priority);
            self::assertSame(TaskStatus::QUEUED->value, $record->taskStatus);
            self::assertSame(StepStatus::QUEUED->value, $record->stepStatus);
            self::assertEquals((object) ['job' => 'alpha'], $record->payload);

            $notification = $this->fetchNotification($listener);

            self::assertIsArray($notification);
            self::assertSame($queue->getNotificationChannel(), $notification['message']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testClaimReturnsAtMostOneRecordAcrossConnections(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $otherPdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'beta']);
            $task->enqueue($taskEnvironment);

            $firstQueue = new DatabaseQueue($pdo, $configuration);
            $secondQueue = new DatabaseQueue($otherPdo, $configuration);

            $claimed = $firstQueue->claim('runner-1');
            $secondClaim = $secondQueue->claim('runner-2');

            self::assertNotNull($claimed);
            self::assertSame(TaskStatus::RUNNING->value, $claimed->taskStatus);
            self::assertSame(StepStatus::RUNNING->value, $claimed->stepStatus);
            self::assertSame('runner-1', $claimed->claimedBy);
            self::assertNull($secondClaim);

            $task = Task::fromQueueRecord($claimed);

            self::assertInstanceOf(QueueWorkflowTaskFixture::class, $task);
            self::assertEquals((object) ['job' => 'beta'], $task->getPayload());
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testEnqueueUsesConfiguredSchemaAndSchemaAwareNotificationChannel(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        DatabaseIntegrationConnection::requireNotificationSupport($this, $pdo);
        DatabaseIntegrationConnection::requireSchemaSupport($this, $pdo);
        $listener = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $schemaName = DatabaseIntegrationConnection::uniqueSchemaName();
        $platformName = DatabaseIntegrationConnection::platform($pdo)->getName();

        try {
            if ($platformName === 'postgresql') {
                DatabaseIntegrationConnection::createSchemaIfSupported($pdo, $schemaName);
            }

            $configuration = new QueueConfiguration($tableName, $schemaName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $queue = new DatabaseQueue($pdo, $configuration);
            $listener->exec(sprintf('LISTEN "%s"', $queue->getNotificationChannel()));

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'schema-aware']);
            $record = $task->enqueue($taskEnvironment);

            self::assertNotNull($record->taskId);
            self::assertStringContainsString($schemaName, $queue->getNotificationChannel());

            $notification = $this->fetchNotification($listener);

            self::assertIsArray($notification);
            self::assertSame($queue->getNotificationChannel(), $notification['message']);
            self::assertEquals(
                (object) ['job' => 'schema-aware'],
                $this->fetchQueueRecord($pdo, $tableName, (int) $record->taskId, $schemaName)->payload,
            );
        } finally {
            DatabaseIntegrationConnection::dropSchemaIfExists($pdo, $schemaName);
        }
    }

    public function testClaimPrefersHigherPriorityTasks(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $lowPriorityTask = new QueueWorkflowTaskFixture();
            $lowPriorityTask->setPayload(['job' => 'low']);
            $lowPriorityTask->enqueue($taskEnvironment, priority: Task::PRIO_VERY_LOW);

            $highPriorityTask = new QueueWorkflowTaskFixture();
            $highPriorityTask->setPayload(['job' => 'high']);
            $highPriorityTask->enqueue($taskEnvironment, priority: Task::PRIO_VERY_HIGH);

            $queue = new DatabaseQueue($pdo, $configuration);

            $firstClaim = $queue->claim('runner-1');
            $secondClaim = $queue->claim('runner-1');

            self::assertNotNull($firstClaim);
            self::assertNotNull($secondClaim);
            self::assertSame(Task::PRIO_VERY_HIGH, $firstClaim->priority);
            self::assertSame(Task::PRIO_VERY_LOW, $secondClaim->priority);
            self::assertEquals((object) ['job' => 'high'], $firstClaim->payload);
            self::assertEquals((object) ['job' => 'low'], $secondClaim->payload);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testEnqueueNormalizesMissingPayloadToObject(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $record = $task->enqueue($taskEnvironment);

            self::assertInstanceOf(\stdClass::class, $record->payload);

            $rehydratedTask = Task::fromQueueRecord($record);

            self::assertInstanceOf(\stdClass::class, $rehydratedTask->getPayload());
            self::assertInstanceOf(QueueWorkflowTaskFixture::class, $rehydratedTask);
            self::assertInstanceOf(
                \ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowStepFixture::class,
                $rehydratedTask->actualStep(),
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testEnqueueStoresAttachmentMetadataAndHydratesAttachmentFromBlobStore(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $sourcePath = tempnam(sys_get_temp_dir(), 'durable-attachment-source-');

        self::assertIsString($sourcePath);

        try {
            file_put_contents($sourcePath, 'blob-backed attachment');

            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->getPayload()->attachment = FileAttachment::fromFile($sourcePath);
            $record = $task->enqueue($taskEnvironment);

            self::assertNotNull($record->taskId);

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame('file_attachment', $row['payload_json']['attachment']['__php_tr_type']);
            self::assertSame(basename($sourcePath), $row['payload_json']['attachment']['name']);
            self::assertSame(1, $this->blobCountForTask($pdo, $configuration, (int) $record->taskId));

            $rehydratedTask = Task::fromQueueRecord(
                $this->fetchQueueRecord($pdo, $tableName, (int) $record->taskId),
                null,
                null,
                new AttachmentBlobStore($pdo, $configuration),
            );
            $attachment = $rehydratedTask->getPayload()->attachment;

            self::assertInstanceOf(FileAttachment::class, $attachment);
            self::assertSame('blob-backed attachment', $attachment->contents());
        } finally {
            @unlink($sourcePath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testAttachmentRoundtripSupportsNestedObjectsAndArrays(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $primaryPath = tempnam(sys_get_temp_dir(), 'durable-attachment-primary-');
        $secondaryPath = tempnam(sys_get_temp_dir(), 'durable-attachment-secondary-');

        self::assertIsString($primaryPath);
        self::assertIsString($secondaryPath);

        try {
            file_put_contents($primaryPath, 'nested object attachment');
            file_put_contents($secondaryPath, 'nested array attachment');

            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->getPayload()->details = (object) [
                'primary' => FileAttachment::fromFile($primaryPath),
            ];
            $task->getPayload()->files = [FileAttachment::fromFile($secondaryPath)];
            $record = $task->enqueue($taskEnvironment);

            self::assertNotNull($record->taskId);

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame('file_attachment', $row['payload_json']['details']['primary']['__php_tr_type']);
            self::assertSame('file_attachment', $row['payload_json']['files'][0]['__php_tr_type']);

            $rehydratedTask = Task::fromQueueRecord(
                $this->fetchQueueRecord($pdo, $tableName, (int) $record->taskId),
                null,
                null,
                new AttachmentBlobStore($pdo, $configuration),
            );

            self::assertInstanceOf(FileAttachment::class, $rehydratedTask->getPayload()->details->primary);
            self::assertIsArray($rehydratedTask->getPayload()->files);
            self::assertInstanceOf(FileAttachment::class, $rehydratedTask->getPayload()->files[0]);
            self::assertSame('nested object attachment', $rehydratedTask->getPayload()->details->primary->contents());
            self::assertSame('nested array attachment', $rehydratedTask->getPayload()->files[0]->contents());
        } finally {
            @unlink($primaryPath);
            @unlink($secondaryPath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testSharedAttachmentInstanceIsStoredOnlyOnce(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $sourcePath = tempnam(sys_get_temp_dir(), 'durable-attachment-shared-');

        self::assertIsString($sourcePath);

        try {
            file_put_contents($sourcePath, 'shared attachment');

            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $sharedAttachment = FileAttachment::fromFile($sourcePath);
            $task = new QueueWorkflowTaskFixture();
            $task->getPayload()->details = (object) ['primary' => $sharedAttachment];
            $task->getPayload()->files = [$sharedAttachment];
            $record = $task->enqueue($taskEnvironment);

            self::assertNotNull($record->taskId);

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(1, $this->blobCountForTask($pdo, $configuration, (int) $record->taskId));
            self::assertSame(
                $row['payload_json']['details']['primary']['blobId'],
                $row['payload_json']['files'][0]['blobId'],
            );
        } finally {
            @unlink($sourcePath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testDeleteExpiredAlsoDeletesAttachmentBlobRowsThroughCascade(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $sourcePath = tempnam(sys_get_temp_dir(), 'durable-attachment-expired-');

        self::assertIsString($sourcePath);

        try {
            file_put_contents($sourcePath, 'expired attachment');

            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $queue = new DatabaseQueue($pdo, $configuration);
            $task = new QueueWorkflowTaskFixture();
            $task->getPayload()->attachment = FileAttachment::fromFile($sourcePath);
            $record = $task->enqueue($taskEnvironment);

            self::assertNotNull($record->taskId);
            self::assertSame(1, $this->blobCountForTask($pdo, $configuration, (int) $record->taskId));

            $past = new \DateTimeImmutable('-1 hour');
            $this->updateTask(
                $pdo,
                $queue,
                (int) $record->taskId,
                [
                    'task_status' => TaskStatus::SUCCEEDED,
                    'step_status' => StepStatus::SUCCEEDED,
                    'task_finished_at' => $past,
                    'step_finished_at' => $past,
                    'cleanup_at' => $past,
                ],
            );

            self::assertSame(1, $queue->deleteExpired());
            self::assertFalse($this->taskExists($pdo, $tableName, (int) $record->taskId));
            self::assertSame(0, $this->blobCountForTask($pdo, $configuration, (int) $record->taskId));
        } finally {
            @unlink($sourcePath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testDeleteExpiredRemovesOnlyExpiredTerminalRows(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $queue = new DatabaseQueue($pdo, $configuration);
            $expiredTaskId = $this->enqueueTask($pdo, $configuration, ['job' => 'expired'])->taskId;
            $futureTaskId = $this->enqueueTask($pdo, $configuration, ['job' => 'future'])->taskId;
            $queuedTaskId = $this->enqueueTask($pdo, $configuration, ['job' => 'queued'])->taskId;

            self::assertNotNull($expiredTaskId);
            self::assertNotNull($futureTaskId);
            self::assertNotNull($queuedTaskId);

            $past = new \DateTimeImmutable('-1 hour');
            $future = new \DateTimeImmutable('+1 hour');

            $this->updateTask(
                $pdo,
                $queue,
                $expiredTaskId,
                [
                    'task_status' => TaskStatus::SUCCEEDED,
                    'step_status' => StepStatus::SUCCEEDED,
                    'task_finished_at' => $past,
                    'step_finished_at' => $past,
                    'cleanup_at' => $past,
                ],
            );
            $this->updateTask(
                $pdo,
                $queue,
                $futureTaskId,
                [
                    'task_status' => TaskStatus::FAILED,
                    'step_status' => StepStatus::FAILED,
                    'task_finished_at' => $past,
                    'step_finished_at' => $past,
                    'cleanup_at' => $future,
                ],
            );
            $this->updateTask(
                $pdo,
                $queue,
                $queuedTaskId,
                [
                    'cleanup_at' => $past,
                ],
            );

            self::assertSame(1, $queue->deleteExpired());
            self::assertFalse($this->taskExists($pdo, $tableName, $expiredTaskId));
            self::assertTrue($this->taskExists($pdo, $tableName, $futureTaskId));
            self::assertTrue($this->taskExists($pdo, $tableName, $queuedTaskId));
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testUpdateKeepsRowLockedUntilOuterTransactionEnds(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $platform = DatabaseIntegrationConnection::platform($pdo);

        if (!$platform->supportsForUpdate() || !$platform->supportsSkipLocked()) {
            $this->markTestSkipped(
                sprintf('%s does not support the row locking required by this test.', $platform->getName()),
            );
        }

        $otherPdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $queue = new DatabaseQueue($pdo, $configuration);
            $taskId = $this->enqueueTask($pdo, $configuration, ['job' => 'locked'])->taskId;

            self::assertNotNull($taskId);

            $pdo->beginTransaction();
            $queue->update($taskId, ['cancel_requested' => true, 'cancel_reason' => 'hold lock']);

            self::assertFalse($this->canLockTaskRow($otherPdo, $tableName, $taskId));

            $pdo->commit();

            self::assertTrue($this->canLockTaskRow($otherPdo, $tableName, $taskId));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($otherPdo->inTransaction()) {
                $otherPdo->rollBack();
            }

            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testUpdateRequiresActiveTransaction(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);
            $taskEnvironment->getSchemaManager()->bootstrap();

            $queue = new DatabaseQueue($pdo, $configuration);
            $taskId = $this->enqueueTask($pdo, $configuration, ['job' => 'tx-required'])->taskId;

            self::assertNotNull($taskId);

            $this->expectException(QueueException::class);
            $this->expectExceptionMessage('DatabaseQueue::update() requires an active transaction.');

            $queue->update($taskId, ['cancel_requested' => true]);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    private function enqueueTask(
        \PDO $pdo,
        QueueConfiguration $configuration,
        array $payload,
    ): \ByLexus\TaskRunner\Queue\QueueRecord {
        $task = new QueueWorkflowTaskFixture();
        $task->setPayload($payload);

        return (new TaskEnvironment($pdo, $configuration))->enqueue($task);
    }

    /** @return array<string, mixed> */
    private function fetchNotification(\PDO $listener): array {
        if (method_exists($listener, 'getNotify')) {
            $notification = $listener->getNotify(\PDO::FETCH_ASSOC, 1000);
        } elseif (method_exists($listener, 'pgsqlGetNotify')) {
            $notification = $listener->pgsqlGetNotify(\PDO::FETCH_ASSOC, 1000);
        } else {
            $this->markTestSkipped('The configured PDO driver does not support PostgreSQL notifications.');
        }

        if (!is_array($notification)) {
            self::fail('Expected a PostgreSQL notification but none was received.');
        }

        /** @var array<string, mixed> $notification */
        return $notification;
    }

    private function taskExists(\PDO $pdo, string $tableName, int $taskId): bool {
        $statement = $pdo->prepare(
            sprintf(
                'SELECT EXISTS (SELECT 1 FROM %s WHERE task_id = :task_id)',
                $this->qualifiedIdentifier($pdo, null, $tableName),
            ),
        );
        $statement->execute(['task_id' => $taskId]);

        return (bool) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function fetchTaskRow(\PDO $pdo, string $tableName, int $taskId): array {
        $statement = $pdo->prepare(
            sprintf('SELECT * FROM %s WHERE task_id = :task_id', $this->qualifiedIdentifier($pdo, null, $tableName)),
        );
        $statement->execute(['task_id' => $taskId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        foreach (['payload_json', 'result_json', 'error_json'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function fetchQueueRecord(
        \PDO $pdo,
        string $tableName,
        int $taskId,
        ?string $schemaName = null,
    ): QueueRecord {
        $statement = $pdo->prepare(
            sprintf(
                'SELECT * FROM %s WHERE task_id = :task_id',
                $this->qualifiedIdentifier($pdo, $schemaName, $tableName),
            ),
        );
        $statement->execute(['task_id' => $taskId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return QueueRecord::fromDatabaseRow($row);
    }

    private function blobCountForTask(\PDO $pdo, QueueConfiguration $configuration, int $taskId): int {
        $statement = $pdo->prepare(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE task_id = :task_id',
                $this->qualifiedIdentifier($pdo, $configuration->getSchemaName(), $configuration->getBlobTableName()),
            ),
        );
        $statement->execute(['task_id' => $taskId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function updateTask(\PDO $pdo, DatabaseQueue $queue, int $taskId, array $changes): void {
        $pdo->beginTransaction();

        try {
            $queue->update($taskId, $changes);
            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function canLockTaskRow(\PDO $pdo, string $tableName, int $taskId): bool {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                sprintf(
                    'SELECT task_id FROM %s WHERE task_id = :task_id FOR UPDATE SKIP LOCKED',
                    $this->qualifiedIdentifier($pdo, null, $tableName),
                ),
            );
            $statement->execute(['task_id' => $taskId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            $pdo->rollBack();

            return is_array($row);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function qualifiedIdentifier(\PDO $pdo, ?string $schemaName, string $identifier): string {
        return DatabaseIntegrationConnection::platform($pdo)->qualifyIdentifier($schemaName, $identifier);
    }
}
