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

    /**
     * @var array<int, PromiseInterface<list<string>>>
     */
    private array $resolverPromises = [];

    /**
     * @var array<int, PromiseInterface<mixed>>
     */
    private array $connectionPromises = [];

    /**
     * @var list<string> Queue of IP addresses to connect to
     */
    private array $connectQueue = [];

    /**
     * @var array<string, mixed>
     */
    private readonly array $parts;

    /**
     * @var array<int, bool> Track resolution status by RecordType value
     */
    private array $resolved = [];

    private int $ipsCount = 0;

    private int $failureCount = 0;

    private int $lastErrorFamily = 0;

    private bool $isResolved = false;

    private ?string $lastError6 = null;

    private ?string $lastError4 = null;

    private ?string $nextAttemptTimerId = null;

    private ?string $resolutionDelayTimerId = null;

    /**
     * @param array<string, mixed> $parts
     */
    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly ResolverInterface $resolver,
        private readonly string $uri,
        private readonly string $host,
        array $parts,
        private readonly bool $ipv6Check = false
    ) {
        $this->parts = $parts;
        $this->resolved = [
            RecordType::A->value => false,
            RecordType::AAAA->value => false,
        ];
    }

    /**
     * Start the Happy Eyeballs connection process
     *
     * @return PromiseInterface<mixed>
     */
    public function connect(): PromiseInterface
    {
        /** @var Promise<mixed> $promise */
        $promise = new Promise();

        // Check if IPv6 pre-check is enabled and IPv6 is actually routable
        if ($this->ipv6Check && ! IPv6ConnectivityChecker::isRoutable()) {
            // Skip IPv6 entirely
            $this->resolved[RecordType::AAAA->value] = true;

            // Start IPv4 (A) resolution immediately without delay
            $this->resolverPromises[RecordType::A->value] = $this->resolve(RecordType::A, $promise)
                ->then(
                    /**
                     * @param list<string> $ips
                     * @return list<string>
                     */
                    fn (array $ips): array => $this->handleResolvedIps($ips, RecordType::A, $promise)
                )
            ;
        } else {
            // Full RFC 8305 Dual Stack implementation
            // (Either ipv6Check is disabled, or IPv6 is routable)

            // Start IPv6 (AAAA) resolution immediately
            $this->resolverPromises[RecordType::AAAA->value] = $this->resolve(RecordType::AAAA, $promise)
                ->then(
                    /**
                     * @param list<string> $ips
                     * @return list<string>
                     */
                    fn (array $ips): array => $this->handleResolvedIps($ips, RecordType::AAAA, $promise)
                )
            ;

            // Start IPv4 (A) resolution with potential delay per RFC 8305
            $this->resolverPromises[RecordType::A->value] = $this->resolve(RecordType::A, $promise)
                ->then(
                    /**
                     * @param list<string> $ips
                     * @return PromiseInterface<list<string>>|list<string>
                     */
                    function (array $ips): PromiseInterface|array {
                        if ($this->isResolved) {
                            return $ips;
                        }

                        // Happy path: IPv6 resolved already or no IPv4 addresses
                        if ($this->resolved[RecordType::AAAA->value] || $ips === []) {
                            return $ips;
                        }

                        return $this->delayIPv4Resolution($ips);
                    }
                )
                ->then(
                    /**
                     * @param list<string> $ips
                     * @return list<string>
                     */
                    fn (array $ips): array => $this->handleResolvedIps($ips, RecordType::A, $promise)
                )
            ;
        }

        $promise->onCancel(function (): void {
            $this->cleanUp();
        });

        return $promise;
    }

    /**
     * Handle resolved IPs for a given record type
     *
     * @param list<string> $ips
     * @param Promise<mixed> $promise
     * @return list<string>
     */
    private function handleResolvedIps(array $ips, RecordType $type, Promise $promise): array
    {
        if ($this->isResolved) {
            return $ips;
        }

        unset($this->resolverPromises[$type->value]);
        $this->resolved[$type->value] = true;

        // Ensure $ips is a proper list and extract string values
        $ipsList = array_values($ips);
        /** @var list<string> $stringIps */
        $stringIps = array_filter($ipsList, 'is_string');

        $this->mixIpsIntoConnectQueue($stringIps);

        if (
            $this->nextAttemptTimerId === null &&
            $this->connectQueue !== [] &&
            $this->connectionPromises === []
        ) {
            $this->check($promise);
        }

        return $ips;
    }

    /**
     * Resolve DNS records for specified type
     *
     * @param Promise<mixed> $rejectTarget
     * @return PromiseInterface<list<string>>
     */
    private function resolve(RecordType $type, Promise $rejectTarget): PromiseInterface
    {
        return $this->resolver->resolveAll($this->host, $type)->then(
            null,
            /**
             * @param \Throwable $e
             * @return list<string>
             */
            function (\Throwable $e) use ($type, $rejectTarget): array {
                /** @var list<string> $emptyList */
                $emptyList = [];

                if ($this->isResolved) {
                    return $emptyList;
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

                return $emptyList;
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
        /** @var Promise<list<string>> $delayedPromise */
        $delayedPromise = new Promise();
        $cancelled = false;

        // Schedule timer to resolve after delay
        $this->resolutionDelayTimerId = Loop::addTimer(
            self::RESOLUTION_DELAY,
            function () use ($delayedPromise, $ips, &$cancelled): void {
                $this->resolutionDelayTimerId = null;
                // @phpstan-ignore-next-line Defensive check for cancellation
                if ($cancelled) {
                    return;
                }

                if ($this->isResolved) {
                    return;
                }
                $delayedPromise->resolve($ips);
            }
        );

        // Resolve immediately if IPv6 completes
        $ipv6Promise = $this->resolverPromises[RecordType::AAAA->value] ?? null;
        if ($ipv6Promise !== null) {
            $ipv6Promise->then(function () use ($delayedPromise, $ips, &$cancelled): void {
                // @phpstan-ignore-next-line Defensive check for cancellation
                if ($cancelled) {
                    return;
                }

                if ($this->isResolved) {
                    return;
                }

                if ($this->resolutionDelayTimerId === null) {
                    return;
                }
                Loop::cancelTimer($this->resolutionDelayTimerId);
                $this->resolutionDelayTimerId = null;
                $delayedPromise->resolve($ips);
            });
        }

        $delayedPromise->onCancel(function () use (&$cancelled): void {
            $cancelled = true;
            if ($this->resolutionDelayTimerId !== null) {
                Loop::cancelTimer($this->resolutionDelayTimerId);
                $this->resolutionDelayTimerId = null;
            }
        });

        return $delayedPromise;
    }

    /**
     * Check and start next connection attempt
     *
     * Per RFC 8305 Section 5: Connection attempts are started with a fixed delay
     * between them, regardless of whether previous attempts have failed.
     *
     * @param Promise<mixed> $promise
     */
    private function check(Promise $promise): void
    {
        if ($this->isResolved || $this->connectQueue === []) {
            return;
        }

        $ip = array_shift($this->connectQueue);
        assert(is_string($ip));

        $connectionPromise = $this->attemptConnection($ip);
        $index = \count($this->connectionPromises);
        $this->connectionPromises[$index] = $connectionPromise;

        $connectionPromise->then(
            onFulfilled: function (mixed $connection) use ($index, $promise): void {
                if ($this->isResolved) {
                    if (\is_object($connection) && method_exists($connection, 'close')) {
                        $connection->close();
                    }

                    return;
                }

                unset($this->connectionPromises[$index]);
                $this->isResolved = true;
                $this->cleanUp();
                $promise->resolve($connection);
            },
            onRejected:
            /**
             * @param \Throwable $e
             */
            function (\Throwable $e) use ($index, $ip, $promise): void {
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
                assert(is_string($message));

                if (! str_contains($ip, ':')) {
                    $this->lastError4 = $message;
                    $this->lastErrorFamily = 4;
                } else {
                    $this->lastError6 = $message;
                    $this->lastErrorFamily = 6;
                }

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
        if (
            $this->nextAttemptTimerId === null &&
            (\count($this->connectQueue) > 0 || ! $this->hasBeenResolved())
        ) {
            $this->nextAttemptTimerId = Loop::addTimer(
                self::CONNECTION_ATTEMPT_DELAY,
                function () use ($promise): void {
                    $this->nextAttemptTimerId = null;
                    if (! $this->isResolved && $this->connectQueue !== []) {
                        $this->check($promise);
                    }
                }
            );
        }
    }

    /**
     * Attempt connection to a specific IP
     *
     * @return PromiseInterface<mixed>
     */
    private function attemptConnection(string $ip): PromiseInterface
    {
        $uri = $this->buildUri($this->parts, $this->host, $ip);

        /** @var PromiseInterface<mixed> */
        return $this->connector->connect($uri);
    }

    /**
     * Mix IPs into connection queue using RFC 8305 Section 4 interleaving
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
                $ip = array_shift($ips);
                assert(is_string($ip));
                $this->connectQueue[] = $ip;
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
            assert(is_string($parts['scheme']));
            $uri .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            assert(is_string($parts['user']));
            $uri .= $parts['user'];
            if (isset($parts['pass'])) {
                assert(is_string($parts['pass']));
                $uri .= ':' . $parts['pass'];
            }
            $uri .= '@';
        }

        $uri .= str_contains($ip, ':') ? "[{$ip}]" : $ip;

        if (isset($parts['port'])) {
            $port = $parts['port'];
            assert(is_int($port) || is_string($port));
            $uri .= ':' . (string)$port;
        }

        if (isset($parts['path'])) {
            assert(is_string($parts['path']));
            $uri .= $parts['path'];
        }

        $uri .= '?hostname=' . rawurlencode($host);

        if (isset($parts['query'])) {
            assert(is_string($parts['query']));
            $uri .= '&' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            assert(is_string($parts['fragment']));
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

        foreach ($this->connectionPromises as $promise) {
            $promise->cancelChain();
        }
        $this->connectionPromises = [];

        foreach (array_reverse($this->resolverPromises) as $promise) {
            $promise->cancelChain();
        }
        $this->resolverPromises = [];

        if ($this->nextAttemptTimerId !== null) {
            Loop::cancelTimer($this->nextAttemptTimerId);
            $this->nextAttemptTimerId = null;
        }

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
        return ($this->resolved[RecordType::A->value] ?? false)
            && ($this->resolved[RecordType::AAAA->value] ?? false);
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
