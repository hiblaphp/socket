<?php

declare(strict_types=1);

namespace Hibla\Socket;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\InvalidUriException;
use Hibla\Socket\Interfaces\ConnectorInterface;

final class UnixConnector implements ConnectorInterface
{
    public function connect(string $path): PromiseInterface
    {
        if (!str_contains($path, '://')) {
            $path = 'unix://' . $path;
        } elseif (!str_starts_with($path, 'unix://')) {
            throw new InvalidUriException(sprintf('Invalid URI "%s" given', $path));
        }

        $resource = @stream_socket_client($path, $errno, $errstr, 1.0);

        if ($resource === false) {
            return Promise::rejected(new ConnectionFailedException(
                \sprintf('Unable to connect to unix domain socket "%s": %s', $path, $errstr),
                $errno
            ));
        }

        $connection = new Connection($resource, isUnix: true);

        return Promise::resolved($connection);
    }
}
