<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Socket\Connection;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\UnixConnector;

describe('Unix Connector', function () {
    describe('URI validation', function () {
        it('accepts unix:// scheme', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect('unix://' . $socketPath);

                expect($promise)->toBeInstanceOf(Hibla\Promise\Interfaces\PromiseInterface::class);
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('automatically prepends unix:// scheme when missing', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($socketPath);

                expect($promise)->toBeInstanceOf(Hibla\Promise\Interfaces\PromiseInterface::class);
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('rejects invalid scheme', function () {
            $connector = new UnixConnector();

            expect(fn () => $connector->connect('tcp:///invalid'))
                ->toThrow(InvalidUriException::class, 'is invalid')
            ;
        });

        it('rejects http scheme', function () {
            $connector = new UnixConnector();

            expect(fn () => $connector->connect('http:///invalid'))
                ->toThrow(InvalidUriException::class, 'expected format: unix://')
            ;
        });
    });

    describe('connection errors', function () {
        it('rejects when socket file does not exist', function () {
            $socketPath = sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.sock';
            $connector = new UnixConnector();

            $promise = $connector->connect($socketPath);

            try {
                $promise->wait();
                throw new Exception('Should have thrown');
            } catch (ConnectionFailedException $e) {
                expect($e->getMessage())->toContain('does not exist');
            }
        });

        it('rejects when path is not a socket', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/regular-file.txt';

            file_put_contents($filePath, 'not a socket');

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($filePath);

                try {
                    $promise->wait();
                    throw new Exception('Should have thrown');
                } catch (ConnectionFailedException $e) {
                    expect($e->getMessage())->toContain('not a valid Unix domain socket');
                }
            } finally {
                unlink($filePath);
                rmdir($tempDir);
            }
        });

        it('rejects when path is a directory', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $dirPath = $tempDir . '/subdir';
            mkdir($dirPath);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($dirPath);

                try {
                    $promise->wait();
                    throw new Exception('Should have thrown');
                } catch (ConnectionFailedException $e) {
                    expect($e->getMessage())->toContain('not a valid Unix domain socket');
                }
            } finally {
                rmdir($dirPath);
                rmdir($tempDir);
            }
        });

        it('rejects when socket is not readable', function () {
            if (posix_geteuid() === 0) {
                $this->markTestSkipped('Test skipped when running as root');
            }

            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );

            chmod($socketPath, 0000);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($socketPath);

                try {
                    $promise->wait();
                    throw new Exception('Should have thrown');
                } catch (ConnectionFailedException $e) {
                    expect($e->getMessage())->toContain('not a valid Unix domain socket');
                }
            } finally {
                chmod($socketPath, 0777);
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });
    });

    describe('successful connections', function () {
        it('connects successfully to unix socket', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($socketPath);
                
                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                
                $connection->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('connects with unix:// scheme prefix', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect('unix://' . $socketPath);
                
                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                $connection->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('resolves promise with connection interface', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $resolved = false;
                $connection = null;

                $connector->connect($socketPath)
                    ->then(function ($conn) use (&$resolved, &$connection) {
                        $resolved = true;
                        $connection = $conn;
                    });

                Loop::addTimer(0.01, function() {
                    Loop::stop();
                });

                Loop::run();

                expect($resolved)->toBeTrue();
                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                
                if ($connection) {
                    $connection->close();
                }
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });
    });

    describe('promise behavior', function () {
        it('returns fulfilled promise for successful connection', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($socketPath);

                expect($promise->isFulfilled())->toBeTrue();
                expect($promise->isRejected())->toBeFalse();

                $connection = $promise->value;
                expect($connection)->toBeInstanceOf(Connection::class);
                
                $connection->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('fulfills promise on successful connection', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($socketPath);
                
                $connection = $promise->wait();

                expect($promise->isFulfilled())->toBeTrue();
                expect($promise->isPending())->toBeFalse();
                expect($promise->isRejected())->toBeFalse();
                
                $connection->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('rejects promise on connection failure', function () {
            $socketPath = sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.sock';
            $connector = new UnixConnector();

            $promise = $connector->connect($socketPath);

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                // Expected
            }

            expect($promise->isRejected())->toBeTrue();
            expect($promise->isPending())->toBeFalse();
            expect($promise->isFulfilled())->toBeFalse();
        });
    });

    describe('error codes', function () {
        it('uses ENOENT for non-existent socket', function () {
            $socketPath = sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.sock';
            $connector = new UnixConnector();

            $promise = $connector->connect($socketPath);

            try {
                $promise->wait();
            } catch (ConnectionFailedException $e) {
                $expectedCode = defined('SOCKET_ENOENT') ? SOCKET_ENOENT : 2;
                expect($e->getCode())->toBe($expectedCode);
            }
        });

        it('uses ENOTSOCK for invalid socket type', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/regular-file.txt';

            file_put_contents($filePath, 'not a socket');

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($filePath);

                try {
                    $promise->wait();
                } catch (ConnectionFailedException $e) {
                    $expectedCode = defined('SOCKET_ENOTSOCK') ? SOCKET_ENOTSOCK : 88;
                    expect($e->getCode())->toBe($expectedCode);
                }
            } finally {
                unlink($filePath);
                rmdir($tempDir);
            }
        });

        it('uses EINVAL for invalid URI', function () {
            $connector = new UnixConnector();

            try {
                $connector->connect('http:///invalid');
            } catch (InvalidUriException $e) {
                $expectedCode = defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22);
                expect($e->getCode())->toBe($expectedCode);
            }
        });
    });

    describe('multiple connections', function () {
        it('can create multiple connections to same socket', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promise1 = $connector->connect($socketPath);
                $promise2 = $connector->connect($socketPath);

                $connection1 = $promise1->wait();
                $connection2 = $promise2->wait();

                expect($connection1)->toBeInstanceOf(ConnectionInterface::class);
                expect($connection2)->toBeInstanceOf(ConnectionInterface::class);
                expect($connection1)->not->toBe($connection2);
                
                $connection1->close();
                $connection2->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('can create connections sequentially', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                
                $connection1 = $connector->connect($socketPath)->wait();
                $connection2 = $connector->connect($socketPath)->wait();
                $connection3 = $connector->connect($socketPath)->wait();

                expect($connection1)->toBeInstanceOf(ConnectionInterface::class);
                expect($connection2)->toBeInstanceOf(ConnectionInterface::class);
                expect($connection3)->toBeInstanceOf(ConnectionInterface::class);
                
                $connection1->close();
                $connection2->close();
                $connection3->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });
    });

    describe('edge cases', function () {
        it('handles socket path with special characters', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/socket-with-special@chars#.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promise = $connector->connect($socketPath);
                
                $connection = $promise->wait();

                expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                $connection->close();
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });

        it('handles concurrent connection attempts', function () {
            $tempDir = sys_get_temp_dir() . '/hibla-test-' . uniqid();
            mkdir($tempDir, 0777, true);
            $socketPath = $tempDir . '/test.sock';

            $server = stream_socket_server(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            stream_set_blocking($server, false);

            try {
                $connector = new UnixConnector();
                $promises = [];

                for ($i = 0; $i < 10; $i++) {
                    $promises[] = $connector->connect($socketPath);
                }

                $connections = array_map(fn ($p) => $p->wait(), $promises);

                expect($connections)->toHaveCount(10);
                foreach ($connections as $connection) {
                    expect($connection)->toBeInstanceOf(ConnectionInterface::class);
                    $connection->close();
                }
            } finally {
                fclose($server);
                unlink($socketPath);
                rmdir($tempDir);
            }
        });
    });
})->skipOnWindows();