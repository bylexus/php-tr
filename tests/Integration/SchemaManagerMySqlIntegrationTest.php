<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Integration;

use ByLexus\TaskRunner\Tests\Support\SchemaManagerIntegrationTestCase;

final class SchemaManagerMySqlIntegrationTest extends SchemaManagerIntegrationTestCase
{
    protected static function databaseProfile(): string {
        return 'mysql';
    }
}
