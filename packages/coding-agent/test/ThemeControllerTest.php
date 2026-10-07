<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\Async\Loop;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\InteractiveThemeController;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\RgbColorValue;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;
use Pig\Tui\TUI;

/**
 * Upstream's `theme-controller.test.ts`. Upstream's vitest mock of the TUI is a PHPUnit mock whose calls
 * are recorded on this test; upstream's `flush()` (one macrotask) is a few ticks of pig's loop.
 */
final class ThemeControllerTest extends TestCase
{
    use ThemeTestEnvironment;

    /** @var Closure(int, ?Closure): Future what the mocked `queryTerminalColors()` does */
    private Closure $queryTerminalColors;

    private int $queryCount = 0;

    /** @var list<bool> */
    private array $notificationCalls = [];

    private ?Closure $terminalColorSchemeListener = null;

    private int $unsubscribeCount = 0;

    private int $requestRenderCount = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpThemeEnvironment();
        $this->queryTerminalColors = static fn (int $timeoutMs, ?Closure $onLateReply): Future => Future::complete(new TerminalColors());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownThemeEnvironment();
    }

    private static function dark(): TerminalColors
    {
        return new TerminalColors(new RgbColor(248, 248, 242), new RgbColor(40, 42, 54));
    }

    private static function light(): TerminalColors
    {
        return new TerminalColors(new RgbColor(30, 30, 30), new RgbColor(250, 250, 250));
    }

    private function createUi(): TUI
    {
        $ui = $this->createStub(TUI::class);
        $ui->method('requestRender')->willReturnCallback(function (): void {
            $this->requestRenderCount++;
        });
        $ui->method('setTerminalColorSchemeNotifications')->willReturnCallback(function (bool $enabled): void {
            $this->notificationCalls[] = $enabled;
        });
        $ui->method('onTerminalColorSchemeChange')->willReturnCallback(function (Closure $listener): Closure {
            $this->terminalColorSchemeListener = $listener;

            return function (): void {
                $this->unsubscribeCount++;
            };
        });
        $ui->method('queryTerminalColors')->willReturnCallback(function (int $timeoutMs, ?Closure $onLateReply = null): Future {
            $this->queryCount++;

            return ($this->queryTerminalColors)($timeoutMs, $onLateReply);
        });

        return $ui;
    }

    private function resolveQueriesWith(TerminalColors $colors): void
    {
        $this->queryTerminalColors = static fn (int $timeoutMs, ?Closure $onLateReply): Future => Future::complete($colors);
    }

    /** @param Closure(): Settings $getSettingsManager */
    private function createController(TUI $ui, Closure $getSettingsManager, ?string $initialThemeSetting = null): InteractiveThemeController
    {
        return new InteractiveThemeController(
            $ui,
            getSettingsManager: $getSettingsManager,
            showError: static function (string $message): void {
            },
            onChanged: static function (): void {
            },
            initialThemeSetting: $initialThemeSetting,
        );
    }

    private function emitTerminalColorScheme(string $terminalTheme): void
    {
        $this->assertNotNull($this->terminalColorSchemeListener);
        ($this->terminalColorSchemeListener)($terminalTheme);
    }

    private static function flush(): void
    {
        for ($tick = 0; $tick < 5; $tick++) {
            Loop::get()->tick();
        }
    }

    public function testUsesTheInitialThemeWithoutPersistingIt(): void
    {
        $ui = $this->createUi();
        $manager = Settings::inMemory(['theme' => 'dark']);
        $controller = $this->createController($ui, static fn (): Settings => $manager, 'light');

        $this->assertSame('light', Themes::theme()->name);
        $this->assertSame('light', $controller->getThemeSelection());
        $controller->applyFromSettings();
        self::flush();

        $this->assertSame(1, $this->queryCount);
        // Upstream spies on `setTheme()` and `flush()`; pig's `Settings` is final, so the setting itself is checked.
        $this->assertSame('dark', $manager->theme());
    }

    public function testAppliesTheThemeImmediatelyAndLetsStartupWaitForTheColors(): void
    {
        $ui = $this->createUi();
        $answer = new Deferred();
        $this->queryTerminalColors = static fn (int $timeoutMs, ?Closure $onLateReply): Future => $answer->future;
        $controller = $this->createController($ui, static fn (): Settings => Settings::inMemory());
        $controller->applyFromSettings();

        // Grayscale until the terminal answers.
        $this->assertSame('system', Themes::theme()->name);
        $this->assertSame("\x1b[39m", Themes::theme()->getFgAnsi('error'));

        $answer->complete(self::dark());
        $wait = $controller->waitForTerminalColors();
        for ($tick = 0; $tick < 10 && !$wait->isComplete(); $tick++) {
            Loop::get()->tick();
        }
        $this->assertTrue($wait->isComplete());
        $this->assertMatchesRegularExpression('/^\x1b\[38;/', Themes::theme()->getFgAnsi('error'));
    }

    public function testFallsBackToPaletteIndicesThenAppliesColorsThatArriveAfterTheTimeout(): void
    {
        $ui = $this->createUi();
        $lateReply = null;
        $this->queryTerminalColors = static function (int $timeoutMs, ?Closure $onLateReply) use (&$lateReply): Future {
            $lateReply = $onLateReply;

            return Future::complete(new TerminalColors());
        };
        $controller = $this->createController($ui, static fn (): Settings => Settings::inMemory());
        $controller->applyFromSettings();
        self::flush();
        $this->assertSame("\x1b[38;5;1m", Themes::theme()->getFgAnsi('error'));

        $this->assertInstanceOf(Closure::class, $lateReply);
        $lateReply(self::dark());
        $this->assertInstanceOf(RgbColorValue::class, Themes::theme()->colors()['error']);
        $this->assertSame('rgb', Themes::theme()->colors()['error']->kind);
    }

    public function testReQueriesTheColorsOnAppearanceChangesAndLetsThemDecide(): void
    {
        $ui = $this->createUi();
        $this->resolveQueriesWith(self::light());
        $controller = $this->createController($ui, static fn (): Settings => Settings::inMemory(), 'light/dark');
        $controller->applyFromSettings();
        $this->assertContains(true, $this->notificationCalls);
        self::flush();
        $this->assertSame('light', Themes::theme()->name);

        $this->resolveQueriesWith(self::dark());
        // The report says light, but the terminal renders dark.
        $this->emitTerminalColorScheme('light');
        self::flush();
        $this->assertSame('dark', Themes::theme()->name);
    }

    public function testUsesTheReportedSchemeForTheSystemThemeWhenTheTerminalReportsNoColors(): void
    {
        $this->setThemeTestEnv('COLORFGBG', '');
        $ui = $this->createUi();
        $controller = $this->createController($ui, static fn (): Settings => Settings::inMemory());
        $controller->applyFromSettings();
        self::flush();
        $this->assertSame('dark', Themes::theme()->appearance());

        $this->emitTerminalColorScheme('light');
        $this->assertSame('light', Themes::theme()->appearance());
        $this->assertSame('light', $controller->getTerminalTheme());
    }

    public function testReRendersOnlyWhenTheReportedColorsChange(): void
    {
        $ui = $this->createUi();
        $controller = $this->createController($ui, static fn (): Settings => Settings::inMemory(['theme' => 'dark']));
        $query = function (TerminalColors $colors) use ($controller): void {
            $this->resolveQueriesWith($colors);
            $controller->applyFromSettings();
            self::flush();
        };

        $query(self::dark());
        // A timeout keeps the known colors; erasing them would count as a change and re-render.
        $query(new TerminalColors());
        $query(self::dark());
        $this->assertSame(1, $this->requestRenderCount);
    }

    public function testDisablesTerminalAppearanceUpdatesWhenDisposed(): void
    {
        $ui = $this->createUi();
        $controller = $this->createController($ui, static fn (): Settings => Settings::inMemory(['theme' => 'light/dark']));
        $controller->applyFromSettings();
        self::flush();

        $controller->dispose();

        $this->assertFalse($this->notificationCalls[count($this->notificationCalls) - 1]);
        $this->assertSame(1, $this->unsubscribeCount);
    }

    public function testLetsAnExplicitSelectionReplaceTheInitialTheme(): void
    {
        $ui = $this->createUi();
        $firstManager = Settings::inMemory(['theme' => 'dark']);
        $secondManager = Settings::inMemory(['theme' => 'light']);
        $manager = $firstManager;
        $controller = $this->createController($ui, static function () use (&$manager): Settings {
            return $manager;
        }, 'light');
        $controller->applyFromSettings();

        $this->assertSame(['success' => true], $controller->setThemeName('dark'));
        $manager = $secondManager;
        $controller->applyFromSettings();
        self::flush();

        $this->assertSame('dark', $controller->getThemeSelection());
        $this->assertSame('dark', Themes::theme()->name);
    }

    public function testReloadsThemeSettingsWhenNoInitialThemeWasSupplied(): void
    {
        $ui = $this->createUi();
        $firstManager = Settings::inMemory(['theme' => 'dark']);
        $secondManager = Settings::inMemory(['theme' => 'light']);
        $manager = $firstManager;
        $controller = $this->createController($ui, static function () use (&$manager): Settings {
            return $manager;
        });
        $controller->applyFromSettings();

        // Upstream's `applyOverrides()`; an in-memory `Settings` writes nowhere.
        $firstManager->set('theme', 'light');
        $controller->applyFromSettings();
        $this->assertSame('light', Themes::theme()->name);

        $secondManager->set('theme', 'dark');
        $manager = $secondManager;
        $controller->applyFromSettings();
        $this->assertSame('dark', Themes::theme()->name);
    }
}
