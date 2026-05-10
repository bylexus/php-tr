<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Queue\Db;

use ByLexus\TaskRunner\Queue\QueueConfiguration;

abstract class AbstractDatabasePlatform implements DatabasePlatform {
    private const MAX_DERIVED_IDENTIFIER_LENGTH = 63;

    public function supportsNotifications(): bool {
        return false;
    }

    public function formatDateTime(\DateTimeInterface $dateTime): string {
        return $dateTime->format('Y-m-d H:i:s.uP');
    }

    public function supportsForUpdate(): bool {
        return true;
    }

    public function supportsSkipLocked(): bool {
        return true;
    }

    public function supportsInsertReturning(): bool {
        return false;
    }

    public function supportsUpdateReturning(): bool {
        return false;
    }

    public function quoteIdentifier(string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function qualifyIdentifier(?string $schemaName, string $identifier): string {
        if ($schemaName === null) {
            return $this->quoteIdentifier($identifier);
        }

        return sprintf('%s.%s', $this->quoteIdentifier($schemaName), $this->quoteIdentifier($identifier));
    }

    public function validateConfiguration(QueueConfiguration $configuration): void {
    }

    public function exportSchemaStatements(QueueConfiguration $configuration): array {
        return array_merge(
            $this->schemaStatements($configuration),
            [
                $this->queueTableStatement($configuration),
                $this->blobTableStatement($configuration),
            ],
            array_values($this->indexStatements($configuration)),
        );
    }

    public function bootstrapSchemaStatements(
        \PDO $connection,
        QueueConfiguration $configuration,
    ): array {
        $statements = $this->schemaStatements($configuration);
        $queueTableName = $configuration->getTableName();
        $blobTableName = $configuration->getBlobTableName();
        $queueTableExists = $this->tableExists($connection, $configuration, $queueTableName);
        $blobTableExists = $this->tableExists($connection, $configuration, $blobTableName);

        if (!$queueTableExists) {
            $statements[] = $this->queueTableStatement($configuration);
        } else {
            $columnNames = $this->fetchColumnNames($connection, $configuration, $queueTableName);

            if (!in_array('priority', $columnNames, true)) {
                $migrationStatement = $this->priorityMigrationStatement($configuration);

                if ($migrationStatement !== null) {
                    $statements[] = $migrationStatement;
                }
            }
        }

        if (!$blobTableExists) {
            $statements[] = $this->blobTableStatement($configuration);
        }

        $existingIndexNames = [];

        if (!$this->supportsCreateIndexIfNotExists()) {
            if ($queueTableExists) {
                $existingIndexNames = array_merge(
                    $existingIndexNames,
                    $this->fetchIndexNames($connection, $configuration, $queueTableName),
                );
            }

            if ($blobTableExists) {
                $existingIndexNames = array_merge(
                    $existingIndexNames,
                    $this->fetchIndexNames($connection, $configuration, $blobTableName),
                );
            }
        }

        foreach ($this->indexStatements($configuration) as $indexName => $statement) {
            if (
                !$this->supportsCreateIndexIfNotExists()
                && in_array($indexName, $existingIndexNames, true)
            ) {
                continue;
            }

            $statements[] = $statement;
        }

        return $statements;
    }

    public function tableExists(\PDO $connection, QueueConfiguration $configuration, string $tableName): bool {
        $statement = $connection->prepare(sprintf(
            'SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = %s
                  AND table_name = :table_name
            )',
            $this->defaultNamespaceExpression($configuration),
        ));
        $statement->execute($this->namespaceParameters($configuration, [
            'table_name' => $tableName,
        ]));

        return (bool) $statement->fetchColumn();
    }

    public function fetchColumnNames(\PDO $connection, QueueConfiguration $configuration, string $tableName): array {
        $statement = $connection->prepare(sprintf(
            'SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = %s
                  AND table_name = :table_name',
            $this->defaultNamespaceExpression($configuration),
        ));
        $statement->execute($this->namespaceParameters($configuration, [
            'table_name' => $tableName,
        ]));

        /** @var list<string> $columnNames */
        $columnNames = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $columnNames;
    }

    protected function schemaStatements(QueueConfiguration $configuration): array {
        return [];
    }

    abstract protected function queueTableStatement(QueueConfiguration $configuration): string;

    abstract protected function blobTableStatement(QueueConfiguration $configuration): string;

    protected function priorityMigrationStatement(QueueConfiguration $configuration): ?string {
        return null;
    }

    protected function supportsCreateIndexIfNotExists(): bool {
        return true;
    }

