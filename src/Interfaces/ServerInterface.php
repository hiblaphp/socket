<?php

declare(strict_types=1);

namespace Hibla\Socket\Interfaces;

use Evenement\EventEmitterInterface;

interface ServerInterface extends EventEmitterInterface
{
    public function getAddress(): ?string;
    public function pause(): void;
    public function resume(): void;
    public function close(): void;
}