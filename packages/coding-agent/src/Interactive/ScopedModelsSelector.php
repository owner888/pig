<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Ai\Model;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Component;
use Pig\Tui\Components\Input;
use Pig\Tui\Focusable;
use Pig\Tui\Fuzzy;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\Keys;
use Pig\Tui\Width;

/**
 * Which models Ctrl+P cycles through, and in what order — `/scoped-models`.
 *
 * Upstream's `components/scoped-models-selector.ts`. The enabled set is either **null, meaning
 * every model** (no filter), or an explicit ordered list of `provider/id` ids; an explicit list
 * that grows to cover every model collapses back to null (`normalizeEnabled()`). Enabled models
 * are listed first, in their order, then the rest.
 *
 * Changes apply to the session as they are made (`$onChange`) and are written to the
 * `enabledModels` setting only on `app.models.save` (`$onPersist`) — "Session-only" until then,
 * which the header says and the footer's `(unsaved)` repeats.
 *
 * Keys, all through the keybinding registry: `tui.select.confirm` toggles the row,
 * `app.models.enableAll` / `app.models.clearAll` act on every model (or on what the search
 * matches, while there is one), `app.models.toggleProvider` on the row's provider,
 * `app.models.reorderUp` / `reorderDown` move an enabled model, `app.models.save` persists.
 * Ctrl+C clears the search, or closes when it is empty; Escape closes. Everything else is typing.
 *
 * An id in the list that no available model has any more (a pattern saved for a provider whose key
 * has gone) is kept, struck through and marked `[unavailable]`, so saving does not drop it.
 */
final class ScopedModelsSelector implements Component, Focusable, InputHandler
{
    public bool $focused = false;

    private Input $search;

    /** @var array<string, Model> */
    private array $modelsById = [];

    /** @var list<string> */
    private array $allIds = [];

    /** @var list<string>|null null is every model */
    private ?array $enabledIds;

    /** @var list<array{id: string, model: Model|null, enabled: bool}> */
    private array $filtered = [];

    private int $selected = 0;

    private bool $dirty = false;

    private const int MAX_VISIBLE = 8;

    /**
     * @param list<Model>       $models every model there is a key for
     * @param list<string>|null $enabledIds the ids enabled now, in order; null for every model
     * @param Closure(list<string>|null): void $onChange the set or order changed (session only)
     * @param Closure(list<string>|null): void $onPersist `app.models.save`
     * @param Closure(): void $onCancel escape, or ctrl+c with nothing typed
     * @param string|null $refreshStatus upstream's `ModelsConfig.refreshStatus`: a line above the
     *        footer while the catalogs are being refreshed (`setRefreshStatus()` changes it)
     */
    public function __construct(
        array $models,
        ?array $enabledIds,
        private readonly Closure $onChange,
        private readonly Closure $onPersist,
        private readonly Closure $onCancel,
        ?string $refreshStatus = null,
    ) {
        $this->setModels($models);
        $this->enabledIds = $enabledIds;
        $this->search = new Input();
        $this->refreshStatus = $refreshStatus === null ? null : ['message' => $refreshStatus, 'kind' => 'muted'];
        $this->refresh();
    }

    /** @var array{message: string, kind: 'muted'|'success'|'warning'}|null */
    private ?array $refreshStatus;

    /**
     * Upstream's `updateModels()`: the models a catalog refresh left, the selection kept on the
     * same model when it is still there; `$enabledIds` replaces the enabled set when given.
     *
     * @param list<Model>       $models
     * @param list<string>|null $enabledIds
     */
    public function updateModels(array $models, bool $replaceEnabled = false, ?array $enabledIds = null): void
    {
        $selectedId = $this->filtered[$this->selected]['id'] ?? null;

        if ($replaceEnabled) {
            $this->enabledIds = $enabledIds;
        }

        $this->setModels($models);
        $this->refresh();

        foreach ($this->filtered as $index => $item) {
            if ($item['id'] === $selectedId) {
                $this->selected = $index;

                break;
            }
        }
    }

