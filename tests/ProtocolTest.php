<?php

namespace Afaya\EdgeTTS\Tests;

use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Service\Protocol;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProtocolTest extends TestCase
{
    public function testSecMsGecMatchesTheOtherPackages(): void
    {
        // SHA-256 of "134116992000000000" + the client token: the Windows file
        // time of 2026-01-01 00:00:00 UTC, the start of the window of this time.
        $at = gmmktime(0, 4, 59, 1, 1, 2026);
        $this->assertSame('42D1947403FD94975436C65DBFCA8003073F9CB5C3F1CE25AF8961A11D7C3DFE', Protocol::secMsGec($at));
        // Same five minute window, same token.
        $this->assertSame(Protocol::secMsGec($at), Protocol::secMsGec($at - 240));
        $this->assertNotSame(Protocol::secMsGec($at), Protocol::secMsGec($at + 1));
    }

    /**
     * @dataProvider prosody
     * @param int|float|string|null $value
     */
    public function testFormatsTheProsody(string $method, $value, string $expected): void
    {
        $this->assertSame($expected, Protocol::$method($value));
    }

    public function prosody(): array
    {
        return [
            ['formatPitch', null, '+0Hz'],
            ['formatPitch', '', '+0Hz'],
            ['formatPitch', 5, '+5Hz'],
            ['formatPitch', -5, '-5Hz'],
            ['formatPitch', '5', '+5Hz'],
            ['formatPitch', '+10Hz', '+10Hz'],
            ['formatPitch', '-2.5Hz', '-2.5Hz'],
            ['formatPitch', '0hz', '0Hz'],
            ['formatRate', null, '+0%'],
            ['formatRate', '0%', '+0%'],
            ['formatRate', '50%', '+50%'],
            ['formatRate', '-25%', '-25%'],
            ['formatRate', 10, '+10%'],
            ['formatRate', 12.5, '+12.5%'],
            ['formatRate', ' +10 % ', '+10%'],
            ['formatVolume', null, '+0%'],
            ['formatVolume', '90%', '+90%'],
            ['formatVolume', -100, '-100%'],
            ['formatVolume', '+100%', '+100%'],
        ];
    }

    /**
     * @dataProvider invalidProsody
     */
    public function testRejectsInvalidProsody(string $method, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Protocol::$method($value);
    }

    public function invalidProsody(): array
    {
        return [
            ['formatPitch', 'loud'],
            ['formatPitch', '10%'],
            ['formatPitch', '1e3'],
            ['formatRate', 'fast'],
            ['formatRate', 'NaN'],
            ['formatVolume', '101%'],
            ['formatVolume', '-101'],
            ['formatVolume', 'quiet'],
        ];
    }

    public function testBuildsSsmlWithoutCharactersXmlCannotHold(): void
    {
        $ssml = Protocol::ssml("a\x0Bb\x00c 'd' <e>", 'x"y', '+0Hz', '+0%', '+0%');
        $this->assertStringContainsString('<voice name="x&quot;y">', $ssml);
        $this->assertStringContainsString('a b c &apos;d&apos; &lt;e&gt;', $ssml);
        $this->assertNotFalse(simplexml_load_string($ssml));
    }

    public function testParsesTextFrames(): void
    {
        $this->assertSame(['turn.end', '{}'], Protocol::parseTextFrame("X-RequestId:1\r\nPath:turn.end\r\n\r\n{}"));
        $this->assertSame(['', ''], Protocol::parseTextFrame('garbage'));
    }

    public function testParsesBinaryFrames(): void
    {
        $headers = "X-RequestId:1\r\nPath:audio\r\n";
        $this->assertSame(['audio', "\x01\x02\x03"], Protocol::parseBinaryFrame(pack('n', strlen($headers)) . $headers . "\x01\x02\x03"));
        $this->assertSame(['audio', ''], Protocol::parseBinaryFrame(pack('n', strlen($headers)) . $headers));
    }

    public function testRejectsBinaryFramesWithBrokenHeaders(): void
    {
        $this->expectException(EdgeTTSException::class);
        Protocol::parseBinaryFrame("\x00\x09x");
    }

    public function testParsesEveryWordBoundaryOfAMessage(): void
    {
        $body = '{"Metadata":[
            {"Type":"WordBoundary","Data":{"Offset":1,"Duration":2,"text":{"Text":"a"}}},
            {"Type":"SentenceBoundary","Data":{"Offset":1,"Duration":9,"text":{"Text":"a b"}}},
            {"Type":"WordBoundary","Data":{"Offset":3,"Duration":4,"text":{"Text":"b"}}}
        ]}';
        $this->assertSame([
            ['type' => 'WordBoundary', 'offset' => 1, 'duration' => 2, 'text' => 'a'],
            ['type' => 'WordBoundary', 'offset' => 3, 'duration' => 4, 'text' => 'b'],
        ], Protocol::parseWordBoundaries($body));
        $this->assertSame([], Protocol::parseWordBoundaries('not json'));
    }

    public function testNamesTheFileAfterTheFormat(): void
    {
        $cases = [
            'audio-24khz-48kbitrate-mono-mp3' => 'mp3',
            'webm-24khz-16bit-mono-opus' => 'webm',
            'ogg-24khz-16bit-mono-opus' => 'ogg',
            'riff-24khz-16bit-mono-pcm' => 'wav',
            'raw-24khz-16bit-mono-pcm' => 'pcm',
            'raw-8khz-8bit-mono-alaw' => 'alaw',
            'raw-8khz-8bit-mono-mulaw' => 'mulaw',
            'raw-16khz-16bit-mono-truesilk' => 'silk',
            'g722-16khz-64kbps' => 'g722',
            'amr-wb-16000hz' => 'amr',
            'something-else' => 'audio',
        ];
        foreach ($cases as $format => $extension) {
            $this->assertSame($extension, Protocol::fileExtension($format), $format);
        }
    }

    public function testReadsTheBitrateOfTheFormat(): void
    {
        $this->assertSame(96, Protocol::kbps('audio-24khz-96kbitrate-mono-mp3'));
        $this->assertSame(48, Protocol::kbps('webm-24khz-16bit-mono-opus'));
    }

    public function testCorrectsTheClockFromADateHeader(): void
    {
        $this->assertFalse(Protocol::adjustClock([]));
        $this->assertTrue(Protocol::adjustClock(['date' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3600)]));
        $this->assertEqualsWithDelta(time() + 3600, Protocol::now(), 2);
        Protocol::resetClock();
        $this->assertEqualsWithDelta(time(), Protocol::now(), 1);
    }
}
