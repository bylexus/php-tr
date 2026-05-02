<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Support;

use Psr\Container\ContainerInterface;

final class InMemoryContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services) {
        $this->services = $services;
    }

    public function get(string $id): mixed {
        if (!$this->has($id)) {
            throw new \RuntimeException(sprintf('Service not found: %s', $id));
        }

        return $this->services[$id];
    }

    public function has(string $id): bool {
        return array_key_exists($id, $this->services);
    }
}