    /**
     * Upstream's `setRefreshStatus()`: the refresh line's text and colour.
     *
     * @param 'muted'|'success'|'warning' $kind
     */
    public function setRefreshStatus(string $message, string $kind): void
    {
        $this->refreshStatus = ['message' => $message, 'kind' => $kind];
    }

    /** @param list<Model> $models */
    private function setModels(array $models): void
    {
        $this->modelsById = [];
        $this->allIds = [];

        foreach ($models as $model) {
            $id = self::id($model);
            $this->modelsById[$id] = $model;
            $this->allIds[] = $id;
        }
    }

    public static function id(Model $model): string
    {
        return "{$model->provider}/{$model->id}";
    }

    /** Upstream's `getModelSearchText()`, which `/model`'s picker matches against too. */
    public static function searchText(Model $model): string
    {
        return "{$model->provider} {$model->provider}/{$model->id} {$model->provider} {$model->id} {$model->name}";
    }

    /**
     * The model's name when it says something its id does not — a renamed override like
     * "MiniMax M3 [work@openrouter, parasail]" for `minimax/minimax-m3` — and null when it only
     * restates the id in other casing or separators, or without a namespace or version suffix
     * ("Claude Sonnet 4.5 (EU)" for `eu.anthropic.claude-sonnet-4-5-20250929-v1:0`). Upstream's
     * `getDistinctModelName()`.
     */
    public static function distinctName(Model $model): ?string
    {
        $name = trim($model->name);
        if ($name === '') {
            return null;
        }

        $words = static fn (string $value): array => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $idWords = $words($model->id);
        $idTokens = array_fill_keys($idWords, true);
        $compactId = implode('', $idWords);

        foreach ($words($name) as $word) {
            // "Single-character words must match a whole ID token: '4' is not covered by
            // 'mistral-small-2603'."
            $covered = isset($idTokens[$word]) || (mb_strlen($word) > 1 && str_contains($compactId, (string) $word));
            if (!$covered) {
                return $name;
            }
        }

        return null;
    }

    /** @return list<string>|null what is enabled now, for a caller (and a test) to read */
    public function enabledIds(): ?array
    {
        return $this->enabledIds;
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->search->invalidate();
    }

    #[\Override]
    public function render(int $width): array
    {
        $theme = Themes::theme();
        $this->search->focused = $this->focused;

        $lines = [
            $theme->fg('accent', $theme->bold('Model Configuration')),
            $theme->fg('muted', Width::truncate('Session-only. ' . self::key('app.models.save') . ' to save to settings.', $width, '…')),
            '',
            ...$this->search->render($width),
            '',
        ];

        $count = count($this->filtered);

        if ($count === 0) {
            $lines[] = $theme->fg('muted', '  No matching models');
        } else {
            $start = max(0, min($this->selected - intdiv(self::MAX_VISIBLE, 2), $count - self::MAX_VISIBLE));
            $end = min($start + self::MAX_VISIBLE, $count);

            for ($i = $start; $i < $end; $i++) {
                $item = $this->filtered[$i];
                $isSelected = $i === $this->selected;
                $id = $item['model']?->id ?? $item['id'];
                $styled = $item['model'] !== null ? $id : $theme->strikethrough($id);
                $name = $item['model'] !== null ? self::distinctName($item['model']) : null;
                $line = ($isSelected ? $theme->fg('accent', '→ ') : '  ')
                    . ($item['model'] !== null && $item['enabled'] ? $theme->fg('accent', '✓ ') : '  ')
                    . ($isSelected ? $theme->fg('accent', $styled) : $styled)
                    . ($name !== null ? $theme->fg('muted', " ({$name})") : '')
                    . $theme->fg('muted', $item['model'] !== null ? " [{$item['model']->provider}]" : ' [unavailable]');
                $lines[] = Width::truncate($line, $width, '…');
            }

            if ($start > 0 || $end < $count) {
                $lines[] = $theme->fg('muted', '  (' . ($this->selected + 1) . "/{$count})");
            }

            $selected = $this->filtered[$this->selected]['model'];
            $lines[] = '';
            $lines[] = $theme->fg('muted', Width::truncate('  ' . ($selected !== null ? "Model Name: {$selected->name}" : 'Model unavailable'), $width, '…'));
        }

        $lines[] = '';

        if ($this->refreshStatus !== null) {
            $lines[] = $theme->fg($this->refreshStatus['kind'], Width::truncate('  ' . $this->refreshStatus['message'], $width, '…'));
        }

        $lines[] = $this->footer($width);

        return $lines;
    }

