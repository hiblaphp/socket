<?php

declare(strict_types=1);

namespace Hibla\Socket\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Stream\Interfaces\DuplexStreamInterface;

/**
 * Represents an established, bidirectional socket connection.
 *
 * This interface extends DuplexStreamInterface, indicating that the connection
 * allows for both reading and writing of data. It serves as a contract for
 * various connection types (e.g., TCP/IP, TLS, Unix Domain Sockets) and provides
 * mechanisms to identify the local and remote endpoints of the connection.
 */
interface ConnectionInterface extends DuplexStreamInterface
{
    /**
     * Retrieves the address of the remote peer.
     *
     * This method returns the address of the client or server on the other end
     * of the connection. The format conforms to standard PHP stream socket identifiers:
     * - IPv4: "tcp://127.0.0.1:8080"
     * - IPv6: "tcp://[::1]:8080"
     * - Unix: "unix:///tmp/socket.sock"
     *
     * @return string|null The remote address string, or null if the connection is closed or the address cannot be determined.
     */
    public function getRemoteAddress(): ?string;

    /**
     * Retrieves the local address of the socket.
     *
     * This method returns the address of the interface on the local machine
     * that is handling this connection.
     * - IPv4: "tcp://127.0.0.1:54321"
     * - IPv6: "tcp://[::1]:54321"
     * - Unix: "unix:///tmp/socket.sock"
     *
     * @return string|null The local address string, or null if the connection is closed or the address cannot be determined.
     */
    public function getLocalAddress(): ?string;

    /**
     * Enables SSL/TLS encryption on the connection mid-flight.
     *
     * Useful for protocols that start in plaintext and upgrade to secure connections
     * dynamically (e.g., MySQL handshake, SMTP STARTTLS, PostgreSQL).
     *
     * @param array<string, mixed> $sslOptions Standard PHP SSL context options.
     * @param bool $isServer Whether this side of the connection is acting as the TLS server.
     * @return PromiseInterface<ConnectionInterface> Resolves when the TLS handshake is complete.
     */
    public function enableEncryption(array $sslOptions = [], bool $isServer = false): PromiseInterface;
}
