<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner;

use ByLexus\TaskRunner\Attribute\CleanupAfter;
use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Enum\RetryMode;
use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\Metadata\MetadataResolver;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\Queue\SchemaManager;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatform;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatformResolver;
use ByLexus\TaskRunner\Result\ErrorInfo;
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Runtime\SignalHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Executes task workflows.
 *
 * Coordinates queue access, step processing, and task lifecycle transitions for the task runner.
 *
 * This file is part of bylexus/php-tr
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
class Runner {
    private const EXPIRED_QUEUE_CLEANUP_INTERVAL_SECONDS = 10;
    private const MAX_RUNTIME_EXCEEDED_MESSAGE = 'Step exceeded its configured maximum runtime.';
    private const STOP_REQUESTED_FAILURE_CODE = 499;
    private const STOP_REQUESTED_FAILURE_MESSAGE = 'Runner stop was requested before the current step completed.';

    private TaskEnvironment $taskEnvironment;
    private \PDO $connection;
    private QueueConfiguration $queueConfiguration;
    private RunnerConfiguration $runnerConfiguration;
    private MetadataResolver $metadataResolver;
    private DatabaseQueue $queue;
    private DatabasePlatform $platform;
    private SignalHandler $signalHandler;
    private LoggerInterface $logger;
    private bool $notificationListenerRegistered = false;
    private ?int $lastExpiredQueueCleanupTimestamp = null;

