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
    public function __construct(
        private readonly array $context = [],
        private readonly ?float $defaultTimeout = null
    ) {
    }

    public function connect(string $uri, ?float $timeout = null): PromiseInterface
    {
        $timeout ??= $this->defaultTimeout;
        
        if (!str_contains($uri, '://')) {
            $uri = 'tcp://' . $uri;
        }

        $parts = parse_url($uri);
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            throw new InvalidUriException(
                \sprintf('Invalid URI "%s" given (expected format: tcp://host:port)', $uri)
            );
        }

        $ip = trim($parts['host'], '[]');
        if (@inet_pton($ip) === false) {
            throw new InvalidUriException(
                \sprintf('Given URI "%s" does not contain a valid host IP address', $uri)
            );
        }

        $context = ['socket' => $this->context];
        
        $args = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $args);
        }
        
        if (isset($args['hostname'])) {
            $context['ssl'] = [
                'SNI_enabled' => true,
                'peer_name' => $args['hostname'],
                ...($context['ssl'] ?? [])
            ];
        }

        $remote = 'tcp://' . $parts['host'] . ':' . $parts['port'];

        $contextOptions = stream_context_create($context);

        set_error_handler(static function (int $code, string $message) use (&$errno, &$errstr): bool {
            $errno = $code;
            $errstr = $message;
            return true;
        });

        $stream = stream_socket_client(
            address: $remote,
            error_code: $errno,
            error_message: $errstr,
            timeout: 0,
            flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            context: $contextOptions
        );

        restore_error_handler();

        if ($stream === false) {
            return Promise::rejected(new ConnectionFailedException(
                sprintf('Connection to %s failed: %s', $uri, $errstr ?? 'Unknown error'),
                $errno ?? 0
            ));
        }

        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();
        $watcherId = null;
        $timeoutId = null;

        $cleanup = function () use (&$watcherId, &$timeoutId): void {
            if ($watcherId !== null) {
                Loop::removeStreamWatcher($watcherId);
                $watcherId = null;
            }
            
            if ($timeoutId !== null) {
                Loop::cancelTimer($timeoutId);
                $timeoutId = null;
            }
        };

        $watcherCallback = function () use ($stream, $promise, $cleanup, $uri): void {
            $cleanup();
            
            if (stream_socket_get_name($stream, true) === false) {
                [$errno, $errstr] = $this->detectConnectionError($stream);
                
                @fclose($stream);
                $promise->reject(new ConnectionFailedException(
                    \sprintf('Connection to %s failed: %s', $uri, $errstr),
                    $errno
                ));
            } else {
                $promise->resolve(new Connection($stream));
            }
        };
        
        $watcherId = Loop::addStreamWatcher(
            stream: $stream,
            callback: $watcherCallback,
            type: StreamWatcher::TYPE_WRITE
        );

        if ($timeout !== null) {
            $timeoutId = Loop::addTimer($timeout, function () use ($stream, $promise, $cleanup, $uri, $timeout): void {
                $cleanup();
                @fclose($stream);
                
                $promise->reject(new ConnectionFailedException(
                    sprintf('Connection to %s timed out after %.2f seconds', $uri, $timeout),
                    \defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110
                ));
            });
        }

        $promise->onCancel(function () use ($stream, $cleanup, $uri): void {
            $cleanup();
            @fclose($stream);

            throw new ConnectionCancelledException(
                sprintf('Connection to %s cancelled during TCP handshake', $uri)
            );
        });

        return $promise;
    }

    /**
     * Detect the actual connection error when stream_socket_get_name() returns false.
     * This uses multiple strategies depending on available PHP extensions and OS.
     *
     * @param resource $stream The failed stream socket
     * @return array{0: int, 1: string} [errno, errstr]
     */
    private function detectConnectionError($stream): array
    {
        $errno = 0;
        $errstr = 'Connection refused';
        
        if (function_exists('socket_import_stream')) {
            $socket = socket_import_stream($stream);
            $errno = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
            $errstr = socket_strerror($errno);
            
            return [$errno, $errstr];
        }
        
        if (PHP_OS_FAMILY === 'Linux') {
            set_error_handler(static function (int $_, string $error) use (&$errno, &$errstr): bool {
                if (preg_match('/errno=(\d+) (.+)/', $error, $m)) {
                    $errno = (int) $m[1];
                    $errstr = $m[2];
                }
                return true;
            });
            
            @fwrite($stream, "\n");
            restore_error_handler();
            
            return [$errno, $errstr];
        }
        
        $errno = \defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111;
        
        return [$errno, $errstr];
    }
}