<?php

use ByLexus\DurableTask\Queue\SchemaManager;
use PHPMailer\PHPMailer\PHPMailer;

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/ChuckNorrisNewsletterTask.php');
require_once(__DIR__ . '/DailyCatTask.php');
require_once(__DIR__ . '/ExampleServiceContainer.php');


$conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=durable_task_test", 'postgres', 'postgres');
$sm = new SchemaManager($conn);
$sm->bootstrap();

$container = new ExampleServiceContainer();

$task = new DailyCatTask($container->get(PHPMailer::class));
$task->setTo([
    'alex@alexi.ch',
    'blex@blexi.ch',
    'clex@clexi.ch',
    'dlex@dlexi.ch',
]);
$task->setFrom('cat@caas.com');
$task->enqueue($conn);

print_r($task);
