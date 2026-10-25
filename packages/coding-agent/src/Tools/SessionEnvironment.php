<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\CodingAgent\Session\AgentSession;

/**
 * The session as the `bash` and `powershell` tools describe it to the command — upstream's
 * `resolveSpawnContext()` in `core/tools/bash.ts`, which reads it off the tool's `ExtensionContext`
 * on every call: `PIG_SESSION_ID`, `PIG_SESSION_FILE`, `PIG_PROVIDER`, `PIG_MODEL` and
 * `PIG_REASONING_LEVEL` (upstream's `PI_*`).
 *
 * pig's `AgentTool::execute()` has no context argument, so a tool is given a closure that answers
 * one of these when the command starts — which is upstream's timing too: a model switched or a
 * level changed between two commands reaches the second.
 */
final readonly class SessionEnvironment
{
    /** The variables this sets, which are also the ones taken out of an inherited environment. */
    public const array VARIABLES = ['PIG_SESSION_ID', 'PIG_SESSION_FILE', 'PIG_PROVIDER', 'PIG_MODEL', 'PIG_REASONING_LEVEL'];

    public function __construct(
        public ?string $sessionId = null,
        public ?string $sessionFile = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $reasoningLevel = null,
        // Not a variable the command is told: where a private session's spill files go
        // (`AgentSession::scratchDirectory()`), null for any other session.
        public ?string $tempDirectory = null,
    ) {
    }

    /**
     * Upstream reads `ctx.sessionManager.getSessionId()`, `getSessionFile()`, `ctx.model` and
     * `ctx.thinkingLevel`. A session with no store (`--no-session`) still has its in-memory id
     * (`AgentSession::sessionId()`), and no file, which upstream leaves unset for it too.
     */
    public static function of(AgentSession $session): self
    {
        $store = $session->store();
        $model = $session->model();

        return new self(
            $session->sessionId(),
            $store?->path,
            $model?->provider,
            $model?->id,
            $session->thinkingLevel()->value,
            $session->scratchDirectory(),
        );
    }

    /**
     * Upstream's `resolveSpawnContext()` environment: the inherited one with the five variables taken
     * out — so a pig started from inside another pig's command does not describe the outer session —
     * and, when there is a session to describe, the five set from it. A value that is not known is
     * left unset rather than set empty.
     *
     * @param array<string, string> $inherited
     * @return array<string, string>
     */
    public static function apply(array $inherited, ?self $session): array
    {
        foreach (self::VARIABLES as $name) {
            unset($inherited[$name]);
        }

        if ($session === null) {
            return $inherited;
        }

        $values = [
            'PIG_SESSION_ID' => $session->sessionId,
            'PIG_SESSION_FILE' => $session->sessionFile,
            'PIG_PROVIDER' => $session->provider,
            'PIG_MODEL' => $session->model,
            'PIG_REASONING_LEVEL' => $session->reasoningLevel,
        ];

        foreach ($values as $name => $value) {
            if ($value !== null && $value !== '') {
                $inherited[$name] = $value;
            }
        }

        return $inherited;
    }
}
