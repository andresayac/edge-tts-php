<?php

namespace Afaya\EdgeTTS\Service;

/**
 * Writes boundaries as SRT or WebVTT subtitles, one cue per boundary.
 * Offsets and durations are in 100-nanosecond units, as the service sends them.
 */
final class Subtitles
{
    /**
     * @param array<int, array{offset: int, duration: int, text: string}> $cues
     */
    public static function toSRT(array $cues): string
    {
        $blocks = [];
        foreach (array_values($cues) as $i => $cue) {
            $blocks[] = ($i + 1) . "\n"
                . self::timestamp($cue['offset'], ',') . ' --> ' . self::timestamp($cue['offset'] + $cue['duration'], ',') . "\n"
                . $cue['text'] . "\n";
        }
        return implode("\n", $blocks);
    }

    /**
     * @param array<int, array{offset: int, duration: int, text: string}> $cues
     */
    public static function toVTT(array $cues): string
    {
        $blocks = [];
        foreach ($cues as $cue) {
            $blocks[] = self::timestamp($cue['offset'], '.') . ' --> ' . self::timestamp($cue['offset'] + $cue['duration'], '.') . "\n"
                . $cue['text'] . "\n";
        }
        return "WEBVTT\n\n" . implode("\n", $blocks);
    }

    private static function timestamp(int $ticks, string $separator): string
    {
        $total = max(0, (int) round($ticks / 10000));
        return sprintf(
            '%02d:%02d:%02d%s%03d',
            intdiv($total, 3600000),
            intdiv($total, 60000) % 60,
            intdiv($total, 1000) % 60,
            $separator,
            $total % 1000
        );
    }
}
