<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Fixture;

use ByLexus\TaskRunner\Result\ErrorInfo;
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

final class AfterTaskHookStepFixture implements Step
{
    public function execute(Task $task): StepResult {
        $outcome = (string) ($task->getPayload()->outcome ?? 'succeed');

        if ($outcome === 'fail') {
            return StepResult::failed(
                new ErrorInfo(500, 'Hook fixture failed.'),
                [],
                'Hook fixture failed.',
            );
        }

        if ($outcome === 'cancel') {
            $task->cancel('Cancelled during execution.');

            return StepResult::succeeded(['cancelRequested' => true]);
        }

        return StepResult::succeeded(['executed' => true]);
    }
}
