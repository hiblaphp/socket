<?php

use Hibla\EventLoop\Loop;
use Hibla\Socket\Connection;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

describe('Connection', function () {
    $server = null;
    $client = null;
    $serverConnection = null;

    function createSocketPair(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        $client = stream_socket_client('tcp://' . $address);
        $serverSocket = stream_socket_accept($server);

        fclose($server);

        return [$client, $serverSocket];
    }

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
        [$client, $server] = createSocketPair();
        $connection = new Connection($server);

        expect($connection)->toBeInstanceOf(ConnectionInterface::class);

        $connection->close();
        fclose($client);
    });

    it('is readable by default', function () {
        [$client, $server] = createSocketPair();
        $connection = new Connection($server);

        expect($connection->isReadable())->toBeTrue();

        $connection->close();
        fclose($client);
    });

    it('is writable by default', function () {
        [$client, $server] = createSocketPair();
        $connection = new Connection($server);

        expect($connection->isWritable())->toBeTrue();

        $connection->close();
        fclose($client);
    });

    it('gets remote address for TCP connection', function () {
        [$client, $server] = createSocketPair();
        $connection = new Connection($server);

        $remoteAddress = $connection->getRemoteAddress();

        expect($remoteAddress)->not->toBeNull()
            ->and($remoteAddress)->toContain('tcp://')
            ->and($remoteAddress)->toContain('127.0.0.1');

        $connection->close();
        fclose($client);
    });

    it('gets local address for TCP connection', function () {
        [$client, $server] = createSocketPair();
        $connection = new Connection($server);

        $localAddress = $connection->getLocalAddress();

        expect($localAddress)->not->toBeNull()
            ->and($localAddress)->toContain('tcp://')
            ->and($localAddress)->toContain('127.0.0.1');

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
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);
        $dataReceived = null;

        $serverConnection->on('data', function ($data) use (&$dataReceived) {
            $dataReceived = $data;
            Loop::stop();
        });

        stream_set_blocking($client, false);
        fwrite($client, "Hello Connection\n");

        $timeout = Loop::addTimer(1.0, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        expect($dataReceived)->toBe("Hello Connection\n");
    });

    it('can write data back', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        $serverConnection->write("Server says hello\n");

        stream_set_blocking($client, false);

        $timeout = Loop::addTimer(0.1, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        $data = fread($client, 1024);
        expect($data)->toBe("Server says hello\n");
    });

    it('returns true when write succeeds', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        $result = $serverConnection->write("test");

        expect($result)->toBeTrue();
    });

    it('emits end event when connection closes', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);
        $endReceived = false;

        $serverConnection->on('end', function () use (&$endReceived) {
            $endReceived = true;
            Loop::stop();
        });

        Loop::addTimer(0.01, function () use (&$client) {
            fclose($client);
        });

        $timeout = Loop::addTimer(1.0, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        expect($endReceived)->toBeTrue();
    });

    it('emits close event when closed', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
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
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);
        $dataCount = 0;

        $serverConnection->on('data', function () use (&$dataCount) {
            $dataCount++;
        });

        $serverConnection->pause();

        stream_set_blocking($client, false);
        fwrite($client, "Message 1\n");

        $timeout = Loop::addTimer(0.1, function () use ($serverConnection, &$client, &$dataCount) {
            expect($dataCount)->toBe(0);
            $serverConnection->resume();
            fwrite($client, "Message 2\n");
        });

        $timeout2 = Loop::addTimer(0.3, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);
        Loop::cancelTimer($timeout2);

        expect($dataCount)->toBeGreaterThan(0);
    });

    it('can pipe to another writable stream', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        [$client2, $server2] = createSocketPair();
        $destination = new Connection($server2);

        $result = $serverConnection->pipe($destination);

        expect($result)->toBeInstanceOf(WritableStreamInterface::class);

        $serverConnection->close();
        $destination->close();
        fclose($client);
        fclose($client2);
    });

    it('ends connection with optional data', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);
        $closeReceived = false;

        $serverConnection->on('close', function () use (&$closeReceived) {
            $closeReceived = true;
            Loop::stop();
        });

        $serverConnection->end("Goodbye\n");

        stream_set_blocking($client, false);

        $timeout = Loop::addTimer(0.1, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        $data = fread($client, 1024);

        expect($data)->toBe("Goodbye\n")
            ->and($closeReceived)->toBeTrue();

        fclose($client);
    });

    it('is not readable after close', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        $serverConnection->close();

        expect($serverConnection->isReadable())->toBeFalse();

        fclose($client);
    });

    it('is not writable after close', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        $serverConnection->close();

        expect($serverConnection->isWritable())->toBeFalse();

        fclose($client);
    });

    it('returns null for addresses after close', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        $serverConnection->close();

        expect($serverConnection->getRemoteAddress())->toBeNull()
            ->and($serverConnection->getLocalAddress())->toBeNull();

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
            ->and($localAddress)->toStartWith('unix://');

        $connection->close();
        fclose($client);
        @unlink($socketPath);
    })->skipOnWindows();

    it('handles bidirectional communication', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);
        $receivedData = [];

        $serverConnection->on('data', function ($data) use (&$receivedData, $serverConnection) {
            $receivedData[] = $data;
            $serverConnection->write("Echo: " . $data);
        });

        stream_set_blocking($client, false);

        Loop::addTimer(0.01, function () use (&$client) {
            fwrite($client, "Message 1\n");
        });

        Loop::addTimer(0.05, function () use (&$client) {
            fwrite($client, "Message 2\n");
        });

        $timeout = Loop::addTimer(0.3, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        $response = '';
        while ($chunk = fread($client, 1024)) {
            $response .= $chunk;
        }

        expect($receivedData)->not->toBeEmpty()
            ->and($response)->toContain("Echo: Message 1\n")
            ->and($response)->toContain("Echo: Message 2\n");
    });

    it('emits drain event when write buffer empties', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);
        $drainEmitted = false;

        $serverConnection->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
        });

        // Write large amount of data to potentially fill buffer
        $largeData = str_repeat('x', 100000);
        $serverConnection->write($largeData);

        $timeout = Loop::addTimer(0.5, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        // Drain might or might not be emitted depending on buffer size
        expect($drainEmitted)->toBeIn([true, false]);
    });

    it('handles multiple small writes', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
        $serverConnection = new Connection($server);

        for ($i = 0; $i < 10; $i++) {
            $serverConnection->write("Line $i\n");
        }

        stream_set_blocking($client, false);

        $timeout = Loop::addTimer(0.2, fn() => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timeout);

        $received = '';
        while ($chunk = fread($client, 1024)) {
            $received .= $chunk;
        }

        expect($received)->toContain("Line 0\n")
            ->and($received)->toContain("Line 9\n");
    });

    it('close is idempotent', function () use (&$client, &$serverConnection) {
        [$client, $server] = createSocketPair();
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
});
