<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner;

use ByLexus\TaskRunner\Attribute\CleanupAfter;
use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\Metadata\MetadataResolver;
use ByLexus\TaskRunner\Queue\AttachmentBlobStore;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Runtime\ClassInstantiator;
use ByLexus\TaskRunner\Runtime\ContextualLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for tasks.
 *
 * Provides the shared workflow state, payload handling, and enqueue or restore logic for user-defined tasks.
 *
 * This file is part of bylexus/php-tr
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
abstract class Task implements DisplayName {
    public const PRIO_VERY_HIGH = 1;
    public const PRIO_HIGH = 2;
    public const PRIO_NORMAL = 3;
    public const PRIO_LOW = 4;
    public const PRIO_VERY_LOW = 5;

    private mixed $payload = null;
    private ?LoggerInterface $logger = null;

    private ?int $id = null;
    private string $taskClass;
    private ?string $stepClass = null;
    private ?TaskStatus $status = null;
    private int $priority = self::PRIO_NORMAL;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $startedAt = null;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?\DateTimeImmutable $cleanupAt = null;
    private ?StepStatus $stepStatus = null;
    private int $stepAttempt = 0;
    private ?\DateTimeImmutable $stepStartedAt = null;
    private ?\DateTimeImmutable $stepFinishedAt = null;
    private mixed $result = null;
    private mixed $error = null;
    private ?\DateTimeImmutable $availableAt = null;
    private ?\DateTimeImmutable $claimedAt = null;
    private ?string $claimedBy = null;
    private ?string $lastErrorCode = null;
    private ?string $lastErrorMessage = null;
    private bool $cancelRequested = false;
    private ?string $cancelReason = null;
    private ?\DateTimeImmutable $updatedAt = null;
    private ?Step $actualStep = null;
    private ?DatabaseQueue $queue = null;

    public function __construct(?LoggerInterface $logger = null) {
        $this->taskClass = static::class;

        if ($logger !== null) {
            $this->setLogger($logger);
        }

        $this->logger?->debug('Task created.', [
            'taskClass' => static::class,
        ]);
    }

