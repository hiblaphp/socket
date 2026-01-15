<?php

declare(strict_types=1);

namespace Tests\Mocks;

use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class MockResolver implements ResolverInterface
{
    private ?float $delay = null;
    private ?string $ip = null;
    private ?\Throwable $error = null;
    private bool $shouldHang = false;
    public bool $resolveCalled = false;
    public ?string $lastDomain = null;

    public function setSuccessAfter(float $delay, string $ip): self
    {
        $this->delay = $delay;
        $this->ip = $ip;
        $this->error = null;
        $this->shouldHang = false;
        return $this;
    }

    public function setFailureAfter(float $delay, \Throwable $error): self
    {
        $this->delay = $delay;
        $this->error = $error;
        $this->ip = null;
        $this->shouldHang = false;
        return $this;
    }

    public function setHang(): self
    {
        $this->shouldHang = true;
        $this->delay = null;
        return $this;
    }

    public function setImmediateSuccess(string $ip): self
    {
        $this->ip = $ip;
        $this->delay = null;
        $this->error = null;
        $this->shouldHang = false;
        return $this;
    }

    public function setImmediateFailure(\Throwable $error): self
    {
        $this->error = $error;
        $this->delay = null;
        $this->ip = null;
        $this->shouldHang = false;
        return $this;
    }

    public function resolve(string $domain): PromiseInterface
    {
        $this->resolveCalled = true;
        $this->lastDomain = $domain;

        if ($this->shouldHang) {
            return new Promise();
        }

        if ($this->delay === null) {
            if ($this->ip !== null) {
                return Promise::resolved($this->ip);
            }
            if ($this->error !== null) {
                return Promise::rejected($this->error);
            }
        }

        /** @var Promise $promise */
        $promise = new Promise();

        Loop::addTimer($this->delay, function () use ($promise): void {
            if ($promise->isCancelled()) {
                return;
            }

            if ($this->ip !== null) {
                $promise->resolve($this->ip);
            } elseif ($this->error !== null) {
                $promise->reject($this->error);
            }
        });

        return $promise;
    }

    public function resolveAll(string $domain, RecordType $type = RecordType::A): PromiseInterface
    {
        return $this->resolve($domain)->then(fn($ip) => [$ip]);
    }

    public function reset(): void
    {
        $this->delay = null;
        $this->ip = null;
        $this->error = null;
        $this->shouldHang = false;
        $this->resolveCalled = false;
        $this->lastDomain = null;
    }
}