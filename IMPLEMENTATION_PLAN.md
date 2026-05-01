# Durable Tasks Implementation Plan

## Purpose

This document translates the concept in `PLAN.md` into an implementation-ready design that can be reviewed before any code is written. The goal is to make the first implementation pass predictable, incremental, and testable.

## Scope For V1

V1 will implement a PostgreSQL-backed durable task library for PHP with these characteristics:

- Framework agnostic.
- Depends on `PDO` for database access.
- Uses PHP 8 attributes for task and step metadata.
- Persists durable workflow state only in the task payload.
- Executes at most one step at a time per task.
- Supports multiple runner processes through PostgreSQL row locking.
- Auto-creates the queue table at runtime.

## Non-Goals For V1

The first version should not attempt to solve these concerns:

- Storage backends other than PostgreSQL.
- Framework-specific dependency injection integration.
- Delayed scheduling or cron-like dispatch.
- In-process concurrency or threading.
- Distributed tracing, metrics backends, or dashboards.
- Arbitrary step graphs beyond task-controlled linear or conditional progression.

## Confirmed Design Decisions

- Metadata uses PHP 8 attributes, not docblock annotations.
- Retry mode defaults to rerunning the same failed step.
- Durable workflow state lives only in the task payload JSON.
- Queue claiming uses PostgreSQL row locking with `FOR UPDATE SKIP LOCKED`.
- The library bootstraps the queue table automatically if it does not exist.
- Baseline statuses are `queued`, `running`, `succeeded`, `failed`, and `cancelled`.
- `StepResult` is the standard result contract for step execution.
- Runner timing uses documented sensible defaults rather than requiring configuration for all values.
- Payload updates: prefer full payload replacement in V1, because patch semantics add complexity and edge cases. Yes, true for V1.
- Metadata persistence: Persisting class names are sufficient, no other metadata needed.
- No task or step history tables are needed in V1.
- Succeeded and failed task rows are retained only temporarily and then deleted according to task-level cleanup metadata.

## Proposed Package Layout

The first implementation should use a small, explicit package structure.

PHP Main Namespace: `ByLexus\DurableTask`
This should be implemented as a PHP Composer project (as library installable by composer).

```text
composer.json
src/           <--- Namespace root
  Attribute/
    CleanupAfter.php
    MaxRuntime.php
    Retries.php
    RetryMode.php
  Contract/
    QueueInterface.php
  Enum/
    RetryMode.php
    TaskStatus.php
    StepStatus.php
    RunnerMode.php
  Exception/
    DurableTaskException.php
    ConfigurationException.php
    QueueException.php
    SerializationException.php
  Metadata/
    TaskMetadata.php
    StepMetadata.php
    MetadataResolver.php
  Queue/
    QueueRecord.php
    PostgresQueue.php
    SchemaManager.php
    NotificationChannel.php
  Result/
    StepResult.php
    ErrorInfo.php
  Runtime/
    Clock.php
    SystemClock.php
    SignalHandler.php
  Task.php
  Step.php
  Runner.php
tests/
examples/
```

The names above are proposed, not mandatory. The important point is to keep concerns separate:

- Attributes define metadata.
- Metadata resolution converts reflection into cached runtime configuration.
- Queue code is the only code that knows PostgreSQL details.
- Runtime code handles instantiation, shutdown, and time.
- Task and Step remain the main user-facing abstractions.

## Public API Design

### Task Base Class

Responsibilities:

- Hold task identity and current persisted state.
- Accept the initial payload.
- Define workflow progression through `nextStep()`.
- Consume step outcomes through `updateStep()`.
- Request enqueueing through the queue layer.

Proposed responsibilities for the base API:

- Expose read-only queue-derived properties such as id, status, timestamps, and retry counters.
- Expose `getPayload()` and `setPayload()` for durable workflow state.
- Expose `cancel()` to request cancellation.
- Expose `actualStep()` or equivalent to inspect the currently persisted step.
- Expose `enqueue(PDO $connection)` as the entry point for new task instances.

Important rule:

