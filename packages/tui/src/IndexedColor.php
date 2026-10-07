<?php

declare(strict_types=1);

namespace Pig\Tui;

/** Upstream's `IndexedColor` in `colors.ts`: an ANSI 256-color palette index. Made by `Colors::indexedColor()`. */
final readonly class IndexedColor implements Color
{
    /** @var 'indexed' */
    public string $kind;

    public function __construct(
        public int $index,
    ) {
        $this->kind = 'indexed';
    }
}
