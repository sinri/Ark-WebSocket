<?php


namespace sinri\ark\websocket\test;


use sinri\ark\websocket\ArkWebSocketWorker;

class SampleWorker extends ArkWebSocketWorker
{

    public function processNewSocket(string $clientHash, string $header)
    {
        $response = __METHOD__ . ' ' . $clientHash . ' , ' . $header;
        $this->logger->info(__METHOD__, ['hash' => $clientHash, 'header' => $header]);
        $this->maskAndBroadcastToClients($response);
    }

    public function processReadMessage(string $clientHash, string $buffer)
    {
        $receivedMessage = self::unmask($buffer);
        $response = __METHOD__ . ' ' . $clientHash . ' , ' . $receivedMessage;
        $this->logger->info(__METHOD__, ['hash' => $clientHash, 'message' => $receivedMessage]);
        $this->maskAndBroadcastToClients($response);
    }

    public function processCloseSocket(string $clientHash)
    {
        $response = __METHOD__ . ' ' . $clientHash;
        $this->logger->info(__METHOD__, ['hash' => $clientHash]);
        $this->maskAndBroadcastToClients($response);
    }
}