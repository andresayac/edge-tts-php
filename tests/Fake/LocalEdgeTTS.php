<?php

namespace Afaya\EdgeTTS\Tests\Fake;

use Afaya\EdgeTTS\Service\EdgeTTS;
use Afaya\EdgeTTS\Transport\WebSocket;

/**
 * An EdgeTTS that sends its real requests to the local server of
 * server.php instead of the service.
 */
class LocalEdgeTTS extends EdgeTTS
{
    private string $wssUrl;
    private string $voicesUrl;

    public function __construct(int $port, string $route, float $timeout = 5.0)
    {
        $this->wssUrl = "ws://127.0.0.1:{$port}/ws/{$route}";
        $this->voicesUrl = "http://127.0.0.1:{$port}/voices/{$route}";
        $this->timeout = $timeout;
    }

    protected function connect(string $url, array $headers): WebSocket
    {
        return parent::connect($this->wssUrl . '?' . parse_url($url, PHP_URL_QUERY), $headers);
    }

    protected function httpGet(string $url, array $headers): array
    {
        return parent::httpGet($this->voicesUrl . '?' . parse_url($url, PHP_URL_QUERY), $headers);
    }
}
