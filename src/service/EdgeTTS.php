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

    public function __construct() {}

    public function getVoices(): array
    {
        $json = file_get_contents(Constants::VOICES_URL . "?trustedclienttoken=" . Constants::TRUSTED_CLIENT_TOKEN);
        $data = json_decode($json, true);

        $voices = [];
        $keysToUnset = ['VoiceTag', 'SuggestedCodec', 'Status'];

        foreach ($data as $voice) {
            $voices[] = array_diff_key($voice, array_flip($keysToUnset));
        }

        return $voices;
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
        $url = Constants::WSS_URL . "?trustedclienttoken=" . Constants::TRUSTED_CLIENT_TOKEN . "&ConnectionId=" . $req_id;

        $SSML_text = $this->getSSML($text, $voice, $options);

        $connector($url)->then(
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

        $message = "X-RequestId:{$req_id}\r\nContent-Type:application/ssml+xml\r\nX-Timestamp:" . $this->getXTime() . "Z\r\nPath:ssml\r\n\r\n{$SSML_text}";
        $ws->send($message);

        $ws->on('message', function ($data) use ($ws) {
            $this->processAudioData($data, $ws);
        });

        $ws->on('close', function () {});
    }

    private function buildTTSConfigMessage(): string
    {
        return "X-Timestamp:" . $this->getXTime() . "\r\nContent-Type:application/json; charset=utf-8\r\nPath:speech.config\r\n\r\n" .
            "{\"context\":{\"synthesis\":{\"audio\":{\"metadataoptions\":{\"sentenceBoundaryEnabled\":false,\"wordBoundaryEnabled\":true},\"outputFormat\":\"audio-24khz-48kbitrate-mono-mp3\"}}}}\r\n";
    }

    private function processAudioData($data, $ws): void
    {
        $needle = "Path:audio\r\n";
        if (strpos($data, $needle) !== false) {
            $audioData = substr($data, strpos($data, $needle) + strlen($needle));
            $this->audio_stream[] = $audioData;
        }

        if (strpos($data, "Path:turn.end") !== false) {
            $ws->close();
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
}
