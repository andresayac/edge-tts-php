<?php

namespace Afaya\EdgeTTS\Tests\Fake;

use Afaya\EdgeTTS\Service\EdgeTTS;

/**
 * An EdgeTTS that sends its real requests to the local server of
 * server.php, through the wssUrl and voicesUrl options.
 */
class LocalEdgeTTS extends EdgeTTS
{
    public function __construct(int $port, string $route, float $timeout = 5.0, array $options = [])
    {
        parent::__construct($options + [
            'wssUrl' => "ws://127.0.0.1:{$port}/ws/{$route}",
            'voicesUrl' => "http://127.0.0.1:{$port}/voices/{$route}",
            'timeout' => $timeout,
        ]);
    }
}
