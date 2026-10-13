<?php

namespace Afaya\EdgeTTS\Transport;

use Afaya\EdgeTTS\Exception\EdgeTTSException;
use Composer\CaBundle\CaBundle;

/**
 * A minimal HTTP/1.1 client over a socket. It does not go through the URL
 * wrappers of PHP, so it works where allow_url_fopen is off, as on most
 * shared hosting.
 *
 * @internal
 */
final class Http
{
    /**
     * TLS options that verify the server. The CA bundle comes from the
     * system, or from composer/ca-bundle where PHP has none configured, as
     * on most Windows installations.
     */
    public static function sslOptions(string $host): array
    {
        $bundle = CaBundle::getSystemCaRootBundlePath();
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            is_dir($bundle) ? 'capath' : 'cafile' => $bundle,
        ];
    }

    /**
     * Opens a TCP connection, with TLS when $secure, and sets its read timeout.
     *
     * @return resource
     */
    public static function open(bool $secure, string $host, int $port, float $timeout)
    {
        $context = stream_context_create(['ssl' => self::sslOptions($host)]);
        $stream = @stream_socket_client(
            ($secure ? 'tls' : 'tcp') . "://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            $error = $errstr !== '' ? $errstr : (error_get_last()['message'] ?? 'unknown error');
            throw new EdgeTTSException("Could not connect to {$host}:{$port}: {$error}");
        }
        $whole = (int) $timeout;
        stream_set_timeout($stream, $whole, (int) (($timeout - $whole) * 1000000));
        return $stream;
    }

    /**
     * Splits an http(s) or ws(s) URL into what a request needs.
     *
     * @return array{secure: bool, host: string, port: int, target: string, hostHeader: string}
     */
    public static function target(string $url, array $schemes): array
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], $schemes, true)) {
            throw new EdgeTTSException("Invalid URL: {$url}");
        }
        $secure = in_array($parts['scheme'], ['https', 'wss'], true);
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        return [
            'secure' => $secure,
            'host' => $parts['host'],
            'port' => $port,
            'target' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''),
            'hostHeader' => $parts['host'] . (isset($parts['port']) ? ':' . $port : ''),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string} the status,
     *     the headers with lowercase names and the body
     */
    public static function get(string $url, array $headers, float $timeout): array
    {
        $target = self::target($url, ['http', 'https']);
        $stream = self::open($target['secure'], $target['host'], $target['port'], $timeout);
        try {
            $request = "GET {$target['target']} HTTP/1.1\r\n"
                . "Host: {$target['hostHeader']}\r\n"
                . "Connection: close\r\n";
            foreach ($headers as $name => $value) {
                $request .= "{$name}: {$value}\r\n";
            }
            self::writeAll($stream, $request . "\r\n");

            [$status, $responseHeaders] = self::readHead($stream, $target['host'], $timeout);
            $body = self::readBody($stream, $responseHeaders, $timeout);
        } finally {
            fclose($stream);
        }

        if (($responseHeaders['content-encoding'] ?? '') === 'gzip' && function_exists('gzdecode')) {
            $decoded = @gzdecode($body);
            $body = $decoded === false ? $body : $decoded;
        }
        return [$status, $responseHeaders, $body];
    }

    /**
     * Reads the status line and the headers of a response.
     *
     * @param resource $stream
     * @return array{0: int, 1: array<string, string>}
     */
    public static function readHead($stream, string $host, float $timeout): array
    {
        $raw = [];
        while (($line = fgets($stream)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            $raw[] = $line;
        }
        if (!$raw) {
            throw new EdgeTTSException(self::timedOut($stream)
                ? "No response from {$host} in {$timeout}s"
                : "{$host} closed the connection without answering");
        }
        return self::parseHeaders($raw);
    }

    /**
     * @param resource $stream
     * @param array<string, string> $headers
     */
    private static function readBody($stream, array $headers, float $timeout): string
    {
        if (stripos($headers['transfer-encoding'] ?? '', 'chunked') !== false) {
            $body = '';
            while (true) {
                $line = fgets($stream);
                if ($line === false) {
                    throw new EdgeTTSException('The response ended in the middle of a chunk');
                }
                $size = hexdec(trim(explode(';', $line, 2)[0]));
                if ($size === 0) {
                    break;
                }
                $body .= self::readExactly($stream, (int) $size, $timeout);
                fgets($stream);
            }
            return $body;
        }
        if (isset($headers['content-length'])) {
            return self::readExactly($stream, (int) $headers['content-length'], $timeout);
        }
        $body = stream_get_contents($stream);
        if (self::timedOut($stream)) {
            throw new EdgeTTSException("No response from the service for {$timeout}s");
        }
        return (string) $body;
    }

    /**
     * Reads the status and headers of the last response of $raw, which
     * holds every response when there were redirects.
     *
     * @param string[] $raw
     * @return array{0: int, 1: array<string, string>}
     */
    public static function parseHeaders(array $raw): array
    {
        $status = 0;
        $headers = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) {
                $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }
        return [$status, $headers];
    }

    /**
     * @param resource $stream
     */
    public static function writeAll($stream, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new EdgeTTSException('Could not write to the connection');
            }
            $data = (string) substr($data, $written);
        }
    }

    /**
     * @param resource $stream
     */
    public static function readExactly($stream, int $length, float $timeout): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                if (self::timedOut($stream)) {
                    throw new EdgeTTSException("No response from the service for {$timeout}s");
                }
                if (feof($stream)) {
                    throw new EdgeTTSException('The service closed the connection');
                }
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * @param resource $stream
     */
    public static function timedOut($stream): bool
    {
        return !empty(stream_get_meta_data($stream)['timed_out']);
    }
}
