<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * One assistant response and whatever tools it asks for. A run has as many turns as
 * the model needs, plus one for each steering or follow-up message.
 */
final readonly class TurnStartEvent implements AgentEvent
{
}
