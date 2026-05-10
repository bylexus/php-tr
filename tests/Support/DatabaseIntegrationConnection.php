<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Support;

use ByLexus\TaskRunner\Queue\Db\AbstractDatabasePlatform;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatform;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatformResolver;
use ByLexus\TaskRunner\Queue\Db\PostgresPlatform;
use ByLexus\TaskRunner\Queue\Db\SqlitePlatform;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use PHPUnit\Framework\TestCase;

final class DatabaseIntegrationConnection
{
    private const PROFILE_CONFIGURATION = [
        'pg' => [
            'display_name' => 'PostgreSQL',
            'name_env' => 'PG_TEST_DATABASE_NAME',
            'dsn_env' => 'PG_TEST_DATABASE_DSN',
            'user_env' => 'PG_TEST_DATABASE_USER',
            'password_env' => 'PG_TEST_DATABASE_PASSWORD',
        ],
        'mysql' => [
            'display_name' => 'MySQL',
            'name_env' => 'MYSQL_TEST_DATABASE_NAME',
            'dsn_env' => 'MYSQL_TEST_DATABASE_DSN',
            'user_env' => 'MYSQL_TEST_DATABASE_USER',
            'password_env' => 'MYSQL_TEST_DATABASE_PASSWORD',
        ],
        'mariadb' => [
            'display_name' => 'MariaDB',
            'name_env' => 'MARIADB_TEST_DATABASE_NAME',
            'dsn_env' => 'MARIADB_TEST_DATABASE_DSN',
            'user_env' => 'MARIADB_TEST_DATABASE_USER',
            'password_env' => 'MARIADB_TEST_DATABASE_PASSWORD',
        ],
        'sqlite' => [
            'display_name' => 'SQLite',
            'name_env' => 'SQLITE_TEST_DATABASE_NAME',
            'dsn_env' => 'SQLITE_TEST_DATABASE_DSN',
            'user_env' => 'SQLITE_TEST_DATABASE_USER',
            'password_env' => 'SQLITE_TEST_DATABASE_PASSWORD',
        ],
    ];

    private static ?string $activeProfile = null;

    public static function activateProfile(string $profile): void {
        self::configurationFor($profile);
        self::$activeProfile = $profile;
    }

    public static function activeProfile(): string {
        if (self::$activeProfile === null) {
            throw new \LogicException('No integration database profile is active.');
        }

        return self::$activeProfile;
    }

    public static function requirePdo(TestCase $testCase): \PDO {
        $profile = self::activeProfile();
        $configuration = self::configurationFor($profile);
        $dsn = self::readEnvironmentVariable($configuration['dsn_env']);
        $user = self::readEnvironmentVariable($configuration['user_env']);
        $password = self::readEnvironmentVariable($configuration['password_env']);
        $isSqlite = is_string($dsn) && str_starts_with($dsn, 'sqlite:');

        if ($dsn === null || (!$isSqlite && ($user === null || $password === null))) {
            $testCase->markTestSkipped(
                sprintf(
                    'Set %s, %s, and %s to run %s integration tests.',
                    $configuration['dsn_env'],
                    $configuration['user_env'],
                    $configuration['password_env'],
                    $configuration['display_name'],
                ),
            );
        }

        return self::createPdo($profile);
    }

