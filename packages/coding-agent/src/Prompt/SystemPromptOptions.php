<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * What a `before_agent_start` handler may change about the prompt of the run it starts —
 * upstream's `NormalizedBuildSystemPromptOptions`, of which pig carries the two fields a handler
 * writes: `sections` and `forceSystemPrompt`. The rest of upstream's options (the tools, the
 * custom prompt, the context files, the skills) are the loadout's here (`ToolLoadout`).
 *
 * Mutable and handed round by handle, as upstream's object is: "Later handlers observe mutations
 * made by earlier handlers."
 */
final class SystemPromptOptions
{
    /**
     * @param array<string, string> $sections additional prompt sections keyed by tag name, rendered
     *        after `cwd` as `<name>\n…\n</name>` (`SystemPrompt::withSections()`)
     * @param string|null $forceSystemPrompt the exact prompt for this run, sent in place of the
     *        recorded one and never recorded (`AgentSession`'s forced prompt projection)
     */
    public function __construct(
        public array $sections = [],
        public ?string $forceSystemPrompt = null,
    ) {
    }
}
