<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicFederation;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Bedrock;
use Pig\Ai\Providers\BedrockOptions;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\Providers\GoogleVertex;
use Pig\Ai\Providers\GoogleVertexOptions;
use Pig\Ai\Providers\Mistral;
use Pig\Ai\Providers\MistralOptions;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\Providers\OpenAiResponses;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\Transcript;

/**
 * Picks the provider and translates the options it wants.
 *
 * Two doors, as upstream has: `simple()` takes options that mean the same thing
 * everywhere — "think hard" — and turns them into whatever the chosen provider calls
 * that; `start()` takes options already in the provider's own dialect. The agent loop
 * only ever uses the first.
 *
 * Upstream names these `streamSimple()` and `stream()`; `Stream::stream()` would stutter,
 * so the low-level one is `start()`.
 */
final class Stream
{
    /** Upstream's `CONTEXT_SAFETY_TOKENS`: what `clampMaxTokensToContext()` keeps clear of the window. */
    private const int CONTEXT_SAFETY_TOKENS = 4096;

    /** Upstream's `MIN_MAX_TOKENS`. */
    private const int MIN_MAX_TOKENS = 1;

    /**
     * Upstream's `MIN_ANSWER_TOKENS`: "Tokens always left for the answer when a thinking budget
     * shares the response ceiling."
     */
    private const int MIN_ANSWER_TOKENS = 1024;

    /**
     * Upstream's `AMBIENT_AUTH_MARKER` in `compat.ts`: what `envApiKey()` answers for Vertex and Bedrock
     * when credentials are there but are not a key — ADC, an AWS profile, a role — so that a provider
     * counts as signed in without any key being sent.
     */
    public const string AMBIENT_AUTH_MARKER = '<authenticated>';

    /** Upstream's `DEFAULT_THINKING_BUDGETS`, for the token-budget thinking models. */
    private const array DEFAULT_THINKING_BUDGETS = [
        'minimal' => 1024,
        'low' => 2048,
        'medium' => 8192,
        'high' => 16384,
    ];

    /**
     * Provider-independent options, mapped and sent.
     *
     * Upstream: streamSimple(), which folds the context's `systemPrompt` and `tools` into a leading
     * system message (`normalizeContext()`) before anything else looks at it.
     *
     * Takes a `TranscriptContext` as well, which is what the agent loop hands its stream function:
     * upstream's `TranscriptContext` is a `Context` structurally (it has `messages` and nothing to
     * fold), and PHP has no structural typing, so the union says the same thing.
     */
    public static function simple(Model $model, Context|TranscriptContext $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $transcript = self::normalize($context);

        return self::start($model, $transcript, self::translate($model, $transcript, $options));
    }

    /** Upstream's `normalizeContext()` at the entry points; a transcript is already normalized. */
    private static function normalize(Context|TranscriptContext $context): TranscriptContext
    {
        return $context instanceof TranscriptContext ? $context : Transcript::normalizeContext($context);
    }

