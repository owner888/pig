<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * Something the UI should react to.
 *
 * Ten arms, so a marker interface rather than a union — see CLAUDE.md. The shape of a run:
 *
 *     AgentStartEvent
 *       TurnStartEvent
 *         MessageStartEvent · MessageUpdateEvent* · MessageEndEvent   (the assistant)
 *         ToolExecutionStartEvent · ToolExecutionUpdateEvent* · ToolExecutionEndEvent
 *         MessageStartEvent · MessageEndEvent                         (each tool result)
 *       TurnEndEvent
 *       …more turns while there are tool calls, steering, or follow-ups
 *     AgentEndEvent
 */
interface AgentEvent
{
}
