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
     * @var resource
     */
    protected $socket;
    /**
     * @var resource[] key as client hash
     */
    protected $clients;

    /**
     * ArkWebSocketDaemon constructor.
     * @param string $host
     * @param int $port
     * @param string $servicePath
     * @param ArkWebSocketWorker $worker
     * @param ArkLogger|null $logger
     */
    public function __construct($host, $port,$servicePath,$worker,$logger=null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->worker = $worker;
        $this->servicePath = $servicePath;
        $this->logger=$logger;
    }

    /**
     * @throws Exception
     */
    protected function reset()
    {
        try {
            //Create TCP/IP stream socket
            $this->logger->debug('creating socket...');
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($this->socket === false) {
                throw new Exception('cannot create socket');
            }
            //reusable port
            $this->logger->debug('setting socket as address-reusable...');
            $done=socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
            if(!$done){
                throw new Exception('cannot set socket reusable');
            }
            //bind socket to specified host
            $this->logger->debug('binding socket to address and port...');
            $done=socket_bind($this->socket, 0, $this->port);
            if(!$done){
                throw new Exception('cannot bind socket to port');
            }
            //listen to port
            $this->logger->debug('socket is ready to listen...');
            $done=socket_listen($this->socket);
            if(!$done){
                throw new Exception('cannot listen to port');
            }
            //create & add listening socket to the list
            //$this->clients = array($this->socket);
            $this->registerNewClient($this->socket);
            $this->logger->info('socket listening started, client set updated',['total'=>count($this->clients),'clients'=>$this->clients]);
        }catch (Exception $exception){
            $error_code=socket_last_error();
            $error_string=socket_strerror($error_code);
            $this->logger->error($exception->getMessage(),['error_code'=>$error_code,'error_string'=>$error_string]);
            throw new Exception($exception->getMessage().', error ['.$error_code.'] '.$error_string);
        }
    }

    /**
     * @param resource $socket
     */
    protected function registerNewClient($socket){
        $hash=self::getClientHash($socket);
        $this->clients[$hash]=$socket;
        $this->logger->info('registered new client',['hash'=>$hash,'socket'=>intval($socket)]);
    }

    /**
     * @return resource[]
     */
    private function getChangedSockets(){
        $changed = $this->clients;
        $selected=socket_select($changed, $null, $null, 0, 10);
        $this->logger->debug('readable sockets selected out',['selected'=>$selected,'total'=>count($changed)]);
        foreach ($changed as $key=>$value){
            $this->logger->debug('changed socket iterator',['key'=>$key,'value'=>intval($value)]);
        }
        return $changed;
    }

    /**
     * @param resource[] $changed
     */
    private function handleNewConnection(&$changed)
    {
        $this->logger->info('a new client came up');
        $socket_new = socket_accept($this->socket); //accept new socket

        $header = socket_read($socket_new, 1024); //read data sent by the socket
        self::perform_handshaking($header, $socket_new, $this->host, $this->port); //perform websocket handshake

        $client_hash=self::getClientHash($socket_new);
        $this->logger->info('new client identified', ['hash'=>$client_hash]);

        $this->registerNewClient($socket_new);//add socket to client array

        //plugin
        $this->logger->debug('let worker handle new socket...');
        $response = $this->worker
            ->setClientCount(count($this->clients))
            ->processNewSocket($client_hash, $header);

        // send
        $response = self::mask($response); //prepare json data
        $this->broadcastMessage($response); //notify all users about new connection

        $this->logger->debug('broadcast this news');

        // make room for new socket
        $found_socket = array_search($this->socket, $changed);
        unset($changed[$found_socket]);
    }

    /**
     * @param resource $changed_socket
     */
    private function readSocket($changed_socket){
        $client_hash=self::getClientHash($changed_socket);
        $this->logger->debug('begin reading socket',['hash'=>$client_hash,'socket'=>intval($changed_socket)]);
        $buffer=null;
        while(true){
            $readBytes=socket_recv($changed_socket,$bufferPiece,10240,0);
            if($readBytes===false){
                $this->logger->warning('socket_recv get false');
                break;
            }
            if($readBytes===0){
                $this->logger->warning('socket_recv get empty data');
                break;
            }
            if($buffer===null)$buffer='';
            $buffer.=$bufferPiece;

            $this->logger->debug('read piece',['piece_length'=>strlen($bufferPiece),'total_length'=>strlen($buffer)]);
        }
        if($buffer!==null) {
            $received_text = self::unmask($buffer); //unmask data

            $this->logger->debug('read with something',['text'=>$received_text]);

            // plugin
            $response_text = $this->worker
                ->setClientCount(count($this->clients))
                ->processReadMessage($client_hash, $received_text);

            $response_text = self::mask($response_text);
            $this->broadcastMessage($response_text); //send data
        }else{
            $this->logger->debug('read without anything, try to check if died');
            $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
            if ($buf === false) { // check disconnected client
                $this->logger->warning('socket seems died',['hash'=>$client_hash]);
                // remove client for $this->clients array
                $found_socket = array_search($changed_socket, $this->clients);
                unset($this->clients[$found_socket]);

                //notify all users about disconnected connection

                //plugin
                $response = $this->worker
                    ->setClientCount(count($this->clients))
                    ->processCloseSocket($client_hash);

                $response = self::mask($response);
                $this->broadcastMessage($response);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function loop(){
        $this->reset();

        $this->logger->info('daemon loop running...');
        while(true){
            $changed=$this->getChangedSockets();
            //check for new socket
            if (in_array($this->socket, $changed)) {
                $this->handleNewConnection($changed);
            }

            //loop through all connected sockets
            foreach ($changed as $changed_socket) {
                $this->readSocket($changed_socket);
            }
        }
        $this->logger->info('daemon loop ending (this line would never be printed)');
        socket_close($this->socket);
    }

    /**
     * @param string $msg
     */
    protected function broadcastMessage($msg)
    {
        foreach ($this->clients as $socketHash=>$changed_socket) {
            $written=@socket_write($changed_socket, $msg, strlen($msg));
            $this->logger->debug('broadcast message, written to socket '.intval($changed_socket),['hash'=>$socketHash,'written'=>$written]);
        }
    }

    /**
     * @param resource $socket
     * @return string|false
     */
    protected static function getClientHash($socket){
        $done=socket_getpeername($socket, $ip,$port); //get ip address of connected socket
        return $done ? ($ip.':'.$port) : false;
    }

    /**
     * @param string $received_header
     * @param resource $client_conn
     * @param string $host
     * @param string $servicePath
     */
    protected static function perform_handshaking($received_header, $client_conn, $host, $servicePath)
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
            "Sec-WebSocket-Origin: {$host}\r\n" .
            "Sec-WebSocket-Location: {$servicePath}\r\n" .
            "Sec-WebSocket-Accept:{$secAccept}\r\n\r\n";
        socket_write($client_conn, $upgrade, strlen($upgrade));
    }

    /**
     * @param string $text
     * @return string
     */
    protected static function mask($text)
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
    protected static function unmask($text)
    {
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