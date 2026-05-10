<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Integration;

use ByLexus\TaskRunner\Tests\Support\DatabaseQueueIntegrationTestCase;

final class DatabaseQueueMySqlIntegrationTest extends DatabaseQueueIntegrationTestCase
{
    protected static function databaseProfile(): string {
        return 'mysql';
    }
}
