# Hibla Socket

**Async, non-blocking TCP, TLS, and Unix domain socket library for PHP.**

Part of the [Hibla](https://github.com/hiblaphp) ecosystem. Built on top of
`hiblaphp/async`'s event loop — all I/O is non-blocking and driven by the same
loop that powers your fibers, timers, and promises.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/socket.svg?style=flat-square)](https://github.com/hiblaphp/socket/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Introduction](#introduction)
- [Quick Start](#quick-start)
- [Two Styles: Promise Chains vs Await](#two-styles-promise-chains-vs-await)

**Connections**
- [Connection Events](#connection-events)
  - [Event reference](#event-reference)
  - [Ordering guarantees](#ordering-guarantees)
  - [Connection lifecycle](#connection-lifecycle)
  - [Attaching listeners](#attaching-listeners)

**Servers**
- [SocketServer — high-level facade](#socketserver)
- [TcpServer](#tcpserver)
- [SecureServer — TLS](#secureserver)
- [UnixServer — Unix domain sockets](#unixserver)
- [FdServer — file descriptor](#fdserver)
- [LimitingServer — connection limits](#limitingserver)

**Clients**
- [Connector — high-level facade](#connector)
- [TcpConnector](#tcpconnector)
- [SecureConnector — TLS](#secureconnector)
- [UnixConnector — Unix domain sockets](#unixconnector)
- [TimeoutConnector](#timeoutconnector)
- [FixedUriConnector](#fixeduriconnector)
- [DNS Resolution](#dns-resolution)
- [Happy Eyeballs — RFC 8305](#happy-eyeballs)

**Working with connections**
- [Reading and writing](#reading-and-writing)
- [Backpressure](#backpressure)
- [Cancellation](#cancellation)
  - [Manual cancellation](#manual-cancellation)
  - [Timeouts are rejections not cancellations](#timeouts-are-rejections-not-cancellations)
  - [Structured cancellation with CancellationToken](#structured-cancellation-with-cancellationtoken)
  - [Cancellation support by connector](#cancellation-support-by-connector)
- [Mid-flight TLS upgrade](#mid-flight-tls-upgrade)
- [Address inspection](#address-inspection)

**Reference**
- [Interface summary](#interface-summary)
- [Exception reference](#exception-reference)

**Meta**
- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Installation
```bash
composer require hiblaphp/socket
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/stream`
- `hiblaphp/promise`
- `hiblaphp/event-loop`
- `hiblaphp/dns`
- `evenement/evenement`

---

## Introduction

PHP's built-in socket functions — `stream_socket_server()`,
`stream_socket_client()`, `stream_socket_accept()` — are synchronous and
blocking. `stream_socket_accept()` stalls the entire PHP thread until a client
connects. `fread()` on a socket blocks until data arrives. `stream_socket_client()`
blocks during the TCP handshake. For a single connection in a simple script this
is fine. The moment you need to handle multiple connections concurrently —
a TCP server serving hundreds of clients, a client making parallel upstream
requests, a proxy routing between two streams — blocking on any one operation
freezes everything else. The event loop cannot fire timers, cannot resume Fibers,
cannot read from other sockets while a blocking call is in progress.

The solution is to hand all socket I/O to the event loop entirely. Instead of
calling `stream_socket_accept()` and waiting, you register a read watcher on the
server socket and supply a callback. Instead of calling `stream_socket_client()`
and blocking for the handshake, you open the socket in `STREAM_CLIENT_ASYNC_CONNECT`
mode and register a write watcher — the event loop fires the callback the instant
the OS confirms the connection is established. All reads and writes go through
non-blocking streams backed by `hiblaphp/stream` watchers, so the event loop
continues driving all other activity while I/O is in flight.

`hiblaphp/socket` is that abstraction. It provides:

- **Servers** that accept connections without blocking — TCP, TLS, Unix domain
  sockets, and file descriptor inheritance for zero-downtime restarts and
  systemd socket activation.
- **Connectors** that establish connections without blocking — with automatic
  DNS resolution, Happy Eyeballs RFC 8305 dual-stack racing, TLS, configurable
  timeouts, and full cancellation support.
- **Connections** that expose an event-driven interface for reading and writing —
  with automatic backpressure tracking, mid-flight TLS upgrade, and direct pipe
  integration with `hiblaphp/stream`.

Every established connection — server-side or client-side — is a
`ConnectionInterface` backed by a `hiblaphp/stream` `DuplexResourceStream`.
Data arrives as `data` events. Writes are buffered and drained asynchronously.
Backpressure is tracked automatically via `write()`'s return value and the
`drain` event. Connections integrate directly with `hiblaphp/stream`'s `pipe()`
so you can wire a file stream to a socket, or two sockets to each other, with
one line and no manual flow control.

The library supports two coding styles throughout. Promise chains give you
maximum throughput and zero Fiber overhead — the right choice for
performance-critical paths where you are establishing thousands of connections
per second.You can use optionally `await()` from `hiblaphp/async` gives you sequential-looking code
that is easier to read and reason about — the right choice for application-level
logic where the overhead of Fiber suspension is invisible. Both styles compose
freely: you can mix them in the same codebase, the same function, or the same
`Promise::all()` call.

---

## Quick Start

### Echo server
```php
use Hibla\Socket\SocketServer;

$server = new SocketServer('tcp://127.0.0.1:8080');

$server->on('connection', function ($connection) {
    $connection->on('data', function (string $data) use ($connection) {
        $connection->write($data);
    });

    $connection->on('error', function (\Throwable $e) {
        echo "Connection error: " . $e->getMessage() . "\n";
    });
});

$server->on('error', function (\Throwable $e) {
    echo "Server error: " . $e->getMessage() . "\n";
});

echo "Listening on " . $server->getAddress() . "\n";
```

### TCP client
```php
use Hibla\Socket\Connector;
use function Hibla\await;

$connector  = new Connector();
$connection = await($connector->connect('tcp://example.com:80'));

$connection->write("GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");

$connection->on('data', function (string $data) {
    echo $data;
});

$connection->on('error', function (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
});
```

---

## Two Styles: Promise Chains vs Await

Every connector method returns a `PromiseInterface`. You can consume it using
raw promise chains or using `await()` from `hiblaphp/async`. Both styles are
fully supported — choose based on context.

`await()` suspends the current Fiber and resumes it when the promise settles,
letting you write sequential-looking code without blocking the event loop.
Promise chains are better when you need fine-grained control over branching,
when you are not inside a Fiber context, or when you want to fire multiple
connections concurrently without waiting on each one.

For performance-critical code or high-throughput scenarios — a proxy handling
thousands of simultaneous connections, a load balancer, or any path where you
are establishing connections in a tight loop — prefer pure promise chains over
`await()`. Fiber suspension and resumption carries a small but measurable
overhead per operation. At low concurrency this is invisible; at high concurrency
it accumulates. If you are benchmarking or squeezing every last RPS, remove
`await()` from the hot path and use `.then()` chains instead.

### Connecting — promise chain style
```php
use Hibla\Socket\Connector;
use Hibla\Socket\Exceptions\ConnectionFailedException;

$connector = new Connector();

$connector->connect('tcp://example.com:80')
    ->then(function ($connection) {
        $connection->on('data', function (string $data) use ($connection) {
            echo $data;
            $connection->close();
        });

        $connection->on('error', function (\Throwable $e) {
            echo "Connection error: " . $e->getMessage() . "\n";
        });

        $connection->write("GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
    })
    ->catch(function (ConnectionFailedException $e) {
        echo "Could not connect: " . $e->getMessage() . "\n";
    });
```

### Connecting — await style
```php
use Hibla\Socket\Connector;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use function Hibla\{async, await};

await(async(function () {
    $connector = new Connector();

    try {
        $connection = await($connector->connect('tcp://example.com:80'));
    } catch (ConnectionFailedException $e) {
        echo "Could not connect: " . $e->getMessage() . "\n";
        return;
    }

    $connection->write("GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");

    $connection->on('data', function (string $data) use ($connection) {
        echo $data;
        $connection->close();
    });

    $connection->on('error', function (\Throwable $e) {
        echo "Connection error: " . $e->getMessage() . "\n";
    });
}));
```

### Concurrent connections — promise chain style

Promise chains are the natural fit when firing multiple connections at once.
`Promise::all()` runs them concurrently and resolves when every connection
settles:
```php
use Hibla\Socket\Connector;
use Hibla\Promise\Promise;

$connector = new Connector();

$hosts = [
    'tcp://server-a.internal:9000',
    'tcp://server-b.internal:9000',
    'tcp://server-c.internal:9000',
];

Promise::all(array_map(
    fn(string $uri) => $connector->connect($uri)
        ->then(function ($connection) use ($uri) {
            $connection->write("PING\n");
            $connection->on('error', fn(\Throwable $e) => echo "$uri error: " . $e->getMessage() . "\n");
            return $uri . ' OK';
        })
        ->catch(fn(\Throwable $e) => $uri . ' FAILED: ' . $e->getMessage()),
    $hosts
))->then(function (array $results) {
    foreach ($results as $result) {
        echo $result . "\n";
    }
});
```

### Concurrent connections — await style
```php
use Hibla\Socket\Connector;
use Hibla\Promise\Promise;
use function Hibla\{async, await};

await(async(function () {
    $connector = new Connector();

    $hosts = [
        'tcp://server-a.internal:9000',
        'tcp://server-b.internal:9000',
        'tcp://server-c.internal:9000',
    ];

    $results = await(Promise::all(array_map(
        fn(string $uri) => $connector->connect($uri)
            ->then(function ($connection) use ($uri) {
                $connection->write("PING\n");
                $connection->on('error', fn(\Throwable $e) => echo "$uri error: " . $e->getMessage() . "\n");
                return $uri . ' OK';
            })
            ->catch(fn(\Throwable $e) => $uri . ' FAILED: ' . $e->getMessage()),
        $hosts
    )));

    foreach ($results as $result) {
        echo $result . "\n";
    }
}));
```

### TLS — promise chain style
```php
use Hibla\Socket\Connector;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Exceptions\ConnectionFailedException;

$connector = new Connector(['tls' => ['verify_peer' => true]]);

$connector->connect('tls://example.com:443')
    ->then(function ($connection) {
        echo "Connected: " . $connection->getRemoteAddress() . "\n";
        $connection->write("GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");

        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    })
    ->catch(function (EncryptionFailedException $e) {
        echo "TLS failed: " . $e->getMessage() . "\n";
    })
    ->catch(function (ConnectionFailedException $e) {
        echo "Connection failed: " . $e->getMessage() . "\n";
    });
```

### TLS — await style
```php
use Hibla\Socket\Connector;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Exceptions\ConnectionFailedException;
use function Hibla\{async, await};

await(async(function () {
    $connector = new Connector(['tls' => ['verify_peer' => true]]);

    try {
        $connection = await($connector->connect('tls://example.com:443'));
    } catch (EncryptionFailedException $e) {
        echo "TLS failed: " . $e->getMessage() . "\n";
        return;
    } catch (ConnectionFailedException $e) {
        echo "Connection failed: " . $e->getMessage() . "\n";
        return;
    }

    echo "Connected: " . $connection->getRemoteAddress() . "\n";
    $connection->write("GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");

    $connection->on('data', fn(string $data) => echo $data);
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
}));
```

---

## Connection Events

All established connections — whether from a server's `connection` event or a
connector's resolved promise — implement `ConnectionInterface` and expose the
same event API. Understanding these events before working with servers and
clients makes every example in this document easier to follow.

### Event reference

| Event | Arguments | When it fires |
| :--- | :--- | :--- |
| `data` | `string $chunk` | A chunk of data arrives from the remote end |
| `end` | — | The remote end half-closes — no more `data` events will follow |
| `drain` | — | The write buffer drops below the soft limit — safe to write again |
| `close` | — | The connection is fully closed and the resource is freed |
| `error` | `\Throwable $e` | A stream error occurred — the connection closes immediately after |

### Ordering guarantees

- `end` always fires before `close` on a clean remote half-close. If the remote
  end closes abruptly (TCP RST, process killed), `end` is skipped and `close`
  fires directly.
- `error` is always followed by `close`. The connection closes itself after
  emitting `error` — you do not need to call `close()` inside an error handler.
- After `close` fires, all listeners are removed. Any listener attached after
  `close` will never fire.
- `drain` only fires if a previous `write()` returned `false`. If the buffer
  never fills, `drain` never fires.

> **Always attach an `error` listener** on every connection. An unhandled `error`
> event on an `EventEmitter` propagates and may terminate your process.

### Connection lifecycle
```
Connection established (server 'connection' event or connector promise resolved)
       │
       ▼
  ┌──────────┐
  │  OPEN    │
  └────┬─────┘
       │
       │ data arrives from remote
       ├──────────────── emit('data', $chunk)     ← repeats for each chunk
       │
       │ write buffer exceeds soft limit
       ├──────────────── write() returns false    ← backpressure signal
       │
       │ write buffer drains below soft limit
       ├──────────────── emit('drain')            ← safe to write again
       │
       │ remote half-closes (clean EOF)
       ├──────────────── emit('end')
       │                 emit('close')            ← always follows 'end'
       │                 resource freed
       │
       │ close() called locally
       ├──────────────── emit('close')
       │                 resource freed
       │
       │ stream error (broken pipe, reset, etc.)
       └──────────────── emit('error', $e)
                         emit('close')            ← always follows 'error'
                         resource freed
```

### Attaching listeners

**Promise chain style:**
```php
$connector->connect('tcp://example.com:9000')
    ->then(function ($connection) {
        $connection->on('data', function (string $chunk) {
            echo "Received: " . $chunk;
        });

        $connection->on('end', function () {
            echo "Remote closed the write side\n";
        });

        $connection->on('drain', function () use ($connection) {
            echo "Buffer drained — resuming writes\n";
        });

        $connection->on('close', function () {
            echo "Connection fully closed\n";
        });

        $connection->on('error', function (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
        });

        $connection->write("Hello\n");
    })
    ->catch(function (\Throwable $e) {
        echo "Could not connect: " . $e->getMessage() . "\n";
    });
```

**Await style:**
```php
use function Hibla\{async, await};

await(async(function () use ($connector) {
    try {
        $connection = await($connector->connect('tcp://example.com:9000'));
    } catch (\Throwable $e) {
        echo "Could not connect: " . $e->getMessage() . "\n";
        return;
    }

    $connection->on('data', function (string $chunk) {
        echo "Received: " . $chunk;
    });

    $connection->on('end', function () {
        echo "Remote closed the write side\n";
    });

    $connection->on('drain', function () use ($connection) {
        echo "Buffer drained — resuming writes\n";
    });

    $connection->on('close', function () {
        echo "Connection fully closed\n";
    });

    $connection->on('error', function (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    });

    $connection->write("Hello\n");
}));
```

---

## Servers

### SocketServer

`SocketServer` is the recommended entry point for most use cases. It inspects
the URI scheme and instantiates the appropriate server (`TcpServer`,
`SecureServer`, or `UnixServer`) automatically.
```php
use Hibla\Socket\SocketServer;

// TCP
$server = new SocketServer('tcp://0.0.0.0:8080');

// TLS
$server = new SocketServer('tls://0.0.0.0:8443', [
    'tls' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk'   => '/path/to/key.pem',
    ],
]);

// Unix domain socket
$server = new SocketServer('unix:///var/run/app.sock');
```

The `$context` array accepts three top-level keys — `tcp`, `tls`, and `unix`
— each containing standard PHP stream context options for that transport:
```php
$server = new SocketServer('tcp://0.0.0.0:8080', [
    'tcp' => [
        'so_reuseport' => true,
        'backlog'      => 65535,
    ],
]);
```

All server types emit the same events:

| Event | Arguments | When |
| :--- | :--- | :--- |
| `connection` | `ConnectionInterface $connection` | A new client connects |
| `error` | `\Throwable $error` | A non-fatal error occurs (e.g. accept failure) |
```php
$server->on('connection', function ($connection) {
    echo "New connection from " . $connection->getRemoteAddress() . "\n";

    $connection->on('data', fn(string $data) => $connection->write($data));
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    $connection->on('close', fn() => echo "Client disconnected\n");
});

$server->on('error', function (\Throwable $e) {
    echo "Server error: " . $e->getMessage() . "\n";
});
```

---

### TcpServer

Low-level TCP server. Binds to an IP and port.
```php
use Hibla\Socket\TcpServer;

$server = new TcpServer('127.0.0.1:8080');
// or bind to all interfaces
$server = new TcpServer('0.0.0.0:8080');
// or a random available port
$server = new TcpServer('127.0.0.1:0');

echo $server->getAddress(); // tcp://127.0.0.1:43210
```

**Context options** — any `socket` stream context option is accepted:
```php
$server = new TcpServer('0.0.0.0:8080', [
    'so_reuseport' => true,
    'backlog'      => 65535,
]);
```

---

### SecureServer

Wraps a `TcpServer` and performs the TLS handshake on every incoming
connection before emitting it. Connections are only emitted after encryption
is fully established.
```php
use Hibla\Socket\TcpServer;
use Hibla\Socket\SecureServer;

$tcp    = new TcpServer('0.0.0.0:8443');
$server = new SecureServer($tcp, [
    'local_cert'        => '/path/to/cert.pem',
    'local_pk'          => '/path/to/key.pem',
    'verify_peer'       => false,
    'allow_self_signed' => true,
]);

$server->on('connection', function ($connection) {
    echo $connection->getRemoteAddress() . "\n"; // tls://...
    $connection->on('data', fn(string $data) => $connection->write($data));
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
});
```

Failed TLS handshakes emit an `error` event on the server and close the
connection — they do not crash the server.

---

### UnixServer

Listens on a Unix domain socket path.
```php
use Hibla\Socket\UnixServer;

$server = new UnixServer('/var/run/app.sock');
// or with scheme prefix
$server = new UnixServer('unix:///var/run/app.sock');
```

The socket file is created on construction and removed automatically on
`close()`. If the path already exists and is actively in use,
`AddressInUseException` is thrown. A stale socket file (no listener) is
removed and replaced automatically.

---

### FdServer

Inherits a listening socket from a parent process via a file descriptor
number. Useful for socket-activated services (systemd) or zero-downtime
restarts.
```php
use Hibla\Socket\FdServer;

// From a file descriptor number
$server = new FdServer(3);

// From a php://fd URI
$server = new FdServer('php://fd/3');
```

---

### LimitingServer

Decorates any `ServerInterface` to enforce a maximum number of concurrent
connections.
```php
use Hibla\Socket\TcpServer;
use Hibla\Socket\LimitingServer;

$tcp    = new TcpServer('0.0.0.0:8080');
$server = new LimitingServer($tcp, connectionLimit: 100);

$server->on('connection', function ($connection) {
    // At most 100 connections active simultaneously
});
```

**Two modes when the limit is reached:**
```php
// Mode 1 — Reject (default): accept and immediately close excess connections
$server = new LimitingServer($tcp, connectionLimit: 100, pauseOnLimit: false);

// Mode 2 — Pause: stop accepting at the OS level until a slot opens
$server = new LimitingServer($tcp, connectionLimit: 100, pauseOnLimit: true);
```

Pause mode provides true backpressure — the kernel's accept queue backs up
instead of dropping connections. Reject mode is better when you want to send
an explicit error response to the client before closing.

---

## Clients

### Connector

`Connector` is the recommended entry point for client connections. It handles
DNS resolution, Happy Eyeballs dual-stack racing, TLS, Unix sockets, and
timeouts behind a single `connect()` call.
```php
use Hibla\Socket\Connector;
use function Hibla\await;

$connector = new Connector();

// TCP (with automatic DNS resolution)
$conn = await($connector->connect('tcp://example.com:80'));

// TLS
$conn = await($connector->connect('tls://example.com:443'));

// Unix domain socket
$conn = await($connector->connect('unix:///var/run/app.sock'));
```

**Configuration options:**
```php
$connector = new Connector([
    // Connection timeout in seconds (default: default_socket_timeout ini)
    'timeout' => 5.0,

    // TCP context options (passed to stream_socket_client)
    'tcp' => [
        'bindto' => '192.168.1.100:0',
    ],

    // TLS context options (see https://www.php.net/manual/en/context.ssl.php)
    'tls' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'cafile'           => '/etc/ssl/certs/ca-certificates.crt',
    ],

    // DNS — true (system resolver), false (skip, IP only), array of nameservers,
    // or a ResolverInterface instance
    'dns' => ['1.1.1.1', '8.8.8.8'],

    // Happy Eyeballs RFC 8305 (true by default)
    'happy_eyeballs' => true,

    // Pre-check if IPv6 is actually routable before attempting AAAA queries
    'ipv6_precheck' => false,

    // Disable any transport entirely
    'unix' => false,
    'tls'  => false,
]);
```

---

### TcpConnector

Establishes a raw non-blocking TCP connection to an IP address. Does not
perform DNS resolution — pass a resolved IP.
```php
use Hibla\Socket\TcpConnector;
use function Hibla\await;

$connector = new TcpConnector();
$conn      = await($connector->connect('tcp://93.184.216.34:80'));
```

---

### SecureConnector

Wraps a `TcpConnector` (or any `ConnectorInterface`) and performs a TLS
handshake after the transport is established.
```php
use Hibla\Socket\TcpConnector;
use Hibla\Socket\SecureConnector;
use function Hibla\await;

$connector = new SecureConnector(new TcpConnector(), [
    'verify_peer'      => true,
    'verify_peer_name' => true,
    'cafile'           => '/etc/ssl/certs/ca-certificates.crt',
]);

$conn = await($connector->connect('tls://example.com:443'));
```

---

### UnixConnector

Connects to a Unix domain socket. The connection is established synchronously
— by the time `connect()` returns a promise, the connection is already made
and the promise is already resolved.
```php
use Hibla\Socket\UnixConnector;
use function Hibla\await;

$connector = new UnixConnector();
$conn      = await($connector->connect('unix:///var/run/app.sock'));
```

---

### TimeoutConnector

Decorates any `ConnectorInterface` and rejects the promise with
`TimeoutException` if the connection is not established within the given
number of seconds. The timeout covers the entire connection process including
DNS and TLS.
```php
use Hibla\Socket\TcpConnector;
use Hibla\Socket\TimeoutConnector;
use Hibla\Socket\Exceptions\TimeoutException;
use function Hibla\{async, await};

// Promise chain style
$connector = new TimeoutConnector(new TcpConnector(), timeout: 3.0);

$connector->connect('tcp://example.com:80')
    ->then(function ($conn) {
        $conn->write("GET / HTTP/1.0\r\n\r\n");
        $conn->on('data', fn(string $data) => echo $data);
        $conn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    })
    ->catch(function (TimeoutException $e) {
        echo "Timed out: " . $e->getMessage() . "\n";
    });

// Await style
await(async(function () {
    $connector = new TimeoutConnector(new TcpConnector(), timeout: 3.0);

    try {
        $conn = await($connector->connect('tcp://example.com:80'));
        $conn->write("GET / HTTP/1.0\r\n\r\n");
        $conn->on('data', fn(string $data) => echo $data);
        $conn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    } catch (TimeoutException $e) {
        echo "Timed out: " . $e->getMessage() . "\n";
    }
}));
```

---

### FixedUriConnector

Always connects to a pre-configured URI regardless of the URI passed to
`connect()`. Useful for proxies, tunnels, and test mocks.
```php
use Hibla\Socket\TcpConnector;
use Hibla\Socket\FixedUriConnector;
use function Hibla\await;

$connector = new FixedUriConnector(
    'tcp://proxy.internal:1080',
    new TcpConnector()
);

// All connect() calls go to the proxy regardless of the target URI
$conn = await($connector->connect('tcp://example.com:80'));
```

---

### DNS Resolution

`Connector` handles DNS automatically. Under the hood it uses `DnsConnector`
(single-stack) or `HappyEyeBallsConnector` (dual-stack, default).

If you need manual DNS resolution, inject a custom `ResolverInterface`:
```php
use Hibla\Dns\Dns;
use Hibla\Socket\Connector;

$resolver  = Dns::builder()
    ->withNameservers(['1.1.1.1', '8.8.8.8'])
    ->withCache()
    ->build();

$connector = new Connector(['dns' => $resolver]);
```

To bypass DNS entirely (IP-only environments or when you resolve yourself):
```php
$connector = new Connector(['dns' => false]);
```

---

### Happy Eyeballs

When `happy_eyeballs` is enabled (the default), `Connector` implements
RFC 8305:

- AAAA (IPv6) resolution starts immediately.
- A (IPv4) resolution starts after a 50ms delay, or immediately if AAAA
  resolves first.
- Connection attempts interleave IPv6 and IPv4 addresses from the queue.
- A 250ms delay is inserted between each attempt.
- The first successful connection wins — all others are cancelled.
```php
// Disable if your environment is IPv4-only and you want to skip the delay
$connector = new Connector(['happy_eyeballs' => false]);

// Enable IPv6 pre-check to skip AAAA entirely when IPv6 isn't routable
$connector = new Connector(['ipv6_precheck' => true]);
```

---

## Working with Connections

### Reading and writing
```php
// Write data — returns false if the internal buffer exceeds the soft limit
$connection->write("Hello, world!\n");

// Listen for incoming data
$connection->on('data', function (string $chunk) {
    echo "Received: " . $chunk;
});

// Half-close — flush remaining writes then close the write side
$connection->end();

// Full close — immediately closes both sides, discarding any buffered data
$connection->close();
```

---

### Backpressure

`write()` returns `false` when the write buffer is full. Stop writing and
wait for the `drain` event before continuing.

**Promise chain style:**
```php
$connector->connect('tcp://example.com:9000')
    ->then(function ($connection) use ($source) {
        $source->on('data', function (string $chunk) use ($connection, $source) {
            if ($connection->write($chunk) === false) {
                $source->pause();
            }
        });

        $connection->on('drain', function () use ($source) {
            $source->resume();
        });

        $connection->on('close', function () use ($source) {
            $source->close();
        });

        $connection->on('error', function (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
        });
    });
```

**Await style:**
```php
use function Hibla\{async, await};

await(async(function () use ($connector, $source) {
    $connection = await($connector->connect('tcp://example.com:9000'));

    $source->on('data', function (string $chunk) use ($connection, $source) {
        if ($connection->write($chunk) === false) {
            $source->pause();
        }
    });

    $connection->on('drain', fn() => $source->resume());
    $connection->on('close', fn() => $source->close());
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
}));
```

If you are piping a Hibla stream into a connection, backpressure is handled
automatically — no manual `pause()`/`resume()` needed:
```php
use Hibla\Stream\ReadableResourceStream;

$file = new ReadableResourceStream(fopen('/tmp/large.bin', 'rb'));
$file->pipe($connection);
```

---

### Cancellation

Every `connect()` call returns a `PromiseInterface`. You can cancel an
in-flight connection attempt by calling `cancel()` on the returned promise.
Cancellation immediately aborts the underlying operation — DNS lookups, TCP
handshakes, and TLS negotiations are all torn down cleanly with no dangling
file descriptors or event loop watchers left behind.

Cancellation in Hibla is a **distinct state** — it is not rejection. Calling
`cancel()` on a promise does not trigger any registered `then()` or `catch()`
handlers. The promise silently transitions to the cancelled state and all
pending callbacks are cleared. Use `onCancel()` on the promise if you need to
react to cancellation in a promise chain, or use `await()` with a
`CancellationToken` which throws `CancelledException` that you can catch with
a normal `try/catch`.

### Manual cancellation

In a pure promise chain there is no `await()`, so cancellation is silent —
`then()` and `catch()` never fire. Register an `onCancel()` handler before
cancelling if you need to react:
```php
use Hibla\Socket\Connector;
use Hibla\EventLoop\Loop;

$connector = new Connector();
$promise   = $connector->connect('tcp://example.com:80');

$promise->onCancel(function () {
    echo "Connection attempt was cancelled\n";
});

$promise->then(function ($connection) use (&$timerId) {
    Loop::cancelTimer($timerId);
    $connection->write("GET / HTTP/1.0\r\n\r\n");
    $connection->on('data', fn(string $data) => echo $data);
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
});

// Cancel after 2 seconds if not yet connected —
// onCancel() fires, then() and catch() do not
$timerId = Loop::addTimer(2.0, fn() => $promise->cancel());
```

### Timeouts are rejections, not cancellations

`TimeoutConnector` behaves differently from manually calling `cancel()`. When
the timeout fires, it rejects the outer promise with a `TimeoutException` —
this is a rejection and does trigger registered `catch()` handlers. Internally
it cancels the pending connection, but the promise your code receives is
rejected, not cancelled:
```php
use Hibla\Socket\TcpConnector;
use Hibla\Socket\TimeoutConnector;
use Hibla\Socket\Exceptions\TimeoutException;
use function Hibla\{async, await};

// Promise chain — catch() fires because TimeoutConnector rejects
$connector = new TimeoutConnector(new TcpConnector(), timeout: 3.0);

$connector->connect('tcp://example.com:80')
    ->then(function ($connection) {
        $connection->write("GET / HTTP/1.0\r\n\r\n");
        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    })
    ->catch(function (TimeoutException $e) {
        echo "Timed out: " . $e->getMessage() . "\n";
    });

// Await style
await(async(function () {
    $connector = new TimeoutConnector(new TcpConnector(), timeout: 3.0);

    try {
        $connection = await($connector->connect('tcp://example.com:80'));
        $connection->write("GET / HTTP/1.0\r\n\r\n");
        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    } catch (TimeoutException $e) {
        echo "Timed out: " . $e->getMessage() . "\n";
    }
}));
```

### Structured cancellation with CancellationToken

For coordinating cancellation across multiple connections or operations from a
single control point, use `CancellationTokenSource` from
`hiblaphp/cancellation`. The source owns the cancel signal — you pass the
readonly `$token` into operations and call `cancel()` on the source when you
want everything to stop.

**Single connection — await style**

When using `await($promise, $token)`, the token automatically tracks the
promise — no manual `track()` needed. If the token is cancelled while
`await()` is suspended, `CancelledException` is thrown:
```php
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Connector;
use function Hibla\{async, await};

$cts       = new CancellationTokenSource();
$connector = new Connector();

await(async(function () use ($cts, $connector) {
    try {
        $connection = await($connector->connect('tcp://example.com:80'), $cts->token);

        $connection->write("GET / HTTP/1.0\r\n\r\n");
        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    } catch (CancelledException $e) {
        echo "Connection cancelled\n";
    }
}));

// Cancel from anywhere — the connect promise is cancelled synchronously
$cts->cancel();
```

**Single connection — promise chain style**

In a promise chain you must call `$token->track()` manually. Since
cancellation is not rejection, `catch()` does not fire — use `onCancel()`
to react:
```php
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Socket\Connector;

$cts       = new CancellationTokenSource();
$connector = new Connector();

$promise = $connector->connect('tcp://example.com:80');

// Manually track — token will cancel this promise when $cts->cancel() is called
$cts->token->track($promise);

$promise->onCancel(function () {
    echo "Connection cancelled\n";
});

$promise->then(function ($connection) {
    $connection->write("GET / HTTP/1.0\r\n\r\n");
    $connection->on('data', fn(string $data) => echo $data);
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
});

// then() and catch() do not fire — only onCancel() does
$cts->cancel();
```

**Multiple concurrent connections**

One token cancels all tracked connections at once regardless of which phase
each is in — DNS, TCP handshake, or TLS:
```php
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Promise;
use Hibla\Socket\Connector;
use function Hibla\{async, await};

$cts       = new CancellationTokenSource();
$connector = new Connector();

$hosts = [
    'tcp://server-a.internal:9000',
    'tcp://server-b.internal:9000',
    'tcp://server-c.internal:9000',
];

await(async(function () use ($cts, $connector, $hosts) {
    try {
        $connections = await(Promise::all(array_map(
            fn(string $uri) => $connector->connect($uri)
                ->then(function ($connection) use ($uri) {
                    $connection->write("PING\n");
                    $connection->on('error', fn(\Throwable $e) => echo "$uri error: " . $e->getMessage() . "\n");
                    return $connection;
                }),
            $hosts
        )), $cts->token);

        echo "All " . count($connections) . " connections established\n";
    } catch (CancelledException $e) {
        echo "All connections cancelled\n";
    }
}));

// Cancels all three connect promises at once — synchronously
$cts->cancel();
```

**Combining user abort and timeout**

`createLinkedTokenSource()` creates a token that cancels when any of the
linked tokens fires — the standard way to combine a user-initiated abort
with a hard deadline:
```php
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Socket\Connector;
use function Hibla\{async, await};

$userCts    = new CancellationTokenSource();      // user clicks abort
$timeoutCts = new CancellationTokenSource(10.0);  // 10 second hard ceiling

// Cancels if user aborts OR 10 seconds elapse — whichever comes first
$linkedCts = CancellationTokenSource::createLinkedTokenSource(
    $userCts->token,
    $timeoutCts->token
);

$connector = new Connector();

await(async(function () use ($linkedCts, $connector) {
    try {
        $connection = await(
            $connector->connect('tcp://example.com:80'),
            $linkedCts->token
        );

        $connection->write("GET / HTTP/1.0\r\n\r\n");
        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    } catch (CancelledException $e) {
        echo "Cancelled — either user aborted or 10s timeout hit\n";
    }
}));

// Wire to your UI abort button
$abortButton->onClick(fn() => $userCts->cancel());
```

**Optional token with `CancellationToken::none()`**

If you are writing a function that should optionally support cancellation,
use `CancellationToken::none()` as the default. All token methods work
correctly on it without any null checks — `track()` is a safe no-op and
`throwIfCancelled()` never throws:
```php
use Hibla\Cancellation\CancellationToken;
use Hibla\Socket\Connector;
use function Hibla\{async, await};

function connectToService(
    string $uri,
    CancellationToken $token = null
): \Hibla\Promise\Interfaces\PromiseInterface {
    $token ??= CancellationToken::none();

    $connector = new Connector();
    $promise   = $connector->connect($uri);

    $token->track($promise); // safe no-op when token is none()

    return $promise;
}

// Works without a token
await(async(function () {
    $conn = await(connectToService('tcp://example.com:80'));
    $conn->write("PING\n");
    $conn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
}));

// Works with a token too
await(async(function () use ($cts) {
    try {
        $conn = await(connectToService('tcp://example.com:80', $cts->token));
        $conn->write("PING\n");
        $conn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    } catch (CancelledException $e) {
        echo "Cancelled\n";
    }
}));
```

### Cancellation support by connector

Not every connector supports cancellation. The table below documents which
connectors respond to `cancel()` and what happens when you call it.

| Connector | Cancellable | What cancellation does |
| :--- | :---: | :--- |
| `TcpConnector` | ✅ | Removes the write watcher and closes the socket immediately |
| `SecureConnector` | ✅ | Cancels the pending TCP connection or TLS handshake, whichever is in flight |
| `DnsConnector` | ✅ | Cancels the DNS lookup if still resolving, or the connection attempt if DNS already completed |
| `HappyEyeBallsConnector` | ✅ | Cancels all pending resolver promises, connection attempts, and clears all internal timers |
| `TimeoutConnector` | ✅ | Cancels the timeout timer and propagates cancellation to the underlying connector |
| `FixedUriConnector` | ✅ | Delegates to the underlying connector — inherits its cancellation behaviour |
| `Connector` (facade) | ✅ | Delegates to whichever connector handles the URI scheme |
| `UnixConnector` | ❌ | Not cancellable — see note below |

**`UnixConnector` is not cancellable.** Unix domain socket connections are
established synchronously via a single `stream_socket_client()` call. By the
time `connect()` returns a promise, the connection is already established and
the promise is already resolved — there is nothing in flight to cancel.
Calling `cancel()` on the returned promise is a no-op. Passing a
`CancellationToken` to `await()` with a `UnixConnector` promise is equally a
no-op — the promise is already settled before the token has a chance to fire.

If you need to enforce a time limit on a Unix socket connection, wrap it in a
`TimeoutConnector`. The timeout fires as a rejection — not a cancellation —
so `catch()` handlers fire normally:
```php
use Hibla\Socket\UnixConnector;
use Hibla\Socket\TimeoutConnector;
use Hibla\Socket\Exceptions\TimeoutException;
use function Hibla\{async, await};

// Promise chain
$connector = new TimeoutConnector(new UnixConnector(), timeout: 2.0);

$connector->connect('unix:///var/run/app.sock')
    ->then(function ($connection) {
        $connection->write("PING\n");
        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    })
    ->catch(function (TimeoutException $e) {
        echo "Unix socket timed out: " . $e->getMessage() . "\n";
    });

// Await style
await(async(function () {
    $connector = new TimeoutConnector(new UnixConnector(), timeout: 2.0);

    try {
        $connection = await($connector->connect('unix:///var/run/app.sock'));
        $connection->write("PING\n");
        $connection->on('data', fn(string $data) => echo $data);
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
    } catch (TimeoutException $e) {
        echo "Unix socket timed out: " . $e->getMessage() . "\n";
    }
}));
```

---

### Mid-flight TLS upgrade

Useful for protocols that start in plaintext and upgrade to TLS (MySQL, SMTP
STARTTLS, PostgreSQL).

**Server side — promise chain style:**
```php
$server->on('connection', function ($connection) {
    $connection->on('data', function (string $data) use ($connection) {
        if (str_contains($data, 'STARTTLS')) {
            $connection->enableEncryption([
                'local_cert' => '/path/to/cert.pem',
                'local_pk'   => '/path/to/key.pem',
            ], isServer: true)
            ->then(function ($secureConn) {
                $secureConn->write("+OK Begin TLS\r\n");
                $secureConn->on('data', fn(string $data) => echo "Secure: " . $data);
                $secureConn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
            })
            ->catch(function (\Throwable $e) use ($connection) {
                echo "TLS upgrade failed: " . $e->getMessage() . "\n";
                $connection->close();
            });
        }
    });

    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
});
```

**Server side — await style:**
```php
use Hibla\Socket\Exceptions\EncryptionFailedException;
use function Hibla\async;

$server->on('connection', function ($connection) {
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");

    $connection->on('data', function (string $data) use ($connection) {
        if (str_contains($data, 'STARTTLS')) {
            async(function () use ($connection) {
                try {
                    $secureConn = await($connection->enableEncryption([
                        'local_cert' => '/path/to/cert.pem',
                        'local_pk'   => '/path/to/key.pem',
                    ], isServer: true));
                } catch (EncryptionFailedException $e) {
                    echo "TLS upgrade failed: " . $e->getMessage() . "\n";
                    $connection->close();
                    return;
                }

                $secureConn->write("+OK Begin TLS\r\n");
                $secureConn->on('data', fn(string $data) => echo "Secure: " . $data);
                $secureConn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
            });
        }
    });
});
```

**Client side — promise chain style:**
```php
$connector->connect('tcp://mail.example.com:25')
    ->then(function ($connection) {
        $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");

        $connection->on('data', function (string $data) use ($connection) {
            if (str_contains($data, 'STARTTLS')) {
                $connection->write("STARTTLS\r\n");
                $connection->enableEncryption(['verify_peer' => true])
                    ->then(fn($conn) => $conn->write("EHLO client.example.com\r\n"))
                    ->catch(fn(\Throwable $e) => echo "TLS failed: " . $e->getMessage() . "\n");
            }
        });
    });
```

**Client side — await style:**
```php
use Hibla\Socket\Exceptions\EncryptionFailedException;
use function Hibla\{async, await};

await(async(function () use ($connector) {
    $connection = await($connector->connect('tcp://mail.example.com:25'));
    $connection->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");

    // ... wait for 220 greeting, send EHLO, receive STARTTLS capability ...

    try {
        $secureConn = await($connection->enableEncryption(['verify_peer' => true]));
    } catch (EncryptionFailedException $e) {
        echo "TLS upgrade failed: " . $e->getMessage() . "\n";
        return;
    }

    $secureConn->write("EHLO client.example.com\r\n");
    $secureConn->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");
}));
```

---

### Address inspection
```php
$connection->getRemoteAddress(); // tcp://93.184.216.34:43210
$connection->getLocalAddress();  // tcp://192.168.1.100:54321

// TLS connections report the scheme correctly
$connection->getRemoteAddress(); // tls://93.184.216.34:443

// Unix connections
$connection->getRemoteAddress(); // unix:///var/run/app.sock
```

---

## Interface summary

| Interface | Implemented by |
| :--- | :--- |
| `ServerInterface` | `TcpServer`, `UnixServer`, `SecureServer`, `FdServer`, `SocketServer`, `LimitingServer` |
| `ConnectorInterface` | `TcpConnector`, `UnixConnector`, `SecureConnector`, `DnsConnector`, `HappyEyeBallsConnector`, `TimeoutConnector`, `FixedUriConnector`, `Connector` |
| `ConnectionInterface` | `Connection` |

Type-hint against the interfaces rather than concrete classes:
```php
use Hibla\Socket\Interfaces\ConnectorInterface;
use Hibla\Socket\Interfaces\ServerInterface;
use Hibla\Socket\Interfaces\ConnectionInterface;
```

---

## Exception reference

All exceptions extend `Hibla\Socket\Exceptions\SocketException` which extends
`\RuntimeException`.

| Exception | When it is thrown |
| :--- | :--- |
| `ConnectionFailedException` | TCP handshake failed, DNS lookup failed, or connection was refused |
| `TimeoutException` | Connection attempt exceeded the configured timeout (extends `ConnectionFailedException`) |
| `EncryptionFailedException` | TLS handshake failed or connection was lost during handshake |
| `InvalidUriException` | Malformed or unsupported URI passed to a connector or server |
| `BindFailedException` | Server failed to bind to the given address (port in use, bad path, invalid FD) |
| `AddressInUseException` | Unix socket path is already actively in use (extends `BindFailedException`) |
| `AcceptFailedException` | `stream_socket_accept()` failed on an otherwise healthy server |
```php
use Hibla\Socket\Exceptions\ConnectionFailedException;
use Hibla\Socket\Exceptions\TimeoutException;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use function Hibla\{async, await};

// Promise chain style
$connector->connect('tls://example.com:443')
    ->then(fn($connection) => $connection->write("GET / HTTP/1.0\r\n\r\n"))
    ->catch(fn(TimeoutException $e)          => print("Timed out: "       . $e->getMessage() . "\n"))
    ->catch(fn(EncryptionFailedException $e) => print("TLS failed: "      . $e->getMessage() . "\n"))
    ->catch(fn(ConnectionFailedException $e) => print("Connect failed: "  . $e->getMessage() . "\n"));

// Await style
await(async(function () use ($connector) {
    try {
        $connection = await($connector->connect('tls://example.com:443'));
        $connection->write("GET / HTTP/1.0\r\n\r\n");
    } catch (TimeoutException $e) {
        echo "Timed out: " . $e->getMessage() . "\n";
    } catch (EncryptionFailedException $e) {
        echo "TLS failed: " . $e->getMessage() . "\n";
    } catch (ConnectionFailedException $e) {
        echo "Connect failed: " . $e->getMessage() . "\n";
    }
}));
```

---

## Development
```bash
git clone https://github.com/hiblaphp/socket.git
cd socket
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by [ReactPHP Socket](https://github.com/reactphp/socket).
  If you are familiar with ReactPHP's socket API, Hibla's will feel immediately
  familiar — with the addition of native promise-based methods, Fiber-aware
  `await()` support, Happy Eyeballs dual-stack connection racing out of the box,
  and first-class structured cancellation via `hiblaphp/cancellation`.
- **Event Emitter:** Built on [evenement/evenement](https://github.com/igorw/evenement).
- **Stream Layer:** Built on [hiblaphp/stream](https://github.com/hiblaphp/stream).
- **Event Loop Integration:** Powered by [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Promise Integration:** Built on [hiblaphp/promise](https://github.com/hiblaphp/promise).
- **DNS Resolution:** Powered by [hiblaphp/dns](https://github.com/hiblaphp/dns).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.