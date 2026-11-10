<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\PreferredProviders;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\CapturesViewportInput;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\TruncatedText;
use Pig\Tui\Container;
use Pig\Tui\Focusable;
use Pig\Tui\Fuzzy;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\Style;

/**
 * The provider list of `/login` and `/logout` — upstream's `OAuthSelectorComponent`
 * (`components/oauth-selector.ts`): a title, a search field, eight rows at a time with each one's
 * kind (`[subscription]`, `[account]`, `[API key]`, when the list mixes them) and status, and the
 * position when it scrolls. Up and down move, Enter picks, Escape cancels, anything else is typed
 * into the search. The preferred partners (`PreferredProviders`) are marked `✦`, and with a `$tui`
 * the selected one's name has its animated brand style (`ProviderBrand`, upstream 683a7a86).
 */
final class OAuthSelectorComponent extends Container implements Focusable, InputHandler, CapturesViewportInput
{
    public bool $focused = false;

    private const int MAX_VISIBLE = 8;

    private readonly Input $search;

    private readonly Container $list;

    /** @var list<AuthSelectorProvider> */
    private array $filtered;

    private int $selected = 0;

    private readonly bool $showAuthTypeLabels;

    private readonly float $animationStart;

    /** @var (Closure(): void)|null */
    private ?Closure $stopAnimation = null;

