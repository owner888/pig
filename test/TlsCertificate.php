<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A certificate for `127.0.0.1` made for the test run, and the server side of a TLS handshake on the loop.
 *
 * What the client trusts it by is `Socket::useExtraCaFile()` — the file is certificate and key in one,
 * and a self-signed certificate is its own authority.
 */
final class TlsCertificate
{
    private static ?string $file = null;

    /** The PEM file: certificate and private key. Removed when the process ends. */
    public static function file(): string
    {
        if (self::$file !== null) {
            return self::$file;
        }

        $config = tempnam(sys_get_temp_dir(), 'pig-cnf-');
        $pem = tempnam(sys_get_temp_dir(), 'pig-pem-');

        if ($config === false || $pem === false) {
            throw new RuntimeException('Cannot make a temporary file for the test certificate');
        }

        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=CA:TRUE\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = $key === false ? false : openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $config]);
        $certificate = $request === false || $request === true ? false : openssl_csr_sign($request, null, $key, 1, ['config' => $config, 'x509_extensions' => 'ext']);

        if ($key === false || $certificate === false || !openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('Cannot make the test certificate: ' . openssl_error_string());
        }

        file_put_contents($pem, $certificatePem . $keyPem);
        register_shutdown_function(static function () use ($config, $pem): void {
            unlink($config);
            unlink($pem);
        });

        return self::$file = $pem;
    }

    /**
     * Do the server's side of the handshake on an accepted connection, without blocking the loop, and
     * call $ready when it is done.
     *
     * @param resource $connection
     * @param string|null $alpn the protocol to pick when the client offers it; null offers none
     * @param Closure(resource): void $ready
     * @return string the watcher doing it, for the caller to take off the loop with the rest
     */
    public static function handshake(mixed $connection, ?string $alpn, Closure $ready): string
    {
        stream_context_set_option($connection, 'ssl', 'local_cert', self::file());

        if ($alpn !== null) {
            stream_context_set_option($connection, 'ssl', 'alpn_protocols', $alpn);
        }

        $watcher = null;
        $watcher = Loop::get()->onReadable($connection, static function ($peer) use (&$watcher, $ready): void {
            $done = stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

            if ($done === 0) {
                return;
            }

            Loop::get()->cancel((string) $watcher);

            if ($done === true) {
                $ready($peer);
            }
        });

        return (string) $watcher;
    }
}
