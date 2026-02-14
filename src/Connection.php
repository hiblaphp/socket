<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Internals\StreamEncryption;
use Hibla\Stream\DuplexResourceStream;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;

/**
 * Concrete implementation of a streaming socket connection.
 *
 * This class wraps a raw PHP stream resource (e.g., a TCP socket, Unix domain socket)
 * and provides an event-driven interface for reading and writing data. It manages
 * the non-blocking I/O loop interactions, write buffering, and flow control.
 *
 * @internal This class is intended for internal use by Servers and Connectors to wrap resources.
 *           Public code should type-hint against {@see ConnectionInterface}.
 */
final class Connection extends EventEmitter implements ConnectionInterface
{
    /**
     * @internal
     */
    public bool $encryptionEnabled = false;

    private readonly DuplexResourceStream $stream;

    /**
     * @param resource $resource
     */
    public function __construct(
        private readonly mixed $resource,
        private readonly bool $isUnix = false
    ) {
        $this->stream = new DuplexResourceStream($this->resource);

        Util::forwardEvents($this->stream, $this, ['data', 'end', 'error', 'close', 'pipe', 'drain', 'finish']);
        $this->stream->on('close', $this->close(...));

        $this->stream->resume();
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function pause(): void
    {
        $this->stream->pause();
    }

    public function resume(): void
    {
        $this->stream->resume();
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        return $this->stream->pipe($destination, $options);
    }

    public function write(string $data): bool
    {
        return $this->stream->write($data);
    }

    public function end(?string $data = null): void
    {
        $this->stream->end($data);
    }

    public function close(): void
    {
        $this->stream->close();
        $this->handleClose();
        $this->removeAllListeners();
    }

    /**
     * Exposes the underlying stream resource.
     *
     * @internal Used by StreamEncryption
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Enables SSL/TLS encryption on the connection.
     *
     * @internal This is rarely used in practice, this is useful for client that need mid flight upgrade from tcp to tls like mysql client
     * @param array<string, mixed> $sslOptions
     * @param bool $isServer
     * @return PromiseInterface<Connection>
     */
    public function enableEncryption(array $sslOptions = [], bool $isServer = false): PromiseInterface
    {
        foreach ($sslOptions as $option => $value) {
            stream_context_set_option($this->resource, 'ssl', $option, $value);
        }

        $encryption = new StreamEncryption(isServer: $isServer);

        return $encryption->enable($this);
    }

    public function getRemoteAddress(): ?string
    {
        if (! \is_resource($this->resource)) {
            return null;
        }

        $result = \stream_socket_get_name($this->resource, true);
        $parsed = $this->parseAddress($result);

        return $parsed;
    }

    public function getLocalAddress(): ?string
    {
        if (! \is_resource($this->resource)) {
            return null;
        }

        return $this->parseAddress(\stream_socket_get_name($this->resource, false));
    }

    private function handleClose(): void
    {
        if (! \is_resource($this->resource)) {
            return;
        }
        @\stream_socket_shutdown($this->resource, STREAM_SHUT_RDWR);
    }

    private function parseAddress(string|false $address): ?string
    {
        if ($address === false) {
            return $this->isUnix ? 'unix://' : null;
        }

        if ($this->isUnix) {
            return $address === '' ? 'unix://' : 'unix://' . $address;
        }

        $pos = \strrpos($address, ':');
        if ($pos !== false && \strpos($address, ':') < $pos && ! str_starts_with($address, '[')) {
            $address = '[' . \substr($address, 0, $pos) . ']:' . \substr($address, $pos + 1);
        }

        $scheme = $this->encryptionEnabled ? 'tls' : 'tcp';

        return $scheme . '://' . $address;
    }
}
