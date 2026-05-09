<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Metadata;

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Attribute\MaxRuntime;
use ByLexus\DurableTask\Attribute\Retries;
use ByLexus\DurableTask\Attribute\RetryMode as RetryModeAttribute;
use ByLexus\DurableTask\Enum\RetryMode;
use ByLexus\DurableTask\Exception\ConfigurationException;

/**
 * Resolves task and step metadata.
 *
 * Uses reflection to read attributes and cache retry, runtime, and cleanup metadata for workflow classes.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class MetadataResolver {
    /** @var array<class-string, TaskMetadata> */
    private array $taskCache = [];

    /**
     * @var array<class-string, array{
     *     retryMode: ?RetryMode,
     *     retries: ?int,
     *     retryDelay: ?\DateInterval,
     *     maxRuntime: ?\DateInterval
     * }>
     */
    private array $stepAttributeCache = [];

    public function resolveTaskMetadata(string $taskClass): TaskMetadata {
        if (isset($this->taskCache[$taskClass])) {
            return $this->taskCache[$taskClass];
        }

        $reflection = $this->reflectClass($taskClass);
        $defaultRetryMode = new RetryModeAttribute(RetryModeAttribute::DEFAULT_MODE);
        $defaultRetries = Retries::createDefault();
        $defaultMaxRuntime = new MaxRuntime(new \DateInterval(MaxRuntime::DEFAULT_SPEC));
        $defaultCleanupAfter = CleanupAfter::createDefault();
        $retrySettings = $this->readRetries($reflection) ?? $defaultRetries;

        $retryMode = $this->readRetryMode($reflection) ?? $defaultRetryMode->mode;
        $retries = $retrySettings->count;
        $retryDelay = clone $retrySettings->delay;
        $maxRuntime = $this->readMaxRuntime($reflection) ?? clone $defaultMaxRuntime->interval;
        $cleanupAfter = $this->readCleanupAfter($reflection) ?? $defaultCleanupAfter;

        $metadata = new TaskMetadata(
            $retryMode,
            $retries,
            $retryDelay,
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
            $attributeValues['retryMode'] ?? $taskMetadata->getRetryMode(),
            $attributeValues['retries'] ?? $taskMetadata->getRetries(),
            $attributeValues['retryDelay'] ?? $taskMetadata->getRetryDelay(),
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

        if ($reflection->getAttributes(CleanupAfter::class) !== []) {
            throw new ConfigurationException(sprintf('CleanupAfter is only allowed on task classes: %s', $stepClass));
        }

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
        $defaultRetryMode = new RetryModeAttribute(RetryModeAttribute::DEFAULT_MODE);
        $defaultRetries = Retries::createDefault();
        $defaultMaxRuntime = new MaxRuntime(new \DateInterval(MaxRuntime::DEFAULT_SPEC));
        $defaultCleanupAfter = CleanupAfter::createDefault();

        return new TaskMetadata(
            $defaultRetryMode->mode,
            $defaultRetries->count,
            $defaultRetries->delay,
            $defaultMaxRuntime->interval,
            $defaultCleanupAfter->successful,
            $defaultCleanupAfter->unsuccessful,
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
