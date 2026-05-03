<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Result;

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;

/**
 * Represents a step execution result.
 *
 * Carries the outcome, message, metadata, and optional error information produced by a workflow step.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class StepResult {
    private StepStatus $status;
    private ?ErrorInfo $errorInfo;
    /** @var array<string, mixed> */
    private array $meta;
    private ?string $message;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        StepStatus $status,
        ?ErrorInfo $errorInfo = null,
        array $meta = [],
        ?string $message = null,
    ) {
        if ($status === StepStatus::QUEUED || $status === StepStatus::RUNNING) {
            throw new ConfigurationException('StepResult status must be succeeded, failed, or cancelled.');
        }

        $this->status = $status;
        $this->errorInfo = $errorInfo;
        $this->meta = $meta;
        $this->message = $message;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function succeeded(
        array $meta = [],
        ?string $message = null,
    ): self {
        return new self(StepStatus::SUCCEEDED, null, $meta, $message);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function failed(
        ?ErrorInfo $errorInfo = null,
        array $meta = [],
        ?string $message = null,
    ): self {
        return new self(StepStatus::FAILED, $errorInfo, $meta, $message);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function cancelled(
        ?ErrorInfo $errorInfo = null,
        array $meta = [],
        ?string $message = null,
    ): self {
        return new self(StepStatus::CANCELLED, $errorInfo, $meta, $message);
    }

    public function getStatus(): StepStatus {
        return $this->status;
    }

    public function getErrorInfo(): ?ErrorInfo {
        return $this->errorInfo;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array {
        return $this->meta;
    }

    public function getMessage(): ?string {
        return $this->message;
    }
}
