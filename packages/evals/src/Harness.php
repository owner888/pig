<?php

declare(strict_types=1);

namespace Pig\Evals;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Models;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\CustomModels;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\Events\BeforeAgentStartEvent;
use Pig\CodingAgent\Extensions\Events\SessionShutdownEvent;
use Pig\CodingAgent\Extensions\Events\SessionStartEvent;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Extensions\RegisteredTool;
use Pig\CodingAgent\Extensions\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\McpServerRegistry;
use Pig\CodingAgent\ModelRefresh;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Tools\ToolLoadout;
use Pig\CodingAgent\Tools\ToolSelection;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\CodingAgent\VirtualModelRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Upstream's `packages/evals/src/harness.ts`: runs an isolated `AgentSession` over one or more
 * `prompt` / `reload` steps, captures transcript events, token/cost telemetry and the session JSONL
 * artifact, and enforces the `with_docs` / `without_docs` system prompt invariants.
 */
final class Harness
{
    /**
     * Documentation evals intentionally exclude shell and unrestricted network tools.
     *
     * @var list<string>
     */
    public const array DOCUMENTATION_EVAL_TOOLS = ['read', 'write', 'edit', 'grep', 'find', 'ls'];

    /**
     * @param array{provider?: string, id?: string}|null $explicitModel
     * @param array<string, string|null>|null $environment
     * @return array{provider: string, id: string}
     */
    public static function resolveModelSelection(?array $explicitModel = null, ?array $environment = null): array
    {
        $envProvider = $environment !== null
            ? ($environment['PIG_PROVIDER'] ?? $environment['PI_PROVIDER'] ?? null)
            : (self::env('PIG_PROVIDER') ?? self::env('PI_PROVIDER'));
        $envModel = $environment !== null
            ? ($environment['PIG_MODEL'] ?? $environment['PI_MODEL'] ?? null)
            : (self::env('PIG_MODEL') ?? self::env('PI_MODEL'));

        $provider = trim((string) ($explicitModel['provider'] ?? $envProvider ?? ''));
        $id = trim((string) ($explicitModel['id'] ?? $envModel ?? ''));

        if ($provider === '' || $id === '') {
            throw new InvalidArgumentException('Select a harness model explicitly or set both PIG_PROVIDER and PIG_MODEL as defaults.');
        }

        return ['provider' => $provider, 'id' => $id];
    }