    public static function createPdo(?string $profile = null): ?\PDO {
        $configuration = self::configurationFor($profile ?? self::activeProfile());
        $dsn = self::readEnvironmentVariable($configuration['dsn_env']);
        $user = self::readEnvironmentVariable($configuration['user_env']);
        $password = self::readEnvironmentVariable($configuration['password_env']);
        $isSqlite = is_string($dsn) && str_starts_with($dsn, 'sqlite:');

        if ($dsn === null || (!$isSqlite && ($user === null || $password === null))) {
            return null;
        }

        $dsn = self::normalizeDsn($dsn);
        $user ??= '';
        $password ??= '';

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        $pdo = new \PDO($dsn, $user, $password, $options);

        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    public static function uniqueTableName(string $prefix = 'phptr_task_queue_test'): string {
        return sprintf('trq_%s_%s', self::activeProfile(), bin2hex(random_bytes(4)));
    }

    public static function uniqueSchemaName(string $prefix = 'phptr_task_queue_schema_test'): string {
        return sprintf('trs_%s_%s', self::activeProfile(), bin2hex(random_bytes(4)));
    }

    public static function dropTableIfExists(\PDO $pdo, string $tableName, ?string $schemaName = null): void {
        $platform = self::platform($pdo);
        $configuration = new QueueConfiguration($tableName, $schemaName);

        $pdo->exec(
            sprintf(
                'DROP TABLE IF EXISTS %s',
                $platform->qualifyIdentifier($schemaName, $configuration->getBlobTableName()),
            ),
        );
        $pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $platform->qualifyIdentifier($schemaName, $tableName)));
    }

    public static function dropSchemaIfExists(\PDO $pdo, string $schemaName): void {
        $platform = self::platform($pdo);

        if ($platform instanceof PostgresPlatform) {
            $pdo->exec(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $platform->quoteIdentifier($schemaName)));

            return;
        }

        if ($platform instanceof SqlitePlatform) {
            return;
        }

        $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', $platform->quoteIdentifier($schemaName)));
    }

    public static function createSchemaIfSupported(\PDO $pdo, string $schemaName): void {
        $platform = self::platform($pdo);

        if ($platform instanceof SqlitePlatform) {
            return;
        }

        if ($platform instanceof PostgresPlatform) {
            $pdo->exec(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $platform->quoteIdentifier($schemaName)));

            return;
        }

        $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS %s', $platform->quoteIdentifier($schemaName)));
    }

    public static function requireNotificationSupport(TestCase $testCase, \PDO $pdo): void {
        if (self::platform($pdo)->supportsNotifications()) {
            return;
        }

        $testCase->markTestSkipped(
            sprintf('%s does not support queue notifications.', self::platform($pdo)->getName()),
        );
    }

    public static function requireSchemaSupport(TestCase $testCase, \PDO $pdo): void {
        if (!(self::platform($pdo) instanceof SqlitePlatform)) {
            return;
        }

        $testCase->markTestSkipped('SQLite queue configuration does not support schema names.');
    }

    /** @return array<string, string> */
    public static function processEnvironment(): array {
        $configuration = self::configurationFor(self::activeProfile());
        $environment = ['PHP_TR_TEST_DB_PROFILE' => self::activeProfile()];

        foreach (['name_env', 'dsn_env', 'user_env', 'password_env'] as $key) {
            $name = $configuration[$key];
            $value = self::readEnvironmentVariable($name);

            if ($value === null) {
                continue;
            }

            $environment[$name] = $value;
        }

        return $environment;
    }

    public static function configuredDatabaseName(TestCase $testCase): string {
        $configuration = self::configurationFor(self::activeProfile());
        $name = self::readEnvironmentVariable($configuration['name_env']);

        if ($name === null || $name === '') {
            $testCase->markTestSkipped(
                sprintf('Set %s to run configured schema integration tests.', $configuration['name_env']),
            );
        }

        return $name;
    }

    public static function platform(\PDO $pdo): DatabasePlatform {
        return DatabasePlatformResolver::resolve($pdo);
    }

    /** @return array{display_name: string, name_env: string, dsn_env: string, user_env: string, password_env: string} */
    private static function configurationFor(string $profile): array {
        $configuration = self::PROFILE_CONFIGURATION[$profile] ?? null;

        if ($configuration === null) {
            throw new \InvalidArgumentException(sprintf('Unknown integration database profile: %s', $profile));
        }

        return $configuration;
    }

    private static function readEnvironmentVariable(string $name): ?string {
        $value = getenv($name);

        if ($value === false) {
            return null;
        }

        return $value;
    }

    private static function normalizeDsn(string $dsn): string {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return $dsn;
        }

        $path = substr($dsn, strlen('sqlite:'));

        if ($path === false || $path === '' || $path === ':memory:' || str_starts_with($path, '/')) {
            return $dsn;
        }

        return sprintf('sqlite:%s/%s', dirname(__DIR__, 2), ltrim($path, '/'));
    }
}
