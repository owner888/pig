<?php

declare(strict_types=1);

use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\Codemode\Declarations;
use Pig\Codemode\Identifier;
use Pig\Codemode\Registry;
use Pig\Codemode\Sandbox;
use Pig\Codemode\Source;
use Pig\Codemode\SourceError;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\Text;
use PigCodemode\CodemodeDescription;

foreach (['CodemodeDescription'] as $class) {
    if (!class_exists("PigCodemode\\{$class}", false)) {
        require __DIR__ . "/{$class}.php";
    }
}

/**
 * The `codemode` tool — upstream's `extensions/codemode`, with PHP where it has JavaScript and
 * a sandboxed `php` child where it has a QuickJS VM in a worker. See `Pig\Codemode\Sandbox` for
 * what the isolation is and is not.
 *
 * The model writes a script; the script calls nested tools as `$tools->name([...])`, fans out
 * with `parallel()`, filters what came back, and returns only what the model needs. Nested
 * calls go through the agent's own tools — the hooked ones, so the permission gate reaches
 * them — or, for a `codemode`-exposed MCP tool that is deliberately not on the model, through
 * `Pig\Codemode\Registry`, where the MCP extension puts them.
 *
 * Registered **inactive**: nothing happens until a server with `codemode` exposure connects
 * (the MCP extension activates it then) or `codemode.enabled: true` is in the settings.
 * `store()`/`load()` persist across scripts as a `codemode-store` entry in the session file,
 * upstream's name, so a conversation moved to pi keeps its store.
 */
