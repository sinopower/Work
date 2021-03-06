<?php

use MyCLabs\Work\Adapter\RabbitMQ\RabbitMQWorkDispatcher;
use PhpAmqpLib\Connection\AMQPConnection;
use Test\MyCLabs\Work\FunctionalTest\FakeTask;

ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);

require_once __DIR__ . '/../../../vendor/autoload.php';


$queue = $argv[1];
$timeout = $argv[2];

$connection = new AMQPConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();

$workDispatcher = new RabbitMQWorkDispatcher($channel, $queue);

// Emit 1 task and wait for 1 second for it to complete
$workDispatcher->runAndWait(new FakeTask(), $timeout);

$channel->close();
$connection->close();
