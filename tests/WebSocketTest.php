<?php

namespace Afaya\EdgeTTS\Tests;

use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Tests\Fake\FakeEdgeTTS;
use Afaya\EdgeTTS\Transport\Http;
use Afaya\EdgeTTS\Transport\WebSocket;
use PHPUnit\Framework\TestCase;

class WebSocketTest extends TestCase
{
    /**
     * @return resource
     */
    private static function stream(string $content = '')
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    /**
     * @dataProvider sizes
     */
    public function testReadsBackWhatItSendsAtEverySize(int $size): void
    {
        $payload = $size > 0 ? random_bytes($size) : '';
        $out = self::stream();
        // Kept in a variable: destroying the socket closes $out.
        $client = new WebSocket(self::stream(), $out);
        $client->send($payload, WebSocket::BINARY);

        rewind($out);
        $sent = stream_get_contents($out);
        $this->assertNotSame(0, ord($sent[1]) & 0x80, 'client frames must be masked');

        $this->assertSame([WebSocket::BINARY, $payload], (new WebSocket(self::stream($sent)))->receive());
    }

    public function sizes(): array
    {
        return ['empty' => [0], 'short' => [125], '16-bit length' => [126], 'long 16-bit' => [65535], '64-bit length' => [65536]];
    }

    public function testJoinsFragmentedMessages(): void
    {
        $frames = FakeEdgeTTS::serverFrame(WebSocket::TEXT, 'Hel', false)
            . FakeEdgeTTS::serverFrame(0x0, 'lo', true);
        $this->assertSame([WebSocket::TEXT, 'Hello'], (new WebSocket(self::stream($frames)))->receive());
    }

    public function testAnswersPingsWithPongs(): void
    {
        $frames = FakeEdgeTTS::serverFrame(WebSocket::PING, 'beat') . FakeEdgeTTS::serverFrame(WebSocket::TEXT, 'after');
        $out = self::stream();
        $client = new WebSocket(self::stream($frames), $out);
        $this->assertSame([WebSocket::TEXT, 'after'], $client->receive());

        rewind($out);
        $this->assertSame([WebSocket::PONG, 'beat'], self::rawFrame(self::stream((string) stream_get_contents($out))));
    }

    /**
     * receive() hides pongs, so read the first frame by hand.
     *
     * @param resource $read
     */
    private static function rawFrame($read): array
    {
        $head = fread($read, 2);
        $length = ord($head[1]) & 0x7F;
        $mask = fread($read, 4);
        $payload = fread($read, $length) ^ substr(str_repeat($mask, 2), 0, $length);
        return [ord($head[0]) & 0x0F, $payload];
    }

    public function testReportsTheCloseOfTheServer(): void
    {
        $frames = FakeEdgeTTS::serverFrame(WebSocket::CLOSE, pack('n', 1000));
        $this->assertSame([WebSocket::CLOSE, pack('n', 1000)], (new WebSocket(self::stream($frames)))->receive());
    }

    public function testThrowsWhenTheConnectionEndsMidFrame(): void
    {
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('closed the connection');
        (new WebSocket(self::stream("\x82\x05ab")))->receive();
    }

    public function testRejectsUrlsThatAreNotWebSockets(): void
    {
        $this->expectException(EdgeTTSException::class);
        WebSocket::connect('https://example.com', []);
    }

    public function testReadsTheLastResponseOfARedirect(): void
    {
        [$status, $headers] = Http::parseHeaders([
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com/',
            'HTTP/1.1 200 OK',
            'Content-Type: application/json',
        ]);
        $this->assertSame(200, $status);
        $this->assertSame(['content-type' => 'application/json'], $headers);
    }
}
