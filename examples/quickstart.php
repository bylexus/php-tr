<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Attribute\CleanupAfter;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

require dirname(__DIR__) . '/vendor/autoload.php';

// Keep connection settings overridable so the example can run unchanged in local setups.
// The default DSN targets PostgreSQL. quickstart only uses runSingle(), so no
// notification wakeup is involved; long-running runLoop() workers only get
// LISTEN / NOTIFY wakeups on PostgreSQL and poll on the other supported backends.
$dsn = getenv('PHP_TR_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=php_tr_test';
$user = getenv('PHP_TR_DB_USER') ?: 'postgres';
$password = getenv('PHP_TR_DB_PASS') ?: 'postgres';

final class PrintGreetingStep extends Step {
    // Implement the execute function to execute the work:
    public function execute(Task $task): StepResult {
        // Steps read input from the task payload.
        // It is advisable to use a namespaced payload, as all steps of a task share
        // the same Payload object. Here, we use the class name as namespace:
        $name = $this->name($task);

        // Do the work!
        fwrite(STDOUT, sprintf("Hello %s from a step.\n", $name));

        // and return a result:
        return StepResult::succeeded(message: 'Greeting printed.');
    }

    // Helper functions to get/set values from the Task's payload:
    public static function setName(Task $task, string $name) {
        $task->getPayload(static::class)->name = $name;
    }
    public static function name(Task $task): string {
        return $task->getPayload(static::class)->name ?? 'world';
    }
}

#[CleanupAfter(new DateInterval('PT10M'))]
final class GreetingTask extends Task {
    public function withName(string $name): self {
        // The root payload is just a stdClass, so examples can keep setup lightweight.
        $this->getPayload()->globalValue = 'some global value';

        // You need to know the exact payload path for providing data for later steps:
        // Here, we use the static function defined in the step PrintGreeting:
        PrintGreetingStep::setName($this, $name);

        return $this;
    }

    // nextStep allows the Task to form a workflow:
    // it receives the actual (done) step and can now return the next (configured) step.
    // Returning null means the flow is done:
    public function nextStep(?Step $actStep = null): ?Step {
        // Returning null ends the workflow. Returning a step queues the next unit of work.
        return $actStep === null ? new PrintGreetingStep() : null;
    }
}

$pdo = new PDO($dsn, $user, $password);
$env = new TaskEnvironment($pdo);
// Quickstart performs an explicit schema bootstrap instead of relying on worker startup side effects.
$env->getSchemaManager()->bootstrap();

// The task owns the payload. Here we seed it before enqueueing the first step.
$task = (new GreetingTask())->withName($argv[1] ?? 'PHP TR');
$record = $env->enqueue($task);

// A runner claims one queued row, hydrates the task and step, executes them, and persists the result.
$runner = $env->createRunner();

// runSingle() is the smallest useful worker mode for demos, tests, and cron-style processing.
$processed = $runner->runSingle();

fwrite(
    STDOUT,
    sprintf(
        "Processed %d task(s). Enqueued task id: %d\n",
        $processed,
        (int) $record->taskId,
    ),
);
