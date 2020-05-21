<?php
require __DIR__ . '/config.php';

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