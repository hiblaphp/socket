<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use Hibla\Socket\Exceptions\AcceptFailedException;
use Hibla\Socket\Exceptions\AddressInUseException;
use Hibla\Socket\Exceptions\BindFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ServerInterface;

final class UnixServer extends EventEmitter implements ServerInterface
{
    /** @var resource */
    private readonly mixed $master;

    private bool $listening = false;

    private ?string $watcherId = null;

    private ?string $socketPath = null;

    public function __construct(string $path, private readonly array $context = [])
    {
        if (strpos($path, '://') === false) {
            $path = 'unix://' . $path;
        } elseif (substr($path, 0, 7) !== 'unix://') {
            throw new InvalidUriException(
                \sprintf('Given URI "%s" is invalid (EINVAL)', $path),
                \defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            );
        }

        $this->socketPath = substr($path, 7);

        if (file_exists($this->socketPath)) {
            $testSocket = @stream_socket_client(
                'unix://' . $this->socketPath,
                $errno,
                $errstr,
                0.1
            );

            if ($testSocket !== false) {
                fclose($testSocket);
                throw new AddressInUseException(
                    \sprintf('Unix domain socket "%s" is already in use', $path),
                    \defined('SOCKET_EADDRINUSE') ? SOCKET_EADDRINUSE : 98
                );
            }

            @unlink($this->socketPath);
        }

        $errno = 0;
        $errstr = '';

        set_error_handler(function ($_, $error) use (&$errno, &$errstr) {
            if (preg_match('/\(([^\)]+)\)|\[(\d+)\]: (.*)/', $error, $match)) {
                $errstr = $match[3] ?? $match[1];
                $errno = (int) ($match[2] ?? 0);
            }
        });

        $master = stream_socket_server(
            address: $path,
            error_code: $errno,
            error_message: $errstr,
            flags: STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            context: stream_context_create(['socket' => $this->context])
        );

        restore_error_handler();

        if ($master === false) {
            throw new BindFailedException(
                \sprintf('Failed to listen on Unix domain socket "%s": %s', $path, $errstr),
                $errno
            );
        }

        $this->master = $master;
        stream_set_blocking($this->master, false);

        $this->resume();
    }

    public function getAddress(): ?string
    {
        if (!\is_resource($this->master)) {
            return null;
        }

        return 'unix://' . stream_socket_get_name($this->master, false);
    }

    public function pause(): void
    {
        if (!$this->listening) {
            return;
        }

        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }

        $this->listening = false;
    }

    public function resume(): void
    {
        if ($this->listening || !\is_resource($this->master)) {
            return;
        }

        $this->watcherId = Loop::addStreamWatcher(
            stream: $this->master,
            callback: $this->acceptConnection(...),
            type: StreamWatcher::TYPE_READ
        );

        $this->listening = true;
    }

    private function acceptConnection(): void
    {
        if (!\is_resource($this->master)) {
            $this->emit('error', [new AcceptFailedException('Master socket is not a valid resource')]);
            return;
        }

        set_error_handler(function () {});
        $newSocket = @stream_socket_accept($this->master, 0);
        restore_error_handler();

        if ($newSocket === false) {
            $this->emit('error', [new AcceptFailedException('Unable to accept new connection')]);
            return;
        }

        $this->handleConnection($newSocket);
    }

    public function close(): void
    {
        if (!\is_resource($this->master)) {
            return;
        }

        $this->pause();
        fclose($this->master);
        $this->removeAllListeners();

        if ($this->socketPath !== null && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    /**
     * @param resource $socket
     * @internal
     */
    public function handleConnection(mixed $socket): void
    {
        $connection = new Connection($socket, isUnix: true);
        $this->emit('connection', [$connection]);
    }
}
