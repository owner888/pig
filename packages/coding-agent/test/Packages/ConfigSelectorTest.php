<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Packages;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\ConfigSelector;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Test\GlobalThemeFixture;
use Pig\Tui\Ansi;

/** Upstream's `config-selector.test.ts`, for the package rows: what a toggle writes, in each scope. */
final class ConfigSelectorTest extends TestCase
{
    use GlobalThemeFixture;

    private string $root;

    private string $home;

    private string $cwd;

    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        $this->root = sys_get_temp_dir() . '/pig-config-' . bin2hex(random_bytes(4));
        $this->home = "{$this->root}/home";
        $this->cwd = "{$this->root}/project";
        mkdir($this->home, 0o700, true);
        mkdir("{$this->cwd}/.pig", 0o700, true);
        mkdir("{$this->cwd}/tools/extensions", 0o700, true);
        file_put_contents("{$this->cwd}/tools/extensions/alpha.php", '<?php');
        file_put_contents("{$this->cwd}/tools/extensions/beta.php", '<?php');
        putenv("PIG_HOME={$this->home}");
    }

    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        putenv('PIG_HOME');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheScreenListsThePackageAndSpaceWritesAMinusIntoTheUsersEntry(): void
    {
        $selector = $this->selector(['packages' => ['../project/tools']]);
        $screen = implode("\n", array_map(Ansi::strip(...), $selector->render(80)));

        $this->assertStringContainsString('Global Resources', $screen);
        $this->assertStringContainsString('../project/tools (user)', $screen);
        $this->assertStringContainsString('[x] alpha.php', $screen);
        $this->assertStringContainsString('[x] beta.php', $screen);

        // The cursor starts on the first item: alpha.php. Space turns it off.
        $selector->handleInput(' ');

        $this->assertSame(
            [['source' => '../project/tools', 'extensions' => ['-extensions/alpha.php']]],
            $this->userPackages(),
        );
        $this->assertStringContainsString('[ ] alpha.php', implode("\n", array_map(Ansi::strip(...), $selector->render(80))));

        // And on again: the verdict is replaced, not stacked, and the emptied entry stays an
        // object because `+` is a verdict too.
        $selector->handleInput(' ');
        $this->assertSame([['source' => '../project/tools', 'extensions' => ['+extensions/alpha.php']]], $this->userPackages());
    }

    public function testTabSwitchesToTheProjectWhereSpaceCyclesADeltaOverTheUsersPackage(): void
    {
        $selector = $this->selector(['packages' => ['../project/tools']]);
        $selector->handleInput("\t");

        $screen = implode("\n", array_map(Ansi::strip(...), $selector->render(80)));
        $this->assertStringContainsString('Project Local Resources', $screen);
        $this->assertStringContainsString('inherited global', $screen);

        // inherit → unload: a delta entry appears in the project's file.
        $selector->handleInput(' ');
        $this->assertSame(
            [['source' => '../tools', 'autoload' => false, 'extensions' => ['-extensions/alpha.php']]],
            $this->projectPackages(),
        );
        $this->assertStringContainsString('[-] alpha.php', implode("\n", array_map(Ansi::strip(...), $selector->render(80))));

        // unload → load.
        $selector->handleInput(' ');
        $this->assertSame([['source' => '../tools', 'autoload' => false, 'extensions' => ['+extensions/alpha.php']]], $this->projectPackages());

        // load → inherit: the emptied delta is removed altogether.
        $selector->handleInput(' ');
        $this->assertSame([], $this->projectPackages());
    }

    public function testTypingFiltersTheRows(): void
    {
        $selector = $this->selector(['packages' => ['../project/tools']]);
        $selector->handleInput('b');
        $selector->handleInput('e');
        $selector->handleInput('t');
        $selector->handleInput('a');

        $screen = implode("\n", array_map(Ansi::strip(...), $selector->render(80)));
        $this->assertStringContainsString('beta.php', $screen);
        $this->assertStringNotContainsString('alpha.php', $screen);
    }

    /** @param array<string, mixed> $user */
    private function selector(array $user): ConfigSelector
    {
        file_put_contents("{$this->home}/settings.json", json_encode($user));
        $settings = Settings::load($this->cwd, $this->home);
        $global = (new PackageManager($this->cwd, Settings::load($this->cwd, $this->home, projectTrusted: false), $this->home))->resolve();
        $project = (new PackageManager($this->cwd, $settings, $this->home))->resolve();

        return new ConfigSelector($global, $project, $settings, $this->cwd, $this->home);
    }

    /** @return list<mixed> */
    private function userPackages(): array
    {
        return json_decode((string) file_get_contents("{$this->home}/settings.json"), true)['packages'] ?? [];
    }

    /** @return list<mixed> */
    private function projectPackages(): array
    {
        $path = "{$this->cwd}/.pig/settings.json";

        return is_file($path) ? (json_decode((string) file_get_contents($path), true)['packages'] ?? []) : [];
    }
}
