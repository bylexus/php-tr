<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Attribute\CleanupAfter;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

require_once __DIR__ . '/CounterStep.php';

#[CleanupAfter(new DateInterval('PT2H'))]
final class CounterTask extends Task {
    public const MAX_COUNT = 100;

    public function nextStep(?Step $actStep = null): ?Step {
        if ($this->getCounter() < self::MAX_COUNT) {
            return new CounterStep();
        }
        return null;
    }

    public function getCounter(): int {
        $value = $this->getPayload('counter');
        return is_int($value) ? $value : 0;
    }
}