    /** @return array<string, string> */
    protected function indexStatements(QueueConfiguration $configuration): array {
        $queueTableName = $this->queueTableName($configuration);
        $blobTableName = $this->blobTableName($configuration);

        return [
            $this->derivedName($configuration, 'task_status_available_at_idx') => $this->createIndexStatement(
                $this->derivedName($configuration, 'task_status_available_at_idx'),
                $queueTableName,
                '(task_status, priority, available_at, task_created_at)',
            ),
            $this->derivedName($configuration, 'cleanup_at_idx') => $this->createIndexStatement(
                $this->derivedName($configuration, 'cleanup_at_idx'),
                $queueTableName,
                '(cleanup_at)',
            ),
            $this->derivedName($configuration, 'claimed_at_idx') => $this->createIndexStatement(
                $this->derivedName($configuration, 'claimed_at_idx'),
                $queueTableName,
                '(claimed_at)',
            ),
            $this->derivedName($configuration, 'updated_at_idx') => $this->createIndexStatement(
                $this->derivedName($configuration, 'updated_at_idx'),
                $queueTableName,
                '(updated_at)',
            ),
            $this->derivedName($configuration, 'blob_task_id_idx') => $this->createIndexStatement(
                $this->derivedName($configuration, 'blob_task_id_idx'),
                $blobTableName,
                '(task_id)',
            ),
        ];
    }

    /** @return list<string> */
    protected function fetchIndexNames(\PDO $connection, QueueConfiguration $configuration, string $tableName): array {
        $statement = $connection->prepare(sprintf(
            'SELECT DISTINCT index_name
                FROM information_schema.statistics
                WHERE table_schema = %s
                  AND table_name = :table_name
                  AND index_name <> :primary_index_name',
            $this->defaultNamespaceExpression($configuration),
        ));
        $statement->execute($this->namespaceParameters($configuration, [
            'table_name' => $tableName,
            'primary_index_name' => 'PRIMARY',
        ]));

        /** @var list<string> $indexNames */
        $indexNames = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $indexNames;
    }

    protected function queueTableName(QueueConfiguration $configuration): string {
        return $this->qualifyIdentifier($configuration->getSchemaName(), $configuration->getTableName());
    }

    protected function blobTableName(QueueConfiguration $configuration): string {
        return $this->qualifyIdentifier($configuration->getSchemaName(), $configuration->getBlobTableName());
    }

    protected function createIndexStatement(string $indexName, string $tableName, string $columns): string {
        $ifNotExists = $this->supportsCreateIndexIfNotExists() ? ' IF NOT EXISTS' : '';

        return sprintf(
            'CREATE INDEX%s %s ON %s %s',
            $ifNotExists,
            $this->quoteIdentifier($indexName),
            $tableName,
            $columns,
        );
    }

    protected function derivedName(QueueConfiguration $configuration, string $suffix): string {
        $sanitizedTableName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $configuration->getTableName()) ?? 'queue';
        $sanitizedTableName = trim($sanitizedTableName, '_');

        if ($sanitizedTableName === '') {
            $sanitizedTableName = 'queue';
        }

        $derivedName = sprintf('%s_%s', $sanitizedTableName, $suffix);

        if (strlen($derivedName) <= self::MAX_DERIVED_IDENTIFIER_LENGTH) {
            return $derivedName;
        }

        $hash = substr(hash('sha1', $sanitizedTableName), 0, 8);
        $maxBaseLength = self::MAX_DERIVED_IDENTIFIER_LENGTH - strlen($suffix) - strlen($hash) - 2;

        if ($maxBaseLength < 1) {
            $maxBaseLength = 1;
        }

        return sprintf('%s_%s_%s', substr($sanitizedTableName, 0, $maxBaseLength), $hash, $suffix);
    }

    protected function defaultNamespaceExpression(QueueConfiguration $configuration): string {
        return $configuration->getSchemaName() === null ? ':schema_name_default' : ':schema_name';
    }

    /** @param array<string, scalar|null> $parameters
     * @return array<string, scalar|null>
     */
    protected function namespaceParameters(QueueConfiguration $configuration, array $parameters): array {
        if ($configuration->getSchemaName() === null) {
            if (str_contains($this->defaultNamespaceExpression($configuration), ':schema_name_default')) {
                $parameters['schema_name_default'] = $this->defaultNamespaceName();
            }

            return $parameters;
        }

        $parameters['schema_name'] = $configuration->getSchemaName();

        return $parameters;
    }

    protected function defaultNamespaceName(): string {
        return 'public';
    }
}
