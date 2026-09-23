<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

use Closure;
use InvalidArgumentException;

/**
 * A tool somebody wrote, as they declare it.
 *
 * Upstream's `CustomTool` is an object literal with five fields and three optional
 * callbacks; the PHP equivalent of an object literal is a value object built from
 * closures, so that is what this is. A tool file returns a factory that returns one of
 * these — or a list of them:
 *
 * ```php
 * <?php // ~/.pig/tools/wc/index.php
 *
 * use Pig\Agent\AgentToolResult;
 * use Pig\Ai\TextContent;
 * use Pig\CodingAgent\CustomTools\CustomTool;
 * use Pig\CodingAgent\CustomTools\CustomToolApi;
 *
 * return fn (CustomToolApi $pi) => new CustomTool(
 *     name: 'wc',
 *     label: 'Count lines',
 *     description: 'Count the lines in a file.',
 *     parameters: [
 *         'type' => 'object',
 *         'properties' => ['path' => ['type' => 'string', 'description' => 'the file']],
 *         'required' => ['path'],
 *     ],
 *     execute: function (string $id, array $params, ?Closure $onUpdate, $ctx) use ($pi) {
 *         $run = $pi->exec(['wc', '-l', $params['path']]);
 *
 *         return new AgentToolResult([new TextContent(trim($run->stdout))]);
 *     },
 * );
 * ```
 *
 * `execute` is given the same four things `AgentTool::execute()` is, plus the session as
 * a `HookContext` — which is the reason a custom tool is worth having over a built-in
 * one: it can read the conversation, see which model is answering, and stop the run.
 *
 * `renderCall` and `renderResult` are optional, and they are what makes a custom tool look
 * native rather than bolted on: without them the transcript shows the tool's name and its
 * text output, which is right for most tools and wrong for one whose result is a table.
 */
final readonly class CustomTool
{
    /** What a provider will accept as a tool name. */
    private const string NAME = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

    /**
     * @param array<string, mixed> $parameters JSON Schema for the arguments object
     * @param Closure(string, array<string, mixed>, ?Closure, \Pig\CodingAgent\Hooks\HookContext, ?\Pig\Async\AbortSignal): \Pig\Agent\AgentToolResult $execute
     * @param Closure(CustomToolSessionEvent, \Pig\CodingAgent\Hooks\HookContext): void|null $onSession
     *        called on `/new`, `/resume`, `/tree` and on the way out — for rebuilding
     *        state from the conversation, or letting go of something that was held
     * @param Closure(array<string, mixed>, \Pig\CodingAgent\Theme\Palette): \Pig\Tui\Component|null $renderCall
     *        the heading line, in place of the tool's name — given the arguments, which are
     *        still arriving and may be half a JSON object
     * @param Closure(\Pig\Agent\AgentToolResult, RenderOptions, \Pig\CodingAgent\Theme\Palette): \Pig\Tui\Component|null $renderResult
     *        what came back, in place of its text
     * @throws InvalidArgumentException when the declaration could not work
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public array $parameters,
        public Closure $execute,
        public ?Closure $onSession = null,
        public ?Closure $renderCall = null,
        public ?Closure $renderResult = null,
    ) {
        // Checked here rather than left to the provider: a tool with no description is
        // one the model will never choose, and a tool with a name the API rejects fails
        // the whole request rather than itself. The loader turns these into a complaint
        // naming the file, which is where the mistake is.
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException(
                "'{$name}' cannot be a tool name: a letter first, then letters, digits, dashes or underscores, at most 64.",
            );
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException("The tool '{$name}' needs a description — it is what the model chooses on.");
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException("The tool '{$name}' needs a label — it is what the UI shows while it runs.");
        }

        if (($parameters['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException(
                "The tool '{$name}' needs a JSON Schema object for its parameters: ['type' => 'object', 'properties' => [...]].",
            );
        }
    }
}
