<?php

// A local stand-in for the service, for the tests of the real socket code.
// It prints its port, then serves one connection at a time until killed.
// The path picks the behavior, such as /ws/replay or /voices/chunked.

require __DIR__ . '/../../vendor/autoload.php';

use Afaya\EdgeTTS\Tests\Fake\FakeEdgeTTS;
use Afaya\EdgeTTS\Transport\WebSocket;

const VOICES = '[{"ShortName":"en-US-AriaNeural","Locale":"en-US","Gender":"Female"},{"ShortName":"es-CO-SalomeNeural","Locale":"es-CO","Gender":"Female"}]';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Cannot listen: {$errstr}\n");
    exit(1);
}
fwrite(STDOUT, parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT) . "\n");
fflush(STDOUT);

// Connections left open on purpose, and sockets kept so that their
// destructors do not close them.
$kept = [];

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }
    $requestLine = (string) fgets($conn);
    $headers = [];
    while (($line = fgets($conn)) !== false && rtrim($line) !== '') {
        $pair = explode(':', $line, 2);
        $headers[strtolower(trim($pair[0]))] = trim($pair[1] ?? '');
    }
    $route = (string) parse_url(explode(' ', $requestLine)[1] ?? '/', PHP_URL_PATH);
    $accept = base64_encode(sha1(($headers['sec-websocket-key'] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $upgrade = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";

    switch ($route) {
        case '/ws/replay':
        case '/ws/ping':
        case '/ws/refuse-voice':
            fwrite($conn, $upgrade);
            $socket = new WebSocket($conn);
            $kept[] = $socket;
            $socket->receive(); // speech.config
            $socket->receive(); // ssml
            if ($route === '/ws/refuse-voice') {
                fwrite($conn, FakeEdgeTTS::serverFrame(WebSocket::CLOSE, pack('n', 1007) . 'Unsupported voice xx-XX-NobodyNeural.'));
                break;
            }
            if ($route === '/ws/ping') {
                fwrite($conn, FakeEdgeTTS::serverFrame(WebSocket::PING, 'heartbeat'));
            }
            foreach (FakeEdgeTTS::session()['frames'] as $frame) {
                fwrite($conn, isset($frame['binary'])
                    ? FakeEdgeTTS::serverFrame(WebSocket::BINARY, base64_decode($frame['binary']))
                    : FakeEdgeTTS::serverFrame(WebSocket::TEXT, $frame['text']));
            }
            break;

        case '/ws/silent':
            fwrite($conn, $upgrade);
            $kept[] = $conn;
            break;

        case '/ws/no-handshake':
            $kept[] = $conn;
            break;

        case '/ws/bad-accept':
            fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: wrong\r\n\r\n");
            $kept[] = $conn;
            break;

        case '/ws/forbidden':
        case '/voices/forbidden':
            fwrite($conn, "HTTP/1.1 403 Forbidden\r\nDate: " . gmdate('D, d M Y H:i:s \G\M\T') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($conn);
            break;

        case '/voices/chunked':
            $body = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\n\r\n";
            foreach (str_split(VOICES, 40) as $chunk) {
                $body .= dechex(strlen($chunk)) . "\r\n{$chunk}\r\n";
            }
            fwrite($conn, $body . "0\r\n\r\n");
            fclose($conn);
            break;

        case '/voices/length':
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen(VOICES) . "\r\n\r\n" . VOICES);
            fclose($conn);
            break;

        case '/voices/eof':
            fwrite($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . VOICES);
            fclose($conn);
            break;

        case '/voices/gzip':
            $gzip = gzencode(VOICES);
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: " . strlen($gzip) . "\r\n\r\n" . $gzip);
            fclose($conn);
            break;

        default:
            fwrite($conn, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
            fclose($conn);
    }
}
