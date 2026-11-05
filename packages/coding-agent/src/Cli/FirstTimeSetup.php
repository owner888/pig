<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Interactive\PigLogo;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Terminal;
use Pig\Tui\TuiMainScreen;

/**
 * The first run's two questions — upstream's `FirstTimeSetupComponent` and `showFirstTimeSetup()`
 * (`cli/startup-ui.ts`): a theme, previewed as the cursor moves, and whether pig may name itself
 * to the providers that ask. Enter goes on, Escape skips the rest and saves nothing.
 *
 * Shown when there is no `~/.pig/agent/settings.json` yet and the mode is interactive — the same
 * test as upstream's, less its gates on the official build and experimental features, which pig
 * has no counterpart to. `PIG_CODING_AGENT_DIR` pointing somewhere is a deliberate setup, not a
 * first run, and skips it as upstream's `ENV_AGENT_DIR` does.
 *
 * Upstream's second question is anonymous usage analytics. pig collects none, so its second
 * question is the one setting of that kind it has, `enableInstallTelemetry`: the `User-Agent`
 * and the provider attribution headers say "pig" (`ProviderAttribution`), or say nothing.
 */
final class FirstTimeSetup extends Container implements InputHandler
{
    private const array THEMES = [
        [Themes::SYSTEM_THEME_NAME, 'System (matches your terminal colors)'],
        ['dark', 'Dark'],
        ['light', 'Light'],
    ];

    private const array TELEMETRY = [
        [true, 'Tell providers this is pig (the default)'],
        [false, "Don't"],
    ];

    /** @var 'theme'|'telemetry' */
    private string $step = 'theme';

    private int $themeIndex = 0;

    private int $telemetryIndex = 0;

    /**
     * @param Closure(string): void $onThemePreview
     * @param Closure(string, bool): void $onSubmit the theme and whether providers are told
     * @param Closure(): void $onCancel
     */
    public function __construct(
        private readonly Closure $onThemePreview,
        private readonly Closure $onSubmit,
        private readonly Closure $onCancel,
    ) {
        $this->update();
    }

    /** Whether this run is the first: no settings file of the person's own yet. */
    public static function shouldRun(?string $settingsPath = null): bool
    {
        if ((getenv('PIG_CODING_AGENT_DIR') ?: '') !== '') {
            return false;
        }

        return !is_file($settingsPath ?? Config::home() . '/settings.json');
    }

    /**
     * Ask, on a screen of its own, and save what was answered; nothing when Escape skipped it.
     * The theme shown while asking is put back to the system one unless a choice was made.
     */
    public static function run(Settings $settings, ?Terminal $terminal = null): void
    {
        $tui = new TuiMainScreen($terminal ?? new ProcessTerminal());
        $chosen = null;
        Themes::setTheme(Themes::SYSTEM_THEME_NAME);

        $setup = new self(
            onThemePreview: static function (string $name) use ($tui): void {
                Themes::setTheme($name);
                $tui->invalidate();
                $tui->requestRender();
            },
            onSubmit: static function (string $theme, bool $telemetry) use (&$chosen): void {
                $chosen = [$theme, $telemetry];
                Loop::get()->stop();
            },
            onCancel: static function (): void {
                Loop::get()->stop();
            },
        );

        $tui->addChild($setup);
        $tui->setFocus($setup);

        Async::run(static function () use ($tui): void {
            $tui->start();
            Loop::get()->run();
        });

        $tui->stop();

        if ($chosen === null) {
            Themes::setTheme(Themes::SYSTEM_THEME_NAME);

            return;
        }

        [$theme, $telemetry] = $chosen;
        $settings->setTheme($theme);
        $settings->setInstallTelemetry($telemetry);
    }

    /** Every change redraws the whole thing, so a previewed theme recolours all of it — upstream's `update()`. */
    #[\Override]
    public function invalidate(): void
    {
        $this->update();
        parent::invalidate();
    }

    private function update(): void
    {
        $theme = Themes::theme();
        $this->clear();
        $this->addChild(new Rule());
        $this->addChild(new Spacer(1));
        $this->addChild(new Text(PigLogo::wordmark(), 1, 0));
        $this->addChild(new Spacer(1));
        $this->addChild(new Text($theme->fg('accent', $theme->bold('Welcome to pig, the coding agent in PHP.')), 1, 0));
        $this->addChild(new Spacer(1));

        if ($this->step === 'theme') {
            $this->addChild(new Text($theme->fg('text', 'Pick a theme.'), 1, 0));
            $this->addChild(new Spacer(1));
            $this->options(array_column(self::THEMES, 1), $this->themeIndex);
        } else {
            $this->addChild(new Text($theme->fg('text', 'May pig say who it is to the model providers?'), 1, 0));
            $this->addChild(new Text($theme->fg('muted', "Its requests then carry \"pig\" in the User-Agent and the attribution header a\nprovider asks for. Nothing else is sent, and nothing is collected by pig. The\nsetting is enableInstallTelemetry in settings.json; /settings changes it later."), 1, 0));
            $this->addChild(new Spacer(1));
            $this->options(array_column(self::TELEMETRY, 1), $this->telemetryIndex);
        }

        $this->addChild(new Spacer(1));
        $this->addChild(new Text($theme->fg('muted', '↑↓ navigate  Enter ' . ($this->step === 'theme' ? 'continue' : 'finish') . '  Esc skip setup'), 1, 0));
        $this->addChild(new Spacer(1));
        $this->addChild(new Rule());
    }

    /** @param list<string> $labels */
    private function options(array $labels, int $selected): void
    {
        $theme = Themes::theme();

        foreach ($labels as $index => $label) {
            $isSelected = $index === $selected;
            $this->addChild(new Text(($isSelected ? $theme->fg('accent', '→ ') : '  ') . $theme->fg($isSelected ? 'accent' : 'text', $label), 1, 0));
        }
    }

    private function move(int $delta): void
    {
        if ($this->step === 'theme') {
            $next = max(0, min(count(self::THEMES) - 1, $this->themeIndex + $delta));

            if ($next !== $this->themeIndex) {
                $this->themeIndex = $next;
                ($this->onThemePreview)(self::THEMES[$next][0]);
            }
        } else {
            $this->telemetryIndex = max(0, min(count(self::TELEMETRY) - 1, $this->telemetryIndex + $delta));
        }

        $this->update();
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = Keybindings::getKeybindings();

        if ($kb->matches($data, 'tui.select.up') || $data === 'k') {
            $this->move(-1);
        } elseif ($kb->matches($data, 'tui.select.down') || $data === 'j') {
            $this->move(1);
        } elseif ($kb->matches($data, 'tui.select.confirm') || $data === "\n") {
            if ($this->step === 'theme') {
                $this->step = 'telemetry';
                $this->update();
            } else {
                ($this->onSubmit)(self::THEMES[$this->themeIndex][0], self::TELEMETRY[$this->telemetryIndex][0]);
            }
        } elseif ($kb->matches($data, 'tui.select.cancel')) {
            ($this->onCancel)();
        }
    }
}
