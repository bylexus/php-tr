<?php

use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;
use ByLexus\TaskRunner\RunnerConfiguration;
use Psr\Log\LoggerInterface;

require_once(__DIR__ . '/../vendor/autoload.php');

$container = new ExampleServiceContainer();
// PostgreSQL wakes runLoop() via LISTEN / NOTIFY. MySQL, MariaDB, and SQLite
// use the same worker API but poll between claim attempts instead.
// $conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=tr_test", 'postgres', 'postgres');
// $qc = new QueueConfiguration(schemaName: 'phptr');
$conn = new PDO("mysql:host=127.0.0.1;port=3307;dbname=tr_test", 'phptr', 'phptr');
$qc = new QueueConfiguration(schemaName: 'tr_test');
// $qc = new QueueConfiguration();
$runnerConfig = new RunnerConfiguration(
    bootstrapSchemaOnStart: true,
);
$env = new TaskEnvironment($conn, $qc, $container, $container->get(LoggerInterface::class), $runnerConfig);
// $conn = new PDO("sqlite:sqlite-test.db");
$runner = $env->createRunner();

// runLoop() benefits from notifications only on PostgreSQL; the other backends poll.
$runner->runLoop();
// $runner->runSingle();
