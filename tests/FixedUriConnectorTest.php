<?php

declare(strict_types=1);

use Hibla\Socket\FixedUriConnector;
use Tests\Mocks\MockConnection;
use Tests\Mocks\MockConnector;

describe('FixedUriConnector', function () {
    test('connects to fixed URI ignoring provided URI', function () {
        $fixedUri = 'tcp://fixed.example.com:443';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://other.example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toBe($fixedUri)
            ->and($mockConnector->connectCalled)->toBeTrue();
    });

    test('always uses fixed URI regardless of input', function () {
        $fixedUri = 'tcp://localhost:8080';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);

        $testUris = [
            'tcp://example.com:80',
            'tcp://192.168.1.1:3000',
            'tcp://different.host:9999',
        ];

        foreach ($testUris as $testUri) {
            $mockConnector->reset();
            $connection = new MockConnection($fixedUri);
            $mockConnector->setImmediateSuccess($connection);

            $fixedUriConnector->connect($testUri)->wait();

            expect($mockConnector->lastUri)->toBe($fixedUri);
        }
    });

    test('successful connection', function () {
        $fixedUri = 'tcp://localhost:8080';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($result->getRemoteAddress())->toBe($fixedUri);
    });

    test('connection failure propagates', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $error = new RuntimeException('Connection failed');

        $mockConnector->setImmediateFailure($error);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(RuntimeException::class, 'Connection failed');
    });

    test('delayed connection success', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setSuccessAfter(0.3, $connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection)
            ->and($mockConnector->lastUri)->toBe($fixedUri);
    });

    test('delayed connection failure', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $error = new RuntimeException('Connection timeout');

        $mockConnector->setFailureAfter(0.3, $error);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');

        expect(fn() => $promise->wait())
            ->toThrow(RuntimeException::class, 'Connection timeout');
    });

    test('promise can be cancelled', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);

        $mockConnector->setHang();

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('promise chaining works correctly', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80')
            ->then(function ($conn) {
                expect($conn)->toBeInstanceOf(MockConnection::class);
                return 'chained';
            });

        $result = $promise->wait();
        expect($result)->toBe('chained');
    });

    test('error handling in promise chain', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $error = new RuntimeException('Failed');

        $mockConnector->setImmediateFailure($error);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80')
            ->catch(function ($e) {
                expect($e)->toBeInstanceOf(RuntimeException::class);
                return 'handled';
            });

        $result = $promise->wait();
        expect($result)->toBe('handled');
    });

    test('multiple sequential connections', function () {
        $fixedUri = 'tcp://fixed.example.com:443';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);

        // First connection
        $connection1 = new MockConnection($fixedUri);
        $mockConnector->setImmediateSuccess($connection1);
        $result1 = $fixedUriConnector->connect('tcp://first.com:80')->wait();

        // Second connection
        $mockConnector->reset();
        $connection2 = new MockConnection($fixedUri);
        $mockConnector->setImmediateSuccess($connection2);
        $result2 = $fixedUriConnector->connect('tcp://second.com:80')->wait();

        expect($result1)->toBe($connection1)
            ->and($result2)->toBe($connection2)
            ->and($mockConnector->lastUri)->toBe($fixedUri);
    });

    test('connection state remains valid after successful connection', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        /** @var MockConnection $result */
        $result = $promise->wait();

        expect($result->isReadable())->toBeTrue()
            ->and($result->isWritable())->toBeTrue()
            ->and($result->isClosed())->toBeFalse();
    });

    test('connection can be written to after successful connection', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        $result = $promise->wait();

        $writeSuccess = $result->write('test data');

        expect($writeSuccess)->toBeTrue()
            ->and($result->isWritable())->toBeTrue();
    });

    test('connection can be closed after successful connection', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        /** @var MockConnection $result */
        $result = $promise->wait();

        $result->close();

        expect($result->isClosed())->toBeTrue()
            ->and($result->isReadable())->toBeFalse()
            ->and($result->isWritable())->toBeFalse();
    });

    test('finally handler executes on success', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);
        $finallyCalled = false;

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        $promise->wait();

        expect($finallyCalled)->toBeTrue();
    });

    test('finally handler executes on failure', function () {
        $fixedUri = 'tcp://example.com:80';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $error = new RuntimeException('Failed');
        $finallyCalled = false;

        $mockConnector->setImmediateFailure($error);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            });

        try {
            $promise->wait();
        } catch (RuntimeException $e) {
            // Expected
        }

        expect($finallyCalled)->toBeTrue();
    });

    test('connection preserves remote and local addresses', function () {
        $fixedUri = 'tcp://fixed.example.com:443';
        $localAddr = 'tcp://127.0.0.1:54321';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri, $localAddr);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('tcp://ignored.com:80');
        $result = $promise->wait();

        expect($result->getRemoteAddress())->toBe($fixedUri)
            ->and($result->getLocalAddress())->toBe($localAddr);
    });

    test('empty string as target URI still uses fixed URI', function () {
        $fixedUri = 'tcp://fixed.example.com:443';
        $mockConnector = new MockConnector();
        $fixedUriConnector = new FixedUriConnector($fixedUri, $mockConnector);
        $connection = new MockConnection($fixedUri);

        $mockConnector->setImmediateSuccess($connection);

        $promise = $fixedUriConnector->connect('');
        $result = $promise->wait();

        expect($mockConnector->lastUri)->toBe($fixedUri)
            ->and($result)->toBe($connection);
    });

    test('wrapping multiple connectors', function () {
        $fixedUri1 = 'tcp://first.example.com:443';
        $fixedUri2 = 'tcp://second.example.com:443';
        
        $mockConnector1 = new MockConnector();
        $mockConnector2 = new MockConnector();
        
        $fixedConnector1 = new FixedUriConnector($fixedUri1, $mockConnector1);
        $fixedConnector2 = new FixedUriConnector($fixedUri2, $mockConnector2);

        $connection1 = new MockConnection($fixedUri1);
        $connection2 = new MockConnection($fixedUri2);

        $mockConnector1->setImmediateSuccess($connection1);
        $mockConnector2->setImmediateSuccess($connection2);

        $result1 = $fixedConnector1->connect('tcp://ignored.com:80')->wait();
        $result2 = $fixedConnector2->connect('tcp://ignored.com:80')->wait();

        expect($mockConnector1->lastUri)->toBe($fixedUri1)
            ->and($mockConnector2->lastUri)->toBe($fixedUri2)
            ->and($result1)->toBe($connection1)
            ->and($result2)->toBe($connection2);
    });
});