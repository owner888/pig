<?php

declare(strict_types=1);

namespace Pig\Tui;

/** A key the user bound to more than one action — upstream's `KeybindingConflict`. */
final readonly class KeybindingConflict
{
    /** @param list<string> $keybindings */
    public function __construct(
        public string $key,
        public array $keybindings,
    ) {
    }
}
