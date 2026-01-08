<?php

use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\AddressInUseException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\UnixServer;

describe('Unix Server', function () {
    $socketPath = null;
    $server = null;
    $clients = [];

    beforeEach(function () use (&$socketPath) {
        $socketPath = sys_get_temp_dir() . '/hibla-socket-test-' . uniqid(rand(), true) . '.sock';
    });

    afterEach(function () use (&$socketPath, &$server, &$clients) {
        foreach ($clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $clients = [];

        if ($server !== null) {
            $server->close();
            $server = null;
        }

        if ($socketPath && file_exists(str_replace('unix://', '', $socketPath))) {
            @unlink(str_replace('unix://', '', $socketPath));
        }
    });

    it('successfully constructs and listens on a socket file', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);
        expect($server->getAddress())->toBe('unix://' . str_replace('unix://', '', $socketPath));
        expect(file_exists(str_replace('unix://', '', $socketPath)))->toBeTrue();
    });

    it('accepts path without unix:// prefix', function () use (&$server) {
        $path = sys_get_temp_dir() . '/hibla-no-prefix-' . uniqid() . '.sock';
        $server = new UnixServer($path);

        expect($server->getAddress())->toBe('unix://' . $path);
        expect(file_exists($path))->toBeTrue();

        @unlink($path);
    });

    it('throws an exception for invalid URI scheme', function () {
        expect(fn() => new UnixServer('tcp://localhost:0'))->toThrow(InvalidUriException::class);
        expect(fn() => new UnixServer('http://example.com'))->toThrow(InvalidUriException::class);
    });

    it('throws an exception when the socket path is already in use', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);

        set_error_handler(function () {});
        expect(fn() => new UnixServer($socketPath))->toThrow(AddressInUseException::class);
        restore_error_handler();
    });

    it('closes the server and removes listening state', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);
        expect(file_exists(str_replace('unix://', '', $socketPath)))->toBeTrue();

        $server->close();

        expect($server->getAddress())->toBeNull();
    });

    it('close twice is no-op', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);

        $server->close();
        $server->close();

        expect($server->getAddress())->toBeNull();
    });

    it('accepts a connection and emits a connection event', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $connectionReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
            $connectionReceived = $connection;
            Loop::stop();
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class);
        expect($connectionReceived->getLocalAddress())->toContain('unix://');
    });

    it('accepts multiple connections', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            if ($connectionCount === 3) {
                Loop::stop();
            }
        });

        for ($i = 0; $i < 3; $i++) {
            $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            if ($client !== false) {
                $clients[] = $client;
            }
        }

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(3);
    });

    it('pauses and resumes accepting connections', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
            $connection->end();
            Loop::stop();
        });

        $server->pause();

        $client1 = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client1 !== false) {
            $clients[] = $client1;
        }
        expect($client1)->toBeResource();

        Loop::addTimer(0.01, function () use ($server, &$connectionCount) {
            expect($connectionCount)->toBe(0);
            $server->resume();
        });

        $timeout = Loop::addTimer(0.5, fn() => Loop::stop());

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(1);
    });

    it('pause after pause is no-op', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);

        $server->pause();
        $server->pause();

        expect($server->getAddress())->not->toBeNull();
    });

    it('resume without pause is no-op', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);

        $server->resume();

        expect($server->getAddress())->not->toBeNull();
    });

    it('emits data event when client sends data', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $dataReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived, &$clients) {
            $connection->on('data', function ($data) use (&$dataReceived) {
                $dataReceived = $data;
                Loop::stop();
            });

            fwrite(end($clients), "Hello Unix Socket\n");
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($dataReceived)->toBe("Hello Unix Socket\n");
    });

    it('does not emit data event when client sends no data', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $dataReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived) {
            $connection->on('data', function ($data) use (&$dataReceived) {
                $dataReceived = true;
            });
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($dataReceived)->toBe(false);
    });

    it('emits end event when client closes connection', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $endReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$endReceived, &$clients) {
            $connection->on('end', function () use (&$endReceived) {
                $endReceived = true;
                Loop::stop();
            });

            Loop::addTimer(0.01, function () use (&$clients) {
                if (!empty($clients) && is_resource(end($clients))) {
                    fclose(end($clients));
                    array_pop($clients);
                }
            });
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($endReceived)->toBe(true);
    });

    it('does not emit end event when client does not close', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $endReceived = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$endReceived) {
            $connection->on('end', function () use (&$endReceived) {
                $endReceived = true;
            });
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($endReceived)->toBe(false);
    });

    it('connection can write data back to client', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);

        $server->on('connection', function (ConnectionInterface $connection) {
            $connection->write("Welcome to Unix!\n");
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        stream_set_blocking($client, false);

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        $data = fread($client, 1024);
        expect($data)->toBe("Welcome to Unix!\n");
    });

    it('gets local address from connection', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $localAddress = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$localAddress) {
            $localAddress = $connection->getLocalAddress();
            Loop::stop();
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($localAddress)->toContain('unix://');
    });

    it('emits error when accept fails', function () use (&$socketPath, &$server) {
        $server = new UnixServer($socketPath);
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

    it('can handle connection with end method', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $closed = false;

        $server->on('connection', function (ConnectionInterface $connection) use (&$closed) {
            $connection->on('close', function () use (&$closed) {
                $closed = true;
                Loop::stop();
            });
            $connection->end("Goodbye from Unix\n");
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        $timeout = Loop::addTimer(1.0, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($closed)->toBe(true);
    });

    it('handles multiple rapid connections', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $connectionCount = 0;

        $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
            $connectionCount++;
        });

        for ($i = 0; $i < 10; $i++) {
            $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            if ($client !== false) {
                $clients[] = $client;
            }
        }

        $timeout = Loop::addTimer(0.1, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        expect($connectionCount)->toBe(10);
    });

    it('connection maintains bidirectional communication', function () use (&$socketPath, &$server, &$clients) {
        $server = new UnixServer($socketPath);
        $serverReceived = null;

        $server->on('connection', function (ConnectionInterface $connection) use (&$serverReceived) {
            $connection->on('data', function ($data) use (&$serverReceived, $connection) {
                $serverReceived = $data;
                $connection->write("Echo: " . $data);
            });
        });

        $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
        if ($client !== false) {
            $clients[] = $client;
        }
        expect($client)->toBeResource();

        stream_set_blocking($client, false);

        Loop::addTimer(0.01, function () use ($client) {
            fwrite($client, "Test message\n");
        });

        $timeout = Loop::addTimer(0.2, function () {
            Loop::stop();
        });

        Loop::run();

        Loop::cancelTimer($timeout);

        $response = fread($client, 1024);

        expect($serverReceived)->toBe("Test message\n")
            ->and($response)->toBe("Echo: Test message\n");
    });
})->skipOnWindows();
