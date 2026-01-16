<?php

declare(strict_types=1);

namespace Hibla\Socket\Internals;

use Hibla\Dns\Enums\RecordType;
use Hibla\Dns\Interfaces\ResolverInterface;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Interfaces\ConnectorInterface;

/**
 * Happy Eyeballs Connection Builder
 * 
 * Implements the RFC 8305 algorithm for racing IPv6 and IPv4 connections.
 * 
 * Key RFC 8305 features:
 * - Resolution Delay: 50ms delay for IPv4 after IPv6 starts (Section 3)
 * - Connection Attempt Delay: 250ms between connection attempts (Section 5)
 * - Interleaved connection attempts alternating between IPv6 and IPv4
 * 
 * @internal
 */
final class HappyEyeBallsConnectionBuilder
{
    /**
     * Delay for starting IPv4 resolution after IPv6 (50ms per RFC 8305)
     */
    private const float RESOLUTION_DELAY = 0.05;

    /**
     * Delay between connection attempts (250ms per RFC 8305)
     */
    private const float CONNECTION_ATTEMPT_DELAY = 0.25;

    /** @var array{4: bool, 28: bool} Track resolution status by RecordType value */
    private array $resolved = [
        RecordType::A->value => false,
        RecordType::AAAA->value => false,
    ];

    /** @var array<int, PromiseInterface> */
    private array $resolverPromises = [];

    /** @var array<int, PromiseInterface> */
    private array $connectionPromises = [];

    /** @var list<string> Queue of IP addresses to connect to */
    private array $connectQueue = [];

    private ?string $nextAttemptTimerId = null;

    private ?string $resolutionDelayTimerId = null;

    private int $ipsCount = 0;

    private int $failureCount = 0;

    private int $lastErrorFamily = 0;

    private ?string $lastError6 = null;

    private ?string $lastError4 = null;

    private bool $isResolved = false;

