<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Metadata\MetadataResolver;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\Queue\SchemaManager;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\TaskFilter;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedServiceFixture;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\ServiceAndLoggerInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Support\InMemoryContainer;
use ByLexus\TaskRunner\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

final class TaskEnvironmentTest extends TestCase
{
    public function testTaskEnvironmentExposesConfiguredConnectionAndQueueConfiguration(): void {
        $connection = $this->createStub(\PDO::class);
        $configuration = new QueueConfiguration('custom_queue', 'custom_schema');
        $container = new InMemoryContainer([]);
        $logger = new SpyLogger();
        $runnerConfiguration = new RunnerConfiguration('runner-test');
        $metadataResolver = new MetadataResolver();

        $context = new TaskEnvironment(
            $connection,
            $configuration,
            $container,
            $logger,
            $runnerConfiguration,
            $metadataResolver,
        );

        self::assertSame($connection, $context->getConnection());
        self::assertSame($configuration, $context->getQueueConfiguration());
        self::assertSame($container, $this->readPrivateProperty($context, 'container'));
        self::assertSame($logger, $this->readPrivateProperty($context, 'logger'));
        $storedRunnerConfiguration = $this->readPrivateProperty($context, 'runnerConfiguration');

        self::assertInstanceOf(RunnerConfiguration::class, $storedRunnerConfiguration);
        self::assertSame($runnerConfiguration->getRunnerId(), $storedRunnerConfiguration->getRunnerId());
        self::assertSame($container, $storedRunnerConfiguration->getContainer());
        self::assertSame($logger, $storedRunnerConfiguration->getLogger());
        self::assertSame($metadataResolver, $this->readPrivateProperty($context, 'metadataResolver'));
    }

    public function testTaskEnvironmentEnqueueDelegatesToTaskWithStoredMetadataResolver(): void {
        $connection = $this->createStub(\PDO::class);
        $configuration = new QueueConfiguration('custom_queue', 'custom_schema');
        $metadataResolver = new MetadataResolver();
        $expectedRecord = new QueueRecord(
            42,
            'task-class',
            'step-class',
            'queued',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            'queued',
            0,
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            null,
            false,
            null,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            Task::PRIO_HIGH,
        );
        $task = new class ($expectedRecord) extends Task {
            public ?TaskEnvironment $receivedTaskEnvironment = null;
            public ?int $receivedPriority = null;
            private QueueRecord $record;

            public function __construct(QueueRecord $record) {
                parent::__construct();
                $this->record = $record;
            }

            public function nextStep(?Step $actStep = null): ?Step {
                return null;
            }

            public function enqueue(TaskEnvironment $taskEnvironment, int $priority = self::PRIO_NORMAL): QueueRecord {
                $this->receivedTaskEnvironment = $taskEnvironment;
                $this->receivedPriority = $priority;

                return $this->record;
            }
        };

        $context = new TaskEnvironment(
            $connection,
            $configuration,
            metadataResolver: $metadataResolver,
        );
        $record = $context->enqueue($task, Task::PRIO_HIGH);

        self::assertSame($expectedRecord, $record);
        self::assertSame($context, $task->receivedTaskEnvironment);
        self::assertSame(Task::PRIO_HIGH, $task->receivedPriority);
        self::assertSame($metadataResolver, $task->receivedTaskEnvironment?->getMetadataResolver());
        self::assertSame($configuration, $task->receivedTaskEnvironment?->getQueueConfiguration());
    }

    public function testTaskEnvironmentCreateRunnerUsesStoredConfigurationDependenciesAndMetadataResolver(): void {
        $connection = $this->createStub(\PDO::class);
        $configuration = new QueueConfiguration('custom_queue');
        $container = new InMemoryContainer([]);
        $logger = new SpyLogger();
        $runnerConfiguration = new RunnerConfiguration('runner-test');
        $metadataResolver = new MetadataResolver();

        $context = new TaskEnvironment(
            $connection,
            $configuration,
            $container,
            $logger,
            $runnerConfiguration,
            $metadataResolver,
        );
        $runner = $context->createRunner();
        $queue = $context->getDatabaseQueue();
        $resolvedRunnerConfiguration = $this->readPrivateProperty($runner, 'runnerConfiguration');

        self::assertSame($connection, $this->readPrivateProperty($runner, 'connection'));
        self::assertSame($configuration, $this->readPrivateProperty($this->readPrivateProperty($runner, 'taskEnvironment'), 'queueConfiguration'));
        self::assertInstanceOf(RunnerConfiguration::class, $resolvedRunnerConfiguration);
        self::assertSame('runner-test', $resolvedRunnerConfiguration->getRunnerId());
        self::assertSame($container, $resolvedRunnerConfiguration->getContainer());
        self::assertSame($logger, $resolvedRunnerConfiguration->getLogger());
        self::assertSame($metadataResolver, $this->readPrivateProperty($runner, 'metadataResolver'));
        self::assertSame($logger, $this->readPrivateProperty($runner, 'logger'));
        self::assertSame($queue, $this->readPrivateProperty($runner, 'queue'));
    }

