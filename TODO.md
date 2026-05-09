# Durable Tasks TODO

## Examples And Documentation

- [ ] Verify a new user can understand the mental model and run the example locally.
- [ ] Verify a new user can understand how to create the schema through explicit bootstrap or exported DDL.

## V1 Acceptance

- [ ] Verify the queue schema can be created on an empty PostgreSQL database through explicit bootstrap.
- [ ] Verify one or more runner processes can safely claim and execute tasks.
- [ ] Verify task state remains durable across runner restarts.
- [ ] Verify end-to-end tests cover the main lifecycle paths.

## Arbitary notes

- during singal shutdown, cleanup queue: running tasks should be reset to queued
- retry delay (time to retry)
- fetch task, task list functions
- re-queue task / step from within step
