<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Runtime;

use ByLexus\DurableTask\Exception\ConfigurationException;
use Psr\Container\ContainerInterface;

final class ClassInstantiator {
    public static function instantiate(
        string $className,
        string $expectedBaseClass,
        string $label,
        ?ContainerInterface $container = null,
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

        if ($container === null) {
            throw new ConfigurationException(sprintf(
                '%s class requires a configured service container for constructor injection: %s',
                $label,
                $className,
            ));
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new ConfigurationException(sprintf(
                    '%s class constructor parameter $%s must be a resolvable class or interface type: %s',
                    $label,
                    $parameter->getName(),
                    $className,
                ));
            }

            $serviceId = $type->getName();

            if (!$container->has($serviceId)) {
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
