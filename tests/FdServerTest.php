<?php

use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\BindFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\FdServer;

describe('FD Server', function () {
    $server = null;
    $clients = [];
    $listeningSockets = [];

    afterEach(function () use (&$server, &$clients, &$listeningSockets) {
        foreach ($clients as $client) {
            if (is_resource($client)) @fclose($client);
        }
        $clients = [];

        if ($server instanceof FdServer) {
            $server->close();
            $server = null;
        }

        foreach ($listeningSockets as $socket) {
            if (is_resource($socket)) @fclose($socket);
        }
        $listeningSockets = [];
    });

    it('constructs with a valid file descriptor number', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        expect($server->getAddress())->toContain('tcp://127.0.0.1:');
    });

    it('constructs with php://fd/ string format', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer('php://fd/' . $fd);
        expect($server->getAddress())->toContain('tcp://127.0.0.1:');
    });

    it('throws InvalidUriException for invalid file descriptor', function () {
        expect(fn() => new FdServer(-1))->toThrow(InvalidUriException::class);
        expect(fn() => new FdServer('invalid'))->toThrow(InvalidUriException::class);
        expect(fn() => new FdServer('php://fd/abc'))->toThrow(InvalidUriException::class);
    });

    it('throws BindFailedException for non-existent file descriptor', function () {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        // Use a very high FD number that's unlikely to exist
        expect(fn() => new FdServer(9999))->toThrow(BindFailedException::class);
    });

    it('throws BindFailedException for non-socket file descriptor', function () {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $tmpFile = tmpfile();
        $fd = get_fd_from_socket($tmpFile);

        expect(fn() => new FdServer($fd))->toThrow(BindFailedException::class, 'not a valid TCP or Unix socket');
        
        fclose($tmpFile);
    });

    it('throws BindFailedException for already connected socket', function () use (&$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('tcp://127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);

        $client = stream_socket_client('tcp://' . $serverAddr);
        $fd = get_fd_from_socket($client);

        expect(fn() => new FdServer($fd))->toThrow(BindFailedException::class);

        fclose($client);
    });

    it('closes the server and stops listening', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        expect($server->getAddress())->not->toBeNull();

        $server->close();
        expect($server->getAddress())->toBeNull();
    });

    it('accepts a connection and emits a connection event', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $connectionReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
            $connectionReceived = $connection;
            Loop::stop();
        });

        $clients[] = @stream_socket_client('tcp://' . $serverAddr, $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class)
            ->and($connectionReceived->getRemoteAddress())->not->toBeNull();
    });

    it('accepts multiple connections', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            if ($connectionCount === 3) {
                Loop::stop();
            }
        });

        $clients[] = @stream_socket_client('tcp://' . $serverAddr);
        $clients[] = @stream_socket_client('tcp://' . $serverAddr);
        $clients[] = @stream_socket_client('tcp://' . $serverAddr);

        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(3);
    });

    it('pauses and resumes accepting connections', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            $connection->end();
            Loop::stop();
        });

        $server->pause();

        $clients[] = @stream_socket_client('tcp://' . $serverAddr);
        expect(end($clients))->toBeResource();

        Loop::addTimer(0.01, function () use ($server, &$connectionCount) {
            expect($connectionCount)->toBe(0);
            $server->resume();
        });

        $timeout = Loop::addTimer(0.5, fn() => Loop::stop());

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(1);
    });

    it('pause after pause is no-op', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);

        $server->pause();
        $server->pause();

        expect($server->getAddress())->not->toBeNull();
    });

    it('resume without pause is no-op', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);

        $server->resume();

        expect($server->getAddress())->not->toBeNull();
    });

    it('close twice is no-op', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);

        $server->close();
        $server->close();

        expect($server->getAddress())->toBeNull();
    });

    it('emits data event when client sends data', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $dataReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived, &$clients) {
            $connection->on('data', function ($data) use (&$dataReceived) {
                $dataReceived = $data;
                Loop::stop();
            });

            fwrite(end($clients), "Hello World\n");
        });

        $clients[] = @stream_socket_client('tcp://' . $serverAddr, $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($dataReceived)->toBe("Hello World\n");
    });

    it('correctly handles Unix socket addresses', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $socketPath = sys_get_temp_dir() . '/test_' . uniqid() . '.sock';

        $listeningSockets[] = @stream_socket_server('unix://' . $socketPath);

        if (end($listeningSockets) === false) {
            test()->skip('Unix sockets not supported on this system');
        }

        $fd = get_fd_from_socket(end($listeningSockets));
        $server = new FdServer($fd);

        expect($server->getAddress())->toBe('unix://' . $socketPath);

        @unlink($socketPath);
    });

    it('correctly handles IPv6 addresses', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = @stream_socket_server('tcp://[::1]:0');

        if (end($listeningSockets) === false) {
            test()->skip('IPv6 is not supported on this system.');
        }

        $address = stream_socket_get_name(end($listeningSockets), false);
        $port = parse_url('tcp://' . $address, PHP_URL_PORT);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $connectionReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
            $connectionReceived = true;
            Loop::stop();
        });

        $clients[] = @stream_socket_client('tcp://[::1]:' . $port);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionReceived)->toBeTrue();
    });

    it('gets local address from connection', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $localAddress = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$localAddress) {
            $localAddress = $connection->getLocalAddress();
            Loop::stop();
        });

        $clients[] = @stream_socket_client('tcp://' . $serverAddr, $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($localAddress)->toContain('tcp://127.0.0.1:');
    });

    it('emits error when accept fails', function () use (&$server, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);
        $errorEmitted = false;

        $server->on('error', function ($error) use (&$errorEmitted) {
            $errorEmitted = true;
            expect($error)->toBeInstanceOf(Throwable::class);
        });

        (function () {
            fclose($this->master);
        })->call($server);

        (function () {
            $this->acceptConnection();
        })->call($server);

        expect($errorEmitted)->toBe(true);
    });

    it('connection can write data back to client', function () use (&$server, &$clients, &$listeningSockets) {
        if (!is_dir('/dev/fd')) {
            test()->skip('Not supported on your platform (requires /dev/fd)');
        }

        $listeningSockets[] = stream_socket_server('127.0.0.1:0');
        $serverAddr = stream_socket_get_name(end($listeningSockets), false);
        $fd = get_fd_from_socket(end($listeningSockets));

        $server = new FdServer($fd);

        $server->on('connection', function (ConnectionInterface $connection) {
            $connection->write("Welcome!\n");
        });

        $clients[] = @stream_socket_client('tcp://' . $serverAddr, $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        stream_set_blocking(end($clients), false);

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        $data = fread(end($clients), 1024);
        expect($data)->toBe("Welcome!\n");
    });
});