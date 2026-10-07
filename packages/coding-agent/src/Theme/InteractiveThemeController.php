<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Closure;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\CodingAgent\Settings;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;
use Pig\Tui\TUI;

/**
 * Applies the theme setting and keeps it in sync with the terminal — upstream's `theme-controller.ts`.
 * The theme applies immediately, and the terminal's colors update it when they arrive; the system theme
 * renders in grayscale until then. Callers that bake theme colors into content can wait for the colors
 * with `waitForTerminalColors()`.
 *
 * Upstream's module function `requestTerminalColors()` is a static method here. Upstream's
 * `SettingsManager.getThemeSetting()` is pig's `Settings::theme()`, which returns the raw setting
 * (a theme name or a `light/dark` pair) the same way. Upstream's promises are `Future`s.
 */
final class InteractiveThemeController
{
    /**
     * How long the system theme stays grayscale before falling back to palette indices. Terminals answer
     * the trailing DA1 request right after the color replies, so this only matters for terminals that
     * answer neither. Replies arriving later still apply.
     */
    private const int TERMINAL_QUERY_TIMEOUT_MS = 100;

    private ?string $currentThemeSetting;

    /** Last reported colors; a query that times out keeps them instead of erasing them. */
    private ?TerminalColors $terminalColors = null;

    private ?string $activeThemeName;

    private bool $autoSyncEnabled = false;

    /** @var (Closure(): void)|null */
    private ?Closure $terminalColorSchemeUnsubscribe = null;

    /** @var Future<null> Settles when the latest color query completed or timed out, and its colors applied. */
    private Future $terminalColorQuery;

    /**
     * Query the terminal's colors and pass them to `$apply` when the query completes or times out, and again
     * if the terminal answers after the timeout. Settles after the first apply.
     *
     * Upstream treats a query that throws or rejects like a terminal that reports nothing. Here a throw
     * propagates and a failed query fails the returned future: `TuiBase::queryTerminalColors()` never
     * rejects, so a failure is a bug to see, not a terminal to accommodate.
     *
     * @param Closure(TerminalColors): void $apply
     * @return Future<null>
     */
    public static function requestTerminalColors(TUI $ui, Closure $apply): Future
    {
        $query = $ui->queryTerminalColors(self::TERMINAL_QUERY_TIMEOUT_MS, $apply);
        $settled = new Deferred();
        $query->onComplete(static function (?\Throwable $error, mixed $colors) use ($apply, $settled): void {
            if ($error !== null) {
                $settled->error($error);

                return;
            }
            $apply($colors);
            $settled->complete();
        });

        return $settled->future;
    }

    private static function sameRgb(?RgbColor $a, ?RgbColor $b): bool
    {
        return $a === $b || ($a !== null && $b !== null && $a->r === $b->r && $a->g === $b->g && $a->b === $b->b);
    }

