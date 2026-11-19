<?php

declare(strict_types=1);

namespace Pig\Async;

/**
 * The certificates a TLS connection trusts, with some of the person's own added — what Node's
 * `NODE_EXTRA_CA_CERTS` does, for a company's proxy or a private CA.
 *
 * PHP takes one file of certificate authorities (`cafile`) and, given it, trusts only what it holds. So
 * the file handed to a connection is the system's own certificates and the extra ones, written
 * together to a file of its own in the temporary directory, once per extra file, and removed when
 * the process ends.
 */
final class TlsRoots
{
    /** @var array<string, string> the extra file => the bundle made from it */
    private static array $bundles = [];

    /**
     * The file of certificates this PHP trusts by default: `openssl.cafile` if it names one, else
     * OpenSSL's own — the order PHP looks in. Null when neither is a file (a system that keeps its
     * certificates only in a directory).
     */
    public static function systemFile(): ?string
    {
        $locations = openssl_get_cert_locations();

        foreach ([$locations['ini_cafile'] ?? '', $locations['default_cert_file'] ?? ''] as $file) {
            if ($file !== '' && is_file($file) && is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * A file that trusts what the system does and what is in $extra.
     *
     * @throws SocketError when $extra cannot be read or holds no certificate, or the system's file cannot be found
     */
    public static function bundleWith(string $extra): string
    {
        if (isset(self::$bundles[$extra])) {
            return self::$bundles[$extra];
        }

        $added = is_file($extra) && is_readable($extra) ? file_get_contents($extra) : false;

        if ($added === false) {
            throw new SocketError("Cannot read the extra certificates in {$extra}");
        }

        if (!str_contains($added, '-----BEGIN CERTIFICATE-----')) {
            throw new SocketError("{$extra} holds no PEM certificate");
        }

        $systemFile = self::systemFile()
            ?? throw new SocketError("Cannot find the system's certificates to add those in {$extra} to");
        $system = file_get_contents($systemFile);

        if ($system === false) {
            throw new SocketError("Cannot read the system's certificates in {$systemFile}");
        }

        $bundle = tempnam(sys_get_temp_dir(), 'pig-ca-');

        if ($bundle === false || file_put_contents($bundle, rtrim($system) . "\n" . $added) === false) {
            throw new SocketError('Cannot write the certificate bundle to the temporary directory');
        }

        register_shutdown_function(static function () use ($bundle): void {
            if (is_file($bundle)) {
                unlink($bundle);
            }
        });

        return self::$bundles[$extra] = $bundle;
    }
}
