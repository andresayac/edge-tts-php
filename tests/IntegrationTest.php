<?php

namespace Afaya\EdgeTTS\Tests;

use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Exception\HandshakeException;
use Afaya\EdgeTTS\Service\Protocol;
use Afaya\EdgeTTS\Tests\Fake\FakeEdgeTTS;
use Afaya\EdgeTTS\Tests\Fake\LocalEdgeTTS;
use Afaya\EdgeTTS\Transport\WebSocket;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real socket code against the local server of Fake/server.php.
 */
class IntegrationTest extends TestCase
{
    /** @var resource */
    private static $process;

    /** @var resource[] */
    private static array $pipes = [];

    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fake/server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            self::$pipes
        );
        self::$port = (int) fgets(self::$pipes[1]);
        if (self::$port === 0) {
            self::fail('The local server did not start: ' . stream_get_contents(self::$pipes[2]));
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$process);
        foreach (self::$pipes as $pipe) {
            fclose($pipe);
        }
        proc_close(self::$process);
    }

    protected function tearDown(): void
    {
        Protocol::resetClock();
    }

    public function testSynthesizesOverARealSocket(): void
    {
        $tts = new LocalEdgeTTS(self::$port, 'replay');
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->assertSame(FakeEdgeTTS::recordedAudio(), $tts->toRaw());
        $this->assertCount(7, $tts->getWordBoundaries());
    }

    public function testStreamsOverARealSocket(): void
    {
        $audio = '';
        foreach ((new LocalEdgeTTS(self::$port, 'replay'))->stream('hi', 'en-US-AriaNeural') as $chunk) {
            $audio .= $chunk;
        }
        $this->assertSame(FakeEdgeTTS::recordedAudio(), $audio);
    }

    public function testAnswersAPingInTheMiddleOfASynthesis(): void
    {
        $tts = new LocalEdgeTTS(self::$port, 'ping');
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->assertSame(FakeEdgeTTS::recordedAudio(), $tts->toRaw());
    }

    public function testReportsTheReasonTheServiceGivesForRefusing(): void
    {
        try {
            (new LocalEdgeTTS(self::$port, 'refuse-voice'))->synthesize('hi', 'xx-XX-NobodyNeural');
            $this->fail('no exception');
        } catch (EdgeTTSException $e) {
            $this->assertSame('The service refused the request: Unsupported voice xx-XX-NobodyNeural.', $e->getMessage());
            $this->assertSame(1007, $e->getCode());
        }
    }

    public function testThrowsWhenTheHandshakeIsRefused(): void
    {
        try {
            (new LocalEdgeTTS(self::$port, 'forbidden'))->synthesize('hi', 'en-US-AriaNeural');
            $this->fail('no exception');
        } catch (HandshakeException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertArrayHasKey('date', $e->getHeaders());
        }
    }

    public function testTimesOutWhenTheServiceIsSilent(): void
    {
        $started = microtime(true);
        try {
            (new LocalEdgeTTS(self::$port, 'silent', 0.5))->synthesize('hi', 'en-US-AriaNeural');
            $this->fail('no exception');
        } catch (EdgeTTSException $e) {
            $this->assertStringContainsString('No response from the service for 0.5s', $e->getMessage());
        }
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function testTimesOutWhenTheHandshakeGetsNoAnswer(): void
    {
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('No response from 127.0.0.1 in 0.5s');
        (new LocalEdgeTTS(self::$port, 'no-handshake', 0.5))->synthesize('hi', 'en-US-AriaNeural');
    }

    public function testRejectsAWrongHandshakeAccept(): void
    {
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('Invalid Sec-WebSocket-Accept');
        (new LocalEdgeTTS(self::$port, 'bad-accept'))->synthesize('hi', 'en-US-AriaNeural');
    }

    public function testReportsAServerThatIsNotThere(): void
    {
        // Port 1 is reserved and nothing listens on it.
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('Could not connect to 127.0.0.1:1');
        WebSocket::connect('ws://127.0.0.1:1/', [], 2.0);
    }

    /**
     * @dataProvider voiceListResponses
     */
    public function testReadsTheVoiceListInEveryShape(string $route): void
    {
        $voices = (new LocalEdgeTTS(self::$port, $route))->getVoices();
        $this->assertSame(['en-US-AriaNeural', 'es-CO-SalomeNeural'], array_column($voices, 'ShortName'));
    }

    public function voiceListResponses(): array
    {
        return [
            'chunked' => ['chunked'],
            'content length' => ['length'],
            'until the end' => ['eof'],
            'gzip' => ['gzip'],
        ];
    }

    public function testReportsARefusedVoiceList(): void
    {
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('status 403');
        (new LocalEdgeTTS(self::$port, 'forbidden'))->getVoices();
    }
}
