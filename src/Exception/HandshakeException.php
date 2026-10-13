<?php

namespace Afaya\EdgeTTS\Exception;

/**
 * The service refused the WebSocket connection.
 */
class HandshakeException extends EdgeTTSException
{
    private int $statusCode;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers response headers, with lowercase names
     */
    public function __construct(int $statusCode, array $headers = [])
    {
        parent::__construct("The service refused the connection (HTTP {$statusCode})", $statusCode);
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string> response headers, with lowercase names
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
