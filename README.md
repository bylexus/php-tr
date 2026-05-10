# PHP Task Runner - a Queue / Task Runner for background tasks

⚠️ Work in progress! Use with caution for now! ⚠️

PHP Task Runner is a PostgreSQL-backed workflow library for PHP >= 8.3. It is meant to queue and run jobs that are to be processed in the background of a frontend application (e.g. queue an email to be sent in the background).

You model work as a `Task` that defines a workflow consisting of `Step`s. Enqueued Tasks then get worked on step-by-step by a Runner. The library stores the Tasks and Steps state in the database so work can survive worker restarts and multiple runner processes can safely compete for queued work.

The public surface is intentionally small and framework agnostic:

- `Task` defines the workflow, consisting of Steps and owns the payload needed to process the steps.
- `Step` executes one unit of work and returns a `StepResult`.
- `Runner` claims queued Tasks/Steps, executes them, and persists the next state.

`Task` and `Step` classes are kept separately, with the goal that single-purpose `Step` classes can be mixed and matched by several `Task` classes. For example, a generic `SendMail` step can be used by many tasks to send information emails.

This README is written for experienced PHP developers who want to integrate the library into an existing application or framework.

## Requirements

- PHP 8.3+
- `ext-pdo`
- PostgreSQL via PDO
- Autoloadable task and step classes in every process that enqueues or runs work

## Installation

```bash
composer require bylexus/php-tr
```

## Quickstart

See a full example in [examples/quickstart.php](examples/quickstart.php).

This quickstart just implements a single Task with a single Step to work on. This chapter explains how to get startet in detail.


### Install

