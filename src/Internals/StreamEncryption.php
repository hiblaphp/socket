<?php

declare(strict_types=1);

namespace Hibla\Socket\Internals;

use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
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
        // 1. Pause the connection to prevent application data from interfering 
        // with the handshake or being read as garbage during the handshake.
        $connection->pause();

        /** @var resource $socket */
        $socket = $connection->getResource();

        $context = stream_context_get_options($socket);
        $method = $context['ssl']['crypto_method'] ?? $this->method;

        /** @var Promise<Connection> $promise */
        $promise = new Promise();
        $watcherId = null;

        $cleanup = function () use (&$watcherId): void {
            if ($watcherId !== null) {
                Loop::removeStreamWatcher($watcherId);
                $watcherId = null;
            }
        };

        $handshake = function () use ($socket, $enable, $method, $promise, $connection, $cleanup): void {
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
                $cleanup();
                $connection->encryptionEnabled = $enable;
                
                // Resume the connection so normal data flow can continue
                $connection->resume();
                $promise->resolve($connection);
                
            } elseif ($result === false) {
                // Failure: Handshake failed permanently
                $cleanup();
                $connection->resume(); // Resume to allow close events to process if needed
                
                if (feof($socket) || $error === null) {
                    $promise->reject(new EncryptionFailedException(
                        'Connection lost during TLS handshake',
                        \defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104
                    ));
                } else {
                    $promise->reject(new EncryptionFailedException(
                        'TLS handshake failed: ' . $error
                    ));
                }
            } else {
                // Result === 0: Needs more I/O. 
                // The watcher will trigger this function again when data is available.
            }
        };

        $watcherId = Loop::addStreamWatcher(
            stream: $socket,
            callback: $handshake,
            type: StreamWatcher::TYPE_READ
        );

        // If we are the client, start the handshake immediately
        // rather than waiting for the server to send data first.
        if (!$this->isServer) {
            $handshake();
        }

        $promise->onCancel(function () use ($cleanup): void {
            $cleanup();
        });

        return $promise;
    }
}