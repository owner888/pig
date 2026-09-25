<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\Utils\JsonSchema;

/**
 * Checks a tool call's arguments against the schema the model was given.
 *
 * Upstream's `validateToolArguments`: run the schema, and throw a message the **model** reads. The
 * throw is the point — `AgentLoop` turns it into a tool result, so a model that got an argument
 * wrong is told what and calls again, where a crash would end the turn.
 *
 * The schema work is `Ai\Utils\JsonSchema`, which is upstream's AJV. **This used to be the
 * validator itself**, and covered two mistakes — a missing required property and a wrong primitive
 * type — with everything else passed through. That was defensible while there was nothing to check
 * a schema with and stopped being so once there was: two notions of what a tool's schema means is
 * the shape that goes wrong quietly, and the narrower one was the one nobody would have thought to
 * look at.
 *
 * What this file still decides is the **message**, which is upstream's format down to the blank
 * line before the arguments.
 */
final class ToolArguments
{
    /**
     * @return array<string, mixed> the arguments, unchanged, when they check out
     * @throws InvalidToolArguments
     */
    public static function validate(Tool $tool, ToolCall $call): array
    {
        $problems = JsonSchema::errors($tool->parameters, $call->arguments);

        if ($problems === []) {
            return $call->arguments;
        }

        throw new InvalidToolArguments(sprintf(
            "Validation failed for tool \"%s\":\n%s\n\nReceived arguments:\n%s",
            $call->name,
            implode("\n", array_map(static fn (string $problem): string => '  - ' . $problem, $problems)),
            json_encode($call->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ));
    }
}
