<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\TimeoutException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Throwable;

/**
 * A connector decorator that enforces a time limit on the connection establishment process.
 *
 * This class wraps an underlying {@see ConnectorInterface} and ensures that the entire
 * connection process—including DNS resolution (if applicable), TCP handshake, and
 * any subsequent protocol negotiation (like TLS handshake)—is completed within a
 * specified timeout duration.
 *
 * If the connection promise does not resolve (or reject) before the timeout expires,
 * the promise is rejected, indicating a timeout.
 */
final class TimeoutConnector implements ConnectorInterface
{
    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly float $timeout
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function connect(string $uri): PromiseInterface
    {
        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();

        $pendingConnection = $this->connector->connect($uri);
        
        /** @var string|null $timerId */
        $timerId = null;

        $cleanup = function () use (&$timerId): void {
            if ($timerId !== null) {
                Loop::cancelTimer($timerId);
                $timerId = null;
            }
        };

        $timerId = Loop::addTimer($this->timeout, function () use ($promise, $pendingConnection, $cleanup, $uri) {
            $cleanup();
            $pendingConnection->cancel();

            $promise->reject(new TimeoutException(
                \sprintf('Connection to %s timed out after %.2f seconds', $uri, $this->timeout),
                \defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110
            ));
        });

        $pendingConnection->then(
            function ($connection) use ($promise, $cleanup) {
                $cleanup();
                $promise->resolve($connection);
            },
            function (mixed $e) use ($promise, $cleanup) {
                $cleanup();
                if ($e instanceof Throwable) {
                    $promise->reject($e);
                }
            }
        );

        $promise->onCancel(function () use ($pendingConnection, $cleanup) {
            $cleanup();
            $pendingConnection->cancel();
        });

        return $promise;
    }
}