<?php

declare(strict_types=1);

use ByLexus\TaskRunner\Tests\Support\DatabaseIntegrationConnection;
use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$profile = getenv('PHP_TR_TEST_DB_PROFILE') ?: null;

$tableName = $argv[1] ?? null;
$markerPath = $argv[2] ?? null;
$connectionMode = $argv[3] ?? 'auto';
$timeoutSeconds = isset($argv[4]) ? (int) $argv[4] : 1;
$readyPath = $argv[5] ?? null;

if ($tableName === null || $markerPath === null) {
    fwrite(
        STDERR,
        "Usage: php tests/Support/run-loop.php <table-name> <marker-path> "
        . "[connection-mode] [timeout-seconds] [ready-path]\n",
    );
    exit(1);
}

if ($profile === null) {
    fwrite(STDERR, "Missing PHP_TR_TEST_DB_PROFILE.\n");
    exit(1);
}

DatabaseIntegrationConnection::activateProfile($profile);
$pdo = DatabaseIntegrationConnection::createPdo();

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Missing configured database connection environment variables.\n");
    exit(1);
}

$env = new TaskEnvironment(
    $pdo,
    new QueueConfiguration($tableName),
    runnerConfiguration: new RunnerConfiguration('runner-loop-process', false, $timeoutSeconds),
);
$runner = $env->createRunner();

if ($readyPath !== null) {
    file_put_contents($readyPath, "ready\n");
}

$runner->runLoop();
file_put_contents($markerPath, "stopped\n");