return static function (ExtensionApi $pi): void {
    $storeEntryType = 'codemode-store';
    $defaultMaxOutputTokens = 10_000;
    $charsPerToken = 4;
    $argsPreviewChars = 200;
    $errorPreviewChars = 500;
    $active = false;
    $settings = null;

    /** @var array<string, array{name: string, description: string, parameters: array, outputSchema?: mixed, execute: Closure, namespace: ?array, exposure: string}> what scripts can call that the model cannot see */
    $nested = static fn (): array => Registry::all();

    /** The agent's own tools (the hooked ones), which scripts can call too, minus codemode itself. */
    $agentTools = static function (HookContext $ctx): array {
        $out = [];

        foreach ($ctx->session?->agent->tools() ?? [] as $tool) {
            $definition = $tool->definition();

            if ($definition->name === CodemodeDescription::TOOL_NAME) {
                continue;
            }

            $out[$definition->name] = ['definition' => $definition, 'tool' => $tool];
        }

        return $out;
    };

    /** Every callable tool as a declaration, agent's first, for the description and `ALL_TOOLS`. */
    $declarations = static function (?HookContext $ctx) use ($agentTools, $nested): array {
        $all = [];

        if ($ctx !== null) {
            foreach ($agentTools($ctx) as $name => $one) {
                $all[$name] = ['name' => $name, 'description' => $one['definition']->description, 'inputSchema' => $one['definition']->parameters];
            }
        }

        foreach ($nested() as $name => $tool) {
            $all[$name] ??= ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $tool['parameters'], ...(isset($tool['outputSchema']) ? ['outputSchema' => $tool['outputSchema']] : [])];
        }

        return array_values($all);
    };

    $namespaces = static function () use ($nested): array {
        $out = [];

        foreach ($nested() as $name => $tool) {
            if ($tool['namespace'] !== null) {
                $out[$name] = $tool['namespace'];
            }
        }

        return $out;
    };

    $deferredNames = static function () use ($nested): array {
        $out = [];

        foreach ($nested() as $name => $tool) {
            if ($tool['exposure'] === 'codemode-deferred') {
                $out[$name] = true;
            }
        }

        return $out;
    };

    /**
     * The description lists the tools the model cannot otherwise see — upstream's `on` mode:
     * nested tools without `direct` exposure. The agent's own tools are callable but not listed,
     * since the model has their schemas already.
     */
    $describe = static function () use ($nested, $namespaces, $deferredNames, &$settings): string {
        $listed = [];

        foreach ($nested() as $name => $tool) {
            $listed[] = ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $tool['parameters'], ...(isset($tool['outputSchema']) ? ['outputSchema' => $tool['outputSchema']] : [])];
        }

        $budget = $settings?->get('codemode.inlineBudget');

        return CodemodeDescription::build($listed, $namespaces(), $deferredNames(), is_int($budget) && $budget >= 0 ? $budget : CodemodeDescription::DEFAULT_INLINE_BUDGET);
    };

    // ---- running a script -----------------------------------------------------------------------

    $truncate = static fn (string $text, int $max): string => strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;

    $execute = static function (string $toolCallId, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use (
        $agentTools,
        $nested,
        $declarations,
        $namespaces,
        $storeEntryType,
        $defaultMaxOutputTokens,
        $charsPerToken,
        $argsPreviewChars,
        $errorPreviewChars,
        $truncate,
        $pi,
    ): AgentToolResult {
        $startedAt = microtime(true);

        try {
            $source = Source::parse((string) ($params['code'] ?? ''));
        } catch (SourceError $error) {
            throw new AgentError($error->getMessage());
        }

        /** @var list<array{id: string, name: string, args: string, status: string, durationMs?: float, error?: string}> */
        $calls = [];
        $publish = static function () use (&$calls, $onUpdate): void {
            if ($onUpdate !== null) {
                $onUpdate(new AgentToolResult([], ['calls' => $calls]));
            }
        };

        $textOf = static fn (AgentToolResult $result): string => implode("\n", array_map(
            static fn ($b) => $b instanceof TextContent ? $b->text : '',
            array_values(array_filter($result->content, static fn ($b): bool => $b instanceof TextContent)),
        ));

        // What a script receives for a call: a tool with an output schema (an MCP tool) gives
        // its structured content when there is one, otherwise its text — decoded when it is JSON,
        // because a PHP script wants an array and not a string it has to decode itself.
        //
        // A result that carries **images** — `read` on a PNG, `generate_image` — answers an array
        // with the text under `text` and the pictures under `images`, each an MCP-shaped block
        // (`type`, `data`, `mimeType`) so `image($r['images'][0])` shows it as it is. Upstream's
        // `models.generateImages()` hands back the same block shape for the same reason; without
        // this the script got the sentence "Saved image to …" and had to `read` the file again to
        // show what it had just made.
        $scriptValue = static function (string $name, AgentToolResult $result, bool $isError) use ($textOf): mixed {
            if (isset($result->details['structuredContent'])) {
                return $result->details['structuredContent'];
            }

            $text = $textOf($result);

            if ($isError) {
                throw new RuntimeException($text !== '' ? $text : "Tool \"{$name}\" failed");
            }

            $images = [];

            foreach ($result->content as $block) {
                if ($block instanceof ImageContent) {
                    $images[] = ['type' => 'image', 'data' => $block->data, 'mimeType' => $block->mimeType];
                }
            }

            if ($images !== []) {
                return ['text' => $text, 'images' => $images];
            }

            $decoded = json_decode($text, true);

            return is_array($decoded) && (str_starts_with(ltrim($text), '{') || str_starts_with(ltrim($text), '[')) ? $decoded : $text;
        };

        $record = static function (string $name, array $args) use (&$calls, $publish, $truncate, $argsPreviewChars, $toolCallId): int {
            $calls[] = ['id' => "{$toolCallId}/" . (count($calls) + 1), 'name' => $name, 'args' => $truncate((string) json_encode($args, JSON_UNESCAPED_SLASHES), $argsPreviewChars), 'status' => 'running'];
            $publish();

            return count($calls) - 1;
        };

        $sandboxTools = [];
        $samples = [];

        foreach ($declarations($ctx) as $declaration) {
            $samples[$declaration['name']] = Declarations::sample($declaration);
        }

        // The agent's tools: executed through the hooked tool, so a `tool_call` guard sees the call.
        foreach ($agentTools($ctx) as $name => $one) {
            $sandboxTools[] = ['name' => $name, 'description' => $samples[$name], 'execute' => static function (array $args, AbortSignal $callSignal) use ($name, $one, &$calls, $record, $publish, $scriptValue, $truncate, $errorPreviewChars, $textOf): mixed {
                $index = $record($name, $args);
                $at = microtime(true);
                $isError = false;

                try {
                    $result = $one['tool']->execute("codemode-{$index}", $args, $callSignal, null);
                } catch (\Throwable $error) {
                    $isError = true;
                    $result = new AgentToolResult([new TextContent($error->getMessage())]);
                }

                $calls[$index]['durationMs'] = (microtime(true) - $at) * 1000;
                $calls[$index]['status'] = $isError ? ($callSignal->aborted() ? 'cancelled' : 'error') : 'ok';

                if ($isError) {
                    $calls[$index]['error'] = $truncate($textOf($result) ?: "Tool \"{$name}\" failed", $errorPreviewChars);
                }

                $publish();

                return $scriptValue($name, $result, $isError);
            }];
        }

        // The nested-only tools: MCP tools the model is not shown.
        foreach ($nested() as $name => $tool) {
            if (isset($agentTools($ctx)[$name])) {
                continue;
            }

            $sandboxTools[] = ['name' => $name, 'description' => $samples[$name] ?? '', 'execute' => static function (array $args, AbortSignal $callSignal) use ($name, $tool, $ctx, &$calls, $record, $publish, $truncate, $errorPreviewChars): mixed {
                $index = $record($name, $args);
                $at = microtime(true);

                try {
                    $value = ($tool['execute'])($args, $callSignal, $ctx);
                    $calls[$index]['status'] = 'ok';
                } catch (\Throwable $error) {
                    $calls[$index]['status'] = $callSignal->aborted() ? 'cancelled' : 'error';
                    $calls[$index]['error'] = $truncate($error->getMessage(), $errorPreviewChars);
                    $calls[$index]['durationMs'] = (microtime(true) - $at) * 1000;
                    $publish();

                    throw $error;
                }

                $calls[$index]['durationMs'] = (microtime(true) - $at) * 1000;
                $publish();

                return $value;
            }];
        }

        $entry = static fn (string $name): array => ['name' => Identifier::of($name), 'description' => $samples[$name] ?? ''];
        $allNames = array_keys($samples);
        $globals = [
            'search_tools' => static function (array $args) use ($allNames, $samples, $namespaces, $entry, $declarations, $ctx): array {
                [$query, $options] = [$args[0] ?? null, is_array($args[1] ?? null) ? $args[1] : []];

                if (!is_string($query)) {
                    throw new RuntimeException('search_tools() expects a query string');
                }

                $limit = $options['limit'] ?? \Pig\Codemode\ToolSearch::DEFAULT_LIMIT;

                if (!is_int($limit) || $limit <= 0) {
                    throw new RuntimeException('search_tools() limit must be a positive integer');
                }

                $namespace = $options['namespace'] ?? null;

                if ($namespace !== null && !is_string($namespace)) {
                    throw new RuntimeException('search_tools() namespace must be a string');
                }

                $spaces = $namespaces();
                $documents = [];

                foreach ($declarations($ctx) as $declaration) {
                    $space = $spaces[$declaration['name']] ?? null;

                    if ($namespace !== null && ($space['name'] ?? null) !== $namespace) {
                        continue;
                    }

                    $documents[] = \Pig\Codemode\ToolSearch::document($declaration['name'], $declaration, $space['name'] ?? null, $space['description'] ?? null);
                }

                return array_map(static fn (array $m): array => $entry($m['name']), \Pig\Codemode\ToolSearch::rank($query, $documents, $limit));
            },
            'describe_tool' => static function (array $args) use ($allNames, $samples): ?string {
                $name = $args[0] ?? null;

                if (!is_string($name)) {
                    throw new RuntimeException('describe_tool() expects a tool name');
                }

                foreach ($allNames as $candidate) {
                    if ($candidate === $name || Identifier::of($candidate) === $name) {
                        return $samples[$candidate];
                    }
                }

                return null;
            },
        ];

        // `load()` sees the `codemode-store` entries on the branch, applied from the root.
        $store = [];

        foreach ($ctx->store?->customEntries($storeEntryType) ?? [] as $note) {
            $data = $note->data;

            if (!is_array($data)) {
                continue;
            }

            foreach ($data['delete'] ?? [] as $key) {
                unset($store[$key]);
            }

            foreach ($data['set'] ?? [] as $key => $value) {
                $store[$key] = $value;
            }
        }

        $sandbox = new Sandbox($sandboxTools, $globals, isset($source->options['timeoutMs']) ? $source->options['timeoutMs'] / 1000 : null);
        $result = $sandbox->execute($source->code, $signal, $store);

        foreach ($calls as &$call) {
            if ($call['status'] === 'running') {
                $call['status'] = 'cancelled';
            }
        }

        unset($call);

        $items = $result['output'];

        if ($result['ok']) {
            $writes = $result['storeWrites'];

            if ($writes['set'] !== [] || $writes['delete'] !== []) {
                $pi->appendEntry($storeEntryType, ['set' => $writes['set'], 'delete' => $writes['delete']]);
            }

            if (($result['value'] ?? null) !== null) {
                $value = $result['value'];
                $items[] = ['type' => 'text', 'text' => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            }
        } else {
            $error = $result['error'];
            $head = match ($error['kind'] ?? 'script') {
                'timeout' => "Script timed out: {$error['message']}",
                'aborted' => "Script aborted: {$error['message']}",
                'memory' => "Script ran out of memory: {$error['message']}",
                'sandbox' => "Script sandbox failed: {$error['message']}",
                default => $error['stack'] ?? (($error['name'] ?? 'Error') . ': ' . $error['message']),
            };
            $summary = $calls === []
                ? 'No tool calls were made.'
                : 'Tool calls made before the failure (they are not undone): ' . implode(', ', array_map(static fn (array $c): string => "{$c['name']} ({$c['status']})", $calls));
            $items[] = ['type' => 'text', 'text' => "Script error:\n{$head}\n\n{$summary}"];
        }

        // The token budget: when the text exceeds it, keep the start and the end and save the whole.
        $maxTokens = $source->options['maxOutputTokens'] ?? $defaultMaxOutputTokens;
        $texts = array_map(static fn (array $i): string => $i['text'], array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'text')));
        $combined = implode("\n", $texts);
        $budget = $maxTokens * $charsPerToken;
        $fullOutputPath = null;

        if ($texts !== [] && strlen($combined) > $budget) {
            [$cut, $removed] = \PigMcp\McpTools::truncateMiddle($combined, $budget);
            $text = 'Warning: truncated output (original token count: ' . (int) ceil(strlen($combined) / $charsPerToken) . ")\nTotal output lines: " . (substr_count($combined, "\n") + 1) . "\n\n{$cut}";

            try {
                $fullOutputPath = \PigMcp\McpTools::saveToTempFile($combined, '.txt');
                $text .= "\n\n[Full output: {$fullOutputPath} (read with offset/limit)]";
            } catch (\Throwable $error) {
                $text .= "\n\n[Could not save the full output: {$error->getMessage()}]";
            }

            $items = [['type' => 'text', 'text' => $text], ...array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'image'))];
        }

        $content = [new TextContent(($result['ok'] ? 'Script completed' : 'Script failed') . "\nWall time " . number_format(microtime(true) - $startedAt, 1) . " seconds\nOutput:\n")];

        foreach ($items as $item) {
            $content[] = $item['type'] === 'image' ? new ImageContent($item['data'], $item['mimeType']) : new TextContent($item['text']);
        }

        $details = ['calls' => $calls];

        if ($fullOutputPath !== null) {
            $details['fullOutputPath'] = $fullOutputPath;
        }

        if (!$result['ok']) {
            throw new AgentError(implode("\n", array_map(static fn ($b) => $b instanceof TextContent ? $b->text : '[image]', $content)), $details);
        }

        return new AgentToolResult($content, $details);
    };

    // ---- drawing --------------------------------------------------------------------------------

    $codePreviewLines = 10;
    $callPreviewCount = 8;
    $outputPreviewLines = 5;

    $renderCall = static function (array $args, Theme $theme) use ($codePreviewLines): Text {
        $code = $args['code'] ?? null;
        $text = $theme->fg('toolTitle', \Pig\Tui\Style::bold('codemode'));

        if ($code !== null && !is_string($code)) {
            $text .= ' ' . $theme->fg('error', '[invalid arg]');
        } elseif (is_string($code) && trim($code) !== '') {
            $lines = Themes::highlightCode(str_replace("\t", '    ', rtrim(str_replace("\r", '', $code))), 'php');
            $shown = array_slice($lines, 0, $codePreviewLines);
            $text .= "\n" . implode("\n", $shown);

            if (count($shown) < count($lines)) {
                $text .= "\n" . $theme->fg('muted', '... (' . (count($lines) - count($shown)) . ' more lines, ctrl+o to expand)');
            }
        }

        return new Text($text, 0, 0);
    };

    $renderResult = static function (AgentToolResult $result, RenderOptions $options, Theme $theme) use ($callPreviewCount, $outputPreviewLines): Text {
        $sections = [];
        $calls = is_array($result->details['calls'] ?? null) ? $result->details['calls'] : [];

        if ($calls !== []) {
            $shown = $options->expanded ? $calls : array_slice($calls, -$callPreviewCount);
            $lines = [];

            foreach ($shown as $call) {
                $icon = match ($call['status']) {
                    'running' => $theme->fg('warning', '…'),
                    'ok' => $theme->fg('success', '✓'),
                    'error' => $theme->fg('error', '✗'),
                    default => $theme->fg('muted', '⊘'),
                };
                $args = !$options->expanded && strlen($call['args']) > 80 ? substr($call['args'], 0, 77) . '...' : $call['args'];
                $line = "{$icon} " . $theme->fg('toolTitle', $call['name']) . ($args !== '' ? ' ' . $theme->fg('muted', $args) : '');

                if (isset($call['durationMs'])) {
                    $line .= ' ' . $theme->fg('dim', $call['durationMs'] < 1000 ? round($call['durationMs']) . 'ms' : number_format($call['durationMs'] / 1000, 1) . 's');
                }

                if ($options->expanded && isset($call['error'])) {
                    $line .= "\n    " . $theme->fg('error', str_replace("\n", "\n    ", $call['error']));
                }

                $lines[] = $line;
            }

            if (count($shown) < count($calls)) {
                array_unshift($lines, $theme->fg('muted', '... (' . (count($calls) - count($shown)) . ' earlier calls, ctrl+o to expand)'));
            }

            $sections[] = implode("\n", $lines);
        }

        if (!$options->partial) {
            $texts = [];

            foreach ($result->content as $index => $block) {
                // Drop the "Script completed\nWall time ...\nOutput:\n" header.
                if ($index === 0 && $block instanceof TextContent && preg_match('/^Script (completed|failed)\nWall time [\d.]+ seconds\nOutput:\n$/', $block->text) === 1) {
                    continue;
                }

                $texts[] = $block instanceof TextContent ? $block->text : '[image]';
            }

            $output = trim(implode("\n", $texts));

            if ($output !== '') {
                $lines = explode("\n", str_replace("\t", '    ', $output));
                $shown = $options->expanded ? $lines : array_slice($lines, 0, $outputPreviewLines);
                $colour = str_starts_with($output, 'Script error:') ? 'error' : 'toolOutput';
                $section = implode("\n", array_map(static fn (string $l): string => $theme->fg($colour, $l), $shown));

                if (count($shown) < count($lines)) {
                    $section .= "\n" . $theme->fg('muted', '... (' . (count($lines) - count($shown)) . ' more lines, ctrl+o to expand)');
                }

                $sections[] = $section;
            }
        }

        return new Text(implode("\n\n", $sections), 0, 0);
    };

    // ---- registering ----------------------------------------------------------------------------

    $tool = null;
    $register = static function () use ($pi, $describe, $execute, $renderCall, $renderResult, &$tool): void {
        $tool = new CustomTool(
            name: CodemodeDescription::TOOL_NAME,
            label: 'codemode',
            description: $describe(),
            parameters: [
                'type' => 'object',
                'properties' => ['code' => ['type' => 'string', 'description' => 'Raw PHP source. Top-level return works. May start with a `// @options: {"max_output_tokens": 1000}` line.']],
                'required' => ['code'],
            ],
            execute: $execute,
            renderCall: $renderCall,
            renderResult: $renderResult,
            promptSnippet: CodemodeDescription::PROMPT_SNIPPET,
            promptGuidelines: [CodemodeDescription::PROMPT_GUIDELINE],
        );
        $pi->registerTool($tool);
    };

    /** Put the tool on the model, or take it off; the description follows the registry. */
    $sync = static function () use ($pi, &$active, $register): void {
        if ($active) {
            $register();
        } else {
            $pi->removeTools(static fn (CustomTool $t): bool => $t->name === CodemodeDescription::TOOL_NAME);
        }
    };

    $inSession = false;
    $wantedBySetting = false;

    // On while a setting says so or while something codemode-only is registered — upstream's
    // `ensureDiscoveryActive()`, the half of it that is about codemode: a `codemode`-exposed
    // MCP server connecting is what puts a tool in the registry, and that is the activation.
    $decide = static function () use (&$active, &$inSession, &$wantedBySetting, $sync): void {
        $next = $inSession && ($wantedBySetting || !Registry::isEmpty());

        if ($next !== $active || $next) {
            $active = $next;
            $sync();
        }
    };

    Registry::onChange($decide);

    $pi->on('session_start', static function ($event, HookContext $ctx) use (&$inSession, &$settings, &$wantedBySetting, $decide): void {
        $settings = $ctx->session?->settings();
        $wantedBySetting = $settings?->get('codemode.enabled') === true;
        $inSession = true;
        $decide();
    });

    $pi->on('session_shutdown', static function () use (&$inSession, $decide): void {
        $inSession = false;
        $decide();
    });
};
