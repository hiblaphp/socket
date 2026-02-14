<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Hibla\Socket\Internals\StreamEncryption;
use Throwable;
use UnexpectedValueException;

/**
 * A connector that establishes secure, encrypted (TLS/SSL) connections.
 *
 * This class acts as a decorator for another {@see ConnectorInterface} (typically a TcpConnector).
 * It orchestrates the connection process by:
 * 1. Delegating the initial transport establishment to the underlying connector (e.g., TCP).
 * 2. Performing an asynchronous TLS handshake ("upgrading" the stream) immediately after connection.
 *
 * It supports standard PHP SSL context options (passed via the constructor) to configure
 * security parameters such as certificate authorities, peer verification, and specific
 * TLS versions.
 *
 * @see https://www.php.net/manual/en/context.ssl.php For a list of available SSL context options.
 */
final class SecureConnector implements ConnectorInterface
{
    private readonly StreamEncryption $streamEncryption;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly array $context = []
    ) {
        $this->streamEncryption = new StreamEncryption(isServer: false);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(string $uri): PromiseInterface
    {
        if (! str_contains($uri, '://')) {
            $uri = 'tls://' . $uri;
        }

        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme']) || $parts['scheme'] !== 'tls') {
            throw new InvalidUriException(
                \sprintf('Given URI "%s" is invalid (EINVAL)', $uri)
            );
        }

        $plainUri = str_replace('tls://', '', $uri);

        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();

        /** @var PromiseInterface<ConnectionInterface>|PromiseInterface<Connection>|null $currentPendingOperation */
        $currentPendingOperation = null;

        $currentPendingOperation = $this->connector->connect($plainUri);

        $currentPendingOperation->then(
            onFulfilled: function (ConnectionInterface $connection) use ($promise, $uri, &$currentPendingOperation) {
                if ($promise->isCancelled()) {
                    $connection->close();

                    return;
                }

                if (! $connection instanceof Connection) {
                    $connection->close();
                    $promise->reject(new UnexpectedValueException('Base connector does not use internal Connection class'));

                    return;
                }

                $socket = $connection->getResource();
                foreach ($this->context as $name => $value) {
                    stream_context_set_option($socket, 'ssl', $name, $value);
                }

                $currentPendingOperation = $this->streamEncryption->enable($connection);

                $currentPendingOperation->then(
                    onFulfilled: function (Connection $secureConnection) use ($promise) {
                        $promise->resolve($secureConnection);
                    },
                    onRejected: function (mixed $e) use ($promise, $connection, $uri) {
                        $connection->close();

                        if ($e instanceof EncryptionFailedException) {
                            $promise->reject($e);
                        } elseif ($e instanceof Throwable) {
                            $promise->reject(new EncryptionFailedException(
                                \sprintf('Connection to %s failed during TLS handshake: %s', $uri, $e->getMessage()),
                                (int) $e->getCode(),
                                $e
                            ));
                        }
                    }
                );
            },
            onRejected: function (Throwable $e) use ($promise, $uri) {
                $promise->reject(new ConnectionFailedException(
                    \sprintf('Connection to %s failed: %s', $uri, $e->getMessage()),
                    (int) $e->getCode(),
                    $e
                ));
            }
        );

        $promise->onCancel(function () use (&$currentPendingOperation) {
            $currentPendingOperation->cancelChain();
        });

        return $promise;
    }
}
