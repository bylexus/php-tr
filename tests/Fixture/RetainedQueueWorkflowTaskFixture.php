<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;

#[CleanupAfter(new \DateInterval('PT30S'), new \DateInterval('P7D'))]
final class RetainedQueueWorkflowTaskFixture extends Task
{
    public function nextStep(?Step $actStep = null): ?Step {
        if ($actStep === null) {
            return new QueueWorkflowStepFixture();
        }

        return null;
    }
}
