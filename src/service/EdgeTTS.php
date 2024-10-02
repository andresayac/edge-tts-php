<?php

namespace App\Service;

use Ratchet\Client\Connector;
use Ramsey\Uuid\Uuid;
use App\Config\Constants;
use React\EventLoop\Loop;

class EdgeTTS
{
    private string $output_path;
    private array $audio_stream = [];

    public function __construct() {}

    public function getVoices()
    {
        $json = file_get_contents(Constants::VOICES_URL . "?trustedclienttoken=" . Constants::TRUSTED_CLIENT_TOKEN);
        $data = json_decode($json, true);
        $voices = [];
        foreach ($data as $voice) {
            $voices[] = $voice['ShortName'];
        }
        return $voices;
    }

    private function checkVoice($voice)
    {
        $voices = $this->getVoices();
        return in_array($voice, $voices) ? $voice : 'en-US-AnaNeural';
    }

    private function getSSML($text, $voice, $options = [])
    {
        $defaults = [
            'pitch' => '0Hz',
            'rate' => '0%',
            'volume' => '0%'
        ];
        $options = array_merge($defaults, $options);

        $pitch = $this->validatePitch($options['pitch']);
        $rate = $this->validateRate($options['rate']);
        $volume = $this->validateVolume($options['volume']);

        $voice = $this->checkVoice($voice);
        $ssml = "<speak version='1.0' xml:lang='en-US'>
                        <voice name='$voice'>
                            <prosody pitch='$pitch' rate='$rate' volume='$volume'>
                                $text
                            </prosody>
                        </voice>
                    </speak>";

        return $ssml;
    }

    private function validatePitch($pitch)
    {
        $value = intval($pitch);
        return ($value < -100 || $value > 100) ? "0Hz" : "{$value}Hz";
    }

    private function validateRate($rate)
    {
        $value = intval($rate);
        return ($value < -100 || $value > 100) ? "0%" : "$value%";
    }

    private function validateVolume($volume)
    {
        $value = intval($volume);
        return ($value < -100 || $value > 100) ? "0%" : "$value%";
    }


    public function generateAudio(string $text, string $output_path, string $voice, string $rate, string $volume, string $pitch): void
    {
        $this->output_path = $output_path;

        $loop = Loop::get();
        $connector = new Connector($loop);

        $req_id = Uuid::uuid4()->toString();
        $url = Constants::WSS_URL . "?trustedclienttoken=" . Constants::TRUSTED_CLIENT_TOKEN . "&ConnectionId=" . $req_id;

        $SSML_text = $this->getSSML($text, $voice, ['rate' => $rate, 'volume' => $volume, 'pitch' => $pitch]);

        $connector($url)->then(
            function ($ws) use ($SSML_text, $req_id) {
                $message_1 = "X-Timestamp:" . $this->getXTime() . "\r\nContent-Type:application/json; charset=utf-8\r\nPath:speech.config\r\n\r\n{\"context\":{\"synthesis\":{\"audio\":{\"metadataoptions\":{\"sentenceBoundaryEnabled\":false,\"wordBoundaryEnabled\":true},\"outputFormat\":\"audio-24khz-48kbitrate-mono-mp3\"}}}}\r\n";
                $ws->send($message_1);

                // Mensaje 2 (SSML)
                $message_2 = "X-RequestId:{$req_id}\r\nContent-Type:application/ssml+xml\r\nX-Timestamp:" . $this->getXTime() . "Z\r\nPath:ssml\r\n\r\n{$SSML_text}";
                $ws->send($message_2);

                $ws->on('message', function ($data) use ($ws) {
                    $needle = "Path:audio\r\n";
                    if (strpos($data, $needle) !== false) {
                        $audioData = substr($data, strpos($data, $needle) + strlen($needle));
                        $this->audio_stream[] = $audioData;
                    }

                    if (strpos($data, "Path:turn.end") !== false) {
                        $ws->close();
                    }
                });

                $ws->on('close', function () {
                    if (!empty($this->audio_stream)) {
                        file_put_contents($this->output_path . '.wav', implode('', $this->audio_stream));                        
                    }
                });
            },
            function ($e) {
                echo "Error: {$e->getMessage()}\n";
            }
        );

        $loop->run();
    }

    private function getXTime(): string
    {
        return (new \DateTime())->format('Y-m-d\TH:i:s.v\Z');
    }
}
