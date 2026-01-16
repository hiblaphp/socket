<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Dns\Dns;
use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectorInterface;

/**
 * Main connector class that provides a high-level facade for creating streaming connections.
 *
 * Supports TCP/IP, TLS, and Unix domain socket connections with automatic DNS resolution,
 * Happy Eyeballs (RFC 8305), and configurable timeouts.
 *
 * @example Basic usage
 * ```php
 * $connector = new Connector();
 * $connector->connect('example.com:443')->then(fn($conn) => ...);
 * ```
 *
 * @example With custom configuration
 * ```php
 * $connector = new Connector([
 *     'timeout' => 5.0,
 *     'tcp' => ['bindto' => '192.168.1.100:0'],
 *     'tls' => ['verify_peer' => false],
 *     'dns' => ['1.1.1.1', '8.8.8.8'],
 * ]);
 * ```
 */
final class Connector implements ConnectorInterface
{
    /**
     * @var array<string, ConnectorInterface>
     */
    private readonly array $connectors;

    /**
     * Creates a new Connector instance.
     *
     * @param array{
     *     tcp?: bool|array|ConnectorInterface,
     *     tls?: bool|array|ConnectorInterface,
     *     unix?: bool|ConnectorInterface,
     *     dns?: bool|array<string>|ResolverInterface,
     *     timeout?: bool|float,
     *     happy_eyeballs?: bool
     * } $context Configuration options
     *
     * @throws \InvalidArgumentException for invalid configuration
     */
    public function __construct(array $context = [])
    {
        $context += [
            'tcp' => true,
            'tls' => true,
            'unix' => true,
            'dns' => true,
            'timeout' => true,
            'happy_eyeballs' => true,
        ];

        if ($context['timeout'] === true) {
            $context['timeout'] = (float) ini_get('default_socket_timeout');
        }

        $connectors = [];
        if ($context['tcp'] !== false) {
            $tcp = $this->buildTcpConnector($context);
            $tcp = $this->applyDnsResolution($tcp, $context);
            $tcp = $this->applyTimeout($tcp, $context);
            
            $connectors['tcp'] = $tcp;
        }

        if ($context['tls'] !== false && isset($connectors['tcp'])) {
            $tls = $this->buildTlsConnector($context, $connectors['tcp']);
            
            $connectors['tls'] = $tls;
        }

        if ($context['unix'] !== false) {
            $connectors['unix'] = $this->buildUnixConnector($context);
        }

        $this->connectors = $connectors;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $uri): PromiseInterface
    {
        $scheme = $this->extractScheme($uri);

        if (!isset($this->connectors[$scheme])) {
            return Promise::rejected(new InvalidUriException(
                sprintf('No connector available for URI scheme "%s" (EINVAL)', $scheme),
                defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
            ));
        }

        return $this->connectors[$scheme]->connect($uri);
    }

    /**
     * Extracts the scheme from a URI.
     */
    private function extractScheme(string $uri): string
    {
        if (!str_contains($uri, '://')) {
            return 'tcp';
        }

        return substr($uri, 0, strpos($uri, '://'));
    }

    /**
     * Builds the base TCP connector.
     */
    private function buildTcpConnector(array $context): ConnectorInterface
    {
        if ($context['tcp'] instanceof ConnectorInterface) {
            return $context['tcp'];
        }

        return new TcpConnector(
            context: is_array($context['tcp']) ? $context['tcp'] : []
        );
    }

    private function applyDnsResolution(ConnectorInterface $connector, array $context): ConnectorInterface
    {
        if ($context['dns'] === false) {
            return $connector;
        }

        $resolver = $this->buildResolver($context);

        if ($context['happy_eyeballs'] === true) {
            return new HappyEyeballsConnector($connector, $resolver);
        }

        return new DnsConnector($connector, $resolver);
    }

    private function buildResolver(array $context): ResolverInterface
    {
        if ($context['dns'] instanceof ResolverInterface) {
            return $context['dns'];
        }

        $builder = Dns::new();

        if (\is_array($context['dns'])) {
            $builder = $builder->withNameservers($context['dns']);
        }

        $builder = $builder->withCache();

        return $builder->build();
    }

    private function applyTimeout(ConnectorInterface $connector, array $context): ConnectorInterface
    {
        if ($context['timeout'] === false || $context['timeout'] <= 0) {
            return $connector;
        }

        return new TimeoutConnector($connector, (float) $context['timeout']);
    }

    private function buildTlsConnector(array $context, ConnectorInterface $baseConnector): ConnectorInterface
    {
        if ($context['tls'] instanceof ConnectorInterface) {
            return $context['tls'];
        }

        return new SecureConnector(
            connector: $baseConnector,
            context: \is_array($context['tls']) ? $context['tls'] : []
        );
    }

    private function buildUnixConnector(array $context): ConnectorInterface
    {
        if ($context['unix'] instanceof ConnectorInterface) {
            return $context['unix'];
        }

        return new UnixConnector();
    }
}