<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\Stream\DuplexResourceStream;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Util;
use Hibla\Socket\Interfaces\ConnectionInterface;

/**
 * @internal
 */
final class Connection extends EventEmitter implements ConnectionInterface
{
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

    public function handleClose(): void
    {
        if (!\is_resource($this->resource)) {
            return;
        }
        @\stream_socket_shutdown($this->resource, STREAM_SHUT_RDWR);
    }

    public function getRemoteAddress(): ?string
    {
        if (!\is_resource($this->resource)) {
            return null;
        }

        $result = \stream_socket_get_name($this->resource, true);
        $parsed = $this->parseAddress($result);

        return $parsed;
    }

    public function getLocalAddress(): ?string
    {
        if (!\is_resource($this->resource)) {
            return null;
        }
        return $this->parseAddress(\stream_socket_get_name($this->resource, false));
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
        if ($pos !== false && \strpos($address, ':') < $pos && !str_starts_with($address, '[')) {
            $address = '[' . \substr($address, 0, $pos) . ']:' . \substr($address, $pos + 1);
        }

        return 'tcp://' . $address;
    }
}
