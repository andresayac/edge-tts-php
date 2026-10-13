<?php

namespace Afaya\EdgeTTS\Service;

use Afaya\EdgeTTS\Config\Constants;
use Afaya\EdgeTTS\Exception\EdgeTTSException;
use InvalidArgumentException;

/**
 * The messages of the read aloud service: the signature, the SSML, the
 * frames and the voice list.
 *
 * @internal
 */
final class Protocol
{
    // Seconds between the Windows epoch (1601-01-01) and the Unix epoch.
    private const WINDOWS_EPOCH_OFFSET = 11644473600;

    /**
     * How far the local clock is behind the service, in seconds. The
     * signature only holds for a five minute window, so a machine with a
     * wrong clock is refused until this is corrected from the Date header
     * of the refusal. It is shared by every client.
     */
    private static int $clockSkew = 0;

    public static function now(): int
    {
        return time() + self::$clockSkew;
    }

    /**
     * Sets the clock skew from the Date header of a response and tells
     * whether the header could be read.
     *
     * @param array<string, string> $headers response headers, with lowercase names
     */
    public static function adjustClock(array $headers): bool
    {
        $server = isset($headers['date']) ? strtotime($headers['date']) : false;
        if ($server === false) {
            return false;
        }
        self::$clockSkew = $server - time();
        return true;
    }

    public static function resetClock(): void
    {
        self::$clockSkew = 0;
    }

    /**
     * The Sec-MS-GEC token: a SHA-256 of the time, in Windows file time
     * rounded down to five minutes, followed by the client token.
     */
    public static function secMsGec(?int $time = null): string
    {
        $ticks = ($time ?? self::now()) + self::WINDOWS_EPOCH_OFFSET;
        $ticks -= $ticks % 300;
        // Appending the zeros keeps the number exact on 32-bit PHP too.
        return strtoupper(hash('sha256', $ticks . '0000000' . Constants::TRUSTED_CLIENT_TOKEN));
    }