    /**
     * Isolate `HOME`, `USERPROFILE` and `PIG_CODING_AGENT_DIR`, stripping `PIG_EVAL_*` / `PI_EVAL_*`
     * runner variables for the duration of the run.
     *
     * @return Closure(): void
     */
    public static function applyIsolatedEnvironment(string $home, string $agentDir): Closure
    {
        $overrides = [
            'HOME' => $home,
            'USERPROFILE' => $home,
            'PIG_CODING_AGENT_DIR' => $agentDir,
            'PI_CODING_AGENT_DIR' => $agentDir,
        ];
        $previous = [];
        $currentEnv = [...getenv(), ...$_ENV];

        foreach (array_keys($currentEnv) as $name) {
            $name = (string) $name;

            if (!str_starts_with($name, 'PIG_EVAL_') && !str_starts_with($name, 'PI_EVAL_')) {
                continue;
            }

            $previous[$name] = self::env($name);
            self::setEnv($name, null);
        }

        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $previous)) {
                $previous[$name] = self::env($name);
            }

            self::setEnv($name, $value);
        }

        return static function () use ($previous): void {
            foreach ($previous as $name => $value) {
                self::setEnv($name, $value);
            }
        };
    }

    /**
     * @return 'without_docs'|'with_docs'
     */
    public static function resolveDocumentationVariant(?string $value = null): string
    {
        if (func_num_args() === 0) {
            $value = self::env('PIG_EVAL_VARIANT') ?? self::env('PI_EVAL_VARIANT');
        }

        if ($value === 'without_docs' || $value === 'with_docs') {
            return $value;
        }

        throw new InvalidArgumentException('PIG_EVAL_VARIANT must be "without_docs" or "with_docs".');
    }

    /**
     * Build the default Pig system prompt with the `<docs>` routing section included before `cwd`.
     *
     * @param list<string> $selectedTools
     * @param array<string, string> $toolSnippets
     * @param list<string> $toolGuidelines
     */
    public static function buildSystemPrompt(
        string $cwd,
        array $selectedTools = self::DOCUMENTATION_EVAL_TOOLS,
        array $toolSnippets = [],
        array $toolGuidelines = [],
    ): string {
        $sections = SystemPrompt::sections(
            $cwd,
            $selectedTools,
            contextFiles: [],
            toolSnippets: $toolSnippets,
            toolGuidelines: $toolGuidelines,
        );

        return Text::getSystemMessageText(new SystemMessage('', self::withDocsSection($sections)));
    }

    /**
     * Insert `<docs>...</docs>` right after `rules` (before `cwd`).
     *
     * @param array<string, string> $sections
     * @return array<string, string>
     */
    public static function withDocsSection(array $sections): array
    {
        $out = [];
        $docsBlock = "<docs>\n" . self::documentationSectionText() . "\n</docs>";
        $inserted = false;

        foreach ($sections as $name => $content) {
            if ($name === 'cwd' && !$inserted) {
                $out['docs'] = $docsBlock;
                $inserted = true;
            }

            $out[$name] = $content;

            if ($name === 'rules' && !$inserted) {
                $out['docs'] = $docsBlock;
                $inserted = true;
            }
        }

        if (!$inserted) {
            $out['docs'] = $docsBlock;
        }

        return $out;
    }

    public static function documentationSectionText(): string
    {
        $readme = Config::readmePath();
        $docs = Config::docsPath();
        $examples = Config::examplesPath();

        return <<< docs
Pig documentation (read only when the user asks about the harness itself, its SDK, extensions, themes, skills, or TUI):
- Main documentation: {$readme}
- Additional docs: {$docs}
- Examples: {$examples} (extensions, custom tools, SDK)
- When reading pig docs or examples, resolve docs/... under Additional docs and examples/... under Examples, not the current working directory
- When asked about: extensions (docs/extensions.md, examples/extensions/), themes (docs/themes.md), skills (docs/skills.md), prompt templates (docs/prompt-templates.md), TUI components (docs/tui.md), keybindings (docs/keybindings.md), SDK integrations (docs/sdk.md), custom providers (docs/custom-provider.md), adding models (docs/models.md), packages (docs/packages.md), environment variables (docs/environment-variables.md), MCP servers (docs/mcp.md), codemode scripts and non-LLM models such as classifiers and image models (docs/codemode.md)
- Before implementing harness changes, read the relevant docs and examples in full, following related cross-references.
docs;
    }

    /**
     * Strip the `<docs>...</docs>` section from a default prompt.
     */
    public static function excludePigDocumentation(string $defaultPrompt): string
    {
        $startMarker = "\n<docs>\n";
        $endMarker = "\n</docs>";
        $start = strpos($defaultPrompt, $startMarker);

        if ($start === false) {
            throw new RuntimeException('Default Pig system prompt has no Pig documentation section.');
        }

        $end = strpos($defaultPrompt, $endMarker, $start);

        if ($end === false) {
            throw new RuntimeException('Default Pig system prompt has no complete Pig documentation section.');
        }

        $cwdPos = strrpos($defaultPrompt, "\nCurrent working directory: ");

        if ($cwdPos === false) {
            $cwdPos = strrpos($defaultPrompt, "\n<cwd>\n");
        }

        if ($cwdPos === false || $cwdPos < $end) {
            throw new RuntimeException('Default Pig system prompt has no working-directory section.');
        }

        $cutStart = $start > 0 && $defaultPrompt[$start - 1] === "\n" ? $start - 1 : $start;

        return substr($defaultPrompt, 0, $cutStart) . substr($defaultPrompt, $end + strlen($endMarker));
    }

    /**
     * @param array{name?: string, expectedPigDocumentation?: bool|null} $options
     */
    public static function verifySystemPrompt(string $systemPrompt, array $options): string
    {
        if (!array_key_exists('expectedPigDocumentation', $options) || $options['expectedPigDocumentation'] === null) {
            return $systemPrompt;
        }

        $name = (string) ($options['name'] ?? 'eval');

        if (!str_contains($systemPrompt, "\nGuidelines:\n") && !str_contains($systemPrompt, "\n<rules>\n")) {
            throw new RuntimeException("Pig system prompt lost its rules in the {$name} eval variant.");
        }

        $hasDocumentation = str_contains($systemPrompt, "\n<docs>\nPig documentation (read only");

        if ($hasDocumentation !== $options['expectedPigDocumentation']) {
            throw new RuntimeException("Pig system prompt does not match the {$name} eval variant.");
        }

        return $systemPrompt;
    }

    /**
     * @param array<string, mixed> $options
     * @return Closure(string|list<array{type: 'prompt', content: string}|array{type: 'reload'}>, ?AbortSignal=): array<string, mixed>
     */
    public static function createPigCodingAgentHarness(array $options = []): Closure
    {
        return static fn (string|array $input, ?AbortSignal $signal = null): array => self::runPigCodingAgent($input, $signal, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Closure(string|list<array{type: 'prompt', content: string}|array{type: 'reload'}>, ?AbortSignal=): array<string, mixed>
     */
    public static function createPigDocumentationEvalHarness(array $options = []): Closure
    {
        $inContainer = self::env('PIG_EVAL_CONTAINER') === '1' || self::env('PI_EVAL_CONTAINER') === '1';

        if (!$inContainer && ($options['allowHost'] ?? false) !== true) {
            throw new RuntimeException('Documentation evals must run in the isolated container sandbox.');
        }

        $variant = self::resolveDocumentationVariant($options['variant'] ?? null);

        return self::createPigCodingAgentHarness([
            ...$options,
            'name' => $variant,
            'tools' => $options['tools'] ?? self::DOCUMENTATION_EVAL_TOOLS,
            ...($variant === 'without_docs'
                ? ['transformSystemPrompt' => static fn (string $prompt): string => self::excludePigDocumentation($prompt)]
                : []),
            'expectedPigDocumentation' => $variant === 'with_docs',
        ]);
    }

    /**
     * @param string|list<array{type: 'prompt', content: string}|array{type: 'reload'}> $input
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function runPigCodingAgent(
        string|array $input,
        ?AbortSignal $signal = null,
        array $options = [],
    ): array {
        $startedAt = hrtime(true);
        $signal?->throwIfAborted();
        $selection = self::resolveModelSelection(is_array($options['model'] ?? null) ? $options['model'] : null);

        // Read credentials from the host before isolating HOME and PIG_CODING_AGENT_DIR.
        $hostAuth = Auth::discover();
        $hostKey = $hostAuth->apiKey($selection['provider']);
        $hostCreds = $hostAuth->credentials($selection['provider']);

        $root = sys_get_temp_dir() . '/pig-eval-' . bin2hex(random_bytes(6));
        $workspace = $root . '/workspace';
        $isolatedHome = $root . '/home';
        $agentDir = $isolatedHome . '/.pig/agent';
        $sessionsDir = $root . '/sessions';

        $restoreEnvironment = self::applyIsolatedEnvironment($isolatedHome, $agentDir);
        $artifacts = [];
        $session = null;
        $store = null;

        try {
            mkdir($workspace, 0o700, true);
            mkdir($agentDir, 0o700, true);
            mkdir($sessionsDir, 0o700, true);

            if (is_array($options['workspaceFiles'] ?? null)) {
                self::seedWorkspace($workspace, $options['workspaceFiles']);
            }

            $auth = Auth::discover($agentDir . '/auth.json');

            if ($hostCreds !== null) {
                $auth->setCredentials($selection['provider'], $hostCreds);
            } elseif ($hostKey !== null && $hostKey !== '') {
                $auth->setRuntimeApiKey($selection['provider'], $hostKey);
            }

            CustomModels::discover()->install($auth);
            $model = Models::find($selection['provider'], $selection['id']);

            if ($model === null) {
                throw new RuntimeException("Eval model not found: {$selection['provider']}/{$selection['id']}");
            }

            if (!$auth->hasKeyFor($selection['provider'])) {
                throw new RuntimeException("Eval model has no configured authentication: {$selection['provider']}/{$selection['id']}");
            }

            $forcedSystemPrompt = null;
            $transformPrompt = $options['transformSystemPrompt'] ?? null;
            $expectDocs = $options['expectedPigDocumentation'] ?? null;

            $buildInlineExtensions = static function () use (&$forcedSystemPrompt, $transformPrompt, $expectDocs): array {
                if (!$transformPrompt instanceof Closure && $expectDocs === null) {
                    return [];
                }

                $api = new ExtensionApi('.', '<inline:eval-system-prompt-transform>', 'eval-system-prompt-transform');
                $api->on('before_agent_start', static function (BeforeAgentStartEvent $event) use (&$forcedSystemPrompt, $transformPrompt, $expectDocs): BeforeAgentStartEventResult {
                    $prompt = $event->systemPrompt;

                    if ($expectDocs !== null && !str_contains($prompt, "\n<docs>\n")) {
                        $sections = $event->systemPromptOptions->sections;
                        $docsBlock = "<docs>\n" . self::documentationSectionText() . "\n</docs>";
                        $cwdMarker = "\n\nCurrent working directory: ";
                        $cwdPos = strrpos($prompt, $cwdMarker);
                        $prompt = $cwdPos !== false
                            ? substr($prompt, 0, $cwdPos) . "\n\n" . $docsBlock . substr($prompt, $cwdPos)
                            : $prompt . "\n\n" . $docsBlock;
                        unset($sections);
                    }

                    if ($transformPrompt instanceof Closure) {
                        $prompt = (string) $transformPrompt($prompt);
                    }

                    $forcedSystemPrompt = $prompt;

                    return new BeforeAgentStartEventResult(systemPrompt: $forcedSystemPrompt);
                });

                return [new LoadedExtension(
                    '<inline:eval-system-prompt-transform>',
                    '<inline:eval-system-prompt-transform>',
                    'eval-system-prompt-transform',
                    $api,
                )];
            };

            $inlineExtensions = $buildInlineExtensions();
            $settings = Settings::load($workspace);
            $store = SessionManager::create($workspace, $sessionsDir);
            $artifacts['runId'] = $store->id;

            $noTools = is_string($options['noTools'] ?? null) ? $options['noTools'] : null;
            $requestedTools = is_array($options['tools'] ?? null) ? array_values(array_map(strval(...), $options['tools'])) : null;
            $toolSelection = $requestedTools !== null ? new ToolSelection($requestedTools) : null;
            $builtIn = match (true) {
                $noTools === 'all' => [],
                $toolSelection !== null => array_values(array_filter(ToolSet::ALL, $toolSelection->allows(...))),
                default => ToolSet::CODING,
            };

            /** @var list<ToolDefinition> $extraCustomTools */
            $extraCustomTools = is_array($options['customTools'] ?? null) ? array_values($options['customTools']) : [];
            $buildCustomToolSet = static function (array $loadedExtensions) use ($extraCustomTools, $noTools, $toolSelection): CustomToolSet {
                $registered = [];

                foreach ($extraCustomTools as $toolDef) {
                    if ($toolDef instanceof ToolDefinition) {
                        $registered[] = new RegisteredTool('<eval:custom-tool>', '<eval:custom-tool>', $toolDef);
                    }
                }

                foreach ($loadedExtensions as $ext) {
                    foreach ($ext->api->tools() as $toolDef) {
                        $registered[] = new RegisteredTool($ext->path, $ext->resolved, $toolDef);
                    }
                }

                $set = new CustomToolSet($registered);

                foreach ($loadedExtensions as $ext) {
                    $set->adopt($ext);
                }

                if ($noTools === 'all') {
                    $set->keep(static fn (): bool => false);
                } elseif ($toolSelection !== null) {
                    $set->keep($toolSelection->allows(...));
                }

                return $set;
            };

            $extensionRunner = new ExtensionRunner($inlineExtensions, $workspace, $store);
            $customTools = $buildCustomToolSet($inlineExtensions);
            $customTools->withContext(static fn () => $extensionRunner->context());

            $agent = CodingAgent::create(
                $model,
                $workspace,
                $builtIn,
                getApiKey: $auth->apiKey(...),
                contextFiles: [],
                thinking: ThinkingLevel::Off,
                skills: [],
                extensionRunner: $extensionRunner,
                customTools: $customTools,
            );
            $loadout = new ToolLoadout($agent, $workspace, $builtIn, $customTools, $extensionRunner, [], []);

            if ($toolSelection !== null) {
                $loadout->useNamed($toolSelection->matches(...));
            }

            $customTools->onChange(static fn () => $loadout->refresh());
            $loadout->apply();
            $agent->replaceMessages([]);

            $session = new AgentSession(
                $agent,
                $workspace,
                $store,
                $settings,
                $extensionRunner,
                auth: $auth,
                loadout: $loadout,
                usesDefaultTools: false,
            );
            $extensionRunner->wire($session);

            /** @var list<LoadedExtension> $currentExtensions */
            $currentExtensions = $inlineExtensions;
            /** @var list<ExtensionError> $currentExtensionErrors */
            $currentExtensionErrors = [];

            $reloadSession = static function () use (
                $workspace,
                $auth,
                $store,
                $session,
                $loadout,
                $buildInlineExtensions,
                $buildCustomToolSet,
                &$extensionRunner,
                &$currentExtensions,
                &$currentExtensionErrors,
            ): void {
                $extensionRunner->emit(new SessionShutdownEvent('reload'));
                ProviderRegistry::forget();
                ExtensionApi::forgetHttpRoutes();
                ExtensionApi::forgetWebPanels();
                ExtensionApi::forgetFlags();
                McpServerRegistry::reset();
                VirtualModelRegistry::reset();

                $reloadedSettings = Settings::load($workspace);
                [$diskExtensions, $problems] = ExtensionLoader::load(
                    $workspace,
                    $reloadedSettings->extensions(),
                    auth: $auth,
                    projectTrusted: true,
                    disabled: ['pig-mcp', 'pig-llama', 'pig-codemode', 'pig-tool-search', 'pig-schedule'],
                );
                CustomModels::discover()->install($auth);
                ModelRefresh::refresh($auth, allowNetwork: false);

                $currentExtensions = [...$buildInlineExtensions(), ...$diskExtensions];
                $currentExtensionErrors = $problems;
                $extensionRunner = new ExtensionRunner($currentExtensions, $workspace, $store);
                $session->setExtensionRunner($extensionRunner);
                $extensionRunner->wire($session);

                $newCustomTools = $buildCustomToolSet($currentExtensions);
                $newCustomTools->withContext(static fn () => $extensionRunner->context());
                $loadout->reloaded($newCustomTools, [], []);
                $newCustomTools->onChange(static fn () => $loadout->refresh());
                $extensionRunner->emit(new SessionStartEvent('reload'));
            };

            $steps = is_string($input) ? [['type' => 'prompt', 'content' => $input]] : $input;
            $response = null;

            foreach ($steps as $step) {
                if (($step['type'] ?? null) === 'reload') {
                    $reloadSession();

                    continue;
                }

                $response = self::promptAgent($session, (string) ($step['content'] ?? ''), $signal);
            }

            if ($response === null) {
                throw new RuntimeException('Pig eval input must include at least one prompt step.');
            }

            $systemPrompt = $forcedSystemPrompt ?? Transcript::getCurrentSystemPrompt($session->messages());
            self::verifySystemPrompt($systemPrompt, $options);

            $stats = $session->stats();
            $events = self::toTranscriptEvents($session->messages());
            $hasPricing = $model->pricing->input > 0
                || $model->pricing->output > 0
                || $model->pricing->cacheRead > 0
                || $model->pricing->cacheWrite > 0
                || $model->pricing->tiers !== [];

            $usage = [
                'provider' => $model->provider,
                'model' => $model->id,
                'inputTokens' => $stats->input,
                'outputTokens' => $stats->output,
                'totalTokens' => $stats->totalTokens,
                'toolCalls' => $stats->toolCalls,
                'metadata' => [
                    'cacheReadTokens' => $stats->cacheRead,
                    'cacheWriteTokens' => $stats->cacheWrite,
                    ...($hasPricing ? ['estimatedCostUsd' => $stats->cost] : []),
                ],
            ];

            $output = isset($options['output']) && $options['output'] instanceof Closure
                ? ($options['output'])([
                    'response' => $response,
                    'session' => $session,
                    'systemPrompt' => $systemPrompt,
                    'agentDir' => $agentDir,
                    'extensions' => $currentExtensions,
                    'extensionErrors' => $currentExtensionErrors,
                ])
                : $response;

            if ($store->path !== null && is_file($store->path)) {
                $artifacts[Report::PIG_SESSION_SNAPSHOT_ARTIFACT] = (string) file_get_contents($store->path);
            }

            return [
                'output' => $output,
                'events' => $events,
                'metadata' => ['systemPromptSha256' => hash('sha256', $systemPrompt)],
                'usage' => $usage,
                'artifacts' => $artifacts,
                'errors' => [],
                'timings' => ['totalMs' => (int) round((hrtime(true) - $startedAt) / 1e6)],
            ];
        } finally {
            try {
                $session?->dispose();
            } catch (Throwable) {
            }

            self::removeTree($root);
            $restoreEnvironment();
        }
    }

    private static function promptAgent(AgentSession $session, string $input, ?AbortSignal $signal): string
    {
        $signal?->throwIfAborted();
        $previousCount = count($session->messages());
        $session->prompt($input);
        $newMessages = array_slice($session->messages(), $previousCount);
        $assistant = null;

        foreach (array_reverse($newMessages) as $message) {
            if ($message instanceof AssistantMessage) {
                $assistant = $message;

                break;
            }
        }

        if ($assistant === null) {
            throw new RuntimeException('Agent run completed without an assistant message.');
        }

        if ($assistant->stopReason !== StopReason::Stop && $assistant->stopReason !== StopReason::ToolUse) {
            throw new RuntimeException($assistant->errorMessage ?? "Agent run ended with unexpected stop reason: {$assistant->stopReason->value}.");
        }

        $output = $session->lastAssistantText() ?? '';

        if ($output === '' && $assistant->stopReason === StopReason::Stop) {
            throw new RuntimeException('Agent run produced no assistant text.');
        }

        return $output;
    }

    /**
     * @param list<mixed> $messages
     * @return list<array<string, mixed>>
     */
    public static function toTranscriptEvents(array $messages): array
    {
        $events = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $events[] = [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => Text::contentText($message->content),
                ];

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $text = Text::contentText($message->content);

                if ($text !== '') {
                    $events[] = [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => $text,
                    ];
                }

                foreach ($message->content as $part) {
                    if ($part instanceof ToolCall) {
                        $events[] = [
                            'type' => 'tool_call',
                            'id' => $part->id,
                            'name' => $part->name,
                            'arguments' => $part->arguments,
                        ];
                    }
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $text = Text::contentText($message->content);
                $allText = true;

                foreach ($message->content as $part) {
                    if (!$part instanceof TextContent) {
                        $allText = false;

                        break;
                    }
                }

                $events[] = [
                    'type' => 'tool_result',
                    'toolCallId' => $message->toolCallId,
                    'name' => $message->toolName,
                    'content' => $allText ? $text : $message->content,
                    ...($message->isError ? ['error' => ['message' => $text !== '' ? $text : 'Tool failed']] : []),
                ];
            }
        }

        return $events;
    }

    /**
     * @param array<string, string> $files
     */
    private static function seedWorkspace(string $workspace, array $files): void
    {
        foreach ($files as $name => $content) {
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..')) {
                throw new InvalidArgumentException("Invalid workspace fixture path: {$name}");
            }

            $path = $workspace . '/' . $name;
            $dir = dirname($path);

            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }

            file_put_contents($path, $content);
            chmod($path, 0o600);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }

    private static function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
