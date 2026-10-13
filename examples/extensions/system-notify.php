<?php

declare(strict_types=1);

/**
 * Tell the person when a piece of work is done, wherever they are looking.
 *
 * Copy it to `~/.pig/agent/extensions/`. When a turn settles — after its retries and the messages
 * queued behind it, not at every `agent_end` — it asks for `$ctx->ui->systemNotify()`, and pig
 * picks how: a desktop notification from the terminal (the terminal's own over SSH), and behind
 * `pig web` the page's — its title, its icon, a chime, a browser notification where allowed. The
 * page only says it when the person is not looking at it.
 */

use Pig\CodingAgent\Hooks\Events\AgentSettledEvent;
use Pig\CodingAgent\Hooks\Events\AgentStartEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookContext;

return static function (HookApi $pi): void {
    $startedAt = null;

    $pi->on('agent_start', static function (AgentStartEvent $event) use (&$startedAt): void {
        $startedAt ??= microtime(true);
    });

    $pi->on('agent_settled', static function (AgentSettledEvent $event, HookContext $ctx) use (&$startedAt): void {
        $seconds = $startedAt !== null ? (int) round(microtime(true) - $startedAt) : null;
        $startedAt = null;

        if ($event->aborted) {
            return;
        }

        $project = basename($ctx->cwd) ?: 'pig';
        $name = trim((string) $ctx->sessionName());
        $title = $name !== '' ? "{$name} · {$project}" : "pig · {$project}";

        $body = match (true) {
            $seconds === null => 'Task finished',
            $seconds >= 60 => sprintf('Task finished (%dm %ds)', intdiv($seconds, 60), $seconds % 60),
            default => "Task finished ({$seconds}s)",
        };

        $ctx->ui->systemNotify($title, $body);
    });
};
