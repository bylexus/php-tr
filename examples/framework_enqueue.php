<?php

declare(strict_types=1);

use ByLexus\TaskRunner\QueueContext;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ImportUserProfileTask.php';

// These environment variables let the example plug into an existing database without code changes.
$dsn = getenv('PHP_TR_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=php_tr_test';
$user = getenv('PHP_TR_DB_USER') ?: 'postgres';
$password = getenv('PHP_TR_DB_PASS') ?: 'postgres';

$pdo = new PDO($dsn, $user, $password);
// This container represents the application services available at enqueue time.
$container = new FrameworkDemoContainer();
$logger = $container->get(LoggerInterface::class);
$queue = new QueueContext($pdo, null, $container, $logger);
// Producer commands often own schema bootstrap in real applications or deployment jobs.
$queue->getSchemaManager()->bootstrap();
$userId = (int) ($argv[1] ?? 42);

// Enqueue side can instantiate the task directly, just like any other application service.
$task = new ImportUserProfileTask(
    $container->get(ExampleImportPolicy::class),
    $container->get(ExampleUserApi::class),
    $container->get(ExampleUserRepository::class),
    $logger,
);

// Only class names and payload are stored; the worker will reconstruct fresh task and step instances later.
$record = $queue->enqueue($task->forUserId($userId));

fwrite(
    STDOUT,
    sprintf("Enqueued profile import task %d for user %d\n", (int) $record->taskId, $userId),
);
