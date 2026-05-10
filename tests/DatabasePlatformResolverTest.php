<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests;

use ByLexus\TaskRunner\Exception\ConfigurationException;
use ByLexus\TaskRunner\Queue\Db\DatabasePlatformResolver;
use ByLexus\TaskRunner\Queue\Db\MySqlPlatform;
use ByLexus\TaskRunner\Queue\Db\PostgresPlatform;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;
use PHPUnit\Framework\TestCase;

final class DatabasePlatformResolverTest extends TestCase
{
    public function testResolverDetectsPostgresDriver(): void {
        $platform = DatabasePlatformResolver::resolve($this->mockPdo('pgsql'));

        self::assertSame('postgresql', $platform->getName());
        self::assertTrue($platform->supportsNotifications());
    }

    public function testResolverDetectsMysqlDriver(): void {
        $platform = DatabasePlatformResolver::resolve($this->mockPdo('mysql', '8.4.5'));

        self::assertSame('mysql', $platform->getName());
        self::assertFalse($platform->supportsNotifications());
    }

    public function testResolverDetectsMariadbDriverFamily(): void {
        $platform = DatabasePlatformResolver::resolve($this->mockPdo('mysql', '10.11.11-MariaDB'));

        self::assertSame('mariadb', $platform->getName());
        self::assertFalse($platform->supportsNotifications());
    }

    public function testPlatformsFormatTimestampsForTheirColumnTypes(): void {
        $timestamp = new \DateTimeImmutable('2026-05-10 12:56:31.123456', new \DateTimeZone('UTC'));

        $mysqlPlatform = DatabasePlatformResolver::resolve($this->mockPdo('mysql', '8.4.5'));
        $mariadbPlatform = DatabasePlatformResolver::resolve($this->mockPdo('mysql', '10.11.11-MariaDB'));
        $postgresPlatform = DatabasePlatformResolver::resolve($this->mockPdo('pgsql'));

        self::assertSame('2026-05-10 12:56:31.123456', $mysqlPlatform->formatDateTime($timestamp));
        self::assertSame('2026-05-10 12:56:31.123456', $mariadbPlatform->formatDateTime($timestamp));
        self::assertSame('2026-05-10 12:56:31.123456+00:00', $postgresPlatform->formatDateTime($timestamp));
    }

    public function testResolverDetectsSqliteDriver(): void {
        $platform = DatabasePlatformResolver::resolve($this->mockPdo('sqlite'));

        self::assertSame('sqlite', $platform->getName());
        self::assertFalse($platform->supportsForUpdate());
        self::assertFalse($platform->supportsSkipLocked());
    }

    public function testSchemaManagerRejectsSchemaNamesOnSqlite(): void {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('SQLite queue configuration does not support schema names.');

        (new TaskEnvironment(
            $this->mockPdo('sqlite'),
            new QueueConfiguration('task_queue', 'app_jobs'),
        ))->getSchemaManager();
    }

    public function testPostgresIntrospectionDoesNotBindSyntheticDefaultSchemaParameter(): void {
        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute', 'fetchColumn'])
            ->getMock();
        $statement->expects(self::once())
            ->method('execute')
            ->with(['table_name' => 'task_queue'])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchColumn')
            ->willReturn(1);

        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare'])
            ->getMock();
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('current_schema()'))
            ->willReturn($statement);

        $exists = (new PostgresPlatform())->tableExists($pdo, new QueueConfiguration(), 'task_queue');

        self::assertTrue($exists);
    }

    public function testMysqlIntrospectionDoesNotBindSyntheticDefaultSchemaParameter(): void {
        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute', 'fetchColumn'])
            ->getMock();
        $statement->expects(self::once())
            ->method('execute')
            ->with(['table_name' => 'task_queue'])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchColumn')
            ->willReturn(1);

        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare'])
            ->getMock();
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('DATABASE()'))
            ->willReturn($statement);

        $exists = (new MySqlPlatform())->tableExists($pdo, new QueueConfiguration(), 'task_queue');

        self::assertTrue($exists);
    }

    private function mockPdo(string $driverName, ?string $serverVersion = null): \PDO {
        $pdo = $this->createStub(\PDO::class);

        $pdo->method('getAttribute')->willReturnCallback(
            static function (int $attribute) use ($driverName, $serverVersion): mixed {
                return match ($attribute) {
                    \PDO::ATTR_DRIVER_NAME => $driverName,
                    \PDO::ATTR_SERVER_VERSION => $serverVersion,
                    default => null,
                };
            },
        );

        return $pdo;
    }
}
