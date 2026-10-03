<?php

declare(strict_types=1);

namespace Pig\Tests\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\NoUi;

final class ComputerExtensionTest extends TestCase
{
    private string $tempHome;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempHome = sys_get_temp_dir() . '/pig-test-home-' . uniqid();
        mkdir($this->tempHome, 0755, true);
        putenv("PIG_HOME={$this->tempHome}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        if (file_exists($this->tempHome . '/cookies.json')) {
            unlink($this->tempHome . '/cookies.json');
        }
        if (is_dir($this->tempHome)) {
            rmdir($this->tempHome);
        }
        parent::tearDown();
    }

    public function testComputerExtensionRegistersToolAndSlashCommand(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-computer/index.php';
        $this->assertFileExists($extPath);

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$extPath], home: $this->tempHome);
        $this->assertSame([], $errors);

        $computerExt = null;
        foreach ($loaded as $ext) {
            if ($ext->name === 'pig-computer') {
                $computerExt = $ext;
                break;
            }
        }

        $this->assertNotNull($computerExt);
        $this->assertSame('pig-computer', $computerExt->name);

        // Tool registered
        $toolNames = array_map(static fn ($t) => $t->name, $computerExt->api->tools());
        $this->assertContains('computer', $toolNames);

        // Command registered
        $commands = $computerExt->api->commands();
        $this->assertArrayHasKey('computer', $commands);
    }

    public function testNavigateStopsWhenCookiesJsonIsMissingForJd(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-computer/index.php';

        [$loaded] = ExtensionLoader::load($root, cliPaths: [$extPath], home: $this->tempHome);
        $computerTool = null;
        foreach ($loaded as $ext) {
            foreach ($ext->api->tools() as $t) {
                if ($t->name === 'computer') {
                    $computerTool = $t;
                    break 2;
                }
            }
        }

        $this->assertNotNull($computerTool);

        $ctx = new HookContext(cwd: '.', ui: new NoUi());
        $res = ($computerTool->execute)('call_1', ['action' => 'navigate', 'url' => 'https://www.jd.com'], null, $ctx);
        $text = $res->content[0]->text ?? '';

        $this->assertStringContainsString('登录凭据缺失 - 操作已终止', $text);
        $this->assertStringContainsString('cookies.json', $text);
    }

    public function testNavigateStopsWhenCookiesAreExpired(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-computer/index.php';

        // Write expired cookie file
        $cookieFile = $this->tempHome . '/cookies.json';
        file_put_contents($cookieFile, json_encode([
            [
                'name' => 'pt_key',
                'value' => 'fake_val',
                'domain' => '.jd.com',
                'expirationDate' => time() - 3600, // expired 1h ago
            ]
        ]));

        [$loaded] = ExtensionLoader::load($root, cliPaths: [$extPath], home: $this->tempHome);
        $computerTool = null;
        foreach ($loaded as $ext) {
            foreach ($ext->api->tools() as $t) {
                if ($t->name === 'computer') {
                    $computerTool = $t;
                    break 2;
                }
            }
        }

        $this->assertNotNull($computerTool);

        $ctx = new HookContext(cwd: '.', ui: new NoUi());
        $res = ($computerTool->execute)('call_2', ['action' => 'navigate', 'url' => 'https://item.jd.com/123.html'], null, $ctx);
        $text = $res->content[0]->text ?? '';

        $this->assertStringContainsString('已过期', $text);
    }
}
