<?php

declare(strict_types=1);

namespace Hibla\Socket\Internals;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Connection;
use Hibla\Socket\Exceptions\EncryptionFailedException;

/**
 * @internal
 * 
 * Handles the asynchronous TLS/SSL handshake process.
 */
final class StreamEncryption
{
    private readonly int $method;

    public function __construct(
        private readonly bool $isServer = true
    ) {
        $this->method = $isServer
            ? STREAM_CRYPTO_METHOD_TLS_SERVER
            : STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }

    /**
     * Enable encryption on the given connection.
     *
     * @param Connection $connection
     * @return PromiseInterface<Connection>
     */
    public function enable(Connection $connection): PromiseInterface
    {
        return $this->toggle($connection, true);
    }

    /**
     * Disable encryption on the given connection.
     *
     * @param Connection $connection
     * @return PromiseInterface<Connection>
     */
    public function disable(Connection $connection): PromiseInterface
    {
        return $this->toggle($connection, false);
    }

    private function toggle(Connection $connection, bool $enable): PromiseInterface
    {
        // Pause actual stream instance to continue operation on raw stream socket
        $connection->pause();

        /** @var resource $socket */
        $socket = $connection->getResource();

        $context = stream_context_get_options($socket);
        $method = $context['ssl']['crypto_method'] ?? $this->method;

        /** @var Promise<Connection> $promise */
        $promise = new Promise();
        $watcherId = null;

        $toggleCrypto = function () use ($socket, $promise, $enable, $method, &$watcherId, $connection): void {
            $error = null;
            set_error_handler(function (int $_, string $msg) use (&$error) {
                $error = str_replace(["\r", "\n"], ' ', subject: $msg);
                if (($pos = strpos($error, '): ')) !== false) {
                    $error = substr($error, $pos + 3);
                }
            });

            $result = stream_socket_enable_crypto($socket, $enable, $method);
            
            restore_error_handler();

            if ($result === true) {
                // Success: Encryption enabled/disabled
                if ($watcherId !== null) {
                    Loop::removeReadWatcher($watcherId);
                    $watcherId = null;
                }
                
                $connection->encryptionEnabled = $enable;
                $connection->resume();
                $promise->resolve($connection);
                
            } elseif ($result === false) {
                // Failure: Handshake failed permanently
                if ($watcherId !== null) {
                    Loop::removeReadWatcher($watcherId);
                    $watcherId = null;
                }
                
                $connection->resume();
                
                if (feof($socket) || $error === null) {
                    // EOF or failed without error => connection closed during handshake
                    $promise->reject(new EncryptionFailedException(
                        'Connection lost during TLS handshake',
                        \defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104
                    ));
                } else {
                    // Handshake failed with error message
                    $promise->reject(new EncryptionFailedException(
                        'TLS handshake failed: ' . $error
                    ));
                }
            } else {
                // Result === 0: Needs more I/O, will retry when readable
            }
        };

        $watcherId = Loop::addReadWatcher(
            stream: $socket,
            callback: $toggleCrypto,
        );

        // If we are the client, start the handshake immediately
        if (!$this->isServer) {
            $toggleCrypto();
        }

        $promise->onCancel(function () use (&$watcherId): void {
            if ($watcherId !== null) {
                Loop::removeReadWatcher($watcherId);
                $watcherId = null;
            }
        });

        return $promise;
    }
}