- `Task` owns workflow orchestration only. It must not perform queue SQL directly.
- Concrete task classes must be instantiatable through a default constructor so the library can rebuild them from persisted class names.

### Step Base Class

Responsibilities:

- Represent one durable unit of work.
- Execute business logic.
- Return a `StepResult`.

Important rules:

- `Step` must not mutate queue state directly.
- `Step` may read the current payload, but all durable updates must return through `StepResult`.
- `Step` should be reconstructable from persisted task payload and queue metadata.
- Concrete step classes must be instantiatable through a default constructor so the library can rebuild them directly.

### StepResult

`StepResult` is the contract between a step and the runner. It should carry:

- Step outcome status.
- Updated task payload.
- Optional progress or informational metadata.
- Optional structured error data.
- Optional message for logs or diagnostics.

Recommended V1 shape:

- `status`: `succeeded`, `failed`, `cancelled`, or `running` only if progress updates are explicitly supported.
- `payload`: full replacement payload.
- `errorInfo`: nullable object with machine-readable and human-readable fields.
- `meta`: optional associative array for diagnostics.

Recommendation:

- Keep V1 to full payload replacement. Merge behavior can be introduced later if needed.

### Attributes

The implementation should support these attributes on tasks and steps:

- `#[CleanupAfter(...)]` on tasks
- `#[RetryMode(...)]`
- `#[Retries(...)]`
- `#[MaxRuntime(...)]`

Precedence rules:

- `CleanupAfter` is task-level only.
- Step-level attributes override task-level defaults where the same property exists.
- Task-level attributes define workflow-wide defaults when a step does not override them.
- Library defaults apply only when neither task nor step provides a value.

### Metadata Resolution

`MetadataResolver` should:

- Reflect task and step classes.
- Validate attribute values early.
- Cache resolved metadata by fully qualified class name.
- Return normalized runtime values rather than raw reflection output.

Validation examples:

- Reject invalid cleanup intervals.
- Reject negative retry counts.
- Reject invalid runtime intervals.
- Reject unsupported retry mode values.

## Data Model And Queue Schema

V1 should use one primary queue table representing the current execution state of each task.

### Table Strategy

- One row per task execution.
- The row contains both task-level state and the currently active step state.
- The row is updated in place throughout the lifecycle.
- No separate task or step history is stored in V1.
- Terminal task rows are deleted after their configured cleanup deadline has passed.


### Proposed Table Name

- Default: `durable_task_queue`
- Configurable through queue configuration.

### Proposed Columns

Core identity:

- `task_id` big integer primary key.
- `task_class` text not null.
- `step_class` text nullable.

Task state:

- `task_status` text not null.
- `task_attempt` integer not null default `0`.
- `task_created_at` timestamptz not null.
- `task_started_at` timestamptz nullable.
- `task_finished_at` timestamptz nullable.
- `cleanup_at` timestamptz nullable.

Step state:

- `step_status` text nullable.
- `step_attempt` integer not null default `0`.
- `step_started_at` timestamptz nullable.
- `step_finished_at` timestamptz nullable.

Durable data:

- `payload_json` jsonb not null.
- `result_json` jsonb nullable.
- `error_json` jsonb nullable.

Execution coordination:

- `available_at` timestamptz not null.
- `claimed_at` timestamptz nullable.
- `claimed_by` text nullable.
- `lock_version` integer not null default `0`.

Diagnostics:

- `last_error_code` text nullable.
- `last_error_message` text nullable.
- `cancel_requested` boolean not null default `false`.
- `cancel_reason` text nullable.
- `updated_at` timestamptz not null.


### Indexes

At minimum:

- Primary key on `task_id`.
- Index on `(task_status, available_at)` for runner pickup.
- Index on `cleanup_at` for terminal row cleanup.
- Index on `claimed_at` for operational visibility.
- Index on `updated_at` for stale execution checks.

### Schema Bootstrap

`SchemaManager` should:

- Create the table if it does not exist.
- Create indexes if they do not exist.
- Run idempotently on every startup.

Important constraint:

- Bootstrap must not silently mutate incompatible existing schemas. If the schema shape is wrong, fail early with a clear configuration error.

