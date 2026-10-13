<?php

namespace Afaya\EdgeTTS\Service;

use RuntimeException;

/**
 * What generate() returns: the audio of one synthesis with its boundaries,
 * kept apart from the EdgeTTS instance.
 */
final class SynthesisResult
{
    /** The audio as a binary string. */
    public string $audio;

    /** The file extension of the format, such as "mp3". */
    public string $format;

    /** The output format, such as "audio-24khz-48kbitrate-mono-mp3". */
    public string $outputFormat;

    /** @var array<int, array{type: string, offset: int, duration: int, text: string}> */
    public array $wordBoundaries;

    /** @var array<int, array{type: string, offset: int, duration: int, text: string}> */
    public array $sentenceBoundaries;

    /** Estimated length of the audio in seconds. */
    public float $duration;

    public function __construct(
        string $audio,
        string $outputFormat,
        array $wordBoundaries,
        array $sentenceBoundaries
    ) {
        $this->audio = $audio;
        $this->outputFormat = $outputFormat;
        $this->format = Protocol::fileExtension($outputFormat);
        $this->wordBoundaries = $wordBoundaries;
        $this->sentenceBoundaries = $sentenceBoundaries;
        $this->duration = strlen($audio) * 8 / (Protocol::kbps($outputFormat) * 1000);
    }

    /**
     * Writes the audio to $path, adding the extension of the format unless
     * $path already ends with it, and returns the path it wrote.
     */
    public function toFile(string $path): string
    {
        $path = Protocol::filePath($path, $this->format);
        if (@file_put_contents($path, $this->audio) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Failed to write the audio to {$path}: {$error}");
        }
        return $path;
    }

    /**
     * SRT subtitles, one cue per sentence.
     */
    public function toSRT(): string
    {
        return Subtitles::toSRT($this->sentenceBoundaries ?: $this->wordBoundaries);
    }

    /**
     * WebVTT subtitles, one cue per sentence.
     */
    public function toVTT(): string
    {
        return Subtitles::toVTT($this->sentenceBoundaries ?: $this->wordBoundaries);
    }
}
