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
}
