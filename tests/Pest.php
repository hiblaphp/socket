<?php

use Hibla\EventLoop\Loop;

uses()
    ->beforeEach(function () {
        Loop::reset();
    })
    ->afterEach(function () {
        Loop::stop();
        Loop::reset();
    })
    ->in(__DIR__)
;

function create_listening_server(array &$sockets): mixed
{
    $server = @stream_socket_server('127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        test()->skip("Could not create a test server: {$errstr}");
    }
    stream_set_blocking($server, false);
    $sockets[] = $server;

    return $server;
}

function get_free_port(): int
{
    $socket = @stream_socket_server('127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        test()->skip("Could not find a free port: {$errstr}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr(strrchr($address, ':'), 1);
}

function create_listening_socket(string $address): mixed
{
    $socket = @stream_socket_server($address, $errno, $errstr);

    if ($socket === false) {
        test()->skip("Could not create listening socket: {$errstr}");
    }

    return $socket;
}

function get_fd_from_socket(mixed $socket): int
{
    $meta = stream_get_meta_data($socket);

    if (PHP_OS_FAMILY === 'Windows') {
        test()->skip('FD extraction not reliably supported on Windows');
    }

    preg_match('/\d+/', $meta['uri'], $matches);
    return (int) ($matches[0] ?? -1);
}

function get_next_free_fd(): int
{
    $tmp = tmpfile();

    $dir = @scandir('/dev/fd');
    if ($dir === false) {
        throw new BadMethodCallException('Not supported on your platform because /dev/fd is not readable');
    }

    $stat = fstat($tmp);
    $ino = (int) $stat['ino'];

    foreach ($dir as $file) {
        $stat = @stat('/dev/fd/' . $file);
        if (isset($stat['ino']) && $stat['ino'] === $ino) {
            return (int) $file;
        }
    }

    throw new UnderflowException('Could not locate file descriptor for this resource');
}