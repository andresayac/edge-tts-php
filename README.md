# Edge TTS for PHP

**Edge TTS** turns text into natural-sounding speech with the online text-to-speech service of Microsoft Edge, without Edge, Windows or an API key.

## Available in three languages

The same library for the Edge text-to-speech service, in Node.js, PHP and Go:

| Language | Install | Repository |
| --- | --- | --- |
| Node.js and browsers | `npm install @andresaya/edge-tts` | [andresayac/edge-tts](https://github.com/andresayac/edge-tts) |
| **PHP** (this one) | `composer require afaya/edge-tts` | [andresayac/edge-tts-php](https://github.com/andresayac/edge-tts-php) |
| Go | `go get github.com/andresayac/edge-tts-go` | [andresayac/edge-tts-go](https://github.com/andresayac/edge-tts-go) |

## Features

- **Text-to-speech** with more than 300 neural voices in over 100 locales.
- **Streaming**: get the audio as it arrives, with a `foreach` or a callback.
- **Word and sentence boundaries**: know when each word and sentence is spoken.
- **Subtitles**: SRT or WebVTT, one cue per sentence.
- **Voice filtering** by language and gender, with the list cached for 10 minutes.
- **Pitch, rate and volume** control.
- **Configurable**: override the endpoint, headers and timeout without waiting for a release.
- **No event loop**: it works inside Laravel, Symfony, WordPress or a plain script.
- **Verified TLS**, on Windows too, without configuring a CA bundle.
- **Command-line tool** included.

## Requirements

- PHP 7.4 or later (tested on 7.4, 8.1, 8.2, 8.3 and 8.4)
- The `json` and `openssl` extensions

## Installation

```bash
composer require afaya/edge-tts
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

$tts = new EdgeTTS();
$tts->synthesize('Hello, world!', 'en-US-AriaNeural');

echo $tts->toFile('hello'); // hello.mp3, the extension comes from the format
```

## Command-line interface

```bash
# Synthesize text to hello.mp3
php vendor/bin/edge-tts synthesize --text "Hello, world!" -o hello

# Read the text from a file, with another voice and prosody, and write subtitles too
php vendor/bin/edge-tts synthesize -f story.txt --voice es-CO-SalomeNeural -r +10% -p -5Hz -o story --subtitles story.srt

# Read from stdin, WebM/Opus output, WebVTT subtitles
cat story.txt | php vendor/bin/edge-tts synthesize --file=- --format webm-24khz-16bit-mono-opus -o story --subtitles story.vtt

# List the voices, optionally filtered
php vendor/bin/edge-tts voice-list
php vendor/bin/edge-tts voice-list --locale es-CO --gender female
```

| Option | Description | Default |
| --- | --- | --- |
| `-t, --text` | Text to convert to audio | |
| `-f, --file` | Input file (text); `--file=-` reads from stdin | |
| `--voice` | Voice to use | `en-US-AriaNeural` |
| `-r, --rate` | Rate of speech | `0%` |
| `-l, --volume` | Volume of speech | `0%` |
| `-p, --pitch` | Pitch of speech | `0Hz` |
| `-o, --output` | Output file name; the extension is added when missing | `output_<timestamp>` |
| `--format` | Output format | `audio-24khz-48kbitrate-mono-mp3` |
| `--subtitles` | Write subtitles: `.srt`, or `.vtt` for WebVTT | |

`edge-tts:synthesize` and `edge-tts:voice-list` still work as names of the commands.

## API

### Configuration

Every option is optional. The defaults come from `Constants`, so when Microsoft changes the service you can set the new values yourself instead of waiting for a release.

```php
$tts = new EdgeTTS([
    'wssUrl'          => 'wss://api.msedgeservices.com/tts/cognitiveservices/websocket/v1',
    'voicesUrl'       => 'https://speech.platform.bing.com/consumer/speech/synthesize/readaloud/voices/list',
    'secMsGecVersion' => '1-155.0.4283.45',
    'headers'         => ['User-Agent' => '...'], // added to the default headers
    'timeout'         => 30,                      // seconds without data before giving up
    'voicesCacheTtl'  => 600,                     // seconds to reuse the voice list, 0 to always fetch it
]);
```

A misspelled option throws `InvalidArgumentException`.

### Voices

```php
$voices   = $tts->getVoices();                  // every voice
$english  = $tts->getVoicesByLanguage('en');    // en-US, en-GB, en-IN...
$american = $tts->getVoicesByLanguage('en-US');
$female   = $tts->getVoicesByGender('Female');
$voice    = $tts->checkVoice('en-US-AriaNeural'); // throws InvalidArgumentException when it does not exist

foreach ($voices as $voice) {
    echo $voice['ShortName'] . ' ' . $voice['FriendlyName'] . PHP_EOL; // en-US-AriaNeural Aria (Neural) - en-US
}
```

Each voice has `Name`, `OriginalName`, `ShortName`, `DisplayName`, `LocalName`, `Gender`, `Locale`, `LocaleName`, `SecondaryLocaleList`, `VoiceType`, `VoiceTag` and `FriendlyName`.

### Synthesis

```php
use Afaya\EdgeTTS\Config\Constants;

$tts->synthesize('Hello, world!', 'en-US-AriaNeural', [
    'rate'         => '+50%',  // -100% and up
    'volume'       => '-10%',  // -100% to +100%
    'pitch'        => '+20Hz', // -100Hz to +100Hz
    'outputFormat' => Constants::OUTPUT_FORMAT['AUDIO_24KHZ_96KBITRATE_MONO_MP3'],
    'timeout'      => 60,      // seconds, for this call only
]);
```

Every option is optional, and numbers work too: `'rate' => 50` is `+50%` and `'pitch' => -5` is `-5Hz`.

#### Output formats

| `Constants::OUTPUT_FORMAT` key | Format | Extension |
| --- | --- | --- |
| `AUDIO_24KHZ_48KBITRATE_MONO_MP3` (default) | `audio-24khz-48kbitrate-mono-mp3` | `.mp3` |
| `AUDIO_24KHZ_96KBITRATE_MONO_MP3` | `audio-24khz-96kbitrate-mono-mp3` | `.mp3` |
| `WEBM_24KHZ_16BIT_MONO_OPUS` | `webm-24khz-16bit-mono-opus` | `.webm` |

These are the only formats the service accepts. It refuses the other Azure formats (WAV, PCM, OGG, 48 kHz MP3...) with "Unsupported Edge output format".

#### Matching the voice to the text

Voices are locale specific. A voice can only read text written in the script of its own language, so passing Chinese, Cyrillic, Arabic or Devanagari text to an English voice returns no audio. `synthesize()` then throws `EmptyAudioException`:

```php
use Afaya\EdgeTTS\Exception\EmptyAudioException;

try {
    $tts->synthesize('这是一个测试', 'en-US-AriaNeural');
} catch (EmptyAudioException $e) {
    // Use a Chinese voice instead, such as zh-CN-XiaoxiaoNeural.
}
```

### Streaming

`stream()` yields the audio as it arrives, so you can start playing or sending it before the synthesis ends:

```php
header('Content-Type: audio/mpeg');

foreach ($tts->stream('Long text to stream...', 'en-US-AriaNeural') as $chunk) {
    echo $chunk;
    flush();
}
```

Leaving the loop early closes the connection. If you prefer a callback:

```php
$tts->synthesizeStream('Long text to stream...', 'en-US-AriaNeural', [], function (string $chunk) {
    file_put_contents('out.mp3', $chunk, FILE_APPEND);
});
```

### Everything in one result

`generate()` returns the audio, the boundaries and the duration in a `SynthesisResult`, and leaves the instance as it was:

```php
$result = $tts->generate('¿Cómo estás? ¡Qué bien!', 'es-CO-SalomeNeural');

$result->audio;              // binary string
$result->format;             // 'mp3'
$result->outputFormat;       // 'audio-24khz-48kbitrate-mono-mp3'
$result->wordBoundaries;
$result->sentenceBoundaries;
$result->duration;           // estimated, in seconds

$result->toFile('greeting'); // greeting.mp3
$result->toSRT();            // or toVTT()
```

### Getting the audio

After `synthesize()`, `stream()` or `synthesizeStream()`:

```php
$raw    = $tts->toRaw();            // binary string
$base64 = $tts->toBase64();         // base64 string
$path   = $tts->toFile('out/audio'); // out/audio.mp3
$path   = $tts->toFile('out/audio.mp3'); // out/audio.mp3, the extension is not repeated
$path   = $tts->toFile('out/audio', 'bin'); // out/audio.bin
$stream = $tts->toStream();         // php://memory resource

$info = $tts->getAudioInfo();       // ['size' => 46944, 'format' => 'mp3', 'estimatedDuration' => 3.912]
$seconds = $tts->getDuration();     // estimated from the size and bitrate
$bytes = $tts->getSizeBytes();
```

### Word and sentence boundaries

```php
foreach ($tts->getWordBoundaries() as $word) {
    // Offsets and durations are in 100-nanosecond units.
    printf("%.2fs %s\n", $word['offset'] / 1e7, $word['text']);
}

$tts->getSentenceBoundaries(); // the same, one per sentence
$tts->saveMetadata('words.json');
```

The service leaves the opening punctuation out of its sentences, so "¿Cómo estás?" comes back as "Cómo estás?". The library puts it back from your text: ¿ ¡ « " ' “ ‘ ( [.

### Subtitles

```php
file_put_contents('speech.srt', $tts->toSRT()); // one cue per sentence
file_put_contents('speech.vtt', $tts->toVTT());

use Afaya\EdgeTTS\Service\Subtitles;

Subtitles::toSRT($tts->getWordBoundaries());    // one cue per word
```

```
1
00:00:00,050 --> 00:00:01,600
¿Cómo estás?

2
00:00:01,600 --> 00:00:02,950
¡Qué bien!
```

### Errors

| Exception | When |
| --- | --- |
| `InvalidArgumentException` | An empty text, an invalid pitch, rate or volume, or `checkVoice()` with an unknown voice |
| `Afaya\EdgeTTS\Exception\EmptyAudioException` | The voice cannot read the text |
| `Afaya\EdgeTTS\Exception\HandshakeException` | The service refused the connection; `getStatusCode()` has the HTTP status |
| `Afaya\EdgeTTS\Exception\EdgeTTSException` | Any other error of the service or the network, such as "The service refused the request: Unsupported voice xx-XX-NobodyNeural." |
| `RuntimeException` | Asking for audio or metadata before a synthesis |

Every exception of the service extends `EdgeTTSException`, which extends `RuntimeException`.

A machine whose clock is off by more than a few minutes is refused by the service. The client then corrects its clock from the response and tries once more.

## Upgrading

Version 1.7 stopped working when Microsoft moved the service to a new endpoint; 1.8.3 and later use the new one. What changed in each version, and what to look at when upgrading, is in the [CHANGELOG](CHANGELOG.md).

## Examples

- [`examples/basic.php`](examples/basic.php): synthesize to a file and list voices.
- [`examples/stream.php`](examples/stream.php): stream the audio with a generator and with a callback.
- [`examples/subtitles.php`](examples/subtitles.php): generate the audio and its subtitles in one call.

## Testing

```bash
composer install
composer test
```

The tests replay a recorded session of the service, through a fake and through a local server on real sockets, so they need no network.

## License

GPL-3.0-or-later. See [LICENSE.txt](LICENSE.txt).

## Acknowledgments

- https://github.com/rany2/edge-tts
- https://github.com/hasscc/hass-edge-tts