    /**
     * Options already in the provider's dialect.
     *
     * Throws rather than returning a failed stream when no key can be found: that is a
     * misconfiguration, not a request that went wrong, and upstream throws here too.
     *
     * Upstream: stream().
     */
    public static function start(Model $model, Context|TranscriptContext $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $context = self::normalize($context);

        // Upstream's `withEnvApiKey()`: an explicit key, else the environment's (`options.env` first).
        $apiKey = self::withoutAmbientMarker($options?->apiKey !== null && trim($options->apiKey) !== ''
            ? $options->apiKey
            : self::envApiKey($model->provider, $options?->env));

        // Each API's own refusal, which upstream's `streamSimple()`s throw before anything is sent:
        // Anthropic goes without a key when an auth header (`options.headers`) or workload identity
        // federation stands in for it, the OpenAI APIs when an `authorization` or
        // `cf-aig-authorization` header does; Gemini, Mistral and an extension need the key.
        $headerAuth = match ($model->api) {
            Api::AnthropicMessages => AnthropicFederation::hasRequestAuth($apiKey, $options?->headers)
                || AnthropicFederation::config($model, $apiKey, $options?->headers, $options?->env) !== null,
            Api::OpenAiCompletions, Api::OpenAiResponses => Utils\Headers::has($options?->headers, 'authorization')
                || Utils\Headers::has($options?->headers, 'cf-aig-authorization'),
            // Neither of upstream's two refuses a missing key: Vertex falls back on Application
            // Default Credentials and Bedrock on the AWS credential chain, and each says so itself
            // when there is nothing there either.
            Api::GoogleVertex, Api::BedrockConverseStream => true,
            default => false,
        };

        if (($apiKey === null || $apiKey === '') && !$headerAuth) {
            throw new ProviderError("No API key for provider: {$model->provider}");
        }

        return match ($model->api) {
            Api::AnthropicMessages => (new Anthropic())->stream($model, $context, self::anthropic($options, $apiKey)),
            Api::OpenAiCompletions => (new OpenAiCompletions())->stream($model, $context, self::openAi($options, $apiKey)),
            Api::OpenAiResponses => (new OpenAiResponses())->stream($model, $context, self::openAi($options, $apiKey)),
            Api::GoogleGenerativeAi => (new Google())->stream($model, $context, self::google($options, (string) $apiKey)),
            Api::GoogleVertex => (new GoogleVertex())->stream($model, $context, self::vertex($options, $apiKey)),
            Api::BedrockConverseStream => (new Bedrock())->stream($model, $context, self::bedrock($options, $apiKey)),
            Api::MistralConversations => (new Mistral())->stream($model, $context, self::mistral($options, (string) $apiKey)),
            // Still no `default`: this arm names the one case that is not a built-in, and the
            // registry is what answers for it. The options are already in the extension's own
            // dialect by the time they reach here — `translate()` asked it — or are what the
            // caller built; either way they carry the key.
            Api::Extension => self::extensionApi($model)->stream($model, $context, self::withKey($options, (string) $apiKey)),
        };
    }

    /**
     * The protocol an extension registered for this model's provider, or a refusal that names it.
     *
     * A model saying `Api::Extension` with no provider behind it is one that was in a session
     * file or a settings file when the extension was installed and is not any more — and "no
     * API key" or a null three layers down is the wrong sentence for that.
     */
    private static function extensionApi(Model $model): Extension\StreamApi
    {
        return Extension\ProviderRegistry::apiFor($model)
            ?? throw new ProviderError("No extension provides the protocol for {$model->provider}/{$model->id}. Is its extension loaded?");
    }

    /**
     * The options as they came, with the key on them.
     *
     * `translate()` hands the extension the key and the extension puts it on what it builds, so
     * the common path arrives here with one already. A caller of `start()` with options that
     * carry none — the shape the four built-in `anthropic()`/`openAi()`/`google()` helpers also
     * handle — gets the base options with the key, which is the most this can build without
     * knowing the extension's subclass.
     */
    private static function withKey(?StreamOptions $options, string $apiKey): StreamOptions
    {
        if ($options !== null && $options->apiKey !== null) {
            return $options;
        }

        return new StreamOptions(...[...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey]);
    }

    /**
     * Upstream's `withEnvApiKey()` drops the ambient marker rather than sending it as a key. pig's
     * `Auth` hands the environment's answer back as the explicit key — upstream's coding agent resolves
     * ambient credentials to `auth: {}` instead — so the marker is dropped from either source here.
     */
    private static function withoutAmbientMarker(?string $apiKey): ?string
    {
        return $apiKey === self::AMBIENT_AUTH_MARKER ? null : $apiKey;
    }

