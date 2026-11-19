<?php

declare(strict_types=1);

namespace Pig\Async\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\SocketError;
use Pig\Async\TlsRoots;
use Pig\Test\AssertsThrows;
use Pig\Test\TlsCertificate;

final class TlsRootsTest extends TestCase
{
    use AssertsThrows;

    public function testTheBundleHoldsTheSystemsCertificatesAndTheExtraOnes(): void
    {
        $system = TlsRoots::systemFile();

        if ($system === null) {
            self::markTestSkipped('this PHP has no file of system certificates');
        }

        $bundle = TlsRoots::bundleWith(TlsCertificate::file());
        $text = (string) file_get_contents($bundle);

        $this->assertSame(substr_count((string) file_get_contents($system), '-----BEGIN CERTIFICATE-----') + 1, substr_count($text, '-----BEGIN CERTIFICATE-----'));
        $this->assertSame($bundle, TlsRoots::bundleWith(TlsCertificate::file()), 'one bundle per extra file');
    }

    public function testAFileThatCannotBeUsedSaysWhy(): void
    {
        $this->assertThrows(SocketError::class, static fn () => TlsRoots::bundleWith('/nonexistent/ca.pem'), 'Cannot read the extra certificates');

        $empty = (string) tempnam(sys_get_temp_dir(), 'pig-ca-test-');
        file_put_contents($empty, 'not a certificate');

        try {
            $this->assertThrows(SocketError::class, static fn () => TlsRoots::bundleWith($empty), 'holds no PEM certificate');
        } finally {
            unlink($empty);
        }
    }
}
