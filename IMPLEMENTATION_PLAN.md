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
- Framework-specific dependency injection integration beyond a generic PSR-11 container lookup.
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
- `StepResult` is the standard result contract for step execution. A Step may also end in an Exception, which is catched by the Runner.
- Runner timing uses documented sensible defaults rather than requiring configuration for all values.
- Payload persistence remains full replacement at queue row boundaries, but the in-memory payload API must always expose a lazily materialized `stdClass` root object and support direct access to lazily materialized top-level payload objects.
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
- Expose root and top-level payload accessors for durable workflow state.
- Expose `cancel()` to request cancellation.
- Expose `actualStep()` or equivalent to inspect the currently persisted step.
- Expose `enqueue(PDO $connection)` as the entry point for new task instances.

Important rule:

- `Task` owns workflow orchestration only. It must not perform queue SQL directly.
- Concrete task classes must remain reconstructable from persisted class names. The exact constructor-resolution contract is under review because PSR-11-based service lookup is now a requested feature.

### Step Base Class

Responsibilities:

- Represent one durable unit of work.
- Execute business logic.
- Return a `StepResult`.

Important rules:

- `Step` must not mutate queue state directly.
- `Step` must not own or cache payload state.
- `Step` must receive the owning `Task` instance during execution and use the task as the single source of truth for payload access and mutation.
- `Step` should be reconstructable from persisted queue metadata; payload reconstruction remains task-owned.
- Concrete step classes must remain reconstructable from persisted queue metadata. The exact constructor-resolution contract is under review because PSR-11-based service lookup is now a requested feature.

### Task-Owned Payload Refactor

The current implementation still duplicates payload behavior across `Task` and `Step` by sharing `HasPayloadAccess` and then reconciling both objects after `execute()`. That is the wrong ownership model.

Required target model:

- Only `Task` owns the payload object and payload access API.
- `Step` receives the owning task during execution, for example through `execute(Task $task): StepResult`.
- Step code reads and mutates payload only through the passed task instance.
- After each step finishes, the task's payload is the authoritative state written back to `payload_json`.
- `StepResult` should no longer carry payload data at all.

Concrete code areas that must change:

- `src/Task.php`
  The task remains the only payload owner. Keep the payload API directly on `Task`, remove the separate `HasPayloadAccess` trait, remove step-payload reconciliation logic that assumes the step also owns payload state, and make `updateStep()` persist the already-mutated task payload.
- `src/Step.php`
  Remove `HasPayloadAccess` from `Step`, drop step-level payload storage and payload helper methods, and change the abstract execution contract from `execute(): StepResult` to `execute(Task $task): StepResult`.
- `src/PayloadNormalizer.php`
  Keep normalization separate, but scope it to the task-owned payload path only. After the refactor, no step code should depend on payload helpers directly.
- `src/Runner.php`
  Change the execution call from `$step->execute()` to `$step->execute($task)`. Remove timeout/cancellation/failure branches that read payload from the step object. All persistence branches should use the task payload as the authoritative durable state after execution.
- `src/Result/StepResult.php`
  Remove payload state from `StepResult` entirely so it carries only outcome and diagnostics.
- `src/Queue/PostgresQueue.php` and `src/Queue/QueueRecord.php`
  Storage remains task-level and can continue to use `payload_json`, but step hydration should no longer imply that the step owns a payload copy.
- `tests/TaskTest.php`, `tests/ResultTest.php`, `tests/Integration/PostgresQueueIntegrationTest.php`, and `tests/Integration/RunnerIntegrationTest.php`
  Rewrite assertions that currently inspect payload through `actualStep()` and replace them with task-owned payload assertions plus execute-signature coverage.
- Step fixtures under `tests/Fixture/` and example steps under `examples/`
  Update every concrete step implementation to the new `execute(Task $task)` signature and replace `$this->getPayload()` usage with `$task->getPayload()`.
- Example and helper tasks under `examples/` and `tests/Fixture/`
  Remove any code that sets payload directly on steps for handoff. Next-step transitions should rely on task payload only.

Proposed implementation sequence:

1. Refactor `Step` to accept the executing task and remove payload helpers from the step base class.
2. Update `Runner` so execution, timeout handling, cancellation handling, retries, and next-step transitions all treat the task payload as authoritative.
3. Simplify `Task::updateStep()` so it persists the task payload after execution without any `StepResult` payload reconciliation.
4. Update every concrete step in tests and examples to use the passed task instance for payload access.
5. Rewrite unit and integration tests that currently expect step payload state to exist independently from the task.

Expected behavioral result:

- There is only one in-memory durable payload object per claimed task.
- Steps mutate that object through the task reference they receive.
- Queue persistence always writes the task's current payload object back after step execution.

### Payload Object Contract

