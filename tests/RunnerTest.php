<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Enum\RetryMode;
use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Metadata\TaskMetadata;
use ByLexus\DurableTask\Queue\PostgresQueue;
use ByLexus\DurableTask\Runner;
use ByLexus\DurableTask\RunnerConfiguration;
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
            new \DateInterval('PT1H'),
            new \DateInterval('PT0S'),
            new \DateInterval('P7D'),
        );

        self::assertSame(
            0,
            $this->toSeconds($this->invokePrivateMethod($runner, 'resolveCleanupAfterIntervalForStatus', $metadata, StepStatus::SUCCEEDED)),
        );
        self::assertSame(
            604800,
            $this->toSeconds($this->invokePrivateMethod($runner, 'resolveCleanupAfterIntervalForStatus', $metadata, StepStatus::FAILED)),
        );
        self::assertSame(
            604800,
            $this->toSeconds($this->invokePrivateMethod($runner, 'resolveCleanupAfterIntervalForStatus', $metadata, StepStatus::CANCELLED)),
        );
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
