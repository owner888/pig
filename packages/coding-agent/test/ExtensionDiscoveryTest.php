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

        putenv("PIG_HOME={$this->homeDir}");
        putenv("PI_HOME={$this->piHomeDir}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
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

    public function testDiscoversExtensionsFromBothPigAndPiDirectories(): void
    {
        $pigExt = $this->homeDir . '/extensions';
        $piExt = $this->piHomeDir . '/extensions';
        mkdir($pigExt, 0755, true);
        mkdir($piExt, 0755, true);

        file_put_contents($pigExt . '/my-hook.php', '<?php');
        file_put_contents($piExt . '/copy.ts', '// ts');
        file_put_contents($piExt . '/system-notify.ts', '// ts');

        $labels = ExtensionDiscovery::discover($this->cwd);

        $this->assertSame(['copy.ts', 'my-hook.php', 'system-notify.ts'], $labels);
    }

    public function testDiscoversNpmPackagesFromSettings(): void
    {
        $pkgDir = $this->piHomeDir . '/npm/node_modules/pi-antigravity';
        mkdir($pkgDir . '/src', 0755, true);
        file_put_contents($pkgDir . '/package.json', json_encode([
            'name' => 'pi-antigravity',
            'pi' => [
                'extensions' => ['./src/index.ts'],
            ],
        ]));

        file_put_contents($this->piHomeDir . '/settings.json', json_encode([
            'packages' => ['npm:pi-antigravity'],
        ]));

        $labels = ExtensionDiscovery::discover($this->cwd);

        $this->assertSame(['pi-antigravity:src'], $labels);
    }

    public function testExtraPathsAreIncluded(): void
    {
        $labels = ExtensionDiscovery::discover($this->cwd, ['/path/to/custom-hook.php']);

        $this->assertSame(['custom-hook.php'], $labels);
    }
}
