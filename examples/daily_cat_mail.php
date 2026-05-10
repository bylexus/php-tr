<?php

use ByLexus\TaskRunner\Queue\QueueConfiguration;
use ByLexus\TaskRunner\TaskEnvironment;
use PHPMailer\PHPMailer\PHPMailer;

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/ChuckNorrisNewsletterTask.php');
require_once(__DIR__ . '/DailyCatTask.php');
require_once(__DIR__ . '/ExampleServiceContainer.php');


// $conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=tr_test", 'postgres', 'postgres');
// $qc = new QueueConfiguration(schemaName: 'phptr');
// $conn = new PDO("mysql:host=127.0.0.1;port=3306;dbname=tr_test", 'phptr', 'phptr');
// $qc = new QueueConfiguration(schemaName: 'tr_test');
$conn = new PDO("mysql:host=127.0.0.1;port=3307;dbname=tr_test", 'phptr', 'phptr');
$qc = new QueueConfiguration(schemaName: 'tr_test');
// $conn = new PDO("sqlite:sqlite-test.db");
// $qc = new QueueConfiguration();
$env = new TaskEnvironment($conn, $qc);
$env->getSchemaManager()->bootstrap();

$container = new ExampleServiceContainer();

$task = new DailyCatTask($container->get(PHPMailer::class));
$task->setTo([
    'alex@alexi.ch',
    'blex@blexi.ch',
    'clex@clexi.ch',
    'dlex@dlexi.ch',
]);
$task->setFrom('cat@caas.com');
$env->enqueue($task);

print_r($task);
