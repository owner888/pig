<?php

declare(strict_types=1);

namespace Pig\Agent;

use RuntimeException;

/** The loop was asked to do something it cannot: continue from nowhere, run a tool that is not there. */
final class AgentError extends RuntimeException
{
}
