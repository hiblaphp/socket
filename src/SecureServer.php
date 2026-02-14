<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Evenement\EventEmitter;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Hibla\Socket\Internals\StreamEncryption;
use Throwable;
use UnexpectedValueException;

/**
 * A server that encrypts incoming connections using TLS (formerly SSL).
 *
 * It wraps a plaintext server (like TcpServer), performs the TLS handshake
 * on new connections, and emits them only after encryption is established.
 */
final class SecureServer extends EventEmitter implements ServerInterface
{
    private readonly StreamEncryption $encryption;

    /**
     * @var array<string, mixed>
     */
    private readonly array $context;

    /**
     * @param ServerInterface $server The underlying plaintext server (usually TcpServer)
     * @param array<string, mixed> $context SSL context options (e.g., 'local_cert', 'passphrase')
     * @see https://www.php.net/manual/en/context.ssl.php
     */
    public function __construct(
        private readonly ServerInterface $server,
        array $context = []
    ) {
        /** @var array<string, mixed> $mergedContext */
        $mergedContext = $context + ['passphrase' => ''];
        $this->context = $mergedContext;

        $this->encryption = new StreamEncryption(isServer: true);

        $this->server->on('error', fn (Throwable $error) => $this->emit('error', [$error]));
        $this->server->on('connection', $this->handleConnection(...));
    }

    /**
     * {@inheritDoc}
     */
    public function getAddress(): ?string
    {
        $address = $this->server->getAddress();

        if ($address === null) {
            return null;
        }

        return str_replace('tcp://', 'tls://', $address);
    }

    /**
     * {@inheritDoc}
     */
    public function pause(): void
    {
        $this->server->pause();
    }

    /**
     * {@inheritDoc}
     */
    public function resume(): void
    {
        $this->server->resume();
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        $this->server->close();
        $this->removeAllListeners();
    }

    private function handleConnection(ConnectionInterface $connection): void
    {
        if (! $connection instanceof Connection) {
            $this->emit('error', [
                new UnexpectedValueException('Base server does not use internal Connection class exposing stream resource'),
            ]);
            $connection->close();

            return;
        }

        $socket = $connection->getResource();
        foreach ($this->context as $name => $value) {
            stream_context_set_option($socket, 'ssl', $name, $value);
        }

        // Capture remote address now, as it might be unavailable after a failed handshake closure
        $remote = $connection->getRemoteAddress() ?? 'unknown';

        $this->encryption->enable($connection)
            ->then(
                onFulfilled: function (Connection $secureConnection) {
                    $this->emit('connection', [$secureConnection]);
                },
                onRejected: function (Throwable $error) use ($connection, $remote): void {
                    $wrappedError = new EncryptionFailedException(
                        \sprintf('Connection from %s failed during TLS handshake: %s', $remote, $error->getMessage()),
                        (int) $error->getCode(),
                        $error
                    );

                    $this->emit('error', [$wrappedError]);
                    $connection->close();
                }
            )
        ;
    }
}