    public function __construct(TaskEnvironment $taskEnvironment) {
        $this->taskEnvironment = $taskEnvironment;
        $this->connection = $taskEnvironment->getConnection();
        $this->queueConfiguration = $taskEnvironment->getQueueConfiguration();
        $this->runnerConfiguration = $taskEnvironment->getRunnerConfiguration();
        $this->metadataResolver = $taskEnvironment->getMetadataResolver();
        $this->logger = $this->runnerConfiguration->getLogger() ?? new NullLogger();
        $this->platform = DatabasePlatformResolver::resolve($this->connection);
        $this->queue = $taskEnvironment->getDatabaseQueue();
        $this->signalHandler = new SignalHandler($this->handleStopRequested(...));

        $this->logger->debug('Runner initialized.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
    }

    public function runSingle(): int {
        $this->logger->debug('Runner single pass started.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
        $this->bootstrapSchemaIfConfigured();
        $this->signalHandler->register();
        $this->cleanupExpiredQueueRecords();

        $processed = 0;

        while (true) {
            $record = $this->queue->claim($this->runnerConfiguration->getRunnerId());

            if ($record === null) {
                break;
            }

            $this->processClaimedRecord($record);
            $processed++;

            if ($this->signalHandler->isStopRequested()) {
                break;
            }
        }

        if ($processed === 0) {
            $this->logger->debug('Runner found no queued task to process.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
            ]);

            return 0;
        }

        return $processed;
    }

    public function runLoop(): void {
        $this->logger->info('Runner loop started.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
        $this->bootstrapSchemaIfConfigured();
        $this->signalHandler->register();
        $this->ensureNotificationListener();

        while (true) {
            $this->cleanupExpiredQueueRecordsIfDue();

            $record = $this->queue->claim($this->runnerConfiguration->getRunnerId());

            if ($record === null) {
                if ($this->signalHandler->isStopRequested()) {
                    break;
                }

                $this->waitForNotification();

                if ($this->signalHandler->isStopRequested()) {
                    break;
                }

                continue;
            }

            $this->processClaimedRecord($record);

            if ($this->signalHandler->isStopRequested()) {
                break;
            }
        }

        $this->logger->info('Runner loop stopped.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
    }

    private function handleStopRequested(int $signal): void {
        $signalName = match (true) {
            $signal === SIGTERM => 'SIGTERM',
            defined('SIGINT') && $signal === SIGINT => 'SIGINT',
            default => sprintf('signal %d', $signal),
        };
        $message = sprintf(
            'Cancellation requested via %s. The runner will stop after the current step completes.',
            $signalName,
        );

        $this->logger->notice($message, [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
            'signal' => $signal,
        ]);

        $failedClaims = $this->failClaimedRunningTasksForStopRequest($signalName);

        if ($failedClaims > 0) {
            $this->logger->warning('Runner marked claimed running tasks as failed after stop request.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'signal' => $signal,
                'failedClaims' => $failedClaims,
            ]);
        }

        if (defined('STDERR')) {
            fwrite(STDERR, $message . PHP_EOL);
        }
    }

    private function bootstrapSchemaIfConfigured(): void {
        if (!$this->runnerConfiguration->shouldBootstrapSchemaOnStart()) {
            return;
        }

        $this->logger->info('Runner bootstrapping queue schema.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);

        $this->taskEnvironment->getSchemaManager()->bootstrap();
    }

    private function ensureNotificationListener(): void {
        if ($this->notificationListenerRegistered || !$this->platform->supportsNotifications()) {
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
        $this->logger->debug('Runner picked up claimed step.', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
            'taskId' => $record->taskId,
            'taskClass' => $record->taskClass,
            'stepClass' => $record->stepClass,
            'taskStatus' => $record->taskStatus,
            'stepStatus' => $record->stepStatus,
            'claimedAt' => $record->claimedAt?->format(DATE_ATOM),
            'claimedBy' => $record->claimedBy,
        ]);

        try {
            $task = Task::fromQueueRecord(
                $record,
                $this->runnerConfiguration->getContainer(),
                $this->logger,
                $this->queue->getAttachmentBlobStore(),
                $this->queue,
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
            $currentRecord = $this->queue->get((int) $record->taskId, true);

            if ($this->isCancellationRequested($currentRecord)) {
                $result = $this->cancelledResultFromRecord($currentRecord);
            }

            $task->updateStep($step, $result);
            $nextStep = null;
            $retryMode = $stepMetadata->getRetryMode();
            $retries = $stepMetadata->getRetries();
            $retryDelay = $stepMetadata->getRetryDelay();

            if (!$this->isCancellationRequested($currentRecord)) {
                try {
                    $nextStep = $task->nextStep($step);
                } catch (\Throwable $throwable) {
                    $result = $this->failedResultFromNextStepException($task, $step, $throwable);
                    $task->updateStep($step, $result);
                    $retryMode = RetryMode::FAIL;
                    $retries = 0;
                    $retryDelay = new \DateInterval('PT0S');
                }
            }

            if ($nextStep !== null) {
                $nextStep->setLogger($this->logger);

                $this->logger->info('Task selected next step.', [
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $nextStep::class,
                ]);
            }

            $changes = $this->changesForResult(
                $currentRecord,
                $task,
                $result,
                $nextStep,
                $taskMetadata,
                $retryMode,
                $retries,
                $retryDelay,
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
                'stepAttempt' => $step->getStepAttempt(),
            ]);

            $result = $step->execute($task);

            $this->logger->debug('Runner completed step execution.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $task->getId(),
                'taskClass' => $task::class,
                'stepClass' => $step::class,
                'stepAttempt' => $step->getStepAttempt(),
                'stepStatus' => $result->getStatus()->value,
            ]);

            return $result;
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

    private function cleanupExpiredQueueRecords(): void {
        $this->failExpiredRunningTasks();
        $this->queue->deleteExpired();
    }

    private function cleanupExpiredQueueRecordsIfDue(?int $currentTimestamp = null): void {
        $timestamp = $currentTimestamp ?? time();

        if (!$this->shouldCleanupExpiredQueueRecords($timestamp)) {
            return;
        }

        $this->cleanupExpiredQueueRecords();
        $this->lastExpiredQueueCleanupTimestamp = $timestamp;
    }

    private function shouldCleanupExpiredQueueRecords(int $currentTimestamp): bool {
        if ($this->lastExpiredQueueCleanupTimestamp === null) {
            return true;
        }

        $elapsedSeconds = $currentTimestamp - $this->lastExpiredQueueCleanupTimestamp;

        return $elapsedSeconds >= self::EXPIRED_QUEUE_CLEANUP_INTERVAL_SECONDS;
    }

    private function failExpiredRunningTasks(): int {
        $this->connection->beginTransaction();

        try {
            $timedOutClaims = 0;

            foreach ($this->queue->findStartedRunningTasks() as $record) {
                if ($record->taskId === null || $record->stepClass === null) {
                    continue;
                }

                try {
                    $taskMetadata = $this->metadataResolver->resolveTaskMetadata($record->taskClass);
                    $stepMetadata = $this->metadataResolver->resolveStepMetadata($record->stepClass, $taskMetadata);
                } catch (\Throwable $throwable) {
                    $this->logger->error('Runner failed to resolve metadata for running task cleanup.', [
                        'runnerId' => $this->runnerConfiguration->getRunnerId(),
                        'taskId' => $record->taskId,
                        'taskClass' => $record->taskClass,
                        'stepClass' => $record->stepClass,
                        'exceptionClass' => $throwable::class,
                        'errorCode' => (int) $throwable->getCode(),
                    ]);

                    continue;
                }

                if (!$this->hasExceededMaxRuntime($record, $stepMetadata->getMaxRuntime())) {
                    continue;
                }

                $this->logger->warning('Runner marked timed out running task as failed during cleanup.', [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ]);

                $this->queue->update(
                    $record->taskId,
                    $this->changesForExpiredRunningTask($taskMetadata),
                    true,
                );
                $timedOutClaims++;
            }

            $this->connection->commit();

            return $timedOutClaims;
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $throwable;
        }
    }

    private function failClaimedRunningTasksForStopRequest(string $signalName): int {
        if ($this->connection->inTransaction()) {
            $this->logger->warning('Runner skipped stop-request failure marking during active transaction.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'signal' => $signalName,
            ]);

            return 0;
        }

        $this->connection->beginTransaction();

        try {
            $failedClaims = 0;

            foreach ($this->queue->findClaimedRunningTasks($this->runnerConfiguration->getRunnerId()) as $record) {
                if ($record->taskId === null) {
                    continue;
                }

                $this->queue->update(
                    $record->taskId,
                    $this->changesForStopRequestedRunningTask($record, $signalName),
                );
                $failedClaims++;
            }

            $this->connection->commit();

            return $failedClaims;
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error('Runner failed while marking claimed running tasks after stop request.', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'signal' => $signalName,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ]);

            return 0;
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
                errorInfo: new ErrorInfo(408, self::MAX_RUNTIME_EXCEEDED_MESSAGE),
                meta: ['timedOut' => true],
                message: self::MAX_RUNTIME_EXCEEDED_MESSAGE,
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
                    self::MAX_RUNTIME_EXCEEDED_MESSAGE,
                ),
                meta: ['timedOut' => true],
                message: self::MAX_RUNTIME_EXCEEDED_MESSAGE,
            );
        }

        return $result;
    }

