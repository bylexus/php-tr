<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Queue\Db;

use ByLexus\TaskRunner\Queue\QueueConfiguration;

interface DatabasePlatform {
    public function getName(): string;

    public function formatDateTime(\DateTimeInterface $dateTime): string;

    public function supportsNotifications(): bool;

    public function supportsForUpdate(): bool;

    public function supportsSkipLocked(): bool;

    public function supportsInsertReturning(): bool;

    public function supportsUpdateReturning(): bool;

    public function quoteIdentifier(string $identifier): string;

    public function qualifyIdentifier(?string $schemaName, string $identifier): string;

    public function validateConfiguration(QueueConfiguration $configuration): void;

    /** @return list<string> */
    public function exportSchemaStatements(QueueConfiguration $configuration): array;

    /** @return list<string> */
    public function bootstrapSchemaStatements(
        \PDO $connection,
        QueueConfiguration $configuration,
    ): array;

    /**
     * Returns a SQL assignment that appends the given bound parameter to the append-only log column.
     */
    public function appendLogExpression(string $parameterName): string;

    public function tableExists(\PDO $connection, QueueConfiguration $configuration, string $tableName): bool;

    /** @return list<string> */
    public function fetchColumnNames(\PDO $connection, QueueConfiguration $configuration, string $tableName): array;
}
