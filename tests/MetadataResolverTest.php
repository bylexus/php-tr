<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Enum\RetryMode;
use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\Metadata\MetadataResolver;
use ByLexus\TaskRunner\Tests\Fixture\ConfiguredTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\DefaultStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\DefaultTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\NegativeRetriesStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\OverrideStepFixture;
use ByLexus\TaskRunner\Tests\Fixture\ZeroCleanupTaskFixture;
use ByLexus\TaskRunner\Tests\Fixture\ZeroMaxRuntimeTaskFixture;
use PHPUnit\Framework\TestCase;

final class MetadataResolverTest extends TestCase
{
    public function testTaskMetadataDefaultsMatchConfiguredLibraryDefaults(): void {
        $resolver = new MetadataResolver();
        $metadata = $resolver->resolveTaskMetadata(DefaultTaskFixture::class);

        self::assertSame(3600, $this->toSeconds($metadata->getMaxRuntime()));
        self::assertSame(0, $this->toSeconds($metadata->getSuccessfulCleanupAfter()));
        self::assertSame(604800, $this->toSeconds($metadata->getUnsuccessfulCleanupAfter()));
    }

    public function testConfiguredTaskMetadataIsResolvedFromAttributes(): void {
        $resolver = new MetadataResolver();
        $metadata = $resolver->resolveTaskMetadata(ConfiguredTaskFixture::class);

        self::assertSame(7200, $this->toSeconds($metadata->getMaxRuntime()));
        self::assertSame(1800, $this->toSeconds($metadata->getSuccessfulCleanupAfter()));
        self::assertSame(172800, $this->toSeconds($metadata->getUnsuccessfulCleanupAfter()));
    }

    public function testStepMetadataUsesStepDefaultsWhenRetryAttributesAreMissing(): void {
        $resolver = new MetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(ConfiguredTaskFixture::class);
        $stepMetadata = $resolver->resolveStepMetadata(DefaultStepFixture::class, $taskMetadata);

        self::assertSame(RetryMode::FAIL, $stepMetadata->getRetryMode());
        self::assertSame(3, $stepMetadata->getRetries());
        self::assertSame(60, $this->toSeconds($stepMetadata->getRetryDelay()));
        self::assertSame(7200, $this->toSeconds($stepMetadata->getMaxRuntime()));
    }

    public function testStepMetadataOverridesStepRetryDefaultsWhereSpecified(): void {
        $resolver = new MetadataResolver();
        $taskMetadata = $resolver->resolveTaskMetadata(ConfiguredTaskFixture::class);
        $stepMetadata = $resolver->resolveStepMetadata(OverrideStepFixture::class, $taskMetadata);

        self::assertSame(RetryMode::SKIP, $stepMetadata->getRetryMode());
        self::assertSame(1, $stepMetadata->getRetries());
        self::assertSame(900, $this->toSeconds($stepMetadata->getRetryDelay()));
        self::assertSame(1800, $this->toSeconds($stepMetadata->getMaxRuntime()));
    }

    public function testNegativeRetriesAreRejected(): void {
        $resolver = new MetadataResolver();

        $this->expectException(ConfigurationException::class);

        $resolver->resolveStepMetadata(NegativeRetriesStepFixture::class);
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

    private function toSeconds(\DateInterval $interval): int {
        $origin = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');

        return $origin->add($interval)->getTimestamp() - $origin->getTimestamp();
    }
}