    public function testTaskEnvironmentCreatesSchemaHelpersFromStoredQueueConfiguration(): void {
        $connection = $this->createStub(\PDO::class);
        $configuration = new QueueConfiguration('custom_queue', 'custom_schema');
        $context = new TaskEnvironment($connection, $configuration);

        $queue = $context->getDatabaseQueue();
        $sameQueue = $context->getDatabaseQueue();
        $schemaManager = $context->getSchemaManager();
        $sameSchemaManager = $context->getSchemaManager();
        $ddl = $context->getSchemaManager()->exportDdl();

        self::assertInstanceOf(DatabaseQueue::class, $queue);
        self::assertSame($queue, $sameQueue);
        self::assertInstanceOf(SchemaManager::class, $schemaManager);
        self::assertSame($schemaManager, $sameSchemaManager);
        self::assertSame($context, $this->readPrivateProperty($schemaManager, 'taskEnvironment'));
        self::assertStringContainsString('CREATE SCHEMA IF NOT EXISTS "custom_schema"', $ddl);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS "custom_schema"."custom_queue"',
            $ddl,
        );
    }

    public function testTaskEnvironmentGetTaskRehydratesTaskWithContainerAndLogger(): void {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is not available.');
        }

        $connection = new \PDO('sqlite::memory:');
        $configuration = new QueueConfiguration('task_queue');
        $service = new ConstructorInjectedServiceFixture('mailer');
        $logger = new SpyLogger();
        $container = new InMemoryContainer([
            ConstructorInjectedServiceFixture::class => $service,
        ]);
        $context = new TaskEnvironment($connection, $configuration, $container, $logger);
        $context->getSchemaManager()->bootstrap();

        $record = $context->enqueue(new ServiceAndLoggerInjectedTaskFixture($service, $logger));

        $task = $context->getTask((int) $record->taskId);

        self::assertInstanceOf(ServiceAndLoggerInjectedTaskFixture::class, $task);
        self::assertSame($record->taskId, $task->getId());
        self::assertSame(TaskStatus::QUEUED, $task->getStatus());
        self::assertSame('mailer', $task->getInjectedServiceName());
        self::assertSame($logger, $task->getInjectedLogger());
    }

    public function testTaskEnvironmentGetTasksReturnsAllTasksOrFiltersByState(): void {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is not available.');
        }

        $connection = new \PDO('sqlite::memory:');
        $configuration = new QueueConfiguration('task_queue');
        $container = new InMemoryContainer([
            ConstructorInjectedServiceFixture::class => new ConstructorInjectedServiceFixture('worker'),
        ]);
        $context = new TaskEnvironment($connection, $configuration, $container);
        $context->getSchemaManager()->bootstrap();

        $failedRecord = $context->enqueue(new QueueWorkflowTaskFixture(), Task::PRIO_VERY_HIGH);
        $queuedRecord = $context->enqueue(new QueueWorkflowTaskFixture(), Task::PRIO_HIGH);
        $injectedRecord = $context->enqueue(
            new ConstructorInjectedTaskFixture(new ConstructorInjectedServiceFixture('worker')),
            Task::PRIO_NORMAL,
        );

        $queue = new DatabaseQueue($connection, $configuration);
        $connection->beginTransaction();
        $queue->update((int) $failedRecord->taskId, ['task_status' => TaskStatus::FAILED]);
        $connection->commit();

        $allTasks = $context->getTasks();
        $failedTasks = $context->getTasks(new TaskFilter(status: TaskStatus::FAILED));

        self::assertCount(3, $allTasks);
        self::assertSame(
            [(int) $failedRecord->taskId, (int) $queuedRecord->taskId, (int) $injectedRecord->taskId],
            array_map(static fn (Task $task): ?int => $task->getId(), $allTasks),
        );
        self::assertCount(1, $failedTasks);
        self::assertSame((int) $failedRecord->taskId, $failedTasks[0]->getId());
        self::assertSame(TaskStatus::FAILED, $failedTasks[0]->getStatus());
        self::assertInstanceOf(ConstructorInjectedTaskFixture::class, $allTasks[2]);
    }

    public function testTaskEnvironmentGetTasksFiltersByTaskClassStepClassAndCombinations(): void {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is not available.');
        }

        $connection = new \PDO('sqlite::memory:');
        $configuration = new QueueConfiguration('task_queue');
        $container = new InMemoryContainer([
            ConstructorInjectedServiceFixture::class => new ConstructorInjectedServiceFixture('worker'),
        ]);
        $context = new TaskEnvironment($connection, $configuration, $container);
        $context->getSchemaManager()->bootstrap();

        $workflowRecord = $context->enqueue(new QueueWorkflowTaskFixture());
        $injectedRecord = $context->enqueue(
            new ConstructorInjectedTaskFixture(new ConstructorInjectedServiceFixture('worker')),
        );

        // filter by task class
        $byTaskClass = $context->getTasks(new TaskFilter(taskClass: QueueWorkflowTaskFixture::class));
        self::assertCount(1, $byTaskClass);
        self::assertSame((int) $workflowRecord->taskId, $byTaskClass[0]->getId());

        // filter by step class
        $byStepClass = $context->getTasks(new TaskFilter(stepClass: QueueWorkflowStepFixture::class));
        self::assertCount(1, $byStepClass);
        self::assertSame((int) $workflowRecord->taskId, $byStepClass[0]->getId());

        // combined AND: matching task + step
        $byBoth = $context->getTasks(new TaskFilter(
            taskClass: QueueWorkflowTaskFixture::class,
            stepClass: QueueWorkflowStepFixture::class,
        ));
        self::assertCount(1, $byBoth);
        self::assertSame((int) $workflowRecord->taskId, $byBoth[0]->getId());

        // combined AND: mismatched task + step returns nothing
        $byMismatch = $context->getTasks(new TaskFilter(
            taskClass: QueueWorkflowTaskFixture::class,
            stepClass: ConstructorInjectedStepFixture::class,
        ));
        self::assertCount(0, $byMismatch);

        // combined status + task class
        $byStatusAndClass = $context->getTasks(new TaskFilter(
            status: TaskStatus::QUEUED,
            taskClass: ConstructorInjectedTaskFixture::class,
        ));
        self::assertCount(1, $byStatusAndClass);
        self::assertSame((int) $injectedRecord->taskId, $byStatusAndClass[0]->getId());

        // no filter returns all
        self::assertCount(2, $context->getTasks());
    }

    public function testTaskEnvironmentUsesRunnerConfigurationDependenciesWhenContextOnesAreNotProvided(): void {
        $connection = $this->createStub(\PDO::class);
        $configuration = new QueueConfiguration('custom_queue');
        $runnerContainer = new InMemoryContainer([]);
        $runnerLogger = new SpyLogger();
        $runnerConfiguration = new RunnerConfiguration('runner-test', true, 15, $runnerContainer, $runnerLogger);

        $context = new TaskEnvironment($connection, $configuration, runnerConfiguration: $runnerConfiguration);
        $runner = $context->createRunner();
        $resolvedRunnerConfiguration = $this->readPrivateProperty($runner, 'runnerConfiguration');

        self::assertSame($runnerConfiguration, $resolvedRunnerConfiguration);
        self::assertSame($runnerContainer, $resolvedRunnerConfiguration->getContainer());
        self::assertSame($runnerLogger, $resolvedRunnerConfiguration->getLogger());
        self::assertSame($runnerContainer, $this->readPrivateProperty($context, 'container'));
        self::assertSame($runnerLogger, $this->readPrivateProperty($context, 'logger'));
    }

    private function readPrivateProperty(object $object, string $propertyName): mixed {
        $reflection = new \ReflectionProperty($object, $propertyName);

        return $reflection->getValue($object);
    }
}
