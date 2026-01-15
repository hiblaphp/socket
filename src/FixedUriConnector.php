<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Socket\Interfaces\ConnectorInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;

final readonly class FixedUriConnector implements ConnectorInterface
{
    /**
     * Creates a new FixedUriConnector that ignores the target URI.
     *
     * @param string $uri The fixed URI to always connect to
     * @param ConnectorInterface $connector The underlying connector to use
     */
    public function __construct(
        private string $uri,
        private ConnectorInterface $connector,
    ) {}

    /**
     * {@inheritdoc}
     *
     * Note: The provided URI parameter is ignored. This connector will
     * always connect to the fixed URI specified in the constructor.
     *
     * @param string $_ The target URI (ignored)
     * @return PromiseInterface<ConnectionInterface>
     */
    public function connect(string $_): PromiseInterface
    {
        return $this->connector->connect($this->uri);
    }
}