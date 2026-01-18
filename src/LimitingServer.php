<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Throwable;

final class LimitingServer extends EventEmitter implements ServerInterface
{
    /** @var array<int, ConnectionInterface> */
    private array $connections = [];

    private bool $pauseOnLimit = false;

    private bool $autoPaused = false;

    private bool $manuPaused = false;

    /**
     * @param ServerInterface $server The underlying server instance
     * @param int|null $connectionLimit Maximum number of concurrent connections (null for unlimited)
     * @param bool $pauseOnLimit Whether to pause the server when limit is reached
     */
    public function __construct(
        private readonly ServerInterface $server,
        private readonly ?int $connectionLimit,
        bool $pauseOnLimit = false
    ) {
        if ($connectionLimit !== null) {
            $this->pauseOnLimit = $pauseOnLimit;
        }

        $this->server->on('connection', $this->handleConnection(...));
        $this->server->on('error', $this->handleError(...));
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getAddress(): ?string
    {
        return $this->server->getAddress();
    }

    public function pause(): void
    {
        if (!$this->manuPaused) {
            $this->manuPaused = true;

            if (!$this->autoPaused) {
                $this->server->pause();
            }
        }
    }

    public function resume(): void
    {
        if ($this->manuPaused) {
            $this->manuPaused = false;

            if (!$this->autoPaused) {
                $this->server->resume();
            }
        }
    }

    public function close(): void
    {
        $this->server->close();
        $this->removeAllListeners();
    }

    private function handleConnection(ConnectionInterface $connection): void
    {
        if ($this->connectionLimit !== null && \count($this->connections) >= $this->connectionLimit) {
            $this->handleError(new ConnectionFailedException(
                'Connection rejected because server reached connection limit'
            ));
            $connection->close();
            return;
        }

        $this->connections[] = $connection;
        
        $connection->on('close', function () use ($connection): void {
            $this->handleDisconnection($connection);
        });

        // Pause server if limit reached and pauseOnLimit is enabled
        if ($this->pauseOnLimit && !$this->autoPaused && \count($this->connections) >= $this->connectionLimit) {
            $this->autoPaused = true;

            if (!$this->manuPaused) {
                $this->server->pause();
            }
        }

        $this->emit('connection', [$connection]);
    }

    private function handleDisconnection(ConnectionInterface $connection): void
    {
        $key = array_search($connection, $this->connections, true);
        if ($key !== false) {
            unset($this->connections[$key]);
        }

        // Continue accepting new connections if below limit
        if ($this->autoPaused && \count($this->connections) < $this->connectionLimit) {
            $this->autoPaused = false;

            if (!$this->manuPaused) {
                $this->server->resume();
            }
        }
    }
    
    private function handleError(Throwable $error): void
    {
        $this->emit('error', [$error]);
    }
}