<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Runtime;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Adds context to log messages.
 *
 * Decorates a PSR-3 logger and merges runtime context into each emitted log entry.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class ContextualLogger extends AbstractLogger
{
    /** @var \Closure(): array<string, mixed> */
    private \Closure $contextProvider;

    /**
     * @param callable(): array<string, mixed> $contextProvider
     */
    public function __construct(
        private LoggerInterface $logger,
        callable $contextProvider,
    ) {
        $this->contextProvider = \Closure::fromCallable($contextProvider);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void {
        $this->logger->log($level, $message, array_merge(($this->contextProvider)(), $context));
    }
}
