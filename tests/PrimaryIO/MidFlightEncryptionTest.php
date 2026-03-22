<?php

declare(strict_types=1);

use Hibla\Socket\SocketServer;
use Hibla\Socket\Connector;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use function Hibla\{async, await};

function generateSelfSignedCert(string $dir): array
{
    $key  = $dir . '/test.key';
    $cert = $dir . '/test.crt';

    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new(
        ['commonName' => '127.0.0.1'],
        $privateKey,
        ['digest_alg' => 'sha256']
    );

    $x509 = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);

    openssl_x509_export_to_file($x509, $cert);
    openssl_pkey_export_to_file($privateKey, $key);

    return ['cert' => $cert, 'key' => $key];
}

$certDir   = null;
$certFiles = null;

beforeAll(function () use (&$certDir, &$certFiles) {
    if (! extension_loaded('openssl')) {
        test()->markTestSkipped('ext-openssl is required for TLS tests');
    }

    $certDir = sys_get_temp_dir() . '/hibla_socket_test_' . getmypid();
    mkdir($certDir, 0700, true);

    $certFiles = generateSelfSignedCert($certDir);
});

afterAll(function () use (&$certDir) {
    if ($certDir === null) {
        return;
    }

    foreach (glob($certDir . '/*') as $file) {
        unlink($file);
    }

    rmdir($certDir);
});

function runWithTimeout(float $seconds, callable $fn): void
{
    $done    = false;
    $timerId = Loop::addTimer($seconds, function () use (&$done) {
        $done = true;
        Loop::stop();
    });

    async(function () use ($fn, &$done, $timerId) {
        try {
            await(async($fn));
        } finally {
            $done = true;
            Loop::cancelTimer($timerId);
        }
    });

    Loop::run();

    if ($done && ! Loop::isRunning()) {
        return;
    }
}

