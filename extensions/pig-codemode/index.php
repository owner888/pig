<?php

declare(strict_types=1);

use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\AssistantImages;
use Pig\Ai\ClassifierBoolQuestion;
use Pig\Ai\ClassifierChoiceQuestion;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\ClassifierScoreQuestion;
use Pig\Ai\ImageContent;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\MessageJson;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
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
use Pig\CodingAgent\Tools\Truncate;
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
    // Every type the sandbox's `image()` detects, or a saved image has no extension to be given.
    $imageExtensions = ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif', 'image/webp' => '.webp'];
    $active = false;
    $settings = null;
    // `models.classify()` and `models.generateImages()` calls one script may have in flight; a
    // `parallel()` over more queues the rest — upstream's `MAX_CONCURRENT_MODEL_CALLS`.
    $maxConcurrentModelCalls = 4;

    /**
     * The active tools as the loadout last offered them, codemode left out — upstream's
     * `loadout.callable`, captured by `prepareLoadout`. In `only` mode they are not on the agent
     * at all, so this is the only way a script still reaches them.
     *
     * @var list<AgentTool>|null
     */
    $callable = null;

    /** `codemode.mode`: `on` (the default) or `only` — upstream's `CodemodeMode`. */
    $mode = static function () use (&$settings): string {
        return $settings?->get('codemode.mode') === 'only' ? 'only' : 'on';
    };

    /** @var array<string, array{name: string, description: string, parameters: array, outputSchema?: mixed, execute: Closure, namespace: ?array, exposure: string}> what scripts can call that the model cannot see */
    $nested = static fn (): array => Registry::all();

    /** The agent's own tools (the hooked ones), which scripts can call too, minus codemode itself. */
    $agentTools = static function (HookContext $ctx) use (&$callable): array {
        $out = [];

        foreach ($callable ?? $ctx->session?->agent->tools() ?? [] as $tool) {
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
     * The description lists what the model cannot otherwise see — upstream's `on` mode: the
     * nested tools, which have no `direct` exposure; the agent's own tools are callable but not
     * listed, since the model has their schemas already. In `only` mode every callable tool is
     * listed, because their declarations are hidden from the request.
     *
     * @param list<AgentTool> $callableTools
     */
    $describe = static function (string $mode, array $callableTools) use ($nested, $namespaces, $deferredNames, &$settings): string {
        $listed = [];

        if ($mode === 'only') {
            foreach ($callableTools as $tool) {
                $definition = $tool->definition();
                $listed[$definition->name] = ['name' => $definition->name, 'description' => $definition->description, 'inputSchema' => $definition->parameters];
            }
        }

        foreach ($nested() as $name => $tool) {
            $listed[$name] ??= ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $tool['parameters'], ...(isset($tool['outputSchema']) ? ['outputSchema' => $tool['outputSchema']] : [])];
        }

        $budget = $settings?->get('codemode.inlineBudget');

        return CodemodeDescription::build(array_values($listed), $namespaces(), $deferredNames(), is_int($budget) && $budget >= 0 ? $budget : CodemodeDescription::DEFAULT_INLINE_BUDGET);
    };

    /**
     * A declared tool's description followed by how a script calls it — upstream's
     * `describeScriptCall()`, the `on` mode's half: the model is told, on the tool it already
     * knows, that a script can call it too. The arguments are the tool's own parameters, so they
     * are not repeated; pig's tools have no output schema, so a call resolves to a string.
     */
    $describeScriptCall = static fn (AgentTool $tool): string => trim($tool->definition()->description)
        . "\n\nCodemode: `\$tools->" . Identifier::of($tool->definition()->name) . '([...])` resolves to a string.';

    /**
     * Whether `$query` names the namespace: its name, its identifier (`mcp__dev-radius` is
     * `mcp__dev_radius`), or the part after its last `__` in either form — upstream's
     * `isNamespaceName()`.
     */
    $isNamespaceName = static function (string $namespace, string $query): bool {
        $suffix = static fn (string $name): ?string => str_contains($name, '__') ? substr($name, strrpos($name, '__') + 2) : null;
        $id = Identifier::of($namespace);
        $queryId = Identifier::of($query);

        return $namespace === $query || $id === $queryId || $suffix($namespace) === $query || $suffix($id) === $queryId;
    };

    // ---- running a script -----------------------------------------------------------------------

    $truncate = static fn (string $text, int $max): string => strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;

    $execute = static function (string $toolCallId, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use (
        $agentTools,
        $nested,
        $declarations,
        $namespaces,
        $isNamespaceName,
        $storeEntryType,
        $defaultMaxOutputTokens,
        $charsPerToken,
        $argsPreviewChars,
        $errorPreviewChars,
        $imageExtensions,
        $maxConcurrentModelCalls,
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

        // A nested call's own id is `<codemode call id>/<n>`, upstream's `NestedToolCallRunner`
        // shape; the session counts them when there is one, so the id the hooks and the events
        // see is the session's.
        $nestedCount = 0;
        $nestedId = static function () use ($ctx, $toolCallId, &$nestedCount): string {
            $session = $ctx->session;

            return $session === null ? "{$toolCallId}/" . (++$nestedCount) : $session->nestedToolCallId($toolCallId);
        };
        $record = static function (string $name, array $args) use (&$calls, $publish, $truncate, $argsPreviewChars, $nestedId): int {
            $calls[] = ['id' => $nestedId(), 'name' => $name, 'args' => $truncate((string) json_encode($args, JSON_UNESCAPED_SLASHES), $argsPreviewChars), 'status' => 'running'];
            $publish();

            return count($calls) - 1;
        };

        /** The usage of this script's `$models->*` calls and of the tools it called, for the result. */
        $modelUsage = null;
        // Upstream's `combineUsage()`: the counts and the money added up.
        $combineUsage = static fn (Usage $a, Usage $b): Usage => new Usage(
            $a->input + $b->input,
            $a->output + $b->output,
            $a->cacheRead + $b->cacheRead,
            $a->cacheWrite + $b->cacheWrite,
            $a->totalTokens + $b->totalTokens,
            new \Pig\Ai\Cost(
                $a->cost->input + $b->cost->input,
                $a->cost->output + $b->cost->output,
                $a->cost->cacheRead + $b->cost->cacheRead,
                $a->cost->cacheWrite + $b->cost->cacheWrite,
                $a->cost->total + $b->cost->total,
            ),
            $a->reasoning === null && $b->reasoning === null ? null : ($a->reasoning ?? 0) + ($b->reasoning ?? 0),
            $a->cacheWrite1h === null && $b->cacheWrite1h === null ? null : ($a->cacheWrite1h ?? 0) + ($b->cacheWrite1h ?? 0),
        );
        $sandboxTools = [];
        $samples = [];

        foreach ($declarations($ctx) as $declaration) {
            $samples[$declaration['name']] = Declarations::sample($declaration);
        }

        // The agent's tools: through the session's nested-call pipeline — the arguments checked
        // against the schema, the hooked tool so a `tool_call` guard sees the call, and
        // `tool_execution_*` events with `parentToolCallId` for the hooks, the RPC port and the
        // TUI. Without a session (a bare `execute` in a test) the hooked tool is called as it is.
        foreach ($agentTools($ctx) as $name => $one) {
            $sandboxTools[] = ['name' => $name, 'description' => $samples[$name], 'execute' => static function (array $args, AbortSignal $callSignal) use ($name, $one, $ctx, $toolCallId, &$calls, $record, $publish, $scriptValue, $truncate, $errorPreviewChars, $textOf, &$modelUsage, $combineUsage): mixed {
                $index = $record($name, $args);
                $at = microtime(true);
                $isError = false;
                $session = $ctx->session;

                if ($session !== null) {
                    [$result, $isError] = $session->executeNestedTool($one['tool'], $calls[$index]['id'], $toolCallId, $args, $callSignal);
                } else {
                    try {
                        $result = $one['tool']->execute($calls[$index]['id'], $args, $callSignal, null);
                    } catch (\Throwable $error) {
                        $isError = true;
                        $result = new AgentToolResult([new TextContent($error->getMessage())]);
                    }
                }

                // What the nested tool spent on models is this call's to bill, as upstream's
                // recorder sums it onto the parent's result.
                if ($result->usage !== null) {
                    $modelUsage = $modelUsage === null ? $result->usage : $combineUsage($modelUsage, $result->usage);
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

        // ---- `$models`: upstream's `createModelGlobals()` -------------------------------------

        $modelTypes = ['chat', 'image', 'classifier'];
        $toModelType = static function (mixed $value) use ($modelTypes): ModelType {
            if (is_string($value) && in_array($value, $modelTypes, true)) {
                return ModelType::from($value);
            }

            throw new RuntimeException('Unknown model type ' . json_encode($value) . '. Use "chat", "image", or "classifier".');
        };
        $toProvider = static function (mixed $value): ?string {
            if ($value === null) {
                return null;
            }

            if (!is_string($value)) {
                throw new RuntimeException('provider must be a string');
            }

            return $value;
        };
        // A script value in an error message: `null`, `a string`, `an array`, or its keys (`{ prompt }`).
        $describeValue = static function (mixed $value): string {
            if ($value === null) {
                return 'null';
            }

            if (is_array($value)) {
                if ($value === []) {
                    return 'an empty array';
                }

                if (array_is_list($value)) {
                    return 'an array';
                }

                $keys = array_keys($value);

                return '{ ' . implode(', ', array_slice($keys, 0, 6)) . (count($keys) > 6 ? ', ...' : '') . ' }';
            }

            return is_string($value) ? 'a string' : 'a ' . get_debug_type($value);
        };
        $withArticle = static fn (string $word): string => (preg_match('/^[aeiou]/', $word) === 1 ? 'an ' : 'a ') . $word;
        $isRecord = static fn (mixed $value): bool => is_array($value) && !array_is_list($value);
        // Catalogue entry for scripts. `headers` is dropped because models.json headers can carry
        // credentials; `pricing` goes out under upstream's name, `cost`.
        $modelInfo = static function (Model|ImageModel|ClassifierModel $model): array {
            $info = json_decode((string) json_encode($model, JSON_THROW_ON_ERROR), true);
            unset($info['headers']);

            if (array_key_exists('pricing', $info)) {
                $info['cost'] = $info['pricing'];
                unset($info['pricing']);
            }

            return $info;
        };
        $auth = $ctx->session?->auth ?? $pi->auth();
        $modelsOfType = static fn (ModelType $type, ?string $provider): array => array_values(array_filter(
            Models::allOfType($type),
            static fn (Model|ImageModel|ClassifierModel $m): bool => $provider === null || $m->provider === $provider,
        ));

        $classifierShape = '{ state: { ... }, questions: { <id>: { type: "choice", instructions, criteria: { <label>: <meaning> } } | { type: "score", instructions, criteria: [<lowest level>, ..., <highest level>] } | { type: "bool", instructions, criteria: { true: <meaning>, false: <meaning> } } }, images?: [{ type: "image", data: <base64>, mimeType }] }';
        // A script's classifier context, checked so a mistake fails with the expected shape
        // rather than a provider error — upstream's `checkClassifierContext()`. `images` is
        // the optional list of image blocks (`read` on a PNG answers them in that shape) a
        // model with image input looks at alongside the state; `Models::classify()` refuses
        // them for a model that cannot see.
        $checkClassifierContext = static function (mixed $context) use ($isRecord, $describeValue, $classifierShape): ClassifierContext {
            $fail = static fn (string $problem): RuntimeException => new RuntimeException("\$models->classify() {$problem}. Expected context: {$classifierShape}. See \"Classify\" in " . CodemodeDescription::DOCS_PATH . '.');

            if (!$isRecord($context)) {
                throw $fail('expects a context array as its second argument, got ' . $describeValue($context));
            }

            if (!is_array($context['state'] ?? null) || ($context['state'] !== [] && array_is_list($context['state']))) {
                throw $fail('context.state must be an array with keys, got ' . $describeValue($context['state'] ?? null));
            }

            $images = [];

            if (array_key_exists('images', $context)) {
                if (!is_array($context['images']) || !array_is_list($context['images'])) {
                    throw $fail('context.images must be a list of image blocks, got ' . $describeValue($context['images']));
                }

                foreach ($context['images'] as $index => $block) {
                    if (!$isRecord($block) || ($block['type'] ?? null) !== 'image' || !is_string($block['data'] ?? null) || !is_string($block['mimeType'] ?? null)) {
                        throw $fail("context.images[{$index}] must be an image block with base64 data and a mimeType, got " . $describeValue($block));
                    }

                    $images[] = new ImageContent($block['data'], $block['mimeType']);
                }
            }

            $questions = $context['questions'] ?? null;

            if (!$isRecord($questions)) {
                throw $fail('context.questions must map question IDs to questions, got ' . $describeValue($questions));
            }

            $isStrings = static fn (array $values): bool => $values !== [] && array_filter($values, static fn ($v): bool => !is_string($v)) === [];
            $built = [];

            foreach ($questions as $id => $question) {
                $at = "context.questions.{$id}";

                if (!$isRecord($question)) {
                    throw $fail("{$at} must be a question array, got " . $describeValue($question));
                }

                if (!is_string($question['instructions'] ?? null)) {
                    throw $fail("{$at}.instructions must be a string");
                }

                $criteria = $question['criteria'] ?? null;
                $type = $question['type'] ?? null;

                if ($type === 'choice') {
                    if (!$isRecord($criteria) || !$isStrings(array_values($criteria))) {
                        throw $fail("{$at} is a \"choice\" question, so criteria must map each label to its meaning");
                    }

                    $built[(string) $id] = new ClassifierChoiceQuestion($question['instructions'], $criteria);
                } elseif ($type === 'score') {
                    if (!is_array($criteria) || !array_is_list($criteria) || !$isStrings($criteria)) {
                        throw $fail("{$at} is a \"score\" question, so criteria must list the levels as strings, lowest first");
                    }

                    $built[(string) $id] = new ClassifierScoreQuestion($question['instructions'], $criteria);
                } elseif ($type === 'bool') {
                    if (!$isRecord($criteria) || !is_string($criteria['true'] ?? null) || !is_string($criteria['false'] ?? null)) {
                        throw $fail("{$at} is a \"bool\" question, so criteria must be ['true' => string, 'false' => string]");
                    }

                    $built[(string) $id] = new ClassifierBoolQuestion($question['instructions'], ['true' => $criteria['true'], 'false' => $criteria['false']]);
                } else {
                    throw $fail("{$at}.type must be \"choice\", \"score\", or \"bool\", got " . json_encode($type));
                }
            }

            return new ClassifierContext($context['state'], $built, $images);
        };
        // Upstream's `checkImagesContext()`.
        $checkImagesContext = static function (mixed $context) use ($isRecord, $describeValue): ImagesContext {
            $fail = static fn (string $problem): RuntimeException => new RuntimeException("\$models->generateImages() {$problem}. Expected context: ['input' => [['type' => 'text', 'text' => <prompt>], ...optional ['type' => 'image', 'data' => <base64>, 'mimeType' => ...] references]]. See \"Generate images\" in " . CodemodeDescription::DOCS_PATH . '.');

            if (!$isRecord($context)) {
                throw $fail('expects a context array as its second argument, got ' . $describeValue($context));
            }

            $input = $context['input'] ?? null;

            if (!is_array($input) || $input === [] || !array_is_list($input)) {
                throw $fail('context.input must be a non-empty list of blocks, got ' . $describeValue($input));
            }

            $blocks = [];

            foreach ($input as $index => $block) {
                if ($isRecord($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $blocks[] = new TextContent($block['text']);
                } elseif ($isRecord($block) && ($block['type'] ?? null) === 'image' && is_string($block['data'] ?? null) && is_string($block['mimeType'] ?? null)) {
                    $blocks[] = new ImageContent($block['data'], $block['mimeType']);
                } else {
                    throw $fail("context.input[{$index}] must be a text or image block, got " . $describeValue($block));
                }
            }

            return new ImagesContext($blocks);
        };

        // At most four model calls at once, in call order — upstream's `createLimiter()`.
        $inFlight = 0;
        /** @var list<Deferred> */
        $waiting = [];
        $limit = static function (Closure $run) use (&$inFlight, &$waiting, $maxConcurrentModelCalls): mixed {
            if ($inFlight >= $maxConcurrentModelCalls) {
                $turn = new Deferred();
                $waiting[] = $turn;
                $turn->future->await();
            }

            $inFlight++;

            try {
                return $run();
            } finally {
                $inFlight--;

                if ($waiting !== []) {
                    array_shift($waiting)->complete(null);
                }
            }
        };

        /** Images `$models->generateImages()` returned, to notice a script that never shows them. */
        $generatedImages = 0;
        $modelCallCount = 0;

        /**
         * Resolve the script's model by provider and id only — a script-supplied baseUrl or
         * headers must never receive the credentials — check the context, then run the call as
         * a nested call row that shows only the model, never a prompt or image data.
         */
        $runModelCall = function (string $name, ModelType $type, array $args, Closure $checkContext, Closure $run) use (
            $isRecord, $describeValue, $withArticle, $modelTypes, $toolCallId, &$calls, $publish, $truncate, $errorPreviewChars, $limit, &$modelUsage, $combineUsage, &$modelCallCount
        ): array {
            [$model, $context] = [$args[0] ?? null, $args[1] ?? null];
            $listHint = "List the {$type->value} models you can use with \$models->getAvailableOfType(\"{$type->value}\").";

            if (!$isRecord($model) || !is_string($model['provider'] ?? null) || !is_string($model['id'] ?? null)) {
                $nullHint = $model === null ? ' $models->getModelOfType() returns null for an unknown provider or id.' : '';

                throw new RuntimeException("{$name}() expects " . $withArticle($type->value) . ' model as its first argument, got ' . $describeValue($model) . ".{$nullHint} {$listHint}");
            }

            $ref = "{$model['provider']}/{$model['id']}";
            $resolved = Models::findOfType($type, $model['provider'], $model['id']);

            if ($resolved === null) {
                $actual = null;

                foreach ($modelTypes as $other) {
                    if ($other !== $type->value && Models::findOfType(ModelType::from($other), $model['provider'], $model['id']) !== null) {
                        $actual = $other;
                        break;
                    }
                }

                throw new RuntimeException($actual !== null
                    ? "\"{$ref}\" is " . $withArticle($actual) . ' model, not ' . $withArticle($type->value) . " model. {$listHint}"
                    : "Unknown {$type->value} model \"{$ref}\". {$listHint}");
            }

            $checked = $checkContext($context);
            $calls[] = ['id' => "{$toolCallId}/{$name}/" . (++$modelCallCount), 'name' => $name, 'args' => "{$resolved->provider}/{$resolved->id}", 'status' => 'running'];
            $index = count($calls) - 1;
            $publish();
            $at = microtime(true);
            /** @var ClassifierResult|AssistantImages $result */
            $result = $limit(static fn () => $run($resolved, $checked));
            $calls[$index]['durationMs'] = (microtime(true) - $at) * 1000;
            $calls[$index]['status'] = match ($result->stopReason) {
                StopReason::Stop => 'ok',
                StopReason::Aborted => 'cancelled',
                default => 'error',
            };

            if ($result->errorMessage !== null) {
                $calls[$index]['error'] = $truncate($result->errorMessage, $errorPreviewChars);
            }

            if ($result->usage !== null) {
                $calls[$index]['cost'] = $result->usage->cost->total;
                $modelUsage = $modelUsage === null ? $result->usage : $combineUsage($modelUsage, $result->usage);
            }

            $publish();

            $out = [
                'api' => $result->api->value,
                'provider' => $result->provider,
                'model' => $result->model,
                'stopReason' => $result->stopReason->value,
                'timestamp' => $result->timestamp,
                ...($result->usage !== null ? ['usage' => MessageJson::encodeUsage($result->usage)] : []),
                ...($result->errorMessage !== null ? ['errorMessage' => $result->errorMessage] : []),
            ];

            if ($result instanceof ClassifierResult) {
                $out['answers'] = json_decode((string) json_encode($result->answers, JSON_THROW_ON_ERROR), true);
            } else {
                $out['output'] = array_map(static fn (TextContent|ImageContent $block): array => $block instanceof ImageContent
                    ? ['type' => 'image', 'data' => $block->data, 'mimeType' => $block->mimeType]
                    : ['type' => 'text', 'text' => $block->text], $result->output);

                if ($result->responseId !== null) {
                    $out['responseId'] = $result->responseId;
                }
            }

            return $out;
        };

        $globals = [
            'search_tools' => static function (array $args) use ($allNames, $samples, $namespaces, $entry, $declarations, $ctx, $isNamespaceName): array {
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

                    if ($namespace !== null && ($space === null || !$isNamespaceName($space['name'], $namespace))) {
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
            // Upstream's `describeNamespace()`: the namespace, its description and instructions,
            // and the identifiers of its tools.
            'describe_namespace' => static function (array $args) use ($allNames, $namespaces, $isNamespaceName): ?array {
                $name = $args[0] ?? null;

                if (!is_string($name)) {
                    throw new RuntimeException('describe_namespace() expects a namespace name');
                }

                $spaces = $namespaces();
                $found = null;
                $names = [];

                foreach ($allNames as $tool) {
                    $space = $spaces[$tool] ?? null;

                    if ($space === null || !$isNamespaceName($space['name'], $name)) {
                        continue;
                    }

                    $found ??= $space;
                    $names[] = Identifier::of($tool);
                }

                if ($found === null) {
                    return null;
                }

                return [
                    'name' => $found['name'],
                    ...(isset($found['description']) && $found['description'] !== null && $found['description'] !== '' ? ['description' => $found['description']] : []),
                    ...(isset($found['instructions']) && $found['instructions'] !== null && $found['instructions'] !== '' ? ['instructions' => $found['instructions']] : []),
                    'tools' => $names,
                ];
            },
            'models.getModelsOfType' => static fn (array $args): array => array_map($modelInfo, $modelsOfType($toModelType($args[0] ?? null), $toProvider($args[1] ?? null))),
            'models.getAvailableOfType' => static fn (array $args): array => array_map($modelInfo, array_values(array_filter(
                $modelsOfType($toModelType($args[0] ?? null), $toProvider($args[1] ?? null)),
                static fn (Model|ImageModel|ClassifierModel $m): bool => $auth?->hasKeyFor($m->provider) ?? false,
            ))),
            'models.getModelOfType' => static function (array $args) use ($toModelType, $modelInfo, $describeValue): ?array {
                [$type, $provider, $id] = [$args[0] ?? null, $args[1] ?? null, $args[2] ?? null];

                if (!is_string($provider) || !is_string($id)) {
                    throw new RuntimeException('$models->getModelOfType($type, $provider, $id) expects three strings, got (' . implode(', ', array_map($describeValue, $args)) . '). The provider and the id are separate arguments, for example $models->getModelOfType("classifier", "typesafe", "jev-latest").');
                }

                $model = Models::findOfType($toModelType($type), $provider, $id);

                return $model === null ? null : $modelInfo($model);
            },
            'models.classify' => static fn (array $args, AbortSignal $callSignal): array => $runModelCall(
                '$models->classify',
                ModelType::Classifier,
                $args,
                $checkClassifierContext,
                static fn (ClassifierModel $model, ClassifierContext $context): ClassifierResult => Models::classify($model, $context, new ClassifierOptions(signal: $callSignal, apiKey: $auth?->apiKey($model->provider))),
            ),
            'models.generateImages' => static fn (array $args, AbortSignal $callSignal): array => $runModelCall(
                '$models->generateImages',
                ModelType::Image,
                $args,
                $checkImagesContext,
                static function (ImageModel $model, ImagesContext $context) use (&$generatedImages, $callSignal, $auth): AssistantImages {
                    $result = Models::generateImages($model, $context, new ImagesOptions(signal: $callSignal, apiKey: $auth?->apiKey($model->provider)));
                    $generatedImages += count(array_filter($result->output, static fn ($block): bool => $block instanceof ImageContent));

                    return $result;
                },
            ),
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

        $scriptOutput = $result['output'];

        if ($result['ok']) {
            $writes = $result['storeWrites'];

            if ($writes['set'] !== [] || $writes['delete'] !== []) {
                $pi->appendEntry($storeEntryType, ['set' => $writes['set'], 'delete' => $writes['delete']]);
            }

            // A returned value is appended like `text()`.
            if (($result['value'] ?? null) !== null) {
                $value = $result['value'];
                $scriptOutput[] = ['type' => 'text', 'text' => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            }
        }

        // Upstream's `formatOutput()`: lay the output out so the model can tell items apart,
        // since providers join adjacent text blocks with a newline or with nothing. With more than
        // one text item (`text()` or the returned value) each starts with a `==> text N/M <==`
        // line, and what `echo` wrote follows everything else in one `<console_output>` block.
        $total = count(array_filter($scriptOutput, static fn (array $i): bool => $i['type'] === 'text' && !($i['console'] ?? false)));
        $items = [];
        $consoleLines = [];
        $index = 0;

        foreach ($scriptOutput as $item) {
            if ($item['type'] === 'image') {
                $items[] = $item;
            } elseif ($item['console'] ?? false) {
                $consoleLines[] = $item['text'];
            } else {
                $index++;
                $items[] = ['type' => 'text', 'text' => $total > 1 ? "==> text {$index}/{$total} <==\n{$item['text']}" : $item['text']];
            }
        }

        if ($consoleLines !== []) {
            $items[] = ['type' => 'text', 'text' => "<console_output>\n" . implode("\n", $consoleLines) . "\n</console_output>"];
        }

        if (!$result['ok']) {
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

        if ($generatedImages > 0 && array_filter($items, static fn (array $i): bool => $i['type'] === 'image') === []) {
            $items[] = ['type' => 'text', 'text' => "Note: \$models->generateImages() returned {$generatedImages} image" . ($generatedImages === 1 ? '' : 's') . " that the script did not show. Show each image block of \$result['output'] with image(\$block)."];
        }

        // Upstream's `joinAdjacentText()`: adjacent text items become one, each part on its own line.
        $joinAdjacentText = static function (array $items): array {
            $joined = [];

            foreach ($items as $item) {
                $last = $joined === [] ? null : $joined[count($joined) - 1];

                if ($item['type'] === 'text' && $last !== null && $last['type'] === 'text') {
                    $separator = $last['text'] === '' || str_ends_with($last['text'], "\n") ? '' : "\n";
                    $joined[count($joined) - 1] = ['type' => 'text', 'text' => $last['text'] . $separator . $item['text']];
                } else {
                    $joined[] = $item;
                }
            }

            return $joined;
        };
        $items = $joinAdjacentText($items);

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

        // Every image the script showed is saved to a file, and a line naming the file goes in
        // front of it — upstream's `saveImages()`. The model sees the picture and has no other way
        // to reach its bytes: a script cannot write a file, and `write` takes text. After the
        // truncation, so a path is never cut. An image shown twice is saved once.
        $saved = [];
        $withPaths = [];

        foreach ($items as $item) {
            if ($item['type'] === 'image') {
                $bytes = base64_decode($item['data'], true);
                $kind = $item['mimeType'] . ', ' . Truncate::size($bytes === false ? 0 : strlen($bytes));
                $extension = $imageExtensions[$item['mimeType']] ?? null;

                if ($extension === null) {
                    throw new AgentError("No file extension for image type {$item['mimeType']}");
                }

                // A failed write (disk full, an unwritable temp dir) must not discard the result
                // of a script whose tool calls already ran, so it becomes the label.
                $saved[$item['data']] ??= (static function () use ($bytes, $extension, $kind): string {
                    $path = sys_get_temp_dir() . '/pig-codemode-' . bin2hex(random_bytes(8)) . $extension;
                    // `file_put_contents` warns as well as answering false, and the warning is the
                    // only place the reason appears.
                    $reason = null;
                    set_error_handler(static function (int $no, string $message) use (&$reason): bool {
                        $reason = $message;

                        return true;
                    });

                    try {
                        $written = $bytes !== false && file_put_contents($path, $bytes) !== false;
                    } finally {
                        restore_error_handler();
                    }

                    if (!$written) {
                        return "[Image ({$kind}) could not be saved: " . ($reason ?? "could not write {$path}") . ']';
                    }

                    chmod($path, 0600);

                    return "[Image saved to {$path} ({$kind})]";
                })();
                $withPaths[] = ['type' => 'text', 'text' => $saved[$item['data']]];
            }

            $withPaths[] = $item;
        }

        $items = $joinAdjacentText($withPaths);

        $content = [new TextContent(($result['ok'] ? 'Script completed' : 'Script failed') . "\nWall time " . number_format(microtime(true) - $startedAt, 1) . " seconds\nOutput:\n")];

        foreach ($items as $item) {
            $content[] = $item['type'] === 'image' ? new ImageContent($item['data'], $item['mimeType']) : new TextContent($item['text']);
        }

        $details = ['calls' => $calls];

        if ($fullOutputPath !== null) {
            $details['fullOutputPath'] = $fullOutputPath;
        }

        // The `models.*` usage, and what nested tools spent, goes on the result itself, where the
        // session bills it — upstream's `usage` on the result; the call rows carry each call's
        // cost for the renderer, and `details.usage` is the same sum for the RPC port.
        if ($modelUsage !== null) {
            $details['usage'] = MessageJson::encodeUsage($modelUsage);
        }

        if (!$result['ok']) {
            throw new AgentError(implode("\n", array_map(static fn ($b) => $b instanceof TextContent ? $b->text : '[image]', $content)), $details, usage: $modelUsage);
        }

        return new AgentToolResult($content, $details, $modelUsage);
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

    /**
     * Upstream's `prepareCodemodeLoadout()`, asked by the loadout with every active tool: in `on`
     * mode the declared tools' descriptions say how a script calls them and codemode lists the
     * nested tools; in `only` mode codemode lists every callable tool and their own declarations
     * are hidden, so the model reaches them through scripts alone. Either way the callable list is
     * kept here, which is the only way a hidden tool is still reachable.
     *
     * @param list<AgentTool> $tools
     */
    $prepareLoadout = static function (array $tools) use (&$callable, $mode, $describe, $describeScriptCall): array {
        $callable = array_values(array_filter($tools, static fn (AgentTool $t): bool => $t->definition()->name !== CodemodeDescription::TOOL_NAME));
        $descriptions = [];
        $hidden = [];

        foreach ($callable as $tool) {
            if ($mode() === 'on') {
                $descriptions[$tool->definition()->name] = $describeScriptCall($tool);
            } else {
                $hidden[] = $tool->definition()->name;
            }
        }

        $descriptions[CodemodeDescription::TOOL_NAME] = $describe($mode(), $callable);

        return ['descriptions' => $descriptions, 'hiddenDeclarations' => $hidden];
    };

    $tool = null;
    $register = static function () use ($pi, $describe, $mode, $execute, $renderCall, $renderResult, $prepareLoadout, &$tool): void {
        $tool = new CustomTool(
            name: CodemodeDescription::TOOL_NAME,
            label: 'codemode',
            // Replaced through `prepareLoadout` with the declarations of the callable tools.
            description: $describe($mode(), []),
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
            // A capable model writes the script as raw text instead of a JSON-escaped string.
            constrainedSampling: ['type' => 'grammar', 'variants' => ['openai_lark' => Source::GRAMMAR]],
            prepareLoadout: $prepareLoadout,
        );
        $pi->registerTool($tool);
    };

    /** Put the tool on the model, or take it off; the description follows the registry. */
    $sync = static function () use ($pi, &$active, &$callable, $register): void {
        if ($active) {
            $register();
        } else {
            $callable = null;
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
