<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/ConsoleLogger.php';

// This stands in for an application service that talks to an external system.
final class ExampleUserApi {
    /**
     * @return array<string, scalar>
     */
    public function fetchById(int $userId): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User id must be greater than zero.');
        }

        return [
            'id' => $userId,
            'email' => sprintf('user-%d@example.com', $userId),
            'plan' => $userId % 2 === 0 ? 'pro' : 'free',
            'fetchedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }
}

// This repository writes to a local file so the example stays runnable without a full framework.
final class ExampleUserRepository {
    private string $outputFile;

    public function __construct(?string $outputFile = null) {
        $this->outputFile = $outputFile ?? __DIR__ . '/framework_imported_users.log';
    }

    /**
     * @param array<string, scalar> $profile
     */
    public function save(array $profile): void {
        file_put_contents(
            $this->outputFile,
            json_encode($profile, JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND,
        );
    }
}

// Policies are normal services too; tasks can use them to choose the next workflow step.
final class ExampleImportPolicy {
    /**
     * @param array<string, scalar> $profile
     */
    public function shouldPersist(array $profile): bool {
        return ($profile['email'] ?? null) !== null;
    }
}

final class FrameworkDemoContainer implements ContainerInterface {
    /** @var array<string, object> */
    private array $services;

    public function __construct() {
        // The runner and hydrated task/step instances can all share the same logger service.
        $logger = new ConsoleLogger();

        $this->services = [
            ExampleImportPolicy::class => new ExampleImportPolicy(),
            ExampleUserApi::class => new ExampleUserApi(),
            ExampleUserRepository::class => new ExampleUserRepository(),
            LoggerInterface::class => $logger,
        ];
    }

    public function get(string $id) {
        // A real application would likely delegate to its framework container here.
        if (!$this->has($id)) {
            throw new InvalidArgumentException(sprintf('Unknown service: %s', $id));
        }

        return $this->services[$id];
    }

    public function has(string $id): bool {
        return array_key_exists($id, $this->services);
    }
}
