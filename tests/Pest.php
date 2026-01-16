<?php

use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

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
        test()->skip("Could not create a test server: {$errstr}");
    }
    stream_set_blocking($server, false);
    $sockets[] = $server;

    return $server;
}

function get_free_port(): int
{
    $socket = @stream_socket_server('127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        test()->skip("Could not find a free port: {$errstr}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr(strrchr($address, ':'), 1);
}

function create_listening_socket(string $address): mixed
{
    $socket = @stream_socket_server($address, $errno, $errstr);

    if ($socket === false) {
        test()->skip("Could not create listening socket: {$errstr}");
    }

    return $socket;
}

function get_fd_from_socket(mixed $socket): int
{
    if (!is_resource($socket)) {
        throw new InvalidArgumentException('Expected a valid resource');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        test()->skip('FD extraction not reliably supported on Windows');
    }

    if (!is_dir('/dev/fd')) {
        test()->skip('Not supported on your platform (requires /dev/fd)');
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
        "countryName" => "PH",
        "stateOrProvinceName" => "Test",
        "localityName" => "Test",
        "organizationName" => "Hibla",
        "organizationalUnitName" => "Testing",
        "commonName" => "127.0.0.1",
        "emailAddress" => "test@example.com"
    ];

    $privkey = openssl_pkey_new([
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
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
                    Loop::removeStreamWatcher($watcherId);
                }
            } elseif ($result === false) {
                if ($watcherId !== null) {
                    Loop::removeStreamWatcher($watcherId);
                }
            }
            // else: needs more I/O, watcher will retry
        };

        // Try once immediately
        $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($result === 0) {
            $watcherId = Loop::addStreamWatcher(
                $client,
                $enableCrypto,
                StreamWatcher::TYPE_READ
            );
        }
    });
}
