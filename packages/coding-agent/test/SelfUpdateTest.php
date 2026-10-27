<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\SelfUpdate;

final class SelfUpdateTest extends TestCase
{
    public function testHelpOutputsUsageAndReturnsZero(): void
    {
        $updater = new SelfUpdate();

        ob_start();
        $code = $updater->run(['--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        // Upstream's shape: `Usage:` on its own line, the command's usage indented under it.
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('pig update [source|self|pig]', $output);
        $this->assertStringContainsString('--self', $output);
        $this->assertStringContainsString('--extensions', $output);
        $this->assertStringContainsString('--models', $output);
        $this->assertStringContainsString(SelfUpdate::CHANGELOG_URL, $output);
    }

    public function testShortHelpOptionOutputsUsage(): void
    {
        $updater = new SelfUpdate();

        ob_start();
        $code = $updater->run(['-h']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('pig update [source|self|pig]', $output);
    }

    public function testSyncDirectoryRecursivelySyncsFiles(): void
    {
        $src = sys_get_temp_dir() . '/pig-sync-src-' . bin2hex(random_bytes(4));
        $dst = sys_get_temp_dir() . '/pig-sync-dst-' . bin2hex(random_bytes(4));

        mkdir($src . '/sub', 0755, true);
        file_put_contents($src . '/file1.txt', 'hello');
        file_put_contents($src . '/sub/file2.txt', 'world');

        $changes = SelfUpdate::syncDirectory($src, $dst);
        $this->assertSame(2, $changes);
        $this->assertFileExists($dst . '/file1.txt');
        $this->assertFileExists($dst . '/sub/file2.txt');
        $this->assertSame('hello', file_get_contents($dst . '/file1.txt'));

        // Second sync should have 0 changes
        $this->assertSame(0, SelfUpdate::syncDirectory($src, $dst));

        // Clean up
        unlink($src . '/sub/file2.txt');
        unlink($src . '/file1.txt');
        rmdir($src . '/sub');
        rmdir($src);

        unlink($dst . '/sub/file2.txt');
        unlink($dst . '/file1.txt');
        rmdir($dst . '/sub');
        rmdir($dst);
    }

    public function testUpdatePigExecutesExpectedCommands(): void
    {
        $executed = [];
        $runner = static function (array $command, ?string $cwd = null) use (&$executed): int {
            $executed[] = [$command, $cwd];

            return 0;
        };

        $updater = new SelfUpdate($runner);

        ob_start();
        $code = $updater->run();
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertNotEmpty($executed);
        $this->assertStringContainsString('pig has been updated', $output);
        $this->assertStringContainsString(SelfUpdate::CHANGELOG_URL, $output);
    }

    public function testFailingCommandReturnsNonZeroStatus(): void
    {
        $runner = static function (array $command, ?string $cwd = null): int {
            return 127;
        };

        $updater = new SelfUpdate($runner);

        ob_start();
        $code = $updater->run();
        ob_end_clean();

        $this->assertSame(127, $code);
    }

    public function testEnsureBundledExtensionsCopiesCoreExtensionsToUserDirectory(): void
    {
        $tempHome = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));
        $synced = SelfUpdate::ensureBundledExtensions($tempHome);

        $this->assertGreaterThan(0, $synced);
        $this->assertDirectoryExists($tempHome . '/extensions/pig-antigravity');
        $this->assertFileExists($tempHome . '/extensions/pig-antigravity/index.php');

        // Subsequent call does not recopy already existing extensions
        $this->assertSame(0, SelfUpdate::ensureBundledExtensions($tempHome));

        // Clean up
        SelfUpdate::deleteRecursive($tempHome);
    }

    public function testAnUpdateInstallsABundledExtensionAddedSinceAndLeavesARemovedOneRemoved(): void
    {
        // An install from before the record: it had pig-antigravity and deleted pig-vless.
        $home = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));
        mkdir("{$home}/extensions/pig-antigravity", 0o755, true);

        try {
            $installed = SelfUpdate::installNewBundled($home);

            $this->assertContains('pig-gemini-video', $installed);
            $this->assertFileExists("{$home}/extensions/pig-gemini-video/index.php");
            $this->assertDirectoryDoesNotExist("{$home}/extensions/pig-vless");
            $this->assertNotContains('pig-antigravity', $installed);

            // Offered once: the next update installs nothing, and a deleted one stays deleted.
            SelfUpdate::deleteRecursive("{$home}/extensions/pig-gemini-video");
            $this->assertSame([], SelfUpdate::installNewBundled($home));
            $this->assertDirectoryDoesNotExist("{$home}/extensions/pig-gemini-video");
            $this->assertContains('pig-gemini-video', json_decode((string) file_get_contents("{$home}/extensions/.bundled.json"), true));
        } finally {
            SelfUpdate::deleteRecursive($home);
        }
    }

    public function testAMachineThatNeverRanPigIsLeftToTheFirstRunCopy(): void
    {
        $home = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));

        $this->assertSame([], SelfUpdate::installNewBundled($home));
        $this->assertDirectoryDoesNotExist("{$home}/extensions");
    }

    public function testTheFirstRunCopyRecordsWhatItOffered(): void
    {
        $home = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));

        try {
            SelfUpdate::ensureBundledExtensions($home);
            $offered = json_decode((string) file_get_contents("{$home}/extensions/.bundled.json"), true);

            $this->assertContains('pig-gemini-video', $offered);
            $this->assertContains('pig-antigravity', $offered);
            $this->assertNotContains('pig-mcp', $offered);
            $this->assertSame([], SelfUpdate::installNewBundled($home));
        } finally {
            SelfUpdate::deleteRecursive($home);
        }
    }
}
