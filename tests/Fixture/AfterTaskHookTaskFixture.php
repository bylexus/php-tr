<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Fixture;

use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

final class AfterTaskHookTaskFixture extends Task
{
    /**
     * Records every afterTask() invocation across all instances so tests can
     * assert that the hook fired with the persisted terminal state.
     *
     * @var list<array{status: string, taskId: int|null, persistedStatus: string|null}>
     */
    public static array $invocations = [];

    public static function reset(): void {
        self::$invocations = [];
    }

    public function nextStep(?Step $actStep = null): ?Step {
        if ($actStep === null) {
            return new AfterTaskHookStepFixture();
        }

        return null;
    }

    protected function afterTask(TaskStatus $status): void {
        self::$invocations[] = [
            'status' => $status->value,
            'taskId' => $this->getId(),
            'persistedStatus' => $this->getStatus()?->value,
        ];

        if (($this->getPayload()->throwInHook ?? false) === true) {
            throw new \RuntimeException('afterTask hook exploded.');
        }
    }
}
