<?php

declare(strict_types=1);

namespace PigCodemode;

use Pig\Codemode\Declarations;
use Pig\Codemode\Identifier;

/**
 * The model-facing description of the `codemode` tool — upstream's `createCodemodeDescription()`
 * in `extensions/codemode/tool.js`: the helper list, guidance for omitted tools, and one section
 * per nested tool grouped by namespace (an MCP server), within a token budget.
 *
 * The prose is upstream's with JavaScript replaced by PHP: `tools.x(...)` is `$tools->x([...])`,
 * `await Promise.all` is `parallel([...])`, `exit()` is `exit_script()` (PHP's `exit` would end the
 * sandbox), `console.log` is `echo`.
 */
final class CodemodeDescription
{
    public const string TOOL_NAME = 'codemode';

    /** Default for the inline tool catalog, in estimated tokens. */
    public const int DEFAULT_INLINE_BUDGET = 3000;

    private const int CHARS_PER_TOKEN = 4;

    public const string PROMPT_SNIPPET = 'Run PHP that calls other tools (chains, loops, parallel(), filtering large results)';

    public const string PROMPT_GUIDELINE = 'Use codemode to batch or chain several tool calls, or to filter large tool output down to what you need, instead of issuing many individual tool calls. Batch independent calls in one codemode call using parallel_settled([...]).';

    private const string INTRO = <<<'TEXT'
        Run PHP code to orchestrate/compose tool calls
        - Evaluates the provided PHP code in a fresh sandboxed `php` process as the body of a function: top-level `return` works, and `$tools` is in scope.
        - All nested tools are methods on `$tools`, for example `$tools->read(['path' => 'composer.json'])`. Tool names are exposed as normalized PHP identifiers, for example `$tools->mcp__ologs__get_profile([...])`.
        - Nested tool methods take one associative array as their input argument.
        - Nested tools return either an array (decoded JSON) or a string, based on the description.
        - A nested tool call that fails, is blocked, or gets invalid arguments throws an exception carrying the tool's error text.
        - Runs plain PHP -- no shell, no file system, no network, no `include`; `disable_functions` and `open_basedir` enforce it. String, array, math, JSON, regex and date functions all work.
        - Accepts raw PHP source text (no `<?php` needed), not JSON, quoted strings, or markdown code fences.
        - You may optionally start the tool input with a first line like `// @options: {"max_output_tokens": 1000, "timeout_ms": 60000}`.
        - `max_output_tokens` sets the token budget for the script's output. Defaults to 10000 tokens.
        - `timeout_ms` sets a hard deadline for the whole script. By default there is none.
        - When the script returns, nested calls still running are cancelled.
        - Tool calls are real and have side effects. If the script fails partway, earlier calls are not undone.
        - Scripts have a 256 MB memory limit; exceeding it fails the script. Filter or aggregate large data instead of accumulating it.

        - Global helpers:
        - `parallel(array $closures): array`: runs the closures' tool calls at the same time and returns their results in order; one that throws makes `parallel()` throw. Use it for independent calls: `[$a, $b] = parallel([fn () => $tools->x([...]), fn () => $tools->y([...])]);`
        - `parallel_settled(array $closures): array`: like `parallel()`, but each result is `['ok' => true, 'value' => ...]` or `['ok' => false, 'error' => '...']`, so one failure does not stop the rest.
        - `exit_script()`: Immediately ends the current script successfully (like an early return from the top level).
        - `text(mixed $value)`: Appends a text item. Non-string values are JSON-encoded.
        - `image(mixed $imageUrlOrItem)`: Appends an image item. Pass a base64 `data:` URL, `['image_url' => ...]`, or an MCP `ImageContent` block, for example `image($result['content'][0])`.
        - `store(string $key, mixed $value)`: stores a JSON-serializable value under a string key for later `codemode` calls in the same session. Storing `null` deletes the key. Writes are kept only if the script succeeds.
        - `load(string $key)`: returns the stored value for a string key, or `null` if it is missing.
        - `ALL_TOOLS`: metadata for the enabled nested tools as `['name' => ..., 'description' => ...]` entries.
        - `search_tools(string $query, array $options = [])`: returns the nested tools that best match the query (BM25, default `limit` 8, optional `namespace`), as `['name' => ..., 'description' => ...]` entries like `ALL_TOOLS`.
        - `describe_tool(string $name)`: returns the description and declaration of a nested tool, or `null`.
        - `echo` and `print` append a text item like `text()`.
        - `return $value` at the top level appends the value like `text()`.
        TEXT;

    private const string DEFERRED_GUIDANCE = <<<'TEXT'
        Some deferred nested tools may be omitted from this description. They are still available on `$tools` and listed in `ALL_TOOLS`.
        To find one, call `search_tools($query)`, or filter `ALL_TOOLS` by `name` and `description`.
        TEXT;

