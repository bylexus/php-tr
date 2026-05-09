<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Queue;

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Enum\TaskStatus;
use ByLexus\DurableTask\Exception\ConfigurationException;
use ByLexus\DurableTask\PayloadNormalizer;
use ByLexus\DurableTask\Exception\QueueException;
use ByLexus\DurableTask\Exception\SerializationException;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Persists workflow records in PostgreSQL.
 *
 * Implements queue storage operations for durable tasks, including inserts, updates, and record retrieval.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class PostgresQueue {
    /** @var list<string> */
    private const UPDATABLE_COLUMNS = [
        'task_status',
        'task_attempt',
        'task_started_at',
        'task_finished_at',
        'cleanup_at',
        'step_class',
        'step_status',
        'step_attempt',
        'step_started_at',
        'step_finished_at',
        'payload_json',
        'result_json',
        'error_json',
        'available_at',
        'claimed_at',
        'claimed_by',
        'last_error_code',
        'last_error_message',
        'cancel_requested',
        'cancel_reason',
        'updated_at',
    ];

    private \PDO $connection;
    private QueueConfiguration $configuration;
    private LoggerInterface $logger;
    private AttachmentBlobStore $attachmentBlobStore;

    public function __construct(
        \PDO $connection,
        ?QueueConfiguration $configuration = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->connection = $connection;
        $this->configuration = $configuration ?? new QueueConfiguration();
        $this->logger = $logger ?? new NullLogger();
        $this->attachmentBlobStore = new AttachmentBlobStore($this->connection, $this->configuration);
    }

    public function getAttachmentBlobStore(): AttachmentBlobStore {
        return $this->attachmentBlobStore;
    }

    public function enqueue(Task $task, Step $firstStep, int $priority = Task::PRIO_NORMAL): QueueRecord {
        $now = $this->currentTimestamp();
        $startedTransaction = false;

        $this->logger->info('Queue enqueue started.', [
            'taskClass' => $task::class,
            'stepClass' => $firstStep::class,
            'priority' => $priority,
            'taskStatus' => TaskStatus::QUEUED->value,
            'stepStatus' => StepStatus::QUEUED->value,
        ]);

        if (!$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $statement = $this->connection->prepare(
                sprintf(
                    <<<'SQL'
INSERT INTO %s (
    task_class,
    step_class,
    task_status,
    task_attempt,
    priority,
    task_created_at,
    task_started_at,
    task_finished_at,
    cleanup_at,
    step_status,
    step_attempt,
    step_started_at,
    step_finished_at,
    payload_json,
    result_json,
    error_json,
    available_at,
    claimed_at,
    claimed_by,
    last_error_code,
    last_error_message,
    cancel_requested,
    cancel_reason,
    updated_at
)
VALUES (
    :task_class,
    :step_class,
    :task_status,
    :task_attempt,
    :priority,
    :task_created_at,
    :task_started_at,
    :task_finished_at,
    :cleanup_at,
    :step_status,
    :step_attempt,
    :step_started_at,
    :step_finished_at,
    NULL,
    :result_json,
    :error_json,
    :available_at,
    :claimed_at,
    :claimed_by,
    :last_error_code,
    :last_error_message,
    FALSE,
    :cancel_reason,
    :updated_at
)
RETURNING *
SQL,
                    $this->quotedTableName(),
                ),
            );
            $statement->execute([
                'task_class' => $task::class,
                'step_class' => $firstStep::class,
                'task_status' => TaskStatus::QUEUED->value,
                'task_attempt' => 0,
                'priority' => $priority,
                'task_created_at' => $this->formatDateTime($now),
                'task_started_at' => null,
                'task_finished_at' => null,
                'cleanup_at' => null,
                'step_status' => StepStatus::QUEUED->value,
                'step_attempt' => 0,
                'step_started_at' => null,
                'step_finished_at' => null,
                'result_json' => null,
                'error_json' => null,
                'available_at' => $this->formatDateTime($now),
                'claimed_at' => null,
                'claimed_by' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'cancel_reason' => null,
                'updated_at' => $this->formatDateTime($now),
            ]);

            $record = $this->fetchRecordFromStatement($statement, 'Failed to read enqueued queue record.');
            $record = $this->update((int) $record->taskId, ['payload_json' => $task->getStoredPayload()], false);
            $this->emitNotification((string) $record->taskId);

            if ($startedTransaction) {
                $this->connection->commit();
            }
        } catch (\Throwable $throwable) {
            if ($startedTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error('Queue enqueue failed.', [
                'taskClass' => $task::class,
                'stepClass' => $firstStep::class,
                'priority' => $priority,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ]);

            throw $throwable;
        }

        $this->logger->info('Queue enqueue completed.', [
            'taskId' => $record->taskId,
            'taskClass' => $record->taskClass,
            'stepClass' => $record->stepClass,
            'priority' => $record->priority,
            'taskStatus' => $record->taskStatus,
            'stepStatus' => $record->stepStatus,
            'availableAt' => $record->availableAt->format(DATE_ATOM),
        ]);

        return $record;
    }

    public function claim(string $runnerId): ?QueueRecord {
        if ($this->connection->inTransaction()) {
            $this->logger->error('Queue claim called inside active transaction.', [
                'runnerId' => $runnerId,
            ]);

            throw new QueueException('PostgresQueue::claim() requires no active transaction.');
        }

        $this->connection->beginTransaction();

        try {
            $select = $this->connection->prepare(
                sprintf(
                    <<<'SQL'
SELECT *
FROM %s
WHERE task_status = :task_status
  AND step_status = :step_status
  AND available_at <= CURRENT_TIMESTAMP
ORDER BY priority ASC, available_at ASC, task_created_at ASC
FOR UPDATE SKIP LOCKED
LIMIT 1
SQL,
                    $this->quotedTableName(),
                ),
            );
            $select->execute([
                'task_status' => TaskStatus::QUEUED->value,
                'step_status' => StepStatus::QUEUED->value,
            ]);

            $row = $select->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $this->connection->commit();

                $this->logger->debug('Queue claim found no available task.', [
                    'runnerId' => $runnerId,
                ]);

                return null;
            }

            $record = QueueRecord::fromDatabaseRow($row);
            $now = $this->currentTimestamp();

            $update = $this->connection->prepare(
                sprintf(
                    <<<'SQL'
UPDATE %s
SET task_status = :task_status,
    step_status = :step_status,
    claimed_at = :claimed_at,
    claimed_by = :claimed_by,
    task_started_at = COALESCE(task_started_at, :task_started_at),
    step_started_at = COALESCE(step_started_at, :step_started_at),
    updated_at = :updated_at
WHERE task_id = :task_id
RETURNING *
SQL,
                    $this->quotedTableName(),
                ),
            );
            $update->execute([
                'task_status' => TaskStatus::RUNNING->value,
                'step_status' => StepStatus::RUNNING->value,
                'claimed_at' => $this->formatDateTime($now),
                'claimed_by' => $runnerId,
                'task_started_at' => $this->formatDateTime($now),
                'step_started_at' => $this->formatDateTime($now),
                'updated_at' => $this->formatDateTime($now),
                'task_id' => $record->taskId,
            ]);

            $claimedRecord = $this->fetchRecordFromStatement($update, 'Failed to read claimed queue record.');

            $this->connection->commit();

            $this->logger->info('Queue claim succeeded.', [
                'runnerId' => $runnerId,
                'taskId' => $claimedRecord->taskId,
                'taskClass' => $claimedRecord->taskClass,
                'stepClass' => $claimedRecord->stepClass,
                'priority' => $claimedRecord->priority,
                'taskStatus' => $claimedRecord->taskStatus,
                'stepStatus' => $claimedRecord->stepStatus,
                'claimedAt' => $claimedRecord->claimedAt?->format(DATE_ATOM),
                'claimedBy' => $claimedRecord->claimedBy,
            ]);

            return $claimedRecord;
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error('Queue claim failed.', [
                'runnerId' => $runnerId,
                'exceptionClass' => $throwable::class,
                'errorCode' => (int) $throwable->getCode(),
            ]);

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(int $taskId, array $changes, bool $notify = false): QueueRecord {
        if ($changes === []) {
            $this->logger->error('Queue update called without changes.', [
                'taskId' => $taskId,
            ]);

            throw new ConfigurationException('Queue update requires at least one changed column.');
        }

        if (!$this->connection->inTransaction()) {
            $this->logger->error('Queue update called without active transaction.', [
                'taskId' => $taskId,
            ]);

            throw new QueueException('PostgresQueue::update() requires an active transaction.');
        }

        $this->logger->info('Queue update started.', [
            'taskId' => $taskId,
            'columns' => array_keys($changes),
            'notify' => $notify,
        ]);

        $this->lockRecord($taskId);

        $assignments = [];
        $parameters = ['task_id' => $taskId];

        foreach ($changes as $column => $value) {
            if (!in_array($column, self::UPDATABLE_COLUMNS, true)) {
                throw new ConfigurationException(sprintf('Queue column is not updatable: %s', $column));
            }

            $parameterName = sprintf('value_%s', $column);
            $assignments[] = sprintf('%s = :%s', $column, $parameterName);
            $parameters[$parameterName] = $this->normalizeColumnValue($column, $value, $taskId);
        }

        if (!array_key_exists('updated_at', $changes)) {
            $assignments[] = 'updated_at = :value_updated_at';
            $parameters['value_updated_at'] = $this->formatDateTime($this->currentTimestamp());
        }

        $statement = $this->connection->prepare(
            sprintf(
                'UPDATE %s SET %s WHERE task_id = :task_id RETURNING *',
                $this->quotedTableName(),
                implode(', ', $assignments),
            ),
        );
        $statement->execute($parameters);
        $record = $this->fetchRecordFromStatement(
            $statement,
            sprintf('Queue row %d could not be updated.', $taskId),
        );

        if ($notify) {
            $this->emitNotification((string) $taskId);
        }

        $this->logger->info('Queue update completed.', [
            'taskId' => $record->taskId,
            'taskClass' => $record->taskClass,
            'stepClass' => $record->stepClass,
            'taskStatus' => $record->taskStatus,
            'stepStatus' => $record->stepStatus,
            'notify' => $notify,
        ]);

        return $record;
    }

    public function deleteExpired(): int {
        $statement = $this->connection->prepare(
            sprintf(
                <<<'SQL'
DELETE FROM %s
WHERE cleanup_at <= CURRENT_TIMESTAMP
  AND task_status IN (:succeeded_status, :failed_status, :cancelled_status)
SQL,
                $this->quotedTableName(),
            ),
        );
        $statement->execute([
            'succeeded_status' => TaskStatus::SUCCEEDED->value,
            'failed_status' => TaskStatus::FAILED->value,
            'cancelled_status' => TaskStatus::CANCELLED->value,
        ]);

        $deletedRows = $statement->rowCount();

        if ($deletedRows > 0) {
            $this->logger->info('Queue deleted expired terminal rows.', [
                'deletedRows' => $deletedRows,
            ]);
        }

        return $deletedRows;
    }

    public function getNotificationChannel(): string {
        $sanitizedTableName = preg_replace(
            '/[^a-zA-Z0-9_]+/',
            '_',
            $this->configuration->getTableName(),
        ) ?? 'durable_task_queue';

        return sprintf('%s_notify', trim($sanitizedTableName, '_'));
    }

    private function emitNotification(string $payload = ''): void {
        $statement = $this->connection->prepare('SELECT pg_notify(:channel, :payload)');
        $statement->execute([
            'channel' => $this->getNotificationChannel(),
            'payload' => $payload,
        ]);

        $this->logger->debug('Queue emitted notification.', [
            'channel' => $this->getNotificationChannel(),
            'payload' => $payload,
        ]);
    }

    private function fetchRecordFromStatement(\PDOStatement $statement, string $errorMessage): QueueRecord {
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $this->logger->error('Queue record fetch failed.', [
                'message' => $errorMessage,
            ]);

            throw new QueueException($errorMessage);
        }

        return QueueRecord::fromDatabaseRow($row);
    }

    private function lockRecord(int $taskId): QueueRecord {
        $statement = $this->connection->prepare(
            sprintf(
                'SELECT * FROM %s WHERE task_id = :task_id FOR UPDATE',
                $this->quotedTableName(),
            ),
        );
        $statement->execute(['task_id' => $taskId]);

        return $this->fetchRecordFromStatement(
            $statement,
            sprintf('Queue row %d could not be locked.', $taskId),
        );
    }

    private function normalizeColumnValue(string $column, mixed $value, ?int $taskId = null): mixed {
        if ($column === 'payload_json') {
            if ($value === null) {
                return null;
            }

            if ($taskId === null) {
                throw new ConfigurationException('Queue payload_json normalization requires a task ID.');
            }

            return $this->encodeJson(
                PayloadNormalizer::normalizeForStorage($value, $this->attachmentBlobStore, $taskId),
            );
        }

        if ($column === 'result_json' || $column === 'error_json') {
            return $value === null ? null : $this->encodeJson($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatDateTime($value);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function encodeJson(mixed $value): string {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException('Failed to encode queue payload as JSON.', 0, $exception);
        }
    }

    private function formatDateTime(\DateTimeInterface $dateTime): string {
        return $dateTime->format('Y-m-d H:i:sP');
    }

    private function currentTimestamp(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function quotedTableName(): string {
        return $this->quotedIdentifier($this->configuration->getTableName());
    }

    private function quotedIdentifier(string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
