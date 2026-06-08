# php-tr TODO

- github action to run tests with docker containers
- publish as composer package on packagist.org
- simple task  that takes a/multiple callables and runs it

- after job hook (also after cancel / failure)
- skip schema creation

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
