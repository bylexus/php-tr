<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Metadata;

use ByLexus\DurableTask\Enum\RetryMode;

/**
 * Stores task execution metadata.
 *
 * Represents resolved retry, runtime, and cleanup settings for a task class.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class TaskMetadata
{
    private RetryMode $retryMode;
    private int $retries;
    private \DateInterval $maxRuntime;
    private \DateInterval $successfulCleanupAfter;
    private \DateInterval $unsuccessfulCleanupAfter;

    public function __construct(
        RetryMode $retryMode,
        int $retries,
        \DateInterval $maxRuntime,
        \DateInterval $successfulCleanupAfter,
        \DateInterval $unsuccessfulCleanupAfter,
    ) {
        $this->retryMode = $retryMode;
        $this->retries = $retries;
        $this->maxRuntime = clone $maxRuntime;
        $this->successfulCleanupAfter = clone $successfulCleanupAfter;
        $this->unsuccessfulCleanupAfter = clone $unsuccessfulCleanupAfter;
    }

    public function getRetryMode(): RetryMode {
        return $this->retryMode;
    }

    public function getRetries(): int {
        return $this->retries;
    }

    public function getMaxRuntime(): \DateInterval {
        return clone $this->maxRuntime;
    }

    public function getSuccessfulCleanupAfter(): \DateInterval {
        return clone $this->successfulCleanupAfter;
    }

    public function getUnsuccessfulCleanupAfter(): \DateInterval {
        return clone $this->unsuccessfulCleanupAfter;
    }
}