    /**
     * The key for a provider, from the environment.
     *
     * Upstream: getEnvApiKey(). An OAuth token wins over an API key where both exist. Vertex and
     * Bedrock can be signed in without a key — Application Default Credentials with a project and a
     * location, or any of the AWS sources upstream lists — and answer `AMBIENT_AUTH_MARKER` then.
     */
    public static function envApiKey(string $provider, ?array $env = null): ?string
    {
        $names = match ($provider) {
            'anthropic' => ['ANTHROPIC_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'],
            'github-copilot' => ['COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN'],
            'openai' => ['OPENAI_API_KEY'],
            'google' => ['GEMINI_API_KEY'],
            'google-vertex' => ['GOOGLE_CLOUD_API_KEY'],
            'amazon-bedrock' => [],
            'groq' => ['GROQ_API_KEY'],
            'cerebras' => ['CEREBRAS_API_KEY'],
            'xai' => ['XAI_API_KEY'],
            'openrouter' => ['OPENROUTER_API_KEY'],
            'zai' => ['ZAI_API_KEY'],
            'mistral' => ['MISTRAL_API_KEY'],
            default => Extension\ProviderRegistry::envKeysFor($provider),
        };

        foreach ($names as $name) {
            $value = StreamOptions::providerEnvValue($name, $env);

            if ($value !== null) {
                return $value;
            }
        }

        // "Vertex AI supports either an explicit API key or Application Default Credentials. Auth is
        // configured via `gcloud auth application-default login`."
        if ($provider === 'google-vertex'
            && self::hasVertexAdcCredentials($env)
            && (StreamOptions::providerEnvValue('GOOGLE_CLOUD_PROJECT', $env) ?? StreamOptions::providerEnvValue('GCLOUD_PROJECT', $env)) !== null
            && StreamOptions::providerEnvValue('GOOGLE_CLOUD_LOCATION', $env) !== null) {
            return self::AMBIENT_AUTH_MARKER;
        }

        // "Amazon Bedrock supports multiple credential sources: AWS_PROFILE, AWS_ACCESS_KEY_ID +
        // AWS_SECRET_ACCESS_KEY, AWS_BEARER_TOKEN_BEDROCK, AWS_CONTAINER_CREDENTIALS_RELATIVE_URI,
        // AWS_CONTAINER_CREDENTIALS_FULL_URI (ECS task roles), AWS_WEB_IDENTITY_TOKEN_FILE (IRSA)."
        if ($provider === 'amazon-bedrock') {
            $set = static fn (string $name): bool => StreamOptions::providerEnvValue($name, $env) !== null;

            if ($set('AWS_PROFILE')
                || ($set('AWS_ACCESS_KEY_ID') && $set('AWS_SECRET_ACCESS_KEY'))
                || $set('AWS_BEARER_TOKEN_BEDROCK')
                || $set('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI')
                || $set('AWS_CONTAINER_CREDENTIALS_FULL_URI')
                || $set('AWS_WEB_IDENTITY_TOKEN_FILE')) {
                return self::AMBIENT_AUTH_MARKER;
            }
        }

        return null;
    }

    /**
     * Upstream's `hasVertexAdcCredentials()`: a scoped `GOOGLE_APPLICATION_CREDENTIALS` that exists,
     * else the process's, else `~/.config/gcloud/application_default_credentials.json`. Upstream caches
     * the process-level answer for the life of the process (its `fs` import is asynchronous); this asks
     * the file system each time, so a file written after startup is seen.
     *
     * @param array<string, string>|null $env
     */
    private static function hasVertexAdcCredentials(?array $env): bool
    {
        $explicit = $env['GOOGLE_APPLICATION_CREDENTIALS'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return file_exists($explicit);
        }

        $gacPath = StreamOptions::providerEnvValue('GOOGLE_APPLICATION_CREDENTIALS', $env);

        if ($gacPath !== null) {
            return file_exists($gacPath);
        }

        $home = getenv('HOME');

        return is_string($home) && $home !== '' && file_exists($home . '/.config/gcloud/application_default_credentials.json');
    }

