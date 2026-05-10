<?php

declare(strict_types=1);

use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ImportUserProfileTask.php';

// Runner and producer must point at the same queue table and database.
// PostgreSQL enables LISTEN / NOTIFY wakeups for runLoop(); MySQL, MariaDB,
// and SQLite use the same worker flow but poll between claim attempts.
$dsn = getenv('PHP_TR_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=php_tr_test';
$user = getenv('PHP_TR_DB_USER') ?: 'postgres';
$password = getenv('PHP_TR_DB_PASS') ?: 'postgres';

$pdo = new PDO($dsn, $user, $password);
// The worker container is what allows constructor injection during task and step hydration.
$container = new FrameworkDemoContainer();
$logger = $container->get(LoggerInterface::class);
$env = new TaskEnvironment(
    $pdo,
    null,
    $container,
    $logger,
    new RunnerConfiguration(
        // Schema bootstrap is disabled here because the enqueue command already did it explicitly.
        bootstrapSchemaOnStart: false,
        runnerId: 'framework-demo-runner',
        notificationWaitTimeoutSeconds: 10,
    ),
);
$mode = $argv[1] ?? 'single';

$runner = $env->createRunner();

if ($mode === 'loop') {
    // Long-running mode is what you would usually supervise as a worker process.
    // PostgreSQL waits on notifications here; other supported backends poll.
    $runner->runLoop();

    exit(0);
}

// Single mode is convenient for demos, tests, and process managers that prefer short-lived workers.
$processed = $runner->runSingle();

fwrite(STDOUT, sprintf("Processed %d task(s)\n", $processed));
