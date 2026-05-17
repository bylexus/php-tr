<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Enum\RetryMode;
use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Metadata\TaskMetadata;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\Result\ErrorInfo;
use ByLexus\TaskRunner\Result\StepResult;
use ByLexus\TaskRunner\Runner;
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RunnerTest extends TestCase
{
    public function testRunnerConfigurationReturnsConfiguredLogger(): void {
        $logger = new SpyLogger();
        $configuration = new RunnerConfiguration('runner-test', false, 30, null, $logger);

        self::assertSame($logger, $configuration->getLogger());
    }

    public function testRunnerUsesConfiguredLoggerForRunnerAndQueue(): void {
        $logger = new SpyLogger();
        $runner = new Runner(
            $this->createTaskEnvironment(new RunnerConfiguration('runner-test', false, 30, null, $logger)),
        );

        self::assertSame($logger, $this->readPrivateProperty($runner, 'logger'));
        self::assertSame($logger, $this->readPrivateProperty($this->readPrivateProperty($runner, 'queue'), 'logger'));
        self::assertTrue($logger->hasRecord('debug', 'Runner initialized [runnerId={runnerId}]'));
    }

    public function testRunnerDefaultsToNullLoggerWhenNoLoggerIsConfigured(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));

        self::assertInstanceOf(NullLogger::class, $this->readPrivateProperty($runner, 'logger'));
        self::assertInstanceOf(
            NullLogger::class,
            $this->readPrivateProperty($this->readPrivateProperty($runner, 'queue'), 'logger'),
        );
    }

    public function testRunnerResolvesCleanupIntervalByTerminalStatus(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));
        $metadata = new TaskMetadata(
            new \DateInterval('PT1H'),
            new \DateInterval('PT0S'),
            new \DateInterval('P7D'),
        );

        self::assertSame(
            0,
            $this->toSeconds(
                $this->invokePrivateMethod(
                    $runner,
                    'resolveCleanupAfterIntervalForStatus',
                    $metadata,
                    StepStatus::SUCCEEDED,
                ),
            ),
        );
        self::assertSame(
            604800,
            $this->toSeconds(
                $this->invokePrivateMethod(
                    $runner,
                    'resolveCleanupAfterIntervalForStatus',
                    $metadata,
                    StepStatus::FAILED,
                ),
            ),
        );
        self::assertSame(
            604800,
            $this->toSeconds(
                $this->invokePrivateMethod(
                    $runner,
                    'resolveCleanupAfterIntervalForStatus',
                    $metadata,
                    StepStatus::CANCELLED,
                ),
            ),
        );
    }

    public function testChangesForResultDelaysFailedRetriesUntilRetryDelayHasElapsed(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));
        $task = new class extends Task {
            #[\Override]
            public function nextStep(?\ByLexus\TaskRunner\Step $actStep = null): ?\ByLexus\TaskRunner\Step {
                return null;
            }
        };
        $metadata = new TaskMetadata(
            new \DateInterval('PT1H'),
            new \DateInterval('PT0S'),
            new \DateInterval('P7D'),
        );
        $recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $record = new QueueRecord(
            123,
            $task::class,
            null,
            'queued',
            $recordedAt,
            null,
            null,
            null,
            'failed',
            0,
            null,
            null,
            (object) ['job' => 'demo'],
            null,
            null,
            $recordedAt,
            null,
            null,
            null,
            null,
            false,
            null,
            $recordedAt,
        );
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        /** @var array<string, mixed> $changes */
        $changes = $this->invokePrivateMethod(
            $runner,
            'changesForResult',
            $record,
            $task,
            StepResult::failed(new ErrorInfo(500, 'boom')),
            null,
            $metadata,
            RetryMode::RESTART,
            3,
            new \DateInterval('PT2M'),
        );

        self::assertSame('queued', $changes['task_status']->value);
        self::assertSame('queued', $changes['step_status']->value);
        self::assertSame(1, $changes['step_attempt']);
        self::assertInstanceOf(\DateTimeImmutable::class, $changes['available_at']);
        self::assertGreaterThanOrEqual($before->getTimestamp() + 119, $changes['available_at']->getTimestamp());
        self::assertLessThanOrEqual($before->getTimestamp() + 121, $changes['available_at']->getTimestamp());
    }

    public function testChangesForResultRetriesFailedStepWhenRetryModeSkipHasAttemptsLeft(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));
        $task = new class extends Task {
            #[\Override]
            public function nextStep(?\ByLexus\TaskRunner\Step $actStep = null): ?\ByLexus\TaskRunner\Step {
                return null;
            }
        };
        $metadata = new TaskMetadata(
            new \DateInterval('PT1H'),
            new \DateInterval('PT0S'),
            new \DateInterval('P7D'),
        );
        $record = $this->makeQueueRecord($task::class, 0);

        /** @var array<string, mixed> $changes */
        $changes = $this->invokePrivateMethod(
            $runner,
            'changesForResult',
            $record,
            $task,
            StepResult::failed(new ErrorInfo(500, 'boom')),
            null,
            $metadata,
            RetryMode::SKIP,
            2,
            new \DateInterval('PT0S'),
        );

        self::assertSame('queued', $changes['task_status']->value);
        self::assertSame('queued', $changes['step_status']->value);
        self::assertSame(1, $changes['step_attempt']);
        self::assertArrayNotHasKey('task_finished_at', $changes);
    }

    public function testChangesForResultSkipsToNextStepWhenRetryModeSkipExhaustsRetries(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));
        $task = new class extends Task {
            #[\Override]
            public function nextStep(?\ByLexus\TaskRunner\Step $actStep = null): ?\ByLexus\TaskRunner\Step {
                return null;
            }
        };
        $metadata = new TaskMetadata(
            new \DateInterval('PT1H'),
            new \DateInterval('PT0S'),
            new \DateInterval('P7D'),
        );
        $record = $this->makeQueueRecord($task::class, 2);
        $nextStep = new class implements \ByLexus\TaskRunner\Step {
            #[\Override]
            public function execute(Task $task): StepResult {
                return StepResult::succeeded();
            }
        };

        /** @var array<string, mixed> $changes */
        $changes = $this->invokePrivateMethod(
            $runner,
            'changesForResult',
            $record,
            $task,
            StepResult::failed(new ErrorInfo(500, 'boom')),
            $nextStep,
            $metadata,
            RetryMode::SKIP,
            2,
            new \DateInterval('PT0S'),
        );

        self::assertSame('queued', $changes['task_status']->value);
        self::assertSame('queued', $changes['step_status']->value);
        self::assertSame(0, $changes['step_attempt']);
        self::assertSame($nextStep::class, $changes['step_class']);
        self::assertArrayNotHasKey('task_finished_at', $changes);
    }

    public function testChangesForResultSucceedsWithSkippedStatusWhenSkipExhaustsRetriesAndNoNextStep(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));
        $task = new class extends Task {
            #[\Override]
            public function nextStep(?\ByLexus\TaskRunner\Step $actStep = null): ?\ByLexus\TaskRunner\Step {
                return null;
            }
        };
        $metadata = new TaskMetadata(
            new \DateInterval('PT1H'),
            new \DateInterval('PT0S'),
            new \DateInterval('P7D'),
        );
        $record = $this->makeQueueRecord($task::class, 2);

        /** @var array<string, mixed> $changes */
        $changes = $this->invokePrivateMethod(
            $runner,
            'changesForResult',
            $record,
            $task,
            StepResult::failed(new ErrorInfo(500, 'boom')),
            null,
            $metadata,
            RetryMode::SKIP,
            2,
            new \DateInterval('PT0S'),
        );

        self::assertSame('succeeded', $changes['task_status']->value);
        self::assertSame('skipped', $changes['step_status']->value);
        self::assertInstanceOf(\DateTimeImmutable::class, $changes['task_finished_at']);
        self::assertInstanceOf(\DateTimeImmutable::class, $changes['cleanup_at']);
    }

    private function makeQueueRecord(string $taskClass, int $stepAttempt): QueueRecord {
        $recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new QueueRecord(
            123,
            $taskClass,
            null,
            'queued',
            $recordedAt,
            null,
            null,
            null,
            'failed',
            $stepAttempt,
            null,
            null,
            (object) ['job' => 'demo'],
            null,
            null,
            $recordedAt,
            null,
            null,
            null,
            null,
            false,
            null,
            $recordedAt,
        );
    }

    public function testRunnerThrottlesExpiredQueueCleanupToEveryTenSeconds(): void {
        $runner = new Runner($this->createTaskEnvironment(new RunnerConfiguration('runner-test')));

        self::assertTrue(
            $this->invokePrivateMethod($runner, 'shouldCleanupExpiredQueueRecords', 1_000),
        );

        $this->writePrivateProperty($runner, 'lastExpiredQueueCleanupTimestamp', 1_000);

        self::assertFalse(
            $this->invokePrivateMethod($runner, 'shouldCleanupExpiredQueueRecords', 1_009),
        );
        self::assertTrue(
            $this->invokePrivateMethod($runner, 'shouldCleanupExpiredQueueRecords', 1_010),
        );
    }

    private function readPrivateProperty(object $object, string $propertyName): mixed {
        $reflection = new \ReflectionProperty($object, $propertyName);

        return $reflection->getValue($object);
    }

    private function writePrivateProperty(object $object, string $propertyName, mixed $value): void {
        $reflection = new \ReflectionProperty($object, $propertyName);
        $reflection->setValue($object, $value);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed {
        $reflection = new \ReflectionMethod($object, $methodName);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function createTaskEnvironment(?RunnerConfiguration $runnerConfiguration = null): TaskEnvironment {
        return new TaskEnvironment($this->createStub(\PDO::class), runnerConfiguration: $runnerConfiguration);
    }

    private function toSeconds(\DateInterval $interval): int {
        $origin = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');

        return $origin->add($interval)->getTimestamp() - $origin->getTimestamp();
    }
}
