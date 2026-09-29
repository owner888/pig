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

    public function testTopLevelNamedFunctionIsRejected(): void
    {
        $file = $this->homeDir . '/extensions/bad_fn.php';
        file_put_contents($file, <<<'PHP'
<?php
function myGlobalExtensionHelper() {}
return function ($pi) {};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertCount(0, $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("defines top-level named function 'myGlobalExtensionHelper()'", $errors[0]->message);
        $this->assertStringContainsString('use scoped closures', $errors[0]->message);
    }

    public function testTopLevelNamedClassIsRejected(): void
    {
        $file = $this->homeDir . '/extensions/bad_class.php';
        file_put_contents($file, <<<'PHP'
<?php
class MyGlobalExtensionClass {}
return function ($pi) {};
PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->cwd, home: $this->homeDir);

        $this->assertCount(0, $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("defines top-level named class 'MyGlobalExtensionClass'", $errors[0]->message);
        $this->assertStringContainsString('use anonymous classes', $errors[0]->message);
    }
}
