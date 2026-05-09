# Durable Tasks TODO

## Phase 1: Project Skeleton

- [x] Add Composer package metadata and autoloading.
- [x] Create the base source layout.
- [x] Add enum and exception scaffolding.
- [x] Add test infrastructure.
- [x] Verify the package installs and autoloads.
- [x] Verify the test runner executes.

## Phase 2: Core Domain Types

- [x] Implement task and step status enums.
- [x] Implement the retry mode enum.
- [x] Implement `StepResult` and `ErrorInfo`.
- [x] Add tests for result and enum behavior.
- [x] Verify the core value objects are stable and tested.

## Phase 3: Attributes And Metadata

- [x] Implement `CleanupAfter`, `RetryMode`, `Retries`, and `MaxRuntime` attributes.
- [x] Implement metadata DTOs.
- [x] Implement metadata resolver and cache.
- [x] Add validation tests.
- [x] Verify invalid metadata fails early.
- [x] Verify precedence rules are covered by tests.
- [x] Verify task cleanup retention can be resolved from task metadata.

## Phase 4: Queue Schema And Records

- [x] Implement queue record representation.
- [x] Implement schema manager.
- [x] Implement queue configuration.
- [x] Add schema bootstrap integration tests.
- [x] Add a support path to dump the required schema DDL.
- [x] Verify an empty PostgreSQL database can be bootstrapped idempotently.
- [x] Verify the queue schema includes the cleanup deadline column used for terminal row retention.
- [x] Verify schema bootstrap runs only once at startup or explicitly by the user, not before every database call.

## Phase 5: Queue Operations

- [x] Implement enqueue.
- [x] Implement claim.
- [x] Implement transition persistence methods.
- [x] Implement notifications.
- [x] Implement expired task cleanup.
- [x] Add concurrency and cleanup integration tests.
- [x] Verify multiple runner processes cannot claim the same task at the same time.
- [x] Verify expired succeeded and failed task rows can be deleted safely without affecting active work.

## Phase 6: Task And Step Contracts

- [x] Implement base `Task` and `Step` classes.
- [x] Implement direct task and step instantiation from persisted class names.
- [x] Define how queue records hydrate domain instances.
- [x] Add unit tests with example task and step classes.
- [x] Verify a queued record can be reconstructed into executable objects.

## Phase 7: Runner

- [x] Implement runner configuration.
- [x] Implement signal handling.
- [x] Implement single mode.
- [x] Implement loop mode.
- [x] Implement optional one-time schema bootstrap on runner startup, disabled by default.
- [x] Implement runtime, cancellation, and cleanup checks.
- [x] Add integration tests for normal execution, retries, shutdown behavior, and retention cleanup.
- [x] Verify the runner can process tasks end-to-end and stop predictably.

## Phase 8: Examples And Documentation

- [x] Add one example workflow.
- [x] Document installation and runner usage.
- [x] Document attribute behavior.
- [x] Document schema bootstrap options and DDL export usage.
- [x] Document operational constraints and non-goals.
- [ ] Verify a new user can understand the mental model and run the example locally.
- [ ] Verify a new user can understand how to create the schema through explicit bootstrap or exported DDL.

## V1 Acceptance

- [x] Verify a task can be enqueued with a payload.
- [ ] Verify the queue schema can be created on an empty PostgreSQL database through explicit bootstrap.
- [x] Verify the runner can optionally perform a one-time startup bootstrap when configured.
- [ ] Verify one or more runner processes can safely claim and execute tasks.
- [ ] Verify task state remains durable across runner restarts.
- [x] Verify a failed step is retried according to metadata.
- [x] Verify exhausted retries result in terminal task failure.
- [x] Verify cancellation is persisted and stops further workflow progression.
- [x] Verify succeeded and failed task rows are deleted after their configured cleanup timeout.
- [ ] Verify end-to-end tests cover the main lifecycle paths.

## Task-Owned Payload Refactor

- [x] Refactor `src/Step.php` so `Step` no longer uses `HasPayloadAccess`, no longer stores payload state, and executes via `execute(Task $task): StepResult`.
- [x] Refactor `src/Task.php` so the task is the only payload owner and `updateStep()` persists the already-mutated task payload after each step execution.
- [x] Update `src/Runner.php` so every execution path passes the task into the step, never reads payload from the step object, and always persists the task payload after execution.
- [x] Inline the task-only payload API into `src/Task.php` and keep `src/PayloadNormalizer.php` as the dedicated normalization helper.
- [x] Remove payload state from `src/Result/StepResult.php` so it carries only outcome and diagnostics.
- [x] Remove step-payload handoff assumptions from the runner transition logic and step hydration path that treated the step as a payload owner.
- [x] Update all step fixtures under `tests/Fixture/` to `execute(Task $task): StepResult` and replace `$this->getPayload()` access with `$task->getPayload()`.
- [x] Update all example steps and helper tasks under `examples/` to the task-owned payload model and remove direct payload writes to step instances.
- [x] Rewrite unit and integration assertions that currently inspect payload through `actualStep()` so they validate task-owned payload behavior instead.


## PSR-11 Constructor Injection Support

