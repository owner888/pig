<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\BuiltinExtensions;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Settings;

/** Upstream's built-in extensions: which load, by which `extensions` entries, and in what company. */
final class BuiltinExtensionsTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-builtin-' . bin2hex(random_bytes(4));
        mkdir("{$this->root}/home/extensions", 0o700, true);
        mkdir("{$this->root}/project/.pig", 0o755, true);
        putenv("PIG_HOME={$this->root}/home");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAllLoadByDefaultInUpstreamsOrder(): void
    {
        $this->assertSame(['llama.cpp', 'codemode', 'tool-search', 'mcp'], $this->names(BuiltinExtensions::enabled([], [])));
    }

    public function testTheUsersEntriesTurnThemOff(): void
    {
        $this->assertSame(['llama.cpp', 'codemode', 'tool-search'], $this->names(BuiltinExtensions::enabled(['-builtin:mcp'], [])));
        $this->assertSame([], $this->names(BuiltinExtensions::enabled(['!builtin:*'], [])));
        $this->assertSame(['mcp'], $this->names(BuiltinExtensions::enabled(['!builtin:*', '+builtin:mcp'], [])));
        $this->assertSame(['llama.cpp', 'codemode', 'tool-search'], $this->names(BuiltinExtensions::enabled([], [], ['mcp'])));
    }

    public function testAProjectEntryDecidesOverTheUsersAndTheLastOneWins(): void
    {
        $this->assertContains('mcp', $this->names(BuiltinExtensions::enabled(['-builtin:mcp'], ['+builtin:mcp'])));
        $this->assertNotContains('codemode', $this->names(BuiltinExtensions::enabled([], ['+builtin:codemode', '!builtin:code*'])));
        $this->assertContains('codemode', $this->names(BuiltinExtensions::enabled([], ['-builtin:codemode', '+builtin:codemode'])));

        $resources = BuiltinExtensions::resources(['-builtin:mcp'], ['-builtin:codemode']);
        $this->assertSame(['user', 'project', 'user', 'user'], array_map(static fn ($r): string => $r->metadata->scope, $resources));
        $this->assertSame([true, false, true, false], array_map(static fn ($r): bool => $r->enabled, $resources));
    }

    public function testNamesPathsAndStaleCopies(): void
    {
        $this->assertNull(BuiltinExtensions::pathOf('builtin:nope'));
        $mcp = (string) BuiltinExtensions::pathOf('builtin:mcp');
        $this->assertFileExists($mcp);
        $this->assertSame('mcp', BuiltinExtensions::nameOf($mcp));
        $this->assertSame('mcp', BuiltinExtensions::nameOf(dirname($mcp)));
        $this->assertFalse(BuiltinExtensions::isStaleCopy($mcp));

        mkdir("{$this->root}/home/extensions/pig-mcp");
        mkdir("{$this->root}/home/extensions/other");
        $this->assertTrue(BuiltinExtensions::isStaleCopy("{$this->root}/home/extensions/pig-mcp"));
        $this->assertFalse(BuiltinExtensions::isStaleCopy("{$this->root}/home/extensions/other"));
    }

    public function testTheExtensionsSettingKeepsItsSwitchesOutOfThePaths(): void
    {
        file_put_contents("{$this->root}/home/settings.json", json_encode(['extensions' => ['-builtin:mcp', 'builtin:codemode', 'mine.php']]));
        $settings = Settings::load("{$this->root}/project", "{$this->root}/home");

        $this->assertSame(['mine.php'], $settings->extensions());
        $this->assertSame(['llama.cpp', 'codemode', 'tool-search'], $this->names($settings->builtinExtensions()));
        $this->assertSame(['llama.cpp', 'tool-search'], $this->names($settings->builtinExtensions(['codemode'])));
    }

    public function testAStaleCopyInTheHomeIsNotLoadedAndSaysSo(): void
    {
        mkdir("{$this->root}/home/extensions/pig-mcp");
        file_put_contents("{$this->root}/home/extensions/pig-mcp/index.php", "<?php\nreturn function (\$pi): void { throw new \\RuntimeException('loaded'); };\n");

        [$loaded, $errors] = ExtensionLoader::load("{$this->root}/project", home: "{$this->root}/home");

        $this->assertSame([], $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('`pig-mcp` is built into pig now', $errors[0]->message);
    }

    public function testBuiltinsLoadLastAndAReplaceableOneGivesWayToAnExtensionWithItsCommand(): void
    {
        file_put_contents("{$this->root}/home/extensions/mine.php", "<?php\nreturn function (\$pi): void { \$pi->registerCommand('mcp', fn () => null, 'Mine'); };\n");
        file_put_contents("{$this->root}/home/extensions/other.php", "<?php\nreturn function (\$pi): void {};\n");

        [$loaded, $errors] = ExtensionLoader::load(
            "{$this->root}/project",
            home: "{$this->root}/home",
            builtins: [(string) BuiltinExtensions::pathOf('mcp')],
        );

        $this->assertSame(['mine', 'other'], array_map(static fn ($e): string => $e->name, $loaded));
        $this->assertCount(1, $errors);
        $this->assertSame('builtin:mcp', $errors[0]->path);
        $this->assertStringContainsString('registers command `/mcp`, so built-in extension `mcp` was not loaded', $errors[0]->message);

        unlink("{$this->root}/home/extensions/mine.php");
        [$loaded, $errors] = ExtensionLoader::load(
            "{$this->root}/project",
            home: "{$this->root}/home",
            builtins: [(string) BuiltinExtensions::pathOf('mcp')],
        );

        $this->assertSame([], $errors);
        $this->assertSame('mcp', BuiltinExtensions::nameOf(end($loaded)->resolved));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function names(array $paths): array
    {
        return array_map(static fn (string $path): string => (string) BuiltinExtensions::nameOf($path), $paths);
    }
}