    /**
     * Upstream: mapOptionsForApi().
     *
     * **One arm per API and no `default`**, which is how `start()` below has always been written
     * and how this should have been: the `default` that used to sit here handed back plain
     * `StreamOptions`, so an API with no arm got no thinking configuration and said nothing about
     * it — which is exactly what happened to the Gemini CLI provider for twelve models. Upstream's own
     * default is an exhaustiveness check that throws. Without a `default`, a new `Api` case fails
     * here loudly instead of quietly asking the provider for its defaults.
     */
    private static function translate(Model $model, TranscriptContext $context, ?SimpleStreamOptions $options): StreamOptions
    {
        // Upstream's `buildBaseOptions()`, which every one of its `streamSimple()`s starts from:
        // `maxTokens: clampMaxTokensToContext(model, context, options?.maxTokens ?? model.maxTokens)`.
        // The model's own ceiling when nobody said, cut to the room the conversation leaves. This
        // used to be `min(model.maxTokens, 32_000)` here and `intdiv(model.maxTokens, 3)` in
        // `Anthropic`, neither of which upstream has any more.
        $maxTokens = self::clampMaxTokensToContext($model, $context, $options?->maxTokens ?? $model->maxTokens);
        $apiKey = self::withoutAmbientMarker($options?->apiKey !== null && trim($options->apiKey) !== ''
            ? $options->apiKey
            : self::envApiKey($model->provider, $options?->env));
        // Upstream's `buildBaseOptions()`: every request-level field the caller gave travels on
        // whatever the API's options are — headers, callbacks, timeouts, retries, env.
        $base = [...($options ?? new SimpleStreamOptions())->baseArgs(), 'maxTokens' => $maxTokens, 'apiKey' => $apiKey];
        return match ($model->api) {
            // Upstream's `streamSimple()` in `openai-completions.ts`, the same as the responses arm
            // below: the level clamped to the ones the model has (`clampThinkingLevel()`, which
            // reads the `thinkingLevelMap`), `off` meaning none, and the caller's `toolChoice`
            // passed on. The provider then sends what the map calls the level.
            Api::OpenAiCompletions => new OpenAiOptions(
                ...$base,
                reasoning: self::clampedReasoning($model, $options?->reasoning),
                toolChoice: $options?->toolChoice,
            ),
            // Upstream's `streamSimple()` in `openai-responses.ts`: the level clamped to the ones
            // the model has (`clampThinkingLevel()`, which reads the `thinkingLevelMap`), `off`
            // meaning none, and the caller's `toolChoice` passed on.
            Api::OpenAiResponses => new OpenAiOptions(
                ...$base,
                reasoning: self::clampedReasoning($model, $options?->reasoning),
                toolChoice: $options?->toolChoice,
            ),
            Api::GoogleGenerativeAi => self::gemini($model, $options, $base),
            Api::GoogleVertex => self::vertexSimple($model, $options, $base),
            Api::BedrockConverseStream => self::bedrockSimple($model, $context, $options, $base),
            Api::AnthropicMessages => self::anthropicSimple($model, $context, $options, $base),
            Api::MistralConversations => self::mistralSimple($model, $options, $base),
            Api::Extension => self::extensionApi($model)->translate($model, $options, $apiKey
                ?? throw new ProviderError("No API key for provider: {$model->provider}")),
        };
    }

    /**
     * Upstream's `clampMaxTokensToContext()`: the request's ceiling, cut so the conversation plus
     * the answer plus 4,096 tokens of slack fit in the window — never below 1. A model with no
     * window (0) is not cut.
     */
    private static function clampMaxTokensToContext(Model $model, TranscriptContext $context, int $maxTokens): int
    {
        if ($model->contextWindow <= 0) {
            return max(self::MIN_MAX_TOKENS, $maxTokens);
        }

        $available = $model->contextWindow - Utils\Estimate::contextTokens($context) - self::CONTEXT_SAFETY_TOKENS;

        return min($maxTokens, max(self::MIN_MAX_TOKENS, $available));
    }

