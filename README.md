# Edge TTS PHP

## Overview

**Edge TTS** is a powerful Text-to-Speech (TTS) package for PHP that leverages Microsoft Edge's speech synthesis capabilities. This package allows you to synthesize speech from text, manage voice options, and process audio streams with real-time callbacks through both programmatic and command-line interfaces.

## Features

- **Text-to-Speech**: Convert text into natural-sounding speech using Microsoft Edge's TTS capabilities.
- **Multiple Voices**: Access a wide variety of voices to suit your project's needs.
- **Real-time Streaming**: Support for audio streaming with real-time processing callbacks.
- **Word Boundaries Metadata**: Get word boundary information with precise timestamps.
- **Flexible Export Options**: Export synthesized audio in different formats (raw, base64, file, or PHP stream).
- **Command-Line Interface**: Use a simple CLI for easy access to functionality.
- **Easy Integration**: Modular structure allows for seamless inclusion in existing PHP projects.
- **Extended Compatibility**: Compatible from PHP 7.4+ to PHP 8.1+ 

## Requirements

- PHP 7.4+ (compatible up to PHP 8.1+) 
- PHP Extensions: `json`, `curl`
- Composer for dependency management

## Installation

You can install Edge TTS via Composer. Run the following command in your terminal:

```bash
composer require afaya/edge-tts
```

This package is a fork of the original [afaya/edge-tts](https://github.com/andresayac/edge-tts-php) package with improvements and fixes. We've downgraded the dependencies to be compatible from PHP 8.1+ to PHP 7.4+ while maintaining the original functionality .

## Usage

### Command-Line Interface

To synthesize speech from text:

```bash
php ./vendor/bin/edge-tts edge-tts:synthesize --text "Hello, world!"
```

To list available voices:

```bash
php ./vendor/bin/edge-tts edge-tts:voice-list
```

### Integration into Your Project

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

// Initialize the EdgeTTS service
$tts = new EdgeTTS();

// Get available voices
$voices = $tts->getVoices();
// var_dump($voices); // array -> use ShortName with the voice name

// Synthesize text with options for voice, rate, volume, and pitch
$tts->synthesize("Hello, world!", 'en-US-AriaNeural', [
    'rate'   => '0%',   // Speech rate (range: -100% to 100%)
    'volume' => '0%',   // Speech volume (range: -100% to 100%)
    'pitch'  => '0Hz'   // Voice pitch (range: -100Hz to 100Hz)
]);

// Export synthesized audio in different formats
$base64Audio = $tts->toBase64();    // Get audio as base64
$tts->toFile("output");             // Save audio to file
$rawAudio = $tts->toRaw();          // Get raw audio stream
```

### Real-time Streaming Synthesis

For real-time audio processing:

```php
$tts->synthesizeStream(
    "Your text here", 
    'en-US-AriaNeural', 
    ['rate' => '10%'],
    function($chunk) {
        // Process each audio chunk in real-time
        echo "Received chunk of " . strlen($chunk) . " bytes\n";
        // You can stream this directly to output, save incrementally, etc.
    }
);
```

## Available Methods

### Audio Information

```php
// Get basic audio information
$info = $tts->getAudioInfo();
// Returns: ['size' => bytes, 'format' => 'mp3', 'estimatedDuration' => seconds]

// Get estimated duration
$duration = $tts->getDuration();

// Get size in bytes
$size = $tts->getSizeBytes();
```

### Word Boundaries Metadata

```php
// Get word boundaries with timestamps
$boundaries = $tts->getWordBoundaries();

// Save metadata to file
$tts->saveMetadata('metadata.json');
```

### Export Options
After synthesizing speech, you can export the audio in various formats:

- `toBase64()`: Returns the audio as a Base64 string 
- `toFile($path)`: Saves the audio to a specified file 
- `toRaw()`: Returns the raw audio stream
- `toStream()`: Returns a PHP stream resource

## Voice Management

```php
// Get all available voices
$voices = $tts->getVoices();

// Each voice contains information like:
// - ShortName: The voice identifier to use in synthesis
// - DisplayName: Human-readable voice name
// - LocalName: Localized voice name
// - Gender: Voice gender
// - Locale: Language/region code
```

## Audio Configuration

The package uses high-quality audio settings by default:
- **Format**: MP3 (audio-24khz-48kbitrate-mono-mp3)
- **Sample Rate**: 24kHz
- **Bitrate**: 48kbps
- **Channels**: Mono

## Error Handling

The package includes comprehensive error handling:

```php
try {
    $tts->synthesize("Hello", 'invalid-voice');
} catch (InvalidArgumentException $e) {
    echo "Invalid voice: " . $e->getMessage();
} catch (RuntimeException $e) {
    echo "Runtime error: " . $e->getMessage();
}
```

## Testing
```bash
./vendor/bin/phpunit
```

## Contributing
We welcome contributions! Please read our CONTRIBUTING.md for guidelines on how to contribute to this project.

## License
This project is licensed under the GNU General Public License v3 (GPLv3).

## Acknowledgments

We would like to extend our gratitude to the developers and contributors of the following projects for their inspiration and groundwork:

* https://github.com/rany2/edge-tts/tree/master/examples
* https://github.com/rany2/edge-tts/blob/master/src/edge_tts/util.py
* https://github.com/hasscc/hass-edge-tts/blob/main/custom_components/edge_tts/tts.py