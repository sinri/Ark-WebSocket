<?php


namespace sinri\ark\websocket;


use sinri\ark\core\ArkLogger;

abstract class ArkWebSocketWorker
{
    /**
     * @var ArkLogger
     */
    protected $logger;
    /**
     * @var ArkWebSocketConnections
     */
    protected $connections;

    /**
     * ArkWebSocketWorker constructor.
     * @param ArkWebSocketConnections $connections
     * @param ArkLogger|null $logger
     */
    public function __construct(ArkWebSocketConnections $connections, ArkLogger $logger = null)
    {
        $this->connections = $connections;
        if ($logger === null) {
            $logger = new ArkLogger();
        }
        $this->logger = $logger;
    }

    /**
     * Fetch headers from new client and respond
     * @param string $clientHash
     * @param string $header Text as Raw Headers
     * @return $this
     */
    abstract public function processNewSocket(string $clientHash, string $header);

    /**
     * Fetch the client request text and respond
     * @param string $clientHash
     * @param string $buffer need to be `unmask`ed for text
     * @return $this
     */
    abstract public function processReadMessage(string $clientHash, string $buffer);

    /**
     * Respond for a client leaving
     * @param string $clientHash
     * @return $this
     */
    abstract public function processCloseSocket(string $clientHash);

    /**
     * @return bool
     * @since 0.1.7
     */
    public function processQueryLoopShouldStop(): bool
    {
        return false;
    }

    /**
     * To be override
     * @return bool
     * @since 0.1.7
     */
    public function processShouldCallSendMessageTasksNow(): bool
    {
        return false;
    }

    /**
     * To be override
     * @return void
     * @since 0.1.7
     */
    public function processSendMessageTasks()
    {
    }

    /**
     * @param string $original_msg
     * @return $this
     */
    public function maskAndBroadcastToClients(string $original_msg)
    {
        $msg = self::mask($original_msg);
        $this->connections->handleEachClient(function ($socketHash, $changed_socket) use ($msg) {
            $written = @socket_write($changed_socket, $msg, strlen($msg));
            $this->logger->debug('broadcast message, written to socket ' . intval($changed_socket), ['hash' => $socketHash, 'written' => $written]);
        });
        return $this;
    }

    /**
     * @param string[] $clientHashList
     * @param string $original_msg
     * @return $this
     * @since 0.1.1
     */
    public function maskAndSendToClients(array $clientHashList, string $original_msg)
    {
        $msg = self::mask($original_msg);
        foreach ($clientHashList as $clientHash) {
            $client = $this->connections->getClientByHash($clientHash);
            if ($client === null || !is_resource($client)) {
                $this->logger->warning(__METHOD__ . ' not a valid resource, passover', ['hash' => $clientHash, 'got' => $client]);
                continue;
            }
            $written = @socket_write($client, $msg, strlen($msg));
            $this->logger->debug('send to message, written to socket ' . intval($client), ['hash' => $clientHash, 'written' => $written]);
        }
        return $this;
    }

    /**
     * @param string $text
     * @return string
     */
    public static function mask(string $text): string
    {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);
        $header = '';
        if ($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header . $text;
    }

    /**
     * @param string $text
     * @return string
     */
    public static function unmask(string $text): string
    {
        if (strlen($text) === 0) return '';
        $length = ord($text[1]) & 127;
        if ($length == 126) {
            $masks = substr($text, 4, 4);
            $data = substr($text, 8);
        } elseif ($length == 127) {
            $masks = substr($text, 10, 4);
            $data = substr($text, 14);
        } else {
            $masks = substr($text, 2, 4);
            $data = substr($text, 6);
        }
        $text = "";
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

}