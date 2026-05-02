<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;

final class ConstructorInjectedStepFixture extends Step
{
    public function __construct(private ConstructorInjectedServiceFixture $service) {
    }

    public function execute(Task $task): StepResult {
        $task->setPayload('stepService', $this->service->getName());

        return StepResult::succeeded(['injectedStepService' => $this->service->getName()]);
    }
}
