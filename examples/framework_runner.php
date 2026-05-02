<?php

declare(strict_types=1);

use ByLexus\DurableTask\Runner;
use ByLexus\DurableTask\RunnerConfiguration;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ImportUserProfileTask.php';

// Runner and producer must point at the same queue table and database.
$dsn = getenv('DURABLE_TASK_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=durable_task_test';
$user = getenv('DURABLE_TASK_DB_USER') ?: 'postgres';
$password = getenv('DURABLE_TASK_DB_PASS') ?: 'postgres';

$pdo = new PDO($dsn, $user, $password);
// The worker container is what allows constructor injection during task and step hydration.
$container = new FrameworkDemoContainer();
$logger = $container->get(LoggerInterface::class);
$mode = $argv[1] ?? 'single';

$runner = new Runner(
    connection: $pdo,
    runnerConfiguration: new RunnerConfiguration(
        // Schema bootstrap is disabled here because the enqueue command already did it explicitly.
        bootstrapSchemaOnStart: false,
        container: $container,
        logger: $logger,
        runnerId: 'framework-demo-runner',
        notificationWaitTimeoutSeconds: 10,
    ),
);

if ($mode === 'loop') {
    // Long-running mode is what you would usually supervise as a worker process.
    $runner->runLoop();

    exit(0);
}

// Single mode is convenient for demos, tests, and process managers that prefer short-lived workers.
$processed = $runner->runSingle();

fwrite(STDOUT, sprintf("Processed %d task(s)\n", $processed));
