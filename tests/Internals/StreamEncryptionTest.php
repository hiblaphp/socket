<?php

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Socket\Connection;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Internals\StreamEncryption;



describe('StreamEncryption', function () {
    $certFile = null;
    $server = null;
    $client = null;
    $connection = null;

    beforeEach(function () use (&$certFile) {
        $certFile = generate_temp_cert();
    });

    afterEach(function () use (&$certFile, &$server, &$client, &$connection) {
        if ($connection) {
            $connection->close();
            $connection = null;
        }
        if (is_resource($client)) {
            fclose($client);
            $client = null;
        }
        if (is_resource($server)) {
            fclose($server);
            $server = null;
        }
        if ($certFile && file_exists($certFile)) {
            unlink($certFile);
        }
    });

    it('successfully enables encryption (Server Mode)', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        // Start the client-side TLS handshake with retry logic
        $clientHandshake = function () use ($client, &$clientHandshake) {
            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($result === 0) {
                // Needs more I/O, schedule another attempt
                Loop::addTimer(0.01, $clientHandshake);
            }
        };

        Loop::addTimer(0.01, $clientHandshake);

        $result = $promise->wait();

        expect($result)->toBeInstanceOf(Connection::class);
        expect($connection->encryptionEnabled)->toBeTrue();

        fwrite($client, "Hello Secure World");

        $received = '';
        $connection->on('data', function ($data) use (&$received) {
            $received .= $data;
        });

        Loop::runOnce();
        Loop::runOnce();

        expect($received)->toBe("Hello Secure World");
    });

    it('rejects when the handshake fails (e.g. non-TLS data)', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);

        $client = stream_socket_client('tcp://' . $address);

        $serverSocket = stream_socket_accept($server);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        fwrite($client, "GET / HTTP/1.0\r\n\r\n");

        try {
            $promise->wait();
            test()->fail('Handshake should have failed');
        } catch (EncryptionFailedException $e) {
            expect($e->getMessage())->not->toBeEmpty();
        }
    });

    it('can be cancelled during handshake', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);

        $client = stream_socket_client('tcp://' . $address);

        $serverSocket = stream_socket_accept($server);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $promise->cancel();

        expect(fn() => $promise->wait())->toThrow(PromiseCancelledException::class);
    });
});
