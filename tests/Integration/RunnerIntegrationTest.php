<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Integration;

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Enum\TaskStatus;
use ByLexus\DurableTask\Queue\PostgresQueue;
use ByLexus\DurableTask\Queue\QueueConfiguration;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Queue\SchemaManager;
use ByLexus\DurableTask\Runner;
use ByLexus\DurableTask\RunnerConfiguration;
use ByLexus\DurableTask\Task;
use ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedServiceFixture;
use ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\PayloadHandoffTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\PayloadMutationTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\RetainedQueueWorkflowTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\RunnerExceptionTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\RunnerRetryTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\RunnerTimeoutTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\QueueWorkflowTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\ServiceAndLoggerInjectedTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\StepInjectedOnlyTaskFixture;
use ByLexus\DurableTask\Tests\Support\PostgresIntegrationConnection;
use ByLexus\DurableTask\Tests\Support\InMemoryContainer;
use ByLexus\DurableTask\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

final class RunnerIntegrationTest extends TestCase
{
    public function testRunSingleProcessesQueuedTaskToTerminalSuccess(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'runner']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleEmitsLifecycleAndQueueLogsToConfiguredLogger(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $logger = new SpyLogger();
            $task = new QueueWorkflowTaskFixture($logger);
            $task->setPayload(['job' => 'runner-logs']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
                \ByLexus\DurableTask\Tests\Fixture\QueueWorkflowStepFixture::class,
                $executingRecord['context']['stepClass'] ?? null,
            );
            self::assertSame((int) $record->taskId, $stepUpdatedRecord['context']['taskId'] ?? null);
            self::assertSame(
                \ByLexus\DurableTask\Tests\Fixture\QueueWorkflowStepFixture::class,
                $stepUpdatedRecord['context']['stepClass'] ?? null,
            );
        } finally {
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleCanBootstrapSchemaWhenConfigured(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);

            self::assertFalse($schemaManager->tableExists());

            $runner = new Runner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-bootstrap', true),
            );

            self::assertSame(0, $runner->runSingle());
            self::assertTrue($schemaManager->tableExists());
        } finally {
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSinglePersistsNullPayloadOnThrownFailure(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new RunnerExceptionTaskFixture();
            $task->setPayload(['to' => 'alex@example.com', 'from' => 'chuck@example.com']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDrainsQueuedFollowUpStepsWhileKeepingExistingPayload(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new PayloadHandoffTaskFixture();
            $task->setPayload(['to' => 'alex@example.com', 'from' => 'chuck@example.com']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSinglePersistsMaterializedPayloadObjectsFromNullRootPayload(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new PayloadMutationTaskFixture();
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDeletesExpiredTerminalRowsBeforePolling(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'cleanup']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $queue = new PostgresQueue($pdo, $configuration);
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

            $runner = new Runner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-cleanup-1'),
            );

            self::assertSame(0, $runner->runSingle());
            self::assertFalse($this->taskExists($pdo, $tableName, (int) $record->taskId));
        } finally {
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDrainsRetryableFailureUntilItSucceeds(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new RunnerRetryTaskFixture();
            $task->setPayload(['failuresRemaining' => 1]);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsTaskWhenMaxRuntimeIsExceeded(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new RunnerTimeoutTaskFixture();
            $task->setPayload(['job' => 'timeout']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleResolvesConstructorDependenciesFromConfiguredContainer(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $service = new ConstructorInjectedServiceFixture('mailer');
            $task = new ConstructorInjectedTaskFixture($service);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleUsesRunnerLoggerWhenContainerDoesNotProvideLoggerService(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $logger = new SpyLogger();
            $service = new ConstructorInjectedServiceFixture('mailer');
            $task = new ServiceAndLoggerInjectedTaskFixture($service, $logger);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsClaimedTaskWhenInjectedTaskHasNoConfiguredContainer(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new ConstructorInjectedTaskFixture(new ConstructorInjectedServiceFixture('mailer'));
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleFailsClaimedTaskWhenInjectedStepServiceIsMissing(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new StepInjectedOnlyTaskFixture();
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunLoopProcessesTasksAndStopsGracefullyOnSigterm(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();
        $markerPath = sprintf('%s/%s.stop', sys_get_temp_dir(), $tableName);
        @unlink($markerPath);

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new RetainedQueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'loop']);
            $record = $task->enqueue($pdo, $configuration);

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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunLoopWakesOnNotifyBeforeTimeoutWithPlainPdo(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();
        $markerPath = sprintf('%s/%s.stop', sys_get_temp_dir(), $tableName);
        $readyPath = sprintf('%s/%s.ready', sys_get_temp_dir(), $tableName);
        @unlink($markerPath);
        @unlink($readyPath);

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $process = proc_open(
                [PHP_BINARY, 'tests/Support/run-loop.php', $tableName, $markerPath, 'plain-pdo', '5', $readyPath],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
            );

            self::assertIsResource($process);

            try {
                $this->waitForFile($readyPath, 30);
                usleep(500_000);

                $task = new RetainedQueueWorkflowTaskFixture();
                $task->setPayload(['job' => 'plain-pdo-notify']);
                $record = $task->enqueue($pdo, $configuration);

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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleDrainsRetriesAndFailsTaskWhenTheyAreExhausted(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new RunnerRetryTaskFixture();
            $task->setPayload(['failuresRemaining' => 2]);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $runner = new Runner(
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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testRunSingleCancelsClaimedTaskWhenCancellationWasRequested(): void {
        $pdo = PostgresIntegrationConnection::requirePdo($this);
        $tableName = PostgresIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $schemaManager = new SchemaManager($pdo, $configuration);
            $schemaManager->bootstrap();

            $task = new QueueWorkflowTaskFixture();
            $task->setPayload(['job' => 'cancel']);
            $record = $task->enqueue($pdo, $configuration);

            self::assertNotNull($record->taskId);

            $queue = new PostgresQueue($pdo, $configuration);
            $this->updateTask(
                $pdo,
                $queue,
                (int) $record->taskId,
                ['cancel_requested' => true, 'cancel_reason' => 'Cancelled before execution.'],
            );

            $runner = new Runner(
                $pdo,
                $configuration,
                new RunnerConfiguration('runner-cancelled'),
            );

            self::assertSame(1, $runner->runSingle());

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
            PostgresIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTaskRow(\PDO $pdo, string $tableName, int $taskId): array {
        $statement = $pdo->prepare(
            sprintf('SELECT * FROM "%s" WHERE task_id = :task_id', str_replace('"', '""', $tableName)),
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
            sprintf('SELECT * FROM "%s" WHERE task_id = :task_id', str_replace('"', '""', $tableName)),
        );
        $statement->execute(['task_id' => $taskId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return QueueRecord::fromDatabaseRow($row);
    }

    private function taskExists(\PDO $pdo, string $tableName, int $taskId): bool {
        $statement = $pdo->prepare(
            sprintf('SELECT EXISTS (SELECT 1 FROM "%s" WHERE task_id = :task_id)', str_replace('"', '""', $tableName)),
        );
        $statement->execute(['task_id' => $taskId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function updateTask(\PDO $pdo, PostgresQueue $queue, int $taskId, array $changes): void {
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
