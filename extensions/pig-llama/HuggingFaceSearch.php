<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\ThemedText;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Container;
use Pig\Tui\Focusable;
use Pig\Tui\Fuzzy;
use Pig\Tui\InputHandler;
use Pig\Tui\KeybindingsManager;
use Pig\Tui\TUI;
use Throwable;

/**
 * Upstream's `HuggingFaceSearch` (`extensions/llama/ui.ts`): a box to type a model name or
 * `owner/repository[:quant]` into, Hugging Face searched half a second after the typing stops, and
 * the results narrowed by fuzzy match while the next search runs.
 *
 * Upstream's `focused` setter hands the focus on to the input; PHP 8.3 has no property hooks, so
 * `render()` does the handing on.
 *
 * @phpstan-import-type HuggingFaceModel from HuggingFaceClient
 */
final class HuggingFaceSearch extends Container implements Focusable, InputHandler
{
    public bool $focused = false;

    private readonly Input $input;

    private readonly Container $resultsContainer;

    /** @var list<HuggingFaceModel> */
    private array $results = [];

    /** @var list<HuggingFaceModel> */
    private array $filteredResults = [];

    private int $selectedIndex = 0;

    private string $query = '';

    private string $status = 'Type at least 2 characters';

    private ?string $debounce = null;

    private ?AbortController $request = null;

    private bool $closed = false;

    /**
     * @param Closure(string, \Pig\Async\AbortSignal): list<HuggingFaceModel> $search
     * @param \ArrayObject<string, list<HuggingFaceModel>> $cache shared across searches of one `/llama`
     * @param Closure(?string): void $onSelectModel
     */
    public function __construct(
        private readonly TUI $tui,
        private readonly KeybindingsManager $keybindings,
        private readonly Closure $search,
        private readonly \ArrayObject $cache,
        private readonly Closure $onSelectModel,
    ) {
        $this->input = new Input();
        $this->resultsContainer = new Container();
        $this->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('dim', 'Model name or owner/repository[:quant]'), 1, 0));
        $this->addChild($this->input);
        $this->addChild(new Spacer(1));
        $this->addChild($this->resultsContainer);
        $this->updateResults();
    }

    private static function compactCount(int|float $value): string
    {
        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, $value >= 10_000_000 ? 0 : 1, '.', '') . 'M';
        }

        if ($value >= 1_000) {
            return number_format($value / 1_000, $value >= 100_000 ? 0 : 1, '.', '') . 'k';
        }

        return (string) $value;
    }

    private function updateResults(): void
    {
        $this->resultsContainer->clear();
        $maxVisible = 10;
        $count = count($this->filteredResults);
        $start = max(0, min($this->selectedIndex - intdiv($maxVisible, 2), $count - $maxVisible));
        $end = min($start + $maxVisible, $count);

        for ($index = $start; $index < $end; $index++) {
            $model = $this->filteredResults[$index];
            $selected = $index === $this->selectedIndex;
            $prefix = $selected ? '→ ' : '  ';
            $details = self::compactCount($model['downloads']) . ' downloads';
            $this->resultsContainer->addChild(new ThemedText(
                static fn (): string => $selected
                    ? Themes::theme()->fg('accent', "{$prefix}{$model['id']}  {$details}")
                    : "{$prefix}{$model['id']}" . Themes::theme()->fg('muted', "  {$details}"),
                0,
                0,
            ));
        }

        if ($start > 0 || $end < $count) {
            $position = '  (' . ($this->selectedIndex + 1) . "/{$count})";
            $this->resultsContainer->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('dim', $position), 0, 0));
        }

        $status = $this->status;

        if ($count === 0 || $status === 'Searching Hugging Face…') {
            $this->resultsContainer->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('dim', "  {$status}"), 0, 0));
        }

        $this->tui->requestRender();
    }

    private function filterResults(): void
    {
        if ($this->query !== '') {
            $matches = array_map(static fn (array $model): string => $model['id'], Fuzzy::filter($this->results, $this->query, static fn (array $model): string => $model['id']));
            $this->filteredResults = array_values(array_filter($this->results, static fn (array $model): bool => in_array($model['id'], $matches, true)));
        } else {
            $this->filteredResults = $this->results;
        }

        $this->selectedIndex = min($this->selectedIndex, max(0, count($this->filteredResults) - 1));
        $this->updateResults();
    }

    private function scheduleSearch(): void
    {
        if ($this->debounce !== null) {
            Loop::get()->cancel($this->debounce);
            $this->debounce = null;
        }

        $this->request?->abort();
        $this->request = null;

        if (mb_strlen($this->query) < 2) {
            $this->status = 'Type at least 2 characters';
            $this->filterResults();

            return;
        }

        $cached = $this->cache[strtolower($this->query)] ?? null;

        if ($cached !== null) {
            $this->results = $cached;
            $this->status = $cached === [] ? 'No GGUF models found' : '';
            $this->filterResults();

            return;
        }

        $this->status = 'Searching Hugging Face…';
        $this->filterResults();
        $query = $this->query;
        $this->debounce = Loop::get()->delay(0.5, function () use ($query): void {
            $this->debounce = null;
            Async::spawn(fn () => $this->runSearch($query));
        });
    }

    private function runSearch(string $query): void
    {
        $request = new AbortController();
        $this->request = $request;

        try {
            $results = ($this->search)($query, $request->signal);
            $this->cache[strtolower($query)] = $results;

            if ($this->closed || $request->signal->aborted() || $this->query !== $query) {
                return;
            }

            $this->results = $results;
            $this->selectedIndex = 0;
            $this->status = $results === [] ? 'No GGUF models found' : '';
            $this->filterResults();
        } catch (Throwable $error) {
            if ($this->closed || $request->signal->aborted() || $this->query !== $query) {
                return;
            }

            $this->results = [];
            $this->status = $error->getMessage();
            $this->filterResults();
        } finally {
            if ($this->request === $request) {
                $this->request = null;
            }
        }
    }

    private function close(?string $model): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->debounce !== null) {
            Loop::get()->cancel($this->debounce);
            $this->debounce = null;
        }

        $this->request?->abort();
        ($this->onSelectModel)($model);
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->keybindings->matches($data, 'tui.select.up')) {
            if ($this->filteredResults !== []) {
                $this->selectedIndex = $this->selectedIndex === 0 ? count($this->filteredResults) - 1 : $this->selectedIndex - 1;
                $this->updateResults();
            }

            return;
        }

        if ($this->keybindings->matches($data, 'tui.select.down')) {
            if ($this->filteredResults !== []) {
                $this->selectedIndex = $this->selectedIndex === count($this->filteredResults) - 1 ? 0 : $this->selectedIndex + 1;
                $this->updateResults();
            }

            return;
        }

        if ($this->keybindings->matches($data, 'tui.select.confirm')) {
            $exact = preg_match('#^[^/\s]+/[^:\s]+(?::[^\s:]+)?$#u', $this->query) === 1 ? $this->query : null;
            $selected = $exact ?? ($this->filteredResults[$this->selectedIndex]['id'] ?? null);

            if ($selected !== null && $selected !== '') {
                $this->close($selected);
            }

            return;
        }

        if ($this->keybindings->matches($data, 'tui.select.cancel')) {
            $this->close(null);

            return;
        }

        $this->input->handleInput($data);
        $query = trim($this->input->value());

        if ($query === $this->query) {
            return;
        }

        $this->query = $query;
        $this->scheduleSearch();
    }

    #[\Override]
    public function render(int $width): array
    {
        $this->input->focused = $this->focused;

        return parent::render($width);
    }
}
