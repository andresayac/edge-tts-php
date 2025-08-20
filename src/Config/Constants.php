<?php

namespace Afaya\EdgeTTS\Config;

class Constants
{
    public const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
    public const BASE_URL = 'speech.platform.bing.com/consumer/speech/synthesize/readaloud';
    public const WSS_URL = 'wss://speech.platform.bing.com/consumer/speech/synthesize/readaloud/edge/v1';
    public const VOICES_URL = 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud/voices/list';

    
    public const CHROMIUM_FULL_VERSION = '130.0.2849.68';
    public const CHROMIUM_MAJOR_VERSION = '130';
    public const SEC_MS_GEC_VERSION = '1-130.0.2849.68';
    
    private const WIN_EPOCH = 11644473600;
    private const S_TO_NS = 1e9;
    private const FIVE_MINUTES = 300;
    
    public static function generateSecMsGec(): string
    {
        // Get current timestamp with UTC timezone
        $timestamp = (new \DateTime('now', new \DateTimeZone('UTC')))->getTimestamp();
        
        // Switch to Windows file time epoch (1601-01-01 00:00:00 UTC)
        $ticks = $timestamp + self::WIN_EPOCH;
        
        // Round down to the nearest 5 minutes
        $ticks -= $ticks % self::FIVE_MINUTES;
        
        // Convert to Windows file time format (100-nanosecond intervals)
        $ticks = (int)($ticks * (self::S_TO_NS / 100));
        
        // Create string to hash
        $str_to_hash = $ticks . self::TRUSTED_CLIENT_TOKEN;
        
        // Return SHA256 hash in uppercase
        return strtoupper(hash('sha256', $str_to_hash));
    }
    
    public const BASE_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];
    
    public const WSS_HEADERS = [
        'Pragma' => 'no-cache',
        'Cache-Control' => 'no-cache',
        'Origin' => 'chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold',
        'Sec-WebSocket-Protocol' => 'synthesize',
        'Sec-WebSocket-Version' => '13'
    ];
    
    public const VOICE_HEADERS = [
        'Authority' => 'speech.platform.bing.com',
        'Sec-CH-UA' => '" Not;A Brand";v="99", "Microsoft Edge";v="130", "Chromium";v="130"',
        'Sec-CH-UA-Mobile' => '?0',
        'Accept' => '*/*',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Dest' => 'empty',
    ];
}
