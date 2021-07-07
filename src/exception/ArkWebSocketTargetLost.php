<?php


namespace sinri\ark\websocket\exception;


use Throwable;

/**
 * Class ArkWebSocketTargetLost
 * @package sinri\ark\websocket\exception
 * @since 0.1.6
 */
class ArkWebSocketTargetLost extends ArkWebSocketError
{
    /**
     * @var resource
     */
    protected $targetResource;

    /**
     * ArkWebSocketTargetLost constructor.
     * @param resource $targetResource
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct($targetResource, $message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->targetResource = $targetResource;
    }

    /**
     * @return resource
     */
    public function getTargetResource()
    {
        return $this->targetResource;
    }

    /**
     * @return int
     */
    public function getTargetResourceAsInteger(): int
    {
        return intval($this->targetResource);
    }
}