The payload API remains object-based, but after the refactor it is task-owned rather than shared between task and step objects.

Required behavior:

- `getPayload()` must always return the root payload as `stdClass`.
- `getPayload('foo')` must return `getPayload()->foo` directly.
- If the root payload or the requested top-level property is currently `null` or missing, first access must materialize a new `stdClass` and store it back into the in-memory payload graph.
- Repeated calls to `getPayload()` and `getPayload('foo')` must return the same object instances for the same task instance.
- A mutation such as `getPayload('foo')->bar = 'somevalue'` must be visible later through both `getPayload('foo')->bar` and `getPayload()->foo->bar`.
- Root payload replacement should continue to be supported, but all setter entry points must normalize the stored in-memory shape back to the object contract.
- A named top-level setter variant must be supported in addition to root replacement so callers can replace one top-level payload object without rebuilding the whole payload manually.
- Existing non-null top-level property values may be scalars, arrays, or objects and must be returned unchanged rather than being replaced with `stdClass`.
- Root replacement accepts `null`, arrays, and objects. `null` must normalize immediately to an empty `stdClass`. Scalar root payloads must be rejected.

Normalization rules:

- Rows with `payload_json = null` must normalize to an empty root object during hydration; the root payload must never remain `null` in memory.
- Legacy array payloads passed into root setters or returned from older tests/examples should be normalized into the root object form so the new accessor contract remains stable.
- Root normalization applies only to the payload root. Top-level property values remain whatever was explicitly stored there, including arrays and scalars.
- First access is intentionally stateful: once the root object or a top-level object is materialized, later persistence may write `{}` or an object subtree even if the row originally stored `null`.

Affected implementation areas for this refinement:

- `src/Task.php`: introduce cached root/top-level payload materialization, named accessors, normalization, and update-step integration.
- `src/Step.php`: remove step-owned payload access and switch execution to task-mediated payload access.
- `src/Result/StepResult.php`: remove payload handling entirely and keep only status plus diagnostics.
- `src/Runner.php`: preserve one task-owned payload object across execution, exception handling, retry requeueing, and next-step transitions.
- `src/Queue/QueueRecord.php` and `src/Queue/PostgresQueue.php`: keep storage compatible with nullable JSON, but ensure hydration and persistence work with the new in-memory object contract and legacy payload shapes.
- `tests/TaskTest.php`, `tests/ResultTest.php`, `tests/Integration/PostgresQueueIntegrationTest.php`, and `tests/Integration/RunnerIntegrationTest.php`: expand coverage from shape equality to task-owned payload identity, lazy materialization, and persistence of nested mutations.
- Payload-related fixtures and examples such as `tests/Fixture/RunnerRetryStepFixture.php`, `tests/Fixture/PayloadOverrideTaskFixture.php`, and the Chuck Norris example files: replace step-local payload access with task-mediated payload access.

### StepResult

`StepResult` is the contract between a step and the runner. It should carry:

- Step outcome status.
- Optional progress or informational metadata.
- Optional structured error data.
- Optional message for logs or diagnostics.

Recommended V1 shape:

- `status`: `succeeded`, `failed`, `cancelled`, or `running` only if progress updates are explicitly supported.
- `errorInfo`: nullable object with machine-readable and human-readable fields.
- `meta`: optional associative array for diagnostics.

Recommendation:

- Keep queue persistence task-owned. Fine-grained mutation is supported through the single task payload object, not through `StepResult` payload transport or partial SQL patch semantics.

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

- `payload_json` jsonb nullable.
- `result_json` jsonb nullable.
- `error_json` jsonb nullable.

Execution coordination:

- `available_at` timestamptz not null.
- `claimed_at` timestamptz nullable.
- `claimed_by` text nullable.

Concurrency control:

- Queue rows are protected through transaction-scoped PostgreSQL row locks.
- Reads and writes for a single queue entry should happen in one locked transaction together.

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

### Schema Bootstrap Execution Strategy

The open question is not whether the schema can be created by `SchemaManager`, but when that bootstrap should actually run.

Options considered:

- The user calls `SchemaManager` explicitly at setup or deployment time.
- Depending classes verify and bootstrap the schema before every database operation.
- The library provides a support script that only dumps the required DDL statements for external execution.

Planned V1 approach:

- Do not run schema verification automatically before every database operation. Lower-level queue or task code must not trigger repetitive schema checks on each call.
- Support explicit bootstrap through `SchemaManager::bootstrap()` as the primary mechanism. The user can/must call it manually during setup, deployment, or application startup.
- Allow the runner to call `SchemaManager::bootstrap()` once when the runner process starts, because that is a single startup check rather than a repeated per-operation cost. But make it configurable (default: not run)
- Also provide a support script or helper that dumps the required DDL statements, so users who prefer manual migrations can apply the schema outside the runtime path.

