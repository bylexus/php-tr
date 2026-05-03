<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Queue;

/**
 * Represents a persisted queue record.
 *
 * Carries the database-backed state for a task and its currently active step.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class QueueRecord {
    public function __construct(
        public readonly ?int $taskId,
        public readonly string $taskClass,
        public readonly ?string $stepClass,
        public readonly string $taskStatus,
        public readonly int $taskAttempt,
        public readonly \DateTimeImmutable $taskCreatedAt,
        public readonly ?\DateTimeImmutable $taskStartedAt,
        public readonly ?\DateTimeImmutable $taskFinishedAt,
        public readonly ?\DateTimeImmutable $cleanupAt,
        public readonly ?string $stepStatus,
        public readonly int $stepAttempt,
        public readonly ?\DateTimeImmutable $stepStartedAt,
        public readonly ?\DateTimeImmutable $stepFinishedAt,
        public readonly mixed $payload,
        public readonly mixed $result,
        public readonly mixed $error,
        public readonly \DateTimeImmutable $availableAt,
        public readonly ?\DateTimeImmutable $claimedAt,
        public readonly ?string $claimedBy,
        public readonly ?string $lastErrorCode,
        public readonly ?string $lastErrorMessage,
        public readonly bool $cancelRequested,
        public readonly ?string $cancelReason,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self {
        return new self(
            isset($row['task_id']) ? (int) $row['task_id'] : null,
            (string) $row['task_class'],
            isset($row['step_class']) ? (string) $row['step_class'] : null,
            (string) $row['task_status'],
            (int) $row['task_attempt'],
            new \DateTimeImmutable((string) $row['task_created_at']),
            self::nullableDateTime($row['task_started_at'] ?? null),
            self::nullableDateTime($row['task_finished_at'] ?? null),
            self::nullableDateTime($row['cleanup_at'] ?? null),
            isset($row['step_status']) ? (string) $row['step_status'] : null,
            (int) $row['step_attempt'],
            self::nullableDateTime($row['step_started_at'] ?? null),
            self::nullableDateTime($row['step_finished_at'] ?? null),
            self::decodeJson($row['payload_json'] ?? null),
            self::decodeJson($row['result_json'] ?? null),
            self::decodeJson($row['error_json'] ?? null),
            new \DateTimeImmutable((string) $row['available_at']),
            self::nullableDateTime($row['claimed_at'] ?? null),
            isset($row['claimed_by']) ? (string) $row['claimed_by'] : null,
            isset($row['last_error_code']) ? (string) $row['last_error_code'] : null,
            isset($row['last_error_message']) ? (string) $row['last_error_message'] : null,
            self::toBool($row['cancel_requested'] ?? false),
            isset($row['cancel_reason']) ? (string) $row['cancel_reason'] : null,
            new \DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private static function nullableDateTime(mixed $value): ?\DateTimeImmutable {
        if ($value === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $value);
    }

    private static function decodeJson(mixed $value): mixed {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            return $value;
        }

        return json_decode($value, false, 512, JSON_THROW_ON_ERROR);
    }

    private static function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
