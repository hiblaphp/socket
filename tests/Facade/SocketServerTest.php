<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\BindFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\SocketServer;

describe('Socket Server', function () {
    $server = null;
    $clients = [];
    $certFile = null;

    beforeEach(function () use (&$certFile) {
        if (DIRECTORY_SEPARATOR === '\\') {
            test()->markTestSkipped('Skipped on Windows');
        }

        $certFile = generate_temp_cert();

        Loop::reset();
    });

    afterEach(function () use (&$server, &$clients, &$certFile) {
        foreach ($clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $clients = [];

        if ($server instanceof SocketServer) {
            $server->close();
            $server = null;
        }

        if ($certFile && file_exists($certFile)) {
            @unlink($certFile);
        }

        Loop::stop();
        Loop::reset();
    });

    describe('TCP Server creation', function () use (&$server, &$clients) {
        it('constructs with just a port number', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer((string)$port);
            expect($server->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('constructs with tcp:// scheme', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            expect($server->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('constructs with ip:port format', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            expect($server->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('finds a random free port when constructed with port 0', function () use (&$server) {
            $server = new SocketServer('127.0.0.1:0');
            $address = $server->getAddress();

            expect($address)->not->toBeNull()
                ->and($address)->not->toContain(':0')
            ;

            $port = parse_url($address, PHP_URL_PORT);
            expect($port)->toBeGreaterThan(0);
        });

        it('accepts a connection and emits connection event', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $connectionReceived = null;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = $connection;
                Loop::stop();
            });

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            expect(end($clients))->toBeResource();

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class)
                ->and($connectionReceived->getRemoteAddress())->not->toBeNull()
            ;
        });

        it('accepts multiple connections', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
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

            run_with_timeout(1.0);

            expect($connectionCount)->toBe(3);
        });

        it('correctly handles IPv6 addresses', function () use (&$server, &$clients) {
            $socket = @stream_socket_server('tcp://[::1]:0');
            if ($socket === false) {
                test()->skip('IPv6 is not supported on this system.');
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $port = parse_url('tcp://' . $address, PHP_URL_PORT);

            $server = new SocketServer('[::1]:' . $port);
            $connectionReceived = false;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = true;
                Loop::stop();
            });

            $clients[] = @stream_socket_client('tcp://[::1]:' . $port);
            expect(end($clients))->toBeResource();

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeTrue();
        });

        it('throws InvalidUriException for invalid numeric-only URI', function () {
            expect(fn () => new SocketServer('999999'))->toThrow(InvalidUriException::class);
        });

        it('constructs with 0.0.0.0 to listen on all interfaces', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('0.0.0.0:' . $port);
            $address = $server->getAddress();

            expect($address)->toBe('tcp://0.0.0.0:' . $port);
        });

        it('throws BindFailedException when port is already in use', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);

            set_error_handler(function () {
            });
            expect(fn () => new SocketServer('127.0.0.1:' . $port))->toThrow(BindFailedException::class);
            restore_error_handler();
        })->skipOnWindows();

        it('handles rapid connection establishment and closing', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $connectionCount = 0;
            $closeCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount, &$closeCount) {
                $connectionCount++;
                $connection->on('close', function () use (&$closeCount) {
                    $closeCount++;
                });
            });
            for ($i = 0; $i < 10; $i++) {
                $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
                if ($client !== false) {
                    $clients[] = $client;
                    Loop::addTimer(0.01 * $i, function () use ($client) {
                        if (is_resource($client)) {
                            fclose($client);
                        }
                    });
                }
            }

            Loop::addTimer(0.5, fn () => Loop::stop());

            run_with_timeout(2.0);

            expect($connectionCount)->toBe(10)
                ->and($closeCount)->toBeGreaterThan(0)
            ;
        });

        it('handles connection when server is at different binding stages', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->pause();
            expect($server->getAddress())->not->toBeNull();

            $server->resume();
            expect($server->getAddress())->not->toBeNull();

            $connectionReceived = false;
            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = true;
                Loop::stop();
            });

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeTrue();
        });
    });

    describe('TLS Server creation', function () use (&$server, &$clients, &$certFile) {
        it('constructs with tls:// scheme and returns tls address', function () use (&$server, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $address = $server->getAddress();

            expect($address)->toBe('tls://127.0.0.1:' . $port);
        });

        it('accepts encrypted connection and emits connection event', function () use (&$server, &$clients, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $connectionReceived = null;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = $connection;
                Loop::stop();
            });

            $server->on('error', function ($error) {
                echo 'Error: ' . $error->getMessage() . "\n";
            });

            Loop::addTimer(0.05, function () use ($port, &$clients) {
                create_async_tls_client($port, $clients);
            });

            run_with_timeout(5.0);

            expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class)
                ->and($connectionReceived->getRemoteAddress())->not->toBeNull()
            ;
        });

        it('accepts multiple encrypted connections', function () use (&$server, &$clients, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $connectionCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
                if ($connectionCount === 3) {
                    Loop::stop();
                }
            });

            $server->on('error', function ($error) {
                echo 'Error: ' . $error->getMessage() . "\n";
            });

            Loop::addTimer(0.05, function () use ($port, &$clients) {
                create_async_tls_client($port, $clients);
                create_async_tls_client($port, $clients);
                create_async_tls_client($port, $clients);
            });

            run_with_timeout(5.0);

            expect($connectionCount)->toBe(3);
        });

        it('can send and receive encrypted data', function () use (&$server, &$clients, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $dataReceived = null;
            $clientData = null;

            $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived) {
                $connection->on('data', function ($data) use (&$dataReceived, $connection) {
                    $dataReceived = $data;
                    $connection->write('Echo: ' . $data);
                });
            });

            $server->on('error', function ($error) {
                echo 'Error: ' . $error->getMessage() . "\n";
            });

            Loop::addTimer(0.05, function () use ($port, &$clients, &$clientData) {
                $client = @stream_socket_client(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $errstr,
                    5,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
                );

                if ($client === false) {
                    return;
                }

                stream_set_blocking($client, false);
                $clients[] = $client;

                stream_context_set_option($client, 'ssl', 'verify_peer', false);
                stream_context_set_option($client, 'ssl', 'verify_peer_name', false);
                stream_context_set_option($client, 'ssl', 'allow_self_signed', true);

                Loop::addTimer(0.1, function () use ($client, &$clientData) {
                    $watcherId = null;
                    $handshakeComplete = false;

                    $enableCrypto = function () use ($client, &$watcherId, &$handshakeComplete, &$clientData) {
                        $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                        if ($result === true) {
                            if ($watcherId !== null) {
                                Loop::removeReadWatcher($watcherId);
                            }
                            $handshakeComplete = true;

                            fwrite($client, "Hello Secure World\n");

                            Loop::addTimer(0.5, function () use ($client, &$clientData) {
                                $clientData = @fread($client, 1024);
                                Loop::stop();
                            });
                        } elseif ($result === false) {
                            if ($watcherId !== null) {
                                Loop::removeReadWatcher($watcherId);
                            }
                        }
                    };

                    $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                    if ($result === 0) {
                        $watcherId = Loop::addReadWatcher(
                            $client,
                            $enableCrypto,
                        );
                    } elseif ($result === true) {
                        $enableCrypto();
                    }
                });
            });

            run_with_timeout(5.0);

            expect($dataReceived)->toBe("Hello Secure World\n")
                ->and($clientData)->toBe("Echo: Hello Secure World\n")
            ;
        });

        it('handles TLS handshake failure gracefully', function () use (&$server, &$clients, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $errorReceived = false;

            $server->on('error', function ($error) use (&$errorReceived) {
                $errorReceived = true;
                Loop::stop();
            });

            Loop::addTimer(0.05, function () use ($port, &$clients) {
                $client = @stream_socket_client(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $errstr,
                    1
                );

                if ($client !== false) {
                    $clients[] = $client;
                    @fwrite($client, "plain text\n");
                }
            });

            run_with_timeout(2.0);

            expect($errorReceived)->toBeTrue();
        });

        it('handles multiple simultaneous TLS handshakes', function () use (&$server, &$clients, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $successCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$successCount) {
                $successCount++;
                if ($successCount === 5) {
                    Loop::stop();
                }
            });

            Loop::addTimer(0.05, function () use ($port, &$clients) {
                for ($i = 0; $i < 5; $i++) {
                    create_async_tls_client($port, $clients);
                }
            });

            run_with_timeout(5.0);

            expect($successCount)->toBe(5);
        });

        it('pauses and resumes TLS server correctly', function () use (&$server, &$clients, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            $connectionCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
                Loop::stop();
            });

            $server->pause();

            Loop::addTimer(0.05, function () use ($port, &$clients) {
                create_async_tls_client($port, $clients);
            });

            Loop::addTimer(0.3, function () use ($server, &$connectionCount) {
                expect($connectionCount)->toBe(0);
                $server->resume();
            });

            run_with_timeout(5.0);

            expect($connectionCount)->toBe(1);
        });
    });

    describe('Unix Server creation', function () use (&$server, &$clients) {
        it('constructs with unix:// scheme', function () use (&$server) {
            $socketPath = sys_get_temp_dir() . '/hibla-socket-test-' . uniqid('', true) . '.sock';
            $server = new SocketServer('unix://' . $socketPath);

            expect($server->getAddress())->toBe('unix://' . $socketPath);
            expect(file_exists($socketPath))->toBeTrue();

            @unlink($socketPath);
        });

        it('accepts connection on unix socket', function () use (&$server, &$clients) {
            $socketPath = sys_get_temp_dir() . '/hibla-socket-test-' . uniqid('', true) . '.sock';
            $server = new SocketServer('unix://' . $socketPath);
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

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class);
            expect($connectionReceived->getLocalAddress())->toContain('unix://');

            @unlink($socketPath);
        });

        it('handles multiple rapid unix socket connections', function () use (&$server, &$clients) {
            $socketPath = sys_get_temp_dir() . '/hibla-socket-test-' . uniqid('', true) . '.sock';
            $server = new SocketServer('unix://' . $socketPath);
            $connectionCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
            });

            for ($i = 0; $i < 15; $i++) {
                $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
                if ($client !== false) {
                    $clients[] = $client;
                }
            }

            Loop::addTimer(0.2, fn () => Loop::stop());

            run_with_timeout(1.0);

            expect($connectionCount)->toBe(15);

            @unlink($socketPath);
        });

        it('cleans up socket file on close', function () use (&$server) {
            $socketPath = sys_get_temp_dir() . '/hibla-socket-test-' . uniqid('', true) . '.sock';
            $server = new SocketServer('unix://' . $socketPath);

            expect(file_exists($socketPath))->toBeTrue();

            $server->close();

            expect(file_exists($socketPath))->toBeFalse();
        });
    })->skipOnWindows();

    describe('FD Server creation', function () use (&$server, &$clients) {
        it('constructs with php://fd/ scheme', function () use (&$server, &$clients) {
            if (PHP_OS_FAMILY === 'Windows') {
                test()->skip('FD extraction not reliably supported on Windows');
            }

            $testSocket = create_listening_socket('tcp://127.0.0.1:0');
            $fd = get_fd_from_socket($testSocket);

            $server = new SocketServer('php://fd/' . $fd);

            expect($server->getAddress())->toContain('tcp://127.0.0.1:');

            $clients[] = $testSocket;
        });

        it('accepts connection on FD server', function () use (&$server, &$clients) {
            if (PHP_OS_FAMILY === 'Windows') {
                test()->skip('FD extraction not reliably supported on Windows');
            }

            $testSocket = create_listening_socket('tcp://127.0.0.1:0');
            $fd = get_fd_from_socket($testSocket);
            $address = stream_socket_get_name($testSocket, false);

            $server = new SocketServer('php://fd/' . $fd);
            $connectionReceived = null;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = $connection;
                Loop::stop();
            });

            $client = @stream_socket_client('tcp://' . $address, $errno, $errstr, 1);
            if ($client !== false) {
                $clients[] = $client;
            }
            expect($client)->toBeResource();

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class);

            $clients[] = $testSocket;
        });

        it('constructs with php://fd/ and numeric FD', function () use (&$server, &$clients) {
            if (PHP_OS_FAMILY === 'Windows') {
                test()->skip('FD extraction not reliably supported on Windows');
            }

            $testSocket = create_listening_socket('tcp://127.0.0.1:0');
            $fd = get_fd_from_socket($testSocket);

            $server = new SocketServer('php://fd/' . $fd);

            expect($server->getAddress())->toContain('tcp://127.0.0.1:');

            $clients[] = $testSocket;
        });
    });

    describe('Server operations', function () use (&$server, &$clients) {
        it('pauses and resumes accepting connections', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $connectionCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount) {
                $connectionCount++;
                $connection->end();
                Loop::stop();
            });

            $server->pause();

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            expect(end($clients))->toBeResource();

            Loop::addTimer(0.01, function () use ($server, &$connectionCount) {
                expect($connectionCount)->toBe(0);
                $server->resume();
            });

            run_with_timeout(1.0);

            expect($connectionCount)->toBe(1);
        });

        it('closes the server and stops listening', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            expect($server->getAddress())->not->toBeNull();

            $server->close();
            expect($server->getAddress())->toBeNull();
        });

        it('returns null address when server is closed', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->close();
            $address = $server->getAddress();

            expect($address)->toBeNull();
        });

        it('emits error events from underlying server', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $errorReceived = null;

            $server->on('error', function ($error) use (&$errorReceived) {
                $errorReceived = $error;
            });

            (function () {
                $this->server->emit('error', [new RuntimeException('Test error')]);
            })->call($server);

            expect($errorReceived)->toBeInstanceOf(RuntimeException::class)
                ->and($errorReceived->getMessage())->toBe('Test error')
            ;
        });

        it('handles multiple pause/resume cycles', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->pause();
            $server->pause();
            $server->resume();
            $server->resume();

            $connectionReceived = false;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = true;
                Loop::stop();
            });

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeTrue();
        });

        it('handles close after close (idempotent)', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->close();
            $server->close();
            $server->close();

            expect($server->getAddress())->toBeNull();
        });

        it('handles pause after close', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->close();
            $server->pause();

            expect($server->getAddress())->toBeNull();
        });

        it('handles resume after close', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->close();
            $server->resume();

            expect($server->getAddress())->toBeNull();
        });
    });

    describe('Context options', function () use (&$server, &$certFile) {
        it('passes tcp context options to TcpServer', function () use (&$server) {
            $port = get_free_port();

            $server = new SocketServer('tcp://127.0.0.1:' . $port, [
                'tcp' => ['backlog' => 128],
            ]);

            expect($server->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('passes tls context options to SecureServer', function () use (&$server, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => [
                    'local_cert' => $certFile,
                    'verify_peer' => false,
                ],
            ]);

            expect($server->getAddress())->toBe('tls://127.0.0.1:' . $port);
        });

        it('passes unix context options to UnixServer', function () use (&$server) {
            $socketPath = sys_get_temp_dir() . '/hibla-socket-test-' . uniqid('', true) . '.sock';

            $server = new SocketServer('unix://' . $socketPath, [
                'unix' => ['backlog' => 128],
            ]);

            expect($server->getAddress())->toBe('unix://' . $socketPath);

            @unlink($socketPath);
        })->skipOnWindows();

        it('handles empty context array', function () use (&$server) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port, []);

            expect($server->getAddress())->toBe('tcp://127.0.0.1:' . $port);
        });

        it('merges default context with provided context', function () use (&$server, &$certFile) {
            $port = get_free_port();

            $server = new SocketServer('tls://127.0.0.1:' . $port, [
                'tls' => ['local_cert' => $certFile],
            ]);

            expect($server->getAddress())->toBe('tls://127.0.0.1:' . $port);
        });
    });

    describe('Data communication', function () use (&$server, &$clients) {
        it('emits data event when client sends data', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
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

            run_with_timeout(1.0);

            expect($dataReceived)->toBe("Hello World\n");
        });

        it('connection can write data back to client', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $server->on('connection', function (ConnectionInterface $connection) {
                $connection->write("Welcome!\n");
            });

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            expect(end($clients))->toBeResource();
            $client = end($clients);

            stream_set_blocking($client, false);

            $receivedData = null;
            $watcherId = null;

            $watcherId = Loop::addReadWatcher($client, function ($resource) use ($client, &$receivedData, &$watcherId) {
                $receivedData = fread($client, 1024);
                if ($watcherId) {
                    Loop::removeReadWatcher($watcherId);
                }
                Loop::stop();
            });

            run_with_timeout(1.0);

            expect($receivedData)->toBe("Welcome!\n");
        });

        it('emits end event when client closes connection', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
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

            run_with_timeout(1.0);

            expect($endReceived)->toBe(true);
        });

        it('handles large data transfer', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $dataReceived = '';
            $largeData = str_repeat('A', 65536); // 64KB

            $server->on('connection', function (ConnectionInterface $connection) use (&$dataReceived, $largeData) {
                $connection->on('data', function ($data) use (&$dataReceived, $largeData) {
                    $dataReceived .= $data;
                    if (strlen($dataReceived) >= strlen($largeData)) {
                        Loop::stop();
                    }
                });
            });

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            expect(end($clients))->toBeResource();

            stream_set_blocking(end($clients), false);
            fwrite(end($clients), $largeData);

            run_with_timeout(2.0);

            expect(strlen($dataReceived))->toBe(strlen($largeData));
        });

        it('handles bidirectional data exchange', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $serverReceived = null;
            $clientReceived = null;

            $server->on('connection', function (ConnectionInterface $connection) use (&$serverReceived) {
                $connection->on('data', function ($data) use (&$serverReceived, $connection) {
                    $serverReceived = $data;
                    $connection->write('Server got: ' . $data);
                });
            });

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            expect(end($clients))->toBeResource();

            stream_set_blocking(end($clients), false);

            Loop::addTimer(0.01, function () use (&$clients) {
                fwrite(end($clients), "Client message\n");
            });

            $client = end($clients);
            $watcherId = null;
            $watcherId = Loop::addReadWatcher($client, function ($resource) use ($client, &$clientReceived, &$watcherId) {
                $clientReceived = fread($client, 1024);
                if ($watcherId) {
                    Loop::removeReadWatcher($watcherId);
                }
                Loop::stop();
            });

            run_with_timeout(1.0);

            expect($serverReceived)->toBe("Client message\n")
                ->and($clientReceived)->toBe("Server got: Client message\n")
            ;
        });

        it('handles connection close event', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
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

            run_with_timeout(1.0);

            expect($closed)->toBe(true);
        });
    });

    describe('Edge cases and error handling', function () use (&$server, &$clients) {
        it('throws InvalidUriException for empty URI', function () {
            expect(fn () => new SocketServer(''))->toThrow(InvalidUriException::class);
        });

        it('throws InvalidUriException for whitespace-only URI', function () {
            expect(fn () => new SocketServer('   '))->toThrow(InvalidUriException::class);
        });

        it('throws InvalidUriException for invalid scheme', function () {
            expect(fn () => new SocketServer('http://127.0.0.1:8080'))->toThrow(InvalidUriException::class);
        });

        it('throws InvalidUriException for malformed URI', function () {
            expect(fn () => new SocketServer('tcp://:8080'))->toThrow(InvalidUriException::class);
        });

        it('throws InvalidUriException for invalid port', function () {
            expect(fn () => new SocketServer('127.0.0.1:99999'))->toThrow(InvalidUriException::class);
        });

        it('handles connection from localhost vs 127.0.0.1', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('127.0.0.1:' . $port);
            $connectionReceived = false;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                $connectionReceived = true;
                Loop::stop();
            });

            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1);
            if ($client !== false) {
                $clients[] = $client;
            }

            run_with_timeout(1.0);

            expect($connectionReceived)->toBeTrue();
        });

        it('handles rapid server creation and destruction', function () {
            $port = get_free_port();

            for ($i = 0; $i < 5; $i++) {
                $tempServer = new SocketServer('127.0.0.1:' . $port);
                expect($tempServer->getAddress())->not->toBeNull();
                $tempServer->close();
                expect($tempServer->getAddress())->toBeNull();
            }

            expect(true)->toBeTrue();
        });

        it('handles connection when no listeners are attached', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);

            $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
            expect(end($clients))->toBeResource();

            Loop::addTimer(0.1, fn () => Loop::stop());

            run_with_timeout(1.0);

            expect(true)->toBeTrue();
        });

        it('handles error listener on connection events', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $errorCaught = false;

            $server->on('connection', function (ConnectionInterface $connection) {
                throw new RuntimeException('Connection error');
            });

            $server->on('error', function ($error) use (&$errorCaught) {
                $errorCaught = true;
            });

            try {
                $clients[] = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);

                Loop::addTimer(0.1, fn () => Loop::stop());

                run_with_timeout(1.0);
            } catch (RuntimeException $e) {
                // Exception thrown during connection handling
            }

            expect(end($clients))->toBeResource();
        });

        it('maintains connection stability under load', function () use (&$server, &$clients) {
            $port = get_free_port();
            $server = new SocketServer('tcp://127.0.0.1:' . $port);
            $connectionCount = 0;
            $dataCount = 0;

            $server->on('connection', function (ConnectionInterface $connection) use (&$connectionCount, &$dataCount) {
                $connectionCount++;

                $connection->on('data', function ($data) use (&$dataCount, $connection) {
                    $dataCount++;
                    $connection->write('Echo: ' . $data);

                    if ($dataCount === 20) {
                        Loop::stop();
                    }
                });
            });

            for ($i = 0; $i < 20; $i++) {
                $client = @stream_socket_client($server->getAddress(), $errno, $errstr, 1);
                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $clients[] = $client;
                    fwrite($client, "Message $i\n");
                }
            }

            run_with_timeout(1.0);

            expect($connectionCount)->toBe(20)
                ->and($dataCount)->toBe(20)
            ;
        });
    });
});
