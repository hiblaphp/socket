<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Connector;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

class ReactHttpClient
{
    private readonly Connector $connector;
    private static int $requestCounter = 0;

    public function __construct(?array $options = null)
    {
        $this->connector = new Connector(
            $options ?? [
                'dns' => true,
                'happy_eyeballs' => true,
            ]
        );
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
            return \React\Promise\reject(new InvalidArgumentException('Invalid URL'));
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
        return new Promise(function ($resolve, $reject) use ($connection, $host, $path, $query, $requestId) {
            $responseData = '';
            $responseReceived = false;

            $request = "GET {$path}{$query} HTTP/1.1\r\n";
            $request .= "Host: {$host}\r\n";
            $request .= "User-Agent: ReactPHP-HTTP-Client/1.0\r\n";
            $request .= "Connection: close\r\n";
            $request .= "\r\n";

            $connection->on('data', function ($chunk) use (&$responseData) {
                $responseData .= $chunk;
            });

            $connection->on('end', function () use (&$responseData, $resolve, $reject, $connection, &$responseReceived) {
                if ($responseReceived) {
                    return;
                }
                $responseReceived = true;

                try {
                    $parsed = $this->parseResponse($responseData);
                    $resolve($parsed);
                } catch (\Throwable $e) {
                    $reject($e);
                } finally {
                    $connection->close();
                }
            });

            $connection->on('close', function () use (&$responseData, $resolve, $reject, &$responseReceived) {
                if ($responseReceived) {
                    return;
                }
                $responseReceived = true;

                if ($responseData !== '') {
                    try {
                        $parsed = $this->parseResponse($responseData);
                        $resolve($parsed);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
            });

            $connection->on('error', function ($error) use ($reject, $connection, &$responseReceived) {
                if ($responseReceived) {
                    return;
                }
                $responseReceived = true;

                $connection->close();
                $reject($error);
            });

            $connection->write($request);
        });
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

$client = new ReactHttpClient();

$start = microtime(true);

$promises = [
    $client->get('https://jsonplaceholder.typicode.com/todos/1'),
    $client->get('https://jsonplaceholder.typicode.com/todos/2'),
    $client->get('https://jsonplaceholder.typicode.com/todos/3'),
    $client->get('https://jsonplaceholder.typicode.com/todos/4'),
    $client->get('https://jsonplaceholder.typicode.com/todos/5'),
];

\React\Promise\all($promises)->then(
    function ($responses) use ($start) {
        foreach ($responses as $index => $response) {
            $todoNumber = $index + 1;
            
            if ($response->isSuccess()) {
                echo "✓ Todo #{$todoNumber} - SUCCESS (HTTP {$response->status})\n";
                
                // if ($response->isJson()) {
                //     $data = $response->json(true);
                //     echo "  Title: {$data['title']}\n";
                //     echo "  Completed: " . ($data['completed'] ? 'Yes' : 'No') . "\n";
                // } else {
                //     echo "  Body: " . substr($response->body, 0, 100) . "...\n";
                // }
            } else {
                echo "✗ Todo #{$todoNumber} - FAILED (HTTP {$response->status})\n";
                echo "  Body: " . substr($response->body, 0, 100) . "\n";
            }
            echo "\n";
        }

        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Total elapsed time: {$elapsed} seconds\n";
        echo "Average time per request: " . round($elapsed / 5, 2) . " seconds\n";
        echo "(All 5 requests ran concurrently!)\n";
    },
    function (\Throwable $e) {
        echo "✗ Request failed: {$e->getMessage()}\n";
    }
);