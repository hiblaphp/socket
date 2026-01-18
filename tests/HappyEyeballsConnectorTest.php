<?php

declare(strict_types=1);

use Hibla\Dns\Dns;
use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\HappyEyeBallsConnector;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\TcpConnector;
use Tests\Mocks\MockConnection;
use Tests\Mocks\MockConnector;
use Tests\Mocks\MockConnectorWithFailureTracking;
use Tests\Mocks\MockResolver;
use Tests\Mocks\MockResolverWithTypes;

describe('HappyEyeBallsConnector', function () {
    test('connects directly when URI contains IPv4 address', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $connection = new MockConnection('tcp://127.0.0.1:8080');

        $mockConnector->setImmediateSuccess($connection);

        $promise = $connector->connect('tcp://127.0.0.1:8080');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toBe('tcp://127.0.0.1:8080')
            ->and($mockResolver->resolveCalled)->toBeFalse();
    });

    test('connects directly when URI contains IPv6 address', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $connection = new MockConnection('tcp://[::1]:8080');

        $mockConnector->setImmediateSuccess($connection);

        $promise = $connector->connect('tcp://[::1]:8080');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toBe('tcp://[::1]:8080')
            ->and($mockResolver->resolveCalled)->toBeFalse();
    });

    test('adds scheme when missing', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockConnector->setImmediateSuccess(new MockConnection());

        $connector->connect('127.0.0.1:8080')->wait();

        expect($mockConnector->connectCalled)->toBeTrue();
    });

    test('rejects with invalid URI', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $promise = $connector->connect('://invalid');

        expect(fn() => $promise->wait())
            ->toThrow(InvalidUriException::class, 'invalid (EINVAL)');
    });

    test('rejects with URI missing host', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $promise = $connector->connect('tcp://');

        expect(fn() => $promise->wait())
            ->toThrow(InvalidUriException::class, 'invalid (EINVAL)');
    });

    test('resolves IPv4 and IPv6 in parallel', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1:248:1893:25c8:1946']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $promise = $connector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockResolver->resolveAllCalledForType(RecordType::AAAA))->toBeTrue()
            ->and($mockResolver->resolveAllCalledForType(RecordType::A))->toBeTrue();
    });

    test('prefers IPv6 over IPv4 when both available', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $ipv6Connection = new MockConnection('tcp://[2606:2800:220:1:248:1893:25c8:1946]:80');

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1:248:1893:25c8:1946']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($ipv6Connection);

        $promise = $connector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($ipv6Connection)

            ->and($mockConnector->lastUri)->toContain('[2606:2800:220:1:248:1893:25c8:1946]');
    });

    test('falls back to IPv4 when IPv6 fails', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $ipv4Connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1:248:1893:25c8:1946']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1:248:1893:25c8:1946]:80',
            new ConnectionFailedException('IPv6 connection refused')
        );

        $mockConnector->setSuccessForUri('tcp://93.184.216.34:80', $ipv4Connection);
        $promise = $connector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($ipv4Connection)
            ->and($mockConnector->connectionAttempts)->toHaveCount(2);
    });

    test('delays IPv4 resolution by 50ms per RFC 8305', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $connection = new MockConnection();

        $startTime = microtime(true);

        $mockResolver->setSuccessAfterForType(RecordType::AAAA, 0.1, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $promise = $connector->connect('tcp://example.com:80');
        $result = $promise->wait();

        $elapsed = microtime(true) - $startTime;

        expect($result)->toBe($connection)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.05)
            ->and($elapsed)->toBeLessThan(0.15);
    });

    test('attempts connections with 250ms delay per RFC 8305', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2',
            '2606:2800:220:1::3'
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);

        $mockConnector->setFailureForAllUris(
            new ConnectionFailedException('Connection refused')
        );

        $startTime = microtime(true);
        $promise = $connector->connect('tcp://example.com:80');

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected - all failed
        }

        $elapsed = microtime(true) - $startTime;

        expect($mockConnector->connectionAttempts)->toHaveCount(3)
            ->and($elapsed)->toBeGreaterThanOrEqual(0.50)
            ->and($elapsed)->toBeLessThan(0.60);
    });

    test('interleaves IPv4 and IPv6 connection attempts', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2'
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, [
            '93.184.216.34',
            '93.184.216.35'
        ]);

        $mockConnector->setFailureForAllUris(
            new ConnectionFailedException('Connection refused')
        );

        $promise = $connector->connect('tcp://example.com:80');

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected - all connections failed
        }

        $attempts = $mockConnector->connectionAttempts;

        expect($attempts)->toHaveCount(4);
        $ipv6Count = 0;
        $ipv4Count = 0;

        foreach ($attempts as $attempt) {
            if (str_contains($attempt, '[')) {
                $ipv6Count++;
            } else {
                $ipv4Count++;
            }
        }

        expect($ipv6Count)->toBe(2)
            ->and($ipv4Count)->toBe(2);

        expect($attempts[0])->toContain('[');
        expect(str_contains($attempts[0], '['))->toBeTrue()
            ->and(str_contains($attempts[1], '['))->toBeFalse()
            ->and(str_contains($attempts[2], '['))->toBeTrue()
            ->and(str_contains($attempts[3], '['))->toBeFalse();
    });

    test('cancellation during DNS lookup', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setHangForType(RecordType::AAAA);
        $mockResolver->setHangForType(RecordType::A);

        $promise = $connector->connect('tcp://example.com:80');
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('cancellation during connection attempts', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setHang();

        $promise = $connector->connect('tcp://example.com:80');

        usleep(100000);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('both DNS lookups fail', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setFailureForType(RecordType::AAAA, new RecordNotFoundException('IPv6 lookup failed'));
        $mockResolver->setFailureForType(RecordType::A, new RecordNotFoundException('IPv4 lookup failed'));

        $promise = $connector->connect('tcp://example.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'failed during DNS lookup');
    });

    test('all connection attempts fail', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1::1]:80',
            new ConnectionFailedException('IPv6 refused')
        );
        $mockConnector->setFailureForUri(
            'tcp://93.184.216.34:80',
            new ConnectionFailedException('IPv4 refused')
        );

        $promise = $connector->connect('tcp://example.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'Connection to tcp://example.com:80 failed');
    });

    test('error message includes both IPv4 and IPv6 errors', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);

        $mockConnector->setFailureForUri(
            'tcp://[2606:2800:220:1::1]:80',
            new ConnectionFailedException('Connection to tcp://[2606:2800:220:1::1]:80 failed: Connection refused (IPv6)')
        );
        $mockConnector->setFailureForUri(
            'tcp://93.184.216.34:80',
            new ConnectionFailedException('Connection to tcp://93.184.216.34:80 failed: Connection refused (IPv4)')
        );

        $promise = $connector->connect('tcp://example.com:80');

        try {
            $promise->wait();
            throw new \Exception('Expected ConnectionFailedException');
        } catch (ConnectionFailedException $e) {
            expect($e->getMessage())
                ->toContain('IPv6')
                ->toContain('IPv4');
        }
    });

    test('preserves URI components in connection attempts', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, []);
        $mockResolver->setImmediateSuccessForType(RecordType::A, ['93.184.216.34']);
        $mockConnector->setImmediateSuccess($connection);

        $connector->connect('tcp://user:pass@example.com:8080/path?key=value#fragment')->wait();

        expect($mockConnector->lastUri)
            ->toContain('user:pass@')
            ->toContain(':8080')
            ->toContain('/path')
            ->toContain('key=value')
            ->toContain('hostname=example.com')
            ->toContain('#fragment');
    });

    test('wraps IPv6 addresses in brackets', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);
        $connection = new MockConnection();

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, ['2606:2800:220:1::1']);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);
        $mockConnector->setImmediateSuccess($connection);

        $connector->connect('tcp://example.com:80')->wait();

        expect($mockConnector->lastUri)->toContain('[2606:2800:220:1::1]');
    });

    test('multiple IPs shuffled for randomization', function () {
        $mockConnector = new MockConnectorWithFailureTracking();
        $mockResolver = new MockResolverWithTypes();
        $connector = new HappyEyeBallsConnector($mockConnector, $mockResolver,false);

        $mockResolver->setImmediateSuccessForType(RecordType::AAAA, [
            '2606:2800:220:1::1',
            '2606:2800:220:1::2',
            '2606:2800:220:1::3'
        ]);
        $mockResolver->setImmediateSuccessForType(RecordType::A, []);

        $mockConnector->setFailureForAllUris(new ConnectionFailedException('Failed'));

        $promise = $connector->connect('tcp://example.com:80');

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected
        }

        expect($mockConnector->connectionAttempts)->toHaveCount(3);
    });
});

