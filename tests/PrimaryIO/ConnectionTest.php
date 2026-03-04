<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Connection;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

describe('Connection', function () {
    $client = null;
    $serverConnection = null;

    afterEach(function () use (&$client, &$serverConnection) {
        if ($serverConnection instanceof Connection) {
            $serverConnection->close();
            $serverConnection = null;
        }

        if (is_resource($client)) {
            @fclose($client);
            $client = null;
        }
    });

    it('implements ConnectionInterface', function () {
        [$client, $server] = creat_socket_pair();
        $connection = new Connection($server);

        expect($connection)->toBeInstanceOf(ConnectionInterface::class);

        $connection->close();
        fclose($client);
    });

    it('is readable by default', function () {
        [$client, $server] = creat_socket_pair();
        $connection = new Connection($server);

        expect($connection->isReadable())->toBeTrue();

        $connection->close();
        fclose($client);
    });

    it('is writable by default', function () {
        [$client, $server] = creat_socket_pair();
        $connection = new Connection($server);

        expect($connection->isWritable())->toBeTrue();

        $connection->close();
        fclose($client);
    });

    it('gets remote address for TCP connection', function () {
        [$client, $server] = creat_socket_pair();
        $connection = new Connection($server);

        $remoteAddress = $connection->getRemoteAddress();

        expect($remoteAddress)->not->toBeNull()
            ->and($remoteAddress)->toContain('tcp://')
            ->and($remoteAddress)->toContain('127.0.0.1')
        ;

        $connection->close();
        fclose($client);
    });

    it('gets local address for TCP connection', function () {
        [$client, $server] = creat_socket_pair();
        $connection = new Connection($server);

        $localAddress = $connection->getLocalAddress();

        expect($localAddress)->not->toBeNull()
            ->and($localAddress)->toContain('tcp://')
            ->and($localAddress)->toContain('127.0.0.1')
        ;

        $connection->close();
        fclose($client);
    });

    it('handles IPv6 addresses correctly', function () {
        $server = @stream_socket_server('tcp://[::1]:0');
        if ($server === false) {
            test()->skip('IPv6 is not supported on this system.');
        }

        $address = stream_socket_get_name($server, false);
        $client = @stream_socket_client('tcp://' . $address);
        $serverSocket = stream_socket_accept($server);

        fclose($server);

        $connection = new Connection($serverSocket);
        $remoteAddress = $connection->getRemoteAddress();

        expect($remoteAddress)->toContain('[::1]');

        $connection->close();
        fclose($client);
    });

    it('emits data event when receiving data', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $dataReceived = null;

        $serverConnection->on('data', function ($data) use (&$dataReceived) {
            $dataReceived = $data;
            Loop::stop();
        });

        stream_set_blocking($client, false);
        fwrite($client, "Hello Connection\n");

        run_with_timeout(1.0);

        expect($dataReceived)->toBe("Hello Connection\n");
    });

    it('can write data back', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        $serverConnection->write("Server says hello\n");
        stream_set_blocking($client, false);

        $receivedData = '';
        $watcherId = null;

        $watcherId = Loop::addReadWatcher($client, function () use ($client, &$receivedData, &$watcherId) {
            $receivedData = fread($client, 1024);
            if ($watcherId) {
                Loop::removeReadWatcher($watcherId);
            }
            Loop::stop();
        });

        run_with_timeout(1.0);

        expect($receivedData)->toBe("Server says hello\n");
    });

    it('returns true when write succeeds', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        $result = $serverConnection->write('test');

        expect($result)->toBeTrue();
    });

    it('emits end event when connection closes', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $endReceived = false;

        $serverConnection->on('end', function () use (&$endReceived) {
            $endReceived = true;
            Loop::stop();
        });

        Loop::addTimer(0.01, function () use (&$client) {
            fclose($client);
        });

        run_with_timeout(1.0);

        expect($endReceived)->toBeTrue();
    });

    it('emits close event when closed', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $closeReceived = false;

        $serverConnection->on('close', function () use (&$closeReceived) {
            $closeReceived = true;
        });

        $serverConnection->close();

        expect($closeReceived)->toBeTrue();

        fclose($client);
    });

    it('can be paused and resumed', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $dataCount = 0;

        $serverConnection->on('data', function () use (&$dataCount) {
            $dataCount++;
        });

        $serverConnection->pause();

        stream_set_blocking($client, false);
        fwrite($client, "Message 1\n");

        Loop::addTimer(0.1, function () use ($serverConnection, &$client, &$dataCount) {
            expect($dataCount)->toBe(0);
            $serverConnection->resume();
            fwrite($client, "Message 2\n");

            Loop::addTimer(0.1, fn() => Loop::stop());
        });

        run_with_timeout(1.0);

        expect($dataCount)->toBeGreaterThan(0);
    });

    it('can pipe to another writable stream', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        [$client2, $server2] = creat_socket_pair();
        $destination = new Connection($server2);

        $result = $serverConnection->pipe($destination);

        expect($result)->toBeInstanceOf(WritableStreamInterface::class);

        $serverConnection->close();
        $destination->close();
        fclose($client);
        fclose($client2);
    });

    it('ends connection with optional data', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $closeReceived = false;

        $serverConnection->on('close', function () use (&$closeReceived) {
            $closeReceived = true;
            Loop::stop();
        });

        $serverConnection->end("Goodbye\n");

        stream_set_blocking($client, false);

        run_with_timeout(1.0);

        $data = fread($client, 1024);

        expect($data)->toBe("Goodbye\n")
            ->and($closeReceived)->toBeTrue()
        ;

        fclose($client);
    });

    it('is not readable after close', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        $serverConnection->close();

        expect($serverConnection->isReadable())->toBeFalse();

        fclose($client);
    });

    it('is not writable after close', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        $serverConnection->close();

        expect($serverConnection->isWritable())->toBeFalse();

        fclose($client);
    });

    it('returns null for addresses after close', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        $serverConnection->close();

        expect($serverConnection->getRemoteAddress())->toBeNull()
            ->and($serverConnection->getLocalAddress())->toBeNull()
        ;

        fclose($client);
    });

    it('handles Unix socket connections', function () {
        $socketPath = sys_get_temp_dir() . '/test-connection-' . uniqid() . '.sock';
        $server = stream_socket_server('unix://' . $socketPath);
        $client = stream_socket_client('unix://' . $socketPath);
        $serverSocket = stream_socket_accept($server);

        fclose($server);

        $connection = new Connection($serverSocket, isUnix: true);

        $remoteAddress = $connection->getRemoteAddress();
        $localAddress = $connection->getLocalAddress();

        expect($remoteAddress)->not->toBeNull()
            ->and($remoteAddress)->toStartWith('unix://')
            ->and($localAddress)->not->toBeNull()
            ->and($localAddress)->toStartWith('unix://')
        ;

        $connection->close();
        fclose($client);
        @unlink($socketPath);
    })->skipOnWindows();

    it('handles bidirectional communication', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $receivedData = [];

        $serverConnection->on('data', function ($data) use (&$receivedData, $serverConnection) {
            $receivedData[] = $data;
            $serverConnection->write('Echo: ' . $data);

            if (count($receivedData) >= 2) {
                Loop::addTimer(0.01, fn() => Loop::stop());
            }
        });

        stream_set_blocking($client, false);

        Loop::addTimer(0.01, function () use (&$client) {
            fwrite($client, "Message 1\n");
        });

        Loop::addTimer(0.05, function () use (&$client) {
            fwrite($client, "Message 2\n");
        });

        run_with_timeout(1.0);

        $response = '';
        while ($chunk = fread($client, 1024)) {
            $response .= $chunk;
        }

        expect($receivedData)->not->toBeEmpty()
            ->and($response)->toContain("Echo: Message 1\n")
            ->and($response)->toContain("Echo: Message 2\n")
        ;
    });

    it('emits drain event when write buffer empties', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $drainEmitted = false;

        $serverConnection->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
            Loop::stop();
        });

        $largeData = str_repeat('x', 100000);
        $serverConnection->write($largeData);

        Loop::addTimer(0.01, function () use ($client) {
            stream_set_blocking($client, false);
            fread($client, 8192);
        });

        run_with_timeout(1.0);

        expect($drainEmitted)->toBeIn([true, false]);
    });

    it('handles multiple small writes', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);

        for ($i = 0; $i < 10; $i++) {
            $serverConnection->write("Line $i\n");
        }

        stream_set_blocking($client, false);

        $received = '';
        $watcherId = null;

        $watcherId = Loop::addReadWatcher($client, function () use ($client, &$received, &$watcherId) {
            $chunk = fread($client, 1024);
            $received .= $chunk;

            if (str_contains($received, "Line 9\n")) {
                if ($watcherId) {
                    Loop::removeReadWatcher($watcherId);
                }
                Loop::stop();
            }
        });

        run_with_timeout(1.0);

        expect($received)->toContain("Line 0\n")
            ->and($received)->toContain("Line 9\n")
        ;
    });

    it('close is idempotent', function () use (&$client, &$serverConnection) {
        [$client, $server] = creat_socket_pair();
        $serverConnection = new Connection($server);
        $closeCount = 0;

        $serverConnection->on('close', function () use (&$closeCount) {
            $closeCount++;
        });

        $serverConnection->close();
        $serverConnection->close();
        $serverConnection->close();

        expect($closeCount)->toBe(1);

        fclose($client);
    });

    describe('enableEncryption', function () {
        $certFile = null;
        $server = null;
        $client = null;

        beforeEach(function () use (&$certFile) {
            if (DIRECTORY_SEPARATOR === '\\') {
                test()->markTestSkipped('Skipped on Windows');
            }
            $certFile = generate_temp_cert();
        });

        afterEach(function () use (&$certFile, &$server, &$client) {
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

        it('returns a PromiseInterface', function () use (&$certFile, &$server, &$client) {
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            $promise = $connection->enableEncryption(isServer: true);

            expect($promise)->toBeInstanceOf(\Hibla\Promise\Interfaces\PromiseInterface::class);

            $promise->cancel();
        });

        it('resolves with the same Connection instance', function () use (&$certFile, &$server, &$client) {
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            $resolved = null;
            $connection->enableEncryption(isServer: true)
                ->then(function ($result) use (&$resolved) {
                    $resolved = $result;
                    Loop::stop();
                });

            drive_client_tls_handshake($client);
            run_with_timeout(2.0);

            expect($resolved)->toBe($connection);
        });

        it('sets encryptionEnabled to true on success', function () use (&$certFile, &$server, &$client) {
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            expect($connection->encryptionEnabled)->toBeFalse();

            $completed = false;
            $connection->enableEncryption(isServer: true)
                ->then(function () use ($connection, &$completed) {
                    expect($connection->encryptionEnabled)->toBeTrue();
                    $completed = true;
                    Loop::stop();
                })
                ->catch(fn($e) => test()->fail($e->getMessage()));

            drive_client_tls_handshake($client);
            run_with_timeout(2.0);

            expect($completed)->toBeTrue();
        });

        it('sets the correct scheme on getRemoteAddress after encryption', function () use (&$certFile, &$server, &$client) {
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            $completed = false;
            $connection->enableEncryption(isServer: true)
                ->then(function () use ($connection, &$completed) {
                    expect($connection->getRemoteAddress())->toStartWith('tls://');
                    $completed = true;
                    Loop::stop();
                })
                ->catch(fn($e) => test()->fail($e->getMessage()));

            drive_client_tls_handshake($client);
            run_with_timeout(2.0);

            expect($completed)->toBeTrue();
        });

        it('applies ssl options from the sslOptions argument', function () use (&$certFile, &$server, &$client) {
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            $completed = false;
            $connection->enableEncryption(
                sslOptions: ['verify_peer' => false, 'allow_self_signed' => true],
                isServer: true
            )
                ->then(function () use (&$completed) {
                    $completed = true;
                    Loop::stop();
                })
                ->catch(fn($e) => test()->fail($e->getMessage()));

            drive_client_tls_handshake($client);
            run_with_timeout(2.0);

            expect($completed)->toBeTrue();
        });

        it('rejects when the handshake fails', function () use (&$certFile, &$server, &$client) {
            // Plain TCP client intentionally — no TLS, forces OpenSSL failure
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            // Overwrite $client with a plain socket to break the handshake
            fclose($client);
            $address = stream_socket_get_name($server, false);
            $client = stream_socket_client('tcp://' . $address);
            stream_set_blocking($client, false);

            $r = [$server];
            $w = $e = null;
            stream_select($r, $w, $e, 1);

            fwrite($client, "GET / HTTP/1.0\r\n\r\n");

            $failed = false;
            $connection->enableEncryption(isServer: true)
                ->then(fn() => test()->fail('Should not have resolved'))
                ->catch(function () use (&$failed) {
                    $failed = true;
                    Loop::stop();
                });

            run_with_timeout(2.0);

            expect($failed)->toBeTrue();
        });

        it('can cancel enableEncryption mid-handshake', function () use (&$certFile, &$server, &$client) {
            [$serverSocket, $client] = make_tls_pair($certFile, $server, $client);
            $connection = new Connection($serverSocket);

            $promise = $connection->enableEncryption(isServer: true);
            $promise->cancel();

            expect(fn() => $promise->wait())->toThrow(CancelledException::class);
            expect(is_resource($connection->getResource()))->toBeTrue();
        });
    });
});
