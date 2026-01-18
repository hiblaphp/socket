<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Hibla\Socket\Internals\HappyEyeBallsConnectionBuilder;

/**
 * Happy Eyeballs Connector (RFC 8305)
 * 
 * Implements dual-stack connection establishment that attempts both IPv6 and IPv4
 * connections in parallel to minimize user-visible delays.
 * 
 * @example Basic usage
 * ```php
 * $connector = new HappyEyeBallsConnector($tcpConnector, $resolver);
 * $connector->connect('example.com:443')->then(fn($conn) => ...);
 * ```
 */
final class HappyEyeBallsConnector implements ConnectorInterface
{
    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly ResolverInterface $resolver,
        private readonly bool $ipv6Check = false
    ) {}

    /**
     * {@inheritdoc}
     */
    public function connect(string $uri): PromiseInterface
    {
        $original = $uri;

        if (!str_contains($uri, '://')) {
            $uri = 'tcp://' . $uri;
            $parts = parse_url($uri);
            if (isset($parts['scheme'])) {
                unset($parts['scheme']);
            }
        } else {
            $parts = parse_url($uri);
        }

        if ($parts === false || !isset($parts['host'])) {
            return Promise::rejected(new InvalidUriException(
                \sprintf('Given URI "%s" is invalid (EINVAL)', $original),
                \defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            ));
        }

        $host = trim($parts['host'], '[]');

        // Skip Happy Eyeballs if URI already contains an IP address
        if (@inet_pton($host) !== false) {
            return $this->connector->connect($original);
        }

        $builder = new HappyEyeBallsConnectionBuilder(
            $this->connector,
            $this->resolver,
            $uri,
            $host,
            $parts,
            $this->ipv6Check
        );

        return $builder->connect();
    }
}
