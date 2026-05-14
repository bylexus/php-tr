<?php

use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\RunnerConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../ConsoleLogger.php';
require_once __DIR__ . '/CounterTask.php';
require_once __DIR__ . '/CounterStep.php';

$conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=tr_test", 'postgres', 'postgres');
$qc = new QueueConfiguration(schemaName: 'phptr');
$logger = new ConsoleLogger();

$runnerConfig = new RunnerConfiguration(bootstrapSchemaOnStart: true);
$env = new TaskEnvironment($conn, $qc, logger: $logger, runnerConfiguration: $runnerConfig);
$env->getSchemaManager()->bootstrap();

$amount = 100;
for ($i = 1; $i <= $amount; $i++) {
    $task = new CounterTask();
    $env->enqueue($task);
}

echo "Enqueued {$amount} CounterTask instances.\n";
