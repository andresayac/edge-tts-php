<?php

namespace Afaya\EdgeTTS\Config;

class Constants
{
    public const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
    public const BASE_URL = 'speech.platform.bing.com/consumer/speech/synthesize/readaloud';
    public const WSS_URL = 'wss://speech.platform.bing.com/consumer/speech/synthesize/readaloud/edge/v1';
    public const VOICES_URL = 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud/voices/list';


    public const CHROMIUM_FULL_VERSION = '140.0.3485.14';
    public const CHROMIUM_MAJOR_VERSION = '140';
    public const SEC_MS_GEC_VERSION = '1-140.0.3485.14';
    

    public const BASE_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0',
        'Accept-Encoding' => 'gzip, deflate, br, zstd',
        'Accept-Language' => 'es,es-ES;q=0.9,en;q=0.8,en-GB;q=0.7,en-US;q=0.6,es-CO;q=0.5,es-MX;q=0.4',
    ];
    
    public const WSS_HEADERS = [
        'Pragma' => 'no-cache',
        'Cache-Control' => 'no-cache',
        'Origin' => 'chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold',
        'Sec-WebSocket-Protocol' => 'synthesize',
        'Sec-WebSocket-Version' => '13',
        'User-Agent' => self::BASE_HEADERS['User-Agent']
    ];
    
    public const VOICE_HEADERS = [
        'Authority' => 'speech.platform.bing.com',
        'Sec-CH-UA' => '" Not;A Brand";v="99", "Microsoft Edge";v="140", "Chromium";v="140"',
        'Sec-CH-UA-Mobile' => '?0',
        'Accept' => '*/*',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Dest' => 'empty',        
    ];
}
