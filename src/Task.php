<?php

declare(strict_types=1);

namespace ByLexus\DurableTask;

use ByLexus\DurableTask\Enum\TaskStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\Metadata\MetadataResolver;
use ByLexus\DurableTask\Queue\PostgresQueue;
use ByLexus\DurableTask\Queue\QueueConfiguration;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Runtime\ClassInstantiator;
use Psr\Container\ContainerInterface;

abstract class Task {
    private mixed $payload = null;

    private ?int $id = null;
    private ?TaskStatus $status = null;
    private int $taskAttempt = 0;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $startedAt = null;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?\DateTimeImmutable $cleanupAt = null;
    private bool $cancelRequested = false;
    private ?string $cancelReason = null;
    private ?Step $actualStep = null;

    public function getId(): ?int {
        return $this->id;
    }

    public function getStatus(): ?TaskStatus {
        return $this->status;
    }

    public function getTaskAttempt(): int {
        return $this->taskAttempt;
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
        $this->cancelRequested = true;
        $this->cancelReason = $reason;
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

    public function enqueue(
        \PDO $connection,
        ?QueueConfiguration $configuration = null,
        ?MetadataResolver $metadataResolver = null,
    ): QueueRecord {
        $firstStep = $this->nextStep(null);

        if ($firstStep === null) {
            throw new ConfigurationException(
                sprintf('Task %s must return an initial step when enqueued.', static::class),
            );
        }

        $resolver = $metadataResolver ?? new MetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(static::class);
        $resolver->resolveStepMetadata($firstStep::class, $taskMetadata);

        $queue = new PostgresQueue($connection, $configuration);
        $record = $queue->enqueue($this, $firstStep);

        $firstStep->hydrateFromQueueRecord($record);
        $this->hydrateFromQueueRecord($record, $firstStep);

        return $record;
    }

    public function updateStep(Step $step, StepResult $result): void {
        $this->actualStep = $step;
    }

    public static function fromQueueRecord(QueueRecord $record, ?ContainerInterface $container = null): self {
        $task = ClassInstantiator::instantiate($record->taskClass, self::class, self::class, $container);
        $actualStep = Step::fromQueueRecord($record, $container);
        $task->hydrateFromQueueRecord($record, $actualStep);

        return $task;
    }

    abstract public function nextStep(?Step $actStep = null): ?Step;

    protected function hydrateFromQueueRecord(QueueRecord $record, ?Step $actualStep = null): void {
        $this->id = $record->taskId;
        $this->status = TaskStatus::from($record->taskStatus);
        $this->taskAttempt = $record->taskAttempt;
        $this->createdAt = $record->taskCreatedAt;
        $this->startedAt = $record->taskStartedAt;
        $this->finishedAt = $record->taskFinishedAt;
        $this->cleanupAt = $record->cleanupAt;
        $this->setStoredPayload($record->payload);
        $this->cancelRequested = $record->cancelRequested;
        $this->cancelReason = $record->cancelReason;
        $this->actualStep = $actualStep;
    }

    protected function setStoredPayload(mixed $payload): void {
        $this->payload = PayloadNormalizer::normalizeRoot($payload);
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
}