### Cleanup Strategy

- Cleanup retention is configured per task class through `#[CleanupAfter(...)]`. Defaults to 7 days if not set.
- When a task reaches `succeeded`, `failed` or `cancelled`, the queue row stores `cleanup_at` as `task_finished_at + cleanup interval`.
- Rows whose `cleanup_at` is in the past are eligible for deletion.
- Cleanup is performed by the library itself; the runner should execute cleanup opportunistically so no separate cron job is required in V1.

## State Model

The implementation should separate task and step status, even if they usually move together.

### Task Statuses

- `queued`: task has been enqueued and is waiting to be claimed.
- `running`: a runner is executing the current step.
- `succeeded`: task finished with no next step.
- `failed`: task cannot continue.
- `cancelled`: task was explicitly cancelled or aborted as cancelled.

### Step Statuses

- `queued`: step is selected and waiting for execution.
- `running`: step execution is in progress.
- `succeeded`: step completed successfully.
- `failed`: step ended with an error.
- `cancelled`: step was aborted.

### Recommended Simplification For V1

- New work enters as `queued`.
- Claimed work transitions to `running`.
- Completed work becomes `succeeded`, `failed`, or `cancelled`.

### State Transition Rules

Enqueue flow:

1. New task instance is validated.
2. Task metadata is resolved.
3. `nextStep(null)` is called to determine the first step.
4. Queue row is created with task status `queued` and step status `queued`.
5. A PostgreSQL notification is emitted.

Claim flow:

1. Runner selects a claimable row using `FOR UPDATE SKIP LOCKED`.
2. The row is marked claimed by runner id.
3. Task and step status transition to `running`.
4. Start timestamps are written if they are not already set.

Successful step flow:

1. Step returns `StepResult::succeeded(...)`.
2. Payload is replaced.
3. Task receives `updateStep()`.
4. Task decides the next step through `nextStep()`.
5. If next step exists, row returns to `queued` with the new `step_class`.
6. If no next step exists, task becomes `succeeded`.
7. On terminal success, the queue row stores `task_finished_at` and, if configured, `cleanup_at`.

Failed step flow:

1. Step throws or returns a failed result.
2. Runner converts thrown exceptions into structured error data.
3. Retry policy is evaluated.
4. If retryable, increment `step_attempt`, clear claim fields, and return row to `queued`.
5. If not retryable, set task and step status to `failed`. If the step's retry mode is set to `skip`, proceed to the next step instead, setting the step class and set the status back to `queued`. Add a skipped info in the result.
6. On terminal failure, the queue row stores `task_finished_at` and, if configured, `cleanup_at`.

Cancelled flow:

1. Cancellation is requested either by API or shutdown handling.
2. Queue row records cancellation request and reason.
3. Runner stops scheduling further steps for the task.
4. Active or next status becomes `cancelled`.

## Queue Layer Design

`PostgresQueue` should be the only component that reads or writes the queue table.

Responsibilities:

- Enqueue new tasks.
- Claim one runnable task safely.
- Persist task and step transitions.
- Persist payload and result updates.
- Persist cancellation requests.
- Delete expired terminal task rows.
- Notify runners after enqueue or requeue operations.
- Expose read APIs for operational inspection.

### Claim Algorithm

Recommended query shape:

1. Begin transaction.
2. Select one row where task status is runnable and `available_at <= now()`.
3. Use `FOR UPDATE SKIP LOCKED`.
4. Update claim metadata inside the same transaction.
5. Commit.

Selection order:

- Order by `available_at`, then `task_created_at`.

This yields deterministic FIFO-like behavior while staying concurrency-safe.

### Notifications

Use PostgreSQL `NOTIFY` after:

- New task enqueue.
- Retry requeue.
- Manual cancellation if it should wake waiting runners.

Runner behavior:

- Poll first.
- If no work is available, wait on `LISTEN` for a bounded timeout.
- Loop again after timeout or notification.

### Cleanup Execution

