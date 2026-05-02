<?php

declare(strict_types=1);

namespace ByLexus\DurableTask;

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Runtime\ClassInstantiator;
use ByLexus\DurableTask\Runtime\ContextualLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

abstract class Step {
    private ?int $taskId = null;
    private ?StepStatus $status = null;
    private int $stepAttempt = 0;
    private ?\DateTimeImmutable $startedAt = null;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?LoggerInterface $baseLogger = null;
    private ?LoggerInterface $logger = null;

    public function __construct(?LoggerInterface $logger = null) {
        if ($logger !== null) {
            $this->setLogger($logger);
        }

        $this->logger?->debug('Step created.', [
            'stepClass' => static::class,
        ]);
    }

    public static function getPayloadClassContext(): string {
        return static::class;
    }

    public function getTaskId(): ?int {
        return $this->taskId;
    }

    public function getStatus(): ?StepStatus {
        return $this->status;
    }

    public function getStepAttempt(): int {
        return $this->stepAttempt;
    }

    public function getStartedAt(): ?\DateTimeImmutable {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable {
        return $this->finishedAt;
    }

    public function setLogger(LoggerInterface $logger): void {
        $this->baseLogger = $logger;
        $this->logger = new ContextualLogger($logger, function (): array {
            return [
                'taskId' => $this->taskId,
                'stepClass' => static::class,
            ];
        });
    }

    public function getLogger(): ?LoggerInterface {
        return $this->logger;
    }

    public static function fromQueueRecord(
        QueueRecord $record,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
    ): ?self {
        if ($record->stepClass === null) {
            return null;
        }

        $step = ClassInstantiator::instantiate($record->stepClass, self::class, self::class, $container, $logger);

        if ($logger !== null) {
            $step->setLogger($logger);
        }

        $step->hydrateFromQueueRecord($record);

        return $step;
    }

    abstract public function execute(Task $task): StepResult;

    public function hydrateFromQueueRecord(QueueRecord $record): void {
        $this->taskId = $record->taskId;
        $this->status = $record->stepStatus === null ? null : StepStatus::from($record->stepStatus);
        $this->stepAttempt = $record->stepAttempt;
        $this->startedAt = $record->stepStartedAt;
        $this->finishedAt = $record->stepFinishedAt;

        $this->logger?->debug('Step hydrated from queue record.', [
            'taskId' => $record->taskId,
            'stepClass' => $record->stepClass,
            'stepStatus' => $record->stepStatus,
            'stepAttempt' => $record->stepAttempt,
        ]);
    }
}
