<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Container;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\InputHandler;

/**
 * `/settings`' *Default thinking level per model* row: a model, then its level — upstream's
 * `model-thinking` row and the two-step `SteppedSubmenu` it opens, with `loop: true`.
 *
 * Choosing a level saves it at once and comes back to the model list, so several models can be
 * set in one visit; Escape on the levels goes back to the models, Escape on the models is done.
 * A model with an override offers `(clear override)` to go back to the global default. The
 * models are the ones a key reaches, the current one first and the default one next, as upstream
 * sorts them; typing narrows the list.
 */
final class ModelThinkingSubmenu extends Container implements InputHandler
{
    private const string CLEAR = '__clear__';

    private SelectList $list;

    /**
     * @param list<Model>                   $models
     * @param array<string, ThinkingLevel>  $overrides keyed `provider/id`, changed here as they are set
     * @param array<string, string>         $descriptions what each level costs, by level
     * @param Closure(Model, ?ThinkingLevel): void $onChange a level saved, or null for the override cleared
     * @param Closure(?string): void        $done the row's new summary
     */
    public function __construct(
        private readonly array $models,
        private array $overrides,
        private readonly ?string $currentKey,
        private readonly ?string $defaultKey,
        private readonly ThinkingLevel $globalDefault,
        private readonly array $descriptions,
        private readonly Closure $onChange,
        private readonly Closure $done,
    ) {
        $this->showModels($this->currentKey ?? $this->defaultKey);
    }

    /** Upstream's `modelThinkingOverridesSummary()`. @param array<string, ThinkingLevel> $overrides */
    public static function summary(array $overrides): string
    {
        return $overrides === [] ? 'none' : count($overrides) . ' configured';
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $this->list->handleInput($data);
    }

    private function showModels(?string $selectKey): void
    {
        $models = $this->models;
        usort($models, function (Model $a, Model $b): int {
            foreach ([$this->currentKey, $this->defaultKey] as $first) {
                if (self::key($a) === $first) {
                    return -1;
                }

                if (self::key($b) === $first) {
                    return 1;
                }
            }

            return strcmp($a->provider, $b->provider);
        });

        $items = [];
        $at = 0;

        foreach ($models as $index => $model) {
            $key = self::key($model);
            $items[] = new SelectItem(
                $key,
                "{$model->id} " . Themes::theme()->fg('muted', "[{$model->provider}]"),
                $this->overrides[$key]->value ?? null,
                searchText: "{$model->provider} {$key} {$model->id}",
            );

            if ($key === $selectKey) {
                $at = $index;
            }
        }

        if ($items === []) {
            $items[] = new SelectItem('__none__', 'No models available', 'Log in to a provider or configure an API key first');
        }

        $this->show(
            'Per-Model Thinking Level',
            'Select a model to configure',
            $items,
            $at,
            function (SelectItem $item) use ($models): void {
                foreach ($models as $model) {
                    if (self::key($model) === $item->value) {
                        $this->showLevels($model);

                        return;
                    }
                }
            },
            fn () => ($this->done)(self::summary($this->overrides)),
        );
    }

    private function showLevels(Model $model): void
    {
        $key = self::key($model);
        $active = $this->overrides[$key] ?? null;
        $items = [];
        $at = 0;

        foreach (ThinkingLevel::supportedBy($model) as $index => $level) {
            $items[] = new SelectItem($level->value, ($level === $active ? '✓ ' : '  ') . $level->value, $this->descriptions[$level->value] ?? null);

            if ($level === $active) {
                $at = $index;
            }
        }

        if ($active !== null) {
            $items[] = new SelectItem(self::CLEAR, '  (clear override)', "Revert to global default ({$this->globalDefault->value})");
        }

        $this->show(
            "Thinking Level for {$model->id} [{$model->provider}]",
            'Select default thinking level for this model',
            $items,
            $at,
            function (SelectItem $item) use ($model, $key): void {
                $level = $item->value === self::CLEAR ? null : ThinkingLevel::from($item->value);

                if ($level === null) {
                    unset($this->overrides[$key]);
                } else {
                    $this->overrides[$key] = $level;
                }

                ($this->onChange)($model, $level);
                $this->showModels($key);
            },
            fn () => $this->showModels($key),
        );
    }

    /**
     * @param list<SelectItem> $items
     * @param Closure(SelectItem): void $onSelect
     * @param Closure(): void $onCancel
     */
    private function show(string $title, string $description, array $items, int $at, Closure $onSelect, Closure $onCancel): void
    {
        $this->list = new SelectList($items, min(10, max(1, count($items))), Themes::getSelectListTheme());
        $this->list->setSelectedIndex($at);
        $this->list->setSelectHandler($onSelect);
        $this->list->setCancelHandler($onCancel);

        $this->clear();
        $this->addChild(new SettingsSubmenu(
            $this->list,
            Themes::theme()->fg('accent', $title) . "\n" . Themes::theme()->fg('muted', $description),
            Themes::theme()->fg('muted', '  Enter to select · type to search · Esc to go back'),
        ));
    }

    private static function key(Model $model): string
    {
        return "{$model->provider}/{$model->id}";
    }
}
