<?php


namespace sinri\ark\websocket;


use Exception;
use sinri\ark\core\ArkHelper;

class ArkWebSocketConnections
{
    /**
     * @var resource[]
     */
    protected $clients;

    /**
     * @var resource
     */
    protected $socket;

    public function __construct()
    {
        $this->clients = [];
    }

    /**
     * @return resource[]
     */
    public function getClients()
    {
        return $this->clients;
    }

    /**
     * @param resource $client
     * @return $this
     */
    public function removeClient($client)
    {
        $key = array_search($client, $this->clients);
        unset($this->clients[$key]);
        return $this;
    }

    /**
     * @param string $hash
     * @return $this
     */
    public function removeClientByHash($hash)
    {
        unset($this->clients[$hash]);
        return $this;
    }

    /**
     * @param callable $callback function($hash,$client)
     */
    public function handleEachClient($callback)
    {
        foreach ($this->clients as $hash => $client) {
            call_user_func_array($callback, [$hash, $client]);
        }
    }

    /**
     * @return int
     */
    public function getCountOfClients()
    {
        return count($this->clients);
    }

    /**
     * @param int $port
     * @param string|int $address
     * @return $this
     * @throws Exception
     */
    public function startListening($port, $address = 0)
    {
        //Create TCP/IP stream socket
        //$this->logger->debug('creating socket...');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new Exception('cannot create socket');
        }
        //reusable port
        //$this->logger->debug('setting socket as address-reusable...');
        $done = socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!$done) {
            throw new Exception('cannot set socket reusable');
        }
        //bind socket to specified host
        //$this->logger->debug('binding socket to address and port...');
        $done = socket_bind($socket, $address, $port);
        if (!$done) {
            throw new Exception('cannot bind socket to port');
        }
        //listen to port
        //$this->logger->debug('socket is ready to listen...');
        $done = socket_listen($socket);
        if (!$done) {
            throw new Exception('cannot listen to port');
        }
        //create & add listening socket to the list
        //$this->registerNewClient($socket);

        $this->setSocket($socket);

        $hash = $this->getClientHash($socket);
        $this->registerClient($socket, $hash);
        //$this->logger->info('registered new client',['hash'=>$hash,'socket'=>intval($socket)]);


        return $this;
    }

    /**
     * @param resource $socket
     * @return string|false
     */
    public function getClientHash($socket)
    {
        // @since 0.0.3 guess this reason
        if ($socket == null) {
            return 'nil';
        }
        if ($this->getSocket() === $socket) {
            return "server";
        }
        $done = @socket_getpeername($socket, $ip, $port); //get ip address of connected socket
        return $done ? ($ip . ':' . $port) : false;
    }

    /**
     * @param string $clientHash
     * @return resource|null
     * @since 0.1.1
     */
    public function getClientByHash($clientHash)
    {
        return ArkHelper::readTarget($this->clients, $clientHash);
    }

    /**
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * @param resource $socket
     * @return ArkWebSocketConnections
     */
    public function setSocket($socket)
    {
        $this->socket = $socket;
        return $this;
    }

    /**
     * @param resource $client
     * @param string $hash
     * @return ArkWebSocketConnections
     */
    public function registerClient($client, $hash = null)
    {
        if ($hash === null) {
            $hash = $this->getClientHash($client);
        }
        $this->clients[$hash] = $client;
        return $this;
    }

    /**
     * @return $this
     */
    public function stopListening()
    {
        if ($this->getSocket()) {
            socket_close($this->getSocket());
        }
        return $this;
    }
}