<?php

namespace Afaya\EdgeTTS\Transport;

use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Exception\HandshakeException;

/**
 * A blocking WebSocket client (RFC 6455) with just what the service needs.
 * It runs no event loop, so it works inside any application.
 *
 * @internal
 */
final class WebSocket
{
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    // Close code of a connection that ended as it should.
    public const NORMAL_CLOSURE = 1000;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private $read;

    /** @var resource|null */
    private $write;

    private float $timeout;

    /**
     * @param resource $read stream the frames of the server come from
     * @param resource|null $write stream the frames of the client go to; $read when null
     */
    public function __construct($read, $write = null, float $timeout = 30.0)
    {
        $this->read = $read;
        $this->write = $write ?? $read;
        $this->timeout = $timeout;
    }

    /**
     * Opens a connection to a ws:// or wss:// URL.
     *
     * @param array<string, string> $headers
     * @throws HandshakeException when the server answers anything but 101
     */
    public static function connect(string $url, array $headers, float $timeout = 30.0): self
    {
        $target = Http::target($url, ['ws', 'wss']);
        $stream = Http::open($target['secure'], $target['host'], $target['port'], $timeout);
        $socket = new self($stream, null, $timeout);

        $key = base64_encode(random_bytes(16));
        $request = "GET {$target['target']} HTTP/1.1\r\n"
            . "Host: {$target['hostHeader']}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }

        try {
            Http::writeAll($stream, $request . "\r\n");
            [$status, $responseHeaders] = Http::readHead($stream, $target['host'], $timeout);
        } catch (EdgeTTSException $e) {
            $socket->drop();
            throw $e;
        }
        if ($status !== 101) {
            $socket->drop();
            throw new HandshakeException($status, $responseHeaders);
        }
        if (($responseHeaders['sec-websocket-accept'] ?? '') !== base64_encode(sha1($key . self::GUID, true))) {
            $socket->drop();
            throw new EdgeTTSException('Invalid Sec-WebSocket-Accept in the handshake response');
        }
        return $socket;
    }

    public function send(string $payload, int $opcode = self::TEXT): void
    {
        if ($this->write === null) {
            throw new EdgeTTSException('The connection is closed');
        }
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('NN', 0, $length);
        }
        // Clients must mask what they send.
        $mask = random_bytes(4);
        $frame .= $mask;
        if ($length > 0) {
            $frame .= $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
        }
        Http::writeAll($this->write, $frame);
    }

    /**
     * Reads the next message, joining fragmented ones and answering pings.
     *
     * @return array{0: int, 1: string} the opcode (TEXT, BINARY or CLOSE) and
     *     the payload; a CLOSE payload holds the close code and the reason
     */
    public function receive(): array
    {
        if ($this->read === null) {
            throw new EdgeTTSException('The connection is closed');
        }
        $type = null;
        $message = '';
        while (true) {
            $head = $this->readExactly(2);
            $first = ord($head[0]);
            $second = ord($head[1]);
            $opcode = $first & 0x0F;
            $length = $second & 0x7F;
            if ($length === 126) {
                $length = unpack('n', $this->readExactly(2))[1];
            } elseif ($length === 127) {
                $parts = unpack('Nhigh/Nlow', $this->readExactly(8));
                $length = $parts['high'] * 4294967296 + $parts['low'];
            }
            $mask = ($second & 0x80) !== 0 ? $this->readExactly(4) : '';
            $payload = $length > 0 ? $this->readExactly($length) : '';
            if ($mask !== '' && $length > 0) {
                $payload ^= substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
            }

            if ($opcode === self::PING) {
                $this->send($payload, self::PONG);
                continue;
            }
            if ($opcode === self::PONG) {
                continue;
            }
            if ($opcode === self::CLOSE) {
                return [self::CLOSE, $payload];
            }
            if ($opcode !== 0) {
                $type = $opcode;
            }
            $message .= $payload;
            if (($first & 0x80) !== 0) {
                return [$type ?? self::TEXT, $message];
            }
        }
    }

    /**
     * Splits the payload of a close frame into its code and its reason.
     *
     * @return array{0: int|null, 1: string}
     */
    public static function closeReason(string $payload): array
    {
        if (strlen($payload) < 2) {
            return [null, ''];
        }
        return [unpack('n', $payload)[1], (string) substr($payload, 2)];
    }

    /**
     * Sends a close frame and closes the stream. Safe to call twice.
     */
    public function close(): void
    {
        if ($this->write === null) {
            return;
        }
        try {
            $this->send(pack('n', self::NORMAL_CLOSURE), self::CLOSE);
        } catch (EdgeTTSException $e) {
            // The other side is gone already.
        }
        $this->drop();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Closes the streams without saying goodbye.
     */
    private function drop(): void
    {
        if ($this->read !== $this->write && is_resource($this->read)) {
            fclose($this->read);
        }
        if (is_resource($this->write)) {
            fclose($this->write);
        }
        $this->read = $this->write = null;
    }

    private function readExactly(int $length): string
    {
        try {
            return Http::readExactly($this->read, $length, $this->timeout);
        } catch (EdgeTTSException $e) {
            // Nothing more can be read; do not try to say goodbye either.
            $this->drop();
            throw $e;
        }
    }
}