    /**
     * @param list<array{name: string, description: string, inputSchema: mixed, outputSchema?: mixed}> $tools the nested tools
     * @param array<string, array{name: string, description?: ?string}> $namespaces tool name => its namespace (MCP server)
     * @param array<string, true> $deferred tool names whose section is left out of the catalog
     */
    public static function build(array $tools, array $namespaces = [], array $deferred = [], ?int $inlineBudget = self::DEFAULT_INLINE_BUDGET): string
    {
        /** @var array<string, array{namespace: ?array, entries: list<array{name: string, section: string, cost: int, deferred: bool}>}> */
        $groups = ['' => ['namespace' => null, 'entries' => []]];

        foreach ($tools as $tool) {
            $namespace = $namespaces[$tool['name']] ?? null;
            $key = $namespace !== null ? 'ns:' . $namespace['name'] : '';
            $groups[$key] ??= ['namespace' => $namespace, 'entries' => []];
            $section = self::section($tool);
            $groups[$key]['entries'][] = [
                'name' => $tool['name'],
                'section' => $section,
                'cost' => (int) ceil(strlen($section) / self::CHARS_PER_TOKEN),
                'deferred' => isset($deferred[$tool['name']]),
            ];
        }

        $ordered = array_values($groups);
        usort($ordered, static function (array $a, array $b): int {
            if ($a['namespace'] === null) {
                return -1;
            }

            if ($b['namespace'] === null) {
                return 1;
            }

            return strcmp($a['namespace']['name'], $b['namespace']['name']);
        });

        $shown = self::selectCatalog($ordered, $inlineBudget);
        $complete = count($shown) === count($tools);
        $sections = [self::INTRO];

        if (!$complete) {
            $sections[] = self::DEFERRED_GUIDANCE;
        }

        if ($tools === []) {
            return implode("\n\n", $sections);
        }

        $count = count($tools);
        $toolSections = [$complete
            ? "Nested tools: COMPLETE list ({$count} tool" . ($count === 1 ? '' : 's') . ').'
            : 'Nested tools: PARTIAL - ' . count($shown) . " of {$count} shown."];

        foreach ($ordered as $group) {
            $visible = array_values(array_filter($group['entries'], static fn (array $e): bool => isset($shown[$e['name']])));

            if ($group['namespace'] !== null) {
                $total = count($group['entries']);
                $label = "{$total} tool" . ($total === 1 ? '' : 's');
                $suffix = count($visible) === $total ? '' : (count($visible) === 0 ? ', none shown' : ', ' . count($visible) . ' shown');
                $description = trim((string) ($group['namespace']['description'] ?? ''));
                $toolSections[] = "## {$group['namespace']['name']} ({$label}{$suffix})" . ($description !== '' ? "\n{$description}" : '');
            }

            foreach ($visible as $entry) {
                $toolSections[] = $entry['section'];
            }
        }

        $sections[] = implode("\n\n", $toolSections);

        return implode("\n\n", $sections);
    }

    /** `### \`id\` (\`raw name\`)` followed by the tool's description and declaration. */
    private static function section(array $tool): string
    {
        $id = Identifier::of($tool['name']);
        $heading = $id === $tool['name'] ? "### `{$id}`" : "### `{$id}` (`{$tool['name']}`)";

        return $heading . "\n" . trim(Declarations::sample($tool));
    }

    /**
     * Pick the tool sections that fit the budget, like OpenCode's catalog: in each round every
     * group (tools without a namespace first, then namespaces by name) places its cheapest
     * remaining tool; a group whose next tool does not fit drops out while the others continue.
     * Every namespace is represented before any namespace is complete.
     *
     * @param list<array{namespace: ?array, entries: list<array{name: string, section: string, cost: int, deferred: bool}>}> $groups
     * @return array<string, true>
     */
    private static function selectCatalog(array $groups, ?int $budget): array
    {
        $queues = [];

        foreach ($groups as $group) {
            $listable = array_values(array_filter($group['entries'], static fn (array $e): bool => !$e['deferred']));

            if ($budget === null) {
                foreach ($listable as $entry) {
                    $queues[] = [$entry];
                }

                continue;
            }

            usort($listable, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);

            if ($listable !== []) {
                $queues[] = $listable;
            }
        }

        $shown = [];

        if ($budget === null) {
            foreach ($queues as $queue) {
                foreach ($queue as $entry) {
                    $shown[$entry['name']] = true;
                }
            }

            return $shown;
        }

        $remaining = $budget;
        $active = $queues;

        while ($active !== []) {
            $next = [];

            foreach ($active as $queue) {
                $head = $queue[0];

                if ($head['cost'] > $remaining) {
                    continue;
                }

                $remaining -= $head['cost'];
                $shown[$head['name']] = true;
                array_shift($queue);

                if ($queue !== []) {
                    $next[] = $queue;
                }
            }

            $active = $next;
        }

        return $shown;
    }
}
