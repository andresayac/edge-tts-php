
<?php

require __DIR__ . '/vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

// Example of how to use the EdgeTTS class
$tts = new EdgeTTS();

// Get voices
$voices = $tts->getVoices();  
// var_dump($voices);  // array -> use ShortName with the name of the voice

$tts->synthesize("Hello, world!", 'en-US-AriaNeural', [
    'rate' => '0%',
    'volume' => '0%',
    'pitch' => '0Hz'
]);

// Example export methods for the audio
$tts->toBase64();
$tts->toFile("output");
$tts->toRaw();

