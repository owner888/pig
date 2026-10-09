<?php

declare(strict_types=1);

namespace Pig\Tests\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\Extensions\Computer\DesktopClient;
use Pig\Extensions\Computer\InputManager;
use Pig\Extensions\Computer\ScreenScaler;

final class ComputerExtensionTest extends TestCase
{
    private string $tempHome;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempHome = sys_get_temp_dir() . '/pig-test-computer-' . uniqid();
        mkdir($this->tempHome, 0755, true);
        putenv("PIG_HOME={$this->tempHome}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        if (is_dir($this->tempHome)) {
            $files = glob("{$this->tempHome}/*") ?: [];
            foreach ($files as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            rmdir($this->tempHome);
        }
        parent::tearDown();
    }

    public function testComputerExtensionLoadsAndRegistersToolsAndSlashCommand(): void
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

        // Command registered
        $commands = $computerExt->api->commands();
        $this->assertArrayHasKey('computer', $commands);

        // 10 Native Tools registered
        $toolNames = array_map(static fn ($t) => $t->name, $computerExt->api->tools());
        $expectedTools = [
            'computer_doctor',
            'computer_ready',
            'computer_observe',
            'computer_click',
            'computer_drag',
            'computer_scroll',
            'computer_type_text',
            'computer_press_key',
            'computer_launch_app',
            'computer_batch',
        ];

        foreach ($expectedTools as $expected) {
            $this->assertContains($expected, $toolNames, "Tool {$expected} should be registered");
        }
    }

    public function testScreenScalerPngDimensions(): void
    {
        $validPng = "\x89PNG\r\n\x1a\n"
                  . "\x00\x00\x00\x0d"
                  . "IHDR"
                  . "\x00\x00\x05\xbe" // 1470
                  . "\x00\x00\x03\xbc" // 956
                  . "\x08\x06\x00\x00\x00";

        $tmpFile = $this->tempHome . '/test.png';
        file_put_contents($tmpFile, $validPng);

        $dims = ScreenScaler::pngDimensions($tmpFile);
        $this->assertNotNull($dims);
        $this->assertSame(1470, $dims['width']);
        $this->assertSame(956, $dims['height']);

        // Invalid file
        $invalidFile = $this->tempHome . '/invalid.png';
        file_put_contents($invalidFile, 'not a png file');
        $this->assertNull(ScreenScaler::pngDimensions($invalidFile));
    }

    public function testScreenScalerFitDimensions(): void
    {
        // Retina 2x: 2940 x 1912
        // maxLong: 1568, maxShort: 980
        // 1568 / 2940 = 0.5333
        // 980 / 1912 = 0.5125 -> scale is 0.5125
        $fitted = ScreenScaler::fitDimensions(2940, 1912);
        $this->assertLessThanOrEqual(1568, $fitted['width']);
        $this->assertLessThanOrEqual(980, $fitted['height']);
        $this->assertGreaterThan(0, $fitted['width']);
        $this->assertGreaterThan(0, $fitted['height']);

        // Small screen stays 1.0 scale
        $small = ScreenScaler::fitDimensions(1280, 800);
        $this->assertSame(1280, $small['width']);
        $this->assertSame(800, $small['height']);
        $this->assertSame(1.0, $small['scale']);
    }

    public function testInputManagerKeycodes(): void
    {
        $this->assertSame(36, InputManager::KEY_CODES['enter']);
        $this->assertSame(48, InputManager::KEY_CODES['tab']);
        $this->assertSame(53, InputManager::KEY_CODES['escape']);
        $this->assertSame(49, InputManager::KEY_CODES['space']);
    }

    public function testInputManagerCoordinateScaling(): void
    {
        // Logical Point (200, 300) with ratio [1.5, 1.5] without jitter
        $res = InputManager::scaleCoordinates(200.0, 300.0, [1.5, 1.5], jitter: false);
        $this->assertSame(300, $res['real_x']);
        $this->assertSame(450, $res['real_y']);

        // With anti-ban jitter
        $jittered = InputManager::scaleCoordinates(200.0, 300.0, [1.5, 1.5], jitter: true);
        $this->assertGreaterThanOrEqual(298, $jittered['real_x']);
        $this->assertLessThanOrEqual(302, $jittered['real_x']);
    }

    public function testDesktopClientDetectsScreenSize(): void
    {
        $client = new DesktopClient();
        if (!$client->isAvailable()) {
            $this->markTestSkipped('Native desktop automation only available on macOS.');
        }

        $size = $client->screenSize();
        $this->assertGreaterThan(0, $size['width']);
        $this->assertGreaterThan(0, $size['height']);
    }
}
