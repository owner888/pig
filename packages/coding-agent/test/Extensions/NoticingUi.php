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
    public function input(string $title, string $placeholder = ''): ?string
    {
        return null;
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
}
