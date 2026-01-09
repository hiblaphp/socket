<?php

use Hibla\EventLoop\Loop;
use Hibla\Socket\Connection;
use Hibla\Socket\Exceptions\EncryptionFailedException;
use Hibla\Socket\Internals\StreamEncryption;

function generate_temp_cert(): string
{
    $dn = [
        "countryName" => "US",
        "stateOrProvinceName" => "Test",
        "localityName" => "Test",
        "organizationName" => "Hibla",
        "organizationalUnitName" => "Testing",
        "commonName" => "127.0.0.1",
        "emailAddress" => "test@example.com"
    ];

    $privkey = openssl_pkey_new([
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new($dn, $privkey, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $privkey, 1, ['digest_alg' => 'sha256']);

    $tempDir = sys_get_temp_dir();
    $certFile = $tempDir . '/hibla_test_cert_' . uniqid() . '.pem';

    $pem = '';
    openssl_x509_export($x509, $cert);
    $pem .= $cert;
    openssl_pkey_export($privkey, $key);
    $pem .= $key;

    file_put_contents($certFile, $pem);

    return $certFile;
}

describe('StreamEncryption', function () {
    $certFile = null;
    $server = null;
    $client = null;
    $connection = null; 

    beforeEach(function () use (&$certFile) {
        $certFile = generate_temp_cert();
    });

    afterEach(function () use (&$certFile, &$server, &$client, &$connection) {
        if ($connection) {
            $connection->close();
            $connection = null;
        }
        if (is_resource($client)) fclose($client);
        if (is_resource($server)) fclose($server);
        if ($certFile && file_exists($certFile)) unlink($certFile);
    });

    it('successfully enables encryption (Server Mode)', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        stream_set_blocking($server, false);

        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
        stream_set_blocking($client, false);

        $r = [$server]; $w = null; $e = null;
        stream_select($r, $w, $e, 1);
        
        $serverSocket = stream_socket_accept($server);
        $connection = new Connection($serverSocket);
        $encryption = new StreamEncryption(isServer: true);
        
        $promise = $encryption->enable($connection);
        
        Loop::addTimer(0.01, function () use ($client) {
            stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        });

        $result = $promise->wait();

        expect($result)->toBeInstanceOf(Connection::class);
        expect($connection->encryptionEnabled)->toBeTrue();
        
        fwrite($client, "Hello Secure World");
        
        $received = '';
        $connection->on('data', function($data) use (&$received) {
            $received .= $data;
        });
        
        Loop::runOnce();
        Loop::runOnce(); 
        
        expect($received)->toBe("Hello Secure World");
    });

    it('rejects when the handshake fails (e.g. non-TLS data)', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        
        $client = stream_socket_client('tcp://' . $address);
        
        $serverSocket = stream_socket_accept($server);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        fwrite($client, "GET / HTTP/1.0\r\n\r\n");

        try {
            $promise->wait();
            test()->fail('Handshake should have failed');
        } catch (EncryptionFailedException $e) {
            expect($e->getMessage())->not->toBeEmpty();
        }
    });

    it('can be cancelled during handshake', function () use (&$certFile, &$server, &$client, &$connection) {
        $context = stream_context_create(['ssl' => ['local_cert' => $certFile]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $address = stream_socket_get_name($server, false);
        
        $client = stream_socket_client('tcp://' . $address);
        
        $serverSocket = stream_socket_accept($server);
        $connection = new Connection($serverSocket);

        $encryption = new StreamEncryption(isServer: true);
        $promise = $encryption->enable($connection);

        $promise->cancel();

        expect(fn() => $promise->wait())->toThrow(EncryptionFailedException::class, 'cancelled');
    });
});