<?php

declare(strict_types=1);

use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Codemode\DeferredTools;
use Pig\Codemode\ToolSearch;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;

/**
 * The `tool_search` tool as an extension — upstream's built-in `extensions/tool-search`.
 *
 * Upstream registers `tool_search` inactive, and the MCP extension activates it when a server
 * has deferred tools. pig has no inactive tool, so this registers it while `DeferredTools` lists
 * something — a deferred tool, loaded or not — and takes it away when the list is empty. The
 * search is upstream's `searchAndLoad()`: BM25 (`ToolSearch`) over the listed tools that are not
 * loaded yet, and each match is loaded — registered by the extension that listed it — so it is
 * declared from the next model call.
 *
 * The description lists the sources the tools come from, as pig's has from the start; upstream's
 * is fixed and leaves that to the `mcp_servers` prompt section.
 */
return static function (ExtensionApi $pi): void {
    $registered = false;

    $search = static function (string $id, array $params): AgentToolResult {
        $query = trim((string) ($params['query'] ?? ''));

        if ($query === '') {
            throw new AgentError('query must not be empty');
        }

        $max = $params['limit'] ?? ToolSearch::DEFAULT_LIMIT;

        if (!is_numeric($max) || (float) $max !== floor((float) $max) || (int) $max <= 0) {
            throw new AgentError('limit must be a positive integer');
        }

        $documents = [];

        foreach (DeferredTools::all() as $name => $one) {
            if (!$one['loaded']) {
                $documents[] = ToolSearch::document($name, ['description' => $one['description'], 'inputSchema' => $one['inputSchema']], $one['namespace']['name'], $one['namespace']['description']);
            }
        }

        $matches = ToolSearch::rank($query, $documents, (int) $max);
        $lines = [];

        foreach ($matches as $match) {
            $tool = DeferredTools::load($match['name']);
            $lines[] = "- {$match['name']}: " . trim((string) strtok(trim((string) $tool?->description), "\r\n"));
        }

        $text = $lines === []
            ? 'No matching tools found.'
            : sprintf("Loaded %d tool%s. They are available from your next call:\n%s", count($lines), count($lines) === 1 ? '' : 's', implode("\n", $lines));

        return new AgentToolResult([new TextContent($text)], ['loaded' => array_map(static fn (array $m): string => $m['name'], $matches)]);
    };

    /** Register `tool_search` while something is listed, with the sources in its description. */
    $sync = static function () use ($pi, &$registered, $search): void {
        $listed = DeferredTools::all();

        if ($listed === []) {
            if ($registered) {
                $pi->removeTools(static fn (CustomTool $tool): bool => $tool->name === ToolSearch::TOOL_NAME);
                $registered = false;
            }

            return;
        }

        // Every source with a listed tool, loaded or not: a source whose tools are all loaded is
        // still where they came from. The ones still waiting come first.
        $sources = [];

        foreach ([false, true] as $loaded) {
            foreach ($listed as $one) {
                if ($one['loaded'] === $loaded) {
                    $sources[$one['namespace']['name']] ??= ['name' => $one['namespace']['name'], 'description' => $one['namespace']['description']];
                }
            }
        }

        // Registered again on every change, as pig-mcp did when it owned the tool: the
        // description stays current, and the tool goes after the ones just loaded.
        $registered = true;
        $pi->registerTool(new CustomTool(
            name: ToolSearch::TOOL_NAME,
            label: ToolSearch::TOOL_NAME,
            description: ToolSearch::description(array_values($sources)),
            parameters: ToolSearch::parameters(),
            execute: $search,
        ));
    };

    DeferredTools::onChange($sync);
    $sync();
};
