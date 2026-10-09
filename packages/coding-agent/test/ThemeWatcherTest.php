<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Colors;
use RuntimeException;

/**
 * pig's own: upstream's watcher has no test. pig's polls on the event loop, so this checks it reloads
 * an edited custom theme, reports a broken edit instead of ignoring it, and stops when asked.
 */
final class ThemeWatcherTest extends TestCase
{
    use ThemeTestEnvironment;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownThemeEnvironment();
    }

    /** @param array<string, mixed> $json */
    private function write(string $path, array $json): void
    {
        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        // A new size as well as a new mtime, so a save within the same second is still seen.
        file_put_contents($path, str_repeat(' ', random_int(1, 50)), FILE_APPEND);
    }

    private static function runFor(float $seconds): void
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            Loop::get()->tick();
        }
    }

    private static function runUntil(callable $predicate, float $timeout = 2.5): void
    {
        $until = microtime(true) + $timeout;
        while (microtime(true) < $until) {
            Loop::get()->tick();
            if ($predicate()) {
                return;
            }
        }
    }

    public function testReloadsAnEditedCustomThemeAndReportsABrokenEdit(): void
    {
        $path = $this->customThemesDir() . '/mine.json';
        $json = [...self::builtinThemeJson('dark'), 'name' => 'mine'];
        $this->write($path, $json);
        $changes = 0;
        Themes::onThemeChange(static function () use (&$changes): void {
            $changes++;
        });

        $this->assertSame(['success' => true], Themes::setTheme('mine', true));
        $this->assertSame(1, $changes);

        $json['colors']['accent'] = '#123456';
        $this->write($path, $json);
        self::runUntil(static fn (): bool => $changes >= 2);
        $this->assertSame(2, $changes);
        $this->assertSame('#123456', Colors::colorToHex(Themes::theme()->colors()['accent']));

        file_put_contents($path, '{ broken');
        try {
            self::runFor(2.5);
            $this->fail('A broken edit should be reported');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Reloading theme "mine"', $error->getMessage());
        }
        $this->assertSame('mine', Themes::theme()->name, 'the last good theme stays');

        Themes::stopThemeWatcher();
        $this->assertTrue(Loop::get()->isIdle());
    }
}
