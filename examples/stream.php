<?php

// Writes the audio to a file as it arrives from the service.
// Run it with: php examples/stream.php

require __DIR__ . '/../vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

$tts = new EdgeTTS();
$text = 'Streaming lets you play the first words before the last ones are synthesized.';

// With a generator.
$out = fopen('stream.mp3', 'wb');
foreach ($tts->stream($text, 'en-US-AriaNeural') as $chunk) {
    fwrite($out, $chunk);
}
fclose($out);
echo 'Wrote ' . filesize('stream.mp3') . " bytes to stream.mp3\n";

// With a callback.
$tts->synthesizeStream($text, 'en-US-AriaNeural', [], function (string $chunk): void {
    echo 'Received ' . strlen($chunk) . " bytes\n";
});

// To send it to a browser instead:
//
//     header('Content-Type: audio/mpeg');
//     foreach ($tts->stream($text, 'en-US-AriaNeural') as $chunk) {
//         echo $chunk;
//         flush();
//     }
