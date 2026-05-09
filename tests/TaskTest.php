<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Enum\TaskStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\FileAttachment;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Task;
use ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedServiceFixture;
use ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\LoggerInjectedStepFixture;
use ByLexus\DurableTask\Tests\Fixture\LoggerInjectedTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\EmptyWorkflowTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\QueueWorkflowStepFixture;
use ByLexus\DurableTask\Tests\Fixture\QueueWorkflowTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\ScalarConstructorTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\ServiceAndLoggerInjectedTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\StepInjectedOnlyTaskFixture;
use ByLexus\DurableTask\Tests\Support\InMemoryContainer;
use ByLexus\DurableTask\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TaskTest extends TestCase
{
    public function testTaskProvidesPayloadClassContext(): void {
        self::assertSame(QueueWorkflowTaskFixture::class, QueueWorkflowTaskFixture::getPayloadClassContext());
    }

    public function testTaskAndStepCanAcceptLoggerInConstructor(): void {
        $logger = new SpyLogger();

        $task = new QueueWorkflowTaskFixture($logger);
        $step = new QueueWorkflowStepFixture($logger);

        self::assertNotNull($task->getLogger());
        self::assertNotNull($step->getLogger());
        self::assertTrue($logger->hasRecord('debug', 'Task created.'));
        self::assertTrue($logger->hasRecord('debug', 'Step created.'));
    }

    public function testTaskCanBeReconstitutedFromQueueRecord(): void {
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
        self::assertSame(2, $task->actualStep()?->getStepAttempt());
    }

    public function testTaskReconstitutionPropagatesLoggerToTaskAndStep(): void {
        $logger = new SpyLogger();
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
        self::assertNotNull($task->actualStep()?->getLogger());
        self::assertTrue($logger->hasRecord('debug', 'Task hydrated from queue record.'));
        self::assertTrue($logger->hasRecord('debug', 'Step hydrated from queue record.'));

        $taskRecord = $this->findLogRecord($logger, 'debug', 'Task hydrated from queue record.');
        $stepRecord = $this->findLogRecord($logger, 'debug', 'Step hydrated from queue record.');

        self::assertSame(42, $taskRecord['context']['taskId'] ?? null);
        self::assertSame(QueueWorkflowStepFixture::class, $taskRecord['context']['stepClass'] ?? null);
        self::assertSame(42, $stepRecord['context']['taskId'] ?? null);
        self::assertSame(QueueWorkflowStepFixture::class, $stepRecord['context']['stepClass'] ?? null);
    }

    public function testTaskReconstitutionInjectsProvidedLoggerWhenConstructorRequestsIt(): void {
        $logger = new SpyLogger();
        $record = new QueueRecord(
            42,
            LoggerInjectedTaskFixture::class,
            LoggerInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
            1,
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

        $this->expectException(ConfigurationException::class);

        $task->enqueue($this->createStub(\PDO::class));
    }

    public function testEnqueueRejectsInvalidPriority(): void {
        $task = new EmptyWorkflowTaskFixture();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Task priority must be between 1 and 5, got 0.');

        $task->enqueue($this->createStub(\PDO::class), priority: 0);
    }

    public function testNullPayloadIsExposedAsObjectOnTaskWhenStepIsHydrated(): void {
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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

        self::assertInstanceOf(\stdClass::class, $task->getStoredPayload());
        self::assertInstanceOf(\stdClass::class, $task->getPayload());
        self::assertInstanceOf(QueueWorkflowStepFixture::class, $task->actualStep());
        self::assertInstanceOf(\stdClass::class, $task->getStoredPayload());
    }

    public function testStoredPayloadMaterializesRootObjectWithoutPriorAccess(): void {
        $task = new QueueWorkflowTaskFixture();

        self::assertFalse($task->hasStoredPayload());
        self::assertInstanceOf(\stdClass::class, $task->getStoredPayload());
        self::assertTrue($task->hasStoredPayload());
        self::assertSame($task->getPayload(), $task->getStoredPayload());
    }

    public function testTaskHydratesAttachmentEnvelopeIntoFileAttachmentObject(): void {
        $record = new QueueRecord(
            42,
            QueueWorkflowTaskFixture::class,
            QueueWorkflowStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
        self::assertSame($rootPayload, $task->getStoredPayload());
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
            \ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
            \ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedStepFixture::class,
            $task->actualStep(),
        );
    }

    public function testTaskReconstitutionUsesFallbackLoggerWhenContainerDoesNotProvideLoggerService(): void {
        $record = new QueueRecord(
            42,
            ServiceAndLoggerInjectedTaskFixture::class,
            \ByLexus\DurableTask\Tests\Fixture\ServiceAndLoggerInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
            \ByLexus\DurableTask\Tests\Fixture\ServiceAndLoggerInjectedStepFixture::class,
            $typedTask->actualStep(),
        );
        $typedStep = $typedTask->actualStep();

        self::assertInstanceOf(
            \ByLexus\DurableTask\Tests\Fixture\ServiceAndLoggerInjectedStepFixture::class,
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
            1,
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
            \ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedStepFixture::class,
            TaskStatus::QUEUED->value,
            1,
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
            1,
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
}
