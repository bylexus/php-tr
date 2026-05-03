<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Attribute;

/**
 * Declares automatic retry count.
 *
 * Defines how many retry attempts a task or step may perform through a PHP attribute.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Retries {
    public const DEFAULT_COUNT = 3;

    public function __construct(public int $count = self::DEFAULT_COUNT) {
    }

    public static function createDefault(): self {
        return new self(self::DEFAULT_COUNT);
    }
}
