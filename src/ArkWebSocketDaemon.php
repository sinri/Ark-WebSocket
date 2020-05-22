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

        $header = '';
        while (true) {
            $readBytes = socket_recv($socket_new, $headerPiece, 1024, MSG_DONTWAIT);
            if ($readBytes === false) {
                $error_code = socket_last_error();
                $error_text = socket_strerror($error_code);
                $this->logger->error('New Client Cannot read header piece', ['socket' => intval($socket_new), 'error_code' => $error_code, 'error_text' => $error_text]);
                break;
            }
            if ($readBytes === 0) {
                break;
            }
            $header .= $headerPiece;
        }

        $this->perform_handshaking($header, $socket_new); //perform websocket handshake

        $client_hash = $this->connections->getClientHash($socket_new);
        $this->logger->info('new client identified', ['hash' => $client_hash, 'socket' => intval($socket_new)]);

        //add socket to client array
        $this->connections->registerClient($socket_new);

        //plugin
        $this->worker->processNewSocket($client_hash, $header);

        $this->logger->info('new client socket processed');
    }

    /**
     * @param resource $changed_socket
     */
    private function readSocket($changed_socket)
    {
        $client_hash = $this->connections->getClientHash($changed_socket);
        if ($client_hash === false) {
            $this->logger->error(__METHOD__ . ' the socket to read is not valid', ['socket' => intval($changed_socket), 'hash' => $client_hash]);
            return;
        }
        $this->logger->debug(__METHOD__ . ' begin reading socket', ['hash' => $client_hash, 'socket' => intval($changed_socket)]);
        $buffer = '';
        try {
            while (true) {
                // @since 0.0.5 not block it
                $readBytes = socket_recv($changed_socket, $bufferPiece, 1024, MSG_DONTWAIT);
                if ($readBytes === false) {
                    // when buffer read out, false would return with error: resource temporary not available
                    if (strlen($buffer) === 0) {
                        $error_code = socket_last_error();
                        $error_text = socket_strerror($error_code);
                        $this->logger->error(
                            __METHOD__ . ' socket_recv got false, seems died',
                            [
                                'hash' => $client_hash,
                                'client' => intval($changed_socket),
                                'error_code' => $error_code,
                                'error_text' => $error_text
                            ]
                        );
                        $this->connections->removeClient($changed_socket);
                        //plugin
                        $this->worker->processCloseSocket($client_hash);
                        throw new Exception(__METHOD__ . " socket_recv got false, seems died.", intval($changed_socket));
                    }
                    break;
                }
                if ($readBytes === 0) {
                    break;
                }
                $buffer .= $bufferPiece;

                $this->logger->debug(__METHOD__ . ' read piece', ['hash' => $client_hash, 'client' => intval($changed_socket), 'piece_length' => strlen($bufferPiece), 'total_length' => strlen($buffer)]);
            }

            // plugin
            $this->worker->processReadMessage($client_hash, $buffer);
        } catch (Exception $exception) {
            // bye bye
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

                foreach ($changed as $changed_socket) {
                    if (false == $this->connections->getClientHash($changed_socket)) {
                        if (is_resource($changed_socket)) socket_shutdown($changed_socket);
                        continue;
                    }
                    if ($changed_socket === $this->connections->getSocket()) {
                        $this->handleNewConnection($changed);
                    } else {
                        $this->readSocket($changed_socket);
                    }
                }
            }
        } catch (Exception $exception) {
            $error_code = socket_last_error();
            $error_string = socket_strerror($error_code);
            $this->logger->error(__METHOD__ . ' ' . $exception->getMessage(), ['error_code' => $error_code, 'error_string' => $error_string]);
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