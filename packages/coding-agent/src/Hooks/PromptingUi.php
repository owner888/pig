<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\Events\UiPromptEndEvent;
use Pig\CodingAgent\Hooks\Events\UiPromptStartEvent;
use Pig\CodingAgent\Theme\Theme;

/**
 * The mode's UI, with `ui_prompt_start` and `ui_prompt_end` said around every blocking
 * question — upstream's `wrapUIPromptContext()` in the extension runner.
 *
 * Only the outermost prompt is reported: a dialog opened from inside another dialog's
 * factory is one wait as far as a terminal or a dashboard is concerned. The events go out
 * on the next turn of the loop, as upstream's go out on a microtask, so a handler that
 * opens a dialog of its own is not running inside the one being reported.
 */
final class PromptingUi implements HookUi
{
    private int $depth = 0;

    /** @var array{0: string, 1: ?string}|null the kind and title of the prompt being waited on */
    private ?array $active = null;

    /** @param Closure(HookEvent): void $emit */
    public function __construct(
        private readonly HookUi $ui,
        private readonly Closure $emit,
    ) {
    }

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        return $this->prompting('select', $title, fn (): ?string => $this->ui->select($title, $options));
    }

    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        return $this->prompting('confirm', $title, fn (): bool => $this->ui->confirm($title, $message));
    }

    #[\Override]
    public function input(string $title, string $placeholder = '', ?AbortSignal $signal = null): ?string
    {
        return $this->prompting('input', $title, fn (): ?string => $this->ui->input($title, $placeholder, $signal));
    }

    #[\Override]
    public function editor(string $title, string $prefill = ''): ?string
    {
        return $this->prompting('editor', $title, fn (): ?string => $this->ui->editor($title, $prefill));
    }

    #[\Override]
    public function custom(Closure $factory): mixed
    {
        return $this->prompting('custom', null, fn (): mixed => $this->ui->custom($factory));
    }

    /**
     * @template T
     * @param Closure(): T $run
     * @return T
     */
    private function prompting(string $kind, ?string $title, Closure $run): mixed
    {
        if ($this->depth++ === 0) {
            $this->active = [$kind, $title];
            $this->say(new UiPromptStartEvent($kind, $title === '' ? null : $title));
        }

        try {
            return $run();
        } finally {
            if (--$this->depth <= 0) {
                $this->depth = 0;
                [$kind, $title] = $this->active ?? [$kind, $title];
                $this->active = null;
                $this->say(new UiPromptEndEvent($kind, $title === '' ? null : $title));
            }
        }
    }

    private function say(HookEvent $event): void
    {
        Loop::get()->defer(fn () => ($this->emit)($event));
    }

    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
        $this->ui->notify($message, $level);
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
        $this->ui->setStatus($key, $text);
    }

    #[\Override]
    public function setEditorText(string $text): void
    {
        $this->ui->setEditorText($text);
    }

    #[\Override]
    public function getEditorText(): string
    {
        return $this->ui->getEditorText();
    }

    #[\Override]
    public function theme(): Theme
    {
        return $this->ui->theme();
    }

    #[\Override]
    public function pasteToEditor(string $text): void
    {
        $this->ui->pasteToEditor($text);
    }

    #[\Override]
    public function setTitle(string $title): void
    {
        $this->ui->setTitle($title);
    }

    #[\Override]
    public function setWorkingMessage(?string $message = null): void
    {
        $this->ui->setWorkingMessage($message);
    }

    #[\Override]
    public function setWorkingVisible(bool $visible): void
    {
        $this->ui->setWorkingVisible($visible);
    }

    #[\Override]
    public function setWorkingIndicator(?array $options = null): void
    {
        $this->ui->setWorkingIndicator($options);
    }

    #[\Override]
    public function setHiddenThinkingLabel(?string $label = null): void
    {
        $this->ui->setHiddenThinkingLabel($label);
    }

    #[\Override]
    public function getToolsExpanded(): bool
    {
        return $this->ui->getToolsExpanded();
    }

    #[\Override]
    public function setToolsExpanded(bool $expanded): void
    {
        $this->ui->setToolsExpanded($expanded);
    }

    #[\Override]
    public function setWidget(string $key, array|Closure|null $content, array $options = []): void
    {
        $this->ui->setWidget($key, $content, $options);
    }

    #[\Override]
    public function setHeader(?Closure $factory): void
    {
        $this->ui->setHeader($factory);
    }

    #[\Override]
    public function setFooter(?Closure $factory): void
    {
        $this->ui->setFooter($factory);
    }

    #[\Override]
    public function getAllThemes(): array
    {
        return $this->ui->getAllThemes();
    }

    #[\Override]
    public function getTheme(string $name): ?Theme
    {
        return $this->ui->getTheme($name);
    }

    #[\Override]
    public function setTheme(string|Theme $theme): array
    {
        return $this->ui->setTheme($theme);
    }

    #[\Override]
    public function onTerminalInput(callable $handler): Closure
    {
        return $this->ui->onTerminalInput($handler);
    }

    #[\Override]
    public function addAutocompleteProvider(Closure $factory): void
    {
        $this->ui->addAutocompleteProvider($factory);
    }

    #[\Override]
    public function setEditorComponent(?Closure $factory): void
    {
        $this->ui->setEditorComponent($factory);
    }

    #[\Override]
    public function getEditorComponent(): ?Closure
    {
        return $this->ui->getEditorComponent();
    }
}
