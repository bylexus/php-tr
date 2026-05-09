<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Attribute;

/**
 * Declares automatic retry count.
 *
 * Defines how many retry attempts a task or step may perform and how long to wait before retrying.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Retries {
    public const DEFAULT_COUNT = 3;
    public const DEFAULT_DELAY_SPEC = 'PT1M';

    public function __construct(
        public int $count = self::DEFAULT_COUNT,
        public \DateInterval $delay = new \DateInterval(self::DEFAULT_DELAY_SPEC),
    ) {
    }

    public static function createDefault(): self {
        return new self(self::DEFAULT_COUNT, new \DateInterval(self::DEFAULT_DELAY_SPEC));
    }
}