    /**
     * Upstream's `streamSimple()` in `anthropic-messages.ts`, arm for arm.
     *
     * - No reasoning asked for: `thinkingEnabled: false`, which `Anthropic` sends as `thinking:
     *   {type: "disabled"}` unless the model cannot switch it off.
     * - A `forceAdaptiveThinking` model: an effort level (`mapThinkingLevelToEffort()`).
     * - Anything else thinks on a token budget: upstream's `adjustMaxTokensForThinking()` raises
     *   the ceiling by the level's budget (minimal 1,024, low 2,048, medium 8,192, high 16,384;
     *   xhigh and max count as high) up to the model's own, keeps 1,024 for the answer when the
     *   budget would take it all, clamps the result to the context again, and the budget to what
     *   is left above 1,024.
     *
     * Upstream's caller-supplied `thinkingBudgets` are not ported: nothing in pig sets them.
     */
    /** @param array<string, mixed> $base `buildBaseOptions()` as named arguments */
    private static function anthropicSimple(Model $model, TranscriptContext $context, ?SimpleStreamOptions $options, array $base): AnthropicOptions
    {
        $reasoning = $options?->reasoning;
        $toolChoice = $options?->toolChoice;
        $maxTokens = $base['maxTokens'];

        if ($reasoning === null) {
            return new AnthropicOptions(...$base, thinkingEnabled: false, toolChoice: $toolChoice);
        }

        $compat = $model->compat instanceof AnthropicCompat ? $model->compat : null;

        if ($compat?->forceAdaptiveThinking === true) {
            return new AnthropicOptions(
                ...$base,
                thinkingEnabled: true,
                effort: self::anthropicEffort($model, $reasoning),
                toolChoice: $toolChoice,
            );
        }

        // `adjustMaxTokensForThinking(base.maxTokens, model.maxTokens, reasoning)`: the base is
        // always set here (`buildBaseOptions()` sets it), so the ceiling is the base plus the budget,
        // capped at the model's.
        $level = in_array($reasoning->value, ['xhigh', 'max'], true) ? 'high' : $reasoning->value;
        $thinkingBudget = self::DEFAULT_THINKING_BUDGETS[$level];
        $adjusted = min($maxTokens + $thinkingBudget, $model->maxTokens);

        if ($adjusted <= $thinkingBudget) {
            $thinkingBudget = min($thinkingBudget, max(0, $adjusted - self::MIN_ANSWER_TOKENS));
        }

        $ceiling = self::clampMaxTokensToContext($model, $context, $adjusted);

        return new AnthropicOptions(
            ...[...$base, 'maxTokens' => $ceiling],
            thinkingEnabled: true,
            thinkingBudgetTokens: min($thinkingBudget, max(0, $ceiling - self::MIN_ANSWER_TOKENS)),
            toolChoice: $toolChoice,
        );
    }

    /**
     * Upstream's `clampThinkingLevel(model, reasoning)` for both OpenAI APIs, with `off` meaning no
     * reasoning. pig has no `max` effort, so a clamp that would land there — a model whose map
     * offers `max` and not `xhigh`, asked for `xhigh` — is refused out loud rather than sent as
     * something else; the agent's own clamp (`ThinkingLevel::clampedFor()`) never asks that.
     */
    private static function clampedReasoning(Model $model, ?ReasoningEffort $reasoning): ?ReasoningEffort
    {
        if ($reasoning === null) {
            return null;
        }

        $clamped = $model->clampThinkingLevel($reasoning->value);

        if ($clamped === 'off') {
            return null;
        }

        return ReasoningEffort::tryFrom($clamped)
            ?? throw new ProviderError("{$model->provider}/{$model->id} has no '{$reasoning->value}' thinking level, and its nearest, '{$clamped}', is not one pig can send");
    }

    private static function anthropic(?StreamOptions $options, ?string $apiKey): AnthropicOptions
    {
        $base = [...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey];

        if ($options instanceof AnthropicOptions) {
            return new AnthropicOptions(
                ...$base,
                thinkingEnabled: $options->thinkingEnabled,
                thinkingBudgetTokens: $options->thinkingBudgetTokens,
                interleavedThinking: $options->interleavedThinking,
                effort: $options->effort,
                thinkingDisplay: $options->thinkingDisplay,
                toolChoice: $options->toolChoice,
            );
        }

        return new AnthropicOptions(...$base);
    }

    /**
     * Upstream's `mapThinkingLevelToEffort()`: the model's `thinkingLevelMap` entry when it is a
     * string, else minimal and low are `low`, medium `medium`, anything else `high`.
     */
    private static function anthropicEffort(Model $model, ?ReasoningEffort $reasoning): string
    {
        $mapped = $reasoning !== null ? ($model->thinkingLevelMap[$reasoning->value] ?? null) : null;
        if (is_string($mapped)) {
            return $mapped;
        }

        return match ($reasoning?->value) {
            'minimal', 'low' => 'low',
            'medium' => 'medium',
            'high', 'xhigh' => 'high',
            default => 'high',
        };
    }

