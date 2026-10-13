<?php

namespace Afaya\EdgeTTS\Tests;

use Afaya\EdgeTTS\Config\Constants;
use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Exception\EmptyAudioException;
use Afaya\EdgeTTS\Exception\HandshakeException;
use Afaya\EdgeTTS\Service\EdgeTTS;
use Afaya\EdgeTTS\Service\Protocol;
use Afaya\EdgeTTS\Tests\Fake\FakeEdgeTTS;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EdgeTTSTest extends TestCase
{
    private const WORDS = ['Hello', 'world', 'Is', 'this', 'a', 'test', 'Yes'];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/edge-tts-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        rmdir($this->tmp);
        Protocol::resetClock();
    }

    private static function words(array $boundaries): array
    {
        return array_column($boundaries, 'text');
    }

    public function testSynthesizeKeepsTheAudioOfEveryBinaryFrame(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize(FakeEdgeTTS::session()['text'], 'en-US-AriaNeural');
        $this->assertSame(FakeEdgeTTS::recordedAudio(), $tts->toRaw());
    }

    public function testSynthesizeCollectsTheWordBoundaries(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $words = $tts->getWordBoundaries();
        $this->assertSame(self::WORDS, self::words($words));
        $this->assertSame(['type' => 'WordBoundary', 'offset' => 500000, 'duration' => 4500000, 'text' => 'Hello'], $words[0]);
    }

    public function testConnectsWithTheTokenTheSignatureAndEdgeHeaders(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $connection = $tts->connections[0];
        parse_str((string) parse_url($connection['url'], PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(Constants::WSS_URL . '?', $connection['url']);
        $this->assertSame(Constants::TRUSTED_CLIENT_TOKEN, $query['Ocp-Apim-Subscription-Key']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $query['Sec-MS-GEC']);
        $this->assertSame(Constants::SEC_MS_GEC_VERSION, $query['Sec-MS-GEC-Version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $query['ConnectionId']);
        $this->assertStringContainsString('Edg/', $connection['headers']['User-Agent']);
        $this->assertStringStartsWith('chrome-extension://', $connection['headers']['Origin']);
    }

    public function testSendsTheConfigThenTheEscapedSsmlWithTheProsody(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('a < b & "c"', 'es-CO-SalomeNeural', ['pitch' => '+5Hz', 'rate' => 10, 'volume' => '-20%']);
        [$config, $ssml] = $tts->messages();

        $this->assertStringContainsString('Path:speech.config', $config);
        $this->assertStringContainsString('"outputFormat":"audio-24khz-48kbitrate-mono-mp3"', $config);
        $this->assertStringContainsString('Path:ssml', $ssml);
        $this->assertStringContainsString('<voice name="es-CO-SalomeNeural">', $ssml);
        $this->assertStringContainsString('<prosody pitch="+5Hz" rate="+10%" volume="-20%">', $ssml);
        $this->assertStringContainsString('a &lt; b &amp; &quot;c&quot;', $ssml);
    }

    public function testPassesTheOutputFormatAndNamesTheFileAfterIt(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural', ['outputFormat' => Constants::OUTPUT_FORMAT['WEBM_24KHZ_16BIT_MONO_OPUS']]);
        $this->assertStringContainsString('"outputFormat":"webm-24khz-16bit-mono-opus"', $tts->messages()[0]);

        $path = $tts->toFile($this->tmp . '/out');
        $this->assertSame($this->tmp . '/out.webm', $path);
        $this->assertSame(FakeEdgeTTS::recordedAudio(), file_get_contents($path));
        $this->assertSame($this->tmp . '/out.bin', $tts->toFile($this->tmp . '/out', 'bin'));
    }

    public function testExportsBase64StreamAndAudioInfo(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');

        $this->assertSame(base64_encode(FakeEdgeTTS::recordedAudio()), $tts->toBase64());
        $this->assertSame(FakeEdgeTTS::recordedAudio(), stream_get_contents($tts->toStream()));
        $this->assertSame(736, $tts->getSizeBytes());
        // 736 bytes at 48 kbit/s.
        $this->assertEqualsWithDelta(0.122666, $tts->getDuration(), 0.000001);
        $this->assertSame(['size' => 736, 'format' => 'mp3', 'estimatedDuration' => $tts->getDuration()], $tts->getAudioInfo());
    }

    public function testStartsFromScratchOnEverySynthesis(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->assertSame(736, $tts->getSizeBytes());
        $this->assertSame(self::WORDS, self::words($tts->getWordBoundaries()));
    }

    public function testStreamsTheSameAudioWithACallback(): void
    {
        $tts = new FakeEdgeTTS();
        $streamed = '';
        $tts->synthesizeStream('hi', 'en-US-AriaNeural', [], function (string $chunk) use (&$streamed): void {
            $streamed .= $chunk;
        });
        $this->assertSame(FakeEdgeTTS::recordedAudio(), $streamed);
        $this->assertSame(736, $tts->getSizeBytes());
        $this->assertSame(self::WORDS, self::words($tts->getWordBoundaries()));
        $this->assertTrue($tts->closed());
    }

    public function testStreamsTheSameAudioWithAGenerator(): void
    {
        $tts = new FakeEdgeTTS();
        $streamed = '';
        foreach ($tts->stream('hi', 'en-US-AriaNeural') as $chunk) {
            $streamed .= $chunk;
        }
        $this->assertSame(FakeEdgeTTS::recordedAudio(), $streamed);
    }

    public function testClosesTheConnectionWhenTheLoopBreaks(): void
    {
        $tts = new FakeEdgeTTS();
        $chunks = 0;
        foreach ($tts->stream('hi', 'en-US-AriaNeural') as $chunk) {
            $chunks++;
            break;
        }
        $this->assertSame(1, $chunks);
        gc_collect_cycles();
        $this->assertTrue($tts->closed());
    }

    public function testThrowsWhenTheHandshakeIsRefused(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->accept = function (): HandshakeException {
            return new HandshakeException(403);
        };
        try {
            $tts->synthesize('hi', 'en-US-AriaNeural');
            $this->fail('no exception');
        } catch (HandshakeException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('403', $e->getMessage());
        }
    }

    public function testCorrectsTheClockFromARefusal(): void
    {
        // The service is an hour ahead and only takes signatures for its own time.
        $ahead = time() + 3600;
        $tts = new FakeEdgeTTS();
        $tts->accept = function (string $url) use ($ahead): ?HandshakeException {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            return $query['Sec-MS-GEC'] === Protocol::secMsGec($ahead)
                ? null
                : new HandshakeException(403, ['date' => gmdate('D, d M Y H:i:s \G\M\T', $ahead)]);
        };
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->assertCount(1, $tts->connections);
    }

    /**
     * @dataProvider invalidOptions
     */
    public function testRejectsAnInvalidPitchRateOrVolume(array $options, string $message): void
    {
        $tts = new FakeEdgeTTS();
        try {
            $tts->synthesize('hi', 'v', $options);
            $this->fail('no exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertCount(0, $tts->connections);
    }

    public function invalidOptions(): array
    {
        return [
            'pitch' => [['pitch' => 'loud'], 'Invalid pitch'],
            'rate' => [['rate' => 'fast'], 'Invalid rate'],
            'volume' => [['volume' => '500%'], 'Invalid volume'],
        ];
    }

    public function testReportsTheVoiceThatCannotReadTheText(): void
    {
        $frames = array_values(array_filter(FakeEdgeTTS::session()['frames'], function (array $frame): bool {
            return isset($frame['text']);
        }));
        // One empty audio frame, as the service sends for an unreadable text.
        array_unshift($frames, ['binary' => base64_encode("\x00\x0cPath:audio\r\n")]);

        $this->expectException(EmptyAudioException::class);
        (new FakeEdgeTTS($frames))->synthesize('这是一个测试', 'en-US-AriaNeural');
    }

    public function testRejectsAnEmptyTextWithoutConnecting(): void
    {
        $tts = new FakeEdgeTTS();
        foreach (['', "  \n\t "] as $text) {
            try {
                $tts->synthesize($text, 'en-US-AriaNeural');
                $this->fail('no exception');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('The text is empty.', $e->getMessage());
            }
        }
        $this->assertCount(0, $tts->connections);
    }

    public function testReportsTheReasonTheServiceGivesForClosing(): void
    {
        $frames = [
            FakeEdgeTTS::session()['frames'][0],
            ['close' => pack('n', 1007) . 'Unsupported output format: foo-bar.'],
        ];
        try {
            (new FakeEdgeTTS($frames))->synthesize('hi', 'en-US-AriaNeural', ['outputFormat' => 'foo-bar']);
            $this->fail('no exception');
        } catch (EdgeTTSException $e) {
            $this->assertSame('The service refused the request: Unsupported output format: foo-bar.', $e->getMessage());
            $this->assertSame(1007, $e->getCode());
        }
    }

    public function testTreatsANormalCloseAsTheEnd(): void
    {
        $frames = array_filter(FakeEdgeTTS::session()['frames'], function (array $frame): bool {
            return strpos($frame['text'] ?? '', 'Path:turn.end') === false;
        });
        $frames[] = ['close' => pack('n', 1000)];
        $tts = new FakeEdgeTTS(array_values($frames));
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->assertSame(736, $tts->getSizeBytes());
    }

    public function testReportsAFolderThatDoesNotExist(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write the audio to ' . $this->tmp . '/missing/out.mp3: ');
        $tts->toFile($this->tmp . '/missing/out');
    }

    public function testReportsAConnectionClosedBeforeTheEnd(): void
    {
        $frames = array_slice(FakeEdgeTTS::session()['frames'], 0, 3);
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('closed the connection');
        (new FakeEdgeTTS($frames))->synthesize('hi', 'en-US-AriaNeural');
    }

    public function testThrowsBeforeAnySynthesis(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No audio data available. Did you run synthesize() first?');
        (new EdgeTTS())->toRaw();
    }

    public function testSavesTheMetadataAsJson(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $tts->saveMetadata($this->tmp . '/meta.json');

        $saved = json_decode(file_get_contents($this->tmp . '/meta.json'), true);
        $this->assertSame($tts->getWordBoundaries(), $saved);
    }

    private const SRT = "1\n00:00:00,050 --> 00:00:02,013\nHello, world.\n\n"
        . "2\n00:00:02,013 --> 00:00:03,913\nIs this a test?\n\n"
        . "3\n00:00:03,913 --> 00:00:05,400\nYes!\n";

    public function testCollectsTheSentenceBoundariesAndWritesSubtitles(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize(FakeEdgeTTS::session()['text'], 'en-US-AriaNeural');

        $this->assertSame(['Hello, world.', 'Is this a test?', 'Yes!'], self::words($tts->getSentenceBoundaries()));
        $this->assertSame(self::WORDS, self::words($tts->getWordBoundaries()));
        $this->assertSame(self::SRT, $tts->toSRT());
        $this->assertStringStartsWith("WEBVTT\n\n00:00:00.050 --> 00:00:02.013\nHello, world.\n", $tts->toVTT());
        $this->assertStringContainsString('"sentenceBoundaryEnabled":true', $tts->messages()[0]);
    }

    public function testGenerateReturnsEverythingWithoutTouchingTheInstance(): void
    {
        $tts = new FakeEdgeTTS();
        $result = $tts->generate(FakeEdgeTTS::session()['text'], 'en-US-AriaNeural', ['outputFormat' => 'webm-24khz-16bit-mono-opus']);

        $this->assertSame(FakeEdgeTTS::recordedAudio(), $result->audio);
        $this->assertSame('webm', $result->format);
        $this->assertSame('webm-24khz-16bit-mono-opus', $result->outputFormat);
        $this->assertSame(self::WORDS, self::words($result->wordBoundaries));
        $this->assertCount(3, $result->sentenceBoundaries);
        $this->assertEqualsWithDelta(0.122666, $result->duration, 0.000001);
        $this->assertSame(self::SRT, $result->toSRT());
        $this->assertSame($this->tmp . '/gen.webm', $result->toFile($this->tmp . '/gen'));

        $this->assertSame([], $tts->getWordBoundaries());
        $this->expectException(RuntimeException::class);
        $tts->toRaw();
    }

    public function testDoesNotRepeatTheExtensionOfTheFile(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $this->assertSame($this->tmp . '/speech.mp3', $tts->toFile($this->tmp . '/speech.mp3'));
    }

    public function testUsesTheConstructorOptions(): void
    {
        $tts = new FakeEdgeTTS(null, [
            'wssUrl' => 'wss://example.com/tts',
            'secMsGecVersion' => '1-999.0.0.0',
            'headers' => ['X-Test' => 'yes', 'User-Agent' => 'custom'],
            'timeout' => 12,
        ]);
        $tts->synthesize('hi', 'en-US-AriaNeural');
        $tts->synthesize('hi', 'en-US-AriaNeural', ['timeout' => 3]);

        [$first, $second] = $tts->connections;
        parse_str((string) parse_url($first['url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('wss://example.com/tts?', $first['url']);
        $this->assertSame('1-999.0.0.0', $query['Sec-MS-GEC-Version']);
        $this->assertSame('yes', $first['headers']['X-Test']);
        $this->assertSame('custom', $first['headers']['User-Agent']);
        $this->assertSame(12.0, $first['timeout']);
        $this->assertSame(3.0, $second['timeout']);
    }

    public function testRejectsAnUnknownOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option wssURL');
        new EdgeTTS(['wssURL' => 'wss://example.com']);
    }

    public function testCachesTheVoiceList(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [200, [], self::RAW_VOICES];
        $tts->getVoices();
        $tts->getVoicesByLanguage('es');
        $this->assertCount(1, $tts->voiceRequests);

        $uncached = new FakeEdgeTTS(null, ['voicesCacheTtl' => 0]);
        $uncached->voiceResponse = [200, [], self::RAW_VOICES];
        $uncached->getVoices();
        $uncached->getVoices();
        $this->assertCount(2, $uncached->voiceRequests);
    }

    public function testRefusesToSaveMissingMetadata(): void
    {
        $this->expectException(RuntimeException::class);
        (new EdgeTTS())->saveMetadata($this->tmp . '/meta.json');
    }

    private const RAW_VOICES = '[
        {"Name":"Microsoft Server Speech Text to Speech Voice (en-US, AriaNeural)","ShortName":"en-US-AriaNeural","Gender":"Female","Locale":"en-US","FriendlyName":"Microsoft Aria Online (Natural) - English (United States)","VoiceTag":{"ContentCategories":["News","Novel"],"VoicePersonalities":["Positive","Confident"]}},
        {"Name":"Microsoft Server Speech Text to Speech Voice (es-CO, GonzaloNeural)","ShortName":"es-CO-GonzaloNeural","Gender":"Male","Locale":"es-CO"}
    ]';

    // Produced by normalizeVoices of the Node package for RAW_VOICES.
    private const NORMALIZED_VOICES = '[{"Name":"en-US-AriaNeural","DisplayName":"Aria","LocalName":"Aria","ShortName":"en-US-AriaNeural","Gender":"Female","Locale":"en-US","LocaleName":"en-US","SecondaryLocaleList":[],"VoiceType":"Neural","VoiceTag":{"TailoredScenarios":["News","Novel"],"VoicePersonalities":["Positive","Confident"]},"FriendlyName":"Aria (Neural) - en-US"},{"Name":"es-CO-GonzaloNeural","DisplayName":"Gonzalo","LocalName":"Gonzalo","ShortName":"es-CO-GonzaloNeural","Gender":"Male","Locale":"es-CO","LocaleName":"es-CO","SecondaryLocaleList":[],"VoiceType":"Neural","VoiceTag":{"TailoredScenarios":[],"VoicePersonalities":[]},"FriendlyName":"Gonzalo (Neural) - es-CO"}]';

    public function testNormalizesTheVoicesLikeTheNodePackage(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [200, [], self::RAW_VOICES];
        $voices = $tts->getVoices();

        $expected = json_decode(self::NORMALIZED_VOICES, true);
        $expected[0]['OriginalName'] = 'Microsoft Server Speech Text to Speech Voice (en-US, AriaNeural)';
        $expected[1]['OriginalName'] = 'Microsoft Server Speech Text to Speech Voice (es-CO, GonzaloNeural)';
        $this->assertEquals($expected, $voices);

        $request = $tts->voiceRequests[0];
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        $this->assertSame(Constants::TRUSTED_CLIENT_TOKEN, $query['TrustedClientToken']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $query['Sec-MS-GEC']);
        $this->assertSame('identity', $request['headers']['Accept-Encoding']);
        $this->assertArrayHasKey('Sec-CH-UA', $request['headers']);
    }

    public function testAcceptsAVoiceListWrappedInAnObject(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [200, [], '{"voices":' . self::RAW_VOICES . '}'];
        $this->assertCount(2, $tts->getVoices());
    }

    public function testFiltersAndChecksVoices(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [200, [], self::RAW_VOICES];

        $this->assertSame(['es-CO-GonzaloNeural'], array_column($tts->getVoicesByLanguage('es'), 'ShortName'));
        $this->assertSame(['en-US-AriaNeural'], array_column($tts->getVoicesByGender('Female'), 'ShortName'));
        $this->assertSame('en-US-AriaNeural', $tts->checkVoice('en-US-AriaNeural'));

        $this->expectException(InvalidArgumentException::class);
        $tts->checkVoice('xx-XX-NobodyNeural');
    }

    public function testCorrectsTheClockForTheVoiceList(): void
    {
        $tts = new class extends FakeEdgeTTS {
            protected function httpGet(string $url, array $headers): array
            {
                $this->voiceRequests[] = ['url' => $url, 'headers' => $headers];
                return count($this->voiceRequests) === 1
                    ? [403, ['date' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3600)], '']
                    : [200, [], '[]'];
            }
        };
        $this->assertSame([], $tts->getVoices());
        $this->assertCount(2, $tts->voiceRequests);
    }

    public function testReportsAFailedVoiceListRequest(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [500, [], ''];
        $this->expectException(EdgeTTSException::class);
        $this->expectExceptionMessage('500');
        $tts->getVoices();
    }
}
