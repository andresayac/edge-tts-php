# Edge TTS

## Overview

**Edge TTS** is a powerful Text-to-Speech (TTS) package for PHP that leverages Microsoft's Edge capabilities. This package allows you to synthesize speech from text and manage voice options easily through a command-line interface (CLI).

## Features

- **Text-to-Speech**: Convert text into natural-sounding speech using Microsoft Edge's TTS capabilities.
- **Multiple Voices**: Access a variety of voices to suit your project's needs.
- **Command-Line Interface**: Use a simple CLI for easy access to functionality.
- **Easy Integration**: Modular structure allows for easy inclusion in existing PHP projects.

## Installation

You can install Edge TTS via Composer. Run the following command in your terminal:

```bash
composer require afaya/edge-ts
```

## Usage
Command-Line Interface
To synthesize speech from text, use the following command:

```bash
php src/cli.php edge-tts:synthesize --text "Hello, world!"
```

To list available voices, run:

```bash
php src/cli.php edge-tts:voice-list
```


## Integration into Your Project
To use Edge TTS in your PHP project, include the autoload file:

```php
require 'vendor/autoload.php';

use Afaya\EdgeTS\EdgeTS; 

$tts = new EdgeTS(); 
$tts->synthesize("Hello, world!", "hello-world", "en-US-AriaNeural", "0%","0%","0Hz");
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
Symfony Console for building the CLI.
Microsoft Edge for TTS capabilities.

It is possible to use the `edge-tts` module directly from Python. For a list of example applications:

* https://github.com/rany2/edge-tts/tree/master/examples
* https://github.com/rany2/edge-tts/blob/master/src/edge_tts/util.py
* https://github.com/hasscc/hass-edge-tts/blob/main/custom_components/edge_tts/tts.py