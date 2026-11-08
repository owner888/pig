<?php

declare(strict_types=1);

/**
 * Subagent Tool - Delegate tasks to specialized agents.
 *
 * Spawns a separate `pig` process for each subagent invocation, giving it an isolated context
 * window. Supports three modes:
 *   - Single: { agent: "name", task: "..." }
 *   - Parallel: { tasks: [{ agent: "name", task: "..." }, ...] }
 *   - Chain: { chain: [{ agent: "name", task: "... {previous} ..." }, ...] }
 *
 * Uses JSON mode to capture structured output from subagents.
 *
 * Upstream's `examples/extensions/subagent/index.ts`, in three files: this one is the tool —
 * `execute`, `renderCall`, `renderResult` — `Subagent.php` is the helpers and
 * `runSingleAgent()`, and `Agents.php` is `agents.ts`. An example, as upstream's is: not loaded
 * until it is linked into `~/.pig/agent/extensions/subagent/` (see `README.md`).
 */

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\Events\ToolResultEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\Results\ToolResultEventResult;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Component;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use PigSubagent\Agents;
use PigSubagent\Subagent;

require_once __DIR__ . '/Agents.php';
require_once __DIR__ . '/Subagent.php';

return static function (ExtensionApi $pig): void {
    $taskItem = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string', 'description' => 'Name of the agent to invoke'],
            'task' => ['type' => 'string', 'description' => 'Task to delegate to the agent'],
            'cwd' => ['type' => 'string', 'description' => 'Working directory for the agent process'],
        ],
        'required' => ['agent', 'task'],
    ];
    $chainItem = $taskItem;
    $chainItem['properties']['task']['description'] = 'Task with optional {previous} placeholder for prior output';

    $parameters = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string', 'description' => 'Name of the agent to invoke (for single mode)'],
            'task' => ['type' => 'string', 'description' => 'Task to delegate (for single mode)'],
            'tasks' => ['type' => 'array', 'items' => $taskItem, 'description' => 'Array of {agent, task} for parallel execution'],
            'chain' => ['type' => 'array', 'items' => $chainItem, 'description' => 'Array of {agent, task} for sequential execution'],
            'agentScope' => [
                'type' => 'string',
                'enum' => ['user', 'project', 'both'],
                'description' => 'Which agent directories to use. Default: "user". Use "both" to include project-local agents.',
                'default' => 'user',
            ],
            'confirmProjectAgents' => ['type' => 'boolean', 'description' => 'Prompt before running project-local agents. Default: true.', 'default' => true],
            'cwd' => ['type' => 'string', 'description' => 'Working directory for the agent process (single mode)'],
        ],
    ];

    // Tool execute results cannot set runtime error state directly, so patch it through the result hook.
    $pig->on('tool_result', static function (ToolResultEvent $event): ?ToolResultEventResult {
        if ($event->toolName !== 'subagent') {
            return null;
        }

        return is_array($event->details) && ($event->details['isError'] ?? false) ? new ToolResultEventResult(isError: true) : null;
    });

    $text = static fn (string $message): AgentToolResult => new AgentToolResult([new TextContent($message)]);

    /** @param list<array<string, mixed>> $items */
    $renderDisplayItems = static function (array $items, Theme $theme, bool $expanded, ?int $limit = null): string {
        $toShow = $limit !== null ? array_slice($items, -$limit) : $items;
        $skipped = $limit !== null && count($items) > $limit ? count($items) - $limit : 0;
        $out = '';

        if ($skipped > 0) {
            $out .= $theme->fg('muted', "... {$skipped} earlier items\n");
        }

        foreach ($toShow as $item) {
            if ($item['type'] === 'text') {
                $preview = $expanded ? $item['text'] : implode("\n", array_slice(explode("\n", $item['text']), 0, 3));
                $out .= $theme->fg('toolOutput', $preview) . "\n";
            } else {
                $out .= $theme->fg('muted', '→ ') . Subagent::formatToolCall($item['name'], $item['args'], $theme) . "\n";
            }
        }

        return rtrim($out);
    };

    $resultIcon = static function (array $child, Theme $theme): string {
        if ($child['status'] === 'pending' || $child['status'] === 'running') {
            return $theme->fg('warning', '⏳');
        }

        return Subagent::isFailed($child) ? $theme->fg('error', '✗') : $theme->fg('success', '✓');
    };

    /** @param list<array<string, mixed>> $results */
    $aggregateUsage = static function (array $results): array {
        $total = Subagent::emptyUsage();

        foreach ($results as $result) {
            foreach (['input', 'output', 'cacheRead', 'cacheWrite', 'cost', 'turns'] as $key) {
                $total[$key] += $result['usage'][$key];
            }
        }

        return $total;
    };

    /** The failure lines upstream adds under a child's header in every expanded view. */
    $addFailureLines = static function (Container $container, array $r, Theme $theme): void {
        if (Subagent::isFailed($r) && $r['errorMessage'] !== null && $r['errorMessage'] !== '') {
            $container->addChild(new Text($theme->fg('error', "Error: {$r['errorMessage']}"), 0, 0));
        }
        if ($r['terminationSignal'] !== null) {
            $container->addChild(new Text($theme->fg('error', "Signal: {$r['terminationSignal']}"), 0, 0));
        }
        if (trim($r['stderr']) !== '') {
            $container->addChild(new Text($theme->fg('error', 'stderr: ' . trim($r['stderr'])), 0, 0));
        }
    };

    /** A child's tool calls, then its final output as Markdown — the expanded view's body. */
    $addChildBody = static function (Container $container, array $r, Theme $theme): void {
        foreach (Subagent::displayItems($r['messages']) as $item) {
            if ($item['type'] === 'toolCall') {
                $container->addChild(new Text($theme->fg('muted', '→ ') . Subagent::formatToolCall($item['name'], $item['args'], $theme), 0, 0));
            }
        }

        $finalOutput = Subagent::finalOutput($r['messages']);

        if ($finalOutput !== '') {
            $container->addChild(new Spacer(1));
            $container->addChild(new Markdown(trim($finalOutput), 0, 0, Themes::getMarkdownTheme()));
        }
    };

    $pig->registerTool(new CustomTool(
        name: 'subagent',
        label: 'Subagent',
        description: implode(' ', [
            'Delegate tasks to specialized subagents with isolated context.',
            'Modes: single (agent + task), parallel (tasks array), chain (sequential with {previous} placeholder).',
            'Default agent scope is "user" (from ' . Config::home() . '/agents).',
            'To enable project-local agents in ' . Agents::CONFIG_DIR_NAME . '/agents, set agentScope: "both" (or "project").',
        ]),
        parameters: $parameters,
        execute: static function (string $toolCallId, array $params, ?Closure $onUpdate, ExtensionContext $ctx, ?AbortSignal $signal) use ($text): AgentToolResult {
            $agentScope = in_array($params['agentScope'] ?? null, ['user', 'project', 'both'], true) ? $params['agentScope'] : 'user';
            $dispatchDefaults = [
                'model' => $ctx->model !== null ? "{$ctx->model->provider}/{$ctx->model->id}" : null,
                'thinkingLevel' => $ctx->thinkingLevel()?->value,
            ];
            $discovery = Agents::discover($ctx->cwd, $agentScope);
            $agents = $discovery['agents'];
            $confirmProjectAgents = (bool) ($params['confirmProjectAgents'] ?? true);

            $chain = is_array($params['chain'] ?? null) ? array_values($params['chain']) : [];
            $tasks = is_array($params['tasks'] ?? null) ? array_values($params['tasks']) : [];
            $single = is_string($params['agent'] ?? null) && $params['agent'] !== '' && is_string($params['task'] ?? null) && $params['task'] !== '';
            $modeCount = (int) ($chain !== []) + (int) ($tasks !== []) + (int) $single;

            $makeDetails = static fn (string $mode, bool $forceError = false): Closure => static fn (array $results): array => [
                'mode' => $mode,
                'results' => $results,
                'isError' => $forceError || array_filter($results, Subagent::isFailed(...)) !== [],
            ];
            $availableText = static fn (): string => implode(', ', array_map(static fn (array $a): string => "{$a['name']} ({$a['source']})", $agents)) ?: 'none';

            if ($modeCount !== 1) {
                return new AgentToolResult(
                    [new TextContent("Invalid parameters. Provide exactly one mode.\nAvailable agents: {$availableText()}")],
                    $makeDetails('single', true)([]),
                );
            }

            $cwdOf = static fn (array $item): ?string => is_string($item['cwd'] ?? null) ? $item['cwd'] : null;
            $resolvedChain = array_map(static fn (array $step): array => Subagent::resolveAgent($ctx->cwd, $agentScope, (string) $step['agent'], $cwdOf($step)), $chain);
            $resolvedTasks = array_map(static fn (array $task): array => Subagent::resolveAgent($ctx->cwd, $agentScope, (string) $task['agent'], $cwdOf($task)), $tasks);
            $resolvedSingle = $single ? Subagent::resolveAgent($ctx->cwd, $agentScope, $params['agent'], $cwdOf($params)) : null;

            if (($agentScope === 'project' || $agentScope === 'both') && $confirmProjectAgents && $ctx->hasUi) {
                $selected = $chain !== [] ? $resolvedChain : ($tasks !== [] ? $resolvedTasks : ($resolvedSingle !== null ? [$resolvedSingle] : []));
                $projectAgentsByPath = [];

                foreach ($selected as $resolved) {
                    if ($resolved['agent'] !== null && $resolved['agent']['source'] === 'project') {
                        $projectAgentsByPath[$resolved['agent']['filePath']] = $resolved['agent'];
                    }
                }

                // Trusted means this project's own `.pig/agents`; an agent found from another task
                // `cwd` is still asked about.
                $trustedProjectAgentsDir = $ctx->isProjectTrusted() && $discovery['projectAgentsDir'] !== null
                    ? realpath($discovery['projectAgentsDir'])
                    : null;
                $requested = array_values(array_filter(
                    $projectAgentsByPath,
                    static fn (array $agent): bool => $trustedProjectAgentsDir === false || $trustedProjectAgentsDir === null
                        || realpath(dirname($agent['filePath'])) !== $trustedProjectAgentsDir,
                ));

                if ($requested !== []) {
                    $sources = implode("\n", array_map(static fn (array $agent): string => "- {$agent['name']}: {$agent['filePath']}", $requested));
                    $ok = $ctx->ui->confirm(
                        'Run project-local agents?',
                        "Sources:\n{$sources}\n\nProject agents are repo-controlled. Only continue for trusted repositories.",
                    );

                    if (!$ok) {
                        return new AgentToolResult(
                            [new TextContent('Canceled: project-local agents not approved.')],
                            $makeDetails($chain !== [] ? 'chain' : ($tasks !== [] ? 'parallel' : 'single'))([]),
                        );
                    }
                }
            }

            if ($chain !== []) {
                $results = [];
                $previousOutput = '';

                foreach ($chain as $i => $step) {
                    $taskWithContext = str_replace('{previous}', $previousOutput, (string) $step['task']);

                    // Create update callback that includes all previous results
                    $chainUpdate = $onUpdate === null ? null : static function (AgentToolResult $partial) use ($onUpdate, &$results, $makeDetails): void {
                        // Combine completed results with current streaming result
                        $currentResult = $partial->details['results'][0] ?? null;

                        if ($currentResult !== null) {
                            $onUpdate(new AgentToolResult($partial->content, $makeDetails('chain')([...$results, $currentResult])));
                        }
                    };

                    $result = Subagent::runSingleAgent($dispatchDefaults, $resolvedChain[$i], (string) $step['agent'], $taskWithContext, $i + 1, $signal, $chainUpdate, $makeDetails('chain'));
                    $results[] = $result;

                    if (Subagent::isFailed($result)) {
                        $errorMsg = Subagent::resultOutput($result);

                        return new AgentToolResult(
                            [new TextContent(Subagent::truncateModelOutput('Chain stopped at step ' . ($i + 1) . " ({$step['agent']}): {$errorMsg}"))],
                            $makeDetails('chain')($results),
                        );
                    }

                    $previousOutput = Subagent::finalOutput($result['messages']);
                }

                return new AgentToolResult(
                    [new TextContent(Subagent::truncateModelOutput(Subagent::finalOutput($results[count($results) - 1]['messages']) ?: '(no output)'))],
                    $makeDetails('chain')($results),
                );
            }

            if ($tasks !== []) {
                if (count($tasks) > Subagent::MAX_PARALLEL_TASKS) {
                    return new AgentToolResult(
                        [new TextContent('Too many parallel tasks (' . count($tasks) . '). Max is ' . Subagent::MAX_PARALLEL_TASKS . '.')],
                        $makeDetails('parallel', true)([]),
                    );
                }

                // Track all results for streaming updates
                $allResults = [];

                foreach ($tasks as $i => $task) {
                    $allResults[$i] = Subagent::unstartedResult((string) $task['agent'], (string) $task['task'], null, 'failed');
                    $allResults[$i]['status'] = 'pending';
                }

                $emitParallelUpdate = static function () use (&$allResults, $onUpdate, $makeDetails): void {
                    if ($onUpdate === null) {
                        return;
                    }

                    $running = count(array_filter($allResults, static fn (array $r): bool => $r['status'] === 'running'));
                    $pending = count(array_filter($allResults, static fn (array $r): bool => $r['status'] === 'pending'));
                    $done = count($allResults) - $running - $pending;
                    $onUpdate(new AgentToolResult(
                        [new TextContent('Parallel: ' . $done . '/' . count($allResults) . " done, {$running} running, {$pending} pending...")],
                        $makeDetails('parallel')(array_values($allResults)),
                    ));
                };

                $results = Subagent::mapWithConcurrencyLimit($tasks, Subagent::MAX_CONCURRENCY, static function (array $t, int $index) use (
                    $dispatchDefaults,
                    $resolvedTasks,
                    $signal,
                    &$allResults,
                    $emitParallelUpdate,
                    $makeDetails,
                ): array {
                    $result = Subagent::runSingleAgent(
                        $dispatchDefaults,
                        $resolvedTasks[$index],
                        (string) $t['agent'],
                        (string) $t['task'],
                        null,
                        $signal,
                        // Per-task update callback
                        static function (AgentToolResult $partial) use (&$allResults, $index, $emitParallelUpdate): void {
                            if (isset($partial->details['results'][0])) {
                                $allResults[$index] = $partial->details['results'][0];
                                $emitParallelUpdate();
                            }
                        },
                        $makeDetails('parallel'),
                    );
                    $allResults[$index] = $result;
                    $emitParallelUpdate();

                    return $result;
                });

                $successCount = count(array_filter($results, static fn (array $r): bool => !Subagent::isFailed($r)));
                $summaries = array_map(static function (array $r): string {
                    $output = Subagent::truncateParallelOutput(Subagent::resultOutput($r));
                    $status = Subagent::isFailed($r) && $r['stopReason'] !== null && $r['stopReason'] !== 'end'
                        ? "{$r['status']} ({$r['stopReason']})"
                        : $r['status'];

                    return "### [{$r['agent']}] {$status}\n\n{$output}";
                }, $results);

                return new AgentToolResult(
                    [new TextContent("Parallel: {$successCount}/" . count($results) . " succeeded\n\n" . implode("\n\n---\n\n", $summaries))],
                    $makeDetails('parallel')($results),
                );
            }

            if ($resolvedSingle !== null) {
                $result = Subagent::runSingleAgent($dispatchDefaults, $resolvedSingle, $params['agent'], $params['task'], null, $signal, $onUpdate, $makeDetails('single'));

                if (Subagent::isFailed($result)) {
                    $errorMsg = Subagent::resultOutput($result);

                    return new AgentToolResult(
                        [new TextContent(Subagent::truncateModelOutput('Agent ' . ($result['stopReason'] ?? $result['status']) . ": {$errorMsg}"))],
                        $makeDetails('single')([$result]),
                    );
                }

                return new AgentToolResult(
                    [new TextContent(Subagent::truncateModelOutput(Subagent::finalOutput($result['messages']) ?: '(no output)'))],
                    $makeDetails('single')([$result]),
                );
            }

            return new AgentToolResult(
                [new TextContent("Invalid parameters. Available agents: {$availableText()}")],
                $makeDetails('single', true)([]),
            );
        },
        renderCall: static function (array $args, Theme $theme, ?RenderOptions $options = null): Component {
            $scope = is_string($args['agentScope'] ?? null) ? $args['agentScope'] : 'user';
            $title = $theme->fg('toolTitle', $theme->bold('subagent '));
            $preview = static fn (string $text, int $max): string => mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '...' : $text;

            if (is_array($args['chain'] ?? null) && $args['chain'] !== []) {
                $chain = array_values($args['chain']);
                $out = $title . $theme->fg('accent', 'chain (' . count($chain) . ' steps)') . $theme->fg('muted', " [{$scope}]");

                foreach (array_slice($chain, 0, 3) as $i => $step) {
                    // Clean up {previous} placeholder for display
                    $cleanTask = trim(str_replace('{previous}', '', (string) ($step['task'] ?? '')));
                    $out .= "\n  " . $theme->fg('muted', ($i + 1) . '.') . ' ' . $theme->fg('accent', (string) ($step['agent'] ?? '...')) . $theme->fg('dim', ' ' . $preview($cleanTask, 40));
                }
                if (count($chain) > 3) {
                    $out .= "\n  " . $theme->fg('muted', '... +' . (count($chain) - 3) . ' more');
                }

                return new Text($out, 0, 0);
            }

            if (is_array($args['tasks'] ?? null) && $args['tasks'] !== []) {
                $tasks = array_values($args['tasks']);
                $out = $title . $theme->fg('accent', 'parallel (' . count($tasks) . ' tasks)') . $theme->fg('muted', " [{$scope}]");

                foreach (array_slice($tasks, 0, 3) as $t) {
                    $out .= "\n  " . $theme->fg('accent', (string) ($t['agent'] ?? '...')) . $theme->fg('dim', ' ' . $preview((string) ($t['task'] ?? ''), 40));
                }
                if (count($tasks) > 3) {
                    $out .= "\n  " . $theme->fg('muted', '... +' . (count($tasks) - 3) . ' more');
                }

                return new Text($out, 0, 0);
            }

            $agentName = is_string($args['agent'] ?? null) && $args['agent'] !== '' ? $args['agent'] : '...';
            $task = is_string($args['task'] ?? null) && $args['task'] !== '' ? $preview($args['task'], 60) : '...';

            return new Text($title . $theme->fg('accent', $agentName) . $theme->fg('muted', " [{$scope}]") . "\n  " . $theme->fg('dim', $task), 0, 0);
        },
        renderResult: static function (AgentToolResult $result, RenderOptions $options, Theme $theme) use (
            $renderDisplayItems,
            $resultIcon,
            $aggregateUsage,
            $addFailureLines,
            $addChildBody,
        ): Component {
            $details = is_array($result->details) ? $result->details : null;
            $expanded = $options->expanded;
            $plain = static function () use ($result): Text {
                $first = $result->content[0] ?? null;

                return new Text($first instanceof TextContent ? $first->text : '(no output)', 0, 0);
            };

            if ($details === null || ($details['results'] ?? []) === []) {
                return $plain();
            }

            $results = $details['results'];

            if ($details['mode'] === 'single' && count($results) === 1) {
                $r = $results[0];
                $isError = Subagent::isFailed($r);
                $isRunning = $r['status'] === 'pending' || $r['status'] === 'running';
                $icon = $resultIcon($r, $theme);
                $displayItems = Subagent::displayItems($r['messages']);
                $finalOutput = Subagent::finalOutput($r['messages']);
                $header = "{$icon} " . $theme->fg('toolTitle', $theme->bold($r['agent'])) . $theme->fg('muted', " ({$r['agentSource']})");

                if ($isRunning) {
                    $header .= ' ' . $theme->fg('warning', "[{$r['status']}]");
                } elseif ($isError) {
                    $header .= ' ' . $theme->fg('error', '[' . ($r['stopReason'] ?? $r['status']) . ']');
                }

                if ($expanded) {
                    $container = new Container();
                    $container->addChild(new Text($header, 0, 0));
                    $addFailureLines($container, $r, $theme);
                    $container->addChild(new Spacer(1));
                    $container->addChild(new Text($theme->fg('muted', '─── Task ───'), 0, 0));
                    $container->addChild(new Text($theme->fg('dim', $r['task']), 0, 0));
                    $container->addChild(new Spacer(1));
                    $container->addChild(new Text($theme->fg('muted', '─── Output ───'), 0, 0));

                    if ($displayItems === [] && $finalOutput === '') {
                        $container->addChild(new Text($theme->fg('muted', '(no output)'), 0, 0));
                    } else {
                        $addChildBody($container, $r, $theme);
                    }

                    $usageStr = Subagent::formatUsageStats($r['usage'], $r['model']);

                    if ($usageStr !== '') {
                        $container->addChild(new Spacer(1));
                        $container->addChild(new Text($theme->fg('dim', $usageStr), 0, 0));
                    }

                    return $container;
                }

                $out = $header;

                if ($isError && $r['errorMessage'] !== null && $r['errorMessage'] !== '') {
                    $out .= "\n" . $theme->fg('error', "Error: {$r['errorMessage']}");
                } elseif ($displayItems === []) {
                    $out .= "\n" . $theme->fg('muted', '(no output)');
                } else {
                    $out .= "\n" . $renderDisplayItems($displayItems, $theme, false, Subagent::COLLAPSED_ITEM_COUNT);

                    if (count($displayItems) > Subagent::COLLAPSED_ITEM_COUNT) {
                        $out .= "\n" . $theme->fg('muted', '(Ctrl+O to expand)');
                    }
                }

                $usageStr = Subagent::formatUsageStats($r['usage'], $r['model']);

                if ($usageStr !== '') {
                    $out .= "\n" . $theme->fg('dim', $usageStr);
                }

                return new Text($out, 0, 0);
            }

            if ($details['mode'] === 'chain') {
                $successCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'completed'));
                $hasActive = array_filter($results, static fn (array $r): bool => $r['status'] === 'pending' || $r['status'] === 'running') !== [];
                $icon = $hasActive ? $theme->fg('warning', '⏳') : ($successCount === count($results) ? $theme->fg('success', '✓') : $theme->fg('error', '✗'));
                $title = $icon . ' ' . $theme->fg('toolTitle', $theme->bold('chain ')) . $theme->fg('accent', "{$successCount}/" . count($results) . ' steps');

                if ($expanded) {
                    $container = new Container();
                    $container->addChild(new Text($title, 0, 0));

                    foreach ($results as $r) {
                        $container->addChild(new Spacer(1));
                        $container->addChild(new Text($theme->fg('muted', "─── Step {$r['step']}: ") . $theme->fg('accent', $r['agent']) . ' ' . $resultIcon($r, $theme), 0, 0));
                        $container->addChild(new Text($theme->fg('muted', 'Task: ') . $theme->fg('dim', $r['task']), 0, 0));
                        $addFailureLines($container, $r, $theme);
                        $addChildBody($container, $r, $theme);
                        $stepUsage = Subagent::formatUsageStats($r['usage'], $r['model']);

                        if ($stepUsage !== '') {
                            $container->addChild(new Text($theme->fg('dim', $stepUsage), 0, 0));
                        }
                    }

                    $usageStr = Subagent::formatUsageStats($aggregateUsage($results));

                    if ($usageStr !== '') {
                        $container->addChild(new Spacer(1));
                        $container->addChild(new Text($theme->fg('dim', "Total: {$usageStr}"), 0, 0));
                    }

                    return $container;
                }

                // Collapsed view
                $out = $title;

                foreach ($results as $r) {
                    $displayItems = Subagent::displayItems($r['messages']);
                    $out .= "\n\n" . $theme->fg('muted', "─── Step {$r['step']}: ") . $theme->fg('accent', $r['agent']) . ' ' . $resultIcon($r, $theme);

                    if ($displayItems === []) {
                        $failed = Subagent::isFailed($r);
                        $out .= "\n" . $theme->fg($failed ? 'error' : 'muted', $failed ? Subagent::failureSummary($r) : '(no output)');
                    } else {
                        $out .= "\n" . $renderDisplayItems($displayItems, $theme, false, 5);
                    }
                }

                $usageStr = Subagent::formatUsageStats($aggregateUsage($results));

                if ($usageStr !== '') {
                    $out .= "\n\n" . $theme->fg('dim', "Total: {$usageStr}");
                }

                return new Text($out . "\n" . $theme->fg('muted', '(Ctrl+O to expand)'), 0, 0);
            }

            if ($details['mode'] === 'parallel') {
                $running = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'running'));
                $pending = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'pending'));
                $successCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'completed'));
                $failCount = count(array_filter($results, Subagent::isFailed(...)));
                $isRunning = $running > 0 || $pending > 0;
                $icon = $isRunning ? $theme->fg('warning', '⏳') : ($failCount > 0 ? $theme->fg('warning', '◐') : $theme->fg('success', '✓'));
                $status = $isRunning
                    ? ($successCount + $failCount) . '/' . count($results) . " done, {$running} running, {$pending} pending"
                    : "{$successCount}/" . count($results) . ' tasks';
                $title = "{$icon} " . $theme->fg('toolTitle', $theme->bold('parallel ')) . $theme->fg('accent', $status);

                if ($expanded && !$isRunning) {
                    $container = new Container();
                    $container->addChild(new Text($title, 0, 0));

                    foreach ($results as $r) {
                        $container->addChild(new Spacer(1));
                        $container->addChild(new Text($theme->fg('muted', '─── ') . $theme->fg('accent', $r['agent']) . ' ' . $resultIcon($r, $theme), 0, 0));
                        $container->addChild(new Text($theme->fg('muted', 'Task: ') . $theme->fg('dim', $r['task']), 0, 0));
                        $addFailureLines($container, $r, $theme);
                        $addChildBody($container, $r, $theme);
                        $taskUsage = Subagent::formatUsageStats($r['usage'], $r['model']);

                        if ($taskUsage !== '') {
                            $container->addChild(new Text($theme->fg('dim', $taskUsage), 0, 0));
                        }
                    }

                    $usageStr = Subagent::formatUsageStats($aggregateUsage($results));

                    if ($usageStr !== '') {
                        $container->addChild(new Spacer(1));
                        $container->addChild(new Text($theme->fg('dim', "Total: {$usageStr}"), 0, 0));
                    }

                    return $container;
                }

                // Collapsed view (or still running)
                $out = $title;

                foreach ($results as $r) {
                    $displayItems = Subagent::displayItems($r['messages']);
                    $out .= "\n\n" . $theme->fg('muted', '─── ') . $theme->fg('accent', $r['agent']) . ' ' . $resultIcon($r, $theme);

                    if ($displayItems === []) {
                        $failed = Subagent::isFailed($r);
                        $summary = match (true) {
                            $r['status'] === 'pending' => '(pending...)',
                            $r['status'] === 'running' => '(running...)',
                            $failed => Subagent::failureSummary($r),
                            default => '(no output)',
                        };
                        $out .= "\n" . $theme->fg($failed ? 'error' : 'muted', $summary);
                    } else {
                        $out .= "\n" . $renderDisplayItems($displayItems, $theme, false, 5);
                    }
                }

                if (!$isRunning) {
                    $usageStr = Subagent::formatUsageStats($aggregateUsage($results));

                    if ($usageStr !== '') {
                        $out .= "\n\n" . $theme->fg('dim', "Total: {$usageStr}");
                    }
                }
                if (!$expanded) {
                    $out .= "\n" . $theme->fg('muted', '(Ctrl+O to expand)');
                }

                return new Text($out, 0, 0);
            }

            return $plain();
        },
    ));
};
