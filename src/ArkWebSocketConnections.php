<?php


namespace sinri\ark\websocket;


use sinri\ark\core\ArkHelper;
use sinri\ark\websocket\exception\ArkWebSocketError;

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
    public function getClients(): array
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
    public function removeClientByHash(string $hash)
    {
        unset($this->clients[$hash]);
        return $this;
    }

    /**
     * @param callable $callback function($hash,$client)
     */
    public function handleEachClient(callable $callback)
    {
        foreach ($this->clients as $hash => $client) {
            call_user_func_array($callback, [$hash, $client]);
        }
    }

    /**
     * @return int
     */
    public function getCountOfClients(): int
    {
        return count($this->clients);
    }

    /**
     * @param int $port
     * @param string|int $address
     * @return $this
     * @throws ArkWebSocketError
     */
    public function startListening(int $port, $address = 0)
    {
        //Create TCP/IP stream socket
        //$this->logger->debug('creating socket...');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new ArkWebSocketError('cannot create socket');
        }
        //reusable port
        //$this->logger->debug('setting socket as address-reusable...');
        $done = socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!$done) {
            throw new ArkWebSocketError('cannot set socket reusable');
        }
        //bind socket to specified host
        //$this->logger->debug('binding socket to address and port...');
        $done = socket_bind($socket, $address, $port);
        if (!$done) {
            throw new ArkWebSocketError('cannot bind socket to port');
        }
        //listen to port
        //$this->logger->debug('socket is ready to listen...');
        $done = socket_listen($socket);
        if (!$done) {
            throw new ArkWebSocketError('cannot listen to port');
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
     * @since 0.1.8 add server node hash to it
     */
    public function getClientHash($socket)
    {
        if ($socket == null) {
            return false;
        }
        if ($this->getSocket() === $socket) {
            return $this->getServerNodeHash($socket) . '-CLIENTS';
        }
        $done = @socket_getpeername($socket, $ip, $port); //get ip address of connected socket
        return $done ? ($this->getServerNodeHash($socket) . '-' . $ip . ':' . $port) : false;
    }

    /**
     * @param resource $socket
     * @return false|string
     * @since 0.1.8
     */
    public function getServerNodeHash($socket)
    {
        if ($socket == null) {
            return false;
        }
        $done = @socket_getsockname($socket, $ip, $port);
        return $done ? ($ip . ':' . $port) : false;
    }

    /**
     * @param string $clientHash
     * @return resource|null
     * @since 0.1.1
     */
    public function getClientByHash(string $clientHash)
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
     * @param string|null $hash
     * @return ArkWebSocketConnections
     */
    public function registerClient($client, string $hash = null)
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