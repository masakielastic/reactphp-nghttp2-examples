<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Varion\Nghttp2\Events\DataReceived;
use Varion\Nghttp2\Events\HeadersReceived;
use Varion\Nghttp2\Events\StreamClosed;
use Varion\Nghttp2\Events\StreamReset;
use Varion\Nghttp2\RequestHead;
use Varion\Nghttp2\Session;
use Varion\Nghttp2\StreamEvent;

// Resolve destination from CLI args. Defaults match the minimal example.
$config = parseClientArgs($argv);
$host = $config['host'];
$port = $config['port'];
$path = $config['path'];

// Create the ReactPHP event loop and async connector.
$loop = Loop::get();
$connector = new Connector([
    'timeout' => 10.0,
    'tls' => [
        // Require verified TLS and explicitly request HTTP/2 via ALPN.
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
        'alpn_protocols' => 'h2',
    ],
], $loop);

// Session is the HTTP/2 state machine itself; I/O is handled separately.
$session = new Session(Session::ROLE_CLIENT);
$responseHeaderBlocks = [];
$responseBody = '';
$targetStreamId = null;
$done = false;
$closedByUs = false;

// Shared failure handler for connection/protocol errors.
$fail = static function (string $message, ?ConnectionInterface $connection = null) use (&$closedByUs, $loop): void {
    fwrite(STDERR, $message . "\n");

    if ($connection !== null) {
        $closedByUs = true;
        $connection->close();
    }

    $loop->stop();
};

$connector->connect("tls://{$host}:{$port}")->then(
    function (ConnectionInterface $connection) use (
        $session,
        $host,
        $path,
        &$responseHeaderBlocks,
        &$responseBody,
        &$targetStreamId,
        &$done,
        &$closedByUs,
        $loop,
        $fail
    ): void {
        try {
            // Verify ALPN after TLS handshake and abort unless HTTP/2 ("h2") was selected.
            $alpn = getNegotiatedAlpn($connection);
            if ($alpn !== 'h2') {
                throw new RuntimeException('ALPN negotiation failed (h2 not selected)');
            }

            // Send the client preface and initial SETTINGS.
            flushSessionOutput($session, $connection);

            // Send GET /httpbin/get with no request body (endStream=true).
            $targetStreamId = $session->submitRequest(new RequestHead(
                'GET',
                'https',
                $host,
                $path,
                [
                    'accept' => 'application/json',
                    'user-agent' => 'ext-nghttp2-example/0.1',
                ],
                true
            ));
            // submitRequest() only enqueues outbound frames; flush to actually send them.
            flushSessionOutput($session, $connection);
        } catch (Throwable $e) {
            $fail('Error: ' . $e->getMessage(), $connection);
            return;
        }

            $connection->on('data', function (string $in) use (
            $session,
            $connection,
            &$responseHeaderBlocks,
            &$responseBody,
            &$targetStreamId,
            &$done,
            &$closedByUs,
            $loop,
            $fail
        ): void {
            if ($done) {
                return;
            }

            try {
                // receive() may create outbound protocol frames (for example ACK/WINDOW_UPDATE),
                // so drain and write output right after feeding inbound bytes.
                $session->receive($in);
                flushSessionOutput($session, $connection);

                if ($targetStreamId === null) {
                    return;
                }

                // Connection-level events (for example GOAWAY) are intentionally omitted
                // in this minimal example.
                $done = consumeResponseEvents($session, $targetStreamId, $responseHeaderBlocks, $responseBody);
                if ($done) {
                    // Print headers and body separately for easier inspection.
                    echo "=== HEADER BLOCKS ===\n";
                    var_export($responseHeaderBlocks);
                    echo "\n\n=== BODY ===\n";
                    echo $responseBody . "\n";

                    $closedByUs = true;
                    $connection->close();
                    $loop->stop();
                }
            } catch (Throwable $e) {
                $fail('Error: ' . $e->getMessage(), $connection);
            }
        });

        $connection->on('error', function (Throwable $e) use ($connection, $fail): void {
            $fail('Socket error: ' . $e->getMessage(), $connection);
        });

        $connection->on('close', function () use (&$done, &$closedByUs, $loop, $fail): void {
            if ($done || $closedByUs) {
                return;
            }

            $fail('Error: connection closed before target stream completed');
        });
    },
    function (Throwable $e) use ($fail): void {
        $fail('Connect failed: ' . $e->getMessage());
    }
);

$loop->run();

function flushSessionOutput(Session $session, ConnectionInterface $connection): void
{
    // Send all outbound frame chunks produced by the Session.
    foreach ($session->drainOutput() as $chunk) {
        $connection->write($chunk);
    }
}

function getNegotiatedAlpn(ConnectionInterface $connection): ?string
{
    // Access the internal stream resource to read negotiated TLS metadata.
    if (!$connection instanceof Connection || !is_resource($connection->stream)) {
        return null;
    }

    $meta = stream_get_meta_data($connection->stream);
    if (!isset($meta['crypto']) || !is_array($meta['crypto'])) {
        return null;
    }

    foreach (['alpn_protocol', 'alpn_selected', 'ssl_alpn_protocol', 'negotiated_protocol'] as $key) {
        $value = $meta['crypto'][$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function consumeResponseEvents(
    Session $session,
    int $targetStreamId,
    array &$responseHeaderBlocks,
    string &$responseBody
): bool {
    // Drain queued events one by one until there are no more events.
    while (($event = $session->nextEvent()) !== null) {
        // This sample handles a single stream, so ignore events for other streams.
        if ($event instanceof StreamEvent && $event->streamId !== $targetStreamId) {
            continue;
        }

        if ($event instanceof HeadersReceived) {
            // HEADERS can appear multiple times (e.g. initial headers and trailers).
            $responseHeaderBlocks[] = [
                'headers' => is_array($event->headers) ? $event->headers : [],
                'end_stream' => $event->endStream,
            ];
            continue;
        }

        if ($event instanceof DataReceived) {
            $responseBody .= $event->data;
            continue;
        }

        if ($event instanceof StreamReset) {
            throw new RuntimeException("stream reset: {$event->errorCode}");
        }

        if ($event instanceof StreamClosed) {
            // StreamClosed is the terminal stream event. Treat non-zero as failure.
            if ($event->errorCode !== 0) {
                throw new RuntimeException(
                    "stream {$event->streamId} closed with error: {$event->errorCode}"
                );
            }
            return true;
        }
    }

    return false;
}

function parseClientArgs(array $argv): array
{
    $script = basename($argv[0] ?? 'http2-client.php');
    $args = array_slice($argv, 1);

    // Show help and exit successfully.
    if (count($args) === 1 && ($args[0] === '-h' || $args[0] === '--help')) {
        printUsage($script);
        exit(0);
    }

    foreach ($args as $arg) {
        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            printUsage($script);
            exit(1);
        }
    }

    if (count($args) > 3) {
        fwrite(STDERR, "Too many arguments.\n");
        printUsage($script);
        exit(1);
    }

    $host = $args[0] ?? 'nghttp2.org';
    $portRaw = $args[1] ?? '443';
    $path = $args[2] ?? '/httpbin/get';

    // Validate strictly to fail fast on unintended inputs.
    if (!ctype_digit($portRaw)) {
        fwrite(STDERR, "Invalid port: {$portRaw}\n");
        exit(1);
    }
    $port = (int) $portRaw;
    if ($port < 1 || $port > 65535) {
        fwrite(STDERR, "Port out of range: {$port}\n");
        exit(1);
    }

    if ($path === '' || $path[0] !== '/') {
        fwrite(STDERR, "Invalid path: {$path} (must start with '/')\n");
        exit(1);
    }

    return [
        'host' => $host,
        'port' => $port,
        'path' => $path,
    ];
}

function printUsage(string $script): void
{
    fwrite(STDERR, "Usage: php -d extension=nghttp2.so {$script} [<HOST> [<PORT> [<PATH>]]]\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Defaults:\n");
    fwrite(STDERR, "  HOST: nghttp2.org\n");
    fwrite(STDERR, "  PORT: 443\n");
    fwrite(STDERR, "  PATH: /httpbin/get\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  -h, --help  Show this help message\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Examples:\n");
    fwrite(STDERR, "  php -d extension=nghttp2.so {$script}\n");
    fwrite(STDERR, "  php -d extension=nghttp2.so {$script} nghttp2.org 443 /httpbin/get\n");
}
