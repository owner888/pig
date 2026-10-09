<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;

/**
 * What `resources_discover` added to a session, for a mode that keeps lists of its own — the
 * `/skills` listing, the autocomplete's commands.
 */
final readonly class ExtensionResources
{
    /**
     * @param list<Skill>       $skills
     * @param list<FileCommand> $commands
     */
    public function __construct(
        public array $skills,
        public array $commands,
    ) {
    }
}