    private function isCancellationRequested(QueueRecord $record): bool {
        return $record->cancelRequested || $record->taskStatus === TaskStatus::CANCELLED->value;
    }

    private function cancelledResultFromRecord(QueueRecord $record): StepResult {
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

    private function failedResultFromNextStepException(Task $task, Step $step, \Throwable $throwable): StepResult {
        $this->logger->error('Runner caught nextStep exception.', [
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
                ['exception' => $throwable::class, 'nextStepFailed' => true],
            ),
            meta: ['nextStepFailed' => true],
            message: $throwable->getMessage(),
        );
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
        \ByLexus\TaskRunner\Metadata\TaskMetadata $taskMetadata,
        \ByLexus\TaskRunner\Enum\RetryMode $retryMode,
        int $retries,
        \DateInterval $retryDelay,
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
            && $retryMode === \ByLexus\TaskRunner\Enum\RetryMode::RESTART
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
            $changes['available_at'] = $now->add($retryDelay);

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
        $changes['cleanup_at'] = $now->add(
            $this->resolveCleanupAfterIntervalForStatus($taskMetadata, $result->getStatus()),
        );

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

    /**
     * @return array<string, mixed>
     */
    private function changesForExpiredRunningTask(\ByLexus\TaskRunner\Metadata\TaskMetadata $taskMetadata): array {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $errorInfo = new ErrorInfo(408, self::MAX_RUNTIME_EXCEEDED_MESSAGE);

        return [
            'task_status' => TaskStatus::FAILED,
            'step_status' => StepStatus::FAILED,
            'task_finished_at' => $now,
            'step_finished_at' => $now,
            'cleanup_at' => $now->add($taskMetadata->getUnsuccessfulCleanupAfter()),
            'result_json' => [
                'status' => StepStatus::FAILED->value,
                'meta' => ['timedOut' => true],
                'message' => self::MAX_RUNTIME_EXCEEDED_MESSAGE,
            ],
            'error_json' => $this->normalizeErrorInfo($errorInfo),
            'last_error_code' => (string) $errorInfo->getCode(),
            'last_error_message' => $errorInfo->getMessage(),
            'claimed_at' => null,
            'claimed_by' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function changesForStopRequestedRunningTask(QueueRecord $record, string $signalName): array {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $message = sprintf('%s Signal: %s.', self::STOP_REQUESTED_FAILURE_MESSAGE, $signalName);
        $errorInfo = new ErrorInfo(
            self::STOP_REQUESTED_FAILURE_CODE,
            $message,
            ['signal' => $signalName, 'stopRequested' => true],
        );

        return [
            'task_status' => TaskStatus::FAILED,
            'step_status' => StepStatus::FAILED,
            'task_finished_at' => $now,
            'step_finished_at' => $now,
            'cleanup_at' => $now->add($this->resolveCleanupAfterInterval($record)),
            'result_json' => [
                'status' => StepStatus::FAILED->value,
                'meta' => ['signal' => $signalName, 'stopRequested' => true],
                'message' => $message,
            ],
            'error_json' => $this->normalizeErrorInfo($errorInfo),
            'last_error_code' => (string) $errorInfo->getCode(),
            'last_error_message' => $errorInfo->getMessage(),
            'claimed_at' => null,
            'claimed_by' => null,
        ];
    }

    private function resolveCleanupAfterInterval(QueueRecord $record): \DateInterval {
        try {
            return $this->metadataResolver->resolveTaskMetadata($record->taskClass)->getUnsuccessfulCleanupAfter();
        } catch (\Throwable) {
            return new \DateInterval(CleanupAfter::DEFAULT_UNSUCCESSFUL_SPEC);
        }
    }

    private function resolveCleanupAfterIntervalForStatus(
        \ByLexus\TaskRunner\Metadata\TaskMetadata $taskMetadata,
        StepStatus $status,
    ): \DateInterval {
        if ($status === StepStatus::SUCCEEDED) {
            return $taskMetadata->getSuccessfulCleanupAfter();
        }

        return $taskMetadata->getUnsuccessfulCleanupAfter();
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
