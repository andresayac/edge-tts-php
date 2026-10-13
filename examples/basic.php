<?php

// Synthesizes a sentence to a file and lists the Spanish voices.
// Run it with: php examples/basic.php

require __DIR__ . '/../vendor/autoload.php';

use Afaya\EdgeTTS\Config\Constants;
use Afaya\EdgeTTS\Exception\EmptyAudioException;
use Afaya\EdgeTTS\Service\EdgeTTS;

$tts = new EdgeTTS();

$voices = $tts->getVoicesByLanguage('es');
echo count($voices) . " Spanish voices\n";
foreach (array_slice($voices, 0, 5) as $voice) {
    echo " - {$voice['ShortName']} ({$voice['FriendlyName']})\n";
}

try {
    $tts->synthesize('Hola, ¿cómo estás? Esto es una prueba.', 'es-CO-SalomeNeural', [
        'rate' => '+10%',
        'pitch' => '-5Hz',
        'outputFormat' => Constants::OUTPUT_FORMAT['AUDIO_24KHZ_96KBITRATE_MONO_MP3'],
    ]);
} catch (EmptyAudioException $e) {
    exit("The voice cannot read this text, pick one of its language.\n");
}

$path = $tts->toFile('hola');
$info = $tts->getAudioInfo();
printf("Saved %s: %d bytes, about %.1fs\n", $path, $info['size'], $info['estimatedDuration']);

foreach ($tts->getWordBoundaries() as $word) {
    // Offsets are in 100-nanosecond units.
    printf("%6.2fs  %s\n", $word['offset'] / 1e7, $word['text']);
}
$tts->saveMetadata('hola.json');
