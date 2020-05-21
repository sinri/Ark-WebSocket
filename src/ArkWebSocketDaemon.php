<?php


namespace sinri\ark\websocket;


use Exception;
use sinri\ark\core\ArkHelper;
use sinri\ark\core\ArkLogger;

class ArkWebSocketDaemon
{
    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;
    /**
     * @var ArkWebSocketWorker
     */
    private $worker;
    /**
     * @var string wss uri for js
     */
    private $servicePath;

    /**
     * @var ArkLogger
     */
    private $logger;

    /**
     * @var ArkWebSocketConnections
     */
    protected $connections;

    /**
     * ArkWebSocketDaemon constructor.
     * @param string $host
     * @param int $port
     * @param string $servicePath
     * @param ArkWebSocketWorker $worker
     * @param ArkWebSocketConnections $connections
     * @param ArkLogger|null $logger
     */
    public function __construct($host, $port, $servicePath, $worker, $connections, $logger = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->worker = $worker;
        $this->servicePath = $servicePath;
        $this->connections = $connections;
        $this->logger = $logger;
    }

    /**
     * @return resource[]
     */
    private function getChangedSockets(){
        $changed = $this->connections->getClients();
        $selected = socket_select($changed, $null, $null, 0, 10);
        if ($selected === false) {
            $error_code = socket_last_error();
            $error_string = socket_strerror($error_code);
            $this->logger->warning('socket select failed, ' . $error_code . ' ' . $error_string);
        } elseif ($selected > 0) {
            $this->logger->debug('readable sockets selected out', ['selected' => $selected, 'total' => count($changed)]);
            foreach ($changed as $key => $value) {
                $this->logger->debug('changed socket iterator', ['key' => $key, 'value' => intval($value)]);
            }
        }
        return $changed;
    }

    /**
     * @param resource[] $changed
     */
    private function handleNewConnection(&$changed)
    {
        $this->logger->info('a new client came up');
        $socket_new = socket_accept($this->connections->getSocket()); //accept new socket

        $header = socket_read($socket_new, 1024); //read data sent by the socket
        $this->perform_handshaking($header, $socket_new); //perform websocket handshake

        $client_hash = $this->connections->getClientHash($socket_new);
        $this->logger->info('new client identified', ['hash' => $client_hash]);

        //add socket to client array
        $this->connections->registerClient($socket_new);

        //plugin
        $this->logger->debug('let worker handle new socket...');
        $this->worker->processNewSocket($client_hash, $header);

        // broadcast should be done in process
        // $this->worker->maskAndBroadcastToClients($response);
        //$this->logger->debug('broadcast this news');

        // make room for new socket
        $found_socket = array_search($this->connections->getSocket(), $changed);
        unset($changed[$found_socket]);
    }

    /**
     * @param resource $changed_socket
     */
    private function readSocket($changed_socket){
        $client_hash = $this->connections->getClientHash($changed_socket);
        $this->logger->debug('begin reading socket', ['hash' => $client_hash, 'socket' => intval($changed_socket)]);
        $buffer=null;
        while(true){
            // @since 0.0.5 not block it
            $readBytes = @socket_recv($changed_socket, $bufferPiece, 10240, MSG_DONTWAIT);
            if ($readBytes === false) {
                $this->logger->warning('socket_recv get false', ['client' => intval($changed_socket)]);
                break;
            }
            if ($readBytes === 0) {
                $this->logger->warning('socket_recv get empty data', ['client' => intval($changed_socket)]);
                break;
            }
            if ($buffer === null) $buffer = '';
            $buffer .= $bufferPiece;

            $this->logger->debug('read piece', ['client' => intval($changed_socket), 'piece_length' => strlen($bufferPiece), 'total_length' => strlen($buffer)]);
        }
        if($buffer!==null) {
//            $received_text = self::unmask($buffer); //unmask data
//            $this->logger->debug('read with something',['text'=>$received_text]);

            // plugin
            $this->worker->processReadMessage($client_hash, $buffer);
        }else{
            $this->logger->debug('read without anything, try to check if died');
            $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
            if ($buf === false) { // check disconnected client
                $this->logger->warning('socket seems died', ['hash' => $client_hash]);
                // remove client for $this->clients array
                $this->connections->removeClient($changed_socket);

                //notify all users about disconnected connection

                //plugin
                $this->worker->processCloseSocket($client_hash);

//                $response = self::mask($response);
//                $this->broadcastMessage($response);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function loop()
    {
        try {
            $this->connections->startListening($this->port, 0);
            $this->logger->info('socket listening on ' . $this->port . ' started, client set updated', [
                'total' => $this->connections->getCountOfClients(),
                'clients' => $this->connections->getClients(),
            ]);

            $this->logger->info('daemon loop running...');
            while (true) {
                $changed = $this->getChangedSockets();
                //check for new socket
                if (in_array($this->connections->getSocket(), $changed)) {
                    $this->handleNewConnection($changed);
                }

                //loop through all connected sockets
                foreach ($changed as $changed_socket) {
                    $this->readSocket($changed_socket);
                }
            }
        } catch (Exception $exception) {
            $error_code = socket_last_error();
            $error_string = socket_strerror($error_code);
            $this->logger->error($exception->getMessage(), ['error_code' => $error_code, 'error_string' => $error_string]);
            throw new Exception($exception->getMessage() . ', error [' . $error_code . '] ' . $error_string);
        } finally {
            $this->logger->info('daemon loop ending (this line would never be printed)');
            $this->connections->stopListening();
        }
    }

    /**
     * @param string $received_header
     * @param resource $client_conn
     */
    protected function perform_handshaking($received_header, $client_conn)
    {
        $headers = array();
        $lines = preg_split("/\r\n/", $received_header);
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey = ArkHelper::readTarget($headers, ['Sec-WebSocket-Key']);
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        //hand shaking header
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Origin: {$this->host}\r\n" .
            "Sec-WebSocket-Location: {$this->servicePath}\r\n" .
            "Sec-WebSocket-Accept:{$secAccept}\r\n\r\n";
        socket_write($client_conn, $upgrade, strlen($upgrade));
    }
}