- [x] Confirm the container contract for `RunnerConfiguration`: use `psr/container` and `Psr\Container\ContainerInterface`.
- [x] Confirm supported constructor signatures: class/interface parameters only, with no defaults or unresolved parameters.
- [x] Confirm constructor-resolution failures are terminal and non-retryable.
- [x] Confirm instantiation failures before `execute()` must persist `task_status`, `step_status`, `error_json`, `last_error_code`, and `last_error_message`.
- [x] Confirm constructor resolvability is checked only when a runner claims work.
- [x] Confirm all library reconstruction paths must use the same container-aware instantiation logic.
- [x] Add `psr/container` as a runtime Composer dependency.
- [x] Add an optional service container to `RunnerConfiguration`.
- [x] Introduce a shared constructor-resolution path for task and step reconstruction based on reflection plus container lookup.
- [x] Reject unsupported constructor parameter kinds with a configuration error that includes the class and parameter name.
- [x] Refactor runner claim processing so instantiation failures are persisted as task failures instead of bubbling out and leaving claimed work unresolved.
- [x] Define and implement the exact persisted failure state for task-construction and step-construction errors.
- [x] Update all reconstruction call sites so they can use the shared container-aware instantiation path.
- [x] Add unit tests for constructor resolution success and failure cases on both tasks and steps.
- [x] Add integration coverage for missing-container and missing-service scenarios.

## PSR-3 Logging

- [x] Add `psr/log` as a runtime Composer dependency.
- [x] Add optional `LoggerInterface` support to the `Task` base class constructor and expose `setLogger()` and `getLogger()`.
- [x] Add optional `LoggerInterface` support to the `Step` base class constructor and expose `setLogger()` and `getLogger()`.
- [x] Add optional `LoggerInterface` support to `RunnerConfiguration`.
- [x] Make `Runner` default to `Psr\Log\NullLogger` when no logger is configured.
- [x] Make `Runner` resolve exactly one active logger per execution path and reuse it consistently.
- [x] Ensure reconstructed tasks and steps always receive the active logger through `setLogger()` after hydration.
- [x] Keep constructor injection compatible when the configured PSR-11 container can resolve `LoggerInterface`.
- [x] Log task and step creation through the base classes.
- [x] Log task hydration, step hydration, `nextStep()` decisions, and `updateStep()` transitions.
- [x] Log queue enqueue, claim, update, notification, and expired-row deletion operations.
- [x] Log task and step status transitions including retry, cancellation, success, and failure paths.
- [x] Log caught exceptions, instantiation failures, and queue persistence failures with structured context.
- [x] Add unit tests for logger storage and propagation on `Task`, `Step`, and `RunnerConfiguration`.
- [x] Add runner tests that verify configured logger usage and `NullLogger` fallback resolution.
- [x] Add queue and integration tests that verify the required lifecycle and error events are emitted.

## FileAttachment Payload Support

- [x] Add a `FileAttachment` value object that keeps file metadata plus either transient in-memory content before persistence or a stored blob reference after hydration.
- [x] Add `FileAttachment::fromFile(string $path): self` to load a readable file into an attachment object.
- [x] Add `FileAttachment::toFile(string $path): void` to write stored attachment content back to disk.
- [x] Keep the public payload API unchanged so developers can assign attachments directly through `Task::getPayload()->foo->bar = FileAttachment::fromFile(...)`.
- [x] Define one reserved payload envelope marker for persisted `FileAttachment` values and store only metadata plus a `blobId` reference in `payload_json`.
- [x] Introduce a second PostgreSQL table for attachment binary data with a `bytea` content column.
- [x] Extend `SchemaManager` bootstrap and schema dump output so the blob table, indexes, and foreign key are created automatically together with the main queue table.
- [x] Add a foreign key from the blob table to the main task row with `ON DELETE CASCADE` so attachment data is removed automatically when a task row is deleted.
- [x] Finalize the planned blob-table columns: `blob_id`, `task_id`, `content`, `size_bytes`, `sha256`, and `created_at`.
- [x] Add the blob lookup index on `task_id`; leave `(task_id, sha256)` deduplication out of the first implementation.
- [x] Add a small attachment blob storage service to insert binary data, fetch binary data, and support file write-back without exposing raw SQL to `FileAttachment`.
- [x] Add recursive payload serialization so nested `FileAttachment` objects are stored automatically as metadata envelopes plus blob references before `payload_json` is written.
- [x] Add recursive payload hydration so persisted attachment envelopes are converted back into `FileAttachment` objects after queue record restore.
- [x] Ensure hydrated attachments can resolve their blob content again for `toFile()` and related read operations.
- [x] Reject unreadable files, missing blob rows, and invalid attachment envelopes with clear serialization or configuration errors.
- [x] Add unit tests for `FileAttachment` creation, metadata retention, and `toFile()` roundtrip behavior.
- [x] Add schema tests for blob-table bootstrap, DDL dump output, and foreign-key cascade behavior.
- [x] Add payload roundtrip tests for nested payload objects and arrays containing `FileAttachment` instances.
- [x] Add queue or runner persistence tests to verify automatic blob storage on database write and automatic attachment hydration on restore.
- [x] Update `README.md` with a short usage example showing direct payload assignment and restoring an attachment back to a file.


## Arbitary notes

- during singal shutdown, cleanup queue: running tasks should be reset to queued
- retry delay (time to retry)
- priority queue
- fetch job, job list functions
