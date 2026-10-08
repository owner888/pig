<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The ways an "OpenAI-compatible" endpoint is not.
 *
 * Seven providers speak `openai-completions`, and every one of them differs somewhere:
 * Mistral wants tool ids exactly nine alphanumeric characters, Grok rejects
 * `reasoning_effort`, Cerebras rejects `store`, Copilot wants assistant text as a string
 * rather than an array. None of that is in anyone's documentation as a difference; it is
 * what a 400 looks like after you have sent it.
 *
 * A table per endpoint rather than one rule, for the reason the tool archive names are a
 * table: a merged rule is right for the providers you tested and silently wrong for the
 * one you did not, and the failure lands on someone else's machine.
 *
 * Ported from upstream's `OpenAICompat`, `detectCompat()` and `getCompat()`.
 */
final readonly class OpenAiCompat
{
    /**
     * Every field is nullable, and **null means "not said"** — upstream's `undefined`. A
     * `compat` block written by a person (`models.json`, a built-in table like Copilot's) says
     * only what is unusual about its endpoint, and `resolve()` fills in the rest from
     * `detect()`, key by key. What `detect()` and `resolve()` return has every field set.
     *
     * @param bool|null   $store            send `store: false`; the strict endpoints reject the field
     * @param bool|null   $developerRole    a reasoning model's system prompt goes in a `developer` turn
     * @param bool|null   $reasoningEffort  send `reasoning_effort` at all
     * @param string|null $maxTokensField   `max_completion_tokens`, or the older `max_tokens`
     * @param bool|null   $toolResultName   a tool result carries the tool's name as well as its id
     * @param bool|null   $assistantAfterToolResult insert a filler turn between a result and a user message
     * @param bool|null   $thinkingAsText   thinking goes back as untagged text, one part ahead of the answer, rather than a field
     * @param bool|null   $mistralToolIds   tool ids are cut and padded to exactly nine characters
     * @param bool|null   $reasoningContentOnAssistantMessages every replayed assistant turn of a reasoning
     *        model carries `reasoning_content`, empty when there is none — upstream's
     *        `requiresReasoningContentOnAssistantMessages`, which DeepSeek needs
     * @param bool|null   $strictMode       a tool that asks for strict sampling (`Tool::$constrainedSampling`)
     *        is sent with `strict` and its strict schema — upstream's `supportsStrictMode`. Also read
     *        by `OpenAiResponses`, where upstream's Responses compat has the same key
     * @param string|null $thinkingFormat   how thinking is switched on and off and how hard — upstream's
     *        `thinkingFormat`: `openai` (`reasoning_effort`), `openrouter`, `deepseek`, `together`,
     *        `baseten`, `zai`, `qwen`, `chat-template`, `qwen-chat-template`, `string-thinking`,
     *        `ant-ling`. A name upstream does not have behaves as `openai`, as it does upstream.
     * @param array<string, mixed>|null $chatTemplateKwargs `chat_template_kwargs` for `chat-template`;
     *        a value `{"$var": "thinking.enabled"|"thinking.effort"|"thinking.budget", "omitWhenOff"?: bool}`
     *        is filled in per request
     * @param array<string, mixed>|null $chatTemplateArgs the same, sent as `chat_template_args`, for `baseten`
     * @param array<string, mixed>|null $openRouterRouting upstream's `openRouterRouting`: OpenRouter's
     *        provider-routing preferences (`order`, `only`, `ignore`, `allow_fallbacks`, `sort`,
     *        `max_price`, …), sent as the request's `provider` field. Passed through as written
     * @param array<string, mixed>|null $vercelGatewayRouting upstream's `vercelGatewayRouting`:
     *        `only` and `order`, sent as `providerOptions.gateway`
     * @param bool|null   $grammarTools     a tool that asks for grammar sampling
     *        (`Tool::$constrainedSampling` `['type' => 'grammar', …]`) is sent as an OpenAI custom tool
     *        with a Lark or regex grammar — upstream's `supportsOpenAIGrammarTools`, default false;
     *        otherwise it falls back to an ordinary function tool. Read by both OpenAI providers, as
     *        upstream's completions and Responses compat both have the key
     */
    public function __construct(
        public ?bool $store = null,
        public ?bool $developerRole = null,
        public ?bool $reasoningEffort = null,
        public ?string $maxTokensField = null,
        public ?bool $toolResultName = null,
        public ?bool $assistantAfterToolResult = null,
        public ?bool $thinkingAsText = null,
        public ?bool $mistralToolIds = null,
        public ?bool $reasoningContentOnAssistantMessages = null,
        public ?bool $strictMode = null,
        public ?string $thinkingFormat = null,
        public ?array $chatTemplateKwargs = null,
        public ?array $chatTemplateArgs = null,
        public ?array $openRouterRouting = null,
        public ?array $vercelGatewayRouting = null,
        public ?bool $grammarTools = null,
    ) {
    }

    /**
     * What a model's endpoint needs: detected, then the model's own `compat` laid over it **per
     * key**.
     *
     * Upstream's `getCompat()`: `model.compat.supportsStore ?? detected.supportsStore`, and the
     * same for every key, so a block that sets one flag leaves the other eight to detection.
     * Reading a block as the whole answer — what pig did — turned a DeepSeek `models.json` entry
     * with `{"requiresThinkingAsText": false}` into an endpoint sent `store`, the `developer` role
     * and `max_completion_tokens`, the three things detection had said not to send.
     */
    public static function resolve(Model $model): self
    {
        $detected = self::detect($model->baseUrl, $model->provider, $model->id);
        // A model of another API may carry its own compat (`AnthropicCompat`); none of it is ours.
        $explicit = $model->compat instanceof self ? $model->compat : null;

        if ($explicit === null) {
            return $detected;
        }

        return new self(
            store: $explicit->store ?? $detected->store,
            developerRole: $explicit->developerRole ?? $detected->developerRole,
            reasoningEffort: $explicit->reasoningEffort ?? $detected->reasoningEffort,
            maxTokensField: $explicit->maxTokensField ?? $detected->maxTokensField,
            toolResultName: $explicit->toolResultName ?? $detected->toolResultName,
            assistantAfterToolResult: $explicit->assistantAfterToolResult ?? $detected->assistantAfterToolResult,
            thinkingAsText: $explicit->thinkingAsText ?? $detected->thinkingAsText,
            mistralToolIds: $explicit->mistralToolIds ?? $detected->mistralToolIds,
            reasoningContentOnAssistantMessages: $explicit->reasoningContentOnAssistantMessages
                ?? $detected->reasoningContentOnAssistantMessages,
            strictMode: $explicit->strictMode ?? $detected->strictMode,
            thinkingFormat: $explicit->thinkingFormat ?? $detected->thinkingFormat,
            chatTemplateKwargs: $explicit->chatTemplateKwargs ?? $detected->chatTemplateKwargs,
            chatTemplateArgs: $explicit->chatTemplateArgs ?? $detected->chatTemplateArgs,
            // Upstream: `model.compat.openRouterRouting ?? {}` — the one key not taken from
            // detection, which says `{}` anyway — and `vercelGatewayRouting ?? detected…`.
            openRouterRouting: $explicit->openRouterRouting ?? [],
            vercelGatewayRouting: $explicit->vercelGatewayRouting ?? $detected->vercelGatewayRouting,
            grammarTools: $explicit->grammarTools ?? $detected->grammarTools,
        );
    }

    /**
     * What an endpoint needs, worked out from its provider name and where it is.
     *
     * Upstream's `detectCompat()`, copied flag by flag for the keys pig has. Every `isX` is
     * `provider === "x" || baseUrl.includes(...)`, case-sensitive — **except DeepSeek's**, whose
     * URL check is `baseUrl.toLowerCase().includes("deepseek.com")`, so `api.DeepSeek.com` is
     * DeepSeek for every flag and not only for one. `$modelId` is for OpenRouter, whose
     * `anthropic/` and `openai/` models take the `developer` role and the rest do not.
     *
     * **One thing here is pig's and not upstream's: Mistral.** Upstream moved Mistral to its own
     * `mistral-conversations` API and its `detectCompat()` no longer names it; pig still reaches
     * `api.mistral.ai` through `openai-completions`, so the rules upstream's detection had for it
     * when it did — non-standard, `max_tokens`, the tool result's name, thinking as text, the
     * nine-character tool ids — stay.
     */
    public static function detect(string $baseUrl, string $provider = '', string $modelId = ''): self
    {
        $isZai = $provider === 'zai'
            || $provider === 'zai-coding-cn'
            || str_contains($baseUrl, 'api.z.ai')
            || str_contains($baseUrl, 'open.bigmodel.cn');
        $isTogether = $provider === 'together'
            || str_contains($baseUrl, 'api.together.ai')
            || str_contains($baseUrl, 'api.together.xyz');
        $isMoonshot = $provider === 'moonshotai' || $provider === 'moonshotai-cn' || str_contains($baseUrl, 'api.moonshot.');
        $isOpenRouter = $provider === 'openrouter' || str_contains($baseUrl, 'openrouter.ai');
        $isCloudflareWorkersAi = $provider === 'cloudflare-workers-ai' || str_contains($baseUrl, 'api.cloudflare.com');
        $isCloudflareAiGateway = $provider === 'cloudflare-ai-gateway' || str_contains($baseUrl, 'gateway.ai.cloudflare.com');
        $isNvidia = $provider === 'nvidia' || str_contains($baseUrl, 'integrate.api.nvidia.com');
        $isAntLing = $provider === 'ant-ling' || str_contains($baseUrl, 'api.ant-ling.com');
        $isCerebras = $provider === 'cerebras' || str_contains($baseUrl, 'cerebras.ai');
        $isDeepSeek = $provider === 'deepseek' || str_contains(strtolower($baseUrl), 'deepseek.com');
        // pig's, see above: what upstream's detection said for Mistral before it moved.
        $isMistral = str_contains($baseUrl, 'mistral.ai');

        $isNonStandard = $isNvidia
            || $isCerebras
            || $provider === 'xai'
            || str_contains($baseUrl, 'api.x.ai')
            || $isTogether
            || str_contains($baseUrl, 'chutes.ai')
            || $isDeepSeek
            || $isZai
            || $isMoonshot
            || $provider === 'opencode'
            || str_contains($baseUrl, 'opencode.ai')
            || $isCloudflareWorkersAi
            || $isCloudflareAiGateway
            || $isAntLing
            || $isMistral;

        // DeepSeek is here, and it is the one found by measurement rather than by a 400: it
        // **accepts** `max_completion_tokens` and ignores it, so nothing fails and every request
        // is simply unbounded. `test/live.php` asked for 16 tokens and got 145. *An endpoint that
        // quietly drops a field is worse than one that refuses it, and only a number tells them
        // apart.*
        $useMaxTokens = str_contains($baseUrl, 'chutes.ai')
            || $isDeepSeek
            || $isMoonshot
            || $isCloudflareAiGateway
            || $isTogether
            || $isNvidia
            || $isAntLing
            || $isZai
            || $isMistral;

        $isGrok = $provider === 'xai' || str_contains($baseUrl, 'api.x.ai');
        $isOpenRouterDeveloperRoleModel = $isOpenRouter
            && (str_starts_with($modelId, 'anthropic/') || str_starts_with($modelId, 'openai/'));

        return new self(
            store: !$isNonStandard,
            developerRole: $isOpenRouterDeveloperRoleModel || (!$isNonStandard && !$isOpenRouter),
            reasoningEffort: !$isGrok
                && !$isZai
                && !$isMoonshot
                && !$isTogether
                && !$isCloudflareAiGateway
                && !$isNvidia
                && !$isAntLing,
            maxTokensField: $useMaxTokens ? 'max_tokens' : 'max_completion_tokens',
            toolResultName: $isMistral,
            // Upstream detects `false` for every endpoint. Kept as a flag because a `compat`
            // block can still ask for it.
            assistantAfterToolResult: false,
            thinkingAsText: $isMistral,
            mistralToolIds: $isMistral,
            // Upstream's `requiresReasoningContentOnAssistantMessages: isDeepSeek`. DeepSeek's
            // thinking mode answers a replayed assistant turn without `reasoning_content` with a
            // 400 — and pig only ever writes that field when the turn had thinking to put in it.
            reasoningContentOnAssistantMessages: $isDeepSeek,
            // Upstream: "OpenAI compatibility alone does not imply strict JSON-schema tool
            // support." The built-in models that have it say so in their own `compat`
            // (`Models`), as upstream's generated catalogue does.
            strictMode: false,
            thinkingFormat: match (true) {
                $isDeepSeek => 'deepseek',
                $isZai => 'zai',
                $isTogether => 'together',
                $isAntLing => 'ant-ling',
                $isOpenRouter => 'openrouter',
                default => 'openai',
            },
            chatTemplateKwargs: [],
            chatTemplateArgs: [],
            openRouterRouting: [],
            vercelGatewayRouting: [],
            // Upstream: `supportsOpenAIGrammarTools: false`, and only its generator turns it on.
            grammarTools: false,
        );
    }
}