    /**
     * @param 'login'|'logout' $mode
     * @param list<AuthSelectorProvider> $providers
     * @param Closure(AuthSelectorProvider): void $onSelect
     * @param Closure(): void $onCancel
     */
    public function __construct(
        private readonly string $mode,
        private readonly array $providers,
        private readonly Closure $onSelect,
        private readonly Closure $onCancel,
        string $initialSearchInput = '',
        ?\Pig\Tui\TUI $tui = null,
    ) {
        $this->animationStart = microtime(true);
        $this->filtered = $providers;
        $this->showAuthTypeLabels = count(array_unique(array_map(static fn (AuthSelectorProvider $p): string => $p->authType, $providers))) > 1;
        $border = static fn (string $text): string => Themes::theme()->fg('border', $text);

        $this->addChild(new Rule($border));
        $this->addChild(new Spacer(1));
        $title = $mode === 'login' ? 'Select provider to configure:' : 'Select provider to logout:';
        $this->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('accent', Style::bold($title)), 1, 0));
        $this->addChild(new Spacer(1));

        $this->search = new Input();
        $this->search->setValue($initialSearchInput);
        $this->search->setSubmitHandler(function (): void {
            $this->pick();
        });
        $this->addChild($this->search);
        $this->addChild(new Spacer(1));

        $this->list = new Container();
        $this->addChild($this->list);
        $this->addChild(new Spacer(1));
        $this->addChild(new Rule($border));

        $this->filter($initialSearchInput);

        if ($tui !== null) {
            $this->stopAnimation = ProviderBrand::startAnimation($tui, fn (): bool => $this->selectedBrandStyle() !== null);
        }
    }

    /** Upstream's `dispose()`: stops the brand animation. */
    public function dispose(): void
    {
        ($this->stopAnimation)?->__invoke();
        $this->stopAnimation = null;
    }

    /** @return (Closure(string, float): string)|null */
    private function selectedBrandStyle(): ?Closure
    {
        $provider = $this->filtered[$this->selected] ?? null;

        return $provider === null ? null : ProviderBrand::styleOf($provider->id);
    }

    /** Upstream's `formatAuthSelectorProviderType()`. */
    public static function typeLabel(string $authType, bool $subscription): string
    {
        if ($authType === 'api_key') {
            return 'API key';
        }

        return $subscription ? 'subscription' : 'account';
    }

    /** Upstream's `formatAuthSelectorProviderStatus()`: " ✓ configured", " • not configured", … */
    public static function statusLabel(AuthSelectorProvider $provider): string
    {
        $theme = Themes::theme();
        $status = $provider->status;

        if ($status === null) {
            return $theme->fg('muted', ' • not configured');
        }

        if ($status['type'] !== $provider->authType) {
            return $theme->fg('muted', ' • ') . $theme->fg('warning', self::typeLabel($status['type'], $provider->subscription) . ' configured');
        }

        $source = $status['source'];

        if ($source === null || $source === '' || $source === 'OAuth' || $source === 'stored credential') {
            return $theme->fg('success', ' ✓ configured');
        }

        $shown = preg_match('/^[A-Z][A-Z0-9_]*(?:, [A-Z][A-Z0-9_]*)*$/', $source) === 1 ? "env: {$source}" : $source;

        return $theme->fg('success', " ✓ {$shown}");
    }

    #[\Override]
    public function render(int $width): array
    {
        $this->search->focused = $this->focused;
        $this->drawList();

        return parent::render($width);
    }

    #[\Override]
    public function capturesViewportInput(): bool
    {
        return true;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = Keybindings::getKeybindings();
        $pageIndex = Keybindings::getSelectPageIndex($kb, $data, $this->selected, count($this->filtered), self::MAX_VISIBLE);

        if ($kb->matches($data, 'tui.select.up')) {
            if ($this->filtered !== []) {
                $this->selected = max(0, $this->selected - 1);
            }
        } elseif ($kb->matches($data, 'tui.select.down')) {
            if ($this->filtered !== []) {
                $this->selected = min(count($this->filtered) - 1, $this->selected + 1);
            }
        } elseif ($pageIndex !== null) {
            if ($this->filtered !== []) {
                $this->selected = $pageIndex;
            }
        } elseif ($kb->matches($data, 'tui.select.confirm')) {
            $this->pick();
        } elseif ($kb->matches($data, 'tui.select.cancel')) {
            ($this->onCancel)();
        } else {
            $this->search->handleInput($data);
            $this->filter($this->search->value());
        }
    }

    private function pick(): void
    {
        $provider = $this->filtered[$this->selected] ?? null;

        if ($provider !== null) {
            ($this->onSelect)($provider);
        }
    }

    private function filter(string $query): void
    {
        $this->filtered = $query === ''
            ? $this->providers
            : Fuzzy::filter(
                $this->providers,
                $query,
                static fn (AuthSelectorProvider $p): string => "{$p->name} {$p->id} {$p->authType} {$p->methodName}",
            );
        $this->selected = max(0, min($this->selected, max(0, count($this->filtered) - 1)));
    }

    /** Upstream's `updateList()`, at render time so a theme change reaches it. */
    private function drawList(): void
    {
        $theme = Themes::theme();
        $this->list->clear();
        $count = count($this->filtered);
        $start = max(0, min($this->selected - intdiv(self::MAX_VISIBLE, 2), $count - self::MAX_VISIBLE));
        $end = min($start + self::MAX_VISIBLE, $count);

        for ($i = $start; $i < $end; $i++) {
            $provider = $this->filtered[$i];
            $type = $this->showAuthTypeLabels
                ? $theme->fg('muted', ' [' . self::typeLabel($provider->authType, $provider->subscription) . ']')
                : '';
            // U+2726 is single-width in every terminal, unlike star emoji or ambiguous-width symbols such as U+2605.
            $partner = PreferredProviders::isPreferred($provider->id) ? $theme->fg('muted', ' ✦') : '';
            $brand = $this->stopAnimation !== null ? ProviderBrand::styleOf($provider->id) : null;
            $line = $i === $this->selected
                ? $theme->fg('accent', '→ ') . ($brand !== null ? $brand($provider->name, (microtime(true) - $this->animationStart) * 1000) : $theme->fg('accent', $provider->name))
                : '  ' . $theme->fg('text', $provider->name);
            $this->list->addChild(new TruncatedText($line . $partner . $type . self::statusLabel($provider), 1, 0));
        }

        if ($start > 0 || $end < $count) {
            $this->list->addChild(new TruncatedText($theme->fg('muted', '  (' . ($this->selected + 1) . "/{$count})"), 1, 0));
        }

        if ($count === 0) {
            $message = $this->providers === []
                ? ($this->mode === 'login' ? 'No providers available' : 'No providers logged in. Use /login first.')
                : 'No matching providers';
            $this->list->addChild(new TruncatedText($theme->fg('muted', "  {$message}"), 1, 0));
        }
    }
}
