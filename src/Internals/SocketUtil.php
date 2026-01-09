<?php

declare(strict_types=1);

namespace Hibla\Socket\Internals;

use Hibla\Socket\Exceptions\AcceptFailedException;

/**
 * @internal
 * 
 * A collection of internal, low-level socket utility functions.
 * This class is not part of the public API and may change at any time.
 */
final class SocketUtil
{
    private static bool $extSocketsAvailable;

    private function __construct() {}

    /**
     * Accepts a new connection from a given server socket.
     *
     * @param resource $socket The server socket to accept from.
     * @return resource The new client socket resource.
     * @throws AcceptFailedException If accepting the connection fails.
     */
    public static function accept(mixed $socket): mixed
    {
        set_error_handler(static fn() => true);

        try {
            $newSocket = stream_socket_accept($socket, 0, $peerName);
        } catch (\Throwable $e) {
            throw new AcceptFailedException(
                'Unable to accept new connection: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        } finally {
            restore_error_handler();
        }

        if ($newSocket === false) {
            $error = self::getLastSocketError();
            throw new AcceptFailedException(
                'Unable to accept new connection: ' . $error['message'],
                $error['code']
            );
        }

        return $newSocket;
    }

    /**
     * Gets the last socket error details (code and message).
     *
     * Prioritizes ext-sockets for accuracy, with a fallback to basic error_get_last().
     *
     * @return array{code: int, message: string}
     */
    public static function getLastSocketError(): array
    {
        self::$extSocketsAvailable ??= function_exists('socket_last_error');

        if (self::$extSocketsAvailable) {
            $errno = socket_last_error();
            $errstr = socket_strerror($errno);
            socket_clear_error();
            return ['code' => $errno, 'message' => $errstr];
        }

        $error = error_get_last();
        if ($error !== null) {
            return ['code' => $error['type'], 'message' => $error['message']];
        }

        return ['code' => 0, 'message' => 'Unknown error'];
    }
}
