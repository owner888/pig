<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\CodingAgent\Cli\SelfUpdate;
use Pig\CodingAgent\Cli\UpdateCheck;
use Pig\CodingAgent\CodingAgentError;
use Pig\Test\AssertsThrows;

final class SelfUpdateTest extends TestCase
{
    use AssertsThrows;

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

        $err = fopen('php://temp', 'w+') ?: throw new \RuntimeException('no temp stream');
        $updater = new SelfUpdate($runner, err: $err);

        ob_start();
        $code = $updater->run();
        ob_end_clean();

        $this->assertSame(127, $code);
        rewind($err);
        $this->assertMatchesRegularExpression('/Failed to update pig via (git pull|Composer) \(exit code 127\)/', (string) stream_get_contents($err));
    }

    /** Upstream 8bc94b1: the person's own command in place of pig's, with its placeholders filled in. */
    public function testAConfiguredCommandReplacesPigsOwnUpdate(): void
    {
        $executed = [];
        $runner = static function (array $command, ?string $cwd = null) use (&$executed): int {
            $executed[] = $command;

            return 0;
        };
        putenv('PIG_SELF_UPDATE_COMMAND=mise  use -g composer:{packageName}@{version} {installSpec}');

        try {
            ob_start();
            $code = (new SelfUpdate($runner, static fn (): string => 'v99.0.0', static fn (): string => '99.0.0'))->run(['self']);
            $output = (string) ob_get_clean();
        } finally {
            putenv('PIG_SELF_UPDATE_COMMAND');
        }

        $this->assertSame(0, $code);
        $this->assertSame([['mise', 'use', '-g', 'composer:pigagent/pig@99.0.0', 'pigagent/pig:99.0.0']], $executed);
        $this->assertStringContainsString('Updating pig with mise use -g composer:pigagent/pig@99.0.0 pigagent/pig:99.0.0...', $output);
        $this->assertStringContainsString('to 99.0.0', $output);
    }

    public function testTheEnvironmentWinsOverTheSettingAndAnUnchangedVersionIsSaid(): void
    {
        $this->assertSame(['nix', 'profile', 'upgrade', 'pig'], SelfUpdate::configuredCommand(['nix', 'profile', 'upgrade', 'pig']));
        $this->assertNull(SelfUpdate::configuredCommand(null));

        putenv('PIG_SELF_UPDATE_COMMAND=true');

        try {
            $this->assertSame(['true'], SelfUpdate::configuredCommand(['nix']));
            ob_start();
            $code = (new SelfUpdate(static fn (): int => 0, static fn (): ?string => null, static fn (): string => '99.0.0'))->updatePigWith(['true']);
            $output = (string) ob_get_clean();
        } finally {
            putenv('PIG_SELF_UPDATE_COMMAND');
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Ran true, but `pig --version` reports no version, not 99.0.0.', $output);
    }

    /** Upstream's `getSelfUpdatePlan()`: no newest release to name means nothing is run. */
    public function testAConfiguredCommandIsNotRunWhenTheNewestReleaseIsUnknown(): void
    {
        $executed = [];
        $runner = static function (array $command) use (&$executed): int {
            $executed[] = $command;

            return 0;
        };
        $latest = static fn (): string => throw new CodingAgentError('Could not determine latest pig version.');

        $err = fopen('php://temp', 'w+') ?: throw new \RuntimeException('no temp stream');

        $code = (new SelfUpdate($runner, static fn (): ?string => null, $latest, $err))->updatePigWith(['true']);

        $this->assertSame(1, $code);
        $this->assertSame([], $executed);
        rewind($err);
        $this->assertStringContainsString('Error: Could not determine latest pig version.', (string) stream_get_contents($err));
    }

    /** The newest release lookup fails loudly, where the startup check stays quiet. */
    public function testTheNewestReleaseLookupThrowsWhenPackagistCannotBeReached(): void
    {
        $check = new UpdateCheck(new HttpClient(0.5), 'http://127.0.0.1:9/');

        $error = $this->assertThrows(CodingAgentError::class, static fn () => $check->latest());

        $this->assertStringStartsWith('Could not determine latest pig version', $error->getMessage());
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

    public function testAnUpdateRemovesTheCopyOfABundledExtensionThatIsAPackageNow(): void
    {
        // pig-android-use left pig for its own repository: the copy an older pig made is synced
        // from nowhere, and beside the package it would register the android_* tools twice.
        $home = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));
        mkdir("{$home}/extensions/pig-android-use/src", 0o755, true);
        file_put_contents("{$home}/extensions/pig-android-use/index.php", '<?php');
        file_put_contents("{$home}/extensions/pig-android-use/src/AdbClient.php", '<?php');

        try {
            foreach (array_keys(SelfUpdate::MOVED_TO_PACKAGES) as $moved) {
                $this->assertDirectoryDoesNotExist(SelfUpdate::coreExtensionsDir() . '/' . $moved, "{$moved} is no longer bundled");
            }
            $this->assertArrayHasKey('pig-computer', SelfUpdate::MOVED_TO_PACKAGES);
            $this->assertArrayHasKey('pig-web-search', SelfUpdate::MOVED_TO_PACKAGES);

            $this->assertSame(['pig-android-use'], SelfUpdate::removeMovedBundled($home));
            $this->assertDirectoryDoesNotExist("{$home}/extensions/pig-android-use");
            $this->assertSame([], SelfUpdate::removeMovedBundled($home));
        } finally {
            SelfUpdate::deleteRecursive($home);
        }
    }

    public function testACheckoutOfAMovedExtensionIsThePersonsAndStays(): void
    {
        $home = sys_get_temp_dir() . '/pig-test-home-' . bin2hex(random_bytes(4));
        mkdir("{$home}/extensions/pig-android-use/.git", 0o755, true);

        try {
            $this->assertSame([], SelfUpdate::removeMovedBundled($home));
            $this->assertDirectoryExists("{$home}/extensions/pig-android-use/.git");
        } finally {
            SelfUpdate::deleteRecursive($home);
        }
    }
}