- `PostgresQueue` should expose a method that deletes rows where `cleanup_at <= now()`.
- Runner loop mode should call this cleanup method on a low-frequency cadence so retention cleanup happens automatically.
- Single mode may perform one cleanup pass before exit.
- Cleanup must only delete terminal task rows and must never touch queued or running work.

## Runner Design

The runner is responsible for orchestration, not business logic.

### Modes

- Single mode: process available work, then exit.
- Loop mode: keep polling and waiting until terminated.

### Recommended Defaults

- Poll interval: 1 second when explicit polling is needed.
- Notification wait timeout: 30 seconds.
- Graceful shutdown timeout: 30 seconds.

All values should be configurable, but these defaults are sufficient for V1.

### Runner Responsibilities

- Bootstrap schema.
- Create a runner identifier.
- Register signal handlers.
- Claim work.
- Rebuild task and step instances.
- Enforce runtime limits.
- Execute steps.
- Persist outcomes.
- Requeue or finalize tasks.

### Execution Flow

1. Runner starts and bootstraps schema.
2. Runner enters loop or single pass.
3. Runner claims one task row.
4. Runner reconstructs the task instance.
5. Runner reconstructs the active step instance.
6. Runner validates cancellation and runtime before execution.
7. Runner executes the step.
8. Runner converts the outcome into queue updates.
9. Runner commits state transitions.
10. Runner continues until no more work or shutdown.

### Shutdown Handling

On `SIGTERM` or equivalent:

1. Stop claiming new work.
2. Allow the current step to finish until graceful timeout expires.
3. If the timeout expires and the process cannot safely continue, persist failure or cancellation according to the configured policy.
4. Exit cleanly.

Because PHP cannot reliably kill arbitrary user code safely, the implementation should prefer cooperative shutdown between steps rather than hard interruption during a step.

## Instantiation And Dependency Boundaries

This is the weakest point in the original concept and needs an explicit V1 rule.

Problem:

- Tasks and steps in the examples accept service dependencies in their constructors.
- A framework-agnostic library cannot reconstruct arbitrary objects from persisted class names alone.

Recommended V1 solution:

- Persist class names and payload only.
- The library instantiates task and step classes directly from their persisted class names.
- Therefore, concrete task and step classes must provide a default constructor with no required parameters.

This keeps the library simple and avoids introducing additional factory abstractions.

Required consequence:

- `Task::enqueue()` must persist enough information to reconstruct the task later, which means task class name plus payload, but not concrete service objects.
- Constructor injection for task and step implementations is out of scope for V1.

Implementation consequence:

- Any runtime dependencies needed by concrete tasks or steps must be acquired without required constructor arguments.
- The first version should assume tasks and steps are plain instantiable classes with internal logic based on payload and static configuration.

## Retry, Failure, And Cancellation Semantics

### Retry Mode

Recommended V1 modes:

- `fail`: no retry; task fails immediately.
- `restart`: rerun the same step up to the configured retry count.
- `skip`: mark the step skipped internally and continue to the next step. Create an entry in the result metadata that this step was skipped.

### Exception Handling

Step code may still throw. The runner should:

1. Catch all `Throwable` values.
2. Convert the exception into `ErrorInfo`.
3. Persist a failed step outcome.
4. Evaluate retry rules.

This keeps error handling centralized and prevents steps from having to own persistence logic.

### Max Runtime

Enforce runtime at these checkpoints:

- Before step execution begins.
- Immediately after step execution returns.
- Before requeueing the next step.

V1 should not attempt in-step interruption. Runtime enforcement is boundary-based, not preemptive.

### Cancellation

Cancellation should be durable and explicit:

- Store `cancel_requested` and `cancel_reason`.
- Check cancellation before executing a claimed step.
- Do not schedule another step after cancellation is observed.

## Logging And Diagnostics

V1 does not need a full logging abstraction, but it should define where diagnostics live.

Persisted diagnostics:

- Last error code.
- Last error message.
- Structured error JSON.
- Claim information.
- Attempt counters.

Runtime diagnostics:

- Runner id.
- Mode.
- Polling and wakeup behavior.

If a logger abstraction is added later, it should not change the queue schema.

