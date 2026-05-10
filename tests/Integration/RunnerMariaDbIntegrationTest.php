<?php

declare(strict_types=1);

namespace ByLexus\TaskRunner\Tests\Integration;

use ByLexus\TaskRunner\Tests\Support\RunnerIntegrationTestCase;

final class RunnerMariaDbIntegrationTest extends RunnerIntegrationTestCase
{
    protected static function databaseProfile(): string {
        return 'mariadb';
    }
}
