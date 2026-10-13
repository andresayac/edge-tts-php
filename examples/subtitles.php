<?php

// Generates the audio and its subtitles in one call.
// Run it with: php examples/subtitles.php

require __DIR__ . '/../vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

$tts = new EdgeTTS();
$result = $tts->generate(
    '¿Cómo estás? ¡Qué bien! Esto es una prueba de subtítulos.',
    'es-CO-SalomeNeural'
);

echo 'Saved ' . $result->toFile('subtitles') . sprintf(", about %.1fs\n", $result->duration);
file_put_contents('subtitles.srt', $result->toSRT());
file_put_contents('subtitles.vtt', $result->toVTT());

echo $result->toSRT();
