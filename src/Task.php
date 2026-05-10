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
abstract class Task {
    public const PRIO_VERY_HIGH = 1;
    public const PRIO_HIGH = 2;
    public const PRIO_NORMAL = 3;
    public const PRIO_LOW = 4;
    public const PRIO_VERY_LOW = 5;

    private mixed $payload = null;
    private ?LoggerInterface $baseLogger = null;
    private ?LoggerInterface $logger = null;

    private ?int $id = null;
    private ?TaskStatus $status = null;
    private int $priority = self::PRIO_NORMAL;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $startedAt = null;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?\DateTimeImmutable $cleanupAt = null;
    private bool $cancelRequested = false;
    private ?string $cancelReason = null;
    private ?Step $actualStep = null;
    private ?DatabaseQueue $queue = null;

    public function __construct(?LoggerInterface $logger = null) {
        if ($logger !== null) {
            $this->setLogger($logger);
        }

        $this->logger?->debug('Task created.', [
            'taskClass' => static::class,
        ]);
    }

    public function getId(): ?int {
        return $this->id;
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

            $this->applyCancelledRecord($record);
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

    public function actualStep(): ?Step {
        return $this->actualStep;
    }

    public function setLogger(LoggerInterface $logger): void {
        $this->baseLogger = $logger;
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

    public static function getPayloadClassContext(): string {
        return static::class;
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

    public function getStoredPayload(): mixed {
        return $this->materializeRootPayload();
    }

    public function hasStoredPayload(): bool {
        return $this->payload !== null;
    }

    public function setPayload(mixed $propertyOrPayload, mixed $payload = null): void {
        $argumentCount = func_num_args();

        if ($argumentCount === 1) {
            $this->payload = PayloadNormalizer::normalizeRoot($propertyOrPayload);

            return;
        }

        if ($argumentCount !== 2 || !is_string($propertyOrPayload)) {
            throw new ConfigurationException(sprintf(
                'setPayload() expects %s or %s.',
                'setPayload(mixed $payload)',
                'setPayload(string $property, mixed $payload)',
            ));
        }

        $this->materializeRootPayload()->{$propertyOrPayload} = $payload;
    }

    public function enqueue(QueueContext $queueContext, int $priority = self::PRIO_NORMAL): QueueRecord {
        self::assertValidPriority($priority);

        $firstStep = $this->nextStep(null);

        if ($firstStep === null) {
            throw new ConfigurationException(
                sprintf('Task %s must return an initial step when enqueued.', static::class),
            );
        }

        $resolver = $queueContext->getMetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(static::class);
        $resolver->resolveStepMetadata($firstStep::class, $taskMetadata);

        if ($this->baseLogger !== null) {
            $firstStep->setLogger($this->baseLogger);
        }

        $this->logger?->info('Task enqueue requested.', [
            'taskClass' => static::class,
            'stepClass' => $firstStep::class,
        ]);

        $queue = $queueContext->getDatabaseQueue();
        $record = $queue->enqueue($this, $firstStep, $priority);

        $firstStep->hydrateFromQueueRecord($record);
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
            'stepAttempt' => $step->getStepAttempt(),
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

        $actualStep = Step::fromQueueRecord($record, $container, $logger);
        $task->hydrateFromQueueRecord($record, $actualStep, $attachmentBlobStore, $queue);

        return $task;
    }

    abstract public function nextStep(?Step $actStep = null): ?Step;

    protected function hydrateFromQueueRecord(
        QueueRecord $record,
        ?Step $actualStep = null,
        ?AttachmentBlobStore $attachmentBlobStore = null,
        ?DatabaseQueue $queue = null,
    ): void {
        $this->id = $record->taskId;
        $this->status = TaskStatus::from($record->taskStatus);
        $this->priority = $record->priority;
        $this->createdAt = $record->taskCreatedAt;
        $this->startedAt = $record->taskStartedAt;
        $this->finishedAt = $record->taskFinishedAt;
        $this->cleanupAt = $record->cleanupAt;
        $this->setStoredPayload($record->payload, $attachmentBlobStore);
        $this->cancelRequested = $record->cancelRequested;
        $this->cancelReason = $record->cancelReason;
        $this->actualStep = $actualStep;
        $this->queue = $queue;

        if ($this->actualStep !== null) {
            $this->actualStep->hydrateFromQueueRecord($record);
        }

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

    private function resolveCancellationCleanupAfter(): \DateInterval {
        try {
            return (new MetadataResolver())->resolveTaskMetadata(static::class)->getUnsuccessfulCleanupAfter();
        } catch (\Throwable) {
            return new \DateInterval(CleanupAfter::DEFAULT_UNSUCCESSFUL_SPEC);
        }
    }

    private function applyCancelledRecord(QueueRecord $record): void {
        $this->status = TaskStatus::from($record->taskStatus);
        $this->priority = $record->priority;
        $this->createdAt = $record->taskCreatedAt;
        $this->startedAt = $record->taskStartedAt;
        $this->finishedAt = $record->taskFinishedAt;
        $this->cleanupAt = $record->cleanupAt;
        $this->cancelRequested = $record->cancelRequested;
        $this->cancelReason = $record->cancelReason;

        if ($this->actualStep !== null) {
            $this->actualStep->hydrateFromQueueRecord($record);
        }
    }
}
