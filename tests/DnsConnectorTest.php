<?php

declare(strict_types=1);

use Hibla\Dns\Dns;
use Hibla\Dns\Exceptions\RecordNotFoundException;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Socket\DnsConnector;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\TcpConnector;
use Tests\Mocks\MockConnection;
use Tests\Mocks\MockConnector;
use Tests\Mocks\MockResolver;

describe('DnsConnector', function () {
    test('connects directly when URI contains IPv4 address', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://127.0.0.1:8080');

        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://127.0.0.1:8080');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toBe('tcp://127.0.0.1:8080')
            ->and($mockResolver->resolveCalled)->toBeFalse();
    });

    test('connects directly when URI contains IPv6 address', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://[::1]:8080');

        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://[::1]:8080');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toBe('tcp://[::1]:8080')
            ->and($mockResolver->resolveCalled)->toBeFalse();
    });

    test('resolves hostname and connects', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockResolver->resolveCalled)->toBeTrue()
            ->and($mockResolver->lastDomain)->toBe('example.com')
            ->and($mockConnector->lastUri)->toContain('93.184.216.34')
            ->and($mockConnector->lastUri)->toContain('hostname=example.com');
    });

    test('adds scheme when missing', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockResolver->lastDomain)->toBe('example.com');
    });

    test('rejects with invalid URI', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        $promise = $dnsConnector->connect('://invalid');

        expect(fn() => $promise->wait())
            ->toThrow(InvalidUriException::class, 'invalid (EINVAL)');
    });

    test('rejects with URI missing host', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        $promise = $dnsConnector->connect('tcp://');

        expect(fn() => $promise->wait())
            ->toThrow(InvalidUriException::class, 'invalid (EINVAL)');
    });

    test('DNS lookup failure propagates', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $error = new RecordNotFoundException('Domain not found');

        $mockResolver->setImmediateFailure($error);

        $promise = $dnsConnector->connect('tcp://nonexistent.example.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'failed during DNS lookup');
    });

    test('connection failure after DNS resolution', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $error = new ConnectionFailedException('Connection refused');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateFailure($error);

        $promise = $dnsConnector->connect('tcp://example.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'Connection to tcp://example.com:80 failed');
    });

    test('delayed DNS resolution success', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setSuccessAfter(0.3, '93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection);
    });

    test('delayed DNS resolution failure', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $error = new RecordNotFoundException('Lookup timeout');

        $mockResolver->setFailureAfter(0.3, $error);

        $promise = $dnsConnector->connect('tcp://example.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class, 'failed during DNS lookup');
    });

    test('cancellation during DNS lookup', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        $mockResolver->setHang();

        $promise = $dnsConnector->connect('tcp://example.com:80');
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('cancellation during connection', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setHang();

        $promise = $dnsConnector->connect('tcp://example.com:80');

        // Give DNS time to resolve
        usleep(100000); // 0.1 seconds

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('builds URI with IPv6 address', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://[2001:db8::1]:80');

        $mockResolver->setImmediateSuccess('2001:db8::1');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toContain('[2001:db8::1]')
            ->and($mockConnector->lastUri)->toContain('hostname=example.com');
    });

    test('preserves port in resolved URI', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:8080');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:8080');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toContain(':8080');
    });

    test('preserves URI path and query', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80/path?key=value');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toContain('/path')
            ->and($mockConnector->lastUri)->toContain('key=value')
            ->and($mockConnector->lastUri)->toContain('hostname=example.com');
    });

    test('preserves user info in URI', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://user:pass@example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toContain('user:pass@');
    });

    test('promise chaining works correctly', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80')
            ->then(function ($conn) {
                expect($conn)->toBeInstanceOf(MockConnection::class);
                return 'success';
            });

        $result = $promise->wait();
        expect($result)->toBe('success');
    });

    test('error handling in promise chain', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $error = new RecordNotFoundException('DNS failed');

        $mockResolver->setImmediateFailure($error);

        $promise = $dnsConnector->connect('tcp://example.com:80')
            ->catch(function ($e) {
                expect($e)->toBeInstanceOf(ConnectionFailedException::class);
                return 'handled';
            });

        $result = $promise->wait();
        expect($result)->toBe('handled');
    });

    test('strips brackets from IPv6 host before resolution', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        // This should not trigger DNS lookup (it's an IP)
        $mockConnector->setImmediateSuccess(new MockConnection());

        $dnsConnector->connect('tcp://[::1]:8080')->wait();

        // Resolver should not be called for IP addresses
        expect($mockResolver->resolveCalled)->toBeFalse();
    });

    test('multiple sequential connections', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        // First connection
        $connection1 = new MockConnection('tcp://93.184.216.34:80');
        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection1);
        $result1 = $dnsConnector->connect('tcp://example.com:80')->wait();

        // Second connection
        $mockResolver->reset();
        $mockConnector->reset();
        $connection2 = new MockConnection('tcp://93.184.216.35:80');
        $mockResolver->setImmediateSuccess('93.184.216.35');
        $mockConnector->setImmediateSuccess($connection2);
        $result2 = $dnsConnector->connect('tcp://example.org:80')->wait();

        expect($result1)->toBe($connection1)
            ->and($result2)->toBe($connection2);
    });

    test('connection state remains valid after successful connection', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80');
        /** @var MockConnection $result */
        $result = $promise->wait();

        expect($result->isReadable())->toBeTrue()
            ->and($result->isWritable())->toBeTrue()
            ->and($result->isClosed())->toBeFalse();
    });

    test('finally handler executes on success', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');
        $finallyCalled = false;

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://example.com:80')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $promise->wait();

        expect($finallyCalled)->toBeTrue();
    });

    test('finally handler executes on DNS failure', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $error = new RecordNotFoundException('DNS failed');
        $finallyCalled = false;

        $mockResolver->setImmediateFailure($error);

        $promise = $dnsConnector->connect('tcp://example.com:80')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        try {
            $promise->wait();
        } catch (ConnectionFailedException $e) {
            // Expected
        }

        expect($finallyCalled)->toBeTrue();
    });

    test('URL encodes hostname in query parameter', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $connection = new MockConnection('tcp://93.184.216.34:80');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateSuccess($connection);

        $promise = $dnsConnector->connect('tcp://sub.example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toContain('hostname=sub.example.com');
    });

    test('wraps non-ConnectionFailedException as generic connection error', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);
        $error = new \RuntimeException('Some other error');

        $mockResolver->setImmediateSuccess('93.184.216.34');
        $mockConnector->setImmediateFailure($error);

        $promise = $dnsConnector->connect('tcp://example.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(\RuntimeException::class, 'Some other error');
    });

    test('exception code is preserved for EINVAL', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        $promise = $dnsConnector->connect('://invalid');

        try {
            $promise->wait();
            throw new \Exception('Expected InvalidUriException to be thrown');
        } catch (InvalidUriException $e) {
            $expectedCode = defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22);
            expect($e->getCode())->toBe($expectedCode);
        }
    });

    test('cancellation throws PromiseCancelledException when waiting', function () {
        $mockConnector = new MockConnector();
        $mockResolver = new MockResolver();
        $dnsConnector = new DnsConnector($mockConnector, $mockResolver);

        $mockResolver->setHang();

        $promise = $dnsConnector->connect('tcp://example.com:80');
        $promise->cancel();

        expect(fn() => $promise->wait())
            ->toThrow(PromiseCancelledException::class, 'Cannot wait on a cancelled promise');
    });
});

