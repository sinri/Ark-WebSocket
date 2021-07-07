<?php

use Psr\Log\LogLevel;
use sinri\ark\core\ArkLogger;
use sinri\ark\websocket\ArkWebSocketConnections;
use sinri\ark\websocket\ArkWebSocketDaemon;
use sinri\ark\websocket\test\SampleWorker;

require_once __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set("Asia/Shanghai");

$logger = new ArkLogger(__DIR__ . '/../log', 'daemon');
$logger->setIgnoreLevel(LogLevel::DEBUG);
$logger->removeCurrentLogFile();

$connections = new ArkWebSocketConnections();

$worker = new SampleWorker($connections, $logger);

$host = '127.0.0.1';
$port = 4444;
$servicePath = '/ws';
//override
require __DIR__ . '/config.php';

$daemon = new ArkWebSocketDaemon(
    $host,
    $port,
    $servicePath,
    $worker,
    $connections,
    $logger
);
try {
    $daemon->loop();
} catch (Exception $e) {
    $logger->error('Exception: ' . $e->getMessage());
}