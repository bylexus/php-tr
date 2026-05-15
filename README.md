# PHP Task Runner - a Queue / Task Runner for background tasks

[![Build Test](https://github.com/bylexus/php-tr/actions/workflows/build-test.yml/badge.svg)](https://github.com/bylexus/php-tr/actions/workflows/build-test.yml)

⚠️ **Work in progress! Use with caution for now! Very early version!** ⚠️

PHP Task Runner is a database-backed workflow library for PHP >= 8.3. It is meant to queue and run jobs that are to be processed in the background of a frontend application (e.g. queue an email to be sent in the background).

It is not a standalone queue/runner, but meant to be integrated in your / a existing framework. So this library needs to be integrated in your environment.

You model work as a `Task` that defines a workflow consisting of `Step`s. Enqueued Tasks then get worked on step-by-step by a Runner. The library stores the Tasks and Steps state in the database so multiple runner processes can safely compete for queued work.

The public surface is intentionally small and framework agnostic:

- `Task` defines the workflow, consisting of Steps and owns the payload needed to process the steps.
- `Step` executes one unit of work and returns a `StepResult`.
- `Runner` claims queued Tasks/Steps, executes them, and persists the next state.

`Task` and `Step` classes are kept separately, with the goal that single-purpose `Step` classes can be mixed and matched by several `Task` classes. For example, a generic `SendMail` step can be used by many tasks to send information emails.

This README is written for experienced PHP developers who want to integrate the library into an existing application or framework.

## Requirements

- PHP 8.3+
- `ext-pdo`
- One of the supported PDO backends:
    - PostgreSQL
    - MySQL >= 8.0
    - MariaDB >= 10.6
    - SQLite
- Autoloadable task and step classes in every process that enqueues or runs work

## Installation

```bash
composer require bylexus/php-tr
```

## Quickstart

See a full example in [examples/quickstart.php](examples/quickstart.php).

This quickstart just implements a single Task with a single Step to work on. This chapter explains how to get startet in detail.


### Setup

Create a `TaskEnvironment` instance: the `TaskEnvironment` is the configuration object that contains
all the needed dependencies:

```php
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\Queue\QueueConfiguration;

$env = new TaskEnvironment(
    connection: getDBConn(), // get your PDO connection as usual
    queueConfiguration: new QueueConfiguration(schemaName: 'appschema'),
    // ...
);

```

The env object will be used for all interaction with the Tasks / runner.


Create the DB objects automatically:

```php
// Reuse the same TaskEnvironment for schema management, enqueueing, and runners.
$env->getSchemaManager()->bootstrap();
```

or by exporting the needed SQL through your own tooling:

```php

$ddl = $env->getSchemaManager()->exportDdl();

// Dump, log, or feed $ddl into your migration tooling.
```

### Create a Step class

First you define one (or multiple) single-purpose Step classes. Steps are one piece of work that can be used in a / multiple Task. Here, we create a simple Step that just prints a message:

```php
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\Result\StepResult;

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
    public static function name(Tast $task): string {
        return $task->getPayload(static::class)->name ?? 'world';
    }
}
```

### Create a Task class to define your workflow

Now, define the `Task` class to define your workflow: Define the needed payload data used by your steps,
and create a workflow in the `nextStep` function:

```php
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\Attribute\CleanupAfter;

#[CleanupAfter(new DateInterval('PT10M'), new DateInterval('P7D'))]
final class GreetingTask extends Task {
    // withName is just a helper method to set up the correct payload:
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
```

### Dispatch a task

Now you're ready to dispatch the task:

```php

// The task owns the payload. Here we seed it before enqueueing the first step.
$task = (new GreetingTask())->withName('Ada Lovelace');
$env->enqueue($task);

// Optional: lower numbers are picked first by runners.
$env->enqueue($task, priority: Task::PRIO_HIGH);
```

Tasks default to priority `3` (`Task::PRIO_NORMAL`). You can choose values from `1` to `5` using the built-in constants:

- `Task::PRIO_VERY_HIGH` = `1`
- `Task::PRIO_HIGH` = `2`
- `Task::PRIO_NORMAL` = `3`
- `Task::PRIO_LOW` = `4`
- `Task::PRIO_VERY_LOW` = `5`

When multiple queued tasks are available, runners claim the highest-priority work first, then fall back to `available_at` and creation time.

If your queued tasks need constructor injection, configure the container and logger once on `TaskEnvironment` and reuse that same object for enqueueing, lookup, and runner creation.

### Start a runner to work on the tasks

A Runner can now be instantiated in a separate script, e.g. a script that runs server-side as a daemon:

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;
use Psr\Log\LoggerInterface;

$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
$env = new TaskEnvironment(
    connection: $pdo,
    queueConfiguration: $queueConfiguration,
    container: $container, // provide your PSR-11 compatible Dependency Injection container
    logger: $container->get(LoggerInterface::class), // PSR-3 compatible logger
    runnerConfiguration: new RunnerConfiguration(runnerId: 'app-worker-1'),
);
// A runner claims one queued row, hydrates the task and step, executes them, and persists the result.
$runner = $env->createRunner();
$runner->runLoop();
```

## Concepts

### `Task` is the workflow instance

A task is the long-lived object stored in the queue row. It owns the payload (arbitary data for the steps) and decides the workflow graph through `nextStep()`.

```php
abstract class Task {
    abstract public function nextStep(?Step $actStep = null): ?Step;
}
```

Important points:

- The task is the only payload owner. Steps receive the task and read or mutate payload through `$task->getPayload()`.
- The steps itself read the payload data, so you have to know the exact name / path of the payload.
- The runner persists the task payload after every step execution.
- The runner calls `nextStep()` of your task to fetch the next work unit. Return `null` to indicate done.

### `Step` is one unit of work

Every step implements `execute(Task $task): StepResult`.

```php
abstract class Step {
    abstract public function execute(Task $task): StepResult;
}
```

Use a step for one unit of work. A step should be undependant of other steps / tasks: It receives its information from the Payload of the task (and can also modify it).

After the work is done (or failed), the step must return a `StepResult` indicating the state of the result.
The result itself must not contain data for futher steps (that goes to the payload), but result information only.

- return `StepResult::succeeded()` when the step was successful
- return `StepResult::failed()` with `ErrorInfo` when the step failed
- throw an exception when it cannot recover locally; the runner converts that exception into a failed `StepResult`

### `Runner` is the worker process

The runner claims one queued task/step at a time, hydrates the task and step class from the row, executes the step, and persists one of these outcomes:

- queue the same step again for a retry
- queue the next step
- mark the task as `succeeded`, `failed` or `cancelled`

Use:

- `runSingle()` for cron-style polling, tests, or one-shot commands
- `runLoop()` for a long-running worker process, waiting for new tasks

`runLoop()` uses PostgreSQL `LISTEN` / `NOTIFY` only when the active PDO connection supports it. MySQL, MariaDB, and SQLite use the polling variant only and sleep for the configured wait timeout between claim attempts when no task is available.

You can safely start multiple runners, as each task can only be claimed by one runner at a time: This allows for parallel execution of multiple tasks. Useful if your runner gets blocked with long-running tasks.

### Task priority

Each queued task row stores a numeric priority. Priority `1` is the highest priority and `5` is the lowest. If you do not pass a priority when enqueueing, the library stores `3`.

```php
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\Task;

$env = new TaskEnvironment($pdo);
$task = (new WelcomeTask())->withEmail('ada@example.com');

$env->enqueue($task, priority: Task::PRIO_VERY_HIGH);
```

This is useful when some background work should jump ahead of normal queue traffic without needing a separate queue table.

## Defining Tasks And Steps

This is the smallest useful pattern:

```php
<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

final class SendWelcomeMailStep extends Step {
    public function execute(Task $task): StepResult {
        $payload = $task->getPayload();

        // send message using your own mail service here
        $task->getPayload(static::class)->sentAt = (new DateTimeImmutable())->format(DATE_ATOM);

        return StepResult::succeeded(message: 'Welcome mail sent.');
    }
}

final class WelcomeTask extends Task {
    public function withEmail(string $email): self {
        $this->getPayload()->email = $email;

        return $this;
    }

    public function nextStep(?Step $actStep = null): ?Step {
        return $actStep === null ? new SendWelcomeMailStep() : null;
    }
}
```

Payload access patterns:

- `$task->getPayload()` returns the root payload object.
- `$task->getPayload(SomeStep::class)` returns a namespaced child object for one step or concern.
- `$task->setPayload($payload)` replaces the root payload.
- `$task->setPayload(SomeStep::class, $value)` sets a namespaced payload fragment.
- `$task->reload()` refreshes task state (including cancellation flags and payload) from the database.
- `$task->persistPayload()` stores only the current payload to the queue row.

The namespaced payload pattern is usually the cleanest way to avoid collisions between steps in a larger workflow.

### Long-running step pattern (reload, cancellation checks, payload checkpoints)

When a step can run for a long time, call `reload()` at checkpoints to inspect fresh state from the queue,
stop early when cancellation was requested, and optionally persist incremental payload data.

```php
<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Result\ErrorInfo;
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

final class ProcessLargeImportStep extends Step {
    public function execute(Task $task): StepResult {
        foreach ($this->chunkIds() as $chunkId) {
            $task->reload();

            if ($task->isCancelRequested()) {
                return StepResult::cancelled(
                    errorInfo: new ErrorInfo(499, $task->getCancelReason() ?? 'Cancellation requested.'),
                    meta: ['chunkId' => $chunkId],
                    message: $task->getCancelReason() ?? 'Cancellation requested.',
                );
            }

            $this->processChunk($chunkId);

            $task->getPayload(static::class)->lastProcessedChunkId = $chunkId;
            $task->persistPayload();
        }

        return StepResult::succeeded(message: 'Import completed.');
    }

    /**
     * @return iterable<int>
     */
    private function chunkIds(): iterable {
        yield from [101, 102, 103];
    }

    private function processChunk(int $chunkId): void {
        // Your long-running work for this chunk.
    }
}
```

### File attachments in payloads

Often you want to use files as part of your workflow (e.g. send emails with attachments). The library allows you to store needed files as part of the payload in a separate table.

Use `FileAttachment` to attach files directly in the task payload. The queue stores only metadata plus a blob reference in `payload_json`; the binary content itself is stored in the attachment blob table that `SchemaManager` creates together with the main queue table.

```php
use ByLexus\TaskRunner\FileAttachment;

$task->getPayload()->mail = (object) [
    'to' => 'alex@example.com',
    'attachment' => FileAttachment::fromFile(__DIR__ . '/invoice.pdf'),
];
```

Inside a step, the hydrated payload value is again a `FileAttachment` object, so you can restore it to a local file when your mailer or external service needs a path:

```php
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;

final class SendMailStep extends Step {
    public function execute(Task $task): StepResult {
        $attachment = $task->getPayload()->mail->attachment;
        $targetPath = sys_get_temp_dir() . '/invoice.pdf';

        $attachment->toFile($targetPath);

        // pass $targetPath to your mailer here

        return StepResult::succeeded();
    }
}
```

## Schema Management

The queue uses one database table plus indexes. You have three supported ways to manage it.

### 1. Explicit bootstrap in your application

Use this when your framework has an installation command, deploy hook, or startup sequence.

```php
use ByLexus\TaskRunner\TaskEnvironment;

(new TaskEnvironment($pdo))->getSchemaManager()->bootstrap();
```

If you already use a `TaskEnvironment`, its `SchemaManager` can also manage the schema for that queue:

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;

$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
$env = new TaskEnvironment($pdo, $queueConfiguration);
$env->getSchemaManager()->bootstrap();
$env->getSchemaManager()->validate();
```

This is the most predictable option in production. It creates the queue table if not present, and / or updates it.

The queue schema includes a `priority` column with default value `3`, so existing producers can keep enqueueing tasks without passing a priority explicitly.

### 2. Export the DDL through your own configured tooling

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;

$env = new TaskEnvironment(
    $pdo,
    new QueueConfiguration('custom_queue_table', 'background_jobs'),
);

$ddl = $env->getSchemaManager()->exportDdl();
```

This returns the exact DDL string for the configured queue table and backend resolved from your live PDO connection. The library does not ship a standalone dump script anymore; wiring the export into your migration or deployment tooling is your responsibility.

### 3. Let the runner bootstrap once at startup

```php
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;

$runnerConfiguration = new RunnerConfiguration(
    bootstrapSchemaOnStart: true,
);
$env = new TaskEnvironment($pdo, runnerConfiguration: $runnerConfiguration);
$runner = $env->createRunner();
```

This is useful for local development or controlled deployments. It is optional and disabled by default.

### Custom queue tables and schemas

Use `QueueConfiguration` when you want more than one queue table, need a non-default name, or want to place queue objects in a dedicated namespace.

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;

$queueConfiguration = new QueueConfiguration('app_background_jobs');

$env = new TaskEnvironment($pdo, $queueConfiguration, runnerConfiguration: $runnerConfiguration);
$env->enqueue($task);
$env->createRunner()->runLoop();
```

The same `QueueConfiguration` must be used consistently by producers, runners, and schema bootstrap.

`TaskEnvironment` is the simplest way to enforce that consistency in application code because it exposes `getTask()`, `getTasks()`, `createRunner()`, and `getSchemaManager()` on the same shared queue context backed by the configured PDO connection.

To place the queue in a specific namespace, pass the schema name as the second argument:

```php
$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
```

Backend-specific behavior:

- PostgreSQL: the second argument is a schema name, and schema bootstrap creates it automatically when needed.
- MySQL / MariaDB: the second argument is used as the database/catalog name qualifier. It must already exist; bootstrap does not create it.
- SQLite: schema names are not supported.

## Running Workers

### Single pass worker

```php
$env = new TaskEnvironment($pdo);
$runner = $env->createRunner();
$processed = $runner->runSingle();
```

`runSingle()` drains queued work until no claimable rows remain. It returns `0` when the queue is empty and the number of steps when it processed at least one step during that pass.

### Long-running worker

Start a long-running runner using the `runLoop()` function. This is best used in conjunction with a process manager like `systemd` or `supervisord`.

```php
$env = new TaskEnvironment(
    $pdo,
    runnerConfiguration: new RunnerConfiguration(
        runnerId: 'billing-worker-1',
        notificationWaitTimeoutSeconds: 15,
    ),
);

$runner = $env->createRunner();

$runner->runLoop();
```

Run multiple worker processes when you want parallel execution. The queue uses backend-specific claim and locking behavior so different runner processes do not claim the same task row at the same time.

## Constructor Service Injection And Framework Integration

The library supports PSR-11 constructor injection for both tasks and steps. This is the integration path you will usually want inside Symfony, Laravel, Laminas, Spiral, a custom container, or your own application kernel.

The framework-oriented example lives in:

- [examples/framework_integration/framework_enqueue.php](examples/framework_integration/framework_enqueue.php)
- [examples/framework_integration/FrameworkDemoContainer.php](examples/framework_integration/FrameworkDemoContainer.php)
- [examples/framework_integration/ImportUserProfileTask.php](examples/framework_integration/ImportUserProfileTask.php)
- [examples/framework_integration/FetchUserProfileStep.php](examples/framework_integration/FetchUserProfileStep.php)
- [examples/framework_integration/PersistUserProfileStep.php](examples/framework_integration/PersistUserProfileStep.php)

The integration contract is:

1. Your producer and worker processes must both load the same task and step classes.
2. Your worker should configure a shared `TaskEnvironment` with the PSR-11 container and logger used for task lookup and runner hydration.
3. Constructor parameters must be resolvable class or interface types. Builtin parameters must have defaults.
4. `LoggerInterface` is resolved from the container when available; otherwise the runner logger or `NullLogger` is used.
5. If a claimed task or step cannot be instantiated, the runner persists a terminal failure for that row.

Typical worker bootstrap:

```php
<?php

declare(strict_types=1);

use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;
use Psr\Log\LoggerInterface;

$container = $app->getContainer();

$env = new TaskEnvironment(
    $pdo,
    null,
    $container,
    $container->get(LoggerInterface::class),
    new RunnerConfiguration(bootstrapSchemaOnStart: false),
);

$runner = $env->createRunner();

$runner->runLoop();
```

Typical Task that takes services in the Constructor:

```php
final class ImportUserProfileTask extends Task {
    // When hydrated from the Runner, the services are looked up in the configured Service container:
    public function __construct(
        private ExampleImportPolicy $policy,
        private ExampleUserApi $api,
        private ExampleUserRepository $repository,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(logger: $logger);
    }
}
```

If your framework already has a command bus, message scheduler, or domain service layer, the usual pattern is:

- controllers/services create a task and enqueue it
- dedicated worker commands run `Runner::runLoop()` or repeated `runSingle()` calls
- task and step classes stay in normal application namespaces and use the same service container as the rest of the app

## Attributes

You can configure specific behaviour of your Tasks / Steps by setting PHP Attributes. Attributes are read from the task and step classes at runtime.

| Attribute | Allowed on | Default | Effect |
| --- | --- | --- | --- |
| `#[CleanupAfter(...)]` | task | `successful: PT0S`, `unsuccessful: P7D` | How long terminal rows stay in the queue table before cleanup removes them. Successful tasks and unsuccessful tasks are configured separately. |
| `#[Retries(...)]` | step | `count: 3`, `delay: PT1M` | Maximum retry count and minimum delay before retrying a failed step. |
| `#[RetryMode(...)]` | step | `fail` | `restart` requeues the same failed step while the other modes end in a terminal failure. |
| `#[MaxRuntime(...)]` | task, step | `PT1H` | Maximum allowed runtime for one step attempt. This is a best-effort deadline: the runner marks overdue steps as failed before or after execution, and cleanup ticks can also fail stale running claims. It does not interrupt PHP while a step is executing; the running process keeps running until the step returns or throws. |

Example:

```php
<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Attribute\CleanupAfter;
use ByLexus\TaskRunner\Attribute\MaxRuntime;
use ByLexus\TaskRunner\Attribute\Retries;
use ByLexus\TaskRunner\Attribute\RetryMode;
use ByLexus\TaskRunner\Enum\RetryMode as RetryModeEnum;

#[CleanupAfter(new DateInterval('PT6H'), new DateInterval('P7D'))]
final class ExportTask extends Task {
    // task-wide runtime and cleanup settings belong here
}

#[Retries(5, new DateInterval('PT2M'))]
#[RetryMode(RetryModeEnum::RESTART)]
#[MaxRuntime(new DateInterval('PT30S'))]
final class CallRemoteApiStep extends Step {
    // this step retries up to 5 times with a 2 minute backoff
}

Use a shorter or longer `DateInterval` when a failing dependency should only be retried after some backoff, for example while waiting for an external service to recover.
```

`MaxRuntime` is not a hard kill switch. If a step is already running when it crosses the deadline, the worker process is not interrupted mid-call. Another runner can mark that claim as failed on a later cleanup tick, and the still-running worker may still write a later state update when it eventually returns.

## Logging

Logging is PSR-3 based.

- Pass a logger into `RunnerConfiguration::logger` when you want runner and queue logs.
- Pass a logger into task or step constructors when you instantiate them yourself.
- Hydrated tasks and steps receive the active runner logger automatically.

The example container in [examples/Support/ExampleServiceContainer.php](examples/Support/ExampleServiceContainer.php) shows the intended shape.

## Examples

Worker examples default to PostgreSQL DSNs. That gives `runLoop()` workers `LISTEN` / `NOTIFY` wakeups. If you point the same examples at MySQL, MariaDB, or SQLite, they still work, but long-running workers fall back to polling between claim attempts.

### Minimal quickstart

- [examples/quickstart.php](examples/quickstart.php): one file, one task, one step, explicit schema bootstrap, one worker pass.

### Multi-step workflow with real services

- [examples/minimal_runner.php](examples/minimal_runner.php): worker for the examples.
- [examples/chuck_norris_newsletter/produce_chuck_norris_newsletter.php](examples/chuck_norris_newsletter/produce_chuck_norris_newsletter.php): enqueues a newsletter task.
- [examples/chuck_norris_newsletter/ChuckNorrisNewsletterTask.php](examples/chuck_norris_newsletter/ChuckNorrisNewsletterTask.php): task orchestration.
- [examples/chuck_norris_newsletter/GetChuckNorrisJokeStep.php](examples/chuck_norris_newsletter/GetChuckNorrisJokeStep.php): remote fetch step.
- [examples/Support/SendMailStep.php](examples/Support/SendMailStep.php): mail delivery step.

This example shows:

- multi-step payload handoff
- constructor injection
- cleanup retention with `#[CleanupAfter]`
- a separate enqueue process and runner process

### Framework-style producer and worker split

- [examples/minimal_runner.php](examples/minimal_runner.php): worker for the examples.
- [examples/framework_integration/framework_enqueue.php](examples/framework_integration/framework_enqueue.php): producer-side enqueue command.
- [examples/framework_integration/FrameworkDemoContainer.php](examples/framework_integration/FrameworkDemoContainer.php): a minimal PSR-11 container plus app services.

This example shows:

- how to pass your application container into `RunnerConfiguration`
- constructor injection for both tasks and steps
- step-level retries and max runtime
- using task payload to pass state between steps

## Operational Notes

- Supported queue backends are PostgreSQL, MySQL, MariaDB, and SQLite via PDO.
- PostgreSQL is the only backend that supports `LISTEN` / `NOTIFY` wakeups for `runLoop()`.
- MySQL, MariaDB, and SQLite use polling only for worker wakeups.
- Task and step classes are re-instantiated from the class names stored in the queue row, so workers must have the same code and autoload configuration as producers.
- Tasks / Steps are restartable (e.g. retry after failure), but idempotency is still your responsibility. If a step talks to an external system, design it so retries or restarts do not create incorrect side effects.
- `runLoop()` is a worker process, not a scheduler. You still decide how your application starts and supervises workers.
- The queue cleanup process deletes terminal rows only after their `cleanup_at` deadline.

## When To Use This Library

This library is a good fit when you want:

- background workflows inside an existing PHP application
- multi-step jobs whose state should live in a relational database already available to your application
- explicit code-level workflow definitions instead of a generic queue payload protocol
- direct integration with your framework container and logger

It is a weaker fit when you need:

- a hosted queue service
- a high-level scheduler or cron replacement
- cross-language workers
- a workflow DSL or visual orchestration layer

## AI Usage

Note that this library was built with the help of an LLM agent: I co-worked with the agent and personally reviewed the code and worked together with the AI. It is not vibe-coded, but carefully programmed with AI support. I fully understand the code and are responsible for it.
