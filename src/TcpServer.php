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

final class TcpServer extends EventEmitter implements ServerInterface
{
    /** @var resource */
    private readonly mixed $master;
    private readonly string $address;
    private bool $listening = false;
    private ?string $watcherId = null;

    public function __construct(string $uri, private readonly array $context = [])
    {
        if (is_numeric($uri)) {
            $uri = '127.0.0.1:' . $uri;
        }

        if (!str_contains($uri, '://')) {
            $uri = 'tcp://' . $uri;
        }

        if (str_ends_with($uri, ':0')) {
            $parts = parse_url(substr($uri, 0, -2));
            if ($parts) {
                $parts['port'] = 0;
            }
        } else {
            $parts = parse_url($uri);
        }

        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            throw new InvalidUriException(
                \sprintf('Invalid URI "%s" given', $uri)
            );
        }

        if (@inet_pton(trim($parts['host'], '[]')) === false) {
            throw new InvalidUriException(
                \sprintf('Invalid URI "%s" does not contain a valid host IP', $uri)
            );
        }

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_server(
            address: $uri,
            error_code: $errno,
            error_message: $errstr,
            flags: STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            context: stream_context_create(['socket' => $this->context + ['backlog' => 511]])
        );

        if ($socket === false) {
            throw new BindFailedException(
                \sprintf('Failed to listen on "%s": %s', $uri, $errstr),
                $errno
            );
        }

        $this->master = $socket;
        stream_set_blocking($this->master, false);
        $this->address = stream_socket_get_name($this->master, false);
        $this->resume();
    }

    public function getAddress(): ?string
    {
        if (!\is_resource($this->master)) {
            return null;
        }

        $address = $this->address;
        $pos = strrpos($address, ':');
        if ($pos !== false && strpos($address, ':') < $pos && !str_starts_with($address, '[')) {
            $addr = substr($address, 0, $pos);
            $port = substr($address, $pos + 1);
            $address = '[' . $addr . ']:' . $port;
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
        $connection = new Connection($socket);
        $this->emit('connection', [$connection]);
    }
}
