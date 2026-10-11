<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\PackageUpdateCheck;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Settings;

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

    public function testAComposerPackageWithANewerReleaseIsReportedAndAPinnedOneIsNot(): void
    {
        if (trim((string) shell_exec('command -v composer')) === '') {
            $this->markTestSkipped('composer is not on the PATH');
        }

        $root = sys_get_temp_dir() . '/pig-update-check-' . bin2hex(random_bytes(4));
        $home = "{$root}/home";
        $cwd = "{$root}/project";
        mkdir($home, 0o700, true);
        mkdir($cwd, 0o700, true);

        // A path repository with two releases, and Packagist off — see `PackageManagerTest`.
        foreach (['1.0.0', '2.0.0'] as $index => $version) {
            mkdir("{$root}/repository/{$index}/extensions", 0o700, true);
            file_put_contents("{$root}/repository/{$index}/extensions/a.php", '<?php');
            file_put_contents("{$root}/repository/{$index}/composer.json", json_encode(['name' => 'acme/tools', 'version' => $version]));
        }

        mkdir("{$root}/composer-home");
        file_put_contents("{$root}/composer-home/config.json", json_encode(['repositories' => [
            ['type' => 'path', 'url' => "{$root}/repository/*", 'options' => ['symlink' => false]],
            ['packagist.org' => false],
        ]]));
        putenv("PIG_CODING_AGENT_DIR={$home}");
        putenv("COMPOSER_HOME={$root}/composer-home");
        putenv('COMPOSER_NO_AUDIT=1');
        putenv('COMPOSER_ALLOW_SUPERUSER=1');

        try {
            file_put_contents("{$home}/settings.json", json_encode(['packages' => ['composer:acme/tools@1.0.0']]));
            $manager = new PackageManager($cwd, Settings::load($cwd, $home), $home);
            $manager->install('composer:acme/tools@1.0.0');

            $this->assertNotContains('composer:acme/tools@1.0.0', (new PackageUpdateCheck())->checkForUpdates($cwd), 'pinned');

            // The same installation, now configured with no constraint: 2.0.0 is what `update` would bring.
            file_put_contents("{$home}/settings.json", json_encode(['packages' => ['composer:acme/tools']]));
            $this->assertContains('composer:acme/tools', (new PackageUpdateCheck())->checkForUpdates($cwd));
        } finally {
            putenv('PIG_CODING_AGENT_DIR');
            putenv('COMPOSER_HOME');
            putenv('COMPOSER_NO_AUDIT');
            putenv('COMPOSER_ALLOW_SUPERUSER');
            shell_exec('rm -rf ' . escapeshellarg($root));
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
