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
use ByLexus\TaskRunner\Queue\QueueRecord;
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
        $this->runnerConfiguration = $taskEnvironment->getRunnerConfiguration();
        $this->metadataResolver = $taskEnvironment->getMetadataResolver();
        $this->logger = $this->runnerConfiguration->getLogger() ?? new NullLogger();
        $this->platform = DatabasePlatformResolver::resolve($this->connection);
        $this->queue = $taskEnvironment->getDatabaseQueue();
        $this->signalHandler = new SignalHandler($this->handleStopRequested(...));

        $this->logger->debug('Runner initialized [runnerId={runnerId}]', [
            'runnerId' => $this->runnerConfiguration->getRunnerId(),
        ]);
    }

    public function runSingle(?string $runnerId = null): int {
        $this->logger->debug('Runner single pass started [runnerId={runnerId}]', [
            'runnerId' => $runnerId ?: $this->runnerConfiguration->getRunnerId(),
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
            $this->logger->debug('Runner found no queued task to process [runnerId={runnerId}]', [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
            ]);

            return 0;
        }

        return $processed;
    }

    public function runLoop(?string $runnerId = null): void {
        $this->logger->info('Runner loop started [runnerId={runnerId}]', [
            'runnerId' => $runnerId ?: $this->runnerConfiguration->getRunnerId(),
        ]);
        $this->bootstrapSchemaIfConfigured();
        $this->signalHandler->register();
        $this->ensureNotificationListener();

        while (true) {
            try {
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
            } catch (\Throwable $throwable) {
                if ($this->connection->inTransaction()) {
                    try {
                        $this->connection->rollBack();
                    } catch (\Throwable $rollbackThrowable) {
                        $this->logger->error(
                            'Runner failed to roll back transaction after loop error [runnerId={runnerId} exceptionClass={exceptionClass} errorCode={errorCode} errorMessage={errorMessage}]',
                            [
                                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                                'exceptionClass' => $rollbackThrowable::class,
                                'errorCode' => (int) $rollbackThrowable->getCode(),
                                'errorMessage' => $rollbackThrowable->getMessage(),
                            ],
                        );
                    }
                }

                $this->logger->error(
                    'Runner loop caught unhandled exception; continuing [runnerId={runnerId} exceptionClass={exceptionClass} errorCode={errorCode} errorMessage={errorMessage}]',
                    [
                        'runnerId' => $this->runnerConfiguration->getRunnerId(),
                        'exceptionClass' => $throwable::class,
                        'errorCode' => (int) $throwable->getCode(),
                        'errorMessage' => $throwable->getMessage(),
                    ],
                );

                if ($this->signalHandler->isStopRequested()) {
                    break;
                }
            }
        }

        $this->logger->info('Runner loop stopped [runnerId={runnerId}]', [
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

        $this->logger->notice(
            'Cancellation requested via {signalName}. The runner will stop after the current step completes. [runnerId={runnerId} signal={signal}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'signal' => $signal,
                'signalName' => $signalName,
            ],
        );

        $failedClaims = $this->failClaimedRunningTasksForStopRequest($signalName);

        if ($failedClaims > 0) {
            $this->logger->warning(
                'Runner marked claimed running tasks as failed after stop request [runnerId={runnerId} signal={signal} failedClaims={failedClaims}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'signal' => $signal,
                    'failedClaims' => $failedClaims,
                ],
            );
        }

        if (defined('STDERR')) {
            fwrite(STDERR, $message . PHP_EOL);
        }
    }

    private function bootstrapSchemaIfConfigured(): void {
        if (!$this->runnerConfiguration->shouldBootstrapSchemaOnStart()) {
            return;
        }

        $this->logger->info('Runner bootstrapping queue schema [runnerId={runnerId}]', [
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

        $this->logger->debug(
            'Runner registered notification listener [runnerId={runnerId} channel={channel}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'channel' => $this->queue->getNotificationChannel(),
            ],
        );
    }

    private function waitForNotification(): void {
        $timeoutMilliseconds = $this->runnerConfiguration->getNotificationWaitTimeoutSeconds() * 1000;

        $this->logger->debug(
            'Runner waiting for notification [runnerId={runnerId} timeoutMilliseconds={timeoutMilliseconds}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'timeoutMilliseconds' => $timeoutMilliseconds,
            ],
        );

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
        $this->logger->debug(
            'Runner picked up claimed step [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} taskStatus={taskStatus} stepStatus={stepStatus} claimedAt={claimedAt} claimedBy={claimedBy}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'taskStatus' => $record->taskStatus,
                'stepStatus' => $record->stepStatus,
                'claimedAt' => $record->claimedAt?->format(DATE_ATOM),
                'claimedBy' => $record->claimedBy,
            ],
        );

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

            $this->logger->info(
                'Runner claimed task for execution [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} taskStatus={taskStatus} stepStatus={stepStatus} claimedAt={claimedAt} claimedBy={claimedBy}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                    'taskStatus' => $record->taskStatus,
                    'stepStatus' => $record->stepStatus,
                    'claimedAt' => $record->claimedAt?->format(DATE_ATOM),
                    'claimedBy' => $record->claimedBy,
                ],
            );

            $taskMetadata = $this->metadataResolver->resolveTaskMetadata($record->taskClass);
            $stepMetadata = $this->metadataResolver->resolveStepMetadata($record->stepClass ?? '', $taskMetadata);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Runner failed to hydrate claimed task [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} exceptionClass={exceptionClass} errorCode={errorCode}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                ],
            );
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
                $this->logger->info(
                    'Task selected next step [taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                    [
                        'taskId' => $record->taskId,
                        'taskClass' => $record->taskClass,
                        'stepClass' => $nextStep::class,
                    ],
                );
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
            $this->logger->info(
                'Runner persisting task result [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} stepStatus={stepStatus} nextStepClass={nextStepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                    'stepStatus' => $result->getStatus()->value,
                    'nextStepClass' => $nextStep === null ? null : $nextStep::class,
                ],
            );
            $this->queue->update((int) $record->taskId, $changes, true);
            $this->connection->commit();
            $this->dispatchAfterTaskHook((int) $record->taskId);
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error(
                'Runner failed while persisting task result [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} exceptionClass={exceptionClass} errorCode={errorCode}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                ],
            );

            throw $throwable;
        }
    }

    /**
     * Best-effort dispatch of a task's afterTask() hook after its terminal state
     * has been committed.
     *
     * Fetches the freshly persisted record, hydrates the task and invokes the
     * hook for terminal states only. Hydration failures (e.g. a task class that
     * cannot be instantiated) are logged and skipped; the hook itself never
     * throws out of here because Task::dispatchAfterTaskHook() swallows errors.
     */
    private function dispatchAfterTaskHook(int $taskId): void {
        try {
            $record = $this->queue->get($taskId);
            $status = TaskStatus::from($record->taskStatus);

            if (
                $status !== TaskStatus::SUCCEEDED
                && $status !== TaskStatus::FAILED
                && $status !== TaskStatus::CANCELLED
            ) {
                return;
            }

            $task = Task::fromQueueRecord(
                $record,
                $this->runnerConfiguration->getContainer(),
                $this->logger,
                $this->queue->getAttachmentBlobStore(),
                $this->queue,
            );
            $task->setLogger($this->logger);
            $task->dispatchAfterTaskHook($status);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Runner could not dispatch afterTask hook [runnerId={runnerId} taskId={taskId} exceptionClass={exceptionClass} errorCode={errorCode} errorMessage={errorMessage}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $taskId,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                    'errorMessage' => $throwable->getMessage(),
                ],
            );
        }
    }

    private function executeStep(Task $task, Step $step): StepResult {
        try {
            $this->logger->info(
                'Runner executing step [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} stepAttempt={stepAttempt}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $task->getId(),
                    'taskClass' => $task::class,
                    'stepClass' => $step::class,
                    'stepAttempt' => $task->getStepAttempt(),
                ],
            );

            $result = $step->execute($task);

            $isFailedStep = $result->getStatus() === StepStatus::FAILED;

            if ($isFailedStep) {
                $this->logger->error(
                    'Runner completed step execution [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} stepAttempt={stepAttempt} stepStatus={stepStatus} errorCode={errorCode} errorMessage={errorMessage}]',
                    [
                        'runnerId' => $this->runnerConfiguration->getRunnerId(),
                        'taskId' => $task->getId(),
                        'taskClass' => $task::class,
                        'stepClass' => $step::class,
                        'stepAttempt' => $task->getStepAttempt(),
                        'stepStatus' => $result->getStatus()->value,
                        'errorCode' => $result->getErrorInfo()?->getCode(),
                        'errorMessage' => $result->getMessage() ?? $result->getErrorInfo()?->getMessage(),
                    ],
                );
            } else {
                $this->logger->debug(
                    'Runner completed step execution [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} stepAttempt={stepAttempt} stepStatus={stepStatus}]',
                    [
                        'runnerId' => $this->runnerConfiguration->getRunnerId(),
                        'taskId' => $task->getId(),
                        'taskClass' => $task::class,
                        'stepClass' => $step::class,
                        'stepAttempt' => $task->getStepAttempt(),
                        'stepStatus' => $result->getStatus()->value,
                    ],
                );
            }

            return $result;
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Runner caught step exception [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} exceptionClass={exceptionClass} errorCode={errorCode}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $task->getId(),
                    'taskClass' => $task::class,
                    'stepClass' => $step::class,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                ],
            );

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
            $finalizedTaskIds = [];

            foreach ($this->queue->findStartedRunningTasks() as $record) {
                if ($record->taskId === null || $record->stepClass === null) {
                    continue;
                }

                try {
                    $taskMetadata = $this->metadataResolver->resolveTaskMetadata($record->taskClass);
                    $stepMetadata = $this->metadataResolver->resolveStepMetadata($record->stepClass, $taskMetadata);
                } catch (\Throwable $throwable) {
                    $this->logger->error(
                        'Runner failed to resolve metadata for running task cleanup [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} exceptionClass={exceptionClass} errorCode={errorCode}]',
                        [
                            'runnerId' => $this->runnerConfiguration->getRunnerId(),
                            'taskId' => $record->taskId,
                            'taskClass' => $record->taskClass,
                            'stepClass' => $record->stepClass,
                            'exceptionClass' => $throwable::class,
                            'errorCode' => (int) $throwable->getCode(),
                        ],
                    );

                    continue;
                }

                if (!$this->hasExceededMaxRuntime($record, $stepMetadata->getMaxRuntime())) {
                    continue;
                }

                $this->logger->warning(
                    'Runner marked timed out running task as failed during cleanup [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                    [
                        'runnerId' => $this->runnerConfiguration->getRunnerId(),
                        'taskId' => $record->taskId,
                        'taskClass' => $record->taskClass,
                        'stepClass' => $record->stepClass,
                    ],
                );

                $this->queue->update(
                    $record->taskId,
                    $this->changesForExpiredRunningTask($taskMetadata),
                    true,
                );
                $finalizedTaskIds[] = $record->taskId;
                $timedOutClaims++;
            }

            $this->connection->commit();

            foreach ($finalizedTaskIds as $taskId) {
                $this->dispatchAfterTaskHook($taskId);
            }

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
            $this->logger->warning(
                'Runner skipped stop-request failure marking during active transaction [runnerId={runnerId} signal={signal}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'signal' => $signalName,
                ],
            );

            return 0;
        }

        $this->connection->beginTransaction();

        try {
            $failedClaims = 0;
            $finalizedTaskIds = [];

            foreach ($this->queue->findClaimedRunningTasks($this->runnerConfiguration->getRunnerId()) as $record) {
                if ($record->taskId === null) {
                    continue;
                }

                $this->queue->update(
                    $record->taskId,
                    $this->changesForStopRequestedRunningTask($record, $signalName),
                );
                $finalizedTaskIds[] = $record->taskId;
                $failedClaims++;
            }

            $this->connection->commit();

            foreach ($finalizedTaskIds as $taskId) {
                $this->dispatchAfterTaskHook($taskId);
            }

            return $failedClaims;
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error(
                'Runner failed while marking claimed running tasks after stop request [runnerId={runnerId} signal={signal} exceptionClass={exceptionClass} errorCode={errorCode}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'signal' => $signalName,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                ],
            );

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

            $this->logger->warning(
                'Runner detected task cancellation request [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ],
            );

            return StepResult::cancelled(
                errorInfo: new ErrorInfo(499, $message),
                meta: ['requested' => true],
                message: $message,
            );
        }

        if ($this->hasExceededMaxRuntime($record, $maxRuntime)) {
            $this->logger->warning(
                'Runner detected step timeout before execution [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ],
            );

            return StepResult::failed(
                errorInfo: new ErrorInfo(408, self::MAX_RUNTIME_EXCEEDED_MESSAGE),
                meta: ['timedOut' => true],
                message: self::MAX_RUNTIME_EXCEEDED_MESSAGE,
            );
        }

        $result = $this->executeStep($task, $step);

        if ($this->hasExceededMaxRuntime($record, $maxRuntime)) {
            $this->logger->warning(
                'Runner detected step timeout after execution [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ],
            );

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

        $this->logger->warning(
            'Runner detected task cancellation request [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
            ],
        );

        return StepResult::cancelled(
            errorInfo: new ErrorInfo(499, $message),
            meta: ['requested' => true],
            message: $message,
        );
    }

    private function failedResultFromNextStepException(Task $task, Step $step, \Throwable $throwable): StepResult {
        $this->logger->error(
            'Runner caught nextStep exception [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} exceptionClass={exceptionClass} errorCode={errorCode}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $task->getId(),
                'taskClass' => $task::class,
                'stepClass' => $step::class,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ],
        );

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

        $this->logger->error(
            'Runner persisting claim failure [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} exceptionClass={exceptionClass} errorCode={errorCode}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'exceptionClass' => $throwable::class,
                'errorCode' => $errorCode,
            ],
        );

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
            $this->dispatchAfterTaskHook((int) $record->taskId);
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
            'payload_json' => $task->getPayload(),
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

        $isRetriableMode = $retryMode === RetryMode::RESTART || $retryMode === RetryMode::SKIP;
        $status = $result->getStatus();

        // Precedence: retry > next step > terminal
        if ($status === StepStatus::FAILED && $isRetriableMode && $record->stepAttempt < $retries) {
            return $this->applyRetryChanges($changes, $record, $retryMode, $retryDelay, $now);
        }

        $skipToNext = $status === StepStatus::FAILED && $retryMode === RetryMode::SKIP && $nextStep !== null;
        $succeedToNext = $status === StepStatus::SUCCEEDED && $nextStep !== null;

        if ($skipToNext || $succeedToNext) {
            /** @var Step $nextStep */
            return $this->applyNextStepChanges($changes, $record, $nextStep, $now, $skipToNext);
        }

        return $this->applyTerminalChanges($changes, $record, $result, $taskMetadata, $retryMode, $now);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function applyRetryChanges(
        array $changes,
        QueueRecord $record,
        RetryMode $retryMode,
        \DateInterval $retryDelay,
        \DateTimeImmutable $now,
    ): array {
        $this->logger->warning(
            'Runner requeued failed step for retry [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} stepAttempt={stepAttempt} retryMode={retryMode}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'stepAttempt' => $record->stepAttempt + 1,
                'retryMode' => $retryMode->value,
            ],
        );

        $changes['task_status'] = TaskStatus::QUEUED;
        $changes['step_status'] = StepStatus::QUEUED;
        $changes['step_attempt'] = $record->stepAttempt + 1;
        $changes['step_started_at'] = null;
        $changes['step_finished_at'] = null;
        $changes['available_at'] = $now->add($retryDelay);

        return $changes;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function applyNextStepChanges(
        array $changes,
        QueueRecord $record,
        Step $nextStep,
        \DateTimeImmutable $now,
        bool $skippedPreviousStep,
    ): array {
        if ($skippedPreviousStep) {
            $this->logger->warning(
                'Runner skipped failed step and queued next step [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} nextStepClass={nextStepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                    'nextStepClass' => $nextStep::class,
                ],
            );
        } else {
            $this->logger->info(
                'Runner queued next step [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $nextStep::class,
                ],
            );
        }

        $changes['task_status'] = TaskStatus::QUEUED;
        $changes['step_class'] = $nextStep::class;
        $changes['step_status'] = StepStatus::QUEUED;
        $changes['step_attempt'] = 0;
        $changes['step_started_at'] = null;
        $changes['step_finished_at'] = null;
        $changes['available_at'] = $now;

        return $changes;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function applyTerminalChanges(
        array $changes,
        QueueRecord $record,
        StepResult $result,
        \ByLexus\TaskRunner\Metadata\TaskMetadata $taskMetadata,
        RetryMode $retryMode,
        \DateTimeImmutable $now,
    ): array {
        $status = $result->getStatus();
        $changes['task_finished_at'] = $now;
        $changes['step_finished_at'] = $now;

        // SKIP on a failed final step: task succeeds, step is recorded as SKIPPED.
        if ($status === StepStatus::FAILED && $retryMode === RetryMode::SKIP) {
            $this->logger->warning(
                'Runner skipped failed final step and marked task as succeeded [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ],
            );

            $changes['cleanup_at'] = $now->add(
                $this->resolveCleanupAfterIntervalForStatus($taskMetadata, StepStatus::SUCCEEDED),
            );
            $changes['task_status'] = TaskStatus::SUCCEEDED;
            $changes['step_status'] = StepStatus::SKIPPED;

            return $changes;
        }

        $changes['cleanup_at'] = $now->add(
            $this->resolveCleanupAfterIntervalForStatus($taskMetadata, $status),
        );

        if ($status === StepStatus::SUCCEEDED) {
            $this->logger->info(
                'Runner marked task as succeeded [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ],
            );

            $changes['task_status'] = TaskStatus::SUCCEEDED;
            $changes['step_status'] = StepStatus::SUCCEEDED;

            return $changes;
        }

        if ($status === StepStatus::CANCELLED) {
            $this->logger->warning(
                'Runner marked task as cancelled [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass}]',
                [
                    'runnerId' => $this->runnerConfiguration->getRunnerId(),
                    'taskId' => $record->taskId,
                    'taskClass' => $record->taskClass,
                    'stepClass' => $record->stepClass,
                ],
            );

            $changes['task_status'] = TaskStatus::CANCELLED;
            $changes['step_status'] = StepStatus::CANCELLED;

            return $changes;
        }

        $this->logger->error(
            'Runner marked task as failed [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} errorCode={errorCode} errorMessage={errorMessage}]',
            [
                'runnerId' => $this->runnerConfiguration->getRunnerId(),
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'errorCode' => $result->getErrorInfo()?->getCode(),
                'errorMessage' => $result->getMessage() ?? $result->getErrorInfo()?->getMessage(),
            ],
        );

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
