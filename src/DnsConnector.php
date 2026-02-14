<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectionInterface;
use Hibla\Socket\Interfaces\ConnectorInterface;

/**
 * A connector that transparently resolves hostnames to IP addresses.
 *
 * This class acts as a decorator for another {@see ConnectorInterface} (typically a TcpConnector).
 * It intercepts the connection request, extracts the hostname from the URI, and performs
 * an asynchronous DNS lookup. Once the hostname is resolved to an IP address, it
 * delegates the actual connection establishment to the underlying connector using the
 * resolved IP.
 *
 * If the URI already contains a valid IP address, DNS resolution is skipped.
 */
final class DnsConnector implements ConnectorInterface
{
    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly ResolverInterface $resolver
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $uri): PromiseInterface
    {
        $original = $uri;

        // Parse URI - add scheme if missing
        if (! str_contains($uri, '://')) {
            $uri = 'tcp://' . $uri;
            $parts = parse_url($uri);
            if (isset($parts['scheme'])) {
                unset($parts['scheme']);
            }
        } else {
            $parts = parse_url($uri);
        }

        // Validate URI structure
        if ($parts === false || ! isset($parts['host'])) {
            return Promise::rejected(new InvalidUriException(
                \sprintf('Given URI "%s" is invalid (EINVAL)', $original),
                \defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            ));
        }

        $host = trim($parts['host'], '[]');

        // Skip DNS lookup if URI already contains an IP address
        if (@inet_pton($host) !== false) {
            return $this->connector->connect($original);
        }

        /** @var Promise<ConnectionInterface> $promise */
        $promise = new Promise();

        // Perform DNS resolution
        $dnsPromise = $this->resolver->resolve($host);
        $connectionPromise = null;
        $resolved = false;

        $dnsPromise->then(
            onFulfilled: function (string $ip) use ($promise, &$connectionPromise, &$resolved, $parts, $host, $original): void {
                $resolved = true;

                // Build new URI with resolved IP
                $connectionUri = $this->buildUri($parts, $host, $ip);

                // Connect using resolved IP
                $connectionPromise = $this->connector->connect($connectionUri);

                $connectionPromise->then(
                    onFulfilled: function ($connection) use ($promise): void {
                        $promise->resolve($connection);
                    },
                    onRejected: function (\Throwable $e) use ($promise, $original): void {
                        if ($e instanceof ConnectionFailedException) {
                            $message = preg_replace(
                                '/^(Connection to [^ ]+)(\?hostname=[^ &]+)?/',
                                '$1',
                                $e->getMessage()
                            );

                            $e = new ConnectionFailedException(
                                \sprintf('Connection to %s failed: %s', $original, $message),
                                $e->getCode(),
                                $e
                            );
                        }

                        $promise->reject($e);
                    }
                );
            },
            onRejected: function (\Throwable $e) use ($promise, $original): void {
                $promise->reject(new ConnectionFailedException(
                    \sprintf('Connection to %s failed during DNS lookup: %s', $original, $e->getMessage()),
                    0,
                    $e
                ));
            }
        );

        $promise->onCancel(function () use (&$dnsPromise, &$connectionPromise, &$resolved): void {
            if (! $resolved) {
                $dnsPromise->cancelChain();

                return;
            }

            if ($connectionPromise !== null) {
                $connectionPromise->cancelChain();
            }
        });

        return $promise;
    }

    /**
     * Build URI from parsed components with resolved IP.
     *
     * @param array<string, mixed> $parts Parsed URI components
     * @param string $host Original hostname
     * @param string $ip Resolved IP address
     * @return string Complete URI with resolved IP
     */
    private function buildUri(array $parts, string $host, string $ip): string
    {
        $uri = '';

        if (isset($parts['scheme']) && is_string($parts['scheme'])) {
            $uri .= $parts['scheme'] . '://';
        }

        if (isset($parts['user']) && is_string($parts['user'])) {
            $uri .= $parts['user'];
            if (isset($parts['pass']) && is_string($parts['pass'])) {
                $uri .= ':' . $parts['pass'];
            }
            $uri .= '@';
        }

        if (str_contains($ip, ':')) {
            $uri .= '[' . $ip . ']';
        } else {
            $uri .= $ip;
        }

        if (isset($parts['port']) && is_int($parts['port'])) {
            $uri .= ':' . $parts['port'];
        }

        if (isset($parts['path']) && is_string($parts['path'])) {
            $uri .= $parts['path'];
        }

        $uri .= '?hostname=' . rawurlencode($host);

        if (isset($parts['query']) && is_string($parts['query'])) {
            $uri .= '&' . $parts['query'];
        }

        if (isset($parts['fragment']) && is_string($parts['fragment'])) {
            $uri .= '#' . $parts['fragment'];
        }

        return $uri;
    }
}
