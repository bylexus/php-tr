<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Metadata;

use ByLexus\TaskRunner\Attribute\CleanupAfter;
use ByLexus\TaskRunner\Attribute\MaxRuntime;
use ByLexus\TaskRunner\Attribute\Retries;
use ByLexus\TaskRunner\Attribute\RetryMode as RetryModeAttribute;
use ByLexus\TaskRunner\Enum\RetryMode;
use ByLexus\TaskRunner\Exception\ConfigurationException;

/**
 * Resolves task and step metadata.
 *
 * Uses reflection to read attributes and cache retry, runtime, and cleanup metadata for workflow classes.
 *
 * This file is part of bylexus/php-tr
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class MetadataResolver {
    /** @var array<class-string, TaskMetadata> */
    private array $taskCache = [];

    private RetryModeAttribute $defaultRetryMode;
    private Retries $defaultRetries;
    private MaxRuntime $defaultMaxRuntime;
    private CleanupAfter $defaultCleanupAfter;

    /**
     * @var array<class-string, array{
     *     retryMode: ?RetryMode,
     *     retries: ?int,
     *     retryDelay: ?\DateInterval,
     *     maxRuntime: ?\DateInterval
     * }>
     */
    private array $stepAttributeCache = [];

    public function __construct() {
        $this->defaultRetryMode = RetryModeAttribute::createDefault();
        $this->defaultRetries = Retries::createDefault();
        $this->defaultMaxRuntime = MaxRuntime::createDefault();
        $this->defaultCleanupAfter = CleanupAfter::createDefault();
    }

    public function resolveTaskMetadata(string $taskClass): TaskMetadata {
        if (isset($this->taskCache[$taskClass])) {
            return $this->taskCache[$taskClass];
        }

        $reflection = $this->reflectClass($taskClass);
        $maxRuntime = $this->readMaxRuntime($reflection) ?? clone $this->defaultMaxRuntime->interval;
        $cleanupAfter = $this->readCleanupAfter($reflection) ?? $this->defaultCleanupAfter;

        $metadata = new TaskMetadata(
            $maxRuntime,
            $cleanupAfter->successful,
            $cleanupAfter->unsuccessful,
        );
        $this->taskCache[$taskClass] = $metadata;

        return $metadata;
    }

    public function resolveStepMetadata(string $stepClass, ?TaskMetadata $taskMetadata = null): StepMetadata {
        $taskMetadata ??= $this->createDefaultTaskMetadata();

        $attributeValues = $this->resolveStepAttributeValues($stepClass);

        return new StepMetadata(
            $attributeValues['retryMode'] ?? $this->defaultRetryMode->mode,
            $attributeValues['retries'] ?? $this->defaultRetries->count,
            $attributeValues['retryDelay'] ?? $this->defaultRetries->delay,
            $attributeValues['maxRuntime'] ?? $taskMetadata->getMaxRuntime(),
        );
    }

    /**
     * @return array{
     *     retryMode: ?RetryMode,
     *     retries: ?int,
     *     retryDelay: ?\DateInterval,
     *     maxRuntime: ?\DateInterval
     * }
     */
    private function resolveStepAttributeValues(string $stepClass): array {
        if (isset($this->stepAttributeCache[$stepClass])) {
            return $this->stepAttributeCache[$stepClass];
        }

        $reflection = $this->reflectClass($stepClass);

        $retrySettings = $this->readRetries($reflection);

        $attributeValues = [
            'retryMode' => $this->readRetryMode($reflection),
            'retries' => $retrySettings?->count,
            'retryDelay' => $retrySettings === null ? null : clone $retrySettings->delay,
            'maxRuntime' => $this->readMaxRuntime($reflection),
        ];

        $this->stepAttributeCache[$stepClass] = $attributeValues;

        return $attributeValues;
    }

    private function reflectClass(string $className): \ReflectionClass {
        if (!class_exists($className)) {
            throw new ConfigurationException(sprintf('Configured class does not exist: %s', $className));
        }

        return new \ReflectionClass($className);
    }

    private function readRetryMode(\ReflectionClass $reflection): ?RetryMode {
        $attributes = $reflection->getAttributes(RetryModeAttribute::class);

        if ($attributes === []) {
            return null;
        }

        /** @var RetryModeAttribute $attribute */
        $attribute = $attributes[0]->newInstance();

        return $attribute->mode;
    }

    private function readRetries(\ReflectionClass $reflection): ?Retries {
        $attributes = $reflection->getAttributes(Retries::class);

        if ($attributes === []) {
            return null;
        }

        /** @var Retries $attribute */
        $attribute = $attributes[0]->newInstance();

        if ($attribute->count < 0) {
            throw new ConfigurationException(
                sprintf('Retries must not be negative on class %s', $reflection->getName()),
            );
        }

        $this->assertNonNegativeInterval(
            $attribute->delay,
            sprintf('Retry delay must not be negative on class %s', $reflection->getName()),
        );

        return $attribute;
    }

    private function readMaxRuntime(\ReflectionClass $reflection): ?\DateInterval {
        $attributes = $reflection->getAttributes(MaxRuntime::class);

        if ($attributes === []) {
            return null;
        }

        /** @var MaxRuntime $attribute */
        $attribute = $attributes[0]->newInstance();

        $this->assertPositiveInterval(
            $attribute->interval,
            sprintf('MaxRuntime must be greater than zero on class %s', $reflection->getName()),
        );

        return clone $attribute->interval;
    }

    private function readCleanupAfter(\ReflectionClass $reflection): ?CleanupAfter {
        $attributes = $reflection->getAttributes(CleanupAfter::class);

        if ($attributes === []) {
            return null;
        }

        /** @var CleanupAfter $attribute */
        $attribute = $attributes[0]->newInstance();

        $this->assertNonNegativeInterval(
            $attribute->successful,
            sprintf('CleanupAfter successful interval must not be negative on class %s', $reflection->getName()),
        );

        $this->assertNonNegativeInterval(
            $attribute->unsuccessful,
            sprintf('CleanupAfter unsuccessful interval must not be negative on class %s', $reflection->getName()),
        );

        return new CleanupAfter(clone $attribute->successful, clone $attribute->unsuccessful);
    }

    private function createDefaultTaskMetadata(): TaskMetadata {
        return new TaskMetadata(
            $this->defaultMaxRuntime->interval,
            $this->defaultCleanupAfter->successful,
            $this->defaultCleanupAfter->unsuccessful,
        );
    }

    private function assertPositiveInterval(\DateInterval $interval, string $message): void {
        $origin = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');
        $target = $origin->add($interval);

        if ($target <= $origin) {
            throw new ConfigurationException($message);
        }
    }

    private function assertNonNegativeInterval(\DateInterval $interval, string $message): void {
        $origin = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');
        $target = $origin->add($interval);

        if ($target < $origin) {
            throw new ConfigurationException($message);
        }
    }
}
