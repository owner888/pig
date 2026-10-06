<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use Closure;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Theme\Palette;

/** A `HookUi` that answers nothing and writes down what it was told. */
final class NoticingUi implements HookUi
{
    /** @var list<string> `[level] message` */
    public array $notices = [];

    /**
     * What `input()` answers. Null means "nobody typed": a real dialog stays open until a key or
     * its owner closes it, so this one parks the caller until `$inputReleased` is completed —
     * which is what the MCP sign-in's redirect-URL prompt relies on, racing the browser callback.
     */
    public ?string $inputAnswer = null;

    public ?\Pig\Async\Deferred $inputReleased = null;

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        return null;
    }

    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        return false;
    }

    #[\Override]
    public function input(string $title, string $placeholder = '', ?\Pig\Async\AbortSignal $signal = null): ?string
    {
        if ($this->inputAnswer !== null) {
            return $this->inputAnswer;
        }

        // Nobody types: the prompt stays open until the signal closes it, as a real one would.
        $this->inputReleased ??= new \Pig\Async\Deferred();
        $released = $this->inputReleased;
        $signal?->onAbort(static function () use ($released): void {
            if (!$released->isComplete()) {
                $released->complete(null);
            }
        });

        return $released->future->await();
    }

    #[\Override]
    public function editor(string $title, string $prefill = ''): ?string
    {
        return null;
    }

    #[\Override]
    public function custom(Closure $factory): mixed
    {
        return null;
    }

    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
        $this->notices[] = "[{$level}] {$message}";
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
    }

    #[\Override]
    public function setEditorText(string $text): void
    {
    }

    #[\Override]
    public function getEditorText(): string
    {
        return '';
    }

    #[\Override]
    public function palette(): Palette
    {
        return Palette::dark(true);
    }

    #[\Override]
    public function pasteToEditor(string $text): void
    {
    }

    #[\Override]
    public function setTitle(string $title): void
    {
    }

    #[\Override]
    public function setWorkingMessage(?string $message = null): void
    {
    }

    #[\Override]
    public function setWorkingVisible(bool $visible): void
    {
    }

    #[\Override]
    public function setHiddenThinkingLabel(?string $label = null): void
    {
    }

    #[\Override]
    public function getToolsExpanded(): bool
    {
        return false;
    }

    #[\Override]
    public function setToolsExpanded(bool $expanded): void
    {
    }

    #[\Override]
    public function setWidget(string $key, array|Closure|null $content, array $options = []): void
    {
    }

    #[\Override]
    public function setHeader(?Closure $factory): void
    {
    }

    #[\Override]
    public function setFooter(?Closure $factory): void
    {
    }

    #[\Override]
    public function getAllThemes(): array
    {
        return ['dark', 'light', 'labra'];
    }

    #[\Override]
    public function getTheme(string $name): ?Palette
    {
        return Palette::dark(true);
    }

    #[\Override]
    public function setTheme(string $name): bool
    {
        return true;
    }

    #[\Override]
    public function onTerminalInput(callable $handler): Closure
    {
        return static fn () => null;
    }
}