describe('mid-flight TLS upgrade (STARTTLS)', function () use (&$certFiles) {

    it('completes server and client TLS upgrade successfully', function () use (&$certFiles) {
        $log = [];

        $record = function (string $msg) use (&$log) {
            $log[] = $msg;
        };

        $server = new SocketServer('tcp://127.0.0.1:0');
        $port   = parse_url($server->getAddress(), PHP_URL_PORT);

        $server->on('error', fn(\Throwable $e) => $record("[server:error] " . $e->getMessage()));

        $server->on('connection', function ($connection) use ($record, $certFiles, $server) {
            $connection->on('error', fn(\Throwable $e) => $record("[server:conn:error] " . $e->getMessage()));

            $connection->once('data', function (string $data) use ($connection, $record, $certFiles, $server) {
                if (trim($data) !== 'STARTTLS') {
                    return;
                }

                $connection->write("+OK\n");

                $connection->enableEncryption([
                    'local_cert'  => $certFiles['cert'],
                    'local_pk'    => $certFiles['key'],
                    'verify_peer' => false,
                ], isServer: true)
                    ->then(function ($secureConn) use ($record, $server) {
                        $record('[server] upgrade:' . $secureConn->getRemoteAddress());

                        $secureConn->on('error', fn(\Throwable $e) => $record("[server:secure:error] " . $e->getMessage()));

                        $secureConn->once('data', function (string $data) use ($secureConn, $record, $server) {
                            $record('[server] secure:received:' . trim($data));
                            $secureConn->write('ECHO:' . trim($data) . "\n");

                            Loop::addTimer(0.1, function () use ($secureConn, $server) {
                                $secureConn->close();
                                $server->close();
                            });
                        });
                    })
                    ->catch(function (EncryptionFailedException $e) use ($record, $connection) {
                        $record('[server] upgrade:failed:' . $e->getMessage());
                        $connection->close();
                    });
            });
        });

        runWithTimeout(5.0, function () use ($record, $port, $certFiles, &$log) {
            $connector  = new Connector(['dns' => false]);
            $connection = await($connector->connect("tcp://127.0.0.1:{$port}"));

            $connection->on('error', fn(\Throwable $e) => $record("[client:error] " . $e->getMessage()));

            $connection->write("STARTTLS\n");

            $serverResponse = await(new Promise(function ($resolve) use ($connection) {
                $connection->once('data', fn(string $data) => $resolve(trim($data)));
            }));

            expect($serverResponse)->toBe('+OK');

            $secureConn = await($connection->enableEncryption([
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ], isServer: false));

            $record('[client] upgrade:' . $secureConn->getRemoteAddress());

            $secureConn->on('error', fn(\Throwable $e) => $record("[client:secure:error] " . $e->getMessage()));

            $secureConn->write("HELLO SECURE WORLD\n");

            $echo = await(new Promise(function ($resolve) use ($secureConn) {
                $secureConn->once('data', fn(string $data) => $resolve(trim($data)));
            }));

            $record('[client] secure:received:' . $echo);
        });

        $serverUpgrades = array_filter($log, fn($l) => str_starts_with($l, '[server] upgrade:'));
        expect($serverUpgrades)->not->toBeEmpty();
        expect(array_values($serverUpgrades)[0])->toContain('tls://');

        $clientUpgrades = array_filter($log, fn($l) => str_starts_with($l, '[client] upgrade:'));
        expect($clientUpgrades)->not->toBeEmpty();
        expect(array_values($clientUpgrades)[0])->toContain('tls://');

        $serverReceived = array_filter($log, fn($l) => str_starts_with($l, '[server] secure:received:'));
        expect($serverReceived)->not->toBeEmpty();
        expect(array_values($serverReceived)[0])->toBe('[server] secure:received:HELLO SECURE WORLD');

        $clientReceived = array_filter($log, fn($l) => str_starts_with($l, '[client] secure:received:'));
        expect($clientReceived)->not->toBeEmpty();
        expect(array_values($clientReceived)[0])->toBe('[client] secure:received:ECHO:HELLO SECURE WORLD');

        $errors = array_filter($log, fn($l) => str_contains($l, ':error]'));
        expect($errors)->toBeEmpty();
    });

    it('fires EncryptionFailedException on bad certificate', function () use (&$certFiles) {
        $server = new SocketServer('tcp://127.0.0.1:0');
        $port   = parse_url($server->getAddress(), PHP_URL_PORT);
        $caught = null;

        $server->on('error', fn() => null);

        $server->on('connection', function ($connection) use ($certFiles, $server) {
            $connection->on('error', fn() => null);

            $connection->once('data', function (string $data) use ($connection, $certFiles, $server) {
                if (trim($data) !== 'STARTTLS') {
                    return;
                }

                $connection->write("+OK\n");

                $connection->enableEncryption([
                    'local_cert'  => $certFiles['cert'],
                    'local_pk'    => $certFiles['cert'], 
                    'verify_peer' => false,
                ], isServer: true)
                    ->then(fn() => null)
                    ->catch(function () use ($connection, $server) {
                        $connection->close();
                        $server->close();
                    });
            });
        });

        runWithTimeout(5.0, function () use ($port, &$caught, $server) {
            $connector  = new Connector(['dns' => false]);
            $connection = await($connector->connect("tcp://127.0.0.1:{$port}"));

            $connection->on('error', fn() => null);
            $connection->write("STARTTLS\n");

            await(new Promise(function ($resolve) use ($connection) {
                $connection->once('data', fn(string $data) => $resolve(trim($data)));
            }));

            try {
                await($connection->enableEncryption([
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ], isServer: false));
            } catch (EncryptionFailedException $e) {
                $caught = $e;
            } finally {
                $connection->close();
                $server->close();
            }
        });

        expect($caught)->toBeInstanceOf(EncryptionFailedException::class);
    });

    it('plain data listener does not fire after TLS upgrade when using once()', function () use (&$certFiles) {
        $plainFiredCount  = 0;
        $secureFiredCount = 0;

        $server = new SocketServer('tcp://127.0.0.1:0');
        $port   = parse_url($server->getAddress(), PHP_URL_PORT);

        $server->on('error', fn() => null);

        $server->on('connection', function ($connection) use (
            $certFiles,
            $server,
            &$plainFiredCount,
            &$secureFiredCount
        ) {
            $connection->on('error', fn() => null);

            $connection->once('data', function (string $data) use (
                $connection,
                $certFiles,
                $server,
                &$plainFiredCount,
                &$secureFiredCount
            ) {
                $plainFiredCount++;

                if (trim($data) !== 'STARTTLS') {
                    return;
                }

                $connection->write("+OK\n");

                $connection->enableEncryption([
                    'local_cert'  => $certFiles['cert'],
                    'local_pk'    => $certFiles['key'],
                    'verify_peer' => false,
                ], isServer: true)
                    ->then(function ($secureConn) use ($server, &$secureFiredCount) {
                        $secureConn->on('error', fn() => null);

                        $secureConn->once('data', function (string $data) use ($secureConn, $server, &$secureFiredCount) {
                            $secureFiredCount++;
                            $secureConn->write('ECHO:' . trim($data) . "\n");

                            Loop::addTimer(0.1, function () use ($secureConn, $server) {
                                $secureConn->close();
                                $server->close();
                            });
                        });
                    })
                    ->catch(fn() => null);
            });
        });

        runWithTimeout(5.0, function () use ($port, $certFiles) {
            $connector  = new Connector(['dns' => false]);
            $connection = await($connector->connect("tcp://127.0.0.1:{$port}"));

            $connection->on('error', fn() => null);
            $connection->write("STARTTLS\n");

            await(new Promise(function ($resolve) use ($connection) {
                $connection->once('data', fn(string $data) => $resolve(trim($data)));
            }));

            $secureConn = await($connection->enableEncryption([
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ], isServer: false));

            $secureConn->on('error', fn() => null);
            $secureConn->write("HELLO SECURE WORLD\n");

            await(new Promise(function ($resolve) use ($secureConn) {
                $secureConn->once('data', fn(string $data) => $resolve(trim($data)));
            }));

            $secureConn->close();
        });

        expect($plainFiredCount)->toBe(1);

        expect($secureFiredCount)->toBe(1);
    });
});