    private static function sameTerminalColors(TerminalColors $a, TerminalColors $b): bool
    {
        if (!self::sameRgb($a->foreground, $b->foreground) || !self::sameRgb($a->background, $b->background)) {
            return false;
        }
        if ($a->palette === $b->palette) {
            return true;
        }
        if ($a->palette === null || $b->palette === null || count($a->palette) !== count($b->palette)) {
            return false;
        }
        foreach ($a->palette as $index => $color) {
            if (!self::sameRgb($color, $b->palette[$index] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Closure(): Settings $getSettingsManager
     * @param Closure(string): void $showError
     * @param Closure(): void $onChanged
     */
    public function __construct(
        private readonly TUI $ui,
        private readonly Closure $getSettingsManager,
        private readonly Closure $showError,
        private readonly Closure $onChanged,
        ?string $initialThemeSetting = null,
    ) {
        $this->terminalColorQuery = Future::complete();
        $this->currentThemeSetting = $initialThemeSetting;
        $this->activeThemeName = $this->resolveThemeName();
        // The system theme starts in grayscale; color follows once the terminal reports its colors.
        Themes::markTerminalColorsPending();
        // A theme that does not load falls back to the system theme here; applyFromSettings() reports it.
        Themes::initTheme($this->activeThemeName, true);
        $this->bindTerminalColorSchemeListener();
    }

    public function rebindTui(): void
    {
        if ($this->terminalColorSchemeUnsubscribe !== null) {
            ($this->terminalColorSchemeUnsubscribe)();
        }
        $this->bindTerminalColorSchemeListener();
        $this->ui->setTerminalColorSchemeNotifications($this->autoSyncEnabled);
    }

    /**
     * Apply the theme setting now and query the terminal's colors, which update the theme when they arrive.
     * Theme pairs and the system theme follow terminal appearance changes.
     */
    public function applyFromSettings(): void
    {
        $themeSetting = $this->getThemeSetting();
        $themeName = $this->resolveThemeName();
        $this->setAutoSync(Themes::parseAutoThemeSetting($themeSetting) !== null || $themeName === Themes::SYSTEM_THEME_NAME);
        $this->applyThemeName($themeName, $themeSetting !== null);
        $this->queryTerminalColors();
    }

    /**
     * Wait until the latest color query completed or timed out. Content that bakes theme colors into
     * strings, such as the startup header, should be built after this. Terminals answer the DA1 request
     * right after the color replies, so this only takes the full timeout when a terminal answers nothing.
     *
     * @return Future<null>
     */
    public function waitForTerminalColors(): Future
    {
        return $this->terminalColorQuery;
    }

    public function getThemeSelection(): ?string
    {
        return $this->currentThemeSetting ?? ($this->getSettingsManager)()->theme() ?? $this->activeThemeName;
    }

    /** @return array{success: bool, error?: string} */
    public function setThemeName(string $themeName, bool $showError = false): array
    {
        $this->setAutoSync($themeName === Themes::SYSTEM_THEME_NAME);
        $result = $this->applyThemeName($themeName, $showError);
        if ($result['success']) {
            $this->currentThemeSetting = $themeName;
        }

        return $result;
    }

    public function setThemeSetting(string $themeSetting): void
    {
        $this->currentThemeSetting = $themeSetting;
        $this->applyFromSettings();
    }

    /** @return array{success: bool, error?: string} */
    public function setThemeInstance(Theme $themeInstance): array
    {
        $this->setAutoSync(false);
        Themes::setThemeInstance($themeInstance);
        $this->activeThemeName = '<in-memory>';
        $this->notifyChanged();

        return ['success' => true];
    }

    public function preview(string $themeSettingOrName): void
    {
        $themeName = Themes::resolveThemeSetting($themeSettingOrName, Themes::getTerminalTheme()) ?? $this->activeThemeName;
        if ($themeName === null || $themeName === '') {
            return;
        }
        if (Themes::setTheme($themeName, true)['success']) {
            $this->ui->invalidate();
            $this->ui->requestRender();
        }
    }

    public function disableAutoSync(): void
    {
        $this->setAutoSync(false);
    }

    public function dispose(): void
    {
        $this->setAutoSync(false);
        if ($this->terminalColorSchemeUnsubscribe !== null) {
            ($this->terminalColorSchemeUnsubscribe)();
        }
        $this->terminalColorSchemeUnsubscribe = null;
    }

    /** @return 'dark'|'light' */
    public function getTerminalTheme(): string
    {
        return Themes::getTerminalTheme();
    }

    private function getThemeSetting(): ?string
    {
        return $this->currentThemeSetting ?? ($this->getSettingsManager)()->theme();
    }

    /** The theme for the current setting and terminal appearance. Without a setting, pi uses the system theme. */
    private function resolveThemeName(): string
    {
        return Themes::resolveThemeSetting($this->getThemeSetting(), Themes::getTerminalTheme()) ?? Themes::SYSTEM_THEME_NAME;
    }

    /** @return array{success: bool, error?: string} */
    private function applyThemeName(string $themeName, bool $showError = false): array
    {
        $result = Themes::setTheme($themeName, true);
        $this->activeThemeName = $result['success'] ? $themeName : Themes::SYSTEM_THEME_NAME;
        $this->notifyChanged();
        if (!$result['success'] && $showError) {
            ($this->showError)("Failed to load theme \"{$themeName}\": {$result['error']}\nFell back to the system theme.");
        }

        return $result;
    }

    /** Query the terminal's colors without waiting for them; `waitForTerminalColors()` waits for this query. */
    private function queryTerminalColors(): void
    {
        $this->terminalColorQuery = self::requestTerminalColors($this->ui, fn (TerminalColors $colors) => $this->applyTerminalColors($colors));
    }

    /**
     * Record reported colors: themes use the default colors for tokens set to "", the system theme is
     * generated from all of them, and light/dark detection uses them. Re-renders only when they changed.
     */
    private function applyTerminalColors(TerminalColors $reported): void
    {
        $previous = $this->terminalColors;
        $next = new TerminalColors(
            $reported->foreground ?? $previous?->foreground,
            $reported->background ?? $previous?->background,
            $reported->palette ?? $previous?->palette,
        );
        // Re-rendering rebuilds every component, so skip it when nothing changed (including timeouts).
        if ($previous !== null && self::sameTerminalColors($previous, $next)) {
            return;
        }
        $this->terminalColors = $next;
        Themes::setTerminalColors($next);
        $this->reapplyForTerminal();
        $this->ui->invalidate();
        $this->ui->requestRender();
    }

    /**
     * Re-apply the setting after the terminal's colors or appearance changed: regenerate the system theme,
     * or switch the theme of a pair. Themes set through extensions or previews are left alone.
     */
    private function reapplyForTerminal(): void
    {
        if ($this->activeThemeName === '<in-memory>') {
            return;
        }
        $themeName = $this->resolveThemeName();
        if ($themeName === Themes::SYSTEM_THEME_NAME || $themeName !== $this->activeThemeName) {
            $this->applyThemeName($themeName);
        }
    }

    private function setAutoSync(bool $enabled): void
    {
        if ($this->autoSyncEnabled === $enabled) {
            return;
        }
        $this->autoSyncEnabled = $enabled;
        $this->ui->setTerminalColorSchemeNotifications($enabled);
    }

    private function bindTerminalColorSchemeListener(): void
    {
        $this->terminalColorSchemeUnsubscribe = $this->ui->onTerminalColorSchemeChange(
            fn (string $terminalTheme) => $this->applyTerminalColorSchemeChange($terminalTheme),
        );
    }

    /**
     * The terminal reported a light/dark switch. Its colors changed too, so query them again: they decide
     * the appearance. The reported scheme only matters for terminals that do not report their background.
     *
     * @param 'dark'|'light' $terminalTheme
     */
    private function applyTerminalColorSchemeChange(string $terminalTheme): void
    {
        if (!$this->autoSyncEnabled) {
            return;
        }
        $previous = Themes::getTerminalTheme();
        Themes::setTerminalColorScheme($terminalTheme);
        if (Themes::getTerminalTheme() !== $previous) {
            $this->reapplyForTerminal();
        }
        $this->queryTerminalColors();
    }

    private function notifyChanged(): void
    {
        $this->ui->invalidate();
        ($this->onChanged)();
    }
}
