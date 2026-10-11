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
 * An action is a button: `['label' => …, 'action' => …, 'args' => [...], 'confirm' => …,
 * 'tone' => 'danger', 'title' => …]`. Pressed (and confirmed, when there is a `confirm` question),
 * it calls `action` with its name and `args`; the panel is drawn again either way, so an action
 * answers only whether it failed.
 *
 * Every string is text, never HTML. The page's locale is in the request, so the extension answers
 * in it: `$request['locale']` (`zh-CN`, `en`, …).
 */
final readonly class WebPanel
{
    /**
     * @param string|array<string, string> $title the sidebar entry and the window heading; by
     *        locale, falling back to `en` and then to the first one given
     * @param Closure(array{locale: string, section: ?string}): list<array<string, mixed>> $render
     * @param (Closure(string, array<string, mixed>, array{locale: string, section: ?string}): ?string)|null $action
     *        null when it worked, else why it did not; a throw is the same as its message
     * @param list<string> $commands slash commands that open the panel in the page (`accounts` for `/accounts`)
     */
    public function __construct(
        public string $id,
        public string|array $title,
        public Closure $render,
        public ?Closure $action = null,
        public string $icon = '',
        public array $commands = [],
    ) {
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
        if (is_string($this->title)) {
            return $this->title;
        }

        return $this->title[$locale] ?? $this->title['en'] ?? (string) array_values($this->title)[0];
    }

    /**
     * What the sidebar lists.
     *
     * @return array{id: string, title: string, icon: string, commands: list<string>}
     */
    public function describe(string $locale): array
    {
        return ['id' => $this->id, 'title' => $this->titleFor($locale), 'icon' => $this->icon, 'commands' => $this->commands];
    }
}
