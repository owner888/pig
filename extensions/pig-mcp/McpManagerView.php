<?php

declare(strict_types=1);

namespace PigMcp;

use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\Tui;
use Pig\Tui\Width;

/**
 * The `/mcp` manager view — upstream's `extensions/mcp/ui.js`: menus that rebuild while servers
 * connect, a read-only status screen, and the sign-in screen that takes a pasted redirect URL.
 *
 * One component that `HookUi::custom()` shows and whose contents are swapped as the manager moves
 * between screens. Each screen parks the caller on a `Deferred` and the keys resume it — the same
 * trick every hook dialog uses, which is what lets `manage()` read as a loop over menus.
 *
 * A `Container` **and** an `InputHandler`: a container given the focus eats every key, which is
 * the trap `SettingsSubmenu` exists for.
 */
final class McpManagerView extends Container implements InputHandler
{
    private const int MAX_VISIBLE_ITEMS = 12;

    /** @var Closure(string): void|null what the current screen does with a key */
    private ?Closure $onKey = null;

    private ?Input $inputTarget = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly Palette $palette,
    ) {
        $this->frame('MCP servers', [new Text($palette->fg('muted', 'Loading…'), 1, 1)]);
    }

    /**
     * Show a menu built by `$build` and answer the chosen value, or null for cancel. `$subscribe`
     * is asked to call back when the servers change, and the menu is rebuilt in place — a server
     * connecting while the list is open updates its row — keeping the selection where it was.
     *
     * @param Closure(): array{title: string, items: list<SelectItem>, details?: ?string, error?: ?string, empty?: string, selected?: ?string, confirmLabel: string, cancelLabel: string} $build
     * @param Closure(Closure(): void): Closure(): void|null $subscribe
     */
    public function menu(Closure $build, ?Closure $subscribe = null): ?string
    {
        $answer = new Deferred();
        $unsubscribe = null;
        $selected = null;

        $finish = static function (?string $value) use ($answer, &$unsubscribe): void {
            if ($answer->isComplete()) {
                return;
            }

            if ($unsubscribe !== null) {
                $unsubscribe();
            }

            $answer->complete($value);
        };

        $render = function () use ($build, &$selected, $finish): void {
            $menu = $build();
            $wanted = $selected ?? ($menu['selected'] ?? null);
            $body = [];

            if (($menu['details'] ?? null) !== null) {
                $body[] = new Text($this->palette->fg('muted', $menu['details']), 1, 0);
            }

            if (($menu['error'] ?? null) !== null) {
                $body[] = new Text($this->palette->fg('error', $menu['error']), 1, 0);
            }

            $body[] = new Spacer(1);
            $footer = "enter {$menu['confirmLabel']} • esc {$menu['cancelLabel']}";

            if ($menu['items'] === []) {
                $body[] = new Text($this->palette->fg('muted', $menu['empty'] ?? 'Nothing to show.'), 1, 0);
                $this->show($this->frame($menu['title'], $body, "esc {$menu['cancelLabel']}"), static function (string $data) use ($finish): void {
                    if (Keys::isEscape($data)) {
                        $finish(null);
                    }
                });

                return;
            }

            $list = new SelectList($menu['items'], min(count($menu['items']), self::MAX_VISIBLE_ITEMS), $this->palette->selectListTheme());

            foreach ($menu['items'] as $index => $item) {
                if ($item->value === $wanted) {
                    $list->setSelectedIndex($index);
                    break;
                }
            }

            $selected = $list->selectedItem()?->value;
            $list->setSelectionChangeHandler(static function (SelectItem $item) use (&$selected): void {
                $selected = $item->value;
            });
            $list->setSelectHandler(static fn (SelectItem $item) => $finish($item->value));
            $list->setCancelHandler(static fn () => $finish(null));
            $body[] = $list;

            $this->show($this->frame($menu['title'], $body, $footer), static fn (string $data) => $list->handleInput($data));
        };

        $render();

        if ($subscribe !== null) {
            $unsubscribe = $subscribe(static function () use ($answer, $render): void {
                if (!$answer->isComplete()) {
                    $render();
                }
            });
        }

        return $answer->future->await();
    }

    /** A read-only screen: a title and a sentence, while something happens. */
    public function status(string $title, string $message): void
    {
        $this->show($this->frame($title, [new Spacer(1), new Text($this->palette->fg('muted', $message), 1, 0)]), null);
    }

    /**
     * The sign-in screen: the authorization URL, and a box for the redirect URL when the browser
     * runs somewhere that cannot reach this machine. Answers the pasted URL, or null when the
     * sign-in finished some other way (`$signal`) or was cancelled.
     */
    public function redirectUrl(string $title, string $authorizationUrl, AbortSignal $signal): ?string
    {
        if ($signal->aborted()) {
            return null;
        }

        $answer = new Deferred();
        $listener = null;

        $finish = static function (?string $value) use ($answer, $signal, &$listener): void {
            if ($answer->isComplete()) {
                return;
            }

            if ($listener !== null) {
                $signal->removeListener($listener);
            }

            $answer->complete($value);
        };

        $listener = $signal->onAbort(static fn () => $finish(null));

        $input = new Input();
        $body = [
            new Spacer(1),
            new Text($this->palette->fg('muted', 'Approve access in your browser. If it did not open, visit:'), 1, 0),
            new Text($this->palette->fg('accent', $authorizationUrl), 1, 0),
            new Spacer(1),
            new Text($this->palette->fg('muted', 'If the browser runs on another machine, paste the URL it was redirected to:'), 1, 0),
            $input,
        ];

        $this->show($this->frame($title, $body, 'enter submit • esc cancel'), static function (string $data) use ($input, $finish): void {
            if (Keys::isEnter($data)) {
                $value = trim($input->value());

                if ($value !== '') {
                    $finish($value);
                }

                return;
            }

            if (Keys::isEscape($data)) {
                $finish(null);

                return;
            }

            $input->handleInput($data);
        }, $input);

        return $answer->future->await();
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->onKey !== null) {
            ($this->onKey)($data);
        }

        $this->tui->requestRender();
    }

    #[\Override]
    public function render(int $width): array
    {
        // A line wider than the terminal is fatal here, so a long URL or error is cut.
        return array_map(
            static fn (string $line): string => Width::visible($line) > $width ? Width::truncate($line, $width, '') : $line,
            parent::render($width),
        );
    }

    /** @param list<\Pig\Tui\Component> $body */
    private function frame(string $title, array $body, ?string $footer = null): array
    {
        $rule = $this->palette->of('accent');
        $children = [new Rule($rule), new Text($this->palette->fg('accent', \Pig\Tui\Style::bold($title)), 1, 0), ...$body];

        if ($footer !== null) {
            $children[] = new Spacer(1);
            $children[] = new Text($this->palette->fg('dim', $footer), 1, 0);
        }

        $children[] = new Rule($rule);

        return $children;
    }

    /**
     * @param list<\Pig\Tui\Component> $children
     * @param Closure(string): void|null $onKey
     */
    private function show(array $children, ?Closure $onKey, ?Input $inputTarget = null): void
    {
        $this->clear();

        foreach ($children as $child) {
            $this->addChild($child);
        }

        $this->onKey = $onKey;
        $this->inputTarget = $inputTarget;
        $this->tui->requestRender();
    }
}