    private function footer(int $width): string
    {
        $theme = Themes::theme();

        if ($this->enabledIds === null) {
            $countText = 'all enabled';
        } else {
            $available = count(array_filter($this->enabledIds, fn (string $id): bool => isset($this->modelsById[$id])));
            $unavailable = count($this->enabledIds) - $available;
            $countText = "{$available}/" . count($this->allIds) . ' enabled' . ($unavailable > 0 ? " · {$unavailable} unavailable" : '');
        }

        $parts = [
            self::key('tui.select.confirm') . ' toggle',
            self::key('app.models.enableAll') . ' all',
            self::key('app.models.clearAll') . ' clear',
            self::key('app.models.toggleProvider') . ' provider',
            self::key('app.models.reorderUp') . '/' . self::key('app.models.reorderDown') . ' reorder',
            self::key('app.models.save') . ' save',
            $countText,
        ];
        $text = '  ' . implode(' · ', $parts);

        return $this->dirty
            ? Width::truncate($theme->fg('dim', "{$text} ") . $theme->fg('warning', '(unsaved)'), $width, '…')
            : $theme->fg('dim', Width::truncate($text, $width, '…'));
    }

    /** Upstream's `keyDisplayText()`: the first key bound to the action. */
    private static function key(string $action): string
    {
        $keys = Keybindings::getKeybindings()->getKeys($action);

        return $keys === [] ? '(unbound)' : str_replace('escape', 'esc', $keys[0]);
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = Keybindings::getKeybindings();
        $count = count($this->filtered);

        if ($kb->matches($data, 'tui.select.up')) {
            if ($count > 0) {
                $this->selected = $this->selected === 0 ? $count - 1 : $this->selected - 1;
            }

            return;
        }

        if ($kb->matches($data, 'tui.select.down')) {
            if ($count > 0) {
                $this->selected = $this->selected === $count - 1 ? 0 : $this->selected + 1;
            }

            return;
        }

        $up = $kb->matches($data, 'app.models.reorderUp');

        if ($up || $kb->matches($data, 'app.models.reorderDown')) {
            $item = $this->filtered[$this->selected] ?? null;

            if ($this->enabledIds === null || $item === null || !in_array($item['id'], $this->enabledIds, true)) {
                return;
            }

            $delta = $up ? -1 : 1;
            $from = array_search($item['id'], $this->enabledIds, true);
            $to = $from + $delta;

            if ($to >= 0 && $to < count($this->enabledIds)) {
                [$this->enabledIds[$from], $this->enabledIds[$to]] = [$this->enabledIds[$to], $this->enabledIds[$from]];
                $this->selected += $delta;
                $this->changed();
            }

            return;
        }

        if ($kb->matches($data, 'tui.select.confirm')) {
            $item = $this->filtered[$this->selected] ?? null;

            if ($item !== null) {
                $this->enabledIds = $this->toggle($item['id']);
                $this->changed();
            }

            return;
        }

        if ($kb->matches($data, 'app.models.enableAll')) {
            $this->enabledIds = $this->enableAll($this->searchTargets());
            $this->changed();

            return;
        }

        if ($kb->matches($data, 'app.models.clearAll')) {
            $this->enabledIds = $this->clearAll($this->searchTargets());
            $this->changed();

            return;
        }

        if ($kb->matches($data, 'app.models.toggleProvider')) {
            $model = $this->filtered[$this->selected]['model'] ?? null;

            if ($model !== null) {
                $providerIds = array_values(array_filter(
                    $this->allIds,
                    fn (string $id): bool => $this->modelsById[$id]->provider === $model->provider,
                ));
                $allEnabled = array_reduce($providerIds, fn (bool $carry, string $id): bool => $carry && $this->isEnabled($id), true);
                $this->enabledIds = $allEnabled ? $this->clearAll($providerIds) : $this->enableAll($providerIds);
                $this->changed();
            }

            return;
        }

        if ($kb->matches($data, 'app.models.save')) {
            ($this->onPersist)($this->enabledIds);
            $this->dirty = false;

            return;
        }

        if (Keys::isCtrlC($data)) {
            if ($this->search->value() !== '') {
                $this->search->setValue('');
                $this->refresh();
            } else {
                ($this->onCancel)();
            }

            return;
        }

        if ($kb->matches($data, 'tui.select.cancel')) {
            ($this->onCancel)();

            return;
        }

        $this->search->handleInput($data);
        $this->refresh();
    }

