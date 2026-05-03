<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Attribute;

use ByLexus\DurableTask\Enum\RetryMode as RetryModeEnum;

/**
 * Declares retry behavior.
 *
 * Defines the retry strategy for a task or step through a PHP attribute.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RetryMode
{
    public const DEFAULT_MODE = RetryModeEnum::FAIL;

    public function __construct(
        public RetryModeEnum $mode = self::DEFAULT_MODE,
    ) {
    }

    public static function createDefault(): self {
        return new self(self::DEFAULT_MODE);
    }
}
