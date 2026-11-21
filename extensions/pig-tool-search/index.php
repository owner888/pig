<?php

declare(strict_types=1);

use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Codemode\ToolSearch;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ToolDefinition;

/**
 * The `tool_search` tool as an extension — upstream's built-in `extensions/tool-search`: registered
 * inactive (`defaultActive: false`) and `model-only`, activated by `--tools`, the `defaultTools`
 * setting or `setActiveTools()`. The search is upstream's `searchAndLoad()`: BM25 (`ToolSearch`)
 * over the registered tools with `codemode` or `deferred` exposure that are not active, and the
 * matches are activated, so they are declared from the next model call and the change is recorded
 * in the transcript like any other. The MCP extension activates it for a `deferred` server.
 */
return static function (ExtensionApi $pig): void {
    $search = static function (string $id, array $params) use ($pig): AgentToolResult {
        $query = trim((string) ($params['query'] ?? ''));

        if ($query === '') {
            throw new AgentError('query must not be empty');
        }

        $max = $params['limit'] ?? ToolSearch::DEFAULT_LIMIT;

        if (!is_numeric($max) || (float) $max !== floor((float) $max) || (int) $max <= 0) {
            throw new AgentError('limit must be a positive integer');
        }

        $active = $pig->getActiveTools();
        /** @var array<string, string> $descriptions name => description, of every candidate */
        $descriptions = [];
        $documents = [];

        // Upstream's `isSearchable()`: `codemode` or `deferred`, and not active.
        foreach ($pig->getAllTools() as $tool) {
            if (in_array($tool['exposure'], ['codemode', 'deferred'], true) && !in_array($tool['name'], $active, true)) {
                $descriptions[$tool['name']] = $tool['description'];
                $documents[] = ToolSearch::document(
                    $tool['name'],
                    ['description' => $tool['description'], 'inputSchema' => $tool['parameters']],
                    $tool['namespace']['name'] ?? null,
                    $tool['namespace']['description'] ?? null,
                );
            }
        }

        $matches = array_map(static fn (array $match): string => $match['name'], ToolSearch::rank($query, $documents, (int) $max));
        $lines = array_map(static fn (string $name): string => "- {$name}: " . trim((string) strtok(trim($descriptions[$name]), "\r\n")), $matches);

        if ($matches !== []) {
            $pig->setActiveTools([...$active, ...$matches]);
        }

        $text = $lines === []
            ? 'No matching tools found.'
            : sprintf("Loaded %d tool%s. They are available from your next call:\n%s", count($lines), count($lines) === 1 ? '' : 's', implode("\n", $lines));

        return new AgentToolResult([new TextContent($text)], ['loaded' => $matches]);
    };

    $pig->registerTool(new ToolDefinition(
        name: ToolSearch::TOOL_NAME,
        label: ToolSearch::TOOL_NAME,
        description: ToolSearch::description(),
        parameters: ToolSearch::parameters(),
        execute: $search,
        promptSnippet: 'Search for tools that are not loaded yet and load the matches',
        defaultActive: false,
        // A query can match any tool that is not declared yet.
        reachesTools: static fn (): string => 'all',
        // Searching is not something scripts need; it changes what the model sees.
        exposure: 'model-only',
    ));
};
