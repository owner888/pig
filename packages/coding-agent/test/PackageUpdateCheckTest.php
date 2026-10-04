<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\PackageUpdateCheck;
use Pig\CodingAgent\Config;

final class PackageUpdateCheckTest extends TestCase
{
    public function testReturnsEmptyUnderOfflineMode(): void
    {
        putenv('PIG_OFFLINE=1');
        try {
            $checker = new PackageUpdateCheck();
            $this->assertSame([], $checker->checkForUpdates());
        } finally {
            putenv('PIG_OFFLINE');
        }
    }

    public function testCheckForUpdatesIsFailSafe(): void
    {
        $checker = new PackageUpdateCheck();
        // Even with non-existent cwd or empty user dir, returns array cleanly without throwing
        $res = $checker->checkForUpdates('/non/existent/path');
        $this->assertIsArray($res);
    }
}
