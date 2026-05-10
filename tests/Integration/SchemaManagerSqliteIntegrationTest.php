<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Integration;

use ByLexus\TaskRunner\Tests\Support\SchemaManagerIntegrationTestCase;

final class SchemaManagerSqliteIntegrationTest extends SchemaManagerIntegrationTestCase
{
    protected static function databaseProfile(): string {
        return 'sqlite';
    }
}
