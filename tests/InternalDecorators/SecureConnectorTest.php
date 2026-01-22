<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Connection;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\SecureConnector;
use Hibla\Socket\SecureServer;
use Hibla\Socket\TcpConnector;
use Hibla\Socket\TcpServer;

describe('Secure Connector', function () {
    describe('URI validation', function () {
        it('accepts tls:// scheme', function () {
            $connector = new SecureConnector(new TcpConnector());

            expect(function () use ($connector) {
                $promise = $connector->connect('tls://127.0.0.1:443');
                $promise->catch(fn () => null);

                return null;
            })->not->toThrow(InvalidUriException::class);
        });

        it('automatically prepends tls:// scheme when missing', function () {
            $connector = new SecureConnector(new TcpConnector());

            expect(function () use ($connector) {
                $promise = $connector->connect('127.0.0.1:443');
                $promise->catch(fn () => null);

                return null;
            })->not->toThrow(InvalidUriException::class);
        });

        it('rejects invalid scheme', function () {
            $connector = new SecureConnector(new TcpConnector());

            expect(fn () => $connector->connect('http://127.0.0.1:443'))
                ->toThrow(InvalidUriException::class, 'is invalid')
            ;
        });

        it('rejects tcp:// scheme', function () {
            $connector = new SecureConnector(new TcpConnector());

            expect(fn () => $connector->connect('tcp://127.0.0.1:443'))
                ->toThrow(InvalidUriException::class)
            ;
        });
    });

    describe('successful TLS connections', function () {
        it('connects successfully to TLS server', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $connectionReceived = null;

                $server->on('connection', function (ConnectionInterface $connection) use (&$connectionReceived) {
                    $connectionReceived = $connection;
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                $clientConnection = null;

                $promise->then(
                    function ($connection) use (&$clientConnection) {
                        $clientConnection = $connection;
                        Loop::stop();
                    },
                    function ($error) {
                        Loop::stop();
                    }
                );

                $timeout = Loop::addTimer(2.0, function () {
                    Loop::stop();
                });

                Loop::run();

                Loop::cancelTimer($timeout);

                expect($clientConnection)->toBeInstanceOf(ConnectionInterface::class);
                expect($connectionReceived)->toBeInstanceOf(ConnectionInterface::class);
                expect($promise->isFulfilled())->toBeTrue();

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });

        it('resolves promise with secure connection', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $server->on('connection', function (ConnectionInterface $connection) {
                    // Connection received
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $resolved = false;
                $connection = null;

                $connector->connect("tls://127.0.0.1:{$port}")
                    ->then(function ($conn) use (&$resolved, &$connection) {
                        $resolved = true;
                        $connection = $conn;
                        Loop::stop();
                    })
                ;

                Loop::addTimer(2.0, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($resolved)->toBeTrue();
                expect($connection)->toBeInstanceOf(ConnectionInterface::class);

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });

        it('returns Connection instance', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $server->on('connection', function (ConnectionInterface $connection) {
                    // Connection received
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                Loop::addTimer(2.0, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(Connection::class);

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });
    });

    describe('connection failures', function () {
        it('rejects when TCP connection refused', function () {
            $connector = new SecureConnector(new TcpConnector());

            $promise = $connector->connect('tls://127.0.0.1:1');

            Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();

            expect($promise->isRejected())->toBeTrue();

            try {
                $promise->wait();

                throw new Exception('Should have thrown');
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('failed');
            }
        });

        it('rejects with ConnectionFailedException on TCP failure', function () {
            $connector = new SecureConnector(new TcpConnector());
            $promise = $connector->connect('tls://127.0.0.1:1');

            Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();

            try {
                $promise->wait();

                throw new Exception('Should have thrown');
            } catch (ConnectionFailedException $e) {
                expect($e)->toBeInstanceOf(ConnectionFailedException::class);
            }
        });

        it('rejects with EncryptionFailedException when TLS handshake fails', function () {
            $port = get_free_port();

            $server = new TcpServer('127.0.0.1:' . $port);

            $server->on('connection', function (ConnectionInterface $connection) {
                $connection->write("HTTP/1.1 400 Bad Request\r\n\r\n");
            });

            $server->on('error', function ($error) {
                // Handle errors silently
            });

            try {
                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                $rejected = false;
                $error = null;

                $promise->then(
                    function ($conn) {
                        Loop::stop();
                    },
                    function ($err) use (&$rejected, &$error) {
                        $rejected = true;
                        $error = $err;
                        Loop::stop();
                    }
                );

                Loop::addTimer(3.0, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($rejected)->toBeTrue();
                expect($error)->toBeInstanceOf(EncryptionFailedException::class);
                expect($error->getMessage())->toContain('TLS handshake');
            } finally {
                $server->close();
            }
        });

        it('includes error message in exception', function () {
            $connector = new SecureConnector(new TcpConnector());
            $promise = $connector->connect('tls://127.0.0.1:1');

            Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('tls://127.0.0.1:1');
            }
        });
    });

    describe('cancellation', function () {
        it('can cancel pending connection during TCP phase', function () {
            $connector = new SecureConnector(new TcpConnector());

            $promise = $connector->connect('tls://192.0.2.1:443');

            Loop::addTimer(0.05, function () use ($promise) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect($promise->isCancelled())->toBeTrue();
            expect($promise->isPending())->toBeFalse();
        });

        it('can cancel pending connection during TLS handshake', function () {
            $port = get_free_port();

            $server = new TcpServer('127.0.0.1:' . $port);

            $server->on('connection', function (ConnectionInterface $connection) {
                // Keep connection open without TLS handshake
            });

            $server->on('error', function ($error) {
                // Handle errors silently
            });

            try {
                $connector = new SecureConnector(new TcpConnector());
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                Loop::addTimer(0.1, function () use ($promise) {
                    $promise->cancel();
                    Loop::stop();
                });

                Loop::run();

                expect($promise->isCancelled())->toBeTrue();
            } finally {
                $server->close();
            }
        });

        it('cannot wait on cancelled promise', function () {
            $connector = new SecureConnector(new TcpConnector());

            $promise = $connector->connect('tls://192.0.2.1:443');

            Loop::addTimer(0.05, function () use ($promise) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect(fn () => $promise->wait())
                ->toThrow(CancelledException::class, 'Cannot wait on a cancelled promise')
            ;
        });
    });

    describe('multiple connections', function () {
        it('can create multiple secure connections sequentially', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $server->on('connection', function (ConnectionInterface $connection) {
                    // Connection received
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $connections = [];
                $completed = 0;

                $promise1 = $connector->connect("tls://127.0.0.1:{$port}");
                $promise1->then(function ($conn) use (&$connections, &$completed) {
                    $connections[0] = $conn;
                    $completed++;
                });

                Loop::addTimer(0.1, function () use ($connector, $port, &$connections, &$completed) {
                    $promise2 = $connector->connect("tls://127.0.0.1:{$port}");
                    $promise2->then(function ($conn) use (&$connections, &$completed) {
                        $connections[1] = $conn;
                        $completed++;
                        if ($completed === 2) {
                            Loop::stop();
                        }
                    });
                });

                Loop::addTimer(5.0, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($connections[0] ?? null)->toBeInstanceOf(ConnectionInterface::class);
                expect($connections[1] ?? null)->toBeInstanceOf(ConnectionInterface::class);
                expect($connections[0])->not->toBe($connections[1]);

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });

        it('can create multiple secure connections concurrently', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $server->on('connection', function (ConnectionInterface $connection) {
                    // Connection received
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);

                $promises = [];
                $connections = [];
                $resolvedCount = 0;

                for ($i = 0; $i < 3; $i++) {
                    $index = $i;
                    $promise = $connector->connect("tls://127.0.0.1:{$port}");
                    $promise->then(function ($conn) use (&$connections, &$resolvedCount, $index) {
                        $connections[$index] = $conn;
                        $resolvedCount++;
                        if ($resolvedCount === 3) {
                            Loop::stop();
                        }
                    });
                    $promises[] = $promise;
                }

                Loop::addTimer(3.0, function () {
                    Loop::stop();
                });

                Loop::run();

                foreach ($promises as $index => $promise) {
                    expect($promise->isFulfilled())->toBeTrue();
                    expect($connections[$index])->toBeInstanceOf(ConnectionInterface::class);
                }

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });
    });

    describe('SSL context options', function () {
        it('accepts custom SSL context options in constructor', function () {
            $context = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => '/path/to/ca.pem',
            ];

            $connector = new SecureConnector(new TcpConnector(), $context);

            expect($connector)->toBeInstanceOf(SecureConnector::class);
        });

        it('applies SSL context to connection', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $server->on('connection', function (ConnectionInterface $connection) {
                    // Connection received
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                Loop::addTimer(2.0, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();
                expect($connection)->toBeInstanceOf(ConnectionInterface::class);

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });
    });

    describe('connector composition', function () {
        it('wraps underlying connector correctly', function () {
            $tcpConnector = new TcpConnector();
            $secureConnector = new SecureConnector($tcpConnector);

            expect($secureConnector)->toBeInstanceOf(SecureConnector::class);
        });

        it('propagates TCP connection errors', function () {
            $tcpConnector = new TcpConnector([]);
            $secureConnector = new SecureConnector($tcpConnector);

            $promise = $secureConnector->connect('tls://127.0.0.1:1');

            Loop::addTimer(0.5, function () {
                Loop::stop();
            });

            Loop::run();

            expect($promise->isRejected())->toBeTrue();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('tls://127.0.0.1:1');
            }
        });
    });

    describe('edge cases', function () {
        it('handles connection close during handshake', function () {
            $port = get_free_port();

            $server = new TcpServer('127.0.0.1:' . $port);

            $server->on('connection', function (ConnectionInterface $connection) {
                $connection->close();
            });

            $server->on('error', function ($error) {
                // Handle errors silently
            });

            try {
                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                $promise->catch(function ($error) {
                    // Expected rejection, handle silently
                });

                Loop::addTimer(2.0, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($promise->isRejected())->toBeTrue();
            } finally {
                $server->close();
            }
        });

        it('strips tls:// scheme for underlying TCP connector', function () {
            $certFile = generate_temp_cert();

            try {
                $port = get_free_port();

                $tcpServer = new TcpServer('127.0.0.1:' . $port);
                $server = new SecureServer($tcpServer, [
                    'local_cert' => $certFile,
                ]);

                $server->on('connection', function (ConnectionInterface $connection) {
                    // Connection received
                });

                $server->on('error', function ($error) {
                    // Handle errors silently
                });

                $sslContext = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];

                $connector = new SecureConnector(new TcpConnector(), $sslContext);
                $promise = $connector->connect("tls://127.0.0.1:{$port}");

                Loop::addTimer(2.0, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($promise->isFulfilled())->toBeTrue();

                $server->close();
            } finally {
                @unlink($certFile);
            }
        });
    });
})->skipOnWindows();
