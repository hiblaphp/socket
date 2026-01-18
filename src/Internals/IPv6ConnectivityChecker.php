<?php

declare(strict_types=1);

namespace Hibla\Socket\Internals;

/**
 * Internal utility to detect if the environment has a routable IPv6 stack.
 * Results are cached to avoid repeated socket operations.
 * 
 * @internal
 */
final class IPv6ConnectivityChecker
{
    /**
     *  Cache TTL in seconds 
     */
    private const int CACHE_TTL = 60;

    /**
     * Cloudflare Public DNS IPv6 (2606:4700:4700::1111)
     */
    private const string IPV6_TEST_ADDR = '2606:4700:4700::1111';

    private static ?bool $routable = null;

    private static ?int $lastCheck = null;

    /**
     *  @var bool|null Override for testing purposes 
     */
    private static ?bool $forced = null;

    public static function isRoutable(): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }

        $now = time();

        if (
            self::$routable !== null &&
            self::$lastCheck !== null &&
            $now - self::$lastCheck < self::CACHE_TTL
        ) {
            return self::$routable;
        }

        self::$lastCheck = $now;
        self::$routable = self::checkConnectivity();

        return self::$routable;
    }

    private static function checkConnectivity(): bool
    {
        $remote = 'udp://[' . self::IPV6_TEST_ADDR . ']:53';

        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT
        );

        if ($socket !== false) {
            fclose($socket);
            return true;
        }

        return false;
    }

    /**
     * Force the checker to return a specific boolean value.
     * Useful for unit testing to simulate IPv6 availability.
     * 
     * @param bool|null $routable Pass null to disable the force override.
     */
    public static function forceValue(?bool $routable): void
    {
        self::$forced = $routable;
    }

    /**
     * Resets the cache and removes any forced values.
     */
    public static function reset(): void
    {
        self::$routable = null;
        self::$lastCheck = null;
        self::$forced = null;
    }
}
