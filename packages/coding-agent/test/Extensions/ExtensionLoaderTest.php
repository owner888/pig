<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookContext;

final class ExtensionLoaderTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pig-ext-loader-test-' . bin2hex(random_bytes(6));
        $this->homeDir = $this->tempDir . '/home';
        $this->cwd = $this->tempDir . '/project';

        mkdir($this->homeDir . '/extensions', 0755, true);
        mkdir($this->cwd . '/.pig/extensions', 0755, true);
        mkdir($this->cwd . '/extensions', 0755, true);

        putenv("PIG_HOME={$this->homeDir}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testLoadsFileAndDirectoryExtensions(): void
    {
        // 1. Single file extension
        $fileExt = $this->homeDir . '/extensions/single.php';
        file_put_contents($fileExt, <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return function (ExtensionApi $pi): void {
    $pi->registerCommand('greet', fn ($args, $ctx) => null, 'Say hello');
};
PHP);

        // 2. Directory extension with index.php
        $dirExt = $this->cwd . '/.pig/extensions/bundled';
        mkdir($dirExt, 0755, true);
        file_put_contents($dirExt . '/index.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
return function (ExtensionApi $pi): void {
    $pi->registerTool(new CustomTool(
        name: 'sample_tool',
        label: 'Sample Tool',
        description: 'Sample description',
        parameters: ['type' => 'object', 'properties' => []],
        execute: fn () => new AgentToolResult([new TextContent('ok')]),
    ));
};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertSame([], $errors);
        $this->assertCount(2, $loaded);

        $names = array_map(static fn ($ext) => $ext->name, $loaded);
        $this->assertContains('single', $names);
        $this->assertContains('bundled', $names);

        $bundled = $loaded[$names[0] === 'bundled' ? 0 : 1];
        $this->assertCount(1, $bundled->api->tools());
        $this->assertSame('sample_tool', $bundled->api->tools()[0]->name);
    }

    public function testFaultIsolationCatchesThrowsAndDoesNotCrash(): void
    {
        $broken = $this->homeDir . '/extensions/broken.php';
        file_put_contents($broken, <<<'PHP'
<?php
return function ($pi): void {
    throw new RuntimeException('Intentional extension failure');
};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertCount(0, $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Intentional extension failure', $errors[0]->message);
    }

    public function testCapturesAccidentalEchoOutputAsError(): void
    {
        $noisy = $this->homeDir . '/extensions/noisy.php';
        file_put_contents($noisy, <<<'PHP'
<?php
echo "corrupt terminal output";
return function ($pi): void {};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertCount(0, $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('printed to standard output', $errors[0]->message);
    }

    public function testExplicitCliPathsAreLoaded(): void
    {
        $custom = $this->tempDir . '/outside.php';
        file_put_contents($custom, <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return function (ExtensionApi $pi): void {
    $pi->registerCommand('custom_cmd', fn () => null);
};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, cliPaths: [$custom], home: $this->homeDir);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertSame('outside', $loaded[0]->name);
    }

    public function testNoExtensionsStillLoadsTheOnesNamedOnTheCommandLine(): void
    {
        // Upstream's `--no-extensions`: "explicit -e paths still work".
        $extension = <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return function (ExtensionApi $pi): void {};
PHP;
        file_put_contents($this->homeDir . '/extensions/discovered.php', $extension);
        is_dir($this->cwd . '/.pig/extensions') || mkdir($this->cwd . '/.pig/extensions', 0755, true);
        file_put_contents($this->cwd . '/.pig/extensions/project.php', $extension);
        file_put_contents($this->tempDir . '/named.php', $extension);

        [$loaded] = ExtensionLoader::load(
            $this->cwd,
            configured: [$this->tempDir . '/named.php'],
            cliPaths: [$this->tempDir . '/named.php'],
            home: $this->homeDir,
            packageExtensions: fn (): array => [$this->homeDir . '/extensions/discovered.php'],
            discover: false,
        );

        $this->assertSame(['named'], array_map(static fn (object $e): string => $e->name, $loaded));
    }

    public function testBackwardCompatibilityWithHookApiTypehint(): void
    {
        $legacy = $this->homeDir . '/extensions/legacy.php';
        file_put_contents($legacy, <<<'PHP'
<?php
use Pig\CodingAgent\Hooks\HookApi;
return function (HookApi $pi): void {
    $pi->registerCommand('legacy_cmd', fn () => null);
};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertSame('legacy', $loaded[0]->name);
    }

    public function testAFolderExtensionsClassesAreSeenInItsSiblingFilesToo(): void
    {
        // The entry `require_once`s a class file beside it, as every bundled extension does. The
        // scan covers the folder, so a reload of the entry runs the first factory rather than a
        // second `require` of the sibling — which is the fatal the `class_exists` guards used to
        // stand in front of.
        $dir = $this->homeDir . '/extensions/with-sibling';
        mkdir($dir . '/src', 0755, true);
        file_put_contents($dir . '/src/Helper.php', <<<'PHP'
<?php
namespace ExtLoaderTestA;
class Helper { public static int $runs = 0; }
PHP);
        file_put_contents($dir . '/index.php', <<<'PHP'
<?php
require_once __DIR__ . '/src/Helper.php';
return function ($pi): void { \ExtLoaderTestA\Helper::$runs++; };
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);
        $this->assertCount(1, $loaded);
        $this->assertSame([], $errors);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);
        $this->assertCount(1, $loaded);
        $this->assertSame([], $errors);
        $this->assertSame(2, \ExtLoaderTestA\Helper::$runs);

        file_put_contents($dir . '/src/Helper.php', "<?php\nnamespace ExtLoaderTestA;\nclass Helper { public static int \$runs = 100; }\n");

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);
        $this->assertCount(1, $loaded, 'still loaded, from memory');
        $this->assertCount(1, $errors);
        $this->assertSame('reload', $errors[0]->stage);
        $this->assertStringContainsString('declares ExtLoaderTestA\Helper, which PHP cannot unload', $errors[0]->message);
        $this->assertStringContainsString('restart pig', $errors[0]->message);
        $this->assertSame(3, \ExtLoaderTestA\Helper::$runs);
    }

    public function testADifferentCopyOfAnExtensionsClassIsRefusedByName(): void
    {
        $global = $this->homeDir . '/extensions/twice';
        $project = $this->cwd . '/extensions/twice';
        mkdir($global, 0755, true);
        mkdir($project, 0755, true);
        $entry = "<?php\nnamespace ExtLoaderTestB;\nclass Shared {}\nreturn function (\$pi): void {};\n";
        file_put_contents($global . '/index.php', $entry);
        file_put_contents($project . '/index.php', $entry . "// edited in the checkout\n");

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertCount(1, $loaded);
        $this->assertSame($global . '/index.php', $loaded[0]->path);
        $this->assertCount(1, $errors);
        $this->assertSame($project . '/index.php', $errors[0]->path);
        $this->assertStringContainsString('declares ExtLoaderTestB\Shared from ' . realpath($global) . '/index.php', $errors[0]->message);
        $this->assertStringContainsString('two different copies of one extension cannot both be loaded', $errors[0]->message);
    }

    public function testLaterExtensionWithSameNameOverridesEarlierOne(): void
    {
        // 1. Global extension: pig-antigravity
        $globalDir = $this->homeDir . '/extensions/pig-antigravity';
        mkdir($globalDir, 0755, true);
        file_put_contents($globalDir . '/index.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return function (ExtensionApi $pi): void {
    $pi->registerCommand('antigravity.usage', fn ($args, $ctx) => null, 'global');
};
PHP);

        // 2. Project-level extension with same name: pig-antigravity
        $projectDir = $this->cwd . '/extensions/pig-antigravity';
        mkdir($projectDir, 0755, true);
        file_put_contents($projectDir . '/index.php', <<<'PHP'
<?php
use Pig\CodingAgent\Extensions\ExtensionApi;
return function (ExtensionApi $pi): void {
    $pi->registerCommand('antigravity.usage', fn ($args, $ctx) => null, 'project');
};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertSame('pig-antigravity', $loaded[0]->name);
        $this->assertSame('project', $loaded[0]->api->commands()['antigravity.usage']->description);
    }
}
