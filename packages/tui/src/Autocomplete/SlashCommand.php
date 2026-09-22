<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

use Closure;

/**
 * A command the user reaches by typing `/name`.
 *
 * `argumentCompletions` is what makes `/model <tab>` list models rather than files: the
 * command itself knows what its argument can be, and nothing else does.
 */
final readonly class SlashCommand
{
    /**
     * @param Closure(string): list<AutocompleteItem>|null $argumentCompletions
     *        given what has been typed after the command, the completions for it
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?Closure $argumentCompletions = null,
    ) {
    }
}
