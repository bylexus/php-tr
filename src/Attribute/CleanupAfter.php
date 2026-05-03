<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Attribute;

/**
 * Declares task cleanup intervals.
 *
 * Defines successful and unsuccessful cleanup delays through a PHP attribute on task classes.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CleanupAfter
{
    public const DEFAULT_SUCCESSFUL_SPEC = 'PT0S';
    public const DEFAULT_UNSUCCESSFUL_SPEC = 'P7D';

    public function __construct(
        public \DateInterval $successful = new \DateInterval(self::DEFAULT_SUCCESSFUL_SPEC),
        public \DateInterval $unsuccessful = new \DateInterval(self::DEFAULT_UNSUCCESSFUL_SPEC),
    ) {
    }

    public static function createDefault(): self {
        return new self(
            new \DateInterval(self::DEFAULT_SUCCESSFUL_SPEC),
            new \DateInterval(self::DEFAULT_UNSUCCESSFUL_SPEC),
        );
    }
}
