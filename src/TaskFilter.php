<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner;

use ByLexus\TaskRunner\Enum\TaskStatus;

/**
 * Criteria for filtering tasks in TaskEnvironment::find().
 *
 * This file is part of bylexus/php-tr
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class TaskFilter {
    /**
     * @param class-string<Task>|null $taskClass
     * @param class-string|null       $stepClass
     */
    public function __construct(
        public readonly ?TaskStatus $status = null,
        public readonly ?string $taskClass = null,
        public readonly ?string $stepClass = null,
    ) {}
}
