<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Enum\TaskStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Task;
use ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedServiceFixture;
use ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\EmptyWorkflowTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\QueueWorkflowStepFixture;
use ByLexus\DurableTask\Tests\Fixture\QueueWorkflowTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\ScalarConstructorTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\StepInjectedOnlyTaskFixture;
use ByLexus\DurableTask\Tests\Support\InMemoryContainer;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    public function testTaskProvidesPayloadClassContext(): void {
        self::assertSame(QueueWorkflowTaskFixture::class, QueueWorkflowTaskFixture::getPayloadClassContext());
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
        );

        $task = Task::fromQueueRecord($record);

        self::assertInstanceOf(QueueWorkflowTaskFixture::class, $task);
        self::assertSame(42, $task->getId());
        self::assertInstanceOf(\stdClass::class, $task->getPayload());
        self::assertSame('bar', $task->getPayload()->foo);
        self::assertInstanceOf(QueueWorkflowStepFixture::class, $task->actualStep());
        self::assertSame(2, $task->actualStep()?->getStepAttempt());
    }

    public function testEnqueueRequiresInitialStep(): void {
        $task = new EmptyWorkflowTaskFixture();

        $this->expectException(ConfigurationException::class);

        $task->enqueue($this->createStub(\PDO::class));
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
        self::assertInstanceOf(\ByLexus\DurableTask\Tests\Fixture\ConstructorInjectedStepFixture::class, $task->actualStep());
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
        $this->expectExceptionMessage('Task class constructor parameter $name must be a resolvable class or interface type');

        Task::fromQueueRecord($record, $container);
    }
}
