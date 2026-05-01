# Durable Tasks in PHP

This project's goal is to provide a durable task libary to execute queued tasks in a fail-proof and restartable manner.

## Main goals

* use database (postgresql) as queue / central state
* provide easy-to-use Task and Step classes:
  * a Task can initiate multiple Steps, which forms a workflow to be executed
  * The developer can configure its own Task / Step classes with PHP Annotations
* The developer has a simple interface through the Task class - no manual queue handling needed
* The Runner executes (multiple) tasks and manages the step executions, failures, retries etc.
* Framework agnostic: just plug it into any framework / architecture.
* Keep Task and Step classes separate so that as Task can configure a "Workflow" from defining a set of pre-defined steps.

## Core architecture

All functionality is offered to the developer as PHP classes that can be easily integrated into existing infrastructure. Each class takes a `PDO` connection object and additional configuration, so this project does not need to set up DB connections by itself.

### Task

The Task class defines the parent class for specific Tasks and manages a "Workflow", consisting on (one ore multiple) Steps to be executed. It is in the task's responsibility to initiate the steps needed in the order needed.

Task configuration can be done by using PHP Annotations for e.g. retries, runtime etc.

A possible interface (not final):

```php
namespace ByLexus\DurableTask;

// When a step fails the task, this config defnes if and how many times a Task should try to restart:
#[RetryMode(RetryMode::Restart)]
#[Retries(3)]
#[MaxRuntime(\DateInterval::createFromDateString('1 day'))]
// defines the maximum runtime of this job before it will get cancelled. Measured from the task's start time (not creation time)
#[MaxRuntime(\DateInterval::createFromDateString('1 day'))]
abstract class Task {
    // The payload to be stored with the task in the queue, usable by the steps. Something json-serializable that is storable in the DB.
    // The payload is stored in a json column in the queue.
    protected mixed $payload;
    // ... and other props from the queue table

    public function getId();
    public function getStatus();
    // other getters for task ...

    public function setPayload(mixed $payload);
    public function getPayload(): mixed;
    public function cancel(string $reason, ?bool $failed = true)
    public function actualStep(): ?Step;
    public function getRuntime(): \DateInterval;

    // starts this Task's workflow. It calls nextStep(null) and creates the needed queue entries:
    public function enqueue(\PDO $conn);

    // called by the Runner when a step update happens: done, fail, progress etc. The StepResult object contain the needed information:
    public function updateStep(Step $step, StepResult $result);

    // To be implemented by the developer: This function creates the Workflow: It gets the actual (done/failed etc.) step,
    // and decides what to run next. null means done, exception means fail.
    abstract public function nextStep(?Step $actStep = null): ?Step;
}
```

So a specific task can be defined and executed:

```php
class MailInfoTask extends Task {
    public function __construct(MailService $mail) { /* ... */ }
    public function nextStep(?Step $act = null): ?Step {
       /** decide which step to execute next: */ 
       return new SendMailStep($this->mail, $this->getPayload()->to, $this->getPayload()->subject, $this->getPayload()->body);
    }
}

$mailTask = new MailInfoTask($mailservice);
$mailTask->setPayload(['to' => 'info@foo.com', 'subject' => 'Info', 'body' => 'This is a Test']);
$mailTask->enqueue($dbConn);
```

### Step

The Step is the base class for one "Step" of a workflow, managed by the Task class. For example, a Workflow can consist of:

- collecting data from an API
- aggregate those data
- send result email

The step is just one of those tasks. It operats / updates on the tasks payload and returns a `StepResult` (or an exception).

Step configuration can be done by using PHP Annotations for e.g. retries, runtime etc.

A possible interface (not final):

```php
namespace ByLexus\DurableTask;

// may be:
// - Fail: no retry, fail after first error, which also fails the task
// - Restart: retries the step if failed
// - Skip: if failed, the step is just skipped, but does not fail the task
#[RetryMode(RetryMode::Restart)]
#[Retries(3)]
#[MaxRuntime(\DateInterval::createFromDateString('1 day'))]
class Step {
    public function getId();
    public function getStatus();
    // other getters for step ...

    public function setPayload(mixed $payload);
    public function getPayload(): mixed;
    public function cancel(string $reason, ?bool $failed = true)
    public function getRuntime(): \DateInterval;

    // Here is where the specific work for this step is executed. It must result in a Step result (no exceptions).
    abstract public function execute(): \StepResult;
}
```

### Runner

The task runner is also offered as a PHP class that can be executed as a permanent running task using your own infrastructure.

* Takes a PDO connection to access the work queue (postgres table)
* single / loop mode: In single mode, the runner executes all tasks currently in the queue and ends.
  In loop mode, the runner waits / polls for new jobs once if done in a loop:
  * polls the queue for new jobs
  * executes the job(s) in the queue
  * once done, waits for postgresql notify events until a timeout
  * re-loop
* cancellation can be initiated by a signal (sigterm). The runner then waits for running steps to be done (until a timeout), and ends. Steps that needs to be killed after the timeout will be marked failed.
* Tasks can be executed in parallel by running multiple runners (manually). Per task, only one step must run, but steps from different tasks can run in parallel. Unfortunately, PHP does not support threading in any way. There are possibilities with forking, but they are not really feasible as they create a new process with new context. So the simplest thing is to just execute the runner multiple times (e.g. by using `supervisord` to spawn multiple processes), and the queue makes sure a task only gets handed out once.


### Work queue

This class manages the PostgreSQL based queue system:

The work queue is a postgresql table that contain the steps in the queue, created and managed by the Task class. Each update emits a postgresql notification (notify/listen mechanism) to inform the runner to process new items.
Access to a task step is locked, only one process must be able to update a single entry.

The following set of columns might be necessary:

- task-id and step-id (primary key, auto-generated: only one step per task at a time)
- task-id itself must be unique, as only one task step at a time can run
- created timestamp
- modified timestamp
- task class
- task status
- step class
- step status
- task started timestamp
- step started timestamp 
- task end timestamp
- step end timestamp 
- payload (json, arbitary data)
- result (json, e.g. errors etc.)

The queue class can create / get / update entries in the queue. All other classes must use the Queue class to update it, no direct update allowed.
It cal also be used by the user to get information on the actual queue state (stats, e.g. waiting, done, etc.)

The Work queue class also manages its table by itself: It should detect if the queue table is already created, and creates it if it is not.
The queue table's name must be configurable, e.g. by setting a constant or other technique.


