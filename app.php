
<?php

require __DIR__ . '/vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

// Example of how to use the EdgeTTS class
$tts = new EdgeTTS();

// Get voices
$voices = $tts->getVoices();  
// var_dump($voices);  // array -> use ShortName with the name of the voice

$ssml = '<speak version="1.0"
       xmlns="http://www.w3.org/2001/10/synthesis"
       xmlns:mstts="https://www.w3.org/2001/mstts"
       xml:lang="es-CO">
  <voice name="es-CO-GonzaloNeural">
    <mstts:express-as style="narration-professional">
      <prosody rate="+5%" pitch="+10Hz" volume="+0%">
        Hola, este es un ejemplo de <emphasis>SSML</emphasis>.
        <break time="400ms" />
        El número es <say-as interpret-as="cardinal">2025</say-as>.
        La palabra se pronuncia
        <phoneme alphabet="ipa" ph="ˈxola">hola</phoneme>.
      </prosody>
    </mstts:express-as>
  </voice>
</speak>';

$tts->synthesize($ssml, 'en-US-AriaNeural', [
    'rate' => '0%',
    'volume' => '0%',
    'pitch' => '0Hz'
]);

// Example export methods for the audio
$tts->toBase64();
$tts->toFile("output");
$tts->toStream();
$tts->saveMetadata("metadata.json");
$tts->toRaw();

// Get audio info
var_dump($tts->getAudioInfo());
// Get duration in seconds
var_dump($tts->getDuration());

// Get size in bytes
var_dump($tts->getSizeBytes());

// Get audio stream
$tts->synthesizeStream(
    "Hello world from streaming TTS",
    'en-US-AnaNeural',
    [],
    function (string $chunk) {
        file_put_contents('out.mp3', $chunk, FILE_APPEND);
    }
);

