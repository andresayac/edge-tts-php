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
 * An instance keeps the audio and the word boundaries of its last
 * synthesis, which the to*() and get*() methods return.
 */
class EdgeTTS
{
    // One string rather than a list of chunks: reading it back costs no copy.
    private string $audio = '';

    private string $output_format = 'audio-24khz-48kbitrate-mono-mp3';

    /** @var array<int, array{type: string, offset: int, duration: int, text: string}> */
    private array $word_boundaries = [];

    /** Seconds to wait for the service before giving up. */
    protected float $timeout = 30.0;

    /**
     * Returns every voice of the service.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVoices(): array
    {
        for ($attempt = 0; ; $attempt++) {
            $url = Constants::VOICES_URL
                . '?TrustedClientToken=' . Constants::TRUSTED_CLIENT_TOKEN
                . '&Sec-MS-GEC=' . Protocol::secMsGec()
                . '&Sec-MS-GEC-Version=' . Constants::SEC_MS_GEC_VERSION;
            [$status, $headers, $body] = $this->httpGet(
                $url,
                array_merge(Constants::getBaseHeaders(), Constants::VOICE_HEADERS)
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
            return Protocol::normalizeVoices($data);
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
     * Speaks $text with $voice and keeps the audio and the word boundaries.
     *
     * @param array{pitch?: int|float|string, rate?: int|float|string, volume?: int|float|string, outputFormat?: string} $options
     *     pitch from "-100Hz" to "+100Hz", rate such as "+50%", volume from
     *     "-100%" to "+100%" (numbers work too) and the output format
     * @throws InvalidArgumentException when an option is invalid
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

        $this->audio = '';
        $this->word_boundaries = [];
        $this->output_format = $format;

        $socket = $this->open();
        try {
            $socket->send(Protocol::speechConfigMessage($format));
            $socket->send(Protocol::ssmlMessage(Protocol::randomHex(), Protocol::ssml($text, $voice, $pitch, $rate, $volume)));
        } catch (EdgeTTSException $e) {
            $socket->close();
            throw $e;
        }

        return $this->receive($socket);
    }

    /**
     * @return Generator<int, string>
     */
    private function receive(WebSocket $socket): Generator
    {
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
                        array_push($this->word_boundaries, ...Protocol::parseWordBoundaries($body));
                    } elseif ($path === 'turn.end') {
                        break;
                    }
                    continue;
                }
                [$path, $audio] = Protocol::parseBinaryFrame($data);
                if ($path === 'audio' && $audio !== '') {
                    $this->audio .= $audio;
                    yield $audio;
                }
            }
        } finally {
            $socket->close();
        }

        if ($this->audio === '') {
            throw new EmptyAudioException();
        }
    }

    private function open(): WebSocket
    {
        for ($attempt = 0; ; $attempt++) {
            $url = Constants::WSS_URL
                . '?Ocp-Apim-Subscription-Key=' . Constants::TRUSTED_CLIENT_TOKEN
                . '&Sec-MS-GEC=' . Protocol::secMsGec()
                . '&Sec-MS-GEC-Version=' . Constants::SEC_MS_GEC_VERSION
                . '&ConnectionId=' . Protocol::randomHex();
            try {
                return $this->connect($url, array_merge(Constants::getBaseHeaders(), Constants::WSS_HEADERS));
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
    protected function connect(string $url, array $headers): WebSocket
    {
        return WebSocket::connect($url, $headers, $this->timeout);
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
     * Writes the audio to $output_path plus the extension of the output
     * format, or $format when given, and returns the path it wrote.
     */
    public function toFile(string $output_path, ?string $format = null): string
    {
        $path = $output_path . '.' . ($format ?? Protocol::fileExtension($this->output_format));
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
}
