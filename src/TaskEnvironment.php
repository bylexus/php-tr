<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner;

use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Metadata\MetadataResolver;
use ByLexus\TaskRunner\Queue\DatabaseQueue;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\Queue\QueueRecord;
use ByLexus\TaskRunner\Queue\SchemaManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps a queue connection context.
 *
 * Stores the shared PDO connection and queue configuration so application code can reuse them across
 * enqueueing, runner creation, and schema management.
 *
 * This file is part of bylexus/php-tr
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class TaskEnvironment {
    private \PDO $connection;
    private QueueConfiguration $queueConfiguration;
    private ?ContainerInterface $container;
    private ?LoggerInterface $logger;
    private RunnerConfiguration $runnerConfiguration;
    private MetadataResolver $metadataResolver;
    private ?DatabaseQueue $databaseQueue = null;
    private ?SchemaManager $schemaManager = null;

    public function __construct(
        \PDO $connection,
        ?QueueConfiguration $queueConfiguration = null,
        ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
        ?RunnerConfiguration $runnerConfiguration = null,
        ?MetadataResolver $metadataResolver = null,
    ) {
        $resolvedContainer = $container ?? $runnerConfiguration?->getContainer();
        $resolvedLogger = $logger ?? $runnerConfiguration?->getLogger();

        $this->connection = $connection;
        $this->queueConfiguration = $queueConfiguration ?? new QueueConfiguration();
        $this->container = $resolvedContainer;
        $this->logger = $resolvedLogger;
        $this->runnerConfiguration = $this->resolveRunnerConfiguration($runnerConfiguration);
        $this->metadataResolver = $metadataResolver ?? new MetadataResolver();
    }

    public function getConnection(): \PDO {
        return $this->connection;
    }

    public function getQueueConfiguration(): QueueConfiguration {
        return $this->queueConfiguration;
    }

    public function getMetadataResolver(): MetadataResolver {
        return $this->metadataResolver;
    }

    /**
     * Returns the lazily-created DatabaseQueue bound to this TaskEnvironment.
     */
    public function getDatabaseQueue(): DatabaseQueue {
        if ($this->databaseQueue === null) {
            $this->databaseQueue = new DatabaseQueue($this->connection, $this->queueConfiguration, $this->logger);
        }

        return $this->databaseQueue;
    }

    /**
     * Returns the stored runner configuration for TaskEnvironment-based runner creation.
     */
    public function getRunnerConfiguration(): RunnerConfiguration {
        return $this->runnerConfiguration;
    }

    public function enqueue(Task $task, int $priority = Task::PRIO_NORMAL): QueueRecord {
        return $task->enqueue($this, $priority);
    }

    public function getTask(int $taskId): Task {
        $queue = $this->getDatabaseQueue();

        return $this->hydrateTask($queue->get($taskId), $queue);
    }

    /**
     * @return list<Task>
     */
    public function getTasks(?TaskStatus $taskStatus = null): array {
        $queue = $this->getDatabaseQueue();
        $tasks = [];

        foreach ($queue->find($taskStatus) as $record) {
            $tasks[] = $this->hydrateTask($record, $queue);
        }

        return $tasks;
    }

    public function createRunner(): Runner {
        return new Runner($this);
    }

    /**
     * Returns the lazily-created SchemaManager bound to this TaskEnvironment.
     */
    public function getSchemaManager(): SchemaManager {
        if ($this->schemaManager === null) {
            $this->schemaManager = new SchemaManager($this);
        }

        return $this->schemaManager;
    }

    private function hydrateTask(QueueRecord $record, DatabaseQueue $queue): Task {
        return Task::fromQueueRecord(
            $record,
            $this->container,
            $this->logger,
            $queue->getAttachmentBlobStore(),
            $queue,
        );
    }

    private function resolveRunnerConfiguration(?RunnerConfiguration $runnerConfiguration = null): RunnerConfiguration {
        if ($runnerConfiguration === null) {
            return new RunnerConfiguration(container: $this->container, logger: $this->logger);
        }

        if ($runnerConfiguration->getContainer() !== null && $runnerConfiguration->getLogger() !== null) {
            return $runnerConfiguration;
        }

        return new RunnerConfiguration(
            $runnerConfiguration->getRunnerId(),
            $runnerConfiguration->shouldBootstrapSchemaOnStart(),
            $runnerConfiguration->getNotificationWaitTimeoutSeconds(),
            $runnerConfiguration->getContainer() ?? $this->container,
            $runnerConfiguration->getLogger() ?? $this->logger,
        );
    }
}
