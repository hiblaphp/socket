<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\Socket\Exceptions\AcceptFailedException;
use Hibla\Socket\Exceptions\BindFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ServerInterface;
use Hibla\Socket\Internals\SocketUtil;

final class FdServer extends EventEmitter implements ServerInterface
{
    private readonly mixed $master;
    private readonly bool $isUnix;

    private bool $listening = false;
    private ?string $watcherId = null;

    public function __construct(int|string $fd)
    {
        if (\is_string($fd) && preg_match('#^php://fd/(\d+)$#', $fd, $matches)) {
            $fd = (int) $matches[1];
        }

        if (!\is_int($fd) || $fd < 0) {
            throw new InvalidUriException(
                'Invalid file descriptor (FD) number given (EINVAL)'
            );
        }

        set_error_handler(function (int $code, string $message) use (&$errno, &$errstr) {
            $errno = $code;
            $errstr = $message;
        });

        $resource = fopen('php://fd/' . $fd, 'r+');

        restore_error_handler();

        if ($resource === false) {
            throw new BindFailedException(
                \sprintf('Failed to open file descriptor %d: %s', $fd, $errstr),
                $errno ?? 0
            );
        }

        $this->master = $resource;

        $this->validateSocketResource($fd);

        $address = stream_socket_get_name($this->master, false);
        $this->isUnix = ($address !== false && !str_contains($address, ':'));

        stream_set_blocking($this->master, false);
        $this->resume();
    }

    public function getAddress(): ?string
    {
        if (!\is_resource($this->master)) {
            return null;
        }

        $address = stream_socket_get_name($this->master, false);
        if ($address === false) return null;

        if ($this->isUnix) {
            return 'unix://' . $address;
        }

        $pos = strrpos($address, ':');
        if ($pos !== false && strpos($address, ':') < $pos && !str_starts_with($address, '[')) {
            $address = '[' . substr($address, 0, $pos) . ']:' . substr($address, $pos + 1);
        }

        return 'tcp://' . $address;
    }

    public function pause(): void
    {
        if (!$this->listening || $this->watcherId === null) {
            return;
        }

        Loop::removeReadWatcher($this->watcherId);
        $this->watcherId = null;
        $this->listening = false;
    }

    public function resume(): void
    {
        if ($this->listening || !\is_resource($this->master)) {
            return;
        }

        $this->watcherId = Loop::addReadWatcher(
            stream: $this->master,
            callback: $this->acceptConnection(...),
        );
        $this->listening = true;
    }

    public function close(): void
    {
        if (!\is_resource($this->master)) {
            return;
        }
        $this->pause();
        fclose($this->master);
        $this->removeAllListeners();
    }

    private function validateSocketResource(int $fd): void
    {
        $meta = stream_get_meta_data($this->master);
        if (!isset($meta['stream_type']) || !\in_array($meta['stream_type'], ['tcp_socket', 'unix_socket'], true)) {
            $this->close();
            throw new BindFailedException(
                \sprintf('File descriptor %d is not a valid TCP or Unix socket (ENOTSOCK)', $fd),
                \defined('SOCKET_ENOTSOCK') ? SOCKET_ENOTSOCK : 88
            );
        }

        if (stream_socket_get_name($this->master, remote: true) !== false) {
            $this->close();
            throw new BindFailedException(
                \sprintf('File descriptor %d is already connected and not a listening socket (EISCONN)', $fd),
                \defined('SOCKET_EISCONN') ? SOCKET_EISCONN : 106
            );
        }
    }

    private function acceptConnection(): void
    {
        try {
            $newSocket = SocketUtil::accept($this->master);
            $this->handleConnection($newSocket);
        } catch (AcceptFailedException $e) {
            $this->emit('error', [$e]);
        }
    }


    private function handleConnection(mixed $socket): void
    {
        $connection = new Connection($socket, isUnix: $this->isUnix);
        $this->emit('connection', [$connection]);
    }
}
