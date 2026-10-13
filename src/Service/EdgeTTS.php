<?php

namespace Afaya\EdgeTTS\Service;

use Afaya\EdgeTTS\Config\Constants;
use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Afaya\EdgeTTS\Exception\EmptyAudioException;
use Afaya\EdgeTTS\Exception\HandshakeException;
use Afaya\EdgeTTS\Transport\Http;
use Afaya\EdgeTTS\Transport\WebSocket;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns text into speech with the online text-to-speech service of
 * Microsoft Edge, without Edge, Windows or an API key.
 *
 * synthesize(), stream() and synthesizeStream() keep the audio and the
 * boundaries of the last synthesis, which the to*() and get*() methods
 * return. generate() returns them in a SynthesisResult instead.
 */
class EdgeTTS
{
    private const OPTIONS = ['wssUrl', 'voicesUrl', 'secMsGecVersion', 'headers', 'timeout', 'voicesCacheTtl'];

    // One string rather than a list of chunks: reading it back costs no copy.
    private string $audio = '';

    private string $output_format = 'audio-24khz-48kbitrate-mono-mp3';

    /** @var array<int, array{type: string, offset: int, duration: int, text: string}> */
    private array $word_boundaries = [];

    /** @var array<int, array{type: string, offset: int, duration: int, text: string}> */
    private array $sentence_boundaries = [];

    protected string $wssUrl = Constants::WSS_URL;

    protected string $voicesUrl = Constants::VOICES_URL;

    protected string $secMsGecVersion = Constants::SEC_MS_GEC_VERSION;

    /** @var array<string, string> added to the default headers of every request */
    protected array $headers = [];

    /** Seconds without data from the service before giving up. */
    protected float $timeout = 30.0;

    /** Seconds to reuse the voice list; 0 fetches it every time. */
    protected int $voicesCacheTtl = 600;

    /** @var array{expires: float, voices: array}|null */
    private ?array $voicesCache = null;