describe('HappyEyeBallsConnector - Real Network Integration', function () {
    test('connects to cloudflare.com using Happy Eyeballs', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1', '1.0.0.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://cloudflare.com:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class)
            ->and($connection->isReadable())->toBeTrue()
            ->and($connection->isWritable())->toBeTrue();

        $connection->close();
    });

    test('connects to google.com using Happy Eyeballs', function () {
        $resolver = Dns::new()
            ->withNameservers(['8.8.8.8', '8.8.4.4'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://google.com:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class)
            ->and($connection->isReadable())->toBeTrue()
            ->and($connection->isWritable())->toBeTrue();

        $connection->close();
    });

    test('prefers IPv6 when available', function () {
        $resolver = Dns::new()
            ->withNameservers(['2606:4700:4700::1111', '2606:4700:4700::1001'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://ipv6.google.com:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class);

        $remoteAddr = $connection->getRemoteAddress();
        expect($remoteAddr)->toContain('[');

        $connection->close();
    });

    test('falls back to IPv4 when IPv6 unreachable', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://example.com:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class)
            ->and($connection->isReadable())->toBeTrue()
            ->and($connection->isWritable())->toBeTrue();

        $connection->close();
    });

    test('handles nonexistent domain gracefully', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://this-domain-definitely-does-not-exist-12345.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class);
    });

    test('connects directly to IPv4 address without DNS', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://1.1.1.1:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class);

        $connection->close();
    });

    test('connects directly to IPv6 address without DNS', function () {
        $resolver = Dns::new()
            ->withNameservers(['2606:4700:4700::1111'])
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://[2606:4700:4700::1111]:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class);

        $connection->close();
    });

    test('connection with caching enabled', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->withCache()
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise1 = $connector->connect('tcp://cloudflare.com:80');
        $connection1 = $promise1->wait();
        expect($connection1)->toBeInstanceOf(ConnectionInterface::class);
        $connection1->close();

        $promise2 = $connector->connect('tcp://cloudflare.com:80');
        $connection2 = $promise2->wait();
        expect($connection2)->toBeInstanceOf(ConnectionInterface::class);
        $connection2->close();
    });

    test('handles connection timeout gracefully', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://google.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class);
    });

    test('cancellation works during active connection', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([]);
        $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);

        $promise = $connector->connect('tcp://google.com:80');

        usleep(100000);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });
})->skip(function () {
    set_error_handler(fn() => true);
    $socket = @stream_socket_client('tcp://[2606:4700:4700::1111]:80', $errno, $errstr, 1);
    restore_error_handler();

    if ($socket === false) {
        return 'IPv6 is not available in this environment';
    }
    fclose($socket);
    return false;
})->skipOnCI();
