<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Attribute;

/**
 * Declares maximum execution time.
 *
 * Defines the runtime limit for a task or step through a PHP attribute.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MaxRuntime
{
    public const DEFAULT_SPEC = 'PT1H'; // one hour default runtime per task

    public function __construct(
        public \DateInterval $interval = new \DateInterval(self::DEFAULT_SPEC),
    ) {
    }

    public static function createDefault(): self {
        return new self(new \DateInterval(self::DEFAULT_SPEC));
    }
}
