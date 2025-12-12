<?php

namespace Afaya\EdgeTTS\Config;

class Constants
{
    public const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
    public const BASE_URL = 'speech.platform.bing.com/consumer/speech/synthesize/readaloud';
    public const WSS_URL = 'wss://speech.platform.bing.com/consumer/speech/synthesize/readaloud/edge/v1';
    public const VOICES_URL = 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud/voices/list';

    public const CHROMIUM_FULL_VERSION = '143.0.3650.75';
    public const CHROMIUM_MAJOR_VERSION = '143';
    public const SEC_MS_GEC_VERSION = '1-143.0.3650.75';

    public static function token32(): string
    {
        $bytes = random_bytes(16);
        return strtoupper(bin2hex($bytes));
    }

    public static function getBaseHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cookie' => 'MUID=' . self::token32()
        ];
    }

    public const WSS_HEADERS = [
        'Pragma' => 'no-cache',
        'Cache-Control' => 'no-cache',
        'Origin' => 'chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold',
        'Sec-WebSocket-Protocol' => 'synthesize',
        'Sec-WebSocket-Version' => '13',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.3650.75 Safari/537.36 Edg/143.0.3650.75'
    ];

    public const VOICE_HEADERS = [
        'Authority' => 'speech.platform.bing.com',
        'Sec-CH-UA' => '" Not;A Brand";v="99", "Microsoft Edge";v="143", "Chromium";v="143"',
        'Sec-CH-UA-Mobile' => '?0',
        'Accept' => '*/*',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Dest' => 'empty'
    ];

    // https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-text-to-speech?tabs=nonstreaming
    public const OUTPUT_FORMAT = [    
        'AUDIO_24KHZ_48KBITRATE_MONO_MP3' => 'audio-24khz-48kbitrate-mono-mp3',
        'AUDIO_24KHZ_96KBITRATE_MONO_MP3' => 'audio-24khz-96kbitrate-mono-mp3',
        'WEBM_24KHZ_16BIT_MONO_OPUS' => 'webm-24khz-16bit-mono-opus',
    ];
}