    /**
     * How hard Gemini should think, said the way the model in question understands it.
     *
     * Upstream's `streamSimple()` in `google-generative-ai.ts`. A level model takes a named level
     * and ignores a budget; 2.5 takes a budget in tokens and has different ceilings for pro and
     * flash. And saying nothing means *dynamic* thinking, not none — so a turn that did not ask
     * for thinking has to ask for none, which `Google` words per model.
     *
     * Upstream clamps the level to the model's own levels here (`clampThinkingLevel()`, which reads
     * the `thinkingLevelMap` — Gemma 4's map has only `minimal` and `high`, so a `low` asked for
     * goes out as `HIGH`), and `off` after the clamp is no thinking. The resolved level then comes
     * through the map (`resolveGoogleThinkingLevel()`), which refuses `xhigh` by name unless the
     * map turns it into one of Google's four. This used to cut `xhigh` to `high` and send the level
     * unclamped, which is the agent's clamp (`ThinkingLevel::clampedFor()`) and not this package's.
     */
    /** @param array<string, mixed> $base `buildBaseOptions()` as named arguments */
    private static function gemini(Model $model, ?SimpleStreamOptions $options, array $base): GoogleOptions
    {
        $clamped = $options?->reasoning !== null ? $model->clampThinkingLevel($options->reasoning->value) : 'off';

        if ($clamped === 'off') {
            return new GoogleOptions(...$base, thinkingEnabled: false);
        }

        $resolvedLevel = GoogleShared::resolveGoogleThinkingLevel($model, $clamped);

        // Upstream's `usesGoogleThinkingLevel()`, a regex over the id. It replaces
        // `str_contains($id, 'gemini-3')`, which matched every 3.x id but missed
        // `gemini-flash-latest`, `gemini-flash-lite-latest` and Gemma 4, which all take a level too.
        if (GoogleShared::usesGoogleThinkingLevel($model)) {
            return new GoogleOptions(...$base, thinkingEnabled: true, thinkingLevel: GoogleShared::toGoogleThinkingLevel($resolvedLevel));
        }

        return new GoogleOptions(...$base, thinkingEnabled: true, thinkingBudget: self::geminiBudget($model, $resolvedLevel));
    }

    /**
     * Upstream's `getGoogleBudget()`, from the level the model's map resolved to.
     *
     * Upstream also takes the caller's own `thinkingBudgets` first; pig's `SimpleStreamOptions` has
     * no such field, so the table is the whole answer. https://ai.google.dev/gemini-api/docs/thinking#set-budget
     */
    private static function geminiBudget(Model $model, string $level): int
    {
        if (str_contains($model->id, '2.5-pro')) {
            return ['minimal' => 128, 'low' => 2048, 'medium' => 8192, 'high' => 32768][$level];
        }

        // Before `2.5-flash`, which its id also contains: Flash-Lite thinks at least 512.
        if (str_contains($model->id, '2.5-flash-lite')) {
            return ['minimal' => 512, 'low' => 2048, 'medium' => 8192, 'high' => 24576][$level];
        }

        if (str_contains($model->id, '2.5-flash')) {
            return ['minimal' => 128, 'low' => 2048, 'medium' => 8192, 'high' => 24576][$level];
        }

        // A model with no published ceiling: -1 lets it decide, which beats a guess.
        return -1;
    }

