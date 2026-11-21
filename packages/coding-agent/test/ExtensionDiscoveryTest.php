<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionDiscovery;

final class ExtensionDiscoveryTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private string $piHomeDir;
    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pig-ext-test-' . bin2hex(random_bytes(6));
        $this->homeDir = $this->tempDir . '/pig-home';
        $this->piHomeDir = $this->tempDir . '/pi-home';
        $this->cwd = $this->tempDir . '/project';

        mkdir($this->homeDir, 0755, true);
        mkdir($this->piHomeDir, 0755, true);
        mkdir($this->cwd, 0755, true);

        putenv("PIG_CODING_AGENT_DIR={$this->homeDir}");
        putenv("PI_HOME={$this->piHomeDir}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_CODING_AGENT_DIR');
        putenv('PI_HOME');

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

    public function testOnlyWhatLoadedIsListedNotEveryFileInTheFolders(): void
    {
        // Upstream lists `getExtensions().extensions`: a folder's extension a setting or a custom
        // session left out did not load, and is not named.
        $pigExt = $this->homeDir . '/extensions';
        mkdir($pigExt . '/pig-vless', 0755, true);
        file_put_contents($pigExt . '/pig-vless/index.php', '<?php');
        file_put_contents($pigExt . '/my-hook.php', '<?php');

        $this->assertSame([], ExtensionDiscovery::discover([]));
        $this->assertSame(['my-hook.php'], ExtensionDiscovery::discover([$pigExt . '/my-hook.php']));
        $this->assertSame(['my-hook.php', 'pig-vless'], ExtensionDiscovery::discover([$pigExt . '/pig-vless/index.php', $pigExt . '/my-hook.php']));
    }

    public function testExtraPathsAreIncluded(): void
    {
        $labels = ExtensionDiscovery::discover(['/path/to/custom-hook.php']);

        $this->assertSame(['custom-hook.php'], $labels);
    }

    public function testBuiltInsAreNotListed(): void
    {
        // Upstream's built-ins are `hidden`: they load, and [Extensions] does not name them.
        $mcp = \Pig\CodingAgent\Extensions\BuiltinExtensions::pathOf('mcp');
        $this->assertNotNull($mcp);

        $labels = ExtensionDiscovery::discover([$mcp, '/path/to/custom-hook.php']);

        $this->assertSame(['custom-hook.php'], $labels);
    }
}
