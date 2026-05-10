<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Support;

use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;

abstract class SchemaManagerIntegrationTestCase extends AbstractDatabaseIntegrationTestCase
{
    public function testBootstrapCreatesSchemaIdempotently(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);

            $taskEnvironment->getSchemaManager()->bootstrap();
            $taskEnvironment->getSchemaManager()->bootstrap();

            self::assertTrue($taskEnvironment->getSchemaManager()->tableExists());
            self::assertTrue($taskEnvironment->getSchemaManager()->blobTableExists());
            self::assertTrue($this->columnExists($pdo, $tableName, 'cleanup_at'));
            self::assertTrue($this->columnExists($pdo, $tableName, 'priority'));
            self::assertTrue($this->columnExists($pdo, $configuration->getBlobTableName(), 'content'));
            self::assertTrue($this->columnAllowsNulls($pdo, $tableName, 'payload_json'));
            self::assertTrue($this->taskIdIsIdentityColumn($pdo, $tableName));
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testExportedDdlCanCreateSchemaExplicitly(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $taskEnvironment = new TaskEnvironment($pdo, new QueueConfiguration($tableName));
            $ddl = $taskEnvironment->getSchemaManager()->exportDdl();

            foreach ($this->statementsFromDdl($ddl) as $statement) {
                $pdo->exec($statement);
            }

            $taskEnvironment = new TaskEnvironment($pdo, new QueueConfiguration($tableName));

            self::assertTrue($taskEnvironment->getSchemaManager()->tableExists());
            self::assertTrue($taskEnvironment->getSchemaManager()->blobTableExists());
            $taskEnvironment->getSchemaManager()->validate();
            self::assertTrue($this->columnExists($pdo, $tableName, 'cleanup_at'));
            self::assertTrue($this->columnExists($pdo, $tableName, 'priority'));
            self::assertTrue($this->columnExists($pdo, sprintf('%s_blob_data', $tableName), 'content'));
            self::assertTrue($this->columnAllowsNulls($pdo, $tableName, 'payload_json'));
            self::assertTrue($this->taskIdIsIdentityColumn($pdo, $tableName));
            self::assertTrue($this->indexExists($pdo, sprintf('%s_cleanup_at_idx', $tableName)));
            self::assertTrue($this->indexExists($pdo, sprintf('%s_blob_task_id_idx', $tableName)));
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testBootstrapCreatesRequiredIndexes(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $configuration = new QueueConfiguration($tableName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);

            $taskEnvironment->getSchemaManager()->bootstrap();

            self::assertTrue($this->indexExists($pdo, sprintf('%s_cleanup_at_idx', $tableName)));
            self::assertTrue($this->indexExists($pdo, sprintf('%s_task_status_available_at_idx', $tableName)));
            self::assertTrue($this->indexExists($pdo, sprintf('%s_blob_task_id_idx', $tableName)));
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testBootstrapAddsMissingPriorityColumnToExistingQueueTable(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        $tableName = DatabaseIntegrationConnection::uniqueTableName();

        try {
            $this->createLegacyQueueTableWithoutPriority($pdo, $tableName);

            (new TaskEnvironment($pdo, new QueueConfiguration($tableName)))->getSchemaManager()->bootstrap();

            self::assertTrue($this->columnExists($pdo, $tableName, 'priority'));
        } finally {
            DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName);
        }
    }

    public function testBootstrapUsesConfiguredSchemaAndCreatesTablesInsideIt(): void {
        $pdo = DatabaseIntegrationConnection::requirePdo($this);
        DatabaseIntegrationConnection::requireSchemaSupport($this, $pdo);
        $platformName = DatabaseIntegrationConnection::platform($pdo)->getName();
        $tableName = DatabaseIntegrationConnection::uniqueTableName();
        $schemaName = $platformName === 'postgresql'
        ? DatabaseIntegrationConnection::uniqueSchemaName()
        : DatabaseIntegrationConnection::configuredDatabaseName($this);

        try {
            if ($platformName !== 'postgresql' && $platformName !== 'mysql' && $platformName !== 'mariadb') {
                DatabaseIntegrationConnection::createSchemaIfSupported($pdo, $schemaName);
            }

            $configuration = new QueueConfiguration($tableName, $schemaName);
            $taskEnvironment = new TaskEnvironment($pdo, $configuration);

            $taskEnvironment->getSchemaManager()->bootstrap();

            self::assertTrue($taskEnvironment->getSchemaManager()->tableExists());
            self::assertTrue($taskEnvironment->getSchemaManager()->blobTableExists());
            self::assertTrue($this->columnExists($pdo, $tableName, 'cleanup_at', $schemaName));
            self::assertTrue($this->columnExists($pdo, $configuration->getBlobTableName(), 'content', $schemaName));
            self::assertTrue($this->indexExists($pdo, sprintf('%s_cleanup_at_idx', $tableName), $schemaName));
        } finally {
            if ($platformName === 'postgresql') {
                DatabaseIntegrationConnection::dropSchemaIfExists($pdo, $schemaName);
            } else {
                DatabaseIntegrationConnection::dropTableIfExists($pdo, $tableName, $schemaName);
            }
        }
    }

    private function columnExists(\PDO $pdo, string $tableName, string $columnName, ?string $schemaName = null): bool {
        return in_array(
            $columnName,
            DatabaseIntegrationConnection::platform($pdo)->fetchColumnNames(
                $pdo,
                new QueueConfiguration($tableName, $schemaName),
                $tableName,
            ),
            true,
        );
    }

    private function taskIdIsIdentityColumn(\PDO $pdo, string $tableName, ?string $schemaName = null): bool {
        return match (DatabaseIntegrationConnection::platform($pdo)->getName()) {
            'postgresql' => $this->postgresIdentityColumn($pdo, $tableName, $schemaName),
            'mysql', 'mariadb' => $this->mySqlIdentityColumn($pdo, $tableName, $schemaName),
            'sqlite' => $this->sqliteIdentityColumn($pdo, $tableName),
            default => false,
        };
    }

    private function columnAllowsNulls(
        \PDO $pdo,
        string $tableName,
        string $columnName,
        ?string $schemaName = null,
    ): bool {
        return match (DatabaseIntegrationConnection::platform($pdo)->getName()) {
            'postgresql' => $this->informationSchemaAllowsNulls(
                $pdo,
                'current_schema()',
                $tableName,
                $columnName,
                $schemaName,
            ),
            'mysql', 'mariadb' => $this->informationSchemaAllowsNulls(
                $pdo,
                'DATABASE()',
                $tableName,
                $columnName,
                $schemaName,
            ),
            'sqlite' => $this->sqliteColumnAllowsNulls($pdo, $tableName, $columnName),
            default => false,
        };
    }

    private function indexExists(\PDO $pdo, string $indexName, ?string $schemaName = null): bool {
        return match (DatabaseIntegrationConnection::platform($pdo)->getName()) {
            'postgresql' => $this->postgresIndexExists($pdo, $indexName, $schemaName),
            'mysql', 'mariadb' => $this->mySqlIndexExists($pdo, $indexName, $schemaName),
            'sqlite' => $this->sqliteIndexExists($pdo, $indexName),
            default => false,
        };
    }

    private function postgresIdentityColumn(\PDO $pdo, string $tableName, ?string $schemaName): bool {
        $statement = $pdo->prepare(sprintf(
            'SELECT is_identity, identity_generation
                FROM information_schema.columns
                WHERE table_schema = %s
                  AND table_name = :table_name
                  AND column_name = :column_name',
            $schemaName === null ? 'current_schema()' : ':schema_name',
        ));
        $statement->execute($this->schemaParameters($schemaName, [
            'table_name' => $tableName,
            'column_name' => 'task_id',
        ]));
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row)
        && $row['is_identity'] === 'YES'
        && $row['identity_generation'] === 'BY DEFAULT';
    }