    private function changed(): void
    {
        $this->dirty = true;
        $this->refresh();
        ($this->onChange)($this->enabledIds);
    }

    /** @return list<string>|null what the search matches while there is one, every model otherwise */
    private function searchTargets(): ?array
    {
        return $this->search->value() !== '' ? array_map(static fn (array $item): string => $item['id'], $this->filtered) : null;
    }

    private function isEnabled(string $id): bool
    {
        return $this->enabledIds === null || in_array($id, $this->enabledIds, true);
    }

    /**
     * Upstream's `normalizeEnabled()`: an explicit list covering every model is null again.
     *
     * @param list<string> $result
     * @return list<string>|null
     */
    private function normalize(array $result): ?array
    {
        return count($result) === count($this->allIds) && array_diff($result, $this->allIds) === [] ? null : $result;
    }

    /** @return list<string>|null upstream's `toggle()` */
    private function toggle(string $id): ?array
    {
        if ($this->enabledIds === null) {
            return array_values(array_filter($this->allIds, static fn (string $other): bool => $other !== $id));
        }

        $index = array_search($id, $this->enabledIds, true);

        if ($index !== false) {
            $without = $this->enabledIds;
            array_splice($without, $index, 1);

            return $without;
        }

        return $this->normalize([...$this->enabledIds, $id]);
    }

    /**
     * @param list<string>|null $targets
     * @return list<string>|null upstream's `enableAll()`
     */
    private function enableAll(?array $targets): ?array
    {
        if ($this->enabledIds === null) {
            return null;
        }

        $result = $this->enabledIds;

        foreach ($targets ?? $this->allIds as $id) {
            if (!in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $this->normalize($result);
    }

    /**
     * @param list<string>|null $targets
     * @return list<string> upstream's `clearAll()`
     */
    private function clearAll(?array $targets): array
    {
        if ($this->enabledIds === null) {
            return $targets === null ? [] : array_values(array_diff($this->allIds, $targets));
        }

        return array_values(array_diff($this->enabledIds, $targets ?? $this->enabledIds));
    }

    /** Upstream's `refresh()`: enabled first in their order, then the rest, then the search. */
    private function refresh(): void
    {
        $ids = $this->enabledIds === null
            ? $this->allIds
            : [...$this->enabledIds, ...array_values(array_diff($this->allIds, $this->enabledIds))];

        $items = array_map(fn (string $id): array => [
            'id' => $id,
            'model' => $this->modelsById[$id] ?? null,
            'enabled' => $this->isEnabled($id),
        ], $ids);

        $query = $this->search->value();
        $this->filtered = $query === ''
            ? $items
            : Fuzzy::filter($items, $query, static fn (array $item): string => $item['model'] !== null ? self::searchText($item['model']) : $item['id']);
        $this->selected = min($this->selected, max(0, count($this->filtered) - 1));
    }
}