    /**
     * Every option is optional. The defaults come from Constants, so when
     * Microsoft changes the service the new values can be set here without
     * waiting for a release.
     *
     * @param array{wssUrl?: string, voicesUrl?: string, secMsGecVersion?: string, headers?: array<string, string>, timeout?: int|float, voicesCacheTtl?: int} $options
     *     timeout and voicesCacheTtl are in seconds
     */
    public function __construct(array $options = [])
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown) {
            throw new InvalidArgumentException(
                'Unknown option ' . implode(', ', $unknown) . '. Known options: ' . implode(', ', self::OPTIONS) . '.'
            );
        }
        if (isset($options['headers']) && !is_array($options['headers'])) {
            throw new InvalidArgumentException('The headers option must be an array of name => value.');
        }
        if (isset($options['timeout']) && $options['timeout'] <= 0) {
            throw new InvalidArgumentException('The timeout option must be more than 0 seconds.');
        }

        $this->wssUrl = (string) ($options['wssUrl'] ?? $this->wssUrl);
        $this->voicesUrl = (string) ($options['voicesUrl'] ?? $this->voicesUrl);
        $this->secMsGecVersion = (string) ($options['secMsGecVersion'] ?? $this->secMsGecVersion);
        $this->headers = $options['headers'] ?? $this->headers;
        $this->timeout = (float) ($options['timeout'] ?? $this->timeout);
        $this->voicesCacheTtl = (int) ($options['voicesCacheTtl'] ?? $this->voicesCacheTtl);
    }

    /**
     * Returns every voice of the service. The list is kept for
     * voicesCacheTtl seconds.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVoices(): array
    {
        if ($this->voicesCache !== null && $this->voicesCache['expires'] > microtime(true)) {
            return $this->voicesCache['voices'];
        }

        for ($attempt = 0; ; $attempt++) {
            $url = $this->voicesUrl
                . '?TrustedClientToken=' . Constants::TRUSTED_CLIENT_TOKEN
                . '&Sec-MS-GEC=' . Protocol::secMsGec()
                . '&Sec-MS-GEC-Version=' . $this->secMsGecVersion;
            [$status, $headers, $body] = $this->httpGet(
                $url,
                array_merge(Constants::getBaseHeaders(), Constants::VOICE_HEADERS, $this->headers)
            );

            // A refused signature usually means the local clock is off.
            if ($status === 403 && $attempt === 0 && Protocol::adjustClock($headers)) {
                continue;
            }
            if ($status !== 200) {
                throw new EdgeTTSException("Voice list request failed with status {$status}");
            }

            $data = json_decode($body, true);
            // Either a bare list or an object with a voices list.
            if (is_array($data) && $data !== [] && !array_key_exists(0, $data)) {
                $data = $data['voices'] ?? $data['Voices'] ?? null;
            }
            if (!is_array($data)) {
                throw new EdgeTTSException('Invalid voice list JSON');
            }

            $voices = Protocol::normalizeVoices($data);
            if ($this->voicesCacheTtl > 0) {
                $this->voicesCache = ['expires' => microtime(true) + $this->voicesCacheTtl, 'voices' => $voices];
            }
            return $voices;
        }
    }

    /**
     * Returns the voices whose locale starts with $locale, so "en" matches
     * every English voice and "en-US" only the American ones.
     */
    public function getVoicesByLanguage(string $locale): array
    {
        return array_values(array_filter($this->getVoices(), function (array $voice) use ($locale): bool {
            return strncmp((string) $voice['Locale'], $locale, strlen($locale)) === 0;
        }));
    }

    /**
     * Returns the voices of $gender, "Male" or "Female".
     */
    public function getVoicesByGender(string $gender): array
    {
        return array_values(array_filter($this->getVoices(), function (array $voice) use ($gender): bool {
            return $voice['Gender'] === $gender;
        }));
    }

    /**
     * Returns $voice when the service has it.
     *
     * @throws InvalidArgumentException when it does not
     */
    public function checkVoice(string $voice): string
    {
        foreach ($this->getVoices() as $v) {
            if ($v['ShortName'] === $voice) {
                return $v['ShortName'];
            }
        }
        throw new InvalidArgumentException('Invalid voice. Use getVoices() to get a list of available voices.');
    }

    public function escapeXML(string $text): string
    {
        return Protocol::escapeXml($text);
    }

    /**
     * Speaks $text with $voice and keeps the audio and the boundaries.
     *
     * @param array{pitch?: int|float|string, rate?: int|float|string, volume?: int|float|string, outputFormat?: string, timeout?: int|float} $options
     *     pitch from "-100Hz" to "+100Hz", rate such as "+50%", volume from
     *     "-100%" to "+100%" (numbers work too), the output format, and the
     *     timeout in seconds for this call
     * @throws InvalidArgumentException when the text is empty or an option is invalid
     * @throws EmptyAudioException when the voice cannot read the text
     * @throws EdgeTTSException when the service fails
     */
    public function synthesize(string $text, string $voice = Constants::DEFAULT_VOICE, array $options = []): void
    {
        foreach ($this->stream($text, $voice, $options) as $chunk) {
            // stream() keeps the audio.
        }
    }

    /**
     * Speaks $text with $voice and calls $onChunk with the audio as it
     * arrives. The audio is also kept, as with synthesize().
     *
     * @param callable(string): void|null $onChunk
     */
    public function synthesizeStream(string $text, string $voice = Constants::DEFAULT_VOICE, array $options = [], ?callable $onChunk = null): void
    {
        foreach ($this->stream($text, $voice, $options) as $chunk) {
            if ($onChunk !== null) {
                $onChunk($chunk);
            }
        }
    }

    /**
     * Speaks $text with $voice and yields the audio as it arrives:
     *
     *     foreach ($tts->stream('Hello', 'en-US-AriaNeural') as $chunk) {
     *         echo $chunk;
     *     }
     *
     * Leaving the loop early closes the connection. The audio is also kept,
     * as with synthesize().
     *
     * @return Generator<int, string>
     */
    public function stream(string $text, string $voice = Constants::DEFAULT_VOICE, array $options = []): Generator
    {
        [$socket, $format] = $this->request($text, $voice, $options);

        $this->audio = '';
        $this->word_boundaries = [];
        $this->sentence_boundaries = [];
        $this->output_format = $format;

        return $this->keep($this->receive($socket, self::collector($text, $this->word_boundaries, $this->sentence_boundaries)));
    }

    /**
     * Speaks $text with $voice and returns the audio, the boundaries and the
     * duration, without changing what this instance keeps.
     */
    public function generate(string $text, string $voice = Constants::DEFAULT_VOICE, array $options = []): SynthesisResult
    {
        [$socket, $format] = $this->request($text, $voice, $options);

        $audio = '';
        $words = [];
        $sentences = [];
        foreach ($this->receive($socket, self::collector($text, $words, $sentences)) as $chunk) {
            $audio .= $chunk;
        }
        return new SynthesisResult($audio, $format, $words, $sentences);
    }

    /**
     * Checks the request, connects and sends it.
     *
     * @return array{0: WebSocket, 1: string} the connection and the output format
     */
    private function request(string $text, string $voice, array $options): array
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('The text is empty.');
        }
        $pitch = Protocol::formatPitch($options['pitch'] ?? null);
        $rate = Protocol::formatRate($options['rate'] ?? null);
        $volume = Protocol::formatVolume($options['volume'] ?? null);
        $format = (string) ($options['outputFormat'] ?? '');
        if ($format === '') {
            $format = Constants::OUTPUT_FORMAT['AUDIO_24KHZ_48KBITRATE_MONO_MP3'];
        }
        if ($voice === '') {
            $voice = Constants::DEFAULT_VOICE;
        }
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->timeout;
        if ($timeout <= 0) {
            throw new InvalidArgumentException('The timeout option must be more than 0 seconds.');
        }

        $socket = $this->open($timeout);
        try {
            $socket->send(Protocol::speechConfigMessage($format));
            $socket->send(Protocol::ssmlMessage(Protocol::randomHex(), Protocol::ssml($text, $voice, $pitch, $rate, $volume)));
        } catch (EdgeTTSException $e) {
            $socket->close();
            throw $e;
        }
        return [$socket, $format];
    }

    /**
     * Returns a function that files each boundary in $words or $sentences,
     * putting back the opening punctuation the service drops from sentences.
     */
    private static function collector(string $text, array &$words, array &$sentences): callable
    {
        $restore = Protocol::sentenceTextRestorer($text);
        return function (array $boundary) use (&$words, &$sentences, $restore): void {
            if ($boundary['type'] === 'WordBoundary') {
                $words[] = $boundary;
                return;
            }
            $boundary['text'] = $restore($boundary['text']);
            $sentences[] = $boundary;
        };
    }

    /**
     * Yields the audio of $chunks and keeps it.
     *
     * @return Generator<int, string>
     */
    private function keep(Generator $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            $this->audio .= $chunk;
            yield $chunk;
        }
    }

    /**
     * Yields the audio the service sends and passes each boundary to
     * $onBoundary. Closes the connection however it ends.
     *
     * @param callable(array): void $onBoundary
     * @return Generator<int, string>
     */
    private function receive(WebSocket $socket, callable $onBoundary): Generator
    {
        $received = false;
        try {
            while (true) {
                [$opcode, $data] = $socket->receive();
                if ($opcode === WebSocket::CLOSE) {
                    // The service closes with a reason when it refuses the
                    // request, such as "Unsupported voice xx-XX-Nobody."
                    [$code, $reason] = WebSocket::closeReason($data);
                    if ($code !== null && $code !== WebSocket::NORMAL_CLOSURE) {
                        throw new EdgeTTSException(
                            'The service refused the request: ' . ($reason !== '' ? $reason : "close code {$code}"),
                            $code
                        );
                    }
                    break;
                }
                if ($opcode === WebSocket::TEXT) {
                    [$path, $body] = Protocol::parseTextFrame($data);
                    if ($path === 'audio.metadata') {
                        foreach (Protocol::parseBoundaries($body) as $boundary) {
                            $onBoundary($boundary);
                        }
                    } elseif ($path === 'turn.end') {
                        break;
                    }
                    continue;
                }
                [$path, $audio] = Protocol::parseBinaryFrame($data);
                if ($path === 'audio' && $audio !== '') {
                    $received = true;
                    yield $audio;
                }
            }
        } finally {
            $socket->close();
        }

        if (!$received) {
            throw new EmptyAudioException();
        }
    }

    private function open(float $timeout): WebSocket
    {
        for ($attempt = 0; ; $attempt++) {
            $url = $this->wssUrl
                . '?Ocp-Apim-Subscription-Key=' . Constants::TRUSTED_CLIENT_TOKEN
                . '&Sec-MS-GEC=' . Protocol::secMsGec()
                . '&Sec-MS-GEC-Version=' . $this->secMsGecVersion
                . '&ConnectionId=' . Protocol::randomHex();
            try {
                return $this->connect(
                    $url,
                    array_merge(Constants::getBaseHeaders(), Constants::WSS_HEADERS, $this->headers),
                    $timeout
                );
            } catch (HandshakeException $e) {
                // A refused signature usually means the local clock is off.
                if ($e->getStatusCode() === 403 && $attempt === 0 && Protocol::adjustClock($e->getHeaders())) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Opens the WebSocket connection. Tests replace it with a fake service.
     *
     * @param array<string, string> $headers
     */
    protected function connect(string $url, array $headers, float $timeout): WebSocket
    {
        return WebSocket::connect($url, $headers, $timeout);
    }

    /**
     * Sends a GET request. Tests replace it with a fake service.
     *
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    protected function httpGet(string $url, array $headers): array
    {
        return Http::get($url, $headers, $this->timeout);
    }

    /**
     * Size of the audio in bytes.
     */
    public function getSizeBytes(): int
    {
        return strlen($this->toRaw());
    }

    /**
     * Estimates the length of the audio in seconds from its size and
     * bitrate. MP3 is constant bitrate; formats without one (opus) assume
     * 48 kbit/s.
     */
    public function getDuration(): float
    {
        return $this->getSizeBytes() * 8 / (Protocol::kbps($this->output_format) * 1000);
    }

    /**
     * @return array{size: int, format: string, estimatedDuration: float}
     */
    public function getAudioInfo(): array
    {
        return [
            'size' => $this->getSizeBytes(),
            'format' => Protocol::fileExtension($this->output_format),
            'estimatedDuration' => $this->getDuration(),
        ];
    }

    /**
     * Writes the audio to $output_path, adding the extension of the output
     * format, or $format when given, unless the path already ends with it.
     * Returns the path it wrote.
     */
    public function toFile(string $output_path, ?string $format = null): string
    {
        $path = Protocol::filePath($output_path, $format ?? Protocol::fileExtension($this->output_format));
        if (@file_put_contents($path, $this->toRaw()) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Failed to write the audio to {$path}: {$error}");
        }
        return $path;
    }

    /**
     * The audio as a binary string.
     */
    public function toRaw(): string
    {
        if ($this->audio === '') {
            throw new RuntimeException('No audio data available. Did you run synthesize() first?');
        }
        return $this->audio;
    }

    public function toBase64(): string
    {
        return base64_encode($this->toRaw());
    }

    /**
     * The audio as a stream resource, positioned at the start.
     *
     * @return resource
     */
    public function toStream()
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->toRaw());
        rewind($stream);
        return $stream;
    }

    /**
     * SRT subtitles of the last synthesis, one cue per sentence.
     */
    public function toSRT(): string
    {
        return Subtitles::toSRT($this->sentence_boundaries ?: $this->word_boundaries);
    }

    /**
     * WebVTT subtitles of the last synthesis, one cue per sentence.
     */
    public function toVTT(): string
    {
        return Subtitles::toVTT($this->sentence_boundaries ?: $this->word_boundaries);
    }

    /**
     * Writes the word boundaries to $output_path as JSON.
     */
    public function saveMetadata(string $output_path): void
    {
        if (!$this->word_boundaries) {
            throw new RuntimeException('No metadata available to save.');
        }
        $json = json_encode($this->word_boundaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (@file_put_contents($output_path, $json . PHP_EOL) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Failed to write the metadata to {$output_path}: {$error}");
        }
    }

    /**
     * When each word of the last synthesis is spoken. Offset and duration
     * are in 100-nanosecond units, as the service sends them.
     *
     * @return array<int, array{type: string, offset: int, duration: int, text: string}>
     */
    public function getWordBoundaries(): array
    {
        return $this->word_boundaries;
    }

    /**
     * When each sentence of the last synthesis is spoken, with the opening
     * punctuation of the text (¿, ¡, «...) that the service leaves out.
     *
     * @return array<int, array{type: string, offset: int, duration: int, text: string}>
     */
    public function getSentenceBoundaries(): array
    {
        return $this->sentence_boundaries;
    }
}