    private function mySqlIdentityColumn(\PDO $pdo, string $tableName, ?string $schemaName): bool {
        $statement = $pdo->prepare(sprintf(
            'SELECT extra
                FROM information_schema.columns
                WHERE table_schema = %s
                  AND table_name = :table_name
                  AND column_name = :column_name',
            $schemaName === null ? 'DATABASE()' : ':schema_name',
        ));
        $statement->execute($this->schemaParameters($schemaName, [
            'table_name' => $tableName,
            'column_name' => 'task_id',
        ]));
        $extra = $statement->fetchColumn();

        return is_string($extra) && str_contains(strtolower($extra), 'auto_increment');
    }

    private function sqliteIdentityColumn(\PDO $pdo, string $tableName): bool {
        $statement = $pdo->prepare('SELECT sql FROM sqlite_master WHERE type = :type AND name = :table_name');
        $statement->execute([
            'type' => 'table',
            'table_name' => $tableName,
        ]);
        $sql = $statement->fetchColumn();

        return is_string($sql) && str_contains(strtoupper($sql), 'AUTOINCREMENT');
    }

    private function informationSchemaAllowsNulls(
        \PDO $pdo,
        string $defaultSchemaExpression,
        string $tableName,
        string $columnName,
        ?string $schemaName,
    ): bool {
        $statement = $pdo->prepare(sprintf(
            'SELECT is_nullable
                FROM information_schema.columns
                WHERE table_schema = %s
                  AND table_name = :table_name
                  AND column_name = :column_name',
            $schemaName === null ? $defaultSchemaExpression : ':schema_name',
        ));
        $statement->execute($this->schemaParameters($schemaName, [
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]));

        return $statement->fetchColumn() === 'YES';
    }

