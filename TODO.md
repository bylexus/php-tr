# php-tr TODO

## Feature: after-task hook [DONE]

When a task reaches a terminal state (succeeded, failed or cancelled) and that
state has been persisted, an implementing Task class can override an `afterTask()`
method that is then invoked. This allows final / after-task code to run
independent of the outcome (e.g. sending a result email).

- add `protected afterTask(TaskStatus $status)` no-op to the `Task` base class,
  overridable by subclasses; read final state via the regular getters.
- a public `dispatchAfterTaskHook()` invokes it for terminal states only and
  swallows/logs hook exceptions so a failing hook never disrupts the runner or
  the persisted state.
- fire it after the terminal commit on every terminal path: normal run,
  hydration/claim failure, max-runtime timeout cleanup, stop-request shutdown,
  and external `cancel()`.
- add an example in the README.md in an appropriate section.

## Feature: separate log column [DONE]

The Task record should get a possibility to log arbitary text in a separate log column:
The log column is part of the task queue table, but not loaded / stored with the normal hydration / storage of a Task: it is an append-only column that need to be stored / requested separately:

- update the SchemaManager to add a new log text column to the schema
- The Task class should get new methods:
    - appendLog(logString): This method appends text directly to the log column using a separate
      database update. The given string should be appended with a newline and the actual timestamp
      (e.g. "\n[{DATE_W3C}]: {logString}").
    - fetchLog(): string: returns the content of the log column by fetching it from the DB
    - The log column should never be loaded / hydrated / stored with the other properties,
      it is a special column only for logging. This way, long logs can be stored without carrying it around for queue management.
- add an example in the README.md in an appropriate section.

## Change: Remove schema creation [DONE]

The SchemaManager should not create the db schema or db (`create schema`, `create database`). This must be done by the user itself.