    public static function randomHex(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function timestamp(): string
    {
        return gmdate('D M d Y H:i:s', self::now()) . ' GMT+0000 (Coordinated Universal Time)';
    }

    /**
     * Accepts "+10Hz", "-5.5Hz" or a number of hertz.
     *
     * @param int|float|string|null $pitch
     */
    public static function formatPitch($pitch): string
    {
        if ($pitch === null || $pitch === '') {
            return '+0Hz';
        }
        if (is_int($pitch) || is_float($pitch)) {
            return self::signed($pitch) . 'Hz';
        }
        $pitch = trim((string) $pitch);
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $pitch)) {
            return self::signed((float) $pitch) . 'Hz';
        }
        if (!preg_match('/^([+-]?\d{1,3}(\.\d+)?)hz$/i', $pitch, $m)) {
            throw new InvalidArgumentException(
                "Invalid pitch format. Expected a value from '-100Hz' to '+100Hz' or a number, got '{$pitch}'."
            );
        }
        return $m[1] . 'Hz';
    }

    /**
     * Accepts "+10%", "-25%" or a number of percent.
     *
     * @param int|float|string|null $rate
     */
    public static function formatRate($rate): string
    {
        $value = self::percent($rate);
        if ($value === null) {
            throw new InvalidArgumentException("Invalid rate format. Expected a percentage such as '+10%' or '-25%'.");
        }
        return self::signed($value) . '%';
    }

    /**
     * Accepts "+10%", "-25%" or a number from -100 to 100.
     *
     * @param int|float|string|null $volume
     */
    public static function formatVolume($volume): string
    {
        $value = self::percent($volume);
        if ($value === null || $value < -100 || $value > 100) {
            throw new InvalidArgumentException("Invalid volume. Expected a value from -100% to +100%.");
        }
        return self::signed($value) . '%';
    }

    /**
     * @param int|float|string|null $value
     * @return int|float|null
     */
    private static function percent($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = trim(rtrim(trim((string) $value), '%'));
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            return null;
        }
        return $value + 0;
    }

    /**
     * @param int|float $value
     */
    private static function signed($value): string
    {
        if ((float) $value == (int) $value) {
            $text = (string) (int) $value;
        } else {
            $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        return ($value >= 0 && $text[0] !== '-' ? '+' : '') . $text;
    }

    public static function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Drops what XML cannot carry; the service closes the connection
     * without audio when the SSML holds a control character.
     */
    public static function cleanText(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $text);
    }

    public static function ssml(string $text, string $voice, string $pitch, string $rate, string $volume): string
    {
        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="https://www.w3.org/2001/mstts" xml:lang="en-US">'
            . '<voice name="' . self::escapeXml($voice) . '">'
            . '<prosody pitch="' . $pitch . '" rate="' . $rate . '" volume="' . $volume . '">'
            . self::escapeXml(self::cleanText($text))
            . '</prosody></voice></speak>';
    }

    public static function speechConfigMessage(string $format): string
    {
        return "X-Timestamp:" . self::timestamp() . "\r\n"
            . "Content-Type:application/json; charset=utf-8\r\n"
            . "Path:speech.config\r\n\r\n"
            . '{"context":{"synthesis":{"audio":{"metadataoptions":{"sentenceBoundaryEnabled":false,"wordBoundaryEnabled":true},"outputFormat":'
            . json_encode($format) . '}}}}';
    }

    public static function ssmlMessage(string $requestId, string $ssml): string
    {
        return "X-RequestId:{$requestId}\r\n"
            . "Content-Type:application/ssml+xml\r\n"
            . "X-Timestamp:" . self::timestamp() . "\r\n"
            . "Path:ssml\r\n\r\n"
            . $ssml;
    }

    /**
     * Splits a text frame into its Path header and its body.
     *
     * @return array{0: string, 1: string}
     */
    public static function parseTextFrame(string $data): array
    {
        $parts = explode("\r\n\r\n", $data, 2);
        return [self::headerPath($parts[0]), $parts[1] ?? ''];
    }

    /**
     * Splits a binary frame, which starts with the length of its headers as
     * a big-endian 16-bit number, into its Path header and its payload.
     *
     * @return array{0: string, 1: string}
     */
    public static function parseBinaryFrame(string $data): array
    {
        if (strlen($data) < 2) {
            throw new EdgeTTSException('Binary frame too short.');
        }
        $length = unpack('n', $data)[1];
        if (strlen($data) < 2 + $length) {
            throw new EdgeTTSException('Binary frame headers longer than the frame.');
        }
        return [self::headerPath(substr($data, 2, $length)), (string) substr($data, 2 + $length)];
    }

    private static function headerPath(string $headers): string
    {
        foreach (explode("\r\n", $headers) as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2 && $pair[0] === 'Path') {
                return trim($pair[1]);
            }
        }
        return '';
    }

    /**
     * @return array<int, array{type: string, offset: int, duration: int, text: string}>
     */
    public static function parseWordBoundaries(string $body): array
    {
        $message = json_decode($body, true);
        if (!is_array($message) || !isset($message['Metadata']) || !is_array($message['Metadata'])) {
            return [];
        }
        $out = [];
        foreach ($message['Metadata'] as $meta) {
            if (($meta['Type'] ?? '') !== 'WordBoundary') {
                continue;
            }
            $out[] = [
                'type' => 'WordBoundary',
                'offset' => (int) ($meta['Data']['Offset'] ?? 0),
                'duration' => (int) ($meta['Data']['Duration'] ?? 0),
                'text' => (string) ($meta['Data']['text']['Text'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Gives every voice the same fields, whatever the service filled in, so
     * that DisplayName reads "Aria" and FriendlyName "Aria (Neural) - en-US".
     */
    public static function normalizeVoices(array $data): array
    {
        $out = [];
        foreach ($data as $v) {
            if (!is_array($v)) {
                continue;
            }
            $short = (string) ($v['ShortName'] ?? '');
            $locale = (string) ($v['Locale'] ?? '');

            $base = preg_replace('/^[a-z]{2}-[A-Z]{2}-/', '', $short);
            $base = preg_replace('/NeuralHD$/', '', $base);
            $base = trim(preg_replace('/Neural$/', '', $base));

            $voiceType = ($v['VoiceType'] ?? '') !== ''
                ? $v['VoiceType']
                : (stripos(($v['Name'] ?? '') . ' ' . $short, 'NeuralHD') !== false ? 'NeuralHD' : 'Neural');

            $localeName = ($v['LocaleName'] ?? '') !== '' ? $v['LocaleName'] : ($locale !== '' ? $locale : null);

            $display = self::firstNonEmpty([$v['DisplayName'] ?? '', $v['FriendlyName'] ?? '', $base, $short]);
            $display = preg_replace('/^Microsoft\s+/i', '', $display);
            $display = trim(explode(' - ', $display)[0]);
            $display = preg_replace('/\s*Online\s*\(Natural\)\s*/i', ' ', $display, 1);
            $display = preg_replace('/\s*Online\s*/i', ' ', $display, 1);
            $display = trim(preg_replace('/\s+/', ' ', $display));

            $tag = isset($v['VoiceTag']) && is_array($v['VoiceTag']) ? $v['VoiceTag'] : [];
            $tailored = $tag['TailoredScenarios'] ?? $tag['ContentCategories'] ?? [];
            $personalities = $tag['VoicePersonalities'] ?? [];

            $name = $short !== '' ? $short : (string) ($v['Name'] ?? '');
            $out[] = [
                'Name' => $name,
                'OriginalName' => (string) ($v['Name'] ?? ''),
                'DisplayName' => $display,
                'LocalName' => $display,
                'ShortName' => $name,
                'Gender' => $v['Gender'] ?? null,
                'Locale' => $locale !== '' ? $locale : null,
                'LocaleName' => $localeName,
                'SecondaryLocaleList' => is_array($v['SecondaryLocaleList'] ?? null) ? $v['SecondaryLocaleList'] : [],
                'VoiceType' => $voiceType,
                'VoiceTag' => [
                    'TailoredScenarios' => is_array($tailored) ? $tailored : [],
                    'VoicePersonalities' => is_array($personalities) ? $personalities : [],
                ],
                'FriendlyName' => "{$display} ({$voiceType}) - {$localeName}",
            ];
        }
        return $out;
    }

    /**
     * @param string[] $values
     */
    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if ((string) $value !== '') {
                return (string) $value;
            }
        }
        return '';
    }

    public static function fileExtension(string $format): string
    {
        $has = function (string $needle) use ($format): bool {
            return strpos($format, $needle) !== false;
        };
        if ($has('mp3')) return 'mp3';
        if ($has('opus') && $has('webm')) return 'webm';
        if ($has('opus') && $has('ogg')) return 'ogg';
        if ($has('wav') || $has('riff')) return 'wav';
        if ($has('pcm') && $has('raw')) return 'pcm';
        if ($has('alaw')) return 'alaw';
        if ($has('mulaw')) return 'mulaw';
        if ($has('truesilk')) return 'silk';
        if ($has('g722')) return 'g722';
        if ($has('amr')) return 'amr';
        return 'audio';
    }

    /**
     * The bitrate of the format, such as 48 for audio-24khz-48kbitrate-mono-mp3.
     * Formats without one (opus) assume 48 kbit/s.
     */
    public static function kbps(string $format): int
    {
        if (preg_match('/(\d+)kbitrate/', $format, $m) && (int) $m[1] > 0) {
            return (int) $m[1];
        }
        return 48;
    }
}
