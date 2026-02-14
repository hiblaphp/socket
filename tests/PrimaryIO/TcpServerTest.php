<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\BindFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\TcpServer;

describe('TCP Server', function () {
    $server = null;
    $clients = [];

    afterEach(function () use (&$server, &$clients) {
        foreach ($clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $clients = [];

        if ($server instanceof TcpServer) {
            $server->close();
            $server = null;
        }
    });

    it('constructs with just a port number', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer((string)$port);
        expect($server->getAddress())->toBe('tcp://127.0.0.1:' . $port);
    });

    it('finds a random free port when constructed with port 0', function () use (&$server) {
        $server = new TcpServer('127.0.0.1:0');
        $address = $server->getAddress();

        expect($address)->not->toBeNull()
            ->and($address)->not->toContain(':0')
        ;

        $port = parse_url($address, PHP_URL_PORT);
        expect($port)->toBeGreaterThan(0);
    });

    it('throws InvalidUriException for an invalid URI', function () {
        expect(fn () => new TcpServer('127.0.0.1'))->toThrow(InvalidUriException::class);
        expect(fn () => new TcpServer('localhost:8080'))->toThrow(InvalidUriException::class, 'Invalid URI');
        expect(fn () => new TcpServer('unix:///tmp/sock'))->toThrow(InvalidUriException::class);
    });

    it('throws BindFailedException when an address is already in use', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);

        set_error_handler(function () {
        });
        expect(fn () => new TcpServer('127.0.0.1:' . $port))->toThrow(BindFailedException::class);
        restore_error_handler();
    })->skipOnWindows();

    it('closes the server and stops listening', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        expect($server->getAddress())->not->toBeNull();

        $server->close();
        expect($server->getAddress())->toBeNull();
    });

    it('accepts a connection and emits a connection event', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $connectionReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
            $connectionReceived = $connection;
            Loop::stop();
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class)
            ->and($connectionReceived->getRemoteAddress())->not->toBeNull()
        ;
    });

    it('accepts multiple connections', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            if ($connectionCount === 3) {
                Loop::stop();
            }
        });

        $clients[] = @stream_socket_client($server->getAddress());
        $clients[] = @stream_socket_client($server->getAddress());
        $clients[] = @stream_socket_client($server->getAddress());

        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(3);
    });

    it('pauses and resumes accepting connections', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            $connection->end();
            Loop::stop();
        });

        $server->pause();

        $clients[] = @stream_socket_client($server->getAddress());
        expect(end($clients))->toBeResource();

        Loop::addTimer(0.01, function () use ($server, &$connectionCount) {
            expect($connectionCount)->toBe(0);
            $server->resume();
        });

        $timeout = Loop::addTimer(0.5, fn () => Loop::stop());

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(1);
    });

    it('pause after pause is no-op', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);

        $server->pause();
        $server->pause();

        expect($server->getAddress())->not->toBeNull();
    });

    it('resume without pause is no-op', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);

        $server->resume();

        expect($server->getAddress())->not->toBeNull();
    });

    it('close twice is no-op', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);

        $server->close();
        $server->close();

        expect($server->getAddress())->toBeNull();
    });

    it('emits data event when client sends data', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $dataReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived, &$clients) {
            $connection->on('data', function ($data) use (&$dataReceived) {
                $dataReceived = $data;
                Loop::stop();
            });

            fwrite(end($clients), "Hello World\n");
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($dataReceived)->toBe("Hello World\n");
    });

    it('does not emit data event when client sends no data', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $dataReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived) {
            $connection->on('data', function ($data) use (&$dataReceived) {
                $dataReceived = true;
            });
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($dataReceived)->toBe(false);
    });

    it('emits end event when client closes connection', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $endReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$endReceived, &$clients) {
            $connection->on('end', function () use (&$endReceived) {
                $endReceived = true;
                Loop::stop();
            });

            Loop::addTimer(0.01, function () use (&$clients) {
                if (! empty($clients) && is_resource(end($clients))) {
                    fclose(end($clients));
                    array_pop($clients);
                }
            });
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($endReceived)->toBe(true);
    });

    it('does not emit end event when client does not close', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $endReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$endReceived) {
            $connection->on('end', function () use (&$endReceived) {
                $endReceived = true;
            });
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($endReceived)->toBe(false);
    });

    it('connection can write data back to client', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);

        $server->on('connection', function (ConnectionInterface $connection) {
            $connection->write("Welcome!\n");
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
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

    it('correctly handles IPv6 addresses', function () use (&$server, &$clients) {
        $socket = @stream_socket_server('tcp://[::1]:0');
        if ($socket === false) {
            test()->skip('IPv6 is not supported on this system.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = parse_url('tcp://' . $address, PHP_URL_PORT);

        $server = new TcpServer('[::1]:' . $port);
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

    it('gets local address from connection', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $localAddress = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$localAddress) {
            $localAddress = $connection->getLocalAddress();
            Loop::stop();
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($localAddress)->toContain('tcp://127.0.0.1:' . $port);
    });

    it('emits error when accept fails', function () use (&$server) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
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

    it('can handle connection with end method', function () use (&$server, &$clients) {
        $port = get_free_port();
        $server = new TcpServer('127.0.0.1:' . $port);
        $closed = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$closed) {
            $connection->on('close', function () use (&$closed) {
                $closed = true;
                Loop::stop();
            });
            $connection->end("Goodbye\n");
        });

        $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        expect(end($clients))->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($closed)->toBe(true);
    });
});
