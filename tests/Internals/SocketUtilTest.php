<?php

declare(strict_types=1);

use Hibla\Socket\Exceptions\AcceptFailedException;
use Hibla\Socket\Internals\SocketUtil;

describe('Socket Util', function () {
    $sockets = [];

    afterEach(function () use (&$sockets) {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        $sockets = [];
    });

    it('successfully accepts a new connection from a listening socket', function () use (&$sockets) {
        $server = create_listening_server($sockets);
        $address = stream_socket_get_name($server, false);

        $client = @stream_socket_client('tcp://' . $address, $errno, $errstr, 1);
        expect($client)->toBeResource();
        $sockets[] = $client;

        $read = [$server];
        $write = null;
        $except = null;
        $numChanged = stream_select($read, $write, $except, 1);
        expect($numChanged)->toBe(1, 'Test server did not receive a connection within 1 second.');

        $newConnection = SocketUtil::accept($server);
        $sockets[] = $newConnection;

        expect($newConnection)->toBeResource();
    });

    it('throws AcceptFailedException when trying to accept from a closed socket', function () {
        $server = @stream_socket_server('127.0.0.1:0');
        expect($server)->toBeResource();
        fclose($server);

        $exception = null;
        try {
            SocketUtil::accept($server);
        } catch (AcceptFailedException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(AcceptFailedException::class);
        $message = $exception->getMessage();
        expect(
            str_contains($message, 'supplied resource is not a valid stream resource') ||
            str_contains($message, 'must be an open stream resource')
        )->toBeTrue('Expected error message about invalid/closed stream resource');
    });

    it('throws AcceptFailedException when trying to accept from a non-socket resource', function () use (&$sockets) {
        $fileResource = tmpfile();
        expect($fileResource)->toBeResource();
        $sockets[] = $fileResource;

        expect(fn () => SocketUtil::accept($fileResource))
            ->toThrow(AcceptFailedException::class)
        ;
    });

    describe('getLastSocketError', function () {
        it('is tested indirectly when a socket operation fails', function () {
            $server = @stream_socket_server('127.0.0.1:0');
            fclose($server);

            try {
                SocketUtil::accept($server);
                test()->fail('AcceptFailedException was not thrown as expected.');
            } catch (AcceptFailedException $e) {
                $message = $e->getMessage();
                expect(
                    str_contains($message, 'supplied resource is not a valid stream resource') ||
                    str_contains($message, 'must be an open stream resource')
                )->toBeTrue('Expected error message about invalid/closed stream resource');
                expect(is_int($e->getCode()))->toBeTrue();
            }
        });
    });
});