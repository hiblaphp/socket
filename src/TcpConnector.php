<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\ConnectionCancelledException;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;

final class TcpConnector implements ConnectorInterface
{
    public function __construct(private readonly array $context = [])
    {
    }

    public function connect(string $uri): PromiseInterface
    {
        if (!str_contains($uri, '://')) {
            $uri = 'tcp://' . $uri;
        }

        $parts = parse_url($uri);
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            throw new InvalidUriException(sprintf('Invalid URI "%s" given', $uri));
        }

        if (filter_var($parts['host'], FILTER_VALIDATE_IP) === false) {
            throw new InvalidUriException(\sprintf('Given URI "%s" does not contain a valid host IP', $uri));
        }
        
        $contextOptions = stream_context_create(['socket' => $this->context]);

        set_error_handler(static function (int $code, string $message) use (&$errno, &$errstr) {
            $errno = $code;
            $errstr = $message;
        });

        $stream = stream_socket_client(
            address: $uri,
            error_code: $errno,
            error_message: $errstr,
            timeout: 0,
            flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            context: $contextOptions
        );

        restore_error_handler();

        if ($stream === false) {
            return Promise::rejected(new ConnectionFailedException(
                \sprintf('Connection to %s failed: %s', $uri, $errstr),
                $errno
            ));
        }


        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();
        $watcherId = null;

        $watcherCallback = function () use ($stream, $promise, &$watcherId, $uri): void {
            if ($watcherId !== null) {
                Loop::removeStreamWatcher($watcherId);
                $watcherId = null;
            }
            
            if (stream_socket_get_name($stream, true) !== false) {
                $promise->resolve(new Connection($stream));
            } else {
                @fclose($stream);
                $promise->reject(new ConnectionFailedException(\sprintf('Connection to %s refused', $uri)));
            }
        };
        
        $watcherId = Loop::addStreamWatcher(
            stream: $stream,
            callback: $watcherCallback,
            type: StreamWatcher::TYPE_WRITE
        );

        $promise->onCancel(function () use ($stream, &$watcherId, $uri): void {
            if ($watcherId !== null) {
                Loop::removeStreamWatcher($watcherId);
                $watcherId = null;
            }
            @fclose($stream);

            throw new ConnectionCancelledException(\sprintf('Connection to %s cancelled', $uri));
        });

        return $promise;
    }
}