Recommended usage:

- Production: prefer explicit schema creation through a deployment step or exported DDL.
- Development or simple setups: allow the runner to perform a one-time startup bootstrap.


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

The current implementation hardcodes direct zero-argument instantiation in `Task::fromQueueRecord()` and `Step::fromQueueRecord()`. That is no longer sufficient for the requested constructor-injection feature.

Requested direction:

- `RunnerConfiguration` should optionally receive a PSR-11 compatible service container when the runner is created.
- Task and step subclasses should be allowed to declare class-based constructor parameters such as `__construct(Mailer $mailer)`.
- The runner should inspect constructors via reflection and try to resolve each class-typed dependency from the configured container.
- If dependency resolution fails, the claimed work item should be marked as failed instead of crashing the runner process and leaving the claimed row unresolved.

Current assessment:

- The current runner only converts exceptions thrown during `Step::execute()` into a failed `StepResult`. Instantiation failures happen earlier during task and step reconstruction, so additional failure-persistence rules are required.
- The package currently has no runtime dependency on `psr/container`, so Composer metadata must change as part of the feature.

Confirmed impacts in the codebase:

- `src/RunnerConfiguration.php` needs a new optional container field and accessor.
- `src/Runner.php` needs a dedicated reconstruction path that can resolve constructor dependencies and persist terminal failure when reconstruction fails.
- `src/Task.php` and `src/Step.php` should stop directly calling `new $className()` and instead delegate to a shared instantiation service or helper.
- Tests must cover both successful service resolution and runner-visible failure persistence when resolution fails.

Confirmed feature rules:

1. Add `psr/container` as a runtime Composer dependency and type the optional runner container as `Psr\Container\ContainerInterface`.
2. Constructor injection supports only resolvable class or interface parameters. Constructors with scalar parameters, default-value fallbacks, union types, intersection types, variadics, or untyped parameters are invalid for runner-side reconstruction.
3. Any constructor parameter that cannot be resolved through the configured container is invalid and must fail reconstruction.
4. Every constructor-resolution failure is fatal and non-retryable. Retry metadata does not apply to task or step reconstruction failures.
5. Instantiation failures must persist a terminal failure state by updating all relevant queue diagnostics: `task_status`, `step_status`, `error_json`, `last_error_code`, and `last_error_message`.
6. Constructor resolvability is checked only when the runner claims work. Enqueue-time and workflow-definition-time validation remain the caller's responsibility.
7. Container-aware reconstruction is not runner-internal only. Every library path that reconstructs a task or step without an already-instantiated object must use the same container-aware instantiation logic.

With these decisions, the feature is now specified enough to implement.

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
- Payload root and top-level object identity caching.
- Payload normalization from `null` and legacy arrays.
- Named payload accessor and setter semantics.
- Retry policy decisions.
- Status transition validation.
- Error serialization.

### Integration Tests

Add PostgreSQL-backed tests for:

- Schema bootstrap on empty database.
- Enqueue and claim flow.
- Null payload hydration into the object view.
- Persistence of materialized top-level payload objects across enqueue, claim, retry, and next-step transitions.
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
5. Add a support path to dump the required schema DDL.

Exit criteria:

- Empty PostgreSQL database can be bootstrapped idempotently.
- Queue schema includes the cleanup deadline column used for terminal row retention.
- Schema bootstrap is executed once at startup or explicitly by the user, not before every database call.

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
3. Add the shared payload object helper and define how queue records hydrate domain instances.
4. Add unit tests with example task and step classes.

Exit criteria:

- A queued record can be reconstructed into executable objects.
- Repeated payload access on a task or step returns stable object references for the root payload and requested top-level payload objects.

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
4. Document schema bootstrap options and DDL export usage.
5. Document operational constraints and non-goals.

Exit criteria:

- A new user can understand the mental model and run the example locally.
- A new user can understand how to create the schema, whether through explicit bootstrap or exported DDL.

## Acceptance Criteria For V1

V1 is ready when all of the following are true:

- A task can be enqueued with a payload.
- `getPayload()` always exposes a stable root `stdClass`, even when the stored payload is `null`.
- `getPayload('foo')` materializes a stable top-level `stdClass` only when that property is missing or `null`; existing scalar, array, and object values are returned unchanged.
- Nested mutations on materialized payload objects survive later reads and persistence.
- The queue schema can be created on an empty PostgreSQL database through explicit bootstrap, and the runner may perform a one-time startup bootstrap.
- One or more runner processes can safely claim and execute tasks.
- Task state remains durable across runner restarts.
- A failed step is retried according to metadata.
- Exhausted retries result in terminal task failure.
- Cancellation is persisted and stops further workflow progression.
- Succeeded and failed task rows are deleted after their configured cleanup timeout.
- End-to-end tests cover the main lifecycle paths.
