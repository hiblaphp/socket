<?php

declare(strict_types=1);

namespace Hibla\Socket\Interfaces;

use Hibla\Stream\Interfaces\DuplexStreamInterface;

interface ConnectionInterface extends DuplexStreamInterface
{
    public function getRemoteAddress(): ?string;
    public function getLocalAddress(): ?string;
}