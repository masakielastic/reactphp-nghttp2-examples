<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Varion\Nghttp2\Events\DataReceived;
use Varion\Nghttp2\Events\GoawayReceived;
use Varion\Nghttp2\Events\HeadersReceived;
use Varion\Nghttp2\Events\StreamClosed;
use Varion\Nghttp2\ResponseHead;
use Varion\Nghttp2\Session;
use Varion\Nghttp2\StreamEvent;

if (!extension_loaded('nghttp2')) {
    fwrite(STDERR, "The nghttp2 extension is not loaded.\n");
    exit(1);
}

// Parse CLI arguments using the same contract as server-minimal.php.
$config = parseServerArgs($argv);
$host = $config['address'];
$port = $config['port'];
$tlsEnabled = $config['tls'];
$keyFile = $config['private_key'];
$certFile = $config['cert'];

if ($tlsEnabled) {
    // Fail fast so runtime TLS handshakes do not fail later with less clear messages.
    if (!is_file($keyFile) || !is_readable($keyFile)) {
        fwrite(STDERR, "TLS private key file is missing or unreadable: {$keyFile}\n");
        exit(1);
    }

    if (!is_file($certFile) || !is_readable($certFile)) {
        fwrite(STDERR, "TLS certificate file is missing or unreadable: {$certFile}\n");
        exit(1);
    }
}

$scheme = $tlsEnabled ? 'tls' : 'tcp';
$listen = sprintf('%s://%s:%d', $scheme, $host, $port);
$context = $tlsEnabled
    ? [
        // These context options are applied by ReactPHP to each accepted TLS connection.
        'tls' => [
            'local_cert' => $certFile,
            'local_pk' => $keyFile,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
            'disable_compression' => true,
            'alpn_protocols' => 'h2',
        ],
    ]
    : [];

$loop = Loop::get();

try {
    // One server handles all incoming sockets on this address inside the event loop.
    $server = new SocketServer($listen, $context, $loop);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to listen on {$listen}: {$e->getMessage()}\n");
    exit(1);
}

if ($tlsEnabled) {
    fwrite(STDERR, "Listening on {$listen} (HTTP/2 over TLS via ALPN)\n");
    fwrite(STDERR, "Try: nghttp -v https://{$host}:{$port}/ --no-verify-peer\n");
    fwrite(STDERR, "Try: curl --http2 -k -v https://{$host}:{$port}/\n");
} else {
    fwrite(STDERR, "Listening on {$listen} (HTTP/2 cleartext h2c prior knowledge)\n");
    fwrite(STDERR, "Try: nghttp -v http://{$host}:{$port}/\n");
    fwrite(STDERR, "Try: curl --http2-prior-knowledge -v http://{$host}:{$port}/\n");
}

$server->on('error', static function (Throwable $e): void {
    fwrite(STDERR, "server error: {$e->getMessage()}\n");
});

$server->on('connection', static function (ConnectionInterface $connection) use ($tlsEnabled): void {
    // In TLS mode, enforce ALPN=h2 so this sample only speaks HTTP/2.
    if ($tlsEnabled) {
        $alpn = getNegotiatedAlpn($connection);
        if ($alpn !== 'h2') {
            fwrite(STDERR, "ALPN negotiation did not select h2 (got: " . ($alpn ?? 'none') . ")\n");
            $connection->close();
            return;
        }
    }

    // Create a fresh HTTP/2 state machine per TCP/TLS connection.
    $session = new Session(Session::ROLE_SERVER);
    // Keep per-stream request metadata until StreamClosed arrives.
    $streamMeta = [];
    $closed = false;

    try {
        // A newly created server session may already have control output queued.
        flushOutput($session, $connection);
    } catch (Throwable $e) {
        fwrite(STDERR, "socket write failed: {$e->getMessage()}\n");
        $connection->close();
        return;
    }

    $finalize = static function () use (&$closed, &$streamMeta): void {
        if ($closed) {
            return;
        }
        $closed = true;

        if (count($streamMeta) > 0) {
            fwrite(
                STDERR,
                "connection ended with unfinished streams: " . implode(', ', array_keys($streamMeta)) . "\n"
            );
        }
    };

    $connection->on('data', static function (string $bytes) use ($connection, $session, &$streamMeta, &$closed, $finalize): void {
        if ($closed) {
            return;
        }

        try {
            // Feed wire bytes into the Sans-I/O engine.
            $session->receive($bytes);
        } catch (Throwable $e) {
            fwrite(STDERR, "receive error: {$e->getMessage()}\n");
            $connection->close();
            return;
        }

        try {
            $shouldClose = consumeConnectionEvents($session, $streamMeta);
            // Even when GOAWAY was received, flush once so pending control/output frames are not dropped.
            flushOutput($session, $connection);
        } catch (Throwable $e) {
            fwrite(STDERR, "socket write failed: {$e->getMessage()}\n");
            $connection->close();
            return;
        }

        if ($shouldClose) {
            // GOAWAY path: close transport after final flush.
            $connection->close();
            return;
        }
    });

    $connection->on('error', static function (Throwable $e): void {
        fwrite(STDERR, "connection handling error: {$e->getMessage()}\n");
    });

    $connection->on('close', static function () use ($finalize): void {
        $finalize();
    });
});

$loop->run();

function parseServerArgs(array $argv): array
{
    // Argument behavior intentionally mirrors server-minimal.php.
    $script = basename($argv[0] ?? 'server.php');
    $args = array_slice($argv, 1);
    $positionals = [];
    $address = '127.0.0.1';

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (str_starts_with($arg, '--address=')) {
            $address = substr($arg, strlen('--address='));
            continue;
        }
        if ($arg === '--address') {
            $i++;
            if (!isset($args[$i])) {
                fwrite(STDERR, "--address requires a value\n");
                printUsage($script);
                exit(1);
            }
            $address = $args[$i];
            continue;
        }
        if (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            printUsage($script);
            exit(1);
        }
        $positionals[] = $arg;
    }

    if (count($positionals) !== 1 && count($positionals) !== 3) {
        printUsage($script);
        exit(1);
    }

    $portRaw = $positionals[0];
    if (!ctype_digit($portRaw)) {
        fwrite(STDERR, "Invalid port: {$portRaw}\n");
        exit(1);
    }
    $port = (int) $portRaw;
    if ($port < 1 || $port > 65535) {
        fwrite(STDERR, "Port out of range: {$port}\n");
        exit(1);
    }

    $tlsEnabled = count($positionals) === 3;
    $privateKey = $tlsEnabled ? $positionals[1] : null;
    $cert = $tlsEnabled ? $positionals[2] : null;

    return [
        'address' => $address,
        'port' => $port,
        'tls' => $tlsEnabled,
        'private_key' => $privateKey,
        'cert' => $cert,
    ];
}

function printUsage(string $script): void
{
    fwrite(STDERR, "Usage: php -d extension=modules/nghttp2.so {$script} <PORT> [<PRIVATE_KEY> <CERT>] [--address=<ADDR>]\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Examples:\n");
    fwrite(STDERR, "  TLS (HTTP/2 over TLS):\n");
    fwrite(STDERR, "    php -d extension=modules/nghttp2.so {$script} 8443 ./certs/server.key ./certs/server.crt --address=127.0.0.1\n");
    fwrite(STDERR, "  h2c (HTTP/2 cleartext prior knowledge):\n");
    fwrite(STDERR, "    php -d extension=modules/nghttp2.so {$script} 8080 --address=127.0.0.1\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "curl test requests:\n");
    fwrite(STDERR, "  TLS:\n");
    fwrite(STDERR, "    curl --http2 -k -v https://127.0.0.1:8443/\n");
    fwrite(STDERR, "  h2c:\n");
    fwrite(STDERR, "    curl --http2-prior-knowledge -v http://127.0.0.1:8080/\n");
}

function getNegotiatedAlpn(ConnectionInterface $connection): ?string
{
    // React\Socket exposes the underlying stream through its internal Connection class.
    if (!$connection instanceof Connection || !is_resource($connection->stream)) {
        return null;
    }

    $meta = stream_get_meta_data($connection->stream);
    if (!isset($meta['crypto']) || !is_array($meta['crypto'])) {
        return null;
    }

    foreach (['alpn_protocol', 'ssl_alpn_protocol', 'alpn_selected', 'negotiated_protocol'] as $key) {
        $value = $meta['crypto'][$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function consumeConnectionEvents(Session $session, array &$streamMeta): bool
{
    // Return true when the connection should close (for example GOAWAY).
    // Return false when frame/event processing can continue.
    while (($event = $session->nextEvent()) !== null) {
        if ($event instanceof GoawayReceived) {
            // This example intentionally simplifies GOAWAY handling by ending the
            // connection loop after one final flush in the caller.
            fwrite(STDERR, "peer sent GOAWAY; closing connection\n");
            return true;
        }

        if (!($event instanceof StreamEvent)) {
            continue;
        }

        $sid = (int) $event->streamId;

        if ($event instanceof HeadersReceived) {
            $headers = is_array($event->headers) ? $event->headers : [];
            // A request can carry multiple HEADERS blocks (for example trailers).
            // Keep all blocks for visibility, but preserve the first block as the primary request headers.
            $streamMeta[$sid]['header_blocks'][] = [
                'headers' => $headers,
                'end_stream' => $event->endStream,
            ];
            // Use the first header block as request metadata for response decisions.
            // Later header blocks (trailers) are preserved for inspection only.
            $streamMeta[$sid]['initial_headers'] = $streamMeta[$sid]['initial_headers'] ?? $headers;
            $streamMeta[$sid]['body'] = $streamMeta[$sid]['body'] ?? '';
            $streamMeta[$sid]['responded'] = $streamMeta[$sid]['responded'] ?? false;

            if ($event->endStream && !($streamMeta[$sid]['responded'] ?? false)) {
                $initialHeaders = $streamMeta[$sid]['initial_headers'] ?? [];
                respond($session, $sid, $initialHeaders, $streamMeta[$sid]['body']);
                $streamMeta[$sid]['responded'] = true;
            }
            continue;
        }

        if ($event instanceof DataReceived) {
            if (!isset($streamMeta[$sid]['initial_headers'])) {
                fwrite(STDERR, "stream {$sid} received DATA before request headers; proceeding with minimal fallback\n");
            }
            $streamMeta[$sid]['body'] = ($streamMeta[$sid]['body'] ?? '') . (string) $event->data;
            $streamMeta[$sid]['responded'] = $streamMeta[$sid]['responded'] ?? false;

            if ($event->endStream && !($streamMeta[$sid]['responded'] ?? false)) {
                $initialHeaders = $streamMeta[$sid]['initial_headers'] ?? [];
                respond($session, $sid, $initialHeaders, $streamMeta[$sid]['body']);
                $streamMeta[$sid]['responded'] = true;
            }
            continue;
        }

        if ($event instanceof StreamClosed) {
            $responded = (bool) ($streamMeta[$sid]['responded'] ?? false);
            if (!$responded) {
                $bodyLen = strlen((string) ($streamMeta[$sid]['body'] ?? ''));
                fwrite(STDERR, "stream {$sid} closed before response (request_body_length={$bodyLen})\n");
            }
            if ($event->errorCode !== 0) {
                fwrite(STDERR, "stream {$sid} closed with error: {$event->errorCode}\n");
            }
            // Remove per-stream state after terminal notification.
            unset($streamMeta[$sid]);
        }
    }

    return false;
}

function respond(Session $session, int $streamId, array $requestHeaders, string $requestBody): void
{
    // Build a compact diagnostic payload so learners can inspect stream state transitions.
    $method = $requestHeaders[':method'] ?? 'GET';
    $path = $requestHeaders[':path'] ?? '/';

    $payload = [
        'ok' => true,
        'stream_id' => $streamId,
        'method' => $method,
        'path' => $path,
        'request_body_length' => strlen($requestBody),
        'state' => $session->getStreamState($streamId),
        'open_stream_count' => $session->getOpenStreamCount(),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{"ok":false}';
    }

    $headers = [
        'content-type' => 'application/json; charset=utf-8',
        'content-length' => (string) strlen($json),
        'server' => 'ext-nghttp2-example',
    ];

    // Send response headers without ending the stream, then end with the final DATA frame.
    $session->submitResponse($streamId, new ResponseHead(200, $headers, false));
    $session->writeData($streamId, $json, true);
}

function flushOutput(Session $session, ConnectionInterface $connection): void
{
    // drainOutput() may contain multiple protocol frames; write each chunk in order.
    foreach ($session->drainOutput() as $chunk) {
        $ok = $connection->write($chunk);
        if ($ok === false) {
            throw new RuntimeException('socket write failed');
        }
    }
}
