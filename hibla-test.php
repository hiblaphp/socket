<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\Socket\Connector;
use Hibla\Promise\Promise;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;

class SimpleHttpClient
{
    private readonly Connector $connector;
    private static int $requestCounter = 0;

    public function __construct(?array $options = null)
    {
        $this->connector = new Connector($options ?? [
            'dns' => true,
            'happy_eyeballs' => true,
            'ipv6_precheck' => true,
        ]);
    }

    /**
     * Perform a GET request to the specified URL
     *
     * @return PromiseInterface<object{status: int, headers: array<string, string>, body: string, json(): mixed}>
     */
    public function get(string $url): PromiseInterface
    {
        $requestId = ++self::$requestCounter;
        
        $parts = parse_url($url);

        if (!$parts || !isset($parts['host'])) {
            return Promise::rejected(new InvalidArgumentException('Invalid URL'));
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $uri = ($scheme === 'https' ? 'tls' : 'tcp') . "://{$host}:{$port}";

        return $this->connector->connect($uri)
            ->then(function($connection) use ($host, $path, $query, $requestId, $url) {
                return $this->sendRequest($connection, $host, $path, $query, $requestId);
            })
            ->catch(function($error) use ($requestId, $url) {
                throw $error;
            });
    }

    /**
     * Send HTTP request and handle response using event-driven approach
     *
     * @return PromiseInterface<object{status: int, headers: array<string, string>, body: string, json(): mixed}>
     */
    private function sendRequest(
        ConnectionInterface $connection,
        string $host,
        string $path,
        string $query,
        int $requestId
    ): PromiseInterface {
        $promise = new Promise();
        $responseData = '';
        $responseReceived = false;

        $request = "GET {$path}{$query} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "User-Agent: Hibla-HTTP-Client/1.0\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";

        $connection->on('data', function ($chunk) use (&$responseData) {
            $responseData .= $chunk;
        });

        $connection->on('end', function () use (&$responseData, $promise, $connection, &$responseReceived) {
            if ($responseReceived) {
                return;
            }
            $responseReceived = true;

            try {
                $parsed = $this->parseResponse($responseData);
                $promise->resolve($parsed);
            } catch (\Throwable $e) {
                $promise->reject($e);
            } finally {
                $connection->close();
            }
        });

        $connection->on('close', function () use (&$responseData, $promise, &$responseReceived) {
            if ($responseReceived) {
                return;
            }
            $responseReceived = true;

            if ($responseData !== '') {
                try {
                    $parsed = $this->parseResponse($responseData);
                    $promise->resolve($parsed);
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            }
        });

        $connection->on('error', function ($error) use ($promise, $connection, &$responseReceived) {
            if ($responseReceived) {
                return;
            }
            $responseReceived = true;

            $connection->close();
            $promise->reject($error);
        });

        $connection->write($request);

        return $promise;
    }

    /**
     * Parse HTTP response into structured data with anonymous class
     *
     * @return object{status: int, headers: array<string, string>, body: string, json(): mixed}
     */
    private function parseResponse(string $response): object
    {
        $parts = explode("\r\n\r\n", $response, 2);
        $headerLines = explode("\r\n", $parts[0]);
        $body = $parts[1] ?? '';

        $statusLine = array_shift($headerLines);
        preg_match('/HTTP\/\d\.\d (\d+)/', $statusLine, $matches);
        $status = (int)($matches[1] ?? 200);

        $headers = [];
        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        if (
            isset($headers['Transfer-Encoding']) &&
            $headers['Transfer-Encoding'] === 'chunked'
        ) {
            $body = $this->decodeChunked($body);
        }

        return new class($status, $headers, $body) {
            public function __construct(
                public readonly int $status,
                public readonly array $headers,
                public readonly string $body
            ) {}

            public function json(bool $associative = false): mixed
            {
                return json_decode($this->body, $associative);
            }

            public function isJson(): bool
            {
                $contentType = $this->headers['Content-Type'] ?? '';
                return str_contains($contentType, 'application/json');
            }

            public function isSuccess(): bool
            {
                return $this->status >= 200 && $this->status < 300;
            }
        };
    }

    /**
     * Decode chunked transfer encoding
     */
    private function decodeChunked(string $data): string
    {
        $decoded = '';
        $offset = 0;

        while ($offset < strlen($data)) {
            $crlfPos = strpos($data, "\r\n", $offset);
            if ($crlfPos === false) break;

            $chunkSize = hexdec(substr($data, $offset, $crlfPos - $offset));
            if ($chunkSize === 0) break;

            $offset = $crlfPos + 2;
            $decoded .= substr($data, $offset, $chunkSize);
            $offset += $chunkSize + 2;
        }

        return $decoded;
    }
}

$client = new SimpleHttpClient();

$start = microtime(true);

$client->get('https://httpbin.org/delay/10')
    ->then(function ($response) {
        echo "✓ Delay 1 - SUCCESS (HTTP {$response->status})\n";
    })
    ->catch(function (\Throwable $e) {
        echo "✗ Delay 1 - FAILED: {$e->getMessage()}\n";
    });