## Testing Strategy

Testing needs to be part of the implementation order, not an afterthought.

Use the newest `phpunit` library for unit tests. Install it as composer dev dependency.

### Unit Tests

Add focused tests for:

- Attribute validation.
- Cleanup retention resolution.
- Metadata precedence.
- StepResult normalization.
- Retry policy decisions.
- Status transition validation.
- Error serialization.

### Integration Tests

Add PostgreSQL-backed tests for:

- Schema bootstrap on empty database.
- Enqueue and claim flow.
- Concurrent claiming with multiple runner processes.
- Retry and requeue behavior.
- Notification wakeups.
- Cancellation persistence.
- Cleanup of succeeded and failed task rows after `cleanup_at` has passed.
- Restart safety after runner exit or crash.

### Example Workflow

Add one end-to-end example with at least three steps:

1. Fetch or assemble data.
2. Transform or aggregate data.
3. Produce an external side effect such as sending an email through a stubbed service.

The example should prove that payload evolves safely across multiple steps.

## Step-By-Step Implementation Order

The implementation should proceed in narrow, reviewable slices.

### Phase 1: Project Skeleton

1. Add Composer package metadata and autoloading.
2. Create the base source layout.
3. Add enum and exception scaffolding.
4. Add test infrastructure.

Exit criteria:

- Package installs and autoloads.
- Test runner executes.

### Phase 2: Core Domain Types

1. Implement status enums.
2. Implement retry enum.
3. Implement `StepResult` and `ErrorInfo`.
4. Add tests for result and enum behavior.

Exit criteria:

- Core value objects are stable and tested.

### Phase 3: Attributes And Metadata

1. Implement attributes.
2. Implement metadata DTOs.
3. Implement metadata resolver and cache.
4. Add validation tests.

Exit criteria:

- Invalid metadata fails early.
- Precedence rules are covered by tests.
- Task cleanup retention can be resolved from task metadata.

### Phase 4: Queue Schema And Records

1. Implement queue record representation.
2. Implement schema manager.
3. Implement queue configuration.
4. Add schema bootstrap integration tests.

Exit criteria:

- Empty PostgreSQL database can be bootstrapped idempotently.
- Queue schema includes the cleanup deadline column used for terminal row retention.

### Phase 5: Queue Operations

1. Implement enqueue.
2. Implement claim.
3. Implement transition persistence methods.
4. Implement notifications.
5. Implement expired task cleanup.
6. Add concurrency and cleanup integration tests.

Exit criteria:

- Multiple runner processes cannot claim the same task at the same time.
- Expired succeeded and failed task rows can be deleted safely without affecting active work.

### Phase 6: Task And Step Contracts

1. Implement base `Task` and `Step` classes.
2. Implement direct task and step instantiation from persisted class names.
3. Define how queue records hydrate domain instances.
4. Add unit tests with example task and step classes.

Exit criteria:

- A queued record can be reconstructed into executable objects.

### Phase 7: Runner

1. Implement runner configuration.
2. Implement signal handling.
3. Implement single mode.
4. Implement loop mode.
5. Implement runtime, cancellation, and cleanup checks.
6. Add integration tests for normal execution, retries, shutdown behavior, and retention cleanup.

Exit criteria:

- Runner can process tasks end-to-end and stop predictably.

### Phase 8: Examples And Documentation

1. Add one example workflow.
2. Document installation and runner usage.
3. Document attribute behavior.
4. Document operational constraints and non-goals.

Exit criteria:

- A new user can understand the mental model and run the example locally.

## Acceptance Criteria For V1

V1 is ready when all of the following are true:

- A task can be enqueued with a payload.
- The queue schema bootstraps automatically on an empty PostgreSQL database.
- One or more runner processes can safely claim and execute tasks.
- Task state remains durable across runner restarts.
- A failed step is retried according to metadata.
- Exhausted retries result in terminal task failure.
- Cancellation is persisted and stops further workflow progression.
- Succeeded and failed task rows are deleted after their configured cleanup timeout.
- End-to-end tests cover the main lifecycle paths.
