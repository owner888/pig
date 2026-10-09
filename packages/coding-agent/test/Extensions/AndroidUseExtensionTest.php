<?php

declare(strict_types=1);

namespace Pig\Tests\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\Extensions\AndroidUse\AdbClient;
use Pig\Extensions\AndroidUse\InputManager;
use Pig\Extensions\AndroidUse\ScreenCapture;

final class AndroidUseExtensionTest extends TestCase
{
    private string $tempHome;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempHome = sys_get_temp_dir() . '/pig-test-android-' . uniqid();
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

    public function testAndroidUseExtensionLoadsAndRegistersToolsAndSlashCommand(): void
    {
        $root = dirname(__DIR__, 4);
        $extPath = $root . '/extensions/pig-android-use/index.php';
        $this->assertFileExists($extPath);

        [$loaded, $errors] = ExtensionLoader::load($root, cliPaths: [$extPath], home: $this->tempHome);
        $this->assertSame([], $errors);

        $androidExt = null;
        foreach ($loaded as $ext) {
            if ($ext->name === 'pig-android-use') {
                $androidExt = $ext;
                break;
            }
        }

        $this->assertNotNull($androidExt);
        $this->assertSame('pig-android-use', $androidExt->name);

        // Verify slash command
        $commands = $androidExt->api->commands();
        $this->assertArrayHasKey('android', $commands);

        // Verify all 11 tools
        $toolNames = array_map(static fn ($t) => $t->name, $androidExt->api->tools());
        $expectedTools = [
            'android_doctor',
            'android_ready',
            'android_observe',
            'android_tap',
            'android_swipe',
            'android_type_text',
            'android_press_button',
            'android_launch_app',
            'android_connect',
            'android_tree',
            'android_batch',
        ];

        foreach ($expectedTools as $expected) {
            $this->assertContains($expected, $toolNames, "Tool {$expected} should be registered");
        }
    }

    public function testScreenCapturePngDimensions(): void
    {
        // 1. Synthetic valid PNG header: width 1080 (0x00000438), height 2400 (0x00000960)
        $validPng = "\x89PNG\r\n\x1a\n"
                  . "\x00\x00\x00\x0d" // chunk length 13
                  . "IHDR"
                  . "\x00\x00\x04\x38" // 1080
                  . "\x00\x00\x09\x60" // 2400
                  . "\x08\x06\x00\x00\x00";

        $dims = ScreenCapture::pngDimensions($validPng);
        $this->assertNotNull($dims);
        $this->assertSame(1080, $dims['width']);
        $this->assertSame(2400, $dims['height']);

        // 2. Corrupt / non-PNG data
        $this->assertNull(ScreenCapture::pngDimensions('not a png image'));
        $this->assertNull(ScreenCapture::pngDimensions("\x89PNG\r\n\x1a\nshort"));
    }

    public function testScreenCaptureFitDimensions(): void
    {
        // Vertical mobile: 1080 x 2400
        // maxShort: 768, maxLong: 1568
        // 768 / 1080 = 0.7111
        // 1568 / 2400 = 0.6533 -> scale is 0.6533
        $fitted = ScreenCapture::fitDimensions(1080, 2400);
        $this->assertSame(706, $fitted['width']);
        $this->assertSame(1568, $fitted['height']);
        $this->assertLessThanOrEqual(768, $fitted['width']);
        $this->assertLessThanOrEqual(1568, $fitted['height']);

        // Small image below limits stays unchanged
        $small = ScreenCapture::fitDimensions(400, 600);
        $this->assertSame(400, $small['width']);
        $this->assertSame(600, $small['height']);
        $this->assertSame(1.0, $small['scale']);
    }

