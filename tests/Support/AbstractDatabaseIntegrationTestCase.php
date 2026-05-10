<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class AbstractDatabaseIntegrationTestCase extends TestCase
{
    abstract protected static function databaseProfile(): string;

    protected function setUp(): void {
        parent::setUp();

        DatabaseIntegrationConnection::activateProfile(static::databaseProfile());
    }

    /** @return array<string, string> */
    protected function processEnvironment(): array {
        return DatabaseIntegrationConnection::processEnvironment();
    }
}