    private function sqliteColumnAllowsNulls(\PDO $pdo, string $tableName, string $columnName): bool {
        $statement = $pdo->query(sprintf('PRAGMA table_info(%s)', $this->qualifiedIdentifier($pdo, null, $tableName)));

        if (!$statement instanceof \PDOStatement) {
            return false;
        }

        while (true) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return false;
            }

            if (($row['name'] ?? null) !== $columnName) {
                continue;
            }

            return ($row['notnull'] ?? '1') === 0 || ($row['notnull'] ?? '1') === '0';
        }
    }

    private function postgresIndexExists(\PDO $pdo, string $indexName, ?string $schemaName): bool {
        $statement = $pdo->prepare(sprintf(
            'SELECT EXISTS (
                SELECT 1
                FROM pg_indexes
                WHERE schemaname = %s
                  AND indexname = :index_name
            )',
            $schemaName === null ? 'current_schema()' : ':schema_name',
        ));
        $statement->execute($this->schemaParameters($schemaName, ['index_name' => $indexName]));

        return (bool) $statement->fetchColumn();
    }

    private function mySqlIndexExists(\PDO $pdo, string $indexName, ?string $schemaName): bool {
        $statement = $pdo->prepare(sprintf(
            'SELECT EXISTS (
                SELECT 1
                FROM information_schema.statistics
                WHERE table_schema = %s
                  AND index_name = :index_name
            )',
            $schemaName === null ? 'DATABASE()' : ':schema_name',
        ));
        $statement->execute($this->schemaParameters($schemaName, ['index_name' => $indexName]));

        return (bool) $statement->fetchColumn();
    }

    private function sqliteIndexExists(\PDO $pdo, string $indexName): bool {
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index'");

        if (!$statement instanceof \PDOStatement) {
            return false;
        }

        while (true) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return false;
            }

            if (($row['name'] ?? null) === $indexName) {
                return true;
            }
        }
    }

    /** @param array<string, scalar|null> $parameters
     * @return array<string, scalar|null>
     */
    private function schemaParameters(?string $schemaName, array $parameters): array {
        if ($schemaName === null) {
            return $parameters;
        }

        $parameters['schema_name'] = $schemaName;

        return $parameters;
    }

    private function createLegacyQueueTableWithoutPriority(\PDO $pdo, string $tableName): void {
        $qualifiedTableName = $this->qualifiedIdentifier($pdo, null, $tableName);

        $statement = match (DatabaseIntegrationConnection::platform($pdo)->getName()) {
            'postgresql' => <<<SQL
CREATE TABLE {$qualifiedTableName} (
    task_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    task_class TEXT NOT NULL,
    step_class TEXT NULL,
    task_status TEXT NOT NULL,
    task_created_at TIMESTAMPTZ NOT NULL,
    task_started_at TIMESTAMPTZ NULL,
    task_finished_at TIMESTAMPTZ NULL,
    cleanup_at TIMESTAMPTZ NULL,
    step_status TEXT NULL,
    step_attempt INTEGER NOT NULL DEFAULT 0,
    step_started_at TIMESTAMPTZ NULL,
    step_finished_at TIMESTAMPTZ NULL,
    payload_json JSONB NULL,
    result_json JSONB NULL,
    error_json JSONB NULL,
    available_at TIMESTAMPTZ NOT NULL,
    claimed_at TIMESTAMPTZ NULL,
    claimed_by TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    cancel_requested BOOLEAN NOT NULL DEFAULT FALSE,
    cancel_reason TEXT NULL,
    updated_at TIMESTAMPTZ NOT NULL
)
SQL,
            'mysql', 'mariadb' => <<<SQL
CREATE TABLE {$qualifiedTableName} (
    task_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_class TEXT NOT NULL,
    step_class TEXT NULL,
    task_status VARCHAR(32) NOT NULL,
    task_created_at DATETIME(6) NOT NULL,
    task_started_at DATETIME(6) NULL,
    task_finished_at DATETIME(6) NULL,
    cleanup_at DATETIME(6) NULL,
    step_status VARCHAR(32) NULL,
    step_attempt INTEGER NOT NULL DEFAULT 0,
    step_started_at DATETIME(6) NULL,
    step_finished_at DATETIME(6) NULL,
    payload_json JSON NULL,
    result_json JSON NULL,
    error_json JSON NULL,
    available_at DATETIME(6) NOT NULL,
    claimed_at DATETIME(6) NULL,
    claimed_by TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    cancel_requested BOOLEAN NOT NULL DEFAULT FALSE,
    cancel_reason TEXT NULL,
    updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB
SQL,
            'sqlite' => <<<SQL
CREATE TABLE {$qualifiedTableName} (
    task_id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_class TEXT NOT NULL,
    step_class TEXT NULL,
    task_status TEXT NOT NULL,
    task_created_at TEXT NOT NULL,
    task_started_at TEXT NULL,
    task_finished_at TEXT NULL,
    cleanup_at TEXT NULL,
    step_status TEXT NULL,
    step_attempt INTEGER NOT NULL DEFAULT 0,
    step_started_at TEXT NULL,
    step_finished_at TEXT NULL,
    payload_json TEXT NULL,
    result_json TEXT NULL,
    error_json TEXT NULL,
    available_at TEXT NOT NULL,
    claimed_at TEXT NULL,
    claimed_by TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    cancel_requested INTEGER NOT NULL DEFAULT 0,
    cancel_reason TEXT NULL,
    updated_at TEXT NOT NULL
)
SQL,
        };

        $pdo->exec($statement);
    }

    private function qualifiedIdentifier(\PDO $pdo, ?string $schemaName, string $identifier): string {
        return DatabaseIntegrationConnection::platform($pdo)->qualifyIdentifier($schemaName, $identifier);
    }

    /** @return list<string> */
    private function statementsFromDdl(string $ddl): array {
        $normalizedDdl = rtrim($ddl);

        if (str_ends_with($normalizedDdl, ';')) {
            $normalizedDdl = substr($normalizedDdl, 0, -1);
        }

        return array_values(
            array_filter(
                array_map('trim', explode(";\n\n", $normalizedDdl)),
                static fn (string $statement): bool => $statement !== '',
            ),
        );
    }
}