Use [composer](https://getcomposer.org/):

```sh
$ composer require bylexus/php-tr
```

Create the DB objects (1 table, some indexes), either by invoking the SchemaManager:

```php

use ByLexus\TaskRunner\Queue\SchemaManager;

// Use the Schema Manager with your existing PostgreSQL PDO connection:
(new SchemaManager($pdo))->bootstrap();
```

or by dumping the needed SQLs:

```sh
# Just dump the needed SQLs:
$ php vendor/bylexus/php-tr/bin/dump-schema.php
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
$task->enqueue($pdo);

// Optional: lower numbers are picked first by runners.
$task->enqueue($pdo, priority: Task::PRIO_HIGH);
```

If you want to keep the queue connection and queue configuration together, create a `QueueContext` once and reuse it:

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\QueueContext;

$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
$queue = new QueueContext($pdo, $queueConfiguration);
$queue->enqueue((new GreetingTask())->withName('Ada Lovelace'));
$queue->enqueue((new GreetingTask())->withName('Grace Hopper'), priority: Task::PRIO_HIGH);
```

Tasks default to priority `3` (`Task::PRIO_NORMAL`). You can choose values from `1` to `5` using the built-in constants:

- `Task::PRIO_VERY_HIGH` = `1`
- `Task::PRIO_HIGH` = `2`
- `Task::PRIO_NORMAL` = `3`
- `Task::PRIO_LOW` = `4`
- `Task::PRIO_VERY_LOW` = `5`

When multiple queued tasks are available, runners claim the highest-priority work first, then fall back to `available_at` and creation time.

### Start a runner to work on the tasks

A Runner can now be instantiated in a separate script, e.g. a script that runs server-side as a daemon:

```php
use ByLexus\TaskRunner\Runner;
use ByLexus\TaskRunner\RunnerConfiguration;

// A runner claims one queued row, hydrates the task and step, executes them, and persists the result.
$runner = new Runner(
    connection: $pdo, // a Postgresql PDO connection object
    runnerConfiguration: new RunnerConfiguration(),
);

// start the processing loop:
$processed = $runner->runLoop();
```

With `QueueContext`, the same setup becomes:

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\QueueContext;
use ByLexus\TaskRunner\RunnerConfiguration;

$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
$queue = new QueueContext($pdo, $queueConfiguration);
$runner = $queue->createRunner(new RunnerConfiguration());
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

`runLoop()` listens for PostgreSQL notifications when the PDO driver supports them and otherwise falls back to sleeping for the configured wait timeout.

You can safely start multiple runners, as each task can only be claimed by one runner at a time: This allows for parallel execution of multiple tasks. Useful if your runner gets blocked with long-running tasks.

### Task priority

Each queued task row stores a numeric priority. Priority `1` is the highest priority and `5` is the lowest. If you do not pass a priority when enqueueing, the library stores `3`.

```php
use ByLexus\TaskRunner\Task;

$task = (new WelcomeTask())->withEmail('ada@example.com');

$task->enqueue($pdo, priority: Task::PRIO_VERY_HIGH);
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

The namespaced payload pattern is usually the cleanest way to avoid collisions between steps in a larger workflow.

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

The queue uses one PostgreSQL table plus indexes. You have three supported ways to manage it.

### 1. Explicit bootstrap in your application

Use this when your framework has an installation command, deploy hook, or startup sequence.

```php
use ByLexus\TaskRunner\Queue\SchemaManager;

(new SchemaManager($pdo))->bootstrap();
```

If you already use a `QueueContext`, the same wrapper can also manage the schema for that queue:

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\QueueContext;

$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
$queue = new QueueContext($pdo, $queueConfiguration);
$queue->bootstrapSchema();
$queue->validateSchema();
$ddl = $queue->exportDdl();
```

This is the most predictable option in production. It creates the queue table if not present, and / or updates it.

The queue schema includes a `priority` column with default value `3`, so existing producers can keep enqueueing tasks without passing a priority explicitly.

### 2. Export the DDL and run it through your own migration system

```bash
php bin/dump-schema.php
php bin/dump-schema.php custom_queue_table
php bin/dump-schema.php custom_queue_table background_jobs
```

This prints the exact `CREATE TABLE` and `CREATE INDEX` statements for the configured queue table.

### 3. Let the runner bootstrap once at startup

```php
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\Runner;

$runnerConfiguration = new RunnerConfiguration(
    bootstrapSchemaOnStart: true,
);
$runner = new Runner(connection: $pdo, runnerConfiguration: $runnerConfiguration);
```

This is useful for local development or controlled deployments. It is optional and disabled by default.

### Custom queue tables and schemas

Use `QueueConfiguration` when you want more than one queue table, need a non-default name, or want to place queue objects in a dedicated PostgreSQL schema.

```php
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\QueueContext;

$queueConfiguration = new QueueConfiguration('app_background_jobs');

$task->enqueue($pdo, $queueConfiguration);

$runner = new Runner(
    connection: $pdo,
    queueConfiguration: $queueConfiguration,
    runnerConfiguration: $runnerConfiguration,
);

$queue = new QueueContext($pdo, $queueConfiguration);
$queue->enqueue($task);
$queue->createRunner($runnerConfiguration)->runLoop();
```

The same `QueueConfiguration` must be used consistently by producers, runners, and schema bootstrap.

`QueueContext` is the simplest way to enforce that consistency in application code because it also exposes `createSchemaManager()`, `bootstrapSchema()`, `validateSchema()`, `tableExists()`, `blobTableExists()`, and `exportDdl()` on the same shared queue context.

To place the queue in a specific schema, pass the schema name as the second argument:

```php
$queueConfiguration = new QueueConfiguration('app_background_jobs', 'background_jobs');
```

Schema bootstrap will create the schema automatically when needed.

## Running Workers

### Single pass worker

```php
$runner = new Runner(connection: $pdo);
$processed = $runner->runSingle();
```

`runSingle()` drains queued work until no claimable rows remain. It returns `0` when the queue is empty and the number of steps when it processed at least one step during that pass.

### Long-running worker

Start a long-running runner using the `runLoop()` function. This is best used in conjunction with a process manager like `systemd` or `supervisord`.

```php
$runner = new Runner(
    connection: $pdo,
    runnerConfiguration: new RunnerConfiguration(
        runnerId: 'billing-worker-1',
        notificationWaitTimeoutSeconds: 15,
    ),
);

$runner->runLoop();
```

Run multiple worker processes when you want parallel execution. The queue uses PostgreSQL row locking so different runner processes do not claim the same task row at the same time.

## Constructor Service Injection And Framework Integration

The library supports PSR-11 constructor injection for both tasks and steps. This is the integration path you will usually want inside Symfony, Laravel, Laminas, Spiral, a custom container, or your own application kernel.

The framework-oriented example lives in:

- [examples/framework_enqueue.php](examples/framework_enqueue.php)
- [examples/framework_runner.php](examples/framework_runner.php)
- [examples/FrameworkDemoContainer.php](examples/FrameworkDemoContainer.php)
- [examples/ImportUserProfileTask.php](examples/ImportUserProfileTask.php)
- [examples/FetchUserProfileStep.php](examples/FetchUserProfileStep.php)
- [examples/PersistUserProfileStep.php](examples/PersistUserProfileStep.php)

The integration contract is:

1. Your producer and worker processes must both load the same task and step classes.
2. Your worker must pass a PSR-11 container into `RunnerConfiguration::container`.
3. Constructor parameters must be resolvable class or interface types. Builtin parameters must have defaults.
4. `LoggerInterface` is resolved from the container when available; otherwise the runner logger or `NullLogger` is used.
5. If a claimed task or step cannot be instantiated, the runner persists a terminal failure for that row.

Typical worker bootstrap:

```php
<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Runner;
use ByLexus\TaskRunner\RunnerConfiguration;
use Psr\Log\LoggerInterface;

$container = $app->getContainer();

$runner = new Runner(
    connection: $pdo,
    runnerConfiguration: new RunnerConfiguration(
        container: $container,
        logger: $container->get(LoggerInterface::class),
        bootstrapSchemaOnStart: false,
    ),
);

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

The example container in [examples/ExampleServiceContainer.php](examples/ExampleServiceContainer.php) and [examples/FrameworkDemoContainer.php](examples/FrameworkDemoContainer.php) shows the intended shape.

## Examples

### Minimal quickstart

- [examples/quickstart.php](examples/quickstart.php): one file, one task, one step, explicit schema bootstrap, one worker pass.

### Multi-step workflow with real services

- [examples/chuck_norris_newsletter.php](examples/chuck_norris_newsletter.php): enqueues a newsletter task.
- [examples/minimal_runner.php](examples/minimal_runner.php): worker entry point for the example.
- [examples/ChuckNorrisNewsletterTask.php](examples/ChuckNorrisNewsletterTask.php): task orchestration.
- [examples/GetChuckNorrisJokeStep.php](examples/GetChuckNorrisJokeStep.php): remote fetch step.
- [examples/SendMailStep.php](examples/SendMailStep.php): mail delivery step.

This example shows:

- multi-step payload handoff
- constructor injection
- cleanup retention with `#[CleanupAfter]`
- a separate enqueue process and runner process

### Framework-style producer and worker split

- [examples/framework_enqueue.php](examples/framework_enqueue.php): producer-side enqueue command.
- [examples/framework_runner.php](examples/framework_runner.php): worker entry point with container and logger.
- [examples/FrameworkDemoContainer.php](examples/FrameworkDemoContainer.php): a minimal PSR-11 container plus app services.

This example shows:

- how to pass your application container into `RunnerConfiguration`
- constructor injection for both tasks and steps
- step-level retries and max runtime
- using task payload to pass state between steps

## Operational Notes

- PostgreSQL is the queue backend. There is no abstraction for other databases yet.
- Task and step classes are re-instantiated from the class names stored in the queue row, so workers must have the same code and autoload configuration as producers.
- Tasks / Steps are restartable (e.g. retry after failure), but idempotency is still your responsibility. If a step talks to an external system, design it so retries or restarts do not create incorrect side effects.
- `runLoop()` is a worker process, not a scheduler. You still decide how your application starts and supervises workers.
- The queue cleanup process deletes terminal rows only after their `cleanup_at` deadline.

## When To Use This Library

This library is a good fit when you want:

- background workflows inside an existing PHP application
- multi-step jobs whose state should live in PostgreSQL
- explicit code-level workflow definitions instead of a generic queue payload protocol
- direct integration with your framework container and logger

It is a weaker fit when you need:

- a hosted queue service
- a high-level scheduler or cron replacement
- cross-language workers
- a workflow DSL or visual orchestration layer

## AI Usage

Note that this library was built with the help of an LLM agent: I co-worked with the agent and personally reviewed the code and worked together with the AI. It is not vibe-coded, but carefully programmed with AI support. I fully understand the code and are responsible for it.
