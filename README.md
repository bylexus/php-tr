# Durable Task for PHP

⚠️ Work in progress! Use with caution for now! ⚠️

Durable Task is a PostgreSQL-backed workflow library for PHP >= 8.3. It is meant to queue and run jobs that are to be processed in the background (e.g. queue an email to be sent).

You model work as a `Task` that defines a workflow consisting of `Step`s. Enqueued Tasks then get worked on step-by-step by a Runner. The library stores the Tasks and Steps state in the database so work can survive worker restarts and multiple runner processes can safely compete for queued work.

The public surface is intentionally small and framework agnostic:

- `Task` defines the workflow, consisting of Steps and owns the payload needed to process the steps.
- `Step` executes one unit of work and returns a `StepResult`.
- `Runner` claims queued Tasks/Steps, executes them, and persists the next durable state.

`Task` and `Step` classes are kept separately, with the goal that single-purpose `Step` classes can be mixed and matched by several `Task` classes. For example, a generic `SendMail` step can be used by many tasks to send information emails.

This README is written for experienced PHP developers who want to integrate the library into an existing application or framework.

## Requirements

- PHP 8.3+
- `ext-pdo`
- PostgreSQL via PDO
- Autoloadable task and step classes in every process that enqueues or runs work

## Installation

```bash
composer require bylexus/durable-task
```

## Quickstart-Demo

The fastest way to see the execution model is the self-contained example in [examples/quickstart.php](examples/quickstart.php). It:

1. Opens a PostgreSQL connection.
2. Bootstraps the queue schema explicitly.
3. Enqueues a one-step task.
4. Processes the queue with `Runner::runSingle()`.

Example command:

```bash
export DURABLE_TASK_DSN='pgsql:host=127.0.0.1;port=5432;dbname=durable_task_test'
export DURABLE_TASK_DB_USER='postgres'
export DURABLE_TASK_DB_PASS='postgres'

php examples/quickstart.php 'Ada Lovelace'
```

## Quickstart - Explained

The quickstart demo just implements a single Task with a single Step to work on. This chapter explains how to get startet in detail.

### The `PrintGreetingStep` step class

First you define one (or multiple) single-purpose Step classes. Here, we create a simple Step that just prints a message:

```php
final class PrintGreetingStep extends Step {
    // Implement the execute function to execute the work:
    public function execute(Task $task): StepResult {
        // Steps read input from the durable task payload.
        // It is advisable to use a namespaced payload, as all steps of a task share
        // the same Payload object. Here, we use the class name as namespace:
        $name = (string) ($task->getPayload(static::class)->name ?? 'world');

        // A step can also write payload data for later inspection or later workflow steps.
        $task->getPayload(static::class)->printedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DATE_ATOM);

        // Do the work!
        fwrite(STDOUT, sprintf("Hello %s from a durable step.\n", $name));

        // and return a result:
        return StepResult::succeeded(message: 'Greeting printed.');
    }
}
```

Now, define the `Task` class to define your workflow: Define the needed payload data used by your steps,
and create a workflow in the `nextStep` function:

```php
#[CleanupAfter(new DateInterval('PT10M'))]
final class GreetingTask extends Task {
    // withName is just a helper method to set up the correct payload:
    public function withName(string $name): self {
        // The root payload is just a stdClass, so examples can keep setup lightweight.
        $this->getPayload()->globalValue = 'some global value';

        // You need to know the exact payload path for providing data for later steps:
        // Here, we use the namespaced 'name' value that is read in the PrintGreeting Step:
        $this->getPayload(PrintGreetingStep::class)->name = $name;

        return $this;
    }

    // nextStep allows the Task to form a workflow:
    // it receives the actual (done) step and can now return the next (configured) step.
    // Returning null means the flow is done:
    public function nextStep(?Step $actStep = null): ?Step {
        // Returning null ends the workflow. Returning a step queues the next durable unit of work.
        return $actStep === null ? new PrintGreetingStep() : null;
    }
}
```

