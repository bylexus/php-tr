<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Enum\RetryMode;
use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Metadata\TaskMetadata;
use ByLexus\DurableTask\Queue\QueueRecord;
use ByLexus\DurableTask\Queue\PostgresQueue;
use ByLexus\DurableTask\Result\ErrorInfo;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Runner;
use ByLexus\DurableTask\RunnerConfiguration;
use ByLexus\DurableTask\Task;
use ByLexus\DurableTask\Tests\Support\SpyLogger;
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
            $this->createStub(\PDO::class),
            null,
            new RunnerConfiguration('runner-test', false, 30, null, $logger),
        );

        self::assertSame($logger, $this->readPrivateProperty($runner, 'logger'));
        self::assertSame($logger, $this->readPrivateProperty($this->readPrivateProperty($runner, 'queue'), 'logger'));
        self::assertTrue($logger->hasRecord('debug', 'Runner initialized.'));
    }

    public function testRunnerDefaultsToNullLoggerWhenNoLoggerIsConfigured(): void {
        $runner = new Runner(
            $this->createStub(\PDO::class),
            null,
            new RunnerConfiguration('runner-test'),
        );

        self::assertInstanceOf(NullLogger::class, $this->readPrivateProperty($runner, 'logger'));
        self::assertInstanceOf(
            NullLogger::class,
            $this->readPrivateProperty($this->readPrivateProperty($runner, 'queue'), 'logger'),
        );
    }

    public function testRunnerResolvesCleanupIntervalByTerminalStatus(): void {
        $runner = new Runner(
            $this->createStub(\PDO::class),
            null,
            new RunnerConfiguration('runner-test'),
        );
        $metadata = new TaskMetadata(
            RetryMode::FAIL,
            3,
            new \DateInterval('PT1M'),
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
        $runner = new Runner(
            $this->createStub(\PDO::class),
            null,
            new RunnerConfiguration('runner-test'),
        );
        $task = new class extends Task {
            public function nextStep(?\ByLexus\DurableTask\Step $actStep = null): ?\ByLexus\DurableTask\Step {
                return null;
            }
        };
        $metadata = new TaskMetadata(
            RetryMode::RESTART,
            3,
            new \DateInterval('PT2M'),
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
            0,
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

    private function readPrivateProperty(object $object, string $propertyName): mixed {
        $reflection = new \ReflectionProperty($object, $propertyName);

        return $reflection->getValue($object);
    }

    private function invokePrivateMethod(object $object, string $methodName, mixed ...$arguments): mixed {
        $reflection = new \ReflectionMethod($object, $methodName);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function toSeconds(\DateInterval $interval): int {
        $origin = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');

        return $origin->add($interval)->getTimestamp() - $origin->getTimestamp();
    }
}
