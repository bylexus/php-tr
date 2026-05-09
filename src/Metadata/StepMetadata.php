<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Metadata;

use ByLexus\DurableTask\Enum\RetryMode;

/**
 * Stores step execution metadata.
 *
 * Represents resolved retry mode, retry count, and maximum runtime settings for a step class.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class StepMetadata
{
    private RetryMode $retryMode;
    private int $retries;
    private \DateInterval $retryDelay;
    private \DateInterval $maxRuntime;

    public function __construct(
        RetryMode $retryMode,
        int $retries,
        \DateInterval $retryDelay,
        \DateInterval $maxRuntime,
    ) {
        $this->retryMode = $retryMode;
        $this->retries = $retries;
        $this->retryDelay = clone $retryDelay;
        $this->maxRuntime = clone $maxRuntime;
    }

    public function getRetryMode(): RetryMode {
        return $this->retryMode;
    }

    public function getRetries(): int {
        return $this->retries;
    }

    public function getRetryDelay(): \DateInterval {
        return clone $this->retryDelay;
    }

    public function getMaxRuntime(): \DateInterval {
        return clone $this->maxRuntime;
    }
}
