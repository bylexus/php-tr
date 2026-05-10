<?php

use ByLexus\TaskRunner\QueueContext;
use PHPMailer\PHPMailer\PHPMailer;

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/ChuckNorrisNewsletterTask.php');
require_once(__DIR__ . '/ExampleServiceContainer.php');


$conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=php_tr_test", 'postgres', 'postgres');
$queue = new QueueContext($conn);
$queue->getSchemaManager()->bootstrap();

$container = new ExampleServiceContainer();

$task = new ChuckNorrisNewsletterTask($container->get(PHPMailer::class));
$task->setTo('alex@alexi.ch');
$task->setFrom('chuck@norris.com');
$queue->enqueue($task);