Now you're ready to dispatch the task:

```php
// The task owns the payload. Here we seed it before enqueueing the first step.
$task = (new GreetingTask())->withName('Ada Lovelace');
$task->enqueue($pdo);
```

## Concepts

### `Task` is the durable workflow instance

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

### `Step` is one durable unit of work

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

## Defining Tasks And Steps

This is the smallest useful pattern:

```php
<?php

declare(strict_types=1);

use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;

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

## Schema Management

The queue uses one PostgreSQL table plus indexes. You have three supported ways to manage it.

### 1. Explicit bootstrap in your application

Use this when your framework has an installation command, deploy hook, or startup sequence.

```php
use ByLexus\DurableTask\Queue\SchemaManager;

(new SchemaManager($pdo))->bootstrap();
```

This is the most predictable option in production. It creates the queue table if not present, and / or updates it.

### 2. Export the DDL and run it through your own migration system

```bash
php bin/dump-schema.php
php bin/dump-schema.php custom_queue_table
```

This prints the exact `CREATE TABLE` and `CREATE INDEX` statements for the configured queue table.

### 3. Let the runner bootstrap once at startup

```php
use ByLexus\DurableTask\RunnerConfiguration;
use ByLexus\DurableTask\Runner;

$runnerConfiguration = new RunnerConfiguration(
    bootstrapSchemaOnStart: true,
);
$runner = new Runner(connection: $pdo, runnerConfiguration: $runnerConfiguration);
```

This is useful for local development or controlled deployments. It is optional and disabled by default.

### Custom queue table names

Use `QueueConfiguration` when you want more than one queue table or need a non-default name.

```php
use ByLexus\DurableTask\Queue\QueueConfiguration;

$queueConfiguration = new QueueConfiguration('app_background_jobs');

$task->enqueue($pdo, $queueConfiguration);

$runner = new Runner(
    connection: $pdo,
    queueConfiguration: $queueConfiguration,
    runnerConfiguration: $runnerConfiguration,
);
```

The same `QueueConfiguration` must be used consistently by producers, runners, and schema bootstrap.

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

use ByLexus\DurableTask\Runner;
use ByLexus\DurableTask\RunnerConfiguration;
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
| `#[CleanupAfter(...)]` | task | `P7D` | How long terminal rows stay in the queue table before cleanup removes them. |
| `#[Retries(...)]` | task, step | `3` | Maximum retry count for a failed step. Step-level value overrides the task-level value. |
| `#[RetryMode(...)]` | task, step | `fail` | Step-level value overrides the task-level value. In the current implementation, `restart` requeues the same failed step while the other modes end in a terminal failure. |
| `#[MaxRuntime(...)]` | task, step | `PT1H` | Maximum allowed runtime for one step attempt. The runner fails the step if the claim has exceeded the configured deadline before or after execution. |

Example:

```php
<?php

declare(strict_types=1);

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Attribute\MaxRuntime;
use ByLexus\DurableTask\Attribute\Retries;
use ByLexus\DurableTask\Attribute\RetryMode;
use ByLexus\DurableTask\Enum\RetryMode as RetryModeEnum;

#[CleanupAfter(new DateInterval('PT6H'))]
final class ExportTask extends Task {
    // task defaults apply to all steps unless a step overrides them
}

#[Retries(5)]
#[RetryMode(RetryModeEnum::RESTART)]
#[MaxRuntime(new DateInterval('PT30S'))]
final class CallRemoteApiStep extends Step {
    // this step retries independently from other steps
}
```

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
- The queue is durable, but idempotency is still your responsibility. If a step talks to an external system, design it so retries or restarts do not create incorrect side effects.
- `runLoop()` is a worker process, not a scheduler. You still decide how your application starts and supervises workers.
- The queue cleanup process deletes terminal rows only after their `cleanup_at` deadline.

## When To Use This Library

This library is a good fit when you want:

- durable background workflows inside an existing PHP application
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
