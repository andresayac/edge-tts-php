<?php

namespace Afaya\EdgeTTS\Service;

use Ratchet\Client\Connector;
use Ramsey\Uuid\Uuid;
use Afaya\EdgeTTS\Config\Constants;
use React\EventLoop\Loop;
use InvalidArgumentException;
use RuntimeException;

class EdgeTTS
{
    private array $audio_stream = [];
    private string $audio_format = 'mp3';
    private array $headers;
    private array $word_boundaries = [];
    private int $offset_compensation = 0;
    private int $last_duration_offset = 0;

    public function __construct() {
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
            ]
        ]);

        $json = file_get_contents(
            Constants::VOICES_URL . "?trustedclienttoken=" . Constants::TRUSTED_CLIENT_TOKEN,
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

    private function checkVoice(string $voice): string
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
    

    private function getSSML(string $text, string $voice, array $options = []): string
    {
        $options = array_merge([
            'pitch' => '0Hz',
            'rate' => '0%',
            'volume' => '0%'
        ], $options);

        $options['pitch'] = str_replace('hz', 'Hz', $options['pitch']);

        $pitch = $this->validatePitch($options['pitch']);
        $rate = $this->validateRate($options['rate']);
        $volume = $this->validateVolume($options['volume']);
        $voice = $this->checkVoice($voice);

        return "<speak version='1.0' xml:lang='en-US'>
                    <voice name='$voice'>
                        <prosody pitch='$pitch' rate='$rate' volume='$volume'>
                            $text
                        </prosody>
                    </voice>
                </speak>";
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
     * @param array $options Options for the synthesis (rate, volume, pitch).
     * @return void
     */
    public function synthesize(string $text, string $voice = 'en-US-AnaNeural', array $options = []): void
    {
        $loop = Loop::get();
        $connector = new Connector($loop);
        $req_id = Uuid::uuid4()->toString();
        
        $url = Constants::WSS_URL 
            . "?TrustedClientToken=" . Constants::TRUSTED_CLIENT_TOKEN 
            . "&ConnectionId=" . $req_id
            . "&Sec-MS-GEC=" . Constants::generateSecMsGec()
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

    /**
     * Sends the TTS request over WebSocket and processes the audio stream.
     */
    private function sendTTSRequest($ws, string $SSML_text, string $req_id): void
    {
        $message = $this->buildTTSConfigMessage();
        $ws->send($message);

        $message = "X-RequestId:{$req_id}\r\n" .
                  "Content-Type:application/ssml+xml\r\n" .
                  "X-Timestamp:" . $this->getXTime() . "Z\r\n" .
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

        return "X-Timestamp:" . $this->getXTime() . "Z\r\n" .
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

    private function getXTime(): string
    {
        return (new \DateTime())->format('Y-m-d\TH:i:s.v\Z');
    }

    public function toFile(string $output_path): void
    {
        if (!empty($this->audio_stream)) {
            file_put_contents($output_path . '.' . $this->audio_format, implode('', $this->audio_stream));
            
            // Save metadata if available
            if (!empty($this->word_boundaries)) {
                $metadata_path = $output_path . '.metadata.json';
                file_put_contents(
                    $metadata_path,
                    implode("\n", array_map('json_encode', $this->word_boundaries))
                );
            }
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

    public function saveMetadata(string $output_path): void
    {
        if (!empty($this->word_boundaries)) {
            file_put_contents(
                $output_path,
                implode("\n", array_map('json_encode', $this->word_boundaries))
            );
        } else {
            throw new RuntimeException("No metadata available to save.");
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
