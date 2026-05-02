<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use Psr\Log\LoggerInterface;

final class LoggerInjectedStepFixture extends Step
{
    public function __construct(private LoggerInterface $injectedLogger) {
        parent::__construct($injectedLogger);
    }

    public function execute(Task $task): StepResult {
        $task->setPayload('loggerClass', $this->injectedLogger::class);

        return StepResult::succeeded(['loggerClass' => $this->injectedLogger::class]);
    }

    public function getInjectedLogger(): LoggerInterface {
        return $this->injectedLogger;
    }
}
