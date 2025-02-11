# Edge TTS

## Overview

**Edge TTS** is a powerful Text-to-Speech (TTS) package for PHP that leverages Microsoft's Edge capabilities. This package allows you to synthesize speech from text and manage voice options easily through a command-line interface (CLI).

## Features

- **Text-to-Speech**: Convert text into natural-sounding speech using Microsoft Edge's TTS capabilities.
- **Multiple Voices**: Access a variety of voices to suit your project's needs.
- **Audio Export Options**: Export synthesized audio in different formats (raw, base64, or directly to a file).
- **Command-Line Interface**: Use a simple CLI for easy access to functionality.
- **Easy Integration**: Modular structure allows for easy inclusion in existing PHP projects.


Command-Line Interface: Use a simple CLI for easy access to functionality.
Easy Integration: Modular structure allows for easy inclusion in existing PHP projects.

## Installation

You can install Edge TTS via Composer. Run the following command in your terminal:

```bash
composer require php-cpm/edge-tts
```

this package is a fork of the original [afaya/edge-tts](https://github.com/andresayac/edge-tts-php) package, with some improvements and fixes.

we downgraded the dependencies to be compatible from php 8.1+ to php 7.4+ while keeping the original functionality.

## Usage
Command-Line Interface
To synthesize speech from text, use the following command:

```bash
php .\vendor\bin\edge-tts edge-tts:synthesize --text "Hello, world!"
```

To list available voices, run:

```bash
php .\vendor\bin\edge-tts edge-tts:voice-list
```


## Integration into Your Project
To use Edge TTS in your PHP project, include the autoload file:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Afaya\EdgeTTS\Service\EdgeTTS;

// Initialize the EdgeTTS service
$tts = new EdgeTTS();

// Get voices
$voices = $tts->getVoices();  
// var_dump($voices);  // array -> use ShortName with the name of the voice

// Synthesize text with options for voice, rate, volume, and pitch
$tts->synthesize("Hello, world!", 'en-US-AriaNeural', [
    'rate' => '0%',       // Speech rate (range: -100% to 100%)
    'volume' => '0%',     // Speech volume (range: -100% to 100%)
    'pitch' => '0Hz'      // Voice pitch (range: -100Hz to 100Hz)
]);

// Export synthesized audio in different formats
$base64Audio = $tts->toBase64();   // Get audio as base64
$tts->toFile("output.wav");        // Save audio to file
$rawAudio = $tts->toRaw();         // Get raw audio stream
```

## Export Options
After synthesizing speech, you can export the audio in various formats:

- ```toBase64```: Returns the audio as a Base64 string.
- ```toFile```: Saves the audio to a specified file (e.g., "output.wav").
- ```toRaw```: Returns the raw audio stream.

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