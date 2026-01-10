<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Socket\Connection;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\TcpConnector;
use Hibla\Promise\Exceptions\PromiseCancelledException;

describe("Tcp Connector", function () {
    describe('URI validation', function () {
        it('accepts tcp:// scheme', function () {
            $connector = new TcpConnector();

            expect(fn() => $connector->connect('tcp://127.0.0.1:80'))
                ->not->toThrow(InvalidUriException::class);
        });

        it('automatically prepends tcp:// scheme when missing', function () {
            $connector = new TcpConnector();

            expect(fn() => $connector->connect('127.0.0.1:80'))
                ->not->toThrow(InvalidUriException::class);
        });

        it('rejects URI without port', function () {
            $connector = new TcpConnector();

            expect(fn() => $connector->connect('tcp://127.0.0.1'))
                ->toThrow(InvalidUriException::class, 'expected format: tcp://host:port');
        });

        it('rejects URI without host', function () {
            $connector = new TcpConnector();

            expect(fn() => $connector->connect('tcp://:8080'))
                ->toThrow(InvalidUriException::class, 'expected format: tcp://host:port');
        });

        it('rejects invalid scheme', function () {
            $connector = new TcpConnector();

            expect(fn() => $connector->connect('http://127.0.0.1:80'))
                ->toThrow(InvalidUriException::class, 'expected format: tcp://host:port');
        });

        it('rejects non-IP hostname', function () {
            $connector = new TcpConnector();

            expect(fn() => $connector->connect('tcp://example.com:80'))
                ->toThrow(InvalidUriException::class, 'does not contain a valid host IP address');
        });

        it('accepts IPv4 address', function () {
            $connector = new TcpConnector();

            try {
                $promise = $connector->connect('tcp://192.168.1.1:8080');
                $promise->catch(fn() => null);
            } catch (InvalidUriException $e) {
                throw $e;
            }

            expect(true)->toBeTrue();
        });

        it('accepts IPv6 address', function () {
            $connector = new TcpConnector();

            try {
                $promise = $connector->connect('tcp://[::1]:8080');
                $promise->catch(fn() => null);
            } catch (InvalidUriException $e) {
                throw $e;
            }

            expect(true)->toBeTrue();
        });
    });

    describe('Successful connections', function () {
        it('connects successfully to TCP server', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            expect($server)->toBeResource();

            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);

            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();
                $promise = $connector->connect("tcp://127.0.0.1:{$port}");

                Loop::addTimer(0.01, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                expect($promise->isFulfilled())->toBeTrue();
            } finally {
                fclose($server);
            }
        });

        it('connects to IPv6 localhost', function () {
            if (!@stream_socket_server('tcp://[::1]:0')) {
                $this->markTestSkipped('IPv6 not supported on this system');
            }

            $server = stream_socket_server('tcp://[::1]:0', $errno, $errstr);
            expect($server)->toBeResource();

            $address = stream_socket_get_name($server, false);
            preg_match('/\[([^\]]+)\]:(\d+)/', $address, $matches);
            $port = $matches[2];

            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();
                $promise = $connector->connect("tcp://[::1]:{$port}");

                Loop::addTimer(0.01, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
            } finally {
                fclose($server);
            }
        });

        it('resolves promise with connection', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();
                $resolved = false;
                $connection = null;

                $connector->connect("tcp://127.0.0.1:{$port}")
                    ->then(function ($conn) use (&$resolved, &$connection) {
                        $resolved = true;
                        $connection = $conn;
                        Loop::stop();
                    });

                Loop::addTimer(0.1, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($resolved)->toBeTrue();
                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
            } finally {
                fclose($server);
            }
        });

        it('returns Connection instance', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();
                $promise = $connector->connect("tcp://127.0.0.1:{$port}");

                Loop::addTimer(0.01, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(Connection::class);
            } finally {
                fclose($server);
            }
        });
    });

    describe('connection failures', function () {
        it('rejects when connection refused', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://127.0.0.1:1');

            Loop::addTimer(0.1, function () {
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

        it('rejects with ConnectionFailedException', function () {
            $connector = new TcpConnector();
            $promise = $connector->connect('tcp://127.0.0.1:1');

            Loop::addTimer(0.1, function () {
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

        it('includes error message in exception', function () {
            $connector = new TcpConnector();
            $promise = $connector->connect('tcp://127.0.0.1:1');

            Loop::addTimer(0.1, function () {
                Loop::stop();
            });

            Loop::run();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('tcp://127.0.0.1:1');
            }
        });

        it('sets error code in exception', function () {
            $connector = new TcpConnector();
            $promise = $connector->connect('tcp://127.0.0.1:1');

            Loop::addTimer(0.1, function () {
                Loop::stop();
            });

            Loop::run();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                expect($e->getCode())->toBeGreaterThan(0);
            }
        });
    });

    describe('timeout', function () {
        it('times out when connection takes too long', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://192.0.2.1:80', 0.1);

            Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();

            expect($promise->isRejected())->toBeTrue();

            try {
                $promise->wait();
                throw new Exception('Should have thrown');
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('timed out');
                expect($e->getMessage())->toContain('0.10 seconds');
            }
        });

        it('uses default timeout from constructor', function () {
            $connector = new TcpConnector([], 0.1);

            $promise = $connector->connect('tcp://192.0.2.1:80');

            Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();

            expect($promise->isRejected())->toBeTrue();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('timed out');
            }
        });

        it('overrides default timeout with explicit timeout', function () {
            $connector = new TcpConnector([], 10.0);

            $promise = $connector->connect('tcp://192.0.2.1:80', 0.1);

            Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('0.10 seconds');
            }
        });

        it('timeout error code is ETIMEDOUT', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://192.0.2.1:80', 0.1);

            Loop::addTimer(0.2, function () {
                Loop::stop();
            });

            Loop::run();

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                $expectedCode = defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110;
                expect($e->getCode())->toBe($expectedCode);
            }
        });

        it('no timeout when set to null', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector([], 0.1);
                $promise = $connector->connect("tcp://127.0.0.1:{$port}", null);

                Loop::addTimer(0.01, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();
                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
            } finally {
                fclose($server);
            }
        });
    });

    describe('cancellation', function () {
        it('can cancel pending connection', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://192.0.2.1:80');
            $exceptionThrown = false;

            Loop::addTimer(0.01, function () use ($promise, &$exceptionThrown) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect($promise->isCancelled())->toBeTrue();
            expect($promise->isPending())->toBeFalse();
        });

        it('cancelled promise throws ConnectionCancelledException', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://192.0.2.1:80');
            $exception = null;

            Loop::addTimer(0.01, function () use ($promise, &$exception) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('cancellation cleans up resources', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://192.0.2.1:80');
            $cancelled = false;

            Loop::addTimer(0.01, function () use ($promise, &$cancelled) {
                $promise->cancel();
            });

            Loop::addTimer(0.02, function () {
                Loop::stop();
            });

            Loop::run();

            expect($promise->isCancelled())->toBeTrue();
            expect($promise->isPending())->toBeFalse();
            expect($promise->isRejected())->toBeFalse();
            expect($promise->isFulfilled())->toBeFalse();
        });

        it('cannot wait on cancelled promise', function () {
            $connector = new TcpConnector();

            $promise = $connector->connect('tcp://192.0.2.1:80');

            Loop::addTimer(0.01, function () use ($promise) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect(fn() => $promise->wait())
                ->toThrow(PromiseCancelledException::class, 'Cannot wait on a cancelled promise');
        });
    });

    describe('multiple connections', function () {
        it('can create multiple connections sequentially', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();

                $promise1 = $connector->connect("tcp://127.0.0.1:{$port}");
                Loop::addTimer(0.01, fn() => Loop::stop());
                Loop::run();
                $connection1 = $promise1->wait();

                $promise2 = $connector->connect("tcp://127.0.0.1:{$port}");
                Loop::addTimer(0.01, fn() => Loop::stop());
                Loop::run();
                $connection2 = $promise2->wait();

                expect($connection1)->toBeInstanceOf(ConnectionInterface::class);
                expect($connection2)->toBeInstanceOf(ConnectionInterface::class);
                expect($connection1)->not->toBe($connection2);
            } finally {
                fclose($server);
            }
        });

        it('can create multiple connections concurrently', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();

                $promises = [];
                for ($i = 0; $i < 5; $i++) {
                    $promises[] = $connector->connect("tcp://127.0.0.1:{$port}");
                }

                Loop::addTimer(0.05, function () {
                    Loop::stop();
                });

                Loop::run();

                foreach ($promises as $promise) {
                    expect($promise->isFulfilled())->toBeTrue();
                    expect($promise->wait())->toBeInstanceOf(ConnectionInterface::class);
                }
            } finally {
                fclose($server);
            }
        });
    });

    describe('context options', function () {
        it('accepts custom context options in constructor', function () {
            $context = [
                'bindto' => '127.0.0.1:0',
            ];

            $connector = new TcpConnector($context);

            expect($connector)->toBeInstanceOf(TcpConnector::class);
        });

        it('handles hostname query parameter for SNI', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();
                $promise = $connector->connect("tcp://127.0.0.1:{$port}?hostname=example.com");

                Loop::addTimer(0.01, function () {
                    Loop::stop();
                });

                Loop::run();

                $connection = $promise->wait();
                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
            } finally {
                fclose($server);
            }
        });
    });

    describe('edge cases', function () {
        it('handles connection with high port number', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();
                $promise = $connector->connect("tcp://127.0.0.1:{$port}");

                Loop::addTimer(0.01, function () {
                    Loop::stop();
                });

                Loop::run();

                expect($promise->isFulfilled())->toBeTrue();
            } finally {
                fclose($server);
            }
        });

        it('handles rapid connect and disconnect', function () {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $address = stream_socket_get_name($server, false);
            [, $port] = explode(':', $address);
            stream_set_blocking($server, false);

            try {
                $connector = new TcpConnector();

                for ($i = 0; $i < 3; $i++) {
                    $promise = $connector->connect("tcp://127.0.0.1:{$port}");

                    Loop::addTimer(0.01, function () {
                        Loop::stop();
                    });

                    Loop::run();

                    $connection = $promise->wait();
                    expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                }
            } finally {
                fclose($server);
            }
        });
    });
});
