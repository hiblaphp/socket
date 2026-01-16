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

final class SecureConnector implements ConnectorInterface
{
    private readonly StreamEncryption $streamEncryption;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly array $context = []
    ) {
        $this->streamEncryption = new StreamEncryption(isServer: false);
    }

    public function connect(string $uri): PromiseInterface
    {
        if (!str_contains($uri, '://')) {
            $uri = 'tls://' . $uri;
        }

        $parts = parse_url($uri);
        if (!$parts || !isset($parts['scheme']) || $parts['scheme'] !== 'tls') {
            throw new InvalidUriException(
                \sprintf('Given URI "%s" is invalid (EINVAL)', $uri)
            );
        }

        $plainUri = str_replace('tls://', '', $uri);

        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();

        /** @var PromiseInterface|null $currentPendingOperation */
        $currentPendingOperation = null;

        $currentPendingOperation = $this->connector->connect($plainUri);

        $currentPendingOperation->then(
            onFulfilled: function (ConnectionInterface $connection) use ($promise, $uri, &$currentPendingOperation) {
                if ($promise->isCancelled()) {
                    $connection->close();
                    return;
                }

                if (!$connection instanceof Connection) {
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
                    onRejected: function (Throwable $e) use ($promise, $connection, $uri) {
                        $connection->close();

                        if ($e instanceof EncryptionFailedException) {
                            $promise->reject($e);
                        } else {
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
            if ($currentPendingOperation instanceof PromiseInterface) {
                $currentPendingOperation->cancelChain();
            }
        });

        return $promise;
    }
}
