<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Runtime;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

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
