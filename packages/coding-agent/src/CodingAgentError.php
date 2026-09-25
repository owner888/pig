<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use RuntimeException;

/**
 * A startup that cannot go on, with a sentence worth showing as it is.
 *
 * The distinction this carries is the one that made `CodingAgent::session()` testable: every message
 * on it was, until that extraction, an `fwrite(STDERR, …)` followed by `exit(1)` inside `bin/pig`.
 * A caller catches this, prints the message, and chooses its own exit code — so the same refusal
 * reads the same whether it reaches a terminal, a JSON-lines host or a test.
 *
 * It carries no code and no category on purpose. Everything that throws this is something a person
 * has to fix before pig can start — a model name that matches nothing, an empty `--api-key`, a
 * session file that will not open — and none of those are worth telling apart programmatically.
 * Anything survivable is a warning on `StartedSession` instead.
 */
final class CodingAgentError extends RuntimeException
{
}
