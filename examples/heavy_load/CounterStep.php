<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

final class CounterStep extends Step {
    public function execute(Task $task): StepResult {
        if (!$task instanceof CounterTask) {
            return StepResult::failed(message: 'Unexpected task type.');
        }

        $counter = $task->getCounter() + 1;
        $task->setPayload('counter', $counter)->persistPayload();

        return StepResult::succeeded(message: "Counter: {$counter}");
    }
}
