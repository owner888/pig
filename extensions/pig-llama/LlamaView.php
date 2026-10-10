<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Interactive\ThemedText;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Component;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Container;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\KeybindingsManager;
use Pig\Tui\Style;
use Pig\Tui\TUI;
use Pig\Tui\Width;
use Throwable;

/**
 * Upstream's `LlamaView` (`extensions/llama/ui.ts`), with the module's `showLlamaUi()` and
 * `runWithProgress()`: one component that `HookUi::custom()` shows, whose contents are swapped
 * as `/llama` moves between the model list, a choice, the Hugging Face search and a progress bar.
 *
 * Every coloured line is a `ThemedText` over `Themes::theme()` (upstream's `theme` proxy), as the
 * MCP manager view draws it. Upstream's `SelectList` takes primary column widths (36–56) that pig's
 * does not have; its two columns are pig's own.
 *
 * @phpstan-import-type LlamaModelInfo from LlamaClient
 * @phpstan-import-type LlamaProgress from LlamaClient
 * @phpstan-import-type ProgressState from LlamaUi
 */
final class LlamaView implements LlamaUi, Component, InputHandler, Focusable
{
    private const string DOWNLOAD_VALUE = "\0download";

    public bool $focused = false;

    /** @var \ArrayObject<string, list<array{id: string, downloads: int|float}>> */
    private readonly \ArrayObject $searchCache;

    private Container $content;

    private ?InputHandler $inputHandler = null;

    private ?Focusable $inputTarget = null;

    private ?Deferred $progressPromise = null;

    private bool $showingProgress = false;

    public function __construct(
        private readonly TUI $tui,
        private readonly KeybindingsManager $keybindings,
    ) {
        $this->searchCache = new \ArrayObject();
        $this->content = self::frame('llama.cpp models', [self::text('muted', 'Loading…', 1, 1)]);
    }

    private static function text(string $color, string $text, int $paddingX = 1, int $paddingY = 0): ThemedText
    {
        return new ThemedText(static fn (): string => Themes::theme()->fg($color, $text), $paddingX, $paddingY);
    }

    /** Upstream's `keyHint()`: the keys dim, the words muted. */
    private function keyHint(string $keybinding, string $description): string
    {
        return Themes::theme()->fg('dim', implode('/', $this->keybindings->getKeys($keybinding))) . Themes::theme()->fg('muted', " {$description}");
    }

    /** @param LlamaModelInfo $model */
    private static function contextLabel(array $model): ?string
    {
        $context = $model['meta']['n_ctx'] ?? $model['meta']['n_ctx_train'] ?? null;

        if (is_int($context) && $context !== 0) {
            return $context >= 1000 ? (string) round($context / 1000) . 'k' : (string) $context;
        }

        $args = $model['status']['args'] ?? [];

        for ($index = 0; $index < count($args) - 1; $index++) {
            if ($args[$index] !== '--ctx-size' && $args[$index] !== '-c' && $args[$index] !== '-ctx') {
                continue;
            }

            $value = $args[$index + 1];

            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value >= 1000 ? (string) round((float) $value / 1000) . 'k' : (string) ($value + 0);
            }
        }

