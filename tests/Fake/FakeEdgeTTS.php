<?php

namespace Afaya\EdgeTTS\Tests\Fake;

use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Exception\HandshakeException;
use Afaya\EdgeTTS\Service\EdgeTTS;
use Afaya\EdgeTTS\Transport\WebSocket;

/**
 * An EdgeTTS that talks to a fake service replaying the recorded session
 * instead of the network.
 */
class FakeEdgeTTS extends EdgeTTS
{
    /** @var array<int, array{text?: string, binary?: string, close?: string}> frames to replay */
    public array $frames;

    /**
     * Returns the exception to refuse a handshake with, or null to accept it.
     *
     * @var (callable(string): ?HandshakeException)|null
     */
    public $accept = null;

    /** @var array<int, array{url: string, headers: array, sent: string}> sent is a file path */
    public array $connections = [];

    /** @var array{0: int, 1: array, 2: string} the voice list response */
    public array $voiceResponse = [200, [], '[]'];

    /** @var array<int, array{url: string, headers: array}> */
    public array $voiceRequests = [];

    public function __construct(?array $frames = null, array $options = [])
    {
        parent::__construct($options);
        $this->frames = $frames ?? self::session()['frames'];
    }

    public static function session(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../fixtures/session.json'), true);
    }

    /**
     * The payload of every binary frame, in order.
     */
    public static function recordedAudio(?array $frames = null): string
    {
        $audio = '';
        foreach ($frames ?? self::session()['frames'] as $frame) {
            if (isset($frame['binary'])) {
                $bytes = base64_decode($frame['binary']);
                $audio .= substr($bytes, 2 + unpack('n', $bytes)[1]);
            }
        }
        return $audio;
    }

    /**
     * Encodes a frame the way a server sends it: unmasked.
     */
    public static function serverFrame(int $opcode, string $payload, bool $fin = true): string
    {
        $length = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | $opcode);
        if ($length < 126) {
            $head .= chr($length);
        } elseif ($length < 65536) {
            $head .= chr(126) . pack('n', $length);
        } else {
            $head .= chr(127) . pack('NN', 0, $length);
        }
        return $head . $payload;
    }

    protected function connect(string $url, array $headers, float $timeout): WebSocket
    {
        if ($this->accept !== null) {
            $refusal = ($this->accept)($url);
            if ($refusal !== null) {
                throw $refusal;
            }
        }

        $server = fopen('php://temp', 'w+b');
        foreach ($this->frames as $frame) {
            if (isset($frame['close'])) {
                fwrite($server, self::serverFrame(WebSocket::CLOSE, $frame['close']));
            } elseif (isset($frame['binary'])) {
                fwrite($server, self::serverFrame(WebSocket::BINARY, base64_decode($frame['binary'])));
            } else {
                fwrite($server, self::serverFrame(WebSocket::TEXT, $frame['text']));
            }
        }
        rewind($server);

        // A file, so what the client sent outlives the stream it closes.
        $sent = tempnam(sys_get_temp_dir(), 'edge-tts-');
        $this->connections[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout, 'sent' => $sent];
        return new WebSocket($server, fopen($sent, 'w+b'), $timeout);
    }

    /**
     * The messages the client sent on connection $i, in order.
     *
     * @return string[]
     */
    public function messages(int $i = 0): array
    {
        $copy = fopen('php://temp', 'w+b');
        fwrite($copy, (string) file_get_contents($this->connections[$i]['sent']));
        rewind($copy);
        $socket = new WebSocket($copy);
        $messages = [];
        try {
            while (true) {
                [$opcode, $payload] = $socket->receive();
                if ($opcode === WebSocket::CLOSE) {
                    break;
                }
                $messages[] = $payload;
            }
        } catch (EdgeTTSException $e) {
            // End of what was sent.
        }
        return $messages;
    }

    /**
     * Whether the client sent a close frame on connection $i.
     */
    public function closed(int $i = 0): bool
    {
        $sent = (string) file_get_contents($this->connections[$i]['sent']);
        // A close frame is the last thing sent: opcode 0x8 with FIN, masked, 2-byte payload.
        return substr($sent, -8, 2) === "\x88\x82";
    }

    protected function httpGet(string $url, array $headers): array
    {
        $this->voiceRequests[] = ['url' => $url, 'headers' => $headers];
        return $this->voiceResponse;
    }

    public function __destruct()
    {
        foreach ($this->connections as $connection) {
            @unlink($connection['sent']);
        }
    }
}
