<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\FileAttachment;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedServiceFixture;
use ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\LoggerInjectedStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\LoggerInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\EmptyWorkflowTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\QueueWorkflowTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\ScalarConstructorTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\ServiceAndLoggerInjectedTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\StepInjectedOnlyTaskFixture;
use ByLexus\TaskRunner\Tests\Support\InMemoryContainer;
use ByLexus\TaskRunner\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TaskTest extends TestCase
{
    public function testTaskCanAcceptLoggerInConstructor(): void {
        $logger = new SpyLogger();

        $task = new QueueWorkflowTaskFixture($logger);

        self::assertNotNull($task->getLogger());
        self::assertTrue($logger->hasRecord('debug', 'Task created.'));
    }

    public function testCancelMarksDetachedTaskAsCancelledInMemory(): void {
        $task = new QueueWorkflowTaskFixture();

        $task->cancel('Cancelled locally.');

        self::assertTrue($task->isCancelRequested());
        self::assertSame('Cancelled locally.', $task->getCancelReason());
        self::assertSame(TaskStatus::CANCELLED, $task->getStatus());
    }

    public function testReloadRefreshesCancellationStateFromDatabase(): void {
        $environment = $this->createSqliteTaskEnvironment();
        $record = (new QueueWorkflowTaskFixture())->enqueue($environment);

        self::assertNotNull($record->taskId);

        $runningTask = $environment->getTask((int) $record->taskId);
        $controlTask = $environment->getTask((int) $record->taskId);

        $controlTask->cancel('Cancelled remotely.');

        self::assertFalse($runningTask->isCancelRequested());

        $runningTask->reload();

        self::assertTrue($runningTask->isCancelRequested());
        self::assertSame('Cancelled remotely.', $runningTask->getCancelReason());
        self::assertSame(TaskStatus::CANCELLED, $runningTask->getStatus());
    }

    public function testPersistPayloadStoresOnlyPayloadChanges(): void {
        $environment = $this->createSqliteTaskEnvironment();
        $record = (new QueueWorkflowTaskFixture())->enqueue($environment);

        self::assertNotNull($record->taskId);

        $task = $environment->getTask((int) $record->taskId);

        self::assertSame(TaskStatus::QUEUED, $task->getStatus());
        self::assertSame(StepStatus::QUEUED, $task->getStepStatus());

        $task->getPayload()->checkpoint = 'phase-1';
        $task->persistPayload();

        $reloaded = $environment->getTask((int) $record->taskId);

        self::assertSame('phase-1', $reloaded->getPayload()->checkpoint);
        self::assertSame(TaskStatus::QUEUED, $reloaded->getStatus());
        self::assertSame(StepStatus::QUEUED, $reloaded->getStepStatus());
    }

    public function testReloadFailsForDetachedTask(): void {
        $task = new QueueWorkflowTaskFixture();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'ByLexus\\TaskRunner\\Task::reload requires an enqueued task bound to a database queue.',
        );

        $task->reload();
    }

    public function testPersistPayloadFailsForDetachedTask(): void {
        $task = new QueueWorkflowTaskFixture();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'ByLexus\\TaskRunner\\Task::persistPayload requires an enqueued task bound to a database queue.',
        );

        $task->persistPayload();
    }

    public function testTaskCanBeReconstitutedFromQueueRecord(): void {
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
            Task::PRIO_LOW,
        );

        $task = Task::fromQueueRecord($record);

        self::assertInstanceOf(QueueWorkflowTaskFixture::class, $task);
        self::assertSame(42, $task->getId());
        self::assertSame(Task::PRIO_LOW, $task->getPriority());
        self::assertInstanceOf(\stdClass::class, $task->getPayload());
        self::assertSame('bar', $task->getPayload()->foo);
        self::assertInstanceOf(QueueWorkflowStepFixture::class, $task->actualStep());
        self::assertSame(2, $task->getStepAttempt());
    }

    public function testTaskExposesAllHydratedQueueColumnsViaGetters(): void {
        $createdAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $startedAt = new \DateTimeImmutable('2026-01-01T00:01:00+00:00');
        $finishedAt = new \DateTimeImmutable('2026-01-01T00:02:00+00:00');
        $cleanupAt = new \DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $stepStartedAt = new \DateTimeImmutable('2026-01-01T00:01:10+00:00');
        $stepFinishedAt = new \DateTimeImmutable('2026-01-01T00:01:50+00:00');
        $availableAt = new \DateTimeImmutable('2026-01-01T00:00:30+00:00');
        $claimedAt = new \DateTimeImmutable('2026-01-01T00:01:05+00:00');
        $updatedAt = new \DateTimeImmutable('2026-01-01T00:02:10+00:00');
        $result = (object) ['status' => 'failed', 'meta' => (object) ['retry' => 1], 'message' => 'boom'];
        $error = (object) ['code' => 500, 'message' => 'internal', 'details' => (object) ['trace' => 'x']];

        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::FAILED->value,
            $createdAt,
            $startedAt,
            $finishedAt,
            $cleanupAt,
            StepStatus::FAILED->value,
            3,
            $stepStartedAt,
            $stepFinishedAt,
            ['foo' => 'bar'],
            $result,
            $error,
            $availableAt,
            $claimedAt,
            'runner-1',
            '500',
            'internal',
            true,
            'stop',
            $updatedAt,
            Task::PRIO_HIGH,
        );

        $task = Task::fromQueueRecord($record);

        self::assertSame(42, $task->getId());
        self::assertSame(QueueWorkflowTaskFixture::class, $task->getTaskClass());
        self::assertSame(QueueWorkflowStepFixture::class, $task->getStepClass());
        self::assertSame(TaskStatus::FAILED, $task->getStatus());
        self::assertSame(Task::PRIO_HIGH, $task->getPriority());
        self::assertSame($createdAt, $task->getCreatedAt());
        self::assertSame($startedAt, $task->getStartedAt());
        self::assertSame($finishedAt, $task->getFinishedAt());
        self::assertSame($cleanupAt, $task->getCleanupAt());
        self::assertSame(StepStatus::FAILED, $task->getStepStatus());
        self::assertSame(3, $task->getStepAttempt());
        self::assertSame($stepStartedAt, $task->getStepStartedAt());
        self::assertSame($stepFinishedAt, $task->getStepFinishedAt());
        self::assertSame($result, $task->getResult());
        self::assertSame($error, $task->getError());
        self::assertSame($availableAt, $task->getAvailableAt());
        self::assertSame($claimedAt, $task->getClaimedAt());
        self::assertSame('runner-1', $task->getClaimedBy());
        self::assertSame('500', $task->getLastErrorCode());
        self::assertSame('internal', $task->getLastErrorMessage());
        self::assertTrue($task->isCancelRequested());
        self::assertSame('stop', $task->getCancelReason());
        self::assertSame($updatedAt, $task->getUpdatedAt());
    }

    public function testTaskReconstitutionPropagatesLoggerToTask(): void {
        $logger = new SpyLogger();
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );

        $task = Task::fromQueueRecord($record, null, $logger);

        self::assertNotNull($task->getLogger());
        self::assertInstanceOf(QueueWorkflowStepFixture::class, $task->actualStep());
        self::assertTrue($logger->hasRecord('debug', 'Task hydrated from queue record.'));

        $taskRecord = $this->findLogRecord($logger, 'debug', 'Task hydrated from queue record.');

        self::assertSame(42, $taskRecord['context']['taskId'] ?? null);
        self::assertSame(QueueWorkflowStepFixture::class, $taskRecord['context']['stepClass'] ?? null);
    }

    public function testTaskReconstitutionInjectsProvidedLoggerWhenConstructorRequestsIt(): void {
        $logger = new SpyLogger();
        $record = new QueueRecord(
            42,
            LoggerInjectedTaskFixture::class,
            LoggerInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );

        $task = Task::fromQueueRecord($record, null, $logger);

        self::assertInstanceOf(LoggerInjectedTaskFixture::class, $task);
        $typedTask = $task;

        self::assertSame($logger, $typedTask->getInjectedLogger());
        self::assertInstanceOf(LoggerInjectedStepFixture::class, $typedTask->actualStep());
        $typedStep = $typedTask->actualStep();

        self::assertInstanceOf(LoggerInjectedStepFixture::class, $typedStep);
        self::assertSame($logger, $typedStep->getInjectedLogger());
    }

    public function testTaskReconstitutionUsesNullLoggerFallbackWhenLoggerIsNotConfigured(): void {
        $record = new QueueRecord(
            42,
            LoggerInjectedTaskFixture::class,
            LoggerInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );

        $task = Task::fromQueueRecord($record);

        self::assertInstanceOf(LoggerInjectedTaskFixture::class, $task);
        $typedTask = $task;

        self::assertInstanceOf(NullLogger::class, $typedTask->getInjectedLogger());
        self::assertInstanceOf(LoggerInjectedStepFixture::class, $typedTask->actualStep());
        $typedStep = $typedTask->actualStep();

        self::assertInstanceOf(LoggerInjectedStepFixture::class, $typedStep);
        self::assertInstanceOf(NullLogger::class, $typedStep->getInjectedLogger());
    }

    public function testEnqueueRequiresInitialStep(): void {
        $task = new EmptyWorkflowTaskFixture();
        $env = new TaskEnvironment($this->createStub(\PDO::class));

        $this->expectException(ConfigurationException::class);

        $task->enqueue($env);
    }

    public function testEnqueueRejectsInvalidPriority(): void {
        $task = new EmptyWorkflowTaskFixture();
        $env = new TaskEnvironment($this->createStub(\PDO::class));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Task priority must be between 1 and 5, got 0.');

        $task->enqueue($env, priority: 0);
    }

    public function testNullPayloadIsExposedAsObjectOnTaskWhenStepIsHydrated(): void {
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
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
        );

        $task = Task::fromQueueRecord($record);

        self::assertInstanceOf(\stdClass::class, $task->getPayload());
        self::assertInstanceOf(\stdClass::class, $task->getPayload());
        self::assertInstanceOf(QueueWorkflowStepFixture::class, $task->actualStep());
        self::assertInstanceOf(\stdClass::class, $task->getPayload());
    }

    public function testStoredPayloadMaterializesRootObjectWithoutPriorAccess(): void {
        $task = new QueueWorkflowTaskFixture();

        self::assertFalse($task->hasStoredPayload());
        self::assertInstanceOf(\stdClass::class, $task->getPayload());
        self::assertTrue($task->hasStoredPayload());
        self::assertSame($task->getPayload(), $task->getPayload());
    }

    public function testTaskHydratesAttachmentEnvelopeIntoFileAttachmentObject(): void {
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            (object) [
                'attachment' => (object) [
                    FileAttachment::TYPE_MARKER_FIELD => FileAttachment::TYPE_MARKER_VALUE,
                    'blobId' => 15,
                    'name' => 'invoice.pdf',
                    'mimeType' => 'application/pdf',
                    'sizeBytes' => 128,
                    'sha256' => str_repeat('a', 64),
                ],
            ],
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
        );

        $task = Task::fromQueueRecord($record);
        $attachment = $task->getPayload()->attachment;

        self::assertInstanceOf(FileAttachment::class, $attachment);
        self::assertSame(15, $attachment->blobId());
        self::assertSame('invoice.pdf', $attachment->name());
        self::assertSame('application/pdf', $attachment->mimeType());
    }

    public function testPayloadAccessCachesRootAndMaterializedTopLevelObject(): void {
        $task = new QueueWorkflowTaskFixture();

        $rootPayload = $task->getPayload();
        $namedPayload = $task->getPayload('details');
        $namedPayload->bar = 'somevalue';

        self::assertSame($rootPayload, $task->getPayload());
        self::assertSame($namedPayload, $task->getPayload('details'));
        self::assertSame($namedPayload, $task->getPayload()->details);
        self::assertSame('somevalue', $task->getPayload()->details->bar);
        self::assertSame($rootPayload, $task->getPayload());
    }

    public function testTopLevelPayloadValuesRemainUntouchedWhenAlreadySet(): void {
        $task = new QueueWorkflowTaskFixture();

        $task->setPayload(['details' => ['bar' => 'baz'], 'count' => 3]);

        self::assertSame(['bar' => 'baz'], $task->getPayload('details'));
        self::assertSame(3, $task->getPayload('count'));
        self::assertSame(['bar' => 'baz'], $task->getPayload()->details);
    }

    public function testNamedSetterStoresTopLevelValues(): void {
        $task = new QueueWorkflowTaskFixture();

        $task->setPayload('details', ['bar' => 'baz']);
        $task->setPayload('count', 3);

        self::assertSame(['bar' => 'baz'], $task->getPayload('details'));
        self::assertSame(3, $task->getPayload('count'));
    }

    public function testRootScalarPayloadIsRejected(): void {
        $task = new QueueWorkflowTaskFixture();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Root payload must be null, an array, or an object.');

        $task->setPayload('invalid-root-payload');
    }

    public function testTaskCanBeReconstitutedWithContainerResolvedConstructorDependencies(): void {
        $record = new QueueRecord(
            42,
            ConstructorInjectedTaskFixture::class,
            \ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );
        $service = new ConstructorInjectedServiceFixture('mailer');
        $container = new InMemoryContainer([
            ConstructorInjectedServiceFixture::class => $service,
        ]);

        $task = Task::fromQueueRecord($record, $container);

        self::assertInstanceOf(ConstructorInjectedTaskFixture::class, $task);
        self::assertSame('mailer', $task->getInjectedServiceName());
        self::assertInstanceOf(
            \ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedStepFixture::class,
            $task->actualStep(),
        );
    }

    public function testTaskReconstitutionUsesFallbackLoggerWhenContainerDoesNotProvideLoggerService(): void {
        $record = new QueueRecord(
            42,
            ServiceAndLoggerInjectedTaskFixture::class,
            \ByLexus\TaskRunner\Tests\Fixture\ServiceAndLoggerInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );
        $service = new ConstructorInjectedServiceFixture('mailer');
        $logger = new SpyLogger();
        $container = new InMemoryContainer([
            ConstructorInjectedServiceFixture::class => $service,
        ]);

        $task = Task::fromQueueRecord($record, $container, $logger);

        self::assertInstanceOf(ServiceAndLoggerInjectedTaskFixture::class, $task);
        $typedTask = $task;

        self::assertSame('mailer', $typedTask->getInjectedServiceName());
        self::assertSame($logger, $typedTask->getInjectedLogger());
        self::assertInstanceOf(
            \ByLexus\TaskRunner\Tests\Fixture\ServiceAndLoggerInjectedStepFixture::class,
            $typedTask->actualStep(),
        );
        $typedStep = $typedTask->actualStep();

        self::assertInstanceOf(
            \ByLexus\TaskRunner\Tests\Fixture\ServiceAndLoggerInjectedStepFixture::class,
            $typedStep,
        );
        self::assertSame($logger, $typedStep->getInjectedLogger());
    }

    public function testTaskReconstitutionFailsWithoutContainerForInjectedTask(): void {
        $record = new QueueRecord(
            42,
            ConstructorInjectedTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Task class requires a configured service container');

        Task::fromQueueRecord($record);
    }

    public function testTaskReconstitutionFailsWhenStepDependencyCannotBeResolved(): void {
        $record = new QueueRecord(
            42,
            StepInjectedOnlyTaskFixture::class,
            \ByLexus\TaskRunner\Tests\Fixture\ConstructorInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Step class requires a configured service container');

        Task::fromQueueRecord($record);
    }

    public function testTaskReconstitutionRejectsUnsupportedConstructorParameterKinds(): void {
        $record = new QueueRecord(
            42,
            ScalarConstructorTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            null,
            null,
            null,
            StepStatus::QUEUED->value,
            2,
            null,
            null,
            ['foo' => 'bar'],
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
        );
        $container = new InMemoryContainer([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Task class constructor parameter $name must be a resolvable class or interface type',
        );

        Task::fromQueueRecord($record, $container);
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function findLogRecord(SpyLogger $logger, string $level, string $message): array {
        foreach ($logger->getRecords() as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                return $record;
            }
        }

        self::fail(sprintf('Expected log record was not found: %s [%s]', $message, $level));
    }

    private function createSqliteTaskEnvironment(): TaskEnvironment {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $environment = new TaskEnvironment($pdo);
        $environment->getSchemaManager()->bootstrap();

        return $environment;
    }
}
