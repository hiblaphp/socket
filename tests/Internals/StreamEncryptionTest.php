<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Connection;
use Hibla\Socket\Internals\StreamEncryption;

describe('Stream Encryption', function () {
    $certFile = null;
    $server = null;
    $client = null;
    $connection = null;

    beforeEach(function () use (&$certFile) {
        if (DIRECTORY_SEPARATOR === '\\') {
            test()->markTestSkipped('Skipped on Windows');
        }

        $certFile = generate_temp_cert();

        Loop::reset();
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

        Loop::stop();
        Loop::reset();
    });

    it('successfully enables encryption (Server Mode)', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
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
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $completed = false;
        $received = '';

        $clientHandshakeTimer = null;
        $clientHandshake = function () use ($client, &$clientHandshake, &$clientHandshakeTimer) {
            if (! is_resource($client)) {
                return;
            }

            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($result === 0) {
                $clientHandshakeTimer = Loop::addTimer(0.01, $clientHandshake);
            } elseif ($clientHandshakeTimer !== null) {
                Loop::cancelTimer($clientHandshakeTimer);
                $clientHandshakeTimer = null;
            }
        };

        Loop::addTimer(0.01, $clientHandshake);

        $promise
            ->then(function ($result) use ($connection, $client, &$received, &$completed) {
                expect($result)->toBeInstanceOf(Connection::class);
                expect($connection->encryptionEnabled)->toBeTrue();

                if (is_resource($client)) {
                    fwrite($client, 'Hello Secure World');
                }

                $connection->on('data', function ($data) use (&$received, &$completed) {
                    $received .= $data;
                    if ($received === 'Hello Secure World') {
                        $completed = true;
                    }
                });
            })
            ->catch(function ($e) {
                test()->fail('Encryption should have succeeded: ' . $e->getMessage());
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $completed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($received)->toBe('Hello Secure World');
    });

    it('successfully enables encryption (Client Mode)', function () use (&$certFile, &$server, &$client, &$connection) {
        $serverContext = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $clientContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverContext);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $clientContext);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);

        $completed = false;
        $received = '';

        $serverHandshakeTimer = null;
        $serverHandshake = function () use ($serverSocket, &$serverHandshake, &$serverHandshakeTimer, &$completed, &$received) {
            if (! is_resource($serverSocket)) {
                return;
            }

            $result = @stream_socket_enable_crypto($serverSocket, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

            if ($result === 0) {
                $serverHandshakeTimer = Loop::addTimer(0.01, $serverHandshake);
            } elseif ($result === true) {
                if ($serverHandshakeTimer !== null) {
                    Loop::cancelTimer($serverHandshakeTimer);
                    $serverHandshakeTimer = null;
                }

                if (is_resource($serverSocket)) {
                    fwrite($serverSocket, 'Server Message');
                }
            }
        };
        Loop::addTimer(0.01, $serverHandshake);

        $connection = new Connection($client);
        $encryption = new StreamEncryption(isServer: false);
        $promise = $encryption->enable($connection);

        $promise
            ->then(function ($result) use ($connection, &$received, &$completed) {
                expect($result)->toBeInstanceOf(Connection::class);
                expect($connection->encryptionEnabled)->toBeTrue();

                $connection->on('data', function ($data) use (&$received, &$completed) {
                    $received .= $data;
                    if ($received === 'Server Message') {
                        $completed = true;
                    }
                });
            })
            ->catch(function ($e) {
                test()->fail('Client-side encryption should have succeeded: ' . $e->getMessage());
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $completed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($received)->toBe('Server Message');
    });

    it('ensures stream is non-blocking after encryption', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
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
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $completed = false;

        $clientHandshakeTimer = null;
        $clientHandshake = function () use ($client, &$clientHandshake, &$clientHandshakeTimer) {
            if (! is_resource($client)) {
                return;
            }

            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($result === 0) {
                $clientHandshakeTimer = Loop::addTimer(0.01, $clientHandshake);
            } elseif ($clientHandshakeTimer !== null) {
                Loop::cancelTimer($clientHandshakeTimer);
                $clientHandshakeTimer = null;
            }
        };
        Loop::addTimer(0.01, $clientHandshake);

        $promise
            ->then(function () use ($connection, &$completed) {
                $metadata = stream_get_meta_data($connection->getResource());
                expect($metadata['blocked'])->toBeFalse();
                $completed = true;
            })
            ->catch(function ($e) {
                test()->fail('Encryption should have succeeded: ' . $e->getMessage());
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $completed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($completed)->toBeTrue();
    });

    it('rejects when the handshake fails (e.g. non-TLS data)', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        if (is_resource($client)) {
            fwrite($client, "GET / HTTP/1.0\r\n\r\n");
        }

        $failed = false;
        $errorMessage = '';

        $promise
            ->then(function () {
                test()->fail('Handshake should have failed');
            })
            ->catch(function ($e) use (&$failed, &$errorMessage) {
                $failed = true;
                $errorMessage = $e->getMessage();
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $failed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($failed)->toBeTrue();
        expect($errorMessage)->not->toBeEmpty();
    });

    it('rejects when connection is closed during handshake', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        Loop::addTimer(0.01, function () use (&$client) {
            if (is_resource($client)) {
                fclose($client);
                $client = null;
            }
        });

        $failed = false;
        $errorMessage = '';

        $promise
            ->then(function () {
                test()->fail('Handshake should have failed due to connection loss');
            })
            ->catch(function ($e) use (&$failed, &$errorMessage) {
                $failed = true;
                $errorMessage = $e->getMessage();
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $failed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($failed)->toBeTrue();
        expect($errorMessage)->toContain('Connection lost during TLS handshake');
    });

    it('can be cancelled during handshake', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $promise->cancel();

        expect(fn () => $promise->wait())->toThrow(CancelledException::class);
    });

    it('handles multiple sequential handshake attempts', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
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
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $completed = false;
        $received = '';

        $clientHandshakeTimer = null;
        $clientHandshake = function () use ($client, &$clientHandshake, &$clientHandshakeTimer) {
            if (! is_resource($client)) {
                return;
            }

            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($result === 0) {
                $clientHandshakeTimer = Loop::addTimer(0.01, $clientHandshake);
            } elseif ($clientHandshakeTimer !== null) {
                Loop::cancelTimer($clientHandshakeTimer);
                $clientHandshakeTimer = null;
            }
        };
        Loop::addTimer(0.01, $clientHandshake);

        $promise
            ->then(function () use ($connection, $client, &$received, &$completed) {
                if (is_resource($client)) {
                    fwrite($client, 'Test');
                }

                $connection->on('data', function ($data) use (&$received, &$completed) {
                    $received .= $data;
                    if ($received === 'Test') {
                        $completed = true;
                    }
                });
            })
            ->catch(function ($e) {
                test()->fail('Encryption should have succeeded: ' . $e->getMessage());
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $completed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($received)->toBe('Test');
    });

    it('cleans up watchers on cancellation', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $promise->cancel();
        expect(fn () => $promise->wait())->toThrow(CancelledException::class);
        expect(is_resource($connection->getResource()))->toBeTrue();
    });

    it('pauses connection during handshake and resumes after', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
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
        stream_set_blocking($serverSocket, false);
        $connection = new Connection($serverSocket);

        $dataReceivedDuringHandshake = false;
        $connection->on('data', function () use (&$dataReceivedDuringHandshake) {
            $dataReceivedDuringHandshake = true;
        });

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $completed = false;
        $received = '';

        $clientHandshakeTimer = null;
        $clientHandshake = function () use ($client, &$clientHandshake, &$clientHandshakeTimer) {
            if (! is_resource($client)) {
                return;
            }

            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($result === 0) {
                $clientHandshakeTimer = Loop::addTimer(0.01, $clientHandshake);
            } elseif ($clientHandshakeTimer !== null) {
                Loop::cancelTimer($clientHandshakeTimer);
                $clientHandshakeTimer = null;
            }
        };
        Loop::addTimer(0.01, $clientHandshake);

        $promise
            ->then(function () use ($connection, $client, &$received, &$completed, &$dataReceivedDuringHandshake) {
                expect($dataReceivedDuringHandshake)->toBeFalse();

                if (is_resource($client)) {
                    fwrite($client, 'Post-handshake data');
                }

                $connection->on('data', function ($data) use (&$received, &$completed) {
                    $received .= $data;
                    if ($received === 'Post-handshake data') {
                        $completed = true;
                    }
                });
            })
            ->catch(function ($e) {
                test()->fail('Encryption should have succeeded: ' . $e->getMessage());
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $completed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($received)->toBe('Post-handshake data');
    });

    it('handles custom crypto method from context', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
            ],
        ]);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $clientContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $clientContext);
        stream_set_blocking($client, false);

        $r = [$server];
        $w = null;
        $e = null;
        stream_select($r, $w, $e, 1);

        $serverSocket = stream_socket_accept($server);
        stream_set_blocking($serverSocket, false);

        stream_context_set_option($serverSocket, 'ssl', 'local_cert', $certFile);
        stream_context_set_option($serverSocket, 'ssl', 'crypto_method', STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);

        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $completed = false;

        $clientHandshakeTimer = null;
        $clientHandshake = function () use ($client, &$clientHandshake, &$clientHandshakeTimer) {
            if (! is_resource($client)) {
                return;
            }

            $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
            if ($result === 0) {
                $clientHandshakeTimer = Loop::addTimer(0.01, $clientHandshake);
            } elseif ($clientHandshakeTimer !== null) {
                Loop::cancelTimer($clientHandshakeTimer);
                $clientHandshakeTimer = null;
            }
        };
        Loop::addTimer(0.01, $clientHandshake);

        $promise
            ->then(function ($result) use ($connection, &$completed) {
                expect($result)->toBeInstanceOf(Connection::class);
                expect($connection->encryptionEnabled)->toBeTrue();
                $completed = true;
            })
            ->catch(function ($e) {
                test()->fail('Encryption should have succeeded: ' . $e->getMessage());
            })
        ;

        $timeout = microtime(true) + 2;
        while (! $completed && microtime(true) < $timeout) {
            Loop::runOnce();
        }

        expect($completed)->toBeTrue();
    });
});
