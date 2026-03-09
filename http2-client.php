<?php
declare(strict_types=1);

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Varion\Nghttp2\DataReceived;
use Varion\Nghttp2\HeadersReceived;
use Varion\Nghttp2\RequestHead;
use Varion\Nghttp2\Session;
use Varion\Nghttp2\StreamClosed;
use Varion\Nghttp2\StreamReset;

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

if (!class_exists(Loop::class) || !class_exists(Connector::class)) {
    fwrite(STDERR, "ReactPHP socket is missing. Install react/socket via Composer.\n");
    exit(1);
}

if (!extension_loaded('nghttp2')) {
    fwrite(STDERR, "PHP extension 'nghttp2' is not loaded.\n");
    exit(1);
}

function printUsage(): void
{
    fwrite(STDERR, "Usage: php http2-client.php URL [fixed-ipv4] [dns-ipv4]\n");
    fwrite(STDERR, "Examples:\n");
    fwrite(STDERR, "  php http2-client.php https://nghttp2.org/httpbin/get\n");
    fwrite(STDERR, "  php http2-client.php https://nghttp2.org/httpbin/get 139.162.123.134\n");
    fwrite(STDERR, "  php http2-client.php https://nghttp2.org/httpbin/get '' 1.1.1.1\n");
}

/**
 * @param array<string, string|list<string>> $headers
 */
function printHeaders(array $headers): void
{
    foreach ($headers as $name => $value) {
        if (is_array($value)) {
            foreach ($value as $line) {
                echo $name . ': ' . $line . "\n";
            }
            continue;
        }

        echo $name . ': ' . $value . "\n";
    }
}

$url = $argv[1] ?? null;
$fixedIpv4 = $argv[2] ?? null;
$dnsIpv4 = $argv[3] ?? null;

$fixedIpv4 = $fixedIpv4 === '' ? null : $fixedIpv4;
$dnsIpv4 = $dnsIpv4 === '' ? null : $dnsIpv4;

if ($url === null) {
    printUsage();
    exit(1);
}

$parsed = parse_url($url);
if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || !isset($parsed['host'])) {
    fwrite(STDERR, "Only https:// URLs are supported.\n");
    printUsage();
    exit(1);
}
if ($fixedIpv4 !== null && !filter_var($fixedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    fwrite(STDERR, "fixed-ipv4 must be a valid IPv4 address.\n");
    exit(1);
}
if ($dnsIpv4 !== null && !filter_var($dnsIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    fwrite(STDERR, "dns-ipv4 must be a valid IPv4 address.\n");
    exit(1);
}
if ($fixedIpv4 !== null && $dnsIpv4 !== null) {
    fwrite(STDERR, "dns-ipv4 is ignored when fixed-ipv4 is set.\n");
}

$host = (string) $parsed['host'];
$port = isset($parsed['port']) ? (int) $parsed['port'] : 443;
$path = $parsed['path'] ?? '/';
if ($path === '') {
    $path = '/';
}
if (isset($parsed['query']) && $parsed['query'] !== '') {
    $path .= '?' . $parsed['query'];
}
$connectHost = $fixedIpv4 ?? $host;

$session = new Session(Session::ROLE_CLIENT);
$responseHeaders = [];
$responseBody = '';
$requestCompleted = false;
$exitCode = 0;
$errorMessage = null;

/** @var ConnectionInterface|null $connection */
$connection = null;
/** @var int|null $streamId */
$streamId = null;

$loop = Loop::get();
$connectorOptions = [
    'timeout' => 10.0,
    'tls' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        // Fixed IPv4 connect still needs hostname for SNI and cert verification.
        'peer_name' => $host,
        'SNI_enabled' => true,
        'alpn_protocols' => 'h2',
    ],
];
if ($fixedIpv4 !== null) {
    $connectorOptions['dns'] = false;
} elseif ($dnsIpv4 !== null) {
    $connectorOptions['dns'] = $dnsIpv4;
}
$connector = new Connector($connectorOptions, $loop);

$finalize = static function (?string $error = null) use (
    &$requestCompleted,
    &$exitCode,
    &$errorMessage,
    &$connection,
    $loop
): void {
    if ($requestCompleted) {
        return;
    }
    $requestCompleted = true;

    if ($connection instanceof ConnectionInterface) {
        $connection->close();
    }

    if ($error !== null) {
        $exitCode = 1;
        $errorMessage = $error;
    }

    $loop->stop();
};

$flushSessionOutput = static function (ConnectionInterface $conn) use ($session): void {
    foreach ($session->drainOutput() as $chunk) {
        if ($chunk !== '') {
            $conn->write($chunk);
        }
    }
};

$connector->connect("tls://{$connectHost}:{$port}")->then(
    static function (ConnectionInterface $conn) use (
        &$connection,
        &$requestCompleted,
        $session,
        &$streamId,
        $host,
        $path,
        &$responseHeaders,
        &$responseBody,
        &$flushSessionOutput,
        &$finalize
    ): void {
        if ($requestCompleted) {
            $conn->close();
            return;
        }
        $connection = $conn;

        try {
            $streamId = $session->submitRequest(new RequestHead(
                'GET',
                'https',
                $host,
                $path,
                [
                    'accept' => '*/*',
                    'user-agent' => 'reactphp-nghttp2-example/0.1',
                ],
                true
            ));
            $flushSessionOutput($conn);
        } catch (Throwable $e) {
            $finalize($e->getMessage());
            return;
        }

        $conn->on('data', static function (string $data) use (
            $conn,
            $session,
            &$streamId,
            &$responseHeaders,
            &$responseBody,
            &$flushSessionOutput,
            &$finalize
        ): void {
            try {
                $session->receive($data);
                $flushSessionOutput($conn);
            } catch (Throwable $e) {
                $finalize($e->getMessage());
                return;
            }

            while (($event = $session->nextEvent()) !== null) {
                if ($streamId !== null && $event instanceof HeadersReceived && $event->streamId === $streamId) {
                    $responseHeaders = $event->headers;
                    continue;
                }
                if ($streamId !== null && $event instanceof DataReceived && $event->streamId === $streamId) {
                    $responseBody .= $event->data;
                    continue;
                }
                if ($streamId !== null && $event instanceof StreamReset && $event->streamId === $streamId) {
                    $finalize("stream reset: {$event->errorCode}");
                    return;
                }
                if ($streamId !== null && $event instanceof StreamClosed && $event->streamId === $streamId) {
                    $finalize();
                    return;
                }
            }
        });

        $conn->on('error', static function (Throwable $e) use (&$finalize): void {
            $finalize($e->getMessage());
        });

        $conn->on('close', static function () use (&$requestCompleted, &$finalize): void {
            if (!$requestCompleted) {
                $finalize('connection closed before stream completion');
            }
        });
    },
    static function (Throwable $e) use (&$finalize): void {
        $finalize('connect failed: ' . $e->getMessage());
    }
);

$loop->addTimer(30.0, static function () use (&$finalize): void {
    $finalize('request timed out');
});

$loop->run();

if ($exitCode !== 0) {
    fwrite(STDERR, $errorMessage . "\n");
    exit($exitCode);
}

echo "=== HEADERS ===\n";
printHeaders($responseHeaders);
echo "\n=== BODY ===\n";
echo $responseBody, "\n";
