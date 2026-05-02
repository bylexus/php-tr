<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use Psr\Log\LoggerInterface;

final class LoggerInjectedTaskFixture extends Task
{
    public function __construct(private LoggerInterface $injectedLogger) {
        parent::__construct($injectedLogger);
    }

    public function nextStep(?Step $actStep = null): ?Step {
        if ($actStep === null) {
            return new LoggerInjectedStepFixture($this->injectedLogger);
        }

        return null;
    }

    public function getInjectedLogger(): LoggerInterface {
        return $this->injectedLogger;
    }
}
