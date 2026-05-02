<?php

use ByLexus\DurableTask\Runner;
use ByLexus\DurableTask\RunnerConfiguration;

require_once(__DIR__ . '/../vendor/autoload.php');

$container = new ExampleServiceContainer();
$runnerConfig = new RunnerConfiguration(bootstrapSchemaOnStart: true, container: $container);
$conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=durable_task_test", 'postgres', 'postgres');
$runner = new Runner(connection: $conn, runnerConfiguration: $runnerConfig);

$runner->runLoop();
// $runner->runSingle();
