<?php

use ByLexus\DurableTask\Queue\SchemaManager;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/ChuckNorrisNewsletterTask.php');
require_once(__DIR__ . '/ExampleServiceContainer.php');


$conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=durable_task_test", 'postgres', 'postgres');
$sm = new SchemaManager($conn);
$sm->bootstrap();

$container = new ExampleServiceContainer();

$task = new ChuckNorrisNewsletterTask($container->get(PHPMailer::class));
$task->setTo('alex@alexi.ch');
$task->setFrom('chuck@norris.com');
$task->enqueue($conn);