    /**
     * Upstream's `streamSimple()` in `google-vertex.ts`: `gemini()`'s rules — the level clamped to the
     * model's own, `off` meaning thinking disabled, a level model sent a level and the rest a budget —
     * with Vertex's own `getGoogleBudget()`, which has no Flash-Lite arm.
     *
     * @param array<string, mixed> $base `buildBaseOptions()` as named arguments
     */
    private static function vertexSimple(Model $model, ?SimpleStreamOptions $options, array $base): GoogleVertexOptions
    {
        $toolChoice = $options?->toolChoice;
        $clamped = $options?->reasoning !== null ? $model->clampThinkingLevel($options->reasoning->value) : 'off';

        if ($clamped === 'off') {
            return new GoogleVertexOptions(...$base, thinkingEnabled: false, toolChoice: $toolChoice);
        }

        $resolvedLevel = GoogleShared::resolveGoogleThinkingLevel($model, $clamped);

        if (GoogleShared::usesGoogleThinkingLevel($model)) {
            return new GoogleVertexOptions(...$base, thinkingEnabled: true, thinkingLevel: GoogleShared::toGoogleThinkingLevel($resolvedLevel), toolChoice: $toolChoice);
        }

        return new GoogleVertexOptions(...$base, thinkingEnabled: true, thinkingBudget: self::vertexBudget($model, $resolvedLevel), toolChoice: $toolChoice);
    }

    /** Upstream's `getGoogleBudget()` in `google-vertex.ts`; the caller's `thinkingBudgets` are not ported. */
    private static function vertexBudget(Model $model, string $level): int
    {
        if (str_contains($model->id, '2.5-pro')) {
            return ['minimal' => 128, 'low' => 2048, 'medium' => 8192, 'high' => 32768][$level];
        }

        if (str_contains($model->id, '2.5-flash')) {
            return ['minimal' => 128, 'low' => 2048, 'medium' => 8192, 'high' => 24576][$level];
        }

        return -1;
    }

    /**
     * Upstream's `streamSimple()` in `bedrock-converse-stream.ts`. The level is passed on as asked —
     * Bedrock's provider maps it itself — and only a Claude model that thinks on a token budget has the
     * ceiling raised: `adjustMaxTokensForThinking()` (the level's budget added, capped at the model's
     * own), clamped to the context again, and the budget cut to what that leaves above 1,024. "Do not
     * coerce to 0 here, or the thinking budget would become the entire maxTokens value."
     *
     * @param array<string, mixed> $base `buildBaseOptions()` as named arguments
     */
    private static function bedrockSimple(Model $model, TranscriptContext $context, ?SimpleStreamOptions $options, array $base): BedrockOptions
    {
        $toolChoice = $options?->toolChoice;
        $reasoning = $options?->reasoning?->value;

        if ($reasoning === null) {
            return new BedrockOptions(...$base, toolChoice: $toolChoice);
        }

        if (Bedrock::isAnthropicClaudeModel($model) && !Bedrock::supportsAdaptiveThinking($model)) {
            $level = $reasoning === 'xhigh' || $reasoning === 'max' ? 'high' : $reasoning;
            $thinkingBudget = self::DEFAULT_THINKING_BUDGETS[$level];
            $adjusted = min($base['maxTokens'] + $thinkingBudget, $model->maxTokens);

            if ($adjusted <= $thinkingBudget) {
                $thinkingBudget = min($thinkingBudget, max(0, $adjusted - self::MIN_ANSWER_TOKENS));
            }

            $maxTokens = self::clampMaxTokensToContext($model, $context, $adjusted);

            return new BedrockOptions(
                ...[...$base, 'maxTokens' => $maxTokens],
                toolChoice: $toolChoice,
                reasoning: $reasoning,
                thinkingBudgets: [$level => min($thinkingBudget, max(0, $maxTokens - self::MIN_ANSWER_TOKENS))],
            );
        }

        return new BedrockOptions(...$base, toolChoice: $toolChoice, reasoning: $reasoning);
    }

    /** The key is resolved late; a caller's own Vertex options are otherwise kept whole. */
    private static function vertex(?StreamOptions $options, ?string $apiKey): GoogleVertexOptions
    {
        $base = [...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey];

        if ($options instanceof GoogleVertexOptions) {
            return new GoogleVertexOptions(
                ...$base,
                thinkingEnabled: $options->thinkingEnabled,
                thinkingBudget: $options->thinkingBudget,
                thinkingLevel: $options->thinkingLevel,
                toolChoice: $options->toolChoice,
                project: $options->project,
                location: $options->location,
            );
        }

        return new GoogleVertexOptions(...$base);
    }

    /** The key is resolved late; a caller's own Bedrock options are otherwise kept whole. */
    private static function bedrock(?StreamOptions $options, ?string $apiKey): BedrockOptions
    {
        $base = [...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey];

        if ($options instanceof BedrockOptions) {
            return new BedrockOptions(
                ...$base,
                region: $options->region,
                profile: $options->profile,
                toolChoice: $options->toolChoice,
                reasoning: $options->reasoning,
                thinkingBudgets: $options->thinkingBudgets,
                interleavedThinking: $options->interleavedThinking,
                thinkingDisplay: $options->thinkingDisplay,
                requestMetadata: $options->requestMetadata,
                bearerToken: $options->bearerToken,
            );
        }

        return new BedrockOptions(...$base);
    }

    /**
     * Upstream's `streamSimple()` in `mistral-conversations.ts`: the level clamped to the model's own
     * (`clampThinkingLevel()`), `off` meaning none. "Models with a thinking level map use
     * `reasoning_effort`; other reasoning models use `prompt_mode`": a reasoning model with a map
     * sends the map's word for the level — `high` when the map has none for it — or, with thinking
     * off, the map's `off` when it has one; a reasoning model without a map sends
     * `prompt_mode: "reasoning"` when thinking is on and nothing when it is off.
     */
    /** @param array<string, mixed> $base `buildBaseOptions()` as named arguments */
    private static function mistralSimple(Model $model, ?SimpleStreamOptions $options, array $base): MistralOptions
    {
        $clamped = $options?->reasoning !== null ? $model->clampThinkingLevel($options->reasoning->value) : null;
        $reasoning = $clamped === 'off' ? null : $clamped;
        // `model.reasoning ? model.thinkingLevelMap : undefined` — pig's "no map" is an empty one.
        $effortMap = $model->reasoning && $model->thinkingLevelMap !== [] ? $model->thinkingLevelMap : null;
        $reasoningEffort = $effortMap !== null
            ? ($reasoning !== null ? ($effortMap[$reasoning] ?? 'high') : ($effortMap['off'] ?? null))
            : null;

        return new MistralOptions(
            ...$base,
            toolChoice: $options?->toolChoice,
            promptMode: $model->reasoning && $effortMap === null && $reasoning !== null ? 'reasoning' : null,
            reasoningEffort: $reasoningEffort,
        );
    }

    /** The same shape as `anthropic()`: the key is resolved late, everything else is kept. */
    private static function mistral(?StreamOptions $options, string $apiKey): MistralOptions
    {
        $base = [...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey];

        if ($options instanceof MistralOptions) {
            return new MistralOptions(
                ...$base,
                toolChoice: $options->toolChoice,
                promptMode: $options->promptMode,
                reasoningEffort: $options->reasoningEffort,
            );
        }

        return new MistralOptions(...$base);
    }

    /** The key is resolved late; a caller's own Google options are otherwise kept whole. */
    private static function google(?StreamOptions $options, string $apiKey): GoogleOptions
    {
        $base = [...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey];

        if ($options instanceof GoogleOptions) {
            return new GoogleOptions(
                ...$base,
                thinkingEnabled: $options->thinkingEnabled,
                thinkingBudget: $options->thinkingBudget,
                thinkingLevel: $options->thinkingLevel,
                toolChoice: $options->toolChoice,
            );
        }

        return new GoogleOptions(...$base);
    }

    /** The same shape as `anthropic()`: the key is resolved late, everything else is kept. */
    private static function openAi(?StreamOptions $options, ?string $apiKey): OpenAiOptions
    {
        $base = [...($options ?? new StreamOptions())->baseArgs(), 'apiKey' => $apiKey];

        if ($options instanceof OpenAiOptions) {
            return new OpenAiOptions(
                ...$base,
                reasoning: $options->reasoning,
                toolChoice: $options->toolChoice,
                serviceTier: $options->serviceTier,
                reasoningSummary: $options->reasoningSummary,
            );
        }

        return new OpenAiOptions(...$base);
    }
}
