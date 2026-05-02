<?php

declare(strict_types=1);

namespace ByLexus\DurableTask;

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Enum\TaskStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\Metadata\MetadataResolver;
use ByLexus\DurableTask\Queue\PostgresQueue;
use ByLexus\DurableTask\Queue\QueueConfiguration;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Queue\SchemaManager;
use ByLexus\DurableTask\Result\ErrorInfo;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Runtime\SignalHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Runner {
    private \PDO $connection;
    private QueueConfiguration $queueConfiguration;
    private RunnerConfiguration $runnerConfiguration;
    private MetadataResolver $metadataResolver;
    private PostgresQueue $queue;
    private SignalHandler $signalHandler;
    private LoggerInterface $logger;
    private bool $notificationListenerRegistered = false;

    public function __construct(
        \PDO $connection,
        ?QueueConfiguration $queueConfiguration = null,
        ?RunnerConfiguration $runnerConfiguration = null,
        ?MetadataResolver $metadataResolver = null,
    ) {
        $this->connection = $connection;
        $this->queueConfiguration = $queueConfiguration ?? new QueueConfiguration();
        $this->runnerConfiguration = $runnerConfiguration ?? new RunnerConfiguration();
        $this->metadataResolver = $metadataResolver ?? new MetadataResolver();
        $this->logger = $this->runnerConfiguration->getLogger() ?? new NullLogger();
        $this->queue = new PostgresQueue($this->connection, $this->queueConfiguration, $this->logger);
        $this->signalHandler = new SignalHandler();

        $this->logger->debug('Runner initialized.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
    }

    public function runSingle(): int {
        $this->logger->debug('Runner single pass started.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
        $this->bootstrapSchemaIfConfigured();
        $this->queue->deleteExpired();

        $record = $this->queue->claim($this->runnerConfiguration->getRunnerId());

        if ($record === null) {
            $this->logger->debug('Runner found no queued task to process.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
            ]);

            return 0;
        }

        $this->processClaimedRecord($record);

        return 1;
    }

    public function runLoop(): void {
        $this->logger->info('Runner loop started.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
        $this->bootstrapSchemaIfConfigured();
        $this->signalHandler->register();
        $this->ensureNotificationListener();

        while (!$this->signalHandler->isStopRequested()) {
            $this->queue->deleteExpired();

            $record = $this->queue->claim($this->runnerConfiguration->getRunnerId());

            if ($record === null) {
                $this->waitForNotification();

                continue;
            }

            $this->processClaimedRecord($record);
        }

        $this->logger->info('Runner loop stopped.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
    }

    private function bootstrapSchemaIfConfigured(): void {
        if (!$this->runnerConfiguration->shouldBootstrapSchemaOnStart()) {
            return;
        }

        $this->logger->info('Runner bootstrapping queue schema.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);

        $schemaManager = new SchemaManager($this->connection, $this->queueConfiguration);
        $schemaManager->bootstrap();
    }

    private function ensureNotificationListener(): void {
        if ($this->notificationListenerRegistered) {
            return;
        }

        $this->connection->exec(
            sprintf(
                'LISTEN "%s"',
                str_replace('"', '""', $this->queue->getNotificationChannel()),
            ),
        );
        $this->notificationListenerRegistered = true;

        $this->logger->debug('Runner registered notification listener.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
            'channel' => $this->queue->getNotificationChannel(),
        ]);
    }

    private function waitForNotification(): void {
        $timeoutMilliseconds = $this->runnerConfiguration->getNotificationWaitTimeoutSeconds() * 1000;

        $this->logger->debug('Runner waiting for notification.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
            'timeoutMilliseconds' => $timeoutMilliseconds,
        ]);

        // Plain PDO pgsql connections still need the alias path; on PHP 8.5 we suppress only that deprecation locally.
        if (class_exists('Pdo\\Pgsql') && $this->connection instanceof \Pdo\Pgsql) {
            call_user_func([$this->connection, 'getNotify'], \PDO::FETCH_ASSOC, $timeoutMilliseconds);

            return;
        }

        if (method_exists($this->connection, 'pgsqlGetNotify')) {
            $this->waitForNotificationOnLegacyPdo($timeoutMilliseconds);

            return;
        }

        usleep($this->runnerConfiguration->getNotificationWaitTimeoutSeconds() * 1_000_000);
    }

    private function waitForNotificationOnLegacyPdo(int $timeoutMilliseconds): void {
        $restoreErrorHandler = false;

        if (PHP_VERSION_ID >= 80500) {
            set_error_handler(static function (int $severity, string $message): bool {
                return $severity === E_DEPRECATED
                    && str_contains($message, 'PDO::pgsqlGetNotify() is deprecated');
            });
            $restoreErrorHandler = true;
        }

        try {
            call_user_func([$this->connection, 'pgsqlGetNotify'], \PDO::FETCH_ASSOC, $timeoutMilliseconds);
        } finally {
            if ($restoreErrorHandler) {
                restore_error_handler();
            }
        }
    }

    private function processClaimedRecord(QueueRecord $record): void {
        try {
            $task = Task::fromQueueRecord(
                $record,
                $this->runnerConfiguration->getContainer(),
                $this->logger,
            );
            $step = $task->actualStep();

            if ($step === null) {
                throw new ConfigurationException(
                    sprintf('Claimed queue row %d has no executable step.', $record->taskId),
                );
            }

            $task->setLogger($this->logger);
            $step->setLogger($this->logger);

            $this->logger->info('Runner claimed task for execution.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'taskStatus' => $record->taskStatus,
                'stepStatus' => $record->stepStatus,
                'claimedAt' => $record->claimedAt?->format(DATE_ATOM),
                'claimedBy' => $record->claimedBy,
            ]);

            $taskMetadata = $this->metadataResolver->resolveTaskMetadata($record->taskClass);
            $stepMetadata = $this->metadataResolver->resolveStepMetadata($record->stepClass ?? '', $taskMetadata);
        } catch (\Throwable $throwable) {
            $this->logger->error('Runner failed to hydrate claimed task.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ]);
            $this->persistClaimFailure($record, $throwable);

            return;
        }

        $result = $this->resolveExecutionResult($record, $task, $step, $stepMetadata->getMaxRuntime());

        $this->connection->beginTransaction();

        try {
            $task->updateStep($step, $result);
            $nextStep = $task->nextStep($step);

            if ($nextStep !== null) {
                $nextStep->setLogger($this->logger);

                $this->logger->info('Task selected next step.', [
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $nextStep::class,
                ]);
            }

            $changes = $this->changesForResult(
                $record,
                $task,
                $result,
                $nextStep,
                $taskMetadata->getCleanupAfter(),
                $stepMetadata->getRetryMode(),
                $stepMetadata->getRetries(),
            );
            $this->logger->info('Runner persisting task result.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'stepStatus' => $result->getStatus()->value,
                'nextStepClass' => $nextStep === null ? null : $nextStep::class,
            ]);
            $this->queue->update((int) $record->taskId, $changes, true);
            $this->connection->commit();
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error('Runner failed while persisting task result.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ]);

            throw $throwable;
        }
    }

    private function executeStep(Task $task, Step $step): StepResult {
        try {
            $this->logger->info('Runner executing step.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $task->getId(),
                'taskClass' => $task::class,
                'stepClass' => $step::class,
                'taskAttempt' => $task->getTaskAttempt(),
                'stepAttempt' => $step->getStepAttempt(),
            ]);

            return $step->execute($task);
        } catch (\Throwable $throwable) {
            $this->logger->error('Runner caught step exception.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $task->getId(),
                'taskClass' => $task::class,
                'stepClass' => $step::class,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ]);

            return StepResult::failed(
                errorInfo: new ErrorInfo(
                    (int) $throwable->getCode(),
                    $throwable->getMessage(),
                    ['exception' => $throwable::class],
                ),
                message: $throwable->getMessage(),
            );
        }
    }

    private function resolveExecutionResult(
        QueueRecord $record,
        Task $task,
        Step $step,
        \DateInterval $maxRuntime,
    ): StepResult {
        if ($record->cancelRequested) {
            $message = $record->cancelReason ?? 'Cancellation requested.';

            $this->logger->warning('Runner detected task cancellation request.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
            ]);

            return StepResult::cancelled(
                errorInfo: new ErrorInfo(499, $message),
                meta: ['requested' => true],
                message: $message,
            );
        }

        if ($this->hasExceededMaxRuntime($record, $maxRuntime)) {
            $this->logger->warning('Runner detected step timeout before execution.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
            ]);

            return StepResult::failed(
                errorInfo: new ErrorInfo(408, 'Step exceeded its configured maximum runtime.'),
                meta: ['timedOut' => true],
                message: 'Step exceeded its configured maximum runtime.',
            );
        }

        $result = $this->executeStep($task, $step);

        if ($this->hasExceededMaxRuntime($record, $maxRuntime)) {
            $this->logger->warning('Runner detected step timeout after execution.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
            ]);

            return StepResult::failed(
                errorInfo: new ErrorInfo(
                    408,
                    'Step exceeded its configured maximum runtime.',
                ),
                meta: ['timedOut' => true],
                message: 'Step exceeded its configured maximum runtime.',
            );
        }

        return $result;
    }

    private function persistClaimFailure(QueueRecord $record, \Throwable $throwable): void {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $errorCode = (int) $throwable->getCode();
        $errorInfo = new ErrorInfo(
            $errorCode,
            $throwable->getMessage(),
            ['exception' => $throwable::class],
        );

        $this->connection->beginTransaction();

        $this->logger->error('Runner persisting claim failure.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
            'taskId' => $record->taskId,
            'taskClass' => $record->taskClass,
            'stepClass' => $record->stepClass,
            'exceptionClass' => $throwable::class,
            'errorCode' => $errorCode,
        ]);

        try {
            $this->queue->update(
                (int) $record->taskId,
                [
                    'task_status' => TaskStatus::FAILED,
                    'step_status' => StepStatus::FAILED,
                    'task_finished_at' => $now,
                    'step_finished_at' => $now,
                    'cleanup_at' => $now->add($this->resolveCleanupAfterInterval($record)),
                    'result_json' => [
                        'status' => StepStatus::FAILED->value,
                        'meta' => ['instantiationFailed' => true],
                        'message' => $throwable->getMessage(),
                    ],
                    'error_json' => $this->normalizeErrorInfo($errorInfo),
                    'last_error_code' => (string) $errorCode,
                    'last_error_message' => $throwable->getMessage(),
                    'claimed_at' => null,
                    'claimed_by' => null,
                ],
                true,
            );
            $this->connection->commit();
        } catch (\Throwable $updateThrowable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $updateThrowable;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function changesForResult(
        QueueRecord $record,
        Task $task,
        StepResult $result,
        ?Step $nextStep,
        \DateInterval $cleanupAfter,
        \ByLexus\DurableTask\Enum\RetryMode $retryMode,
        int $retries,
    ): array {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $changes = [
            'payload_json' => $task->getStoredPayload(),
            'result_json' => [
                'status' => $result->getStatus()->value,
                'meta' => $result->getMeta(),
                'message' => $result->getMessage(),
            ],
            'error_json' => $this->normalizeErrorInfo($result->getErrorInfo()),
            'last_error_code' => $result->getErrorInfo() === null ? null : (string) $result->getErrorInfo()->getCode(),
            'last_error_message' => $result->getErrorInfo()?->getMessage(),
            'claimed_at' => null,
            'claimed_by' => null,
        ];

        if (
            $result->getStatus() === StepStatus::FAILED
            && $retryMode === \ByLexus\DurableTask\Enum\RetryMode::RESTART
            && $record->stepAttempt < $retries
        ) {
            $this->logger->warning('Runner requeued failed step for retry.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'stepAttempt' => $record->stepAttempt + 1,
            ]);

            $changes['task_status'] = TaskStatus::QUEUED;
            $changes['step_status'] = StepStatus::QUEUED;
            $changes['step_attempt'] = $record->stepAttempt + 1;
            $changes['step_started_at'] = null;
            $changes['step_finished_at'] = null;
            $changes['available_at'] = $now;

            return $changes;
        }

        if ($result->getStatus() === StepStatus::SUCCEEDED && $nextStep !== null) {
            $this->logger->info('Runner queued next step.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $nextStep::class,
            ]);

            $changes['task_status'] = TaskStatus::QUEUED;
            $changes['step_class'] = $nextStep::class;
            $changes['step_status'] = StepStatus::QUEUED;
            $changes['step_attempt'] = 0;
            $changes['step_started_at'] = null;
            $changes['step_finished_at'] = null;
            $changes['available_at'] = $now;

            return $changes;
        }

        $changes['task_finished_at'] = $now;
        $changes['step_finished_at'] = $now;
        $changes['cleanup_at'] = $now->add($cleanupAfter);

        if ($result->getStatus() === StepStatus::SUCCEEDED) {
            $this->logger->info('Runner marked task as succeeded.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
            ]);

            $changes['task_status'] = TaskStatus::SUCCEEDED;
            $changes['step_status'] = StepStatus::SUCCEEDED;

            return $changes;
        }

        if ($result->getStatus() === StepStatus::CANCELLED) {
            $this->logger->warning('Runner marked task as cancelled.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
            ]);

            $changes['task_status'] = TaskStatus::CANCELLED;
            $changes['step_status'] = StepStatus::CANCELLED;

            return $changes;
        }

        $this->logger->error('Runner marked task as failed.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
            'taskId' => $record->taskId,
            'taskClass' => $record->taskClass,
            'stepClass' => $record->stepClass,
            'errorCode' => $result->getErrorInfo()?->getCode(),
        ]);

        $changes['task_status'] = TaskStatus::FAILED;
        $changes['step_status'] = StepStatus::FAILED;

        return $changes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeErrorInfo(?ErrorInfo $errorInfo): ?array {
        if ($errorInfo === null) {
            return null;
        }

        return [
            'code' => $errorInfo->getCode(),
            'message' => $errorInfo->getMessage(),
            'details' => $errorInfo->getDetails(),
        ];
    }

    private function resolveCleanupAfterInterval(QueueRecord $record): \DateInterval {
        try {
            return $this->metadataResolver->resolveTaskMetadata($record->taskClass)->getCleanupAfter();
        } catch (\Throwable) {
            return new \DateInterval(CleanupAfter::DEFAULT_SPEC);
        }
    }

    private function hasExceededMaxRuntime(QueueRecord $record, \DateInterval $maxRuntime): bool {
        if ($record->stepStartedAt === null) {
            return false;
        }

        $deadline = $record->stepStartedAt->add($maxRuntime);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $now > $deadline;
    }
}
