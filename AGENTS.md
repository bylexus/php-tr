# Agent information

## Project

PHP Task Runner (`bylexus/php-tr`) is a framework-agnostic, database-backed workflow library for PHP 8.3+ background jobs.
Work is modeled as `Task` workflows composed of `Step` units, and runners execute them safely across multiple worker processes.
Queue state, retries, scheduling, cancellation, and payload/result data are persisted in the database.

## Important Files

- `README.md`: Main integration guide with setup, quickstart, concepts, and runner usage.
- `src/Task.php`: Core abstract workflow entity that stores payload and all persisted task/step lifecycle state.
- `src/Step.php`: Minimal step contract that defines executable work via `execute(Task $task): StepResult`.
- `src/Runner.php`: Worker runtime that claims queued tasks, executes steps, handles retries/timeouts, and persists lifecycle transitions.
- `src/TaskEnvironment.php`: Central runtime entry point that wires PDO, queue config, schema management, enqueueing, task lookup, and runner creation.
- `src/Queue/SchemaManager.php`: Database schema utility that bootstraps, exports DDL, and validates required queue/blob table columns.