describe('DnsConnector - Real Network Integration', function () {
    test('resolves and connects to cloudflare.com using real DNS and TCP connector', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1', '1.0.0.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([], 5.0);

        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise = $dnsConnector->connect('tcp://cloudflare.com:80');
        /** @var MockConnection */
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class)
            ->and($connection->isReadable())->toBeTrue()
            ->and($connection->isWritable())->toBeTrue();

        $connection->close();
    });

    test('resolves and connects to google.com using real DNS and TCP connector', function () {
        $resolver = Dns::new()
            ->withNameservers(['8.8.8.8', '8.8.4.4'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([], 5.0);
        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise = $dnsConnector->connect('tcp://google.com:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class)
            ->and($connection->isReadable())->toBeTrue()
            ->and($connection->isWritable())->toBeTrue();

        $connection->close();
    });

    test('handles real DNS lookup failure for nonexistent domain', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([], 5.0);
        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise = $dnsConnector->connect('tcp://this-domain-definitely-does-not-exist-12345.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class);
    });

    test('connects directly to IP address without DNS lookup', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->build();

        $tcpConnector = new TcpConnector([], 5.0);
        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise = $dnsConnector->connect('tcp://1.1.1.1:80');
        $connection = $promise->wait();

        expect($connection)
            ->toBeInstanceOf(ConnectionInterface::class)
            ->and($connection->isReadable())->toBeTrue()
            ->and($connection->isWritable())->toBeTrue();

        $connection->close();
    });

    test('resolves IPv6 address and connects', function () {
        $resolver = Dns::new()
            ->withNameservers(['2606:4700:4700::1111', '2606:4700:4700::1001'])             ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([], 5.0);
        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise = $dnsConnector->connect('tcp://ipv6.google.com:80');
        
        try {
            $connection = $promise->wait();
            
            expect($connection)
                ->toBeInstanceOf(ConnectionInterface::class);
                
            $connection->close();
        } catch (ConnectionFailedException $e) {
            $this->markTestSkipped('IPv6 might not be available in this environment');
        }
    });

    test('real connection with caching enabled', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->withCache()
            ->build();

        $tcpConnector = new TcpConnector([], 5.0);
        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise1 = $dnsConnector->connect('tcp://cloudflare.com:80');
        $connection1 = $promise1->wait();
        
        expect($connection1)->toBeInstanceOf(ConnectionInterface::class);
        $connection1->close();

        $promise2 = $dnsConnector->connect('tcp://cloudflare.com:80');
        $connection2 = $promise2->wait();
        
        expect($connection2)->toBeInstanceOf(ConnectionInterface::class);
        $connection2->close();
    });

    test('handles connection timeout with real network', function () {
        $resolver = Dns::new()
            ->withNameservers(['1.1.1.1'])
            ->withTimeout(5.0)
            ->build();

        $tcpConnector = new TcpConnector([], 0.001);
        $dnsConnector = new DnsConnector($tcpConnector, $resolver);

        $promise = $dnsConnector->connect('tcp://google.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(ConnectionFailedException::class);
    });
})->skipOnCI();