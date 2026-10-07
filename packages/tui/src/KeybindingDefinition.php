<?php

declare(strict_types=1);

namespace Pig\Tui;

/** One action's default keys — upstream's `KeybindingDefinition`. */
final readonly class KeybindingDefinition
{
    /** @param string|list<string> $defaultKeys */
    public function __construct(
        public string|array $defaultKeys,
        public ?string $description = null,
    ) {
    }
}
