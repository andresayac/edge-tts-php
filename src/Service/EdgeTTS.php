<?php

namespace Afaya\EdgeTTS\Service;

use Ratchet\Client\Connector;
use React\Socket\Connector as SocketConnector;
use Ramsey\Uuid\Uuid;
use Afaya\EdgeTTS\Config\Constants;
use React\EventLoop\Loop;
use InvalidArgumentException;
use RuntimeException;
use DateTime;
use DateTimeZone;

class EdgeTTS
{
    private array $audio_stream = [];
    private string $audio_format = 'mp3';
    private array $headers;
    private array $word_boundaries = [];
    private int $offset_compensation = 0;
    private int $last_duration_offset = 0;

    public function __construct()
    {
        $this->headers = array_merge(
            Constants::BASE_HEADERS,
            Constants::WSS_HEADERS
        );
    }

    public function getVoices(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $this->formatHeaders(array_merge(
                    Constants::BASE_HEADERS,
                    Constants::VOICE_HEADERS
                ))
            ],
            "ssl" => array(
                "verify_peer" => false,
                "verify_peer_name" => false,
            ),
        ]);

        $json = file_get_contents(
            Constants::VOICES_URL . "?Ocp-Apim-Subscription-Key=" . Constants::TRUSTED_CLIENT_TOKEN . "&Sec-MS-GEC=" . $this->generateSecMsGec(Constants::TRUSTED_CLIENT_TOKEN) . "&Sec-MS-GEC-Version=" . urlencode(Constants::SEC_MS_GEC_VERSION),
            false,
            $context
        );

        if ($json === false) {
            throw new RuntimeException("Failed to fetch voices list");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid response from voices API");
        }

        $voices = [];
        $keysToUnset = ['VoiceTag', 'SuggestedCodec', 'Status'];

        foreach ($data as $voice) {
            $voice['FriendlyName'] = $voice['FriendlyName'] ?? $voice['LocalName'];
            $voice['FriendlyName'] = "{$voice['FriendlyName']} ({$voice['VoiceType']}) - {$voice['LocaleName']}";
            $voices[] = array_diff_key($voice, array_flip($keysToUnset));
        }

        return $voices;
    }

    private function formatHeaders(array $headers): string
    {
        return implode("\r\n", array_map(
            fn($k, $v) => "$k: $v",
            array_keys($headers),
            array_values($headers)
        ));
    }

    public function checkVoice(string $voice): string
    {
        $voices = $this->getVoices();
        $matchedVoice = array_filter($voices, function ($v) use ($voice) {
            return $v['ShortName'] === $voice;
        });

        if (empty($matchedVoice)) {
            throw new InvalidArgumentException("Invalid voice. Use getVoices() to get a list of available voices.");
        }

        return reset($matchedVoice)['ShortName'];
    }

    public function detectSSML(string $content): array
    {
        $trimmedContent = trim($content);

        $looksLikeSSML = preg_match('/^<\?xml|^<speak/i', $trimmedContent);

        if (!$looksLikeSSML) {
            return [
                'isValid' => true,
                'isSSML'  => false
            ];
        }

        $errors = [];

        $hasSpeakTag = preg_match('/<speak\b[^>]*>[\s\S]*<\/speak>/i', $trimmedContent);
        $hasVoiceTag = preg_match('/<voice\b[^>]*>[\s\S]*<\/voice>/i', $trimmedContent);

        if (!$hasSpeakTag) {
            throw new \RuntimeException('Invalid SSML: Missing <speak> tag');
        }

        if (!$hasVoiceTag) {
            throw new \RuntimeException('Invalid SSML: Missing <voice> tag');
        }

        $hasCorrectNamespace = preg_match('/xmlns="http:\/\/www\.w3\.org\/2001\/10\/synthesis"/i', $trimmedContent);
        if (!$hasCorrectNamespace && $hasSpeakTag) {
            throw new \RuntimeException('Invalid SSML: Missing or incorrect namespace declaration');
        }

        return [
            'isValid' => empty($errors),
            'isSSML'  => (bool)($hasSpeakTag || $hasVoiceTag),
            'errors'  => !empty($errors) ? $errors : null
        ];
    }

    public function escapeXML(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function getSSML(string $content, string $voice, array $options = []): string
    {
        $options = array_merge([
            'pitch' => '0Hz',
            'rate' => '0%',
            'volume' => '0%'
        ], $options);

        $options['pitch'] = str_replace('hz', 'Hz', $options['pitch']);

        $inputType = $options['inputType'] ?? 'auto';
        $treatAsSSML = false;

        if ($inputType === 'ssml') {
            $treatAsSSML = true;
        } else if ($inputType === 'text') {
            $treatAsSSML = false;
        } else {
            $detection = $this->detectSSML($content);
            $treatAsSSML = $detection['isSSML'];

            if ($detection['isSSML']) {
                if (!$detection['isValid']) {
                    error_log('⚠ SSML validation warnings: ' . json_encode($detection['errors']));
                }
            }
        }

        if ($treatAsSSML) {
            $ssml = trim($content);
            if (strpos($ssml, 'xmlns=') === false) {
                $ssml = preg_replace(
                    '/<speak([^>]*)>/i',
                    '<speak$1 xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="https://www.w3.org/2001/mstts">',
                    $ssml
                );
            }

            if (!preg_match('/<voice\b[^>]*>/i', $ssml) && $voice) {
                $ssml = preg_replace(
                    '/(<speak[^>]*>)([\s\S]*?)(<\/speak>)/i',
                    '$1<voice name="' . $voice . '">$2</voice>$3',
                    $ssml
                );
            }

            return $ssml;
        }

        $pitch = $this->validatePitch($options['pitch'] ?? 0);
        $rate = $this->validateRate($options['rate'] ?? 0);
        $volume = $this->validateVolume($options['volume'] ?? 0);

        $escapedText = $this->escapeXML($content);

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="https://www.w3.org/2001/mstts" xml:lang="en-US">
                    <voice name="' . $voice . '">
                        <prosody pitch="' . $pitch . '" rate="' . $rate . '" volume="' . $volume . '">
                            ' . $escapedText . '
                        </prosody>
                    </voice>
                </speak>
        ';
    }

    private function validatePitch(string $pitch): string
    {
        if (!preg_match('/^-?\d{1,3}Hz$/', $pitch) || intval($pitch) < -100 || intval($pitch) > 100) {
            throw new InvalidArgumentException("Invalid pitch format. Expected format: '-100Hz to 100Hz'.");
        }
        return $pitch;
    }

    private function validateRate(string $rate): string
    {
        if (!preg_match('/^-?\d{1,3}%$/', $rate) || intval($rate) < -100 || intval($rate) > 100) {
            throw new InvalidArgumentException("Invalid rate format. Expected format: '-100% to 100%'.");
        }
        return $rate;
    }

    private function validateVolume(string $volume): string
    {
        if (!preg_match('/^-?\d{1,3}%$/', $volume) || intval($volume) < -100 || intval($volume) > 100) {
            throw new InvalidArgumentException("Invalid volume format. Expected format: '-100% to 100%'.");
        }
        return $volume;
    }

    /**
     * Synthesizes text to speech using the Edge TTS service.
     *
     * @param string $text The text to be synthesized.
     * @param string $voice The voice to use (default: 'en-US-AnaNeural').
     * @param array $options Options for the synthesis (rate, volume, pitch, inputType).
     * @return void
     */
    public function synthesize(string $text, string $voice = 'en-US-AnaNeural', array $options = []): void
    {
        $loop = Loop::get();

        $socketConnector = new SocketConnector($loop, [
            'tls' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
        $connector = new Connector($loop, $socketConnector);

        $req_id = Uuid::uuid4()->toString();

        $url = Constants::WSS_URL
            . "?Ocp-Apim-Subscription-Key=" . Constants::TRUSTED_CLIENT_TOKEN
            . "&ConnectionId=" . $req_id
            . "&Sec-MS-GEC=" . $this->generateSecMsGec(Constants::TRUSTED_CLIENT_TOKEN)
            . "&Sec-MS-GEC-Version=" . urlencode(Constants::SEC_MS_GEC_VERSION);

        $SSML_text = $this->getSSML($text, $voice, $options);

        $connector($url, [], array_merge($this->headers, [
            'Sec-WebSocket-Protocol' => 'synthesize'
        ]))->then(

            function ($ws) use ($SSML_text, $req_id) {
                $this->sendTTSRequest($ws, $SSML_text, $req_id);
            },
            function ($e) {
                echo "Error: {$e->getMessage()}\n";
            }
        );

        $loop->run();
    }

    public function synthesizeStream(string $text, string $voice = 'en-US-AnaNeural', array $options = [], ?callable $onChunk = null): void
    {
        $this->audio_stream = [];

        $loop = Loop::get();
        $socketConnector = new SocketConnector($loop, [
            'tls' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
        $connector = new Connector($loop, $socketConnector);

        $reqId     = Uuid::uuid4()->toString();
        $secMsGEC  = $this->generateSecMsGec(Constants::TRUSTED_CLIENT_TOKEN);


        $url = Constants::WSS_URL
            . "?Ocp-Apim-Subscription-Key=" . Constants::TRUSTED_CLIENT_TOKEN
            . "&ConnectionId="       . $reqId
            . "&Sec-MS-GEC="         . $secMsGEC
            . "&Sec-MS-GEC-Version=" . urlencode(Constants::SEC_MS_GEC_VERSION);

        $SSML = $this->getSSML($text, $voice, $options);

        $timeout = $loop->addTimer(30.0, function () use (&$ws) {
            if (isset($ws) && $ws && $ws->readyState === 1 /* OPEN */) {
                $ws->close();
            }
        });

        $connector($url, [], array_merge($this->headers, [
            'Sec-WebSocket-Protocol' => 'synthesize',
        ]))->then(
            function ($socket) use ($SSML, $reqId, $loop, $timeout, $onChunk, &$ws) {
                $ws = $socket;

                $ws->send($this->buildTTSConfigMessage());

                $speechMsg =
                    "X-RequestId:{$reqId}\r\n" .
                    "Content-Type:application/ssml+xml\r\n" .
                    "X-Timestamp:" . $this->getXTime() . "\r\n" .
                    "Path:ssml\r\n\r\n" .
                    $SSML;
                $ws->send($speechMsg);


                $ws->on('message', function ($data) use ($ws, $onChunk) {
                    if (strpos($data, "Path:audio.metadata") !== false) {
                        $metadataStart = strpos($data, "\r\n\r\n") + 4;
                        $metadataJson  = substr($data, $metadataStart);
                        $meta = $this->parseMetadata($metadataJson);
                        if ($meta !== null) {
                            $this->word_boundaries[] = $meta;
                            $this->last_duration_offset = $meta['offset'] + $meta['duration'];
                        }
                        return;
                    }

                    if (strpos($data, "Path:turn.end") !== false) {
                        $this->offset_compensation = $this->last_duration_offset + 8750000;
                        $ws->close();
                        return;
                    }

                    $needle = "Path:audio\r\n";
                    $pos = strpos($data, $needle);
                    if ($pos !== false) {
                        $audioChunk = substr($data, $pos + strlen($needle));
                        $this->audio_stream[] = $audioChunk;


                        if ($onChunk) {
                            $onChunk($audioChunk); // <- string binario del fragmento
                        }
                    }
                });

                $ws->on('error', function ($e) use ($loop, $timeout) {
                    $loop->cancelTimer($timeout);
                    throw new RuntimeException("WebSocket error: " . $e->getMessage());
                });

                $ws->on('close', function () use ($loop, $timeout) {
                    $loop->cancelTimer($timeout);
                    $loop->stop();
                });
            },
            function ($e) {
                throw new RuntimeException("Connect error: " . $e->getMessage());
            }
        );

        // Corre hasta que el WS cierre
        $loop->run();
    }

    /**
     * Sends the TTS request over WebSocket and processes the audio stream.
     */
    private function sendTTSRequest($ws, string $SSML_text, string $req_id): void
    {
        $message = $this->buildTTSConfigMessage();
        $ws->send($message);

        $message = "X-RequestId:{$req_id}\r\n" .
            "Content-Type:application/ssml+xml\r\n" .
            "X-Timestamp:" . $this->getXTime() . "\r\n" .
            "Path:ssml\r\n\r\n" .
            $SSML_text;
        $ws->send($message);

        $ws->on('message', function ($data) use ($ws) {
            $this->processAudioData($data, $ws);
        });

        $ws->on('close', function () {});
    }

    private function buildTTSConfigMessage(): string
    {
        $config = [
            'context' => [
                'synthesis' => [
                    'audio' => [
                        'metadataoptions' => [
                            'sentenceBoundaryEnabled' => false,
                            'wordBoundaryEnabled' => true
                        ],
                        'outputFormat' => 'audio-24khz-48kbitrate-mono-mp3'
                    ]
                ]
            ]
        ];

        return "X-Timestamp:" . $this->getXTime() . "\r\n" .
            "Content-Type:application/json; charset=utf-8\r\n" .
            "Path:speech.config\r\n\r\n" .
            json_encode($config) . "\r\n";
    }

    private function processAudioData($data, $ws): void
    {
        if (strpos($data, "Path:audio.metadata") !== false) {
            $metadataStart = strpos($data, "\r\n\r\n") + 4;
            $metadataJson = substr($data, $metadataStart);
            $metadata = $this->parseMetadata($metadataJson);

            if ($metadata !== null) {
                $this->word_boundaries[] = $metadata;
                $this->last_duration_offset = $metadata['offset'] + $metadata['duration'];
            }
            return;
        }

        if (strpos($data, "Path:turn.end") !== false) {
            $this->offset_compensation = $this->last_duration_offset + 8750000; // average padding
            $ws->close();
            return;
        }

        $needle = "Path:audio\r\n";
        if (strpos($data, $needle) !== false) {
            $audioData = substr($data, strpos($data, $needle) + strlen($needle));
            $this->audio_stream[] = $audioData;
        }
    }


    /**
     * Generates a Sec-MS-GEC token.
     */
    private function generateSecMsGec(string $trustedClientToken, string $timeZone = 'UTC'): string
    {
        $date = new DateTime('now', new DateTimeZone($timeZone));
        $timestamp = $date->getTimestamp();
        $ticks = (int) floor($timestamp + 11644473600);
        $rounded = $ticks - ($ticks % 300);
        $windowsTicks = $rounded * 10000000;
        $data = (string) $windowsTicks . $trustedClientToken;
        return strtoupper(hash('sha256', $data));
    }

    /**
     * Returns the current time in the required format 
     */

    private function getXTime(string $timeZone = 'UTC'): string
    {
        try {
            $now = new DateTime('now', new DateTimeZone($timeZone));
            return $now->format('D, M d, Y H:i:s T');
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid timezone: $timeZone");
        }
    }

    /**
     * Lee el bitrate (kbps) desde el formato, ej: audio-24khz-48kbitrate-mono-mp3 -> 48.
     */
    private function parseBitrateKbpsFromFormat(?string $format = null): ?int
    {
        $format = $format ?? $this->audio_format;
        if (preg_match('/-(\d+)kbitrate-/', $format, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Tamaño total en bytes del audio acumulado.
     */
    public function getSizeBytes(): int
    {
        if (empty($this->audio_stream)) {
            throw new \RuntimeException("No audio data available");
        }
        // strlen en PHP cuenta bytes en strings binarios
        return strlen($this->toRaw());
    }

    /**
     * Estima la duración (en segundos).
     * 1) Si el formato incluye Xkbitrate, usa size_bytes / (X*1000/8).
     * 2) Fallback: asume PCM 24kHz mono 16-bit => 48,000 bytes/s.
     */
    public function getDuration(): float
    {
        if (empty($this->audio_stream)) {
            throw new \RuntimeException("No audio data available");
        }

        $sizeBytes = $this->getSizeBytes();

        // 1) Preferir bitrate declarado en el formato (más realista para MP3)
        $kbps = $this->parseBitrateKbpsFromFormat($this->audio_format);
        if ($kbps !== null && $kbps > 0) {
            $bytesPerSecond = ($kbps * 1000) / 8.0; // kbps -> bytes/s
            return $sizeBytes / $bytesPerSecond;
        }

        // 2) Fallback razonable (PCM 24kHz mono 16-bit)
        $fallbackBytesPerSecond = 24000 * 2; // 24k muestras/s * 2 bytes
        return $sizeBytes / $fallbackBytesPerSecond;
    }

    /**
     * Información básica del audio.
     */
    public function getAudioInfo(): array
    {
        $size = $this->getSizeBytes();

        return [
            'size'              => $size,
            'format'            => $this->audio_format,
            'estimatedDuration' => $this->getDuration(),
        ];
    }

    public function toFile(string $output_path): void
    {
        if (!empty($this->audio_stream)) {
            file_put_contents($output_path . '.' . $this->audio_format, implode('', $this->audio_stream));
        } else {
            throw new RuntimeException("No audio data available to save.");
        }
    }

    public function toRaw(): string
    {
        if (empty($this->audio_stream)) {
            throw new RuntimeException("No audio data available.");
        }

        return implode('', $this->audio_stream);
    }

    public function toBase64(): string
    {
        return base64_encode($this->toRaw());
    }

    public function toStream()
    {
        if (empty($this->audio_stream)) {
            throw new \RuntimeException("No audio data available. Did you run synthesize() first?");
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, implode('', $this->audio_stream));
        rewind($stream);

        return $stream;
    }

    public function saveMetadata(string $output_path): void
    {
        if (empty($this->word_boundaries)) {
            throw new \RuntimeException("No metadata available to save.");
        }

        $json = json_encode($this->word_boundaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (file_put_contents($output_path, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Failed to write metadata file.");
        }
    }

    private function parseMetadata(string $data): ?array
    {
        $metadata = json_decode($data, true);
        if (!isset($metadata['Metadata'])) {
            return null;
        }

        foreach ($metadata['Metadata'] as $meta_obj) {
            if ($meta_obj['Type'] === 'WordBoundary') {
                $current_offset = $meta_obj['Data']['Offset'] + $this->offset_compensation;
                $current_duration = $meta_obj['Data']['Duration'];

                return [
                    'type' => 'WordBoundary',
                    'offset' => $current_offset,
                    'duration' => $current_duration,
                    'text' => $meta_obj['Data']['text']['Text']
                ];
            }
        }

        return null;
    }

    public function getWordBoundaries(): array
    {
        return $this->word_boundaries;
    }
}
