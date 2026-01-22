<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Exceptions\TimeoutException;
use Hibla\Socket\TimeoutConnector;
use Tests\Mocks\MockConnection;
use Tests\Mocks\MockConnector;

describe('TimeoutConnector', function () {
    test('immediate successful connection', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $expectedConnection = new MockConnection();

        $mockConnector->setImmediateSuccess($expectedConnection);

        $promise = $timeoutConnector->connect('tcp://localhost:8080');
        $connection = $promise->wait();

        expect($connection)->toBe($expectedConnection)
            ->and($mockConnector->connectCalled)->toBeTrue()
        ;
    });

    test('connection times out', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        expect(fn () => $promise->wait())
            ->toThrow(TimeoutException::class, 'Connection to tcp://example.com:80 timed out after 1.00 seconds')
        ;
    });

    test('timeout with custom duration', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 0.5);

        $mockConnector->setHang();

        $startTime = microtime(true);
        $promise = $timeoutConnector->connect('tcp://example.com:80');

        try {
            $promise->wait();

            throw new Exception('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $elapsed = microtime(true) - $startTime;

            expect($e->getMessage())->toContain('timed out after 0.50 seconds')
                ->and($elapsed)->toBeGreaterThanOrEqual(0.5)
                ->and($elapsed)->toBeLessThan(0.7) // Allow some overhead
            ;
        }
    });

    test('underlying connection failure before timeout', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $expectedError = new RuntimeException('Connection refused');

        $mockConnector->setFailureAfter(0.5, $expectedError);

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Connection refused')
        ;
    });

    test('immediate connection failure', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $expectedError = new RuntimeException('Invalid host');

        $mockConnector->setImmediateFailure($expectedError);

        $promise = $timeoutConnector->connect('tcp://invalid:80');

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Invalid host')
        ;
    });

    test('cancelling connection cleans up timer', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        expect(fn () => $promise->wait())
            ->toThrow(CancelledException::class)
        ;
    });

    test('multiple simultaneous connections', function () {
        $connector1 = new MockConnector();
        $connector2 = new MockConnector();

        $timeout1 = new TimeoutConnector($connector1, 1.0);
        $timeout2 = new TimeoutConnector($connector2, 1.0);

        $connection1 = new MockConnection('tcp://server1:80');
        $connection2 = new MockConnection('tcp://server2:80');

        $connector1->setSuccessAfter(0.3, $connection1);
        $connector2->setSuccessAfter(0.5, $connection2);

        $promise1 = $timeout1->connect('tcp://server1:80');
        $promise2 = $timeout2->connect('tcp://server2:80');

        $result1 = $promise1->wait();
        $result2 = $promise2->wait();

        expect($result1)->toBe($connection1)
            ->and($result2)->toBe($connection2)
        ;
    });

    test('connection succeeds just before timeout', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $connection = new MockConnection();

        $mockConnector->setSuccessAfter(0.95, $connection);

        $promise = $timeoutConnector->connect('tcp://example.com:80');
        $result = $promise->wait();

        expect($result)->toBe($connection);
    });

    test('timeout exception code', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        try {
            $promise->wait();

            throw new Exception('Expected TimeoutException to be thrown');
        } catch (TimeoutException $e) {
            $expectedCode = defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110;
            expect($e->getCode())->toBe($expectedCode);
        }
    });

    test('connection with different URIs', function () {
        $uris = [
            'tcp://localhost:8080',
            'tcp://192.168.1.1:3000',
            'tcp://example.com:443',
        ];

        foreach ($uris as $uri) {
            $mockConnector = new MockConnector();
            $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
            $connection = new MockConnection($uri);

            $mockConnector->setImmediateSuccess($connection);

            $promise = $timeoutConnector->connect($uri);
            $result = $promise->wait();

            expect($result)->toBe($connection)
                ->and($mockConnector->lastUri)->toBe($uri)
            ;
        }
    });

    test('promise chaining', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $connection = new MockConnection();

        $mockConnector->setSuccessAfter(0.3, $connection);

        $promise = $timeoutConnector->connect('tcp://example.com:80')
            ->then(function ($conn) {
                expect($conn)->toBeInstanceOf(MockConnection::class);

                return 'success';
            })
        ;

        $result = $promise->wait();
        expect($result)->toBe('success');
    });

    test('error handling in promise chain', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80')
            ->catch(function ($error) {
                expect($error)->toBeInstanceOf(TimeoutException::class);

                return 'handled';
            })
        ;

        $result = $promise->wait();
        expect($result)->toBe('handled');
    });

    test('timeout cancels pending connection', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        expect(fn () => $promise->wait())
            ->toThrow(TimeoutException::class)
        ;

        expect($mockConnector->connectCalled)->toBeTrue();
    });

    test('connection preserves remote and local addresses', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $remoteAddr = 'tcp://example.com:80';
        $localAddr = 'tcp://127.0.0.1:54321';

        $connection = new MockConnection($remoteAddr, $localAddr);
        $mockConnector->setImmediateSuccess($connection);

        $promise = $timeoutConnector->connect($remoteAddr);
        $result = $promise->wait();

        expect($result->getRemoteAddress())->toBe($remoteAddr)
            ->and($result->getLocalAddress())->toBe($localAddr)
        ;
    });

    test('very short timeout', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 0.1);

        $mockConnector->setSuccessAfter(0.5, new MockConnection());

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        expect(fn () => $promise->wait())
            ->toThrow(TimeoutException::class, 'timed out after 0.10 seconds')
        ;
    });

    test('zero timeout immediately times out', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 0.0);

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        expect(fn () => $promise->wait())
            ->toThrow(TimeoutException::class)
        ;
    });

    test('connection state remains valid after successful connection', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $connection = new MockConnection();

        $mockConnector->setImmediateSuccess($connection);

        $promise = $timeoutConnector->connect('tcp://example.com:80');
        /** @var MockConnection $result */
        $result = $promise->wait();

        expect($result->isReadable())->toBeTrue()
            ->and($result->isWritable())->toBeTrue()
            ->and($result->isClosed())->toBeFalse()
        ;
    });

    test('finally handler executes on timeout', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $finallyCalled = false;

        $mockConnector->setHang();

        $promise = $timeoutConnector->connect('tcp://example.com:80')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            })
        ;

        try {
            $promise->wait();
        } catch (TimeoutException $e) {
            // Expected
        }

        expect($finallyCalled)->toBeTrue();
    });

    test('finally handler executes on success', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $connection = new MockConnection();
        $finallyCalled = false;

        $mockConnector->setImmediateSuccess($connection);

        $promise = $timeoutConnector->connect('tcp://example.com:80')
            ->finally(function () use (&$finallyCalled) {
                $finallyCalled = true;
            })
        ;

        $promise->wait();

        expect($finallyCalled)->toBeTrue();
    });

    test('multiple sequential connections with same connector', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);

        // First connection
        $connection1 = new MockConnection('tcp://server1:80');
        $mockConnector->setImmediateSuccess($connection1);
        $result1 = $timeoutConnector->connect('tcp://server1:80')->wait();

        // Reset and second connection
        $mockConnector->reset();
        $connection2 = new MockConnection('tcp://server2:80');
        $mockConnector->setImmediateSuccess($connection2);
        $result2 = $timeoutConnector->connect('tcp://server2:80')->wait();

        expect($result1)->toBe($connection1)
            ->and($result2)->toBe($connection2)
        ;
    });

    test('timeout with delayed failure', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 2.0);
        $error = new RuntimeException('Delayed error');

        $mockConnector->setFailureAfter(1.5, $error);

        $promise = $timeoutConnector->connect('tcp://example.com:80');

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Delayed error')
        ;
    });

    test('connection can be written to after successful timeout connection', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $connection = new MockConnection();

        $mockConnector->setSuccessAfter(0.3, $connection);

        $promise = $timeoutConnector->connect('tcp://example.com:80');
        $result = $promise->wait();

        $writeSuccess = $result->write('test data');

        expect($writeSuccess)->toBeTrue()
            ->and($result->isWritable())->toBeTrue()
        ;
    });

    test('connection can be closed after successful timeout connection', function () {
        $mockConnector = new MockConnector();
        $timeoutConnector = new TimeoutConnector($mockConnector, 1.0);
        $connection = new MockConnection();

        $mockConnector->setImmediateSuccess($connection);

        $promise = $timeoutConnector->connect('tcp://example.com:80');
        /** @var MockConnection $result */
        $result = $promise->wait();

        $result->close();

        expect($result->isClosed())->toBeTrue()
            ->and($result->isReadable())->toBeFalse()
            ->and($result->isWritable())->toBeFalse();
    });
});
