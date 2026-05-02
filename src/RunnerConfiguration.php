<?php

declare(strict_types=1);

namespace ByLexus\DurableTask;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class RunnerConfiguration
{
    private bool $bootstrapSchemaOnStart;
    private int $notificationWaitTimeoutSeconds;
    private string $runnerId;
    private ?ContainerInterface $container;
    private ?LoggerInterface $logger;

    public function __construct(
        ?string $runnerId = null,
        bool $bootstrapSchemaOnStart = false,
        int $notificationWaitTimeoutSeconds = 30,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->runnerId = $runnerId ?? self::generateRunnerId();
        $this->bootstrapSchemaOnStart = $bootstrapSchemaOnStart;
        $this->notificationWaitTimeoutSeconds = $notificationWaitTimeoutSeconds;
        $this->container = $container;
        $this->logger = $logger;
    }

    public function getRunnerId(): string {
        return $this->runnerId;
    }

    public function shouldBootstrapSchemaOnStart(): bool {
        return $this->bootstrapSchemaOnStart;
    }

    public function getNotificationWaitTimeoutSeconds(): int {
        return $this->notificationWaitTimeoutSeconds;
    }

    public function getContainer(): ?ContainerInterface {
        return $this->container;
    }

    public function getLogger(): ?LoggerInterface {
        return $this->logger;
    }

    private static function generateRunnerId(): string {
        return sprintf('runner-%s', bin2hex(random_bytes(8)));
    }
}
