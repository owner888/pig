<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * Something the UI should react to.
 *
 * A marker interface rather than a union — see CLAUDE.md. Ten arms come from the agent loop,
 * and they are the shape of a run:
 *
 *     AgentStartEvent
 *       TurnStartEvent
 *         MessageStartEvent · MessageUpdateEvent* · MessageEndEvent   (the assistant)
 *         ToolExecutionStartEvent · ToolExecutionUpdateEvent* · ToolExecutionEndEvent
 *         MessageStartEvent · MessageEndEvent                         (each tool result)
 *       TurnEndEvent
 *       …more turns while there are tool calls, steering, or follow-ups
 *     AgentEndEvent
 *
 * **A listener may see arms that are not on that list.** Anything above the loop may put its
 * own events on the same stream, and `Pig\CodingAgent\Session\AgentSession` does: between
 * one run and the next it can announce that it is waiting out a 503, or summarising because
 * the prompt was too long. They arrive *after* an `AgentEndEvent` and before the
 * `AgentStartEvent` of the run they lead to, which is exactly where they happen.
 *
 * Which is why a `match` over this interface keeps its `default` arm — the ten above are not
 * all of them, and were never going to be. A listener that does not know an event ignores it.
 */
interface AgentEvent
{
}
