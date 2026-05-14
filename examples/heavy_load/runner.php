<?php

use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../ConsoleLogger.php';
require_once __DIR__ . '/CounterTask.php';
require_once __DIR__ . '/CounterStep.php';

// Multiple instances of this script can run in parallel.
// The queue uses row-level locking to ensure each task is processed by exactly one runner.

$conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=tr_test", 'postgres', 'postgres');
$qc = new QueueConfiguration(schemaName: 'phptr');
$logger = new ConsoleLogger();

$runnerConfig = new RunnerConfiguration(bootstrapSchemaOnStart: true);
$env = new TaskEnvironment($conn, $qc, logger: $logger, runnerConfiguration: $runnerConfig);
$runner = $env->createRunner();

$runner->runLoop();
