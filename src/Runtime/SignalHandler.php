<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Runtime;

/**
 * Handles runner shutdown signals.
 *
 * Listens for process signals and exposes graceful shutdown state for the task runner.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class SignalHandler
{
    private bool $stopRequested = false;

    public function register(): void {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $this->requestStop(...));

        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $this->requestStop(...));
        }
    }

    public function isStopRequested(): bool {
        return $this->stopRequested;
    }

    public function requestStop(): void {
        $this->stopRequested = true;
    }
}
