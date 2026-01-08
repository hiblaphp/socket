<?php

declare(strict_types=1);

namespace Hibla\Socket\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for establishing streaming connections.
 */
interface ConnectorInterface
{
    /**
     * Creates a streaming connection to the given remote address.
     *
     * @param string $uri The URI to connect to.
     * @return PromiseInterface<ConnectionInterface> Resolves with a ConnectionInterface on success or rejects on failure.
     */
    public function connect(string $uri): PromiseInterface;
}