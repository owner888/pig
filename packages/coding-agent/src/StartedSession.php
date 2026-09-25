<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;

/**
 * Everything `CodingAgent::session()` assembled, and everything it has to say about it.
 *
 * Upstream's `CreateAgentSessionResult`, which carries three fields — the session, the custom-tool
 * load result, and one optional `modelFallbackMessage`. This carries more because pig's modes are
 * handed the pieces rather than digging them back out of the session: `InteractiveMode` draws the
 * context files and the skills in its banner and offers the file commands for completion, and both
 * other modes need the hooks and the custom tools.
 *
 * `warnings` is the field that made the extraction possible at all. The startup it replaces wrote
 * five kinds of warning straight to standard error from five different places, which is the one
 * thing a test cannot look at. They are collected here, already worded, in the order they were
 * found; whoever asked prints them. Severity is not a field: everything here is survivable by
 * definition, because anything fatal threw instead.
 *
 * @param list<Skill>             $skills
 * @param list<ContextFile>       $contextFiles
 * @param list<FileCommand>      $fileCommands
 * @param list<string>            $warnings
 */
final readonly class StartedSession
{
    public function __construct(
        public AgentSession $session,
        public Model $model,
        public ThinkingLevel $thinking,
        public array $contextFiles,
        public array $skills,
        public array $fileCommands,
        public HookRunner $hooks,
        public CustomToolSet $customTools,
        public ?SessionManager $store,
        public array $warnings,
        /** Whether the conversation was picked up rather than started, for a caller that shows it. */
        public bool $resumed = false,
    ) {
    }
}
