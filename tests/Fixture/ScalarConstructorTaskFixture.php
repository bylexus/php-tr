<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;

final class ScalarConstructorTaskFixture extends Task
{
    public function __construct(private string $name) {
    }

    public function nextStep(?Step $actStep = null): ?Step {
        return null;
    }
}
