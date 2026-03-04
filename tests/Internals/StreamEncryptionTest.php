<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Connection;
use Hibla\Socket\Internals\StreamEncryption;

describe('Stream Encryption', function () {
    $certFile = null;
    $server = null;
    $client = null;
    $connection = null;

    beforeEach(function () use (&$certFile) {
        if (DIRECTORY_SEPARATOR === '\\') {
            test()->markTestSkipped('Skipped on Windows');
        }
        $certFile = generate_temp_cert();
        Loop::reset();
    });

    afterEach(function () use (&$certFile, &$server, &$client, &$connection) {
        if ($connection) {
            $connection->close();
            $connection = null;
        }
        if (is_resource($client)) { fclose($client); $client = null; }
        if (is_resource($server)) { fclose($server); $server = null; }
        if ($certFile && file_exists($certFile)) { unlink($certFile); }

        Loop::stop();
        Loop::reset();
    });

    it('successfully enables encryption (Server Mode)', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
        $connection = new Connection($serverSocket);

        $received = '';

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(function ($result) use ($connection, &$client, &$received) {
                expect($result)->toBeInstanceOf(Connection::class);
                expect($connection->encryptionEnabled)->toBeTrue();

                if (is_resource($client)) {
                    fwrite($client, 'Hello Secure World');
                }

                $connection->on('data', function ($data) use (&$received) {
                    $received .= $data;
                    if ($received === 'Hello Secure World') {
                        Loop::stop();
                    }
                });
            })
            ->catch(fn ($e) => test()->fail('Encryption should have succeeded: ' . $e->getMessage()));

        drive_client_tls_handshake($client);
        run_with_timeout(2.0);

        expect($received)->toBe('Hello Secure World');
    });

    it('successfully enables encryption (Client Mode)', function () use (&$certFile, &$server, &$client, &$connection) {
        $serverContext = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);
        $clientContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverContext);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $clientContext);
        stream_set_blocking($client, false);

        $r = [$server]; $w = $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);

        drive_server_tls_handshake($serverSocket, function () use ($serverSocket) {
            if (is_resource($serverSocket)) {
                fwrite($serverSocket, 'Server Message');
            }
        });

        $connection = new Connection($client);
        $received   = '';

        (new StreamEncryption(isServer: false))
            ->enable($connection)
            ->then(function ($result) use ($connection, &$received) {
                expect($result)->toBeInstanceOf(Connection::class);
                expect($connection->encryptionEnabled)->toBeTrue();

                $connection->on('data', function ($data) use (&$received) {
                    $received .= $data;
                    if ($received === 'Server Message') {
                        Loop::stop();
                    }
                });
            })
            ->catch(fn ($e) => test()->fail('Client-side encryption should have succeeded: ' . $e->getMessage()));

        run_with_timeout(2.0);

        expect($received)->toBe('Server Message');
    });

    it('ensures stream is non-blocking after encryption', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
        $connection = new Connection($serverSocket);

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(function () use ($connection) {
                $metadata = stream_get_meta_data($connection->getResource());
                expect($metadata['blocked'])->toBeFalse();
                Loop::stop();
            })
            ->catch(fn ($e) => test()->fail('Encryption should have succeeded: ' . $e->getMessage()));

        drive_client_tls_handshake($client);
        run_with_timeout(2.0);
    });

    it('rejects when the handshake fails (e.g. non-TLS data)', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client, plainClient: true);
        $connection = new Connection($serverSocket);

        fwrite($client, "GET / HTTP/1.0\r\n\r\n");

        $failed       = false;
        $errorMessage = '';

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(fn () => test()->fail('Handshake should have failed'))
            ->catch(function ($e) use (&$failed, &$errorMessage) {
                $failed       = true;
                $errorMessage = $e->getMessage();
                Loop::stop();
            });

        run_with_timeout(2.0);

        expect($failed)->toBeTrue();
        expect($errorMessage)->not->toBeEmpty();
    });

    it('rejects when connection is closed during handshake', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client, plainClient: true);
        $connection = new Connection($serverSocket);

        Loop::addTimer(0.01, function () use (&$client) {
            if (is_resource($client)) {
                fclose($client);
                $client = null;
            }
        });

        $failed       = false;
        $errorMessage = '';

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(fn () => test()->fail('Handshake should have failed due to connection loss'))
            ->catch(function ($e) use (&$failed, &$errorMessage) {
                $failed       = true;
                $errorMessage = $e->getMessage();
                Loop::stop();
            });

        run_with_timeout(2.0);

        expect($failed)->toBeTrue();
        expect($errorMessage)->toContain('Connection lost during TLS handshake');
    });

    it('can be cancelled during handshake', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client, plainClient: true);
        $connection = new Connection($serverSocket);

        $promise = (new StreamEncryption(isServer: true))->enable($connection);
        $promise->cancel();

        expect(fn () => $promise->wait())->toThrow(CancelledException::class);
    });

    it('handles multiple sequential handshake attempts', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
        $connection = new Connection($serverSocket);

        $received = '';

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(function () use ($connection, &$client, &$received) {
                if (is_resource($client)) {
                    fwrite($client, 'Test');
                }

                $connection->on('data', function ($data) use (&$received) {
                    $received .= $data;
                    if ($received === 'Test') {
                        Loop::stop();
                    }
                });
            })
            ->catch(fn ($e) => test()->fail('Encryption should have succeeded: ' . $e->getMessage()));

        drive_client_tls_handshake($client);
        run_with_timeout(2.0);

        expect($received)->toBe('Test');
    });

    it('cleans up watchers on cancellation', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client, plainClient: true);
        $connection = new Connection($serverSocket);

        $promise = (new StreamEncryption(isServer: true))->enable($connection);
        $promise->cancel();

        expect(fn () => $promise->wait())->toThrow(CancelledException::class);
        expect(is_resource($connection->getResource()))->toBeTrue();
    });

    it('pauses connection during handshake and resumes after', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
        $connection = new Connection($serverSocket);

        $dataReceivedDuringHandshake = false;
        $connection->on('data', function () use (&$dataReceivedDuringHandshake) {
            $dataReceivedDuringHandshake = true;
        });

        $received = '';

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(function () use ($connection, &$client, &$received, &$dataReceivedDuringHandshake) {
                expect($dataReceivedDuringHandshake)->toBeFalse();

                if (is_resource($client)) {
                    fwrite($client, 'Post-handshake data');
                }

                $connection->on('data', function ($data) use (&$received) {
                    $received .= $data;
                    if ($received === 'Post-handshake data') {
                        Loop::stop();
                    }
                });
            })
            ->catch(fn ($e) => test()->fail('Encryption should have succeeded: ' . $e->getMessage()));

        drive_client_tls_handshake($client);
        run_with_timeout(2.0);

        expect($received)->toBe('Post-handshake data');
    });

    it('handles custom crypto method from context', function () use (&$certFile, &$server, &$client, &$connection) {
        [$serverSocket, $client] = make_tls_pair(
            $certFile, $server, $client,
            sslOptions: ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER]
        );

        stream_context_set_option($serverSocket, 'ssl', 'local_cert', $certFile);
        stream_context_set_option($serverSocket, 'ssl', 'crypto_method', STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);

        $connection = new Connection($serverSocket);
        $completed  = false;

        (new StreamEncryption(isServer: true))
            ->enable($connection)
            ->then(function ($result) use ($connection, &$completed) {
                expect($result)->toBeInstanceOf(Connection::class);
                expect($connection->encryptionEnabled)->toBeTrue();
                $completed = true;
                Loop::stop();
            })
            ->catch(fn ($e) => test()->fail('Encryption should have succeeded: ' . $e->getMessage()));

        drive_client_tls_handshake($client, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        run_with_timeout(2.0);

        expect($completed)->toBeTrue();
    });
});