        return null;
    }

    /** @param LlamaModelInfo $model */
    private static function modelDescription(array $model): string
    {
        $details = [];
        $loaded = $model['status']['value'] === 'loaded' || $model['status']['value'] === 'sleeping';

        if ($loaded) {
            $details[] = 'loaded';
        } elseif ($model['status']['value'] !== 'unloaded') {
            $details[] = $model['status']['value'];
        }

        $context = $loaded ? self::contextLabel($model) : null;

        if ($context !== null) {
            $details[] = "{$context} context";
        }

        return implode(' · ', $details);
    }

    private static function selectTheme(): SelectListTheme
    {
        return new SelectListTheme(
            selectedText: static fn (string $text): string => Themes::theme()->fg('accent', $text),
            description: static fn (string $text): string => Themes::theme()->fg('muted', $text),
            scrollInfo: static fn (string $text): string => Themes::theme()->fg('dim', $text),
            noMatch: static fn (string $text): string => Themes::theme()->fg('warning', $text),
        );
    }

    /** @param list<Component> $body */
    private static function frame(string $title, array $body, ?string $footer = null): Container
    {
        $container = new Container();
        $border = static fn (string $text): string => Themes::theme()->fg('accent', $text);
        $container->addChild(new Rule($border));
        $container->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('accent', Style::bold($title)), 1, 0));

        foreach ($body as $child) {
            $container->addChild($child);
        }

        if ($footer !== null && $footer !== '') {
            $container->addChild(new Spacer(1));
            $container->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('dim', $footer), 1, 0));
        }

        $container->addChild(new Rule($border));

        return $container;
    }

    private function setContent(Container $content, ?InputHandler $inputHandler = null, ?Focusable $inputTarget = null): void
    {
        if ($this->inputTarget !== null) {
            $this->inputTarget->focused = false;
        }

        $this->progressPromise = null;
        $this->showingProgress = false;
        $this->content = $content;
        $this->inputHandler = $inputHandler;
        $this->inputTarget = $inputTarget;
        $this->tui->requestRender();
    }

    #[\Override]
    public function showModels(string $serverUrl, array $models): array
    {
        $sorted = $models;
        usort($sorted, static function (array $left, array $right): int {
            $loaded = (int) ($right['status']['value'] === 'loaded') - (int) ($left['status']['value'] === 'loaded');

            return $loaded !== 0 ? $loaded : strcmp($left['id'], $right['id']);
        });
        $byId = [];

        foreach ($sorted as $model) {
            $byId[$model['id']] = $model;
        }

        $items = [
            ...array_map(static fn (array $model): SelectItem => new SelectItem($model['id'], $model['id'], self::modelDescription($model)), $sorted),
            new SelectItem(self::DOWNLOAD_VALUE, 'Download model…', 'Hugging Face owner/repository[:quant]'),
        ];
        $answer = new Deferred();
        $list = new SelectList($items, min(count($items), 12), self::selectTheme());
        $list->setSelectHandler(static function (SelectItem $item) use ($answer, $byId): void {
            if ($answer->isComplete()) {
                return;
            }

            if ($item->value === self::DOWNLOAD_VALUE) {
                $answer->complete(['type' => 'download']);
            } elseif (isset($byId[$item->value])) {
                $answer->complete(['type' => 'model', 'model' => $byId[$item->value]]);
            }
        });
        $list->setCancelHandler(static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(['type' => 'close']);
            }
        });
        $this->setContent(
            self::frame(
                'llama.cpp models',
                [self::text('dim', $serverUrl), new Spacer(1), $list],
                $this->keyHint('tui.select.confirm', 'load/unload/download') . ' • ' . $this->keyHint('tui.select.cancel', 'close'),
            ),
            $list,
        );

        return $answer->future->await();
    }

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        $answer = new Deferred();
        $list = new SelectList(
            array_map(static fn (string $option): SelectItem => new SelectItem($option, $option), $options),
            min(count($options), 12),
            self::selectTheme(),
        );
        $list->setSelectHandler(static function (SelectItem $item) use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete($item->value);
            }
        });
        $list->setCancelHandler(static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(null);
            }
        });
        $this->setContent(
            self::frame(
                $title,
                [new Spacer(1), $list],
                $this->keyHint('tui.select.confirm', 'select') . ' • ' . $this->keyHint('tui.select.cancel', 'cancel'),
            ),
            $list,
        );

        return $answer->future->await();
    }

    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        return $this->select("{$title}\n{$message}", ['Yes', 'No']) === 'Yes';
    }

    #[\Override]
    public function connectionError(string $serverUrl, string $message): string
    {
        $choice = $this->select("llama.cpp unavailable\n{$serverUrl}\n\n{$message}", ['Retry', 'Close']);

        return $choice === 'Retry' ? 'retry' : 'close';
    }

    #[\Override]
    public function searchModels(Closure $search): ?string
    {
        $answer = new Deferred();
        $component = new HuggingFaceSearch(
            $this->tui,
            $this->keybindings,
            $search,
            $this->searchCache,
            static function (?string $model) use ($answer): void {
                if (!$answer->isComplete()) {
                    $answer->complete($model);
                }
            },
        );
        $this->setContent(
            self::frame(
                'Download model',
                [new Spacer(1), $component],
                $this->keyHint('tui.select.confirm', 'select') . ' • ' . $this->keyHint('tui.select.cancel', 'back'),
            ),
            $component,
            $component,
        );

        return $answer->future->await();
    }

    #[\Override]
    public function showStatus(string $title, string $message): void
    {
        $this->setContent(self::frame($title, [new Spacer(1), self::text('muted', $message)]));
    }

    #[\Override]
    public function progress(array $state): Future
    {
        $this->progressPromise ??= new Deferred();
        $promise = $this->progressPromise;
        $this->showingProgress = true;
        $this->updateProgress($state);

        return $promise->future;
    }

    #[\Override]
    public function updateProgress(array $state): void
    {
        if (!$this->showingProgress) {
            return;
        }

        $body = [
            self::text('text', $state['model']),
            new Spacer(1),
            self::text('muted', $state['message']),
        ];

        if (isset($state['ratio'])) {
            $available = 40;
            $filled = (int) round(max(0.0, min(1.0, $state['ratio'])) * $available);
            $body[] = self::text('accent', str_repeat('█', $filled) . str_repeat('─', $available - $filled) . ' ' . round($state['ratio'] * 100) . '%');
        }

        if (($state['detail'] ?? '') !== '') {
            $body[] = self::text('dim', $state['detail']);
        }

        $this->content = self::frame($state['title'], $body, $this->keyHint('tui.select.cancel', 'stop'));
        $this->inputHandler = null;
        $this->tui->requestRender();
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->progressPromise !== null && $this->keybindings->matches($data, 'tui.select.cancel')) {
            $resolve = $this->progressPromise;
            $this->progressPromise = null;
            $resolve->complete(null);

            return;
        }

        $this->inputHandler?->handleInput($data);
        $this->tui->requestRender();
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->inputTarget !== null) {
            $this->inputTarget->focused = $this->focused;
        }

        return array_map(
            static fn (string $line): string => Width::visible($line) > $width ? Width::truncate($line, $width, '') : $line,
            $this->content->render($width),
        );
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->content->invalidate();
    }

    /**
     * Upstream's `showLlamaUi()`: the view in a custom dialog while $run drives it; a failure is
     * said as an error and closes it.
     *
     * @param Closure(LlamaUi): void $run
     */
    public static function showLlamaUi(HookContext $ctx, Closure $run): void
    {
        $ctx->ui->custom(static function (TUI $tui, mixed $theme, Closure $done) use ($ctx, $run): LlamaView {
            $view = new self($tui, Keybindings::getKeybindings());
            Async::spawn(static function () use ($view, $run, $done, $ctx): void {
                try {
                    $run($view);
                } catch (Throwable $error) {
                    $ctx->ui->notify($error->getMessage(), 'error');
                }

                $done(null);
            });

            return $view;
        });
    }

    /**
     * Upstream's `runWithProgress()`: the work beside a progress screen; Escape asks whether to stop,
     * and a yes runs $cancel, aborts the work's signal and waits for it to settle.
     *
     * @template T
     * @param Closure(\Pig\Async\AbortSignal, Closure(LlamaProgress): void): T $run
     * @param Closure(): void $cancel
     * @return array{cancelled: true}|array{cancelled: false, value: T}
     */
    public static function runWithProgress(
        LlamaUi $ui,
        string $title,
        string $model,
        string $initialMessage,
        string $cancelTitle,
        string $cancelMessage,
        Closure $run,
        Closure $cancel,
    ): array {
        $controller = new AbortController();
        /** @var ProgressState $state */
        $state = ['title' => $title, 'model' => $model, 'message' => $initialMessage];
        $settled = Async::spawn(static function () use ($run, $controller, $ui, &$state): array {
            try {
                return ['ok' => true, 'value' => $run($controller->signal, static function (array $progress) use ($ui, &$state): void {
                    $state = [...$state, ...$progress];
                    $ui->updateProgress($state);
                })];
            } catch (Throwable $error) {
                return ['ok' => false, 'error' => $error];
            }
        });

        while (!$settled->isComplete()) {
            if (self::race($settled, $ui->progress($state)) === 'settled') {
                break;
            }

            $stop = $ui->confirm($cancelTitle, $cancelMessage);

            if (!$stop || $settled->isComplete()) {
                continue;
            }

            try {
                $cancel();
            } finally {
                $controller->abort('Cancelled');
            }

            $settled->await();

            return ['cancelled' => true];
        }

        $result = $settled->await();

        if (!$result['ok']) {
            throw $result['error'];
        }

        return ['cancelled' => false, 'value' => $result['value']];
    }

    /** `Promise.race([settled.then(() => "settled"), stop.then(() => "stop")])`. */
    private static function race(Future $settled, Future $stop): string
    {
        $winner = new Deferred();
        $settled->onComplete(static function () use ($winner): void {
            if (!$winner->isComplete()) {
                $winner->complete('settled');
            }
        });
        $stop->onComplete(static function () use ($winner): void {
            if (!$winner->isComplete()) {
                $winner->complete('stop');
            }
        });

        return $winner->future->await();
    }
}
