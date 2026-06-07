<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Queue;

use ByLexus\TaskRunner\Enum\StepStatus;
use ByLexus\TaskRunner\Enum\TaskStatus;
use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\PayloadNormalizer;
use ByLexus\TaskRunner\Exception\QueueException;
use ByLexus\TaskRunner\Queue\Db\AbstractDatabasePlatform;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatform;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatformResolver;
use ByLexus\TaskRunner\Exception\SerializationException;
use ByLexus\TaskRunner\Step;
use ByLexus\TaskRunner\Task;
use ByLexus\TaskRunner\TaskFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Persists workflow records in the configured database.
 *
 * Implements queue storage operations for tasks, including inserts, updates, and record retrieval.
 *
 * This file is part of bylexus/php-tr
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class DatabaseQueue {
    private const MAX_NOTIFICATION_CHANNEL_LENGTH = 63;
    private const NOTIFICATION_CHANNEL_HASH_LENGTH = 12;

    /** @var list<string> */
    private const UPDATABLE_COLUMNS = [
        'task_status',
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
    private AbstractDatabasePlatform $platform;
    private LoggerInterface $logger;
    private AttachmentBlobStore $attachmentBlobStore;

    public function __construct(
        \PDO $connection,
        ?QueueConfiguration $configuration = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->connection = $connection;
        $this->configuration = $configuration ?? new QueueConfiguration();
        $platform = DatabasePlatformResolver::resolve($this->connection);

        if (!$platform instanceof AbstractDatabasePlatform) {
            throw new ConfigurationException('Unsupported database platform implementation.');
        }

        $this->platform = $platform;
        $this->platform->validateConfiguration($this->configuration);
        $this->logger = $logger ?? new NullLogger();
        $this->attachmentBlobStore = new AttachmentBlobStore($this->connection, $this->configuration);
    }

    public function getAttachmentBlobStore(): AttachmentBlobStore {
        return $this->attachmentBlobStore;
    }

    public function getConnection(): \PDO {
        return $this->connection;
    }

    public function getPlatform(): DatabasePlatform {
        return $this->platform;
    }

    public function get(int $taskId, bool $forUpdate = false): QueueRecord {
        $statement = $this->connection->prepare(
            sprintf(
                'SELECT * FROM %s WHERE task_id = :task_id%s',
                $this->quotedTableName(),
                $forUpdate && $this->platform->supportsForUpdate() ? ' FOR UPDATE' : '',
            ),
        );
        $statement->execute(['task_id' => $taskId]);

        return $this->fetchRecordFromStatement(
            $statement,
            sprintf('Queue row %d could not be loaded.', $taskId),
        );
    }

    /**
     * @return list<QueueRecord>
     */
    public function find(?TaskFilter $filter = null): array {
        $conditions = [];
        $params = [];

        if ($filter !== null && $filter->status !== null) {
            $conditions[] = 'task_status = :task_status';
            $params['task_status'] = $filter->status->value;
        }

        if ($filter !== null && $filter->taskClass !== null) {
            $conditions[] = 'task_class = :task_class';
            $params['task_class'] = $filter->taskClass;
        }

        if ($filter !== null && $filter->stepClass !== null) {
            $conditions[] = 'step_class = :step_class';
            $params['step_class'] = $filter->stepClass;
        }

        $where = $conditions !== [] ? "\nWHERE " . implode("\n  AND ", $conditions) : '';

        $statement = $this->connection->prepare(
            sprintf(
                <<<'SQL'
SELECT *
FROM %s%s
ORDER BY priority ASC, available_at ASC, task_created_at ASC
SQL,
                $this->quotedTableName(),
                $where,
            ),
        );
        $statement->execute($params);

        return $this->fetchRecordsFromStatement($statement);
    }

    public function enqueue(Task $task, Step $firstStep, int $priority = Task::PRIO_NORMAL): QueueRecord {
        $now = $this->currentTimestamp();
        $startedTransaction = false;

        $this->logger->info(
            'Queue enqueue started [taskClass={taskClass} stepClass={stepClass} priority={priority} taskStatus={taskStatus} stepStatus={stepStatus}]',
            [
                'taskClass' => $task::class,
                'stepClass' => $firstStep::class,
                'priority' => $priority,
                'taskStatus' => TaskStatus::QUEUED->value,
                'stepStatus' => StepStatus::QUEUED->value,
            ],
        );

        if (!$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $insertSql = <<<'SQL'
INSERT INTO %s (
    task_class,
    step_class,
    task_status,
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
SQL;

            if ($this->platform->supportsInsertReturning()) {
                $insertSql .= "\nRETURNING *";
            }

            $statement = $this->connection->prepare(
                sprintf(
                    $insertSql,
                    $this->quotedTableName(),
                ),
            );
            $statement->execute([
                'task_class' => $task::class,
                'step_class' => $firstStep::class,
                'task_status' => TaskStatus::QUEUED->value,
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

            if ($this->platform->supportsInsertReturning()) {
                $record = $this->fetchRecordFromStatement($statement, 'Failed to read enqueued queue record.');
            } else {
                $record = $this->loadInsertedRecord('Failed to read enqueued queue record.');
            }

            $record = $this->update((int) $record->taskId, ['payload_json' => $task->getPayload()], false);
            $this->emitNotification((string) $record->taskId);

            if ($startedTransaction) {
                $this->connection->commit();
            }
        } catch (\Throwable $throwable) {
            if ($startedTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error(
                'Queue enqueue failed [taskClass={taskClass} stepClass={stepClass} priority={priority} exceptionClass={exceptionClass} errorCode={errorCode}]',
                [
                    'taskClass' => $task::class,
                    'stepClass' => $firstStep::class,
                    'priority' => $priority,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                ],
            );

            throw $throwable;
        }

        $this->logger->info(
            'Queue enqueue completed [taskId={taskId} taskClass={taskClass} stepClass={stepClass} priority={priority} taskStatus={taskStatus} stepStatus={stepStatus} availableAt={availableAt}]',
            [
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'priority' => $record->priority,
                'taskStatus' => $record->taskStatus,
                'stepStatus' => $record->stepStatus,
                'availableAt' => $record->availableAt->format(DATE_ATOM),
            ],
        );

        return $record;
    }

    public function claim(string $runnerId): ?QueueRecord {
        if ($this->connection->inTransaction()) {
            $this->logger->error('Queue claim called inside active transaction [runnerId={runnerId}]', [
                'runnerId' => $runnerId,
            ]);

            throw new QueueException('DatabaseQueue::claim() requires no active transaction.');
        }

        $this->connection->beginTransaction();

        try {
            if (!$this->platform->supportsSkipLocked()) {
                $claimedRecord = $this->claimWithoutSkipLocked($runnerId);

                $this->connection->commit();

                return $claimedRecord;
            }

            $select = $this->connection->prepare(
                $this->claimSelectSql(true),
            );
            $select->execute([
                'task_status' => TaskStatus::QUEUED->value,
                'step_status' => StepStatus::QUEUED->value,
                'available_at' => $this->formatDateTime($this->currentTimestamp()),
            ]);

            $row = $select->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $this->connection->commit();

                $this->logger->debug('Queue claim found no available task [runnerId={runnerId}]', [
                    'runnerId' => $runnerId,
                ]);

                return null;
            }

            $record = QueueRecord::fromDatabaseRow($row);
            $now = $this->currentTimestamp();
            $claimUpdateSql = <<<'SQL'
UPDATE %s
SET task_status = :task_status,
    step_status = :step_status,
    claimed_at = :claimed_at,
    claimed_by = :claimed_by,
    task_started_at = COALESCE(task_started_at, :task_started_at),
    step_started_at = COALESCE(step_started_at, :step_started_at),
    updated_at = :updated_at
WHERE task_id = :task_id
SQL;

            if ($this->platform->supportsUpdateReturning()) {
                $claimUpdateSql .= "\nRETURNING *";
            }

            $update = $this->connection->prepare(
                sprintf(
                    $claimUpdateSql,
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

            if ($this->platform->supportsUpdateReturning()) {
                $claimedRecord = $this->fetchRecordFromStatement($update, 'Failed to read claimed queue record.');
            } else {
                $claimedRecord = $this->get((int) $record->taskId, false);
            }

            $this->connection->commit();

            $this->logger->info(
                'Queue claim succeeded [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} priority={priority} taskStatus={taskStatus} stepStatus={stepStatus} claimedAt={claimedAt} claimedBy={claimedBy}]',
                [
                    'runnerId' => $runnerId,
                    'taskId' => $claimedRecord->taskId,
                    'taskClass' => $claimedRecord->taskClass,
                    'stepClass' => $claimedRecord->stepClass,
                    'priority' => $claimedRecord->priority,
                    'taskStatus' => $claimedRecord->taskStatus,
                    'stepStatus' => $claimedRecord->stepStatus,
                    'claimedAt' => $claimedRecord->claimedAt?->format(DATE_ATOM),
                    'claimedBy' => $claimedRecord->claimedBy,
                ],
            );

            return $claimedRecord;
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->logger->error(
                'Queue claim failed [runnerId={runnerId} exceptionClass={exceptionClass} errorCode={errorCode}]',
                [
                    'runnerId' => $runnerId,
                    'exceptionClass' => $throwable::class,
                    'errorCode' => (int) $throwable->getCode(),
                ],
            );

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(int $taskId, array $changes, bool $notify = false): QueueRecord {
        if ($changes === []) {
            $this->logger->error('Queue update called without changes [taskId={taskId}]', [
                'taskId' => $taskId,
            ]);

            throw new ConfigurationException('Queue update requires at least one changed column.');
        }

        $this->requireActiveTransaction('DatabaseQueue::update()', ['taskId' => $taskId]);

        $this->logger->info(
            'Queue update started [taskId={taskId} columns={columns} notify={notify}]',
            [
                'taskId' => $taskId,
                'columns' => implode(',', array_keys($changes)),
                'notify' => $notify,
            ],
        );

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
                $this->platform->supportsUpdateReturning()
                    ? 'UPDATE %s SET %s WHERE task_id = :task_id RETURNING *'
                    : 'UPDATE %s SET %s WHERE task_id = :task_id',
                $this->quotedTableName(),
                implode(', ', $assignments),
            ),
        );
        $statement->execute($parameters);
        if ($this->platform->supportsUpdateReturning()) {
            $record = $this->fetchRecordFromStatement(
                $statement,
                sprintf('Queue row %d could not be updated.', $taskId),
            );
        } else {
            $record = $this->get($taskId, false);
        }

        if ($notify) {
            $this->emitNotification((string) $taskId);
        }

        $this->logger->info(
            'Queue update completed [taskId={taskId} taskClass={taskClass} stepClass={stepClass} taskStatus={taskStatus} stepStatus={stepStatus} notify={notify}]',
            [
                'taskId' => $record->taskId,
                'taskClass' => $record->taskClass,
                'stepClass' => $record->stepClass,
                'taskStatus' => $record->taskStatus,
                'stepStatus' => $record->stepStatus,
                'notify' => $notify,
            ],
        );

        return $record;
    }

    public function deleteExpired(): int {
        $statement = $this->connection->prepare(
            sprintf(
                <<<'SQL'
DELETE FROM %s
WHERE cleanup_at <= :cleanup_before
  AND task_status IN (:succeeded_status, :failed_status, :cancelled_status)
SQL,
                $this->quotedTableName(),
            ),
        );
        $statement->execute([
            'cleanup_before' => $this->formatDateTime($this->currentTimestamp()),
            'succeeded_status' => TaskStatus::SUCCEEDED->value,
            'failed_status' => TaskStatus::FAILED->value,
            'cancelled_status' => TaskStatus::CANCELLED->value,
        ]);

        $deletedRows = $statement->rowCount();

        if ($deletedRows > 0) {
            $this->logger->info('Queue deleted expired terminal rows [deletedRows={deletedRows}]', [
                'deletedRows' => $deletedRows,
            ]);
        }

        return $deletedRows;
    }

    /**
     * @return list<QueueRecord>
     */
    public function findStartedRunningTasks(): array {
        return $this->selectRunningTasks("\n  AND step_started_at IS NOT NULL", [
            'task_status' => TaskStatus::RUNNING->value,
            'step_status' => StepStatus::RUNNING->value,
        ]);
    }

    /**
     * @return list<QueueRecord>
     */
    public function findClaimedRunningTasks(string $runnerId): array {
        return $this->selectRunningTasks("\n  AND claimed_by = :claimed_by", [
            'task_status' => TaskStatus::RUNNING->value,
            'step_status' => StepStatus::RUNNING->value,
            'claimed_by' => $runnerId,
        ]);
    }

    public function getNotificationChannel(): string {
        $nameParts = [];

        if ($this->configuration->getSchemaName() !== null) {
            $nameParts[] = $this->sanitizeIdentifierPart($this->configuration->getSchemaName()) ?? 'queue';
        }

        $nameParts[] = $this->sanitizeIdentifierPart($this->configuration->getTableName()) ?? 'phptr_task_queue';

        $channelBase = trim(implode('_', $nameParts), '_');

        if ($channelBase === '') {
            $channelBase = 'phptr_task_queue';
        }

        $channel = sprintf('%s_notify', $channelBase);

        if (strlen($channel) <= self::MAX_NOTIFICATION_CHANNEL_LENGTH) {
            return $channel;
        }

        $hash = substr(hash('sha1', $channel), 0, self::NOTIFICATION_CHANNEL_HASH_LENGTH);
        $maxBaseLength = self::MAX_NOTIFICATION_CHANNEL_LENGTH - strlen('_notify_') - strlen($hash);

        return sprintf('%s_notify_%s', substr($channelBase, 0, $maxBaseLength), $hash);
    }

    private function emitNotification(string $payload = ''): void {
        if (!$this->platform->supportsNotifications()) {
            return;
        }

        $statement = $this->connection->prepare('SELECT pg_notify(:channel, :payload)');
        $statement->execute([
            'channel' => $this->getNotificationChannel(),
            'payload' => $payload,
        ]);

        $this->logger->debug(
            'Queue emitted notification [channel={channel} payload={payload}]',
            [
                'channel' => $this->getNotificationChannel(),
                'payload' => $payload,
            ],
        );
    }

    private function fetchRecordFromStatement(\PDOStatement $statement, string $errorMessage): QueueRecord {
        try {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $this->logger->error('Queue record fetch failed [message={message}]', [
                    'message' => $errorMessage,
                ]);

                throw new QueueException($errorMessage);
            }

            return QueueRecord::fromDatabaseRow($row);
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<QueueRecord>
     */
    private function selectRunningTasks(string $additionalWhere, array $parameters): array {
        $this->requireActiveTransaction('DatabaseQueue::selectRunningTasks()');

        $statement = $this->connection->prepare(
            $this->runningTaskSelectSql($additionalWhere),
        );
        $statement->execute($parameters);

        return $this->fetchRecordsFromStatement($statement);
    }

    /**
     * @return list<QueueRecord>
     */
    private function fetchRecordsFromStatement(\PDOStatement $statement): array {
        try {
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }

        $records = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $records[] = QueueRecord::fromDatabaseRow($row);
        }

        return $records;
    }

    private function lockRecord(int $taskId): QueueRecord {
        $statement = $this->connection->prepare(
            sprintf(
                'SELECT * FROM %s WHERE task_id = :task_id%s',
                $this->quotedTableName(),
                $this->platform->supportsForUpdate() ? ' FOR UPDATE' : '',
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
        return $this->platform->formatDateTime($dateTime);
    }

    private function currentTimestamp(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function quotedTableName(): string {
        return $this->platform->qualifyIdentifier(
            $this->configuration->getSchemaName(),
            $this->configuration->getTableName(),
        );
    }

    private function sanitizeIdentifierPart(string $identifier): ?string {
        return preg_replace('/[^a-zA-Z0-9_]+/', '_', $identifier);
    }

    private function loadInsertedRecord(string $errorMessage): QueueRecord {
        $taskId = $this->lastInsertedId();

        if ($taskId === null) {
            throw new QueueException($errorMessage);
        }

        return $this->get($taskId, false);
    }

    private function lastInsertedId(): ?int {
        $value = $this->connection->lastInsertId();

        if ($value === false || $value === '0' || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function requireActiveTransaction(string $operation, array $context = []): void {
        if ($this->connection->inTransaction()) {
            return;
        }

        $placeholders = '{operation}';

        foreach (array_keys($context) as $key) {
            $placeholders .= sprintf(' %s={%s}', $key, $key);
        }

        $this->logger->error(
            'Queue operation called without active transaction [' . $placeholders . ']',
            [
                'operation' => $operation,
                ...$context,
            ],
        );

        throw new QueueException(sprintf('%s requires an active transaction.', $operation));
    }

    private function claimSelectSql(bool $withSkipLocked): string {
        $lockingClause = '';

        if ($withSkipLocked && $this->platform->supportsSkipLocked()) {
            $lockingClause = "\nFOR UPDATE SKIP LOCKED";
        }

        return sprintf(
            <<<'SQL'
SELECT *
FROM %s
WHERE task_status = :task_status
  AND step_status = :step_status
  AND available_at <= :available_at
ORDER BY priority ASC, available_at ASC, task_created_at ASC
LIMIT 1%s
SQL,
            $this->quotedTableName(),
            $lockingClause,
        );
    }

    private function runningTaskSelectSql(string $additionalWhere = ''): string {
        $lockingClause = $this->runningTaskLockingClause();

        return sprintf(
            <<<'SQL'
SELECT *
FROM %s
WHERE task_status = :task_status
  AND step_status = :step_status%s%s
SQL,
            $this->quotedTableName(),
            $additionalWhere,
            $lockingClause === '' ? '' : "\n{$lockingClause}",
        );
    }

    private function runningTaskLockingClause(): string {
        if ($this->platform->supportsSkipLocked()) {
            return 'FOR UPDATE SKIP LOCKED';
        }

        if ($this->platform->supportsForUpdate()) {
            return 'FOR UPDATE';
        }

        return '';
    }

    private function claimWithoutSkipLocked(string $runnerId): ?QueueRecord {
        while (true) {
            $select = $this->connection->prepare(
                $this->claimSelectSql(false),
            );
            $select->execute([
                'task_status' => TaskStatus::QUEUED->value,
                'step_status' => StepStatus::QUEUED->value,
                'available_at' => $this->formatDateTime($this->currentTimestamp()),
            ]);

            $row = $select->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $this->logger->debug('Queue claim found no available task [runnerId={runnerId}]', [
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
  AND task_status = :expected_task_status
  AND step_status = :expected_step_status
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
                'expected_task_status' => TaskStatus::QUEUED->value,
                'expected_step_status' => StepStatus::QUEUED->value,
            ]);

            if ($update->rowCount() !== 1) {
                continue;
            }

            $claimedRecord = $this->get((int) $record->taskId, false);

            $this->logger->info(
                'Queue claim succeeded [runnerId={runnerId} taskId={taskId} taskClass={taskClass} stepClass={stepClass} priority={priority} taskStatus={taskStatus} stepStatus={stepStatus} claimedAt={claimedAt} claimedBy={claimedBy}]',
                [
                    'runnerId' => $runnerId,
                    'taskId' => $claimedRecord->taskId,
                    'taskClass' => $claimedRecord->taskClass,
                    'stepClass' => $claimedRecord->stepClass,
                    'priority' => $claimedRecord->priority,
                    'taskStatus' => $claimedRecord->taskStatus,
                    'stepStatus' => $claimedRecord->stepStatus,
                    'claimedAt' => $claimedRecord->claimedAt?->format(DATE_ATOM),
                    'claimedBy' => $claimedRecord->claimedBy,
                ],
            );

            return $claimedRecord;
        }
    }
}
