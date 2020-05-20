<?php


namespace sinri\ark\websocket;


use sinri\ark\core\ArkLogger;

abstract class ArkWebSocketWorker
{
    /**
     * @var ArkLogger
     */
    protected $logger;

    protected $clientCount;

    public function __construct($logger=null)
    {
        if($logger===null){
            $logger=new ArkLogger();
        }
        $this->logger=$logger;
    }

    /**
     * @param string $clientHash
     * @param string $header
     * @return string
     */
    abstract public function processNewSocket($clientHash, $header);

    /**
     * @param string $clientHash
     * @param string $receivedMessage
     * @return string
     */
    abstract public function processReadMessage($clientHash, $receivedMessage);

    /**
     * @param string $clientHash
     * @return string
     */
    abstract public function processCloseSocket($clientHash);

    /**
     * @param int $clientCount
     * @return ArkWebSocketWorker
     */
    public function setClientCount($clientCount)
    {
        $this->clientCount = $clientCount;
        return $this;
    }

}