<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Runtime;

use ByLexus\DurableTask\Exception\ConfigurationException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ClassInstantiator {
    public static function instantiate(
        string $className,
        string $expectedBaseClass,
        string $label,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
    ): object {
        if (!class_exists($className)) {
            throw new ConfigurationException(sprintf('%s class does not exist: %s', $label, $className));
        }

        $reflectionClass = new \ReflectionClass($className);

        if (!$reflectionClass->isInstantiable()) {
            throw new ConfigurationException(sprintf('%s class is not instantiable: %s', $label, $className));
        }

        $constructor = $reflectionClass->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $instance = $reflectionClass->newInstance();

            self::assertExpectedType($instance, $expectedBaseClass, $label, $className);

            return $instance;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                throw new ConfigurationException(sprintf(
                    '%s class constructor parameter $%s must be a resolvable class or interface type: %s',
                    $label,
                    $parameter->getName(),
                    $className,
                ));
            }

            $serviceId = $type->getName();

            if ($serviceId === LoggerInterface::class) {
                $arguments[] = self::resolveLoggerArgument($container, $logger);

                continue;
            }

            if ($container === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                throw new ConfigurationException(sprintf(
                    '%s class requires a configured service container for constructor injection: %s',
                    $label,
                    $className,
                ));
            }

            if (!$container->has($serviceId)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }

                throw new ConfigurationException(sprintf(
                    '%s class constructor parameter $%s could not be resolved from the service container: %s (%s)',
                    $label,
                    $parameter->getName(),
                    $className,
                    $serviceId,
                ));
            }

            try {
                $arguments[] = $container->get($serviceId);
            } catch (\Throwable $throwable) {
                throw new ConfigurationException(sprintf(
                    '%s class constructor parameter $%s could not be resolved from the service container: %s (%s)',
                    $label,
                    $parameter->getName(),
                    $className,
                    $serviceId,
                ), 0, $throwable);
            }
        }

        $instance = $reflectionClass->newInstanceArgs($arguments);

        self::assertExpectedType($instance, $expectedBaseClass, $label, $className);

        return $instance;
    }

    private static function resolveLoggerArgument(
        ?ContainerInterface $container,
        ?LoggerInterface $logger,
    ): LoggerInterface {
        if ($container !== null && $container->has(LoggerInterface::class)) {
            try {
                $resolvedLogger = $container->get(LoggerInterface::class);

                if ($resolvedLogger instanceof LoggerInterface) {
                    return $resolvedLogger;
                }
            } catch (\Throwable) {
                return $logger ?? new NullLogger();
            }
        }

        return $logger ?? new NullLogger();
    }

    private static function assertExpectedType(
        object $instance,
        string $expectedBaseClass,
        string $label,
        string $className,
    ): void {
        if (!$instance instanceof $expectedBaseClass) {
            throw new ConfigurationException(sprintf(
                'Configured %s class must extend %s: %s',
                strtolower($label),
                $expectedBaseClass,
                $className,
            ));
        }
    }
}
