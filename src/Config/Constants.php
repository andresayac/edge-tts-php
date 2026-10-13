<?php

namespace Afaya\EdgeTTS\Config;

class Constants
{
    public const VERSION = '1.9.1';

    public const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
    public const BASE_URL = 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud';
    // NOTE: the token goes as Ocp-Apim-Subscription-Key here; Edge user agents only.
    public const WSS_URL = 'wss://api.msedgeservices.com/tts/cognitiveservices/websocket/v1';
    public const VOICES_URL = 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud/voices/list';

    public const CHROMIUM_FULL_VERSION = '155.0.0.0';
    public const CHROMIUM_MAJOR_VERSION = '155';
    public const SEC_MS_GEC_VERSION = '1-155.0.4283.45';

    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/'
        . self::CHROMIUM_FULL_VERSION . ' Safari/537.36 Edg/' . self::CHROMIUM_FULL_VERSION;

    public const DEFAULT_VOICE = 'en-US-AnaNeural';

    public static function token32(): string
    {
        return strtoupper(bin2hex(random_bytes(16)));
    }

    public static function getBaseHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cookie' => 'MUID=' . self::token32(),
        ];
    }

    public const WSS_HEADERS = [
        'Pragma' => 'no-cache',
        'Cache-Control' => 'no-cache',
        'Origin' => 'chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold',
        'User-Agent' => self::USER_AGENT,
    ];

    public const VOICE_HEADERS = [
        'Sec-CH-UA' => '"Microsoft Edge";v="' . self::CHROMIUM_MAJOR_VERSION . '", "Chromium";v="' . self::CHROMIUM_MAJOR_VERSION . '", "Not(A:Brand";v="24"',
        'Sec-CH-UA-Mobile' => '?0',
        'Sec-CH-UA-Platform' => '"Windows"',
        'Accept' => '*/*',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Dest' => 'empty',
        // Plain JSON; the stream wrapper does not decode gzip, br or zstd.
        'Accept-Encoding' => 'identity',
    ];

    // https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-text-to-speech?tabs=nonstreaming
    public const OUTPUT_FORMAT = [
        'AUDIO_24KHZ_48KBITRATE_MONO_MP3' => 'audio-24khz-48kbitrate-mono-mp3',
        'AUDIO_24KHZ_96KBITRATE_MONO_MP3' => 'audio-24khz-96kbitrate-mono-mp3',
        'WEBM_24KHZ_16BIT_MONO_OPUS' => 'webm-24khz-16bit-mono-opus',
    ];
}
