<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use Psr\Log\LoggerInterface;

final class ServiceAndLoggerInjectedTaskFixture extends Task
{
    public function __construct(
        private ConstructorInjectedServiceFixture $service,
        private LoggerInterface $injectedLogger,
    ) {
        parent::__construct($injectedLogger);
    }

    public function nextStep(?Step $actStep = null): ?Step {
        if ($actStep === null) {
            return new ServiceAndLoggerInjectedStepFixture($this->service, $this->injectedLogger);
        }

        return null;
    }

    public function updateStep(Step $step, StepResult $result): void {
        parent::updateStep($step, $result);
        $this->setPayload('taskService', $this->service->getName());
    }

    public function getInjectedServiceName(): string {
        return $this->service->getName();
    }

    public function getInjectedLogger(): LoggerInterface {
        return $this->injectedLogger;
    }
}
