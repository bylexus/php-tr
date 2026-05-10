<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Integration;

use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\FileAttachment;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\QueueContext;
use ByLexus\TaskRunner\Runner;
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\Tests\Support\AbstractDatabaseIntegrationTestCase;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedServiceFixture;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\SignalControlledShutdownTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\AttachmentRoundtripTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\CancellingTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\PayloadHandoffTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\PayloadMutationTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\RetainedQueueWorkflowTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\RunnerExceptionTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\RunnerNextStepExceptionTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\RunnerRetryTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\RunnerTimeoutTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\ServiceAndLoggerInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\StepInjectedOnlyTaskFixture;
use ByLexus\TaskRunner\Tests\Support\DatabaseIntegrationConnection;
use ByLexus\TaskRunner\Tests\Support\InMemoryContainer;
use ByLexus\TaskRunner\Tests\Support\SpyLogger;

abstract class RunnerIntegrationTestBase extends AbstractDatabaseIntegrationTestCase
{
    public function testRunSingleMarksTaskFailedWhenNextStepThrows(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RunnerNextStepExceptionTaskFixture();
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-next-step-failure'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertSame(true, $row['payload_json']['stepCompleted']);
            self::assertSame('nextStep exploded.', $row['last_error_message']);
            self::assertSame('failed', $row['result_json']['status']);
            self::assertSame(true, $row['result_json']['meta']['nextStepFailed']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleHydratesAttachmentPayloadsForStepExecution(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $sourcePath = tempnam(sys_get_temp_dir(), 'durable-attachment-runner-');

        self::assertIsString($sourcePath);

        try {
            file_put_contents($sourcePath, 'runner attachment content');

            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new AttachmentRoundtripTaskFixture();
            $task->getPayload()->attachment = FileAttachment::fromFile($sourcePath);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-attachment-test'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
            self::assertSame('runner attachment content', $row['payload_json']['attachmentRestoredContent']);
            self::assertSame('file_attachment', $row['payload_json']['attachment']['__php_tr_type']);
        } finally {
            @unlink($sourcePath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleProcessesQueuedTaskToTerminalSuccess(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'runner']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-test-1'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
            self::assertSame(StepStatus::SUCCEEDED->value, $row['step_status']);
            self::assertNull($row['claimed_at']);
            self::assertNull($row['claimed_by']);
            self::assertSame(['job' => 'runner'], $row['payload_json']);
            self::assertEquals(
                ['status' => 'succeeded', 'meta' => ['executed' => true], 'message' => null],
                $row['result_json'],
            );
            self::assertNotNull($row['task_finished_at']);
            self::assertNotNull($row['cleanup_at']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleEmitsLifecycleAndQueueLogsToConfiguredLogger(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $logger = new SpyLogger();
            $queueContext = new QueueContext($pdo, $configuration, logger: $logger);
            $queueContext->getSchemaManager()->bootstrap();
            $task = new QueueWorkflowTaskFixture($logger);
            $task->setPayload(['job' => 'runner-logs']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-logger-test', false, 30, null, $logger),
            );

            self::assertSame(1, $runner->runSingle());

            self::assertTrue($logger->hasRecord('info', 'Task enqueue requested.'));
            self::assertTrue($logger->hasRecord('info', 'Queue enqueue started.'));
            self::assertTrue($logger->hasRecord('info', 'Queue claim succeeded.'));
            self::assertTrue($logger->hasRecord('info', 'Runner executing step.'));
            self::assertTrue($logger->hasRecord('info', 'Task step updated.'));
            self::assertTrue($logger->hasRecord('info', 'Runner marked task as succeeded.'));

            $executingRecord = $this->findLogRecord($logger, 'info', 'Runner executing step.');
            $stepUpdatedRecord = $this->findLogRecord($logger, 'info', 'Task step updated.');

            self::assertSame((int) $record->taskId, $executingRecord['context']['taskId'] ?? null);
            self::assertSame(
                \ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowStepFixture::class,
                $executingRecord['context']['stepClass'] ?? null,
            );
            self::assertSame((int) $record->taskId, $stepUpdatedRecord['context']['taskId'] ?? null);
            self::assertSame(
                \ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowStepFixture::class,
                $stepUpdatedRecord['context']['stepClass'] ?? null,
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleCanBootstrapSchemaWhenConfigured(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);

            self::assertFalse($queueContext->getSchemaManager()->tableExists());

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-bootstrap', true),
            );

            self::assertSame(0, $runner->runSingle());
            self::assertTrue($queueContext->getSchemaManager()->tableExists());
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSinglePersistsNullPayloadOnThrownFailure(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RunnerExceptionTaskFixture();
            $task->setPayload(['to' => 'alex@example.com', 'from' => 'chuck@example.com']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-null-payload-failure'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertSame(['to' => 'alex@example.com', 'from' => 'chuck@example.com'], $row['payload_json']);
            self::assertSame('Step exploded.', $row['last_error_message']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDrainsQueuedFollowUpStepsWhileKeepingExistingPayload(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new PayloadHandoffTaskFixture();
            $task->setPayload(['to' => 'alex@example.com', 'from' => 'chuck@example.com']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-payload-keep'),
            );

            self::assertSame(2, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
            self::assertSame(StepStatus::SUCCEEDED->value, $row['step_status']);
            self::assertSame(['to' => 'alex@example.com', 'from' => 'chuck@example.com'], $row['payload_json']);
            self::assertEquals(
                ['status' => 'succeeded', 'meta' => [], 'message' => null],
                $row['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSinglePersistsMaterializedPayloadObjectsFromNullRootPayload(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new PayloadMutationTaskFixture();
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-payload-materialize'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
            self::assertSame(['details' => ['bar' => 'somevalue']], $row['payload_json']);

            $rehydratedTask = Task::fromQueueRecord($this->fetchQueueRecord($pdo, $tableName, (int) $record->taskId));

            self::assertInstanceOf(\stdClass::class, $rehydratedTask->getPayload());
            self::assertSame('somevalue', $rehydratedTask->getPayload('details')->bar);
            self::assertSame('somevalue', $rehydratedTask->getPayload()->details->bar);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDeletesExpiredTerminalRowsBeforePolling(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'cleanup']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $queue = new DatabaseQueue($pdo, $configuration);
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

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-cleanup-1'),
            );

            self::assertSame(0, $runner->runSingle());
            self::assertFalse($this->taskExists($pdo, $tableName, (int) $record->taskId));
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDrainsRetryableFailureUntilItSucceeds(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RunnerRetryTaskFixture();
            $task->setPayload(['failuresRemaining' => 1]);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-retry-1'),
            );

            self::assertSame(2, $runner->runSingle());

            $completedRow = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $completedRow['task_status']);
            self::assertSame(StepStatus::SUCCEEDED->value, $completedRow['step_status']);
            self::assertEquals(['failuresRemaining' => 0, 'completed' => true], $completedRow['payload_json']);
            self::assertEquals(
                ['status' => 'succeeded', 'meta' => ['retried' => true], 'message' => 'Step succeeded.'],
                $completedRow['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsTaskWhenMaxRuntimeIsExceeded(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RunnerTimeoutTaskFixture();
            $task->setPayload(['job' => 'timeout']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-timeout-1'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertSame('408', $row['last_error_code']);
            self::assertSame('Step exceeded its configured maximum runtime.', $row['last_error_message']);
            self::assertEquals(
                [
                    'status' => 'failed',
                    'meta' => ['timedOut' => true],
                    'message' => 'Step exceeded its configured maximum runtime.',
                ],
                $row['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsStaleRunningTaskWhenMaxRuntimeIsAlreadyExceeded(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RunnerTimeoutTaskFixture();
            $task->setPayload(['job' => 'stale-timeout']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $queue = new DatabaseQueue($pdo, $configuration);
            $startedAt = new \DateTimeImmutable('-5 seconds');
            $this->updateTask(
                $pdo,
                $queue,
                (int) $record->taskId,
                [
                    'task_status' => TaskStatus::RUNNING,
                    'step_status' => StepStatus::RUNNING,
                    'task_started_at' => $startedAt,
                    'step_started_at' => $startedAt,
                    'claimed_at' => $startedAt,
                    'claimed_by' => 'stale-runner',
                ],
            );

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-timeout-cleanup-1'),
            );

            self::assertSame(0, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertSame('408', $row['last_error_code']);
            self::assertSame('Step exceeded its configured maximum runtime.', $row['last_error_message']);
            self::assertNull($row['claimed_at']);
            self::assertNull($row['claimed_by']);
            self::assertNotNull($row['task_finished_at']);
            self::assertNotNull($row['cleanup_at']);
            self::assertEquals(
                [
                    'status' => 'failed',
                    'meta' => ['timedOut' => true],
                    'message' => 'Step exceeded its configured maximum runtime.',
                ],
                $row['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleResolvesConstructorDependenciesFromConfiguredContainer(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $service = new ConstructorInjectedServiceFixture('mailer');
            $task = new ConstructorInjectedTaskFixture($service);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration(
                    'runner-container-success',
                    false,
                    30,
                    new InMemoryContainer([
                        ConstructorInjectedServiceFixture::class => $service,
                    ]),
                ),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
            self::assertSame(StepStatus::SUCCEEDED->value, $row['step_status']);
            self::assertSame('mailer', $row['payload_json']['taskService']);
            self::assertSame('mailer', $row['payload_json']['stepService']);
            self::assertEquals(
                ['status' => 'succeeded', 'meta' => ['injectedStepService' => 'mailer'], 'message' => null],
                $row['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleUsesRunnerLoggerWhenContainerDoesNotProvideLoggerService(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $logger = new SpyLogger();
            $service = new ConstructorInjectedServiceFixture('mailer');
            $task = new ServiceAndLoggerInjectedTaskFixture($service, $logger);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration(
                    'runner-container-logger-fallback',
                    false,
                    30,
                    new InMemoryContainer([
                        ConstructorInjectedServiceFixture::class => $service,
                    ]),
                    $logger,
                ),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
            self::assertSame(StepStatus::SUCCEEDED->value, $row['step_status']);
            self::assertSame('mailer', $row['payload_json']['taskService']);
            self::assertSame('mailer', $row['payload_json']['stepService']);
            self::assertSame(SpyLogger::class, $row['payload_json']['loggerClass']);
            self::assertEquals(
                [
                    'status' => 'succeeded',
                    'meta' => [
                        'injectedStepService' => 'mailer',
                        'loggerClass' => SpyLogger::class,
                    ],
                    'message' => null,
                ],
                $row['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsClaimedTaskWhenInjectedTaskHasNoConfiguredContainer(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new ConstructorInjectedTaskFixture(new ConstructorInjectedServiceFixture('mailer'));
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-container-missing'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertNull($row['claimed_at']);
            self::assertNull($row['claimed_by']);
            self::assertSame('0', $row['last_error_code']);
            self::assertStringContainsString(
                'requires a configured service container',
                (string) $row['last_error_message'],
            );
            self::assertEquals(
                [
                    'status' => 'failed',
                    'meta' => ['instantiationFailed' => true],
                    'message' => $row['last_error_message'],
                ],
                $row['result_json'],
            );
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsClaimedTaskWhenInjectedStepServiceIsMissing(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new StepInjectedOnlyTaskFixture();
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration(
                    'runner-service-missing',
                    false,
                    30,
                    new InMemoryContainer([]),
                ),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertSame('0', $row['last_error_code']);
            self::assertStringContainsString(
                'could not be resolved from the service container',
                (string) $row['last_error_message'],
            );
            self::assertStringContainsString('ConstructorInjectedStepFixture', (string) $row['last_error_message']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunLoopProcessesTasksAndStopsGracefullyOnSigterm(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $markerPath = sprintf('%s/%s.stop', sys_get_temp_dir(), $tableName);
        @unlink($markerPath);

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RetainedQueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'loop']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $process = proc_open(
                [PHP_BINARY, 'tests/Support/run-loop.php', $tableName, $markerPath],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                $this->processEnvironment(),
            );

            self::assertIsResource($process);

            try {
                $this->waitForTaskStatus($pdo, $tableName, (int) $record->taskId, TaskStatus::SUCCEEDED->value, 50);

                proc_terminate($process, SIGTERM);
                $this->waitForFile($markerPath, 30);
            } finally {
                $this->terminateProcess($process);

                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                proc_close($process);
            }
        } finally {
            @unlink($markerPath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunLoopWaitsForRunningStepBeforeStoppingOnSigterm(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $markerPath = sprintf('%s/%s.stop', sys_get_temp_dir(), $tableName);
        $startedPath = sprintf('%s/%s.started', sys_get_temp_dir(), $tableName);
        $releasePath = sprintf('%s/%s.release', sys_get_temp_dir(), $tableName);
        @unlink($markerPath);
        @unlink($startedPath);
        @unlink($releasePath);

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new SignalControlledShutdownTaskFixture();
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $process = proc_open(
                [PHP_BINARY, 'tests/Support/run-loop.php', $tableName, $markerPath],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                array_merge($this->processEnvironment(), [
                    'PHP_TR_SIGNAL_STARTED_PATH' => $startedPath,
                    'PHP_TR_SIGNAL_RELEASE_PATH' => $releasePath,
                ]),
            );

            self::assertIsResource($process);

            try {
                $this->waitForTaskStatus($pdo, $tableName, (int) $record->taskId, TaskStatus::RUNNING->value, 20);
                $this->waitForFile($startedPath, 30);

                proc_terminate($process, SIGTERM);

                $this->waitForTaskStatus($pdo, $tableName, (int) $record->taskId, TaskStatus::FAILED->value, 20);

                $failedRow = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

                self::assertSame(TaskStatus::FAILED->value, $failedRow['task_status']);
                self::assertSame(StepStatus::FAILED->value, $failedRow['step_status']);
                self::assertSame(
                    'Runner stop was requested before the current step completed. Signal: SIGTERM.',
                    $failedRow['last_error_message'],
                );

                file_put_contents($releasePath, "release\n");

                $this->waitForTaskStatus($pdo, $tableName, (int) $record->taskId, TaskStatus::SUCCEEDED->value, 30);
                $this->waitForFile($markerPath, 30);

                $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

                self::assertSame(TaskStatus::SUCCEEDED->value, $row['task_status']);
                self::assertSame(StepStatus::SUCCEEDED->value, $row['step_status']);
                self::assertSame(true, $row['result_json']['meta']['completedAfterSignal']);
                self::assertStringContainsString(
                    'Cancellation requested via SIGTERM. The runner will stop after the current step completes.',
                    (string) stream_get_contents($pipes[2]),
                );
            } finally {
                $this->terminateProcess($process);

                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                proc_close($process);
            }
        } finally {
            @unlink($markerPath);
            @unlink($startedPath);
            @unlink($releasePath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleWaitsForRunningStepBeforeStoppingOnSigterm(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $markerPath = sprintf('%s/%s.stop', sys_get_temp_dir(), $tableName);
        $startedPath = sprintf('%s/%s.started', sys_get_temp_dir(), $tableName);
        $releasePath = sprintf('%s/%s.release', sys_get_temp_dir(), $tableName);
        @unlink($markerPath);
        @unlink($startedPath);
        @unlink($releasePath);

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $gracefulTask = new SignalControlledShutdownTaskFixture();
            $gracefulRecord = $gracefulTask->enqueue($queueContext);
            $followUpTask = new QueueWorkflowTaskFixture();
            $followUpRecord = $followUpTask->enqueue($queueContext);

            self::assertNotNull($gracefulRecord->taskId);
            self::assertNotNull($followUpRecord->taskId);

            $process = proc_open(
                [PHP_BINARY, 'tests/Support/run-single.php', $tableName, $markerPath],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                array_merge($this->processEnvironment(), [
                    'PHP_TR_SIGNAL_STARTED_PATH' => $startedPath,
                    'PHP_TR_SIGNAL_RELEASE_PATH' => $releasePath,
                ]),
            );

            self::assertIsResource($process);

            try {
                $this->waitForTaskStatus(
                    $pdo,
                    $tableName,
                    (int) $gracefulRecord->taskId,
                    TaskStatus::RUNNING->value,
                    20,
                );
                $this->waitForFile($startedPath, 30);

                proc_terminate($process, SIGTERM);

                $this->waitForTaskStatus(
                    $pdo,
                    $tableName,
                    (int) $gracefulRecord->taskId,
                    TaskStatus::FAILED->value,
                    20,
                );

                $failedGracefulRow = $this->fetchTaskRow($pdo, $tableName, (int) $gracefulRecord->taskId);

                self::assertSame(TaskStatus::FAILED->value, $failedGracefulRow['task_status']);
                self::assertSame(StepStatus::FAILED->value, $failedGracefulRow['step_status']);
                self::assertSame(
                    'Runner stop was requested before the current step completed. Signal: SIGTERM.',
                    $failedGracefulRow['last_error_message'],
                );

                file_put_contents($releasePath, "release\n");

                $this->waitForTaskStatus(
                    $pdo,
                    $tableName,
                    (int) $gracefulRecord->taskId,
                    TaskStatus::SUCCEEDED->value,
                    30,
                );
                $this->waitForFile($markerPath, 30);

                $gracefulRow = $this->fetchTaskRow($pdo, $tableName, (int) $gracefulRecord->taskId);
                $followUpRow = $this->fetchTaskRow($pdo, $tableName, (int) $followUpRecord->taskId);

                self::assertSame(TaskStatus::SUCCEEDED->value, $gracefulRow['task_status']);
                self::assertSame(StepStatus::SUCCEEDED->value, $gracefulRow['step_status']);
                self::assertSame(true, $gracefulRow['result_json']['meta']['completedAfterSignal']);
                self::assertSame(TaskStatus::QUEUED->value, $followUpRow['task_status']);
                self::assertSame(StepStatus::QUEUED->value, $followUpRow['step_status']);
                self::assertStringContainsString(
                    'Cancellation requested via SIGTERM. The runner will stop after the current step completes.',
                    (string) stream_get_contents($pipes[2]),
                );
            } finally {
                $this->terminateProcess($process);

                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                proc_close($process);
            }
        } finally {
            @unlink($markerPath);
            @unlink($startedPath);
            @unlink($releasePath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunLoopWakesOnNotifyBeforeTimeoutWithPlainPdo(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        DatabaseIntegrationConnection::requireNotificationSupport($this, $pdo);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $markerPath = sprintf('%s/%s.stop', sys_get_temp_dir(), $tableName);
        $readyPath = sprintf('%s/%s.ready', sys_get_temp_dir(), $tableName);
        @unlink($markerPath);
        @unlink($readyPath);

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $process = proc_open(
                [PHP_BINARY, 'tests/Support/run-loop.php', $tableName, $markerPath, 'plain-pdo', '5', $readyPath],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
                $this->processEnvironment(),
            );

            self::assertIsResource($process);

            try {
                $this->waitForFile($readyPath, 30);
                usleep(500_000);

                $task = new RetainedQueueWorkflowTaskFixture();
                $task->setPayload(['job' => 'plain-pdo-notify']);
                $record = $task->enqueue($queueContext);

                self::assertNotNull($record->taskId);
                $this->waitForTaskStatus($pdo, $tableName, (int) $record->taskId, TaskStatus::SUCCEEDED->value, 20);

                proc_terminate($process, SIGTERM);
                $this->waitForFile($markerPath, 30);
            } finally {
                $this->terminateProcess($process);

                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                proc_close($process);
            }
        } finally {
            @unlink($readyPath);
            @unlink($markerPath);
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDrainsRetriesAndFailsTaskWhenTheyAreExhausted(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new RunnerRetryTaskFixture();
            $task->setPayload(['failuresRemaining' => 2]);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-retry-exhausted'),
            );

            self::assertSame(2, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::FAILED->value, $row['task_status']);
            self::assertSame(StepStatus::FAILED->value, $row['step_status']);
            self::assertEquals(['failuresRemaining' => 0], $row['payload_json']);
            self::assertSame('500', $row['last_error_code']);
            self::assertSame('Retry requested.', $row['last_error_message']);
            self::assertNotNull($row['task_finished_at']);
            self::assertNotNull($row['cleanup_at']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleCancelsClaimedTaskWhenCancellationWasRequested(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'cancel']);
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $task->cancel('Cancelled before execution.');

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-cancelled'),
            );

            self::assertSame(0, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::CANCELLED->value, $row['task_status']);
            self::assertSame(StepStatus::CANCELLED->value, $row['step_status']);
            self::assertEquals(['job' => 'cancel'], $row['payload_json']);
            self::assertEquals(
                ['status' => 'cancelled', 'meta' => ['requested' => true], 'message' => 'Cancelled before execution.'],
                $row['result_json'],
            );
            self::assertSame('499', $row['last_error_code']);
            self::assertSame('Cancelled before execution.', $row['last_error_message']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleKeepsTaskCancelledWhenStepCancelsDuringExecution(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $queueContext = new QueueContext($pdo, $configuration);
            $queueContext->getSchemaManager()->bootstrap();

            $task = new CancellingTaskFixture();
            $record = $task->enqueue($queueContext);

            self::assertNotNull($record->taskId);

            $runner = $this->createRunner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-cancel-during-execution'),
            );

            self::assertSame(1, $runner->runSingle());

            $row = $this->fetchTaskRow($pdo, $tableName, (int) $record->taskId);

            self::assertSame(TaskStatus::CANCELLED->value, $row['task_status']);
            self::assertSame(StepStatus::CANCELLED->value, $row['step_status']);
            self::assertSame(CancellingTaskFixture::class, $row['task_class']);
            self::assertSame(
                \ByLexus\TaskRunner\Tests\Fixture\CancellingStepFixture::class,
                $row['step_class'],
            );
            self::assertSame(true, $row['payload_json']['cancelledDuringExecution']);
            self::assertEquals(
                ['status' => 'cancelled', 'meta' => ['requested' => true], 'message' => 'Cancelled during execution.'],
                $row['result_json'],
            );
            self::assertSame('499', $row['last_error_code']);
            self::assertSame('Cancelled during execution.', $row['last_error_message']);
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTaskRow(\PDO $pdo, string $tableName, int $taskId): array {
        $statement = $pdo->prepare(
            sprintf('SELECT * FROM %s WHERE task_id = :task_id', $this->qualifiedIdentifier($pdo, $tableName)),
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

    private function fetchQueueRecord(\PDO $pdo, string $tableName, int $taskId): QueueRecord {
        $statement = $pdo->prepare(
            sprintf('SELECT * FROM %s WHERE task_id = :task_id', $this->qualifiedIdentifier($pdo, $tableName)),
        );
        $statement->execute(['task_id' => $taskId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return QueueRecord::fromDatabaseRow($row);
    }

    private function taskExists(\PDO $pdo, string $tableName, int $taskId): bool {
        $statement = $pdo->prepare(
            sprintf(
                'SELECT EXISTS (SELECT 1 FROM %s WHERE task_id = :task_id)',
                $this->qualifiedIdentifier($pdo, $tableName),
            ),
        );
        $statement->execute(['task_id' => $taskId]);

        return (bool) $statement->fetchColumn();
    }

    private function createRunner(
        \PDO $pdo,
        QueueConfiguration $configuration,
        ?RunnerConfiguration $runnerConfiguration = null,
    ): Runner {
        return (new QueueContext($pdo, $configuration, runnerConfiguration: $runnerConfiguration))->createRunner();
    }

    private function qualifiedIdentifier(\PDO $pdo, string $identifier): string {
        return DatabaseIntegrationConnection::platform($pdo)->qualifyIdentifier(null, $identifier);
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

    private function waitForTaskStatus(
        \PDO $pdo,
        string $tableName,
        int $taskId,
        string $expectedStatus,
        int $attempts,
    ): void {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $row = $this->fetchTaskRow($pdo, $tableName, $taskId);

            if (($row['task_status'] ?? null) === $expectedStatus) {
                return;
            }

            usleep(100_000);
        }

        self::fail(sprintf('Task %d did not reach status %s in time.', $taskId, $expectedStatus));
    }

    private function waitForFile(string $path, int $attempts): void {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if (is_file($path)) {
                return;
            }

            usleep(100_000);
        }

        self::fail(sprintf('Expected marker file was not created: %s', $path));
    }

    /**
     * @param resource $process
     */
    private function terminateProcess($process): void {
        $status = proc_get_status($process);

        if (!is_array($status) || !($status['running'] ?? false)) {
            return;
        }

        proc_terminate($process, SIGTERM);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = proc_get_status($process);

            if (!is_array($status) || !($status['running'] ?? false)) {
                return;
            }

            usleep(100_000);
        }

        if (defined('SIGKILL')) {
            proc_terminate($process, SIGKILL);
        }
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function findLogRecord(SpyLogger $logger, string $level, string $message): array {
        foreach ($logger->getRecords() as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                return $record;
            }
        }

        self::fail(sprintf('Expected log record was not found: %s [%s]', $message, $level));
    }
}
