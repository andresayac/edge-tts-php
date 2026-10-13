# Changelog

The version numbers follow the Node.js package [`@andresaya/edge-tts`](https://github.com/andresayac/edge-tts): the same number means the same features.

## 1.9.1

Everything from 1.8.3 keeps working: same methods and the same options.

### Added
- `new EdgeTTS($options)`: `wssUrl`, `voicesUrl`, `secMsGecVersion`, `headers`, `timeout` and `voicesCacheTtl`, so a change on Microsoft's side can be handled without a new release. A misspelled option throws.
- `generate()` returns the audio, the word and sentence boundaries and the duration in a `SynthesisResult`, without changing the instance.
- Sentence boundaries (`getSentenceBoundaries()`) and subtitles: `toSRT()`, `toVTT()` and the `Subtitles` class. Opening punctuation such as ¿ and ¡, which the service drops, is restored.
- A `timeout` option per call.
- The voice list is cached for 10 minutes per instance.
- CLI: `--format`, `--subtitles`, `--file=-` to read from stdin, and `voice-list --locale --gender`.

### Fixed
- `toFile('speech.mp3')` wrote `speech.mp3.mp3`.

## 1.8.3

1.7 was refused with a 403 when the service moved to a new endpoint. This version uses `api.msedgeservices.com` with Edge 155 headers, and also:

- Throws exceptions when the synthesis fails, instead of printing the error and leaving no audio. They carry the reason the service gives, such as an unsupported voice or output format.
- No longer needs ReactPHP, Ratchet or ramsey/uuid, so it no longer runs a global event loop.
- Works with `allow_url_fopen` off, as on most shared hosting; the voice list needed it.
- Keeps the audio in one string, so reading it back costs no copy: two hours of audio fit in the default 128 MB memory limit.
- Verifies TLS certificates, with composer/ca-bundle where PHP has no CA bundle.
- Starts from scratch on every synthesis: the audio and the word boundaries of the previous one used to pile up.
- Estimates the duration from the bitrate of the format.
- Accepts numbers for pitch, rate and volume, and any output format.
- An empty text throws `InvalidArgumentException` without connecting.
- Adds `stream()`, `getVoicesByLanguage()` and `getVoicesByGender()`; `toFile()` returns the path it wrote.
- The CLI runs with Symfony Console 5.4 to 8, from `vendor/bin`, and has the `synthesize` and `voice-list` names of the Node CLI.