    /** @var array<string, mixed> */
    private readonly array $parts;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly ResolverInterface $resolver,
        private readonly string $uri,
        private readonly string $host,
        array $parts
    ) {
        $this->parts = $parts;
    }

    /**
     * Start the Happy Eyeballs connection process
     */
    public function connect(): PromiseInterface
    {
        /** @var Promise $promise */
        $promise = new Promise();

        $lookupResolve = function (RecordType $type) use ($promise): \Closure {
            return function (array $ips) use ($type, $promise): void {
                if ($this->isResolved) {
                    return;
                }

                unset($this->resolverPromises[$type->value]);
                $this->resolved[$type->value] = true;

                $this->mixIpsIntoConnectQueue($ips);

                // Start next connection attempt if:
                // 1. No timer is scheduled AND
                // 2. We have IPs to try AND  
                // 3. No connection attempts are currently running
                if (
                    $this->nextAttemptTimerId === null &&
                    $this->connectQueue !== [] &&
                    $this->connectionPromises === []
                ) {
                    $this->check($promise);
                }
            };
        };

        // Start IPv6 (AAAA) resolution immediately
        $this->resolverPromises[RecordType::AAAA->value] = $this->resolve(RecordType::AAAA, $promise)
            ->then($lookupResolve(RecordType::AAAA));

        // Start IPv4 (A) resolution with potential delay per RFC 8305
        $this->resolverPromises[RecordType::A->value] = $this->resolve(RecordType::A, $promise)
            ->then(function (array $ips) use ($promise): PromiseInterface|array {
                if ($this->isResolved) {
                    return [];
                }

                // Happy path: IPv6 resolved already or no IPv4 addresses
                if ($this->resolved[RecordType::AAAA->value] || $ips === []) {
                    return $ips;
                }

                // Delay IPv4 processing per RFC 8305 Section 3
                return $this->delayIPv4Resolution($ips);
            })
            ->then($lookupResolve(RecordType::A));

        $promise->onCancel(function () use ($promise): void {
            $this->cleanUp();
        });

        return $promise;
    }

    /**
     * Resolve DNS records for specified type
     * 
     * @return PromiseInterface<list<string>>
     */
    private function resolve(RecordType $type, Promise $rejectTarget): PromiseInterface
    {
        return $this->resolver->resolveAll($this->host, $type)->then(
            null,
            function (\Throwable $e) use ($type, $rejectTarget): array {
                if ($this->isResolved) {
                    return [];
                }

                unset($this->resolverPromises[$type->value]);
                $this->resolved[$type->value] = true;

                // Track errors by address family
                if ($type === RecordType::A) {
                    $this->lastError4 = $e->getMessage();
                    $this->lastErrorFamily = 4;
                } else {
                    $this->lastError6 = $e->getMessage();
                    $this->lastErrorFamily = 6;
                }

                // Reject if both resolved and no IPs found
                if ($this->hasBeenResolved() && $this->ipsCount === 0) {
                    $this->isResolved = true;
                    $rejectTarget->reject(new ConnectionFailedException(
                        $this->buildErrorMessage(),
                        0,
                        $e
                    ));
                }

                return [];
            }
        );
    }

    /**
     * Delay IPv4 resolution per RFC 8305 Section 3
     * 
     * @param list<string> $ips
     * @return PromiseInterface<list<string>>
     */
    private function delayIPv4Resolution(array $ips): PromiseInterface
    {
        /** @var Promise $delayedPromise */
        $delayedPromise = new Promise();
        $cancelled = false;

        // Schedule timer to resolve after delay
        $this->resolutionDelayTimerId = Loop::addTimer(
            self::RESOLUTION_DELAY,
            function () use ($delayedPromise, $ips, &$cancelled): void {
                $this->resolutionDelayTimerId = null;
                if (!$cancelled && !$this->isResolved) {
                    $delayedPromise->resolve($ips);
                }
            }
        );

        // Resolve immediately if IPv6 completes
        $ipv6Promise = $this->resolverPromises[RecordType::AAAA->value] ?? null;
        if ($ipv6Promise !== null) {
            $ipv6Promise->then(function () use ($delayedPromise, $ips, &$cancelled): void {
                if (!$cancelled && !$this->isResolved && $this->resolutionDelayTimerId !== null) {
                    Loop::cancelTimer($this->resolutionDelayTimerId);
                    $this->resolutionDelayTimerId = null;
                    $delayedPromise->resolve($ips);
                }
            });
        }

        $delayedPromise->onCancel(function () use (&$cancelled, &$ips): void {
            $cancelled = true;
            if ($this->resolutionDelayTimerId !== null) {
                Loop::cancelTimer($this->resolutionDelayTimerId);
                $this->resolutionDelayTimerId = null;
            }
            $ips = [];
        });

        return $delayedPromise;
    }

    /**
     * Check and start next connection attempt
     * 
     * Per RFC 8305 Section 5: Connection attempts are started with a fixed delay
     * between them, regardless of whether previous attempts have failed.
     */
    private function check(Promise $promise): void
    {
        if ($this->isResolved || $this->connectQueue === []) {
            return;
        }

        $ip = array_shift($this->connectQueue);

        $connectionPromise = $this->attemptConnection($ip);
        $index = \count($this->connectionPromises);
        $this->connectionPromises[$index] = $connectionPromise;

        $connectionPromise->then(
            onFulfilled: function ($connection) use ($index, $promise): void {
                if ($this->isResolved) {
                    $connection->close();
                    
                    return;
                }

                unset($this->connectionPromises[$index]);
                $this->isResolved = true;
                $this->cleanUp();
                $promise->resolve($connection);
            },
            onRejected: function (\Throwable $e) use ($index, $ip, $promise): void {
                if ($this->isResolved) {
                    return;
                }

                unset($this->connectionPromises[$index]);
                $this->failureCount++;

                // Track error by IP family
                $message = preg_replace(
                    '/^(Connection to [^ ]+)(\?hostname=[^ &]+)?/',
                    '$1',
                    $e->getMessage()
                );

                if (!str_contains($ip, ':')) {
                    $this->lastError4 = $message;
                    $this->lastErrorFamily = 4;
                } else {
                    $this->lastError6 = $message;
                    $this->lastErrorFamily = 6;
                }

                // RFC 8305: Do NOT cancel timer on failure
                // The timer continues running and will start the next attempt
                // This is parallel racing, not serial fallback

                // Only reject if all attempts exhausted
                if (
                    $this->hasBeenResolved() &&
                    $this->ipsCount === $this->failureCount &&
                    $this->connectQueue === []
                ) {
                    $this->isResolved = true;
                    $this->cleanUp();
                    $promise->reject(new ConnectionFailedException(
                        $this->buildErrorMessage(),
                        $e->getCode(),
                        $e
                    ));
                }
            }
        );

        // Schedule next attempt per RFC 8305 Section 5
        // Timer runs independently of connection success/failure
        if (
            $this->nextAttemptTimerId === null &&
            (\count($this->connectQueue) > 0 || !$this->hasBeenResolved())
        ) {
            $this->nextAttemptTimerId = Loop::addTimer(
                self::CONNECTION_ATTEMPT_DELAY,
                function () use ($promise): void {
                    $this->nextAttemptTimerId = null;
                    if (!$this->isResolved && $this->connectQueue !== []) {
                        $this->check($promise);
                    }
                }
            );
        }
    }

    /**
     * Attempt connection to a specific IP
     */
    private function attemptConnection(string $ip): PromiseInterface
    {
        $uri = $this->buildUri($this->parts, $this->host, $ip);
        return $this->connector->connect($uri);
    }

    /**
     * Mix IPs into connection queue using RFC 8305 Section 4 interleaving
     * 
     * Alternates between IPv6 and IPv4 addresses to give IPv6 preference
     * while maintaining fast fallback to IPv4.
     * 
     * @param list<string> $ips
     */
    private function mixIpsIntoConnectQueue(array $ips): void
    {
        shuffle($ips);
        $this->ipsCount += \count($ips);

        $stash = $this->connectQueue;
        $this->connectQueue = [];

        while ($stash !== [] || $ips !== []) {
            if ($ips !== []) {
                $this->connectQueue[] = array_shift($ips);
            }
            if ($stash !== []) {
                $this->connectQueue[] = array_shift($stash);
            }
        }
    }

    /**
     * Build URI with resolved IP
     * 
     * @param array<string, mixed> $parts
     */
    private function buildUri(array $parts, string $host, string $ip): string
    {
        $uri = '';

        if (isset($parts['scheme'])) {
            $uri .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $uri .= $parts['user'];
            if (isset($parts['pass'])) {
                $uri .= ':' . $parts['pass'];
            }
            $uri .= '@';
        }

        // Wrap IPv6 addresses in brackets
        $uri .= str_contains($ip, ':') ? "[{$ip}]" : $ip;

        if (isset($parts['port'])) {
            $uri .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            $uri .= $parts['path'];
        }

        $uri .= '?hostname=' . rawurlencode($host);

        if (isset($parts['query'])) {
            $uri .= '&' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $uri .= '#' . $parts['fragment'];
        }

        return $uri;
    }

    /**
     * Clean up all pending operations
     */
    private function cleanUp(): void
    {
        $this->connectQueue = [];

        // Cancel pending connections
        foreach ($this->connectionPromises as $promise) {
            $promise->cancelChain();
        }
        $this->connectionPromises = [];

        // Cancel pending DNS resolutions (IPv4 first if awaiting IPv6 delay)
        foreach (array_reverse($this->resolverPromises) as $promise) {
            $promise->cancelChain();
        }
        $this->resolverPromises = [];

        $this->cancelNextAttempt();
        $this->cancelResolutionDelay();
    }

    /**
     * Cancel the next scheduled connection attempt
     */
    private function cancelNextAttempt(): void
    {
        if ($this->nextAttemptTimerId !== null) {
            Loop::cancelTimer($this->nextAttemptTimerId);
            $this->nextAttemptTimerId = null;
        }
    }

    /**
     * Cancel the IPv4 resolution delay timer
     */
    private function cancelResolutionDelay(): void
    {
        if ($this->resolutionDelayTimerId !== null) {
            Loop::cancelTimer($this->resolutionDelayTimerId);
            $this->resolutionDelayTimerId = null;
        }
    }

    /**
     * Check if both IPv4 and IPv6 resolution completed
     */
    private function hasBeenResolved(): bool
    {
        return $this->resolved[RecordType::A->value]
            && $this->resolved[RecordType::AAAA->value];
    }

    /**
     * Build comprehensive error message
     */
    private function buildErrorMessage(): string
    {
        $v4Error = $this->lastError4 ?? 'No IPv4 address found';
        $v6Error = $this->lastError6 ?? 'No IPv6 address found';

        $message = match (true) {
            $v4Error === $v6Error => $v6Error,
            $this->lastErrorFamily === 6 => \sprintf(
                'Last error for IPv6: %s. Previous error for IPv4: %s',
                $v6Error,
                $v4Error
            ),
            default => \sprintf(
                'Last error for IPv4: %s. Previous error for IPv6: %s',
                $v4Error,
                $v6Error
            ),
        };

        if ($this->hasBeenResolved() && $this->ipsCount === 0) {
            $prefix = ($this->lastError6 === $this->lastError4)
                ? ' during DNS lookup: '
                : ' during DNS lookup. ';
            $message = $prefix . $message;
        } else {
            $message = ': ' . $message;
        }

        return \sprintf('Connection to %s failed%s', $this->uri, $message);
    }
}
