
<?php

require __DIR__ . '/vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

// Example of how to use the EdgeTTS class
$tts = new EdgeTTS();

// Get voices
$voices = $tts->getVoices();

$tts->checkVoice('af-ZA-WillemNeural'); // Validates if the voice exists
// var_dump($voices);  // array -> use ShortName with the name of the voice
// imprimir todas las voces unicamente la key 'ShortName'
// foreach ($voices as $voice) {
//     echo $voice['ShortName'] . PHP_EOL;
// }


$text = 'Hola mi nombre es Ximena y estoy utilizando el servicio de texto a voz de Microsoft Edge TTS. ¡Es genial poder convertir texto en audio de alta calidad!';

$tts->synthesize($text, 'en-US-AndrewMultilingualNeural', [
    'rate' => '+0%',
    'volume' => '+0%',
    'pitch' => '+0Hz',
    'outputFormat' => 'audio-24khz-48kbitrate-mono-mp3'
]);

// // Example export methods for the audio
// $tts->toBase64();
$tts->toFile("output");
// $tts->toStream();
$tts->saveMetadata("metadata.json");
// $tts->toRaw();

// // Get audio info
var_dump($tts->getAudioInfo());
// // Get duration in seconds
// var_dump($tts->getDuration());

// // Get size in bytes
// var_dump($tts->getSizeBytes());

// // Get audio stream
$tts->synthesizeStream(
  "Hello world from streaming TTS",
  'en-US-JennyNeural',
  [],
  function (string $chunk) {
    file_put_contents('out.mp3', $chunk, FILE_APPEND);
  }
);
