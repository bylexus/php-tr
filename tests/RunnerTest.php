<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

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

    private function readPrivateProperty(object $object, string $propertyName): mixed {
        $reflection = new \ReflectionProperty($object, $propertyName);

        return $reflection->getValue($object);
    }
}
