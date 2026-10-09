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

    public const string PROMPT_SNIPPET = 'Run PHP that calls other tools';

    public const string PROMPT_GUIDELINE = 'Use codemode to batch independent tool calls (parallel_settled), chain them, or filter large output, instead of many separate calls.';

    /** The reference for scripts, beside this file: globals, tool results, `store()`, and limits. */
    public const string DOCS_PATH = __DIR__ . '/CODEMODE.md';

    /**
     * What the model reads on every request, so it is short — upstream's 1.0 `DESCRIPTION_INTRO`
     * plus one line per global. The details are in `CODEMODE.md`, which the model reads when it
     * needs them; the first version of this spelled every helper out here and cost ~830 tokens
     * of every request against ~300 now.
     */
    private const string INTRO = <<<'TEXT'
        Run PHP that calls other tools. The input is raw PHP source (no `<?php`, not JSON, no code fence), run as a function body in a sandboxed `php` process: top-level `return` works and `$tools` is in scope. No shell, file system, network or `include`.
        - `$tools->name([...args])` answers a string, or an array if the tool's declaration says so, and throws on failure. A result with pictures in it answers `['text' => ..., 'images' => [block, ...]]`; show one with `image($r['images'][0])`. Calls still running when the script ends are cancelled.
        - Optional first line: `// @options: {"max_output_tokens": 10000, "timeout_ms": 60000}`
        TEXT;

    private const string GLOBALS = <<<'TEXT'
        Globals:
        - `text($value)`, `image($dataUrlOrImageBlock)`, `echo`, and top-level `return` add output; `exit_script()` ends the script (not `exit`). `image()` also saves the image to a temp file and the result names its path.
        - `parallel([fn () => ..., ...])` runs independent calls at once, keys kept; `parallel_settled([...])` answers `['ok' => bool, 'value' | 'error']` per arm so one failure does not stop the rest.
        - `store($key, $value)` and `load($key)` keep small JSON values across codemode calls.
        - `ALL_TOOLS`, `search_tools($query, ['limit' => 8, 'namespace' => ...])`, `describe_tool($name)`, `describe_namespace($name)`: find unlisted tools, such as MCP tools.
        - `$models`: classifiers and image generation.
        TEXT;

    private const string DEFERRED_GUIDANCE = 'Some nested tools are not listed here. They are still on `$tools` and in `ALL_TOOLS`; `search_tools($query)` finds them.';

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
        $sections = [self::INTRO, self::GLOBALS . "\n- The full reference is " . self::DOCS_PATH . '; read it when a detail above is not enough.'];

        if (!$complete) {
            $sections[] = self::DEFERRED_GUIDANCE;
        }

        if ($tools === []) {
            return implode("\n\n", $sections);
        }

        $toolSections = ['Nested tools:'];

        foreach ($ordered as $group) {
            $visible = array_values(array_filter($group['entries'], static fn (array $e): bool => isset($shown[$e['name']])));

            if ($group['namespace'] !== null) {
                // Only tools that did not fit the budget are counted as not listed here.
                $listing = count($visible) === count($group['entries']) ? '' : (count($visible) === 0 ? ' (tools not listed)' : ' (some tools not listed)');
                $description = trim((string) ($group['namespace']['description'] ?? ''));
                $toolSections[] = "## {$group['namespace']['name']}{$listing}" . ($description !== '' ? "\n{$description}" : '');
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
