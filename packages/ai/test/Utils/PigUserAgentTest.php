<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\PigUserAgent;

/**
 * Node's `os.release()`, from what PHP reports — tested with the values injected, so the Windows
 * arm is exercised on a machine that is not Windows.
 */
final class PigUserAgentTest extends TestCase
{
    public function testWindowsGetsTheBuildNodeReportsAfterMajorAndMinor(): void
    {
        // `php_uname('r')` on Windows 11 is `10.0`; the build is in `php_uname('v')`. Node's
        // `os.release()` (libuv's `RtlGetVersion()`) is `10.0.22631`.
        $this->assertSame('10.0.22631', PigUserAgent::releaseFrom('Windows', '10.0', 'build 22631 (Windows 11 Pro)'));
        $this->assertSame('6.3.9600', PigUserAgent::releaseFrom('Windows', '6.3', 'Build 9600 (Windows 8.1)'));
    }

    public function testAWindowsVersionWithNoBuildInItIsTheReleaseAsItIs(): void
    {
        $this->assertSame('10.0', PigUserAgent::releaseFrom('Windows', '10.0', 'Windows Server 2022'));
    }

    public function testEverywhereElseItIsUnameRWhateverTheVersionSays(): void
    {
        $this->assertSame('6.8.0-45-generic', PigUserAgent::releaseFrom('Linux', '6.8.0-45-generic', '#45-Ubuntu SMP build 12 PREEMPT_DYNAMIC'));
        $this->assertSame('24.1.0', PigUserAgent::releaseFrom('Darwin', '24.1.0', 'Darwin Kernel Version 24.1.0'));
    }

    public function testTheRealReleaseIsWhatTheInjectedOneComputesForThisMachine(): void
    {
        $this->assertSame(PigUserAgent::releaseFrom(PHP_OS_FAMILY, php_uname('r'), php_uname('v')), PigUserAgent::release());
    }
}
