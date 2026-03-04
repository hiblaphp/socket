<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;

uses()
    ->beforeEach(function () {
        Loop::reset();
    })
    ->afterEach(function () {
        Loop::forceStop();
        Loop::reset();
    })
    ->in(__DIR__)
;

function create_listening_server(array &$sockets): mixed
{
    $server = @stream_socket_server('127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        test()->markTestSkipped("Could not create a test server: {$errstr}");
    }
    stream_set_blocking($server, false);
    $sockets[] = $server;

    return $server;
}

function get_free_port(): int
{
    $socket = @stream_socket_server('127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        test()->markTestSkipped("Could not find a free port: {$errstr}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr(strrchr($address, ':'), 1);
}

function create_listening_socket(string $address): mixed
{
    $socket = @stream_socket_server($address, $errno, $errstr);

    if ($socket === false) {
        test()->markTestSkipped("Could not create listening socket: {$errstr}");
    }

    return $socket;
}

function get_fd_from_socket(mixed $socket): int
{
    if (! is_resource($socket)) {
        throw new InvalidArgumentException('Expected a valid resource');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('FD extraction not reliably supported on Windows');
    }

    if (! is_dir('/dev/fd')) {
        test()->markTestSkipped('Not supported on your platform (requires /dev/fd)');
    }

    $stat = @fstat($socket);
    if ($stat === false) {
        throw new RuntimeException('Could not get socket file stats');
    }

    $ino = (int) $stat['ino'];

    set_error_handler(function () { /* suppress all errors */
    });

    try {
        $dir = scandir('/dev/fd');

        if ($dir === false) {
            throw new RuntimeException('Cannot read /dev/fd directory');
        }

        foreach ($dir as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fdStat = stat('/dev/fd/' . $file);

            if ($fdStat !== false && isset($fdStat['ino']) && $fdStat['ino'] === $ino) {
                return (int) $file;
            }
        }
    } finally {
        restore_error_handler();
    }

    throw new RuntimeException('Could not determine file descriptor for socket');
}

function generate_temp_cert(): string
{
    $dn = [
        'countryName' => 'PH',
        'stateOrProvinceName' => 'Test',
        'localityName' => 'Test',
        'organizationName' => 'Hibla',
        'organizationalUnitName' => 'Testing',
        'commonName' => '127.0.0.1',
        'emailAddress' => 'test@example.com',
    ];

    $privkey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new($dn, $privkey, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $privkey, 1, ['digest_alg' => 'sha256']);

    $tempDir = sys_get_temp_dir();
    $certFile = $tempDir . '/hibla_test_cert_' . uniqid() . '.pem';

    $pem = '';
    openssl_x509_export($x509, $cert);
    $pem .= $cert;
    openssl_pkey_export($privkey, $key);
    $pem .= $key;

    file_put_contents($certFile, $pem);

    return $certFile;
}

function create_async_tls_client(int $port, array &$clients, array $sslOptions = []): void
{
    $client = @stream_socket_client(
        'tcp://127.0.0.1:' . $port,
        $errno,
        $errstr,
        5,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
    );

    if ($client === false) {
        echo "TCP connect failed: $errstr\n";

        return;
    }

    stream_set_blocking($client, false);
    $clients[] = $client;

    $defaultOptions = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ];

    $options = array_merge($defaultOptions, $sslOptions);

    foreach ($options as $key => $value) {
        stream_context_set_option($client, 'ssl', $key, $value);
    }

    Loop::addTimer(0.1, function () use ($client) {
        $watcherId = null;

        $enableCrypto = function () use ($client, &$watcherId) {
            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($result === true) {
                if ($watcherId !== null) {
                    Loop::removeReadWatcher($watcherId);
                }
            } elseif ($result === false) {
                if ($watcherId !== null) {
                    Loop::removeReadWatcher($watcherId);
                }
            }
            // else: needs more I/O, watcher will retry
        };

        // Try once immediately
        $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($result === 0) {
            $watcherId = Loop::addReadWatcher(
                $client,
                $enableCrypto
            );
        }
    });
}

function creat_socket_pair(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);

    $client = stream_socket_client('tcp://' . $address);
    $serverSocket = stream_socket_accept($server);

    fclose($server);

    return [$client, $serverSocket];
}

function run_with_timeout(float $seconds): void
{
    $timer = Loop::addTimer($seconds, function () {
        Loop::stop();
        test()->fail('Test timed out');
    });

    Loop::run();

    Loop::cancelTimer($timer);
}

function drive_client_tls_handshake(mixed &$client, int $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT): void
{
    $timerRef = null;

    $handshake = function () use (&$client, &$handshake, &$timerRef, $cryptoMethod) {
        if (!is_resource($client)) {
            return;
        }

        $result = @stream_socket_enable_crypto($client, true, $cryptoMethod);

        if ($result === 0) {
            $timerRef = Loop::addTimer(0.01, $handshake);
        } elseif ($timerRef !== null) {
            Loop::cancelTimer($timerRef);
            $timerRef = null;
        }
    };

    Loop::addTimer(0.01, $handshake);
}

function make_tls_pair(string $certFile, mixed &$server, mixed &$client, array $sslOptions = [], bool $plainClient = false): array
{
    $context = stream_context_create([
        'ssl' => array_merge([
            'local_cert'        => $certFile,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ], $sslOptions),
    ]);

    $server = stream_socket_server(
        'tcp://127.0.0.1:0', $errno, $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );
    $address = stream_socket_get_name($server, false);
    stream_set_blocking($server, false);

    $client = $plainClient
        ? stream_socket_client('tcp://' . $address)
        : stream_socket_client('tcp://' . $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);

    stream_set_blocking($client, false);

    $r = [$server]; $w = $e = null;
    stream_select($r, $w, $e, 1);

    $serverSocket = stream_socket_accept($server);
    stream_set_blocking($serverSocket, false);

    return [$serverSocket, $client];
}

function drive_server_tls_handshake(mixed $serverSocket, callable $onComplete, int $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER): void
{
    $timerRef = null;

    $handshake = function () use ($serverSocket, &$handshake, &$timerRef, $cryptoMethod, $onComplete) {
        if (!is_resource($serverSocket)) {
            return;
        }

        $result = @stream_socket_enable_crypto($serverSocket, true, $cryptoMethod);

        if ($result === 0) {
            $timerRef = Loop::addTimer(0.01, $handshake);
        } elseif ($result === true) {
            if ($timerRef !== null) {
                Loop::cancelTimer($timerRef);
                $timerRef = null;
            }
            $onComplete();
        }
    };

    Loop::addTimer(0.01, $handshake);
}