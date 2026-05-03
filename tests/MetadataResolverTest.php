<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Enum\RetryMode;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\Metadata\MetadataResolver;
use ByLexus\DurableTask\Tests\Fixture\ConfiguredTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\DefaultStepFixture;
use ByLexus\DurableTask\Tests\Fixture\DefaultTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\InvalidCleanupOnStepFixture;
use ByLexus\DurableTask\Tests\Fixture\NegativeRetriesTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\OverrideStepFixture;
use ByLexus\DurableTask\Tests\Fixture\ZeroCleanupTaskFixture;
use ByLexus\DurableTask\Tests\Fixture\ZeroMaxRuntimeTaskFixture;
use PHPUnit\Framework\TestCase;

final class MetadataResolverTest extends TestCase
{
    public function testTaskMetadataDefaultsMatchConfiguredLibraryDefaults(): void {
        $resolver = new MetadataResolver();
        $metadata = $resolver->resolveTaskMetadata(DefaultTaskFixture::class);

        self::assertSame(RetryMode::FAIL, $metadata->getRetryMode());
        self::assertSame(3, $metadata->getRetries());
        self::assertSame(3600, $this->toSeconds($metadata->getMaxRuntime()));
        self::assertSame(0, $this->toSeconds($metadata->getSuccessfulCleanupAfter()));
        self::assertSame(604800, $this->toSeconds($metadata->getUnsuccessfulCleanupAfter()));
    }

    public function testConfiguredTaskMetadataIsResolvedFromAttributes(): void {
        $resolver = new MetadataResolver();
        $metadata = $resolver->resolveTaskMetadata(ConfiguredTaskFixture::class);

        self::assertSame(RetryMode::RESTART, $metadata->getRetryMode());
        self::assertSame(5, $metadata->getRetries());
        self::assertSame(7200, $this->toSeconds($metadata->getMaxRuntime()));
        self::assertSame(1800, $this->toSeconds($metadata->getSuccessfulCleanupAfter()));
        self::assertSame(172800, $this->toSeconds($metadata->getUnsuccessfulCleanupAfter()));
    }

    public function testStepMetadataUsesTaskDefaultsWhenStepAttributesAreMissing(): void {
        $resolver = new MetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(ConfiguredTaskFixture::class);
        $stepMetadata = $resolver->resolveStepMetadata(DefaultStepFixture::class, $taskMetadata);

        self::assertSame(RetryMode::RESTART, $stepMetadata->getRetryMode());
        self::assertSame(5, $stepMetadata->getRetries());
        self::assertSame(7200, $this->toSeconds($stepMetadata->getMaxRuntime()));
    }

    public function testStepMetadataOverridesTaskDefaultsWhereSpecified(): void {
        $resolver = new MetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(ConfiguredTaskFixture::class);
        $stepMetadata = $resolver->resolveStepMetadata(OverrideStepFixture::class, $taskMetadata);

        self::assertSame(RetryMode::SKIP, $stepMetadata->getRetryMode());
        self::assertSame(1, $stepMetadata->getRetries());
        self::assertSame(1800, $this->toSeconds($stepMetadata->getMaxRuntime()));
    }

    public function testNegativeRetriesAreRejected(): void {
        $resolver = new MetadataResolver();

        $this->expectException(ConfigurationException::class);

        $resolver->resolveTaskMetadata(NegativeRetriesTaskFixture::class);
    }

    public function testZeroCleanupIntervalIsAllowed(): void {
        $resolver = new MetadataResolver();
        $metadata = $resolver->resolveTaskMetadata(ZeroCleanupTaskFixture::class);

        self::assertSame(0, $this->toSeconds($metadata->getSuccessfulCleanupAfter()));
        self::assertSame(86400, $this->toSeconds($metadata->getUnsuccessfulCleanupAfter()));
    }

    public function testZeroMaxRuntimeIsRejected(): void {
        $resolver = new MetadataResolver();

        $this->expectException(ConfigurationException::class);

        $resolver->resolveTaskMetadata(ZeroMaxRuntimeTaskFixture::class);
    }

    public function testCleanupAfterOnStepClassIsRejected(): void {
        $resolver = new MetadataResolver();

        $this->expectException(ConfigurationException::class);

        $resolver->resolveStepMetadata(InvalidCleanupOnStepFixture::class);
    }

    private function toSeconds(\DateInterval $interval): int {
        $origin = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');

        return $origin->add($interval)->getTimestamp() - $origin->getTimestamp();
    }
}