    public function testInputManagerKeycodes(): void
    {
        $this->assertSame(3, InputManager::BUTTON_KEYCODES['home']);
        $this->assertSame(4, InputManager::BUTTON_KEYCODES['back']);
        $this->assertSame(26, InputManager::BUTTON_KEYCODES['power']);
        $this->assertSame(187, InputManager::BUTTON_KEYCODES['app_switch']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown button 'invalid_btn'");
        InputManager::pressButton(new AdbClient('/bin/echo'), 'invalid_btn');
    }

    public function testInputManagerTapCoordinateScale(): void
    {
        $adb = new AdbClient('/bin/echo');

        // Tap on scaled screenshot (200, 400) with ratio [1.5, 1.5] without jitter
        $res = InputManager::tap($adb, 200.0, 400.0, [1.5, 1.5], humanJitter: false);
        $this->assertSame(300, $res['real_x']);
        $this->assertSame(600, $res['real_y']);

        // With anti-ban human jitter
        $jittered = InputManager::tap($adb, 200.0, 400.0, [1.5, 1.5], humanJitter: true);
        $this->assertGreaterThanOrEqual(298, $jittered['real_x']);
        $this->assertLessThanOrEqual(302, $jittered['real_x']);
    }

    public function testInputManagerSwipeDirectionCalculations(): void
    {
        $adb = new AdbClient('/bin/echo');
        $screen = ['width' => 1000, 'height' => 2000];

        // up swipe scrolls down: start from lower, move up
        $up = InputManager::swipeDirection($adb, 'up', $screen);
        $this->assertSame(500, $up['from_x']);
        $this->assertSame(500, $up['to_x']);
        $this->assertGreaterThan($up['to_y'], $up['from_y']);

        // down swipe scrolls up: start from upper, move down
        $down = InputManager::swipeDirection($adb, 'down', $screen);
        $this->assertLessThan($down['to_y'], $down['from_y']);
    }

    public function testTreeParserParsesCompactNodes(): void
    {
        $rawXml = <<<XML
<?xml version='1.0' encoding='UTF-8' standalone='yes' ?>
<hierarchy rotation="0">
  <node index="0" text="" resource-id="" class="android.widget.FrameLayout" package="com.tencent.mm" content-desc="" checkable="false" checked="false" clickable="false" enabled="true" focusable="false" focused="false" scrollable="false" long-clickable="false" password="false" selected="false" bounds="[0,0][1080,2400]">
    <node index="0" text="微信" resource-id="com.tencent.mm:id/title" class="android.widget.TextView" package="com.tencent.mm" content-desc="" checkable="false" checked="false" clickable="true" enabled="true" focusable="false" focused="false" scrollable="false" long-clickable="false" password="false" selected="false" bounds="[40,120][200,180]" />
    <node index="1" text="" resource-id="" class="android.widget.ImageView" package="com.tencent.mm" content-desc="搜索" checkable="false" checked="false" clickable="true" enabled="true" focusable="false" focused="false" scrollable="false" long-clickable="false" password="false" selected="false" bounds="[900,120][1040,180]" />
  </node>
</hierarchy>
XML;

        $nodes = \Pig\Extensions\AndroidUse\TreeParser::parse($rawXml, [1.0, 1.0], 10);
        $this->assertCount(2, $nodes);

        $this->assertSame('TextView', $nodes[0]['type']);
        $this->assertSame('微信', $nodes[0]['text']);
        $this->assertSame('id/title', $nodes[0]['id']);
        $this->assertSame([40, 120, 160, 60], $nodes[0]['rect']);
        $this->assertTrue($nodes[0]['clickable']);

        $this->assertSame('ImageView', $nodes[1]['type']);
        $this->assertSame('搜索', $nodes[1]['desc']);
    }

    public function testInputManagerBatchExecution(): void
    {
        $adb = new AdbClient('/bin/echo');

        $steps = [
            ['op' => 'tap', 'args' => ['x' => 100, 'y' => 200]],
            ['op' => 'type_text', 'args' => ['text' => 'hello']],
            ['op' => 'press_button', 'args' => ['name' => 'back']],
        ];

        $res = InputManager::batch($adb, $steps, [1.0, 1.0]);
        $this->assertSame(3, $res['completed']);
        $this->assertCount(3, $res['results']);
        $this->assertSame('tap', $res['results'][0]['op']);
        $this->assertSame('type_text', $res['results'][1]['op']);
        $this->assertSame('press_button', $res['results'][2]['op']);
    }
}
