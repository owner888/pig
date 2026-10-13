<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;

/**
 * A panel an extension puts in the web UI: an entry in the sidebar's Extensions group, and a
 * window the page draws from what `render` returns. pig's own — upstream has no web page.
 *
 * The extension describes the panel; it does not script it. `render` answers a list of blocks, and
 * the page draws them in its own components and the page's theme, so an extension's panel looks
 * like the rest of the page and runs none of the extension's code in the browser. The blocks:
 *
 * - `['type' => 'heading', 'text' => …]`
 * - `['type' => 'text', 'text' => …, 'tone' => 'muted'|'error'|'warning'|'success']` (tone optional)
 * - `['type' => 'list', 'items' => [item, …], 'empty' => …]` — an item is `['title' => …,
 *   'detail' => …, 'meta' => …, 'tone' => …, 'active' => bool, 'actions' => [action, …]]`, all but
 *   `title` optional; `empty` is the text for no items
 * - `['type' => 'meter', 'label' => …, 'value' => 0.0–1.0, 'detail' => …]` — a bar, as quota is drawn
 * - `['type' => 'card', 'title' => …, 'blocks' => [block, …]]` — blocks grouped under a title
 * - `['type' => 'actions', 'actions' => [action, …]]` — a row of buttons
 * - `['type' => 'lazy', 'section' => …, 'placeholder' => …]` — drawn later, from `render` asked
 *   again with that `section`: what needs a network call does not hold the rest of the panel up
 *
 * Where it goes: `placement` is the extension's default — `sidebar`, the Extensions group, or
 * `header`, an entry in the ⋯ menu of the page's header, named by `label` (a short name; the title
 * when there is none). The person can move a panel to the other place in the page; that choice wins.
 *
 * An action is a button: `['label' => …, 'action' => …, 'args' => [...], 'confirm' => …,
 * 'tone' => 'danger', 'title' => …]`. Pressed (and confirmed, when there is a `confirm` question),
 * it calls `action` with its name, `args`, the request and a `HookUi` — the same `confirm()`,
 * `select()`, `input()`, `editor()` and `notify()` a hook has as `$ctx->ui`, drawn by the page as
 * it draws a hook's, so an action can ask before it acts or ask for what it needs. A `notify()` is
 * a line at the top of the panel. The panel is drawn again either way, so an action answers only
 * whether it failed.
 *
 * Every string is text, never HTML. The page's locale is in the request, so the extension answers
 * in it: `$request['locale']` (`zh-CN`, `en`, …).
 */
final readonly class WebPanel
{
    /** Where a panel can be: the sidebar's Extensions group, or the page's header. */
    public const array PLACEMENTS = ['sidebar', 'header'];

    /**
     * @param string|array<string, string> $title the sidebar entry and the window heading; by
     *        locale, falling back to `en` and then to the first one given
     * @param Closure(array{locale: string, section: ?string}): list<array<string, mixed>> $render
     * @param (Closure(string, array<string, mixed>, array{locale: string, section: ?string}, \Pig\CodingAgent\Hooks\HookUi): ?string)|null $action
     *        null when it worked, else why it did not; a throw is the same as its message
     * @param list<string> $commands slash commands that open the panel in the page (`accounts` for `/accounts`)
     * @param 'sidebar'|'header' $placement where the page puts it unless the person moves it
     * @param string|array<string, string>|null $label the ⋯ menu entry's short name, by locale like the title
     */
    public function __construct(
        public string $id,
        public string|array $title,
        public Closure $render,
        public ?Closure $action = null,
        public string $icon = '',
        public array $commands = [],
        public string $placement = 'sidebar',
        public string|array|null $label = null,
    ) {
        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new \InvalidArgumentException("Web panel '{$id}': no placement '{$placement}'. There is " . implode(' and ', self::PLACEMENTS) . '.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException("A web panel id is lower-case letters, digits, '.', '_' and '-': '{$id}' is not one.");
        }

        if (is_array($title) && $title === []) {
            throw new \InvalidArgumentException("Web panel '{$id}' has no title.");
        }
    }

    /** The title in `$locale`, else in English, else the first one there is. */
    public function titleFor(string $locale): string
    {
        return self::inLocale($this->title, $locale);
    }

    /** The ⋯ menu entry's name in `$locale`: the label, or the title without one. */
    public function labelFor(string $locale): string
    {
        return $this->label === null || $this->label === [] ? $this->titleFor($locale) : self::inLocale($this->label, $locale);
    }

    /** @param string|array<string, string> $text */
    private static function inLocale(string|array $text, string $locale): string
    {
        if (is_string($text)) {
            return $text;
        }

        return $text[$locale] ?? $text['en'] ?? (string) array_values($text)[0];
    }

    /**
     * What the sidebar lists.
     *
     * @return array{id: string, title: string, label: string, icon: string, commands: list<string>, placement: string}
     */
    public function describe(string $locale): array
    {
        return [
            'id' => $this->id,
            'title' => $this->titleFor($locale),
            'label' => $this->labelFor($locale),
            'icon' => $this->icon,
            'commands' => $this->commands,
            'placement' => $this->placement,
        ];
    }
}