    #[\Override]
    public function displayName(): string {
        return static::class;
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getTaskClass(): string {
        return $this->taskClass;
    }

    public function getStepClass(): ?string {
        return $this->stepClass;
    }

    public function getStatus(): ?TaskStatus {
        return $this->status;
    }

    public function getPriority(): int {
        return $this->priority;
    }

    public function getCreatedAt(): ?\DateTimeImmutable {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable {
        return $this->finishedAt;
    }

    public function getCleanupAt(): ?\DateTimeImmutable {
        return $this->cleanupAt;
    }

    public function getStepStatus(): ?StepStatus {
        return $this->stepStatus;
    }

    public function getStepAttempt(): int {
        return $this->stepAttempt;
    }

    public function getStepStartedAt(): ?\DateTimeImmutable {
        return $this->stepStartedAt;
    }

    public function getStepFinishedAt(): ?\DateTimeImmutable {
        return $this->stepFinishedAt;
    }

    public function getResult(): mixed {
        return $this->result;
    }

    public function getError(): mixed {
        return $this->error;
    }

    public function getAvailableAt(): ?\DateTimeImmutable {
        return $this->availableAt;
    }

    public function getClaimedAt(): ?\DateTimeImmutable {
        return $this->claimedAt;
    }

    public function getClaimedBy(): ?string {
        return $this->claimedBy;
    }

    public function getLastErrorCode(): ?string {
        return $this->lastErrorCode;
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    public function cancel(string $reason): void {
        if ($this->queue === null || $this->id === null) {
            $this->cancelRequested = true;
            $this->cancelReason = $reason;
            $this->status = TaskStatus::CANCELLED;

            return;
        }

        $connection = $this->queue->getConnection();
        $startedTransaction = false;

        if (!$connection->inTransaction()) {
            $connection->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $record = $this->queue->get($this->id, true);
            $changes = $this->changesForCancellation($record, $reason);

            if ($changes !== []) {
                $record = $this->queue->update($this->id, $changes, true);
            }

            if ($startedTransaction) {
                $connection->commit();
            }

            $payload = $this->payload;
            $this->hydrateFromQueueRecord($record, $this->actualStep, $this->queue->getAttachmentBlobStore(), $this->queue);
            $this->payload = $payload;
        } catch (\Throwable $throwable) {
            if ($startedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
    }

    public function isCancelRequested(): bool {
        return $this->cancelRequested;
    }

    public function getCancelReason(): ?string {
        return $this->cancelReason;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable {
        return $this->updatedAt;
    }

    public function actualStep(): ?Step {
        return $this->actualStep;
    }

    public function setLogger(LoggerInterface $logger): void {
        $this->logger = new ContextualLogger($logger, function (): array {
            return [
                'taskId' => $this->id,
                'stepClass' => $this->actualStep === null ? null : $this->actualStep::class,
            ];
        });
    }

    public function getLogger(): ?LoggerInterface {
        return $this->logger;
    }

    public function getPayload(?string $property = null): mixed {
        $rootPayload = $this->materializeRootPayload();

        if ($property === null) {
            return $rootPayload;
        }

        if (!property_exists($rootPayload, $property) || $rootPayload->{$property} === null) {
            $rootPayload->{$property} = new \stdClass();
        }

        return $rootPayload->{$property};
    }

    public function hasStoredPayload(): bool {
        return $this->payload !== null;
    }

    public function setPayload(mixed $propertyOrPayload, mixed $payload = null): self {
        $argumentCount = func_num_args();

        if ($argumentCount === 1) {
            $this->payload = PayloadNormalizer::normalizeRoot($propertyOrPayload);

            return $this;
        }

        if ($argumentCount !== 2 || !is_string($propertyOrPayload)) {
            throw new ConfigurationException(sprintf(
                'setPayload() expects %s or %s.',
                'setPayload(mixed $payload)',
                'setPayload(string $property, mixed $payload)',
            ));
        }

        $this->materializeRootPayload()->{$propertyOrPayload} = $payload;
        return $this;
    }

    public function reload(): self {
        $this->assertPersistedQueueBound(__METHOD__);

        $record = $this->queue->get($this->id);
        $this->hydrateFromQueueRecord($record, $this->actualStep, $this->queue->getAttachmentBlobStore(), $this->queue);
        return $this;
    }

    public function persistPayload(): self {
        $this->assertPersistedQueueBound(__METHOD__);

        $connection = $this->queue->getConnection();
        $startedTransaction = false;

        if (!$connection->inTransaction()) {
            $connection->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $record = $this->queue->update(
                $this->id,
                ['payload_json' => $this->getPayload()],
                true,
            );

            if ($startedTransaction) {
                $connection->commit();
            }

            $this->setStoredPayload($record->payload, $this->queue->getAttachmentBlobStore());
        } catch (
            \Throwable $throwable
        ) {
            if ($startedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
        return $this;
    }

    public function enqueue(TaskEnvironment $taskEnvironment, int $priority = self::PRIO_NORMAL): QueueRecord {
        self::assertValidPriority($priority);

        $firstStep = $this->nextStep(null);

        if ($firstStep === null) {
            throw new ConfigurationException(
                sprintf('Task %s must return an initial step when enqueued.', static::class),
            );
        }

        $resolver = $taskEnvironment->getMetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(static::class);
        $resolver->resolveStepMetadata($firstStep::class, $taskMetadata);

        $this->logger?->info('Task enqueue requested.', [
            'taskClass' => static::class,
            'stepClass' => $firstStep::class,
        ]);

        $queue = $taskEnvironment->getDatabaseQueue();
        $record = $queue->enqueue($this, $firstStep, $priority);

        $this->hydrateFromQueueRecord($record, $firstStep, $queue->getAttachmentBlobStore(), $queue);

        return $record;
    }

    public function updateStep(Step $step, StepResult $result): void {
        $this->actualStep = $step;

        $this->logger?->info('Task step updated.', [
            'taskId' => $this->id,
            'taskClass' => static::class,
            'stepClass' => $step::class,
            'stepStatus' => $result->getStatus()->value,
            'stepAttempt' => $this->stepAttempt,
        ]);
    }

    public static function fromQueueRecord(
        QueueRecord $record,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
        ?AttachmentBlobStore $attachmentBlobStore = null,
        ?DatabaseQueue $queue = null,
    ): self {
        $task = ClassInstantiator::instantiate($record->taskClass, self::class, self::class, $container, $logger);

        if ($logger !== null) {
            $task->setLogger($logger);
        }

        $actualStep = self::instantiateStepForRecord($task, $record, $container, $logger);
        $task->hydrateFromQueueRecord($record, $actualStep, $attachmentBlobStore, $queue);

        return $task;
    }

    private static function instantiateStepForRecord(
        self $task,
        QueueRecord $record,
        ?ContainerInterface $container,
        ?LoggerInterface $logger,
    ): ?Step {
        if ($record->stepClass === null) {
            return null;
        }

        if ($record->stepClass === $record->taskClass && $task instanceof Step) {
            return $task;
        }

        $step = ClassInstantiator::instantiate($record->stepClass, Step::class, 'Step', $container, $logger);

        if (!$step instanceof Step) {
            throw new ConfigurationException(sprintf(
                'Configured step class must implement %s: %s',
                Step::class,
                $record->stepClass,
            ));
        }

        return $step;
    }

    abstract public function nextStep(?Step $actStep = null): ?Step;

    protected function hydrateFromQueueRecord(
        QueueRecord $record,
        ?Step $actualStep = null,
        ?AttachmentBlobStore $attachmentBlobStore = null,
        ?DatabaseQueue $queue = null,
    ): void {
        $this->id = $record->taskId;
        $this->taskClass = $record->taskClass;
        $this->stepClass = $record->stepClass;
        $this->status = TaskStatus::from($record->taskStatus);
        $this->priority = $record->priority;
        $this->createdAt = $record->taskCreatedAt;
        $this->startedAt = $record->taskStartedAt;
        $this->finishedAt = $record->taskFinishedAt;
        $this->cleanupAt = $record->cleanupAt;
        $this->stepStatus = $record->stepStatus === null ? null : StepStatus::from($record->stepStatus);
        $this->stepAttempt = $record->stepAttempt;
        $this->stepStartedAt = $record->stepStartedAt;
        $this->stepFinishedAt = $record->stepFinishedAt;
        $this->setStoredPayload($record->payload, $attachmentBlobStore);
        $this->result = $record->result;
        $this->error = $record->error;
        $this->availableAt = $record->availableAt;
        $this->claimedAt = $record->claimedAt;
        $this->claimedBy = $record->claimedBy;
        $this->lastErrorCode = $record->lastErrorCode;
        $this->lastErrorMessage = $record->lastErrorMessage;
        $this->cancelRequested = $record->cancelRequested;
        $this->cancelReason = $record->cancelReason;
        $this->updatedAt = $record->updatedAt;
        $this->actualStep = $actualStep;
        $this->queue = $queue;

        $this->logger?->debug('Task hydrated from queue record.', [
            'taskId' => $record->taskId,
            'taskClass' => $record->taskClass,
            'taskStatus' => $record->taskStatus,
            'stepClass' => $record->stepClass,
            'stepStatus' => $record->stepStatus,
        ]);
    }

    protected function setStoredPayload(mixed $payload, ?AttachmentBlobStore $attachmentBlobStore = null): void {
        $this->payload = PayloadNormalizer::hydrateStoredRoot($payload, $attachmentBlobStore);
    }

    /**
     * @return array<string, mixed>
     */
    private function changesForCancellation(QueueRecord $record, string $reason): array {
        $changes = [
            'cancel_requested' => true,
            'cancel_reason' => $reason,
        ];

        if (
            $record->taskStatus === TaskStatus::SUCCEEDED->value
            || $record->taskStatus === TaskStatus::FAILED->value
        ) {
            return [];
        }

        if ($record->taskStatus === TaskStatus::CANCELLED->value) {
            return $changes;
        }

        $changes['task_status'] = TaskStatus::CANCELLED;

        if (
            $record->taskStatus === TaskStatus::RUNNING->value
            || $record->stepStatus === StepStatus::RUNNING->value
        ) {
            return $changes;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $changes['step_status'] = StepStatus::CANCELLED;
        $changes['task_finished_at'] = $now;
        $changes['step_finished_at'] = $now;
        $changes['cleanup_at'] = $now->add($this->resolveCancellationCleanupAfter());
        $changes['result_json'] = [
            'status' => StepStatus::CANCELLED->value,
            'meta' => ['requested' => true],
            'message' => $reason,
        ];
        $changes['error_json'] = [
            'code' => 499,
            'message' => $reason,
            'details' => [],
        ];
        $changes['last_error_code'] = '499';
        $changes['last_error_message'] = $reason;
        $changes['claimed_at'] = null;
        $changes['claimed_by'] = null;

        return $changes;
    }

    private static function assertValidPriority(int $priority): void {
        if ($priority < self::PRIO_VERY_HIGH || $priority > self::PRIO_VERY_LOW) {
            throw new ConfigurationException(sprintf(
                'Task priority must be between %d and %d, got %d.',
                self::PRIO_VERY_HIGH,
                self::PRIO_VERY_LOW,
                $priority,
            ));
        }
    }

    private function materializeRootPayload(): \stdClass {
        if ($this->payload === null) {
            $this->payload = new \stdClass();

            return $this->payload;
        }

        if (!$this->payload instanceof \stdClass) {
            $this->payload = PayloadNormalizer::normalizeRoot($this->payload);
        }

        return $this->payload;
    }

    private function assertPersistedQueueBound(string $operation): void {
        if ($this->queue === null || $this->id === null) {
            throw new ConfigurationException(sprintf(
                '%s requires an enqueued task bound to a database queue.',
                $operation,
            ));
        }
    }

    private function resolveCancellationCleanupAfter(): \DateInterval {
        try {
            return (new MetadataResolver())->resolveTaskMetadata(static::class)->getUnsuccessfulCleanupAfter();
        } catch (\Throwable) {
            return new \DateInterval(CleanupAfter::DEFAULT_UNSUCCESSFUL_SPEC);
        }
    }
}
