<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * The ways an "OpenAI-compatible" endpoint is not.
 *
 * Several providers speak `openai-completions`, and every one of them differs somewhere:
 * Grok rejects `reasoning_effort`, Cerebras rejects `store`, z.ai streams tool calls only when
 * asked with `tool_stream`, Copilot wants assistant text as a string rather than an array. None of that is in anyone's documentation as a difference; it is
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
     *
     * The keys below are about caching and session affinity. `sessionAffinityFormat` and
     * `supportsLongCacheRetention` are both APIs' keys: for `openai-completions` `detect()` works
     * them out as upstream's `detectCompat()` does and `resolve()` lays the model's own over that;
     * `OpenAiResponses` reads the model's own compat with its runtime default, as upstream's
     * Responses `getCompat()` does. `sendSessionAffinityHeaders` and `cacheControlFormat` are
     * completions keys; `supportsExplicitPromptCacheMode` and `supportsMaxOutputTokens` are Responses
     * keys, which `detect()` says nothing about.
     *
     * @param string|null $sessionAffinityFormat `openai` sends the session id as `session_id` and
     *        `x-client-request-id`, `openai-nosession` as `x-client-request-id` alone, `openrouter`
     *        as `x-session-id` (default: `openrouter` for OpenRouter, else `openai`); on completions
     *        `x-session-affinity` goes with `x-client-request-id`, and only when
     *        `sendSessionAffinityHeaders` says so
     * @param bool|null   $supportsLongCacheRetention `cacheRetention: long` may ask for the longer
     *        prompt cache — `prompt_cache_retention: "24h"`, or Anthropic-style `ttl: "1h"` (default
     *        true; on completions false for Together, Cloudflare, NVIDIA and Ant Ling)
     * @param bool|null   $supportsExplicitPromptCacheMode the model takes `prompt_cache_options`
     *        (OpenAI GPT-5.6 and later; default false, `Models` sets it as upstream's generator does)
     * @param bool|null   $supportsMaxOutputTokens the endpoint takes `max_output_tokens` (default true)
     * @param bool|null   $sendSessionAffinityHeaders upstream's completions key: the session id goes
     *        out as headers in `sessionAffinityFormat`'s shape (detected: OpenRouter only)
     * @param string|null $cacheControlFormat upstream's completions key: `anthropic` puts Anthropic's
     *        `cache_control` on the system prompt, the last tool and the last conversation text
     *        (detected: an OpenRouter `anthropic/…` model)
     *
     * The keys below are upstream's `OpenAICompletionsCompatSchema` keys that pig had no field for:
     *
     * @param bool|null   $supportsUsageInStreaming `stream_options: {include_usage: true}` is sent
     *        (default true; only an explicit false leaves it out)
     * @param bool|null   $supportsFinishReason streamed chunks carry `finish_reason` (default true).
     *        When false, a stream that ends without one is `toolUse` if it made a call, else `stop`;
     *        when true, a stream that ends without one is an error
     * @param bool|null   $zaiToolStream     z.ai takes a top-level `tool_stream: true` to stream tool-call
     *        deltas (default false; `Models` sets it on z.ai's models as upstream's generator does)
     * @param string|null $thinkingTokenBudgetField the top-level field that caps reasoning tokens —
     *        `thinking_token_budget` (vLLM), `thinking_budget` (Qwen, DashScope, SGLang) or
     *        `thinking_budget_tokens` (llama.cpp); off by default and never set on a built-in model
     * @param bool|null   $supportsThinkingTokenBudget upstream's alias for
     *        `thinkingTokenBudgetField: "thinking_token_budget"`
     * @param int|float|null $vllmPriority vLLM's scheduler priority, sent as the top-level `priority`
     *        field; only the model's own (never detected)
     * @param bool|null   $supportsMidConvoSystemMessages the model takes a system message after the
     *        conversation has started (both APIs; default false). Carried and sent on — pig's
     *        transcript has no mid-conversation system messages for it to decide anything about
     * @param bool|null   $supportsMidConvoToolAdditions such a message may add tools (completions;
     *        default false). Carried, for the same reason
     * @param bool|null   $supportsToolSearch the model takes client-executed tool search (Responses;
     *        default false). Carried — pig has no tool search
     * @param bool|null   $supportsAdditionalTools the model takes message-anchored `additional_tools`
     *        items (Responses; default false). Carried — pig has no mid-conversation tool additions
     */
    public function __construct(
        public ?bool $store = null,
        public ?bool $developerRole = null,
        public ?bool $reasoningEffort = null,
        public ?string $maxTokensField = null,
        public ?bool $toolResultName = null,
        public ?bool $assistantAfterToolResult = null,
        public ?bool $thinkingAsText = null,
        public ?bool $reasoningContentOnAssistantMessages = null,
        public ?bool $strictMode = null,
        public ?string $thinkingFormat = null,
        public ?array $chatTemplateKwargs = null,
        public ?array $chatTemplateArgs = null,
        public ?array $openRouterRouting = null,
        public ?array $vercelGatewayRouting = null,
        public ?bool $grammarTools = null,
        public ?string $sessionAffinityFormat = null,
        public ?bool $supportsLongCacheRetention = null,
        public ?bool $supportsExplicitPromptCacheMode = null,
        public ?bool $supportsMaxOutputTokens = null,
        public ?bool $sendSessionAffinityHeaders = null,
        public ?string $cacheControlFormat = null,
        public ?bool $supportsUsageInStreaming = null,
        public ?bool $supportsFinishReason = null,
        public ?bool $zaiToolStream = null,
        public ?string $thinkingTokenBudgetField = null,
        public ?bool $supportsThinkingTokenBudget = null,
        public int|float|null $vllmPriority = null,
        public ?bool $supportsMidConvoSystemMessages = null,
        public ?bool $supportsMidConvoToolAdditions = null,
        public ?bool $supportsToolSearch = null,
        public ?bool $supportsAdditionalTools = null,
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
            sessionAffinityFormat: $explicit->sessionAffinityFormat ?? $detected->sessionAffinityFormat,
            supportsLongCacheRetention: $explicit->supportsLongCacheRetention ?? $detected->supportsLongCacheRetention,
            supportsExplicitPromptCacheMode: $explicit->supportsExplicitPromptCacheMode,
            supportsMaxOutputTokens: $explicit->supportsMaxOutputTokens,
            sendSessionAffinityHeaders: $explicit->sendSessionAffinityHeaders ?? $detected->sendSessionAffinityHeaders,
            cacheControlFormat: $explicit->cacheControlFormat ?? $detected->cacheControlFormat,
            supportsUsageInStreaming: $explicit->supportsUsageInStreaming ?? $detected->supportsUsageInStreaming,
            supportsFinishReason: $explicit->supportsFinishReason ?? $detected->supportsFinishReason,
            zaiToolStream: $explicit->zaiToolStream ?? $detected->zaiToolStream,
            thinkingTokenBudgetField: $explicit->thinkingTokenBudgetField ?? $detected->thinkingTokenBudgetField,
            supportsThinkingTokenBudget: $explicit->supportsThinkingTokenBudget ?? $detected->supportsThinkingTokenBudget,
            // Upstream: `vllmPriority: model.compat.vllmPriority` — the one key besides
            // `openRouterRouting` that detection has no value for.
            vllmPriority: $explicit->vllmPriority,
            supportsMidConvoSystemMessages: $explicit->supportsMidConvoSystemMessages ?? $detected->supportsMidConvoSystemMessages,
            supportsMidConvoToolAdditions: $explicit->supportsMidConvoToolAdditions ?? $detected->supportsMidConvoToolAdditions,
            // Responses keys, which completions detection says nothing about.
            supportsToolSearch: $explicit->supportsToolSearch,
            supportsAdditionalTools: $explicit->supportsAdditionalTools,
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
     * Mistral is not here: it speaks its own `mistral-conversations` API (`Providers\Mistral`), as
     * upstream's does, and upstream's `detectCompat()` no longer names it. pig used to keep the
     * rules upstream's detection had for `api.mistral.ai` before the move — non-standard,
     * `max_tokens`, the tool result's name, thinking as text, nine-character tool ids.
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
            || $isAntLing;

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
            || $isZai;

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
            toolResultName: false,
            // Upstream detects `false` for every endpoint. Kept as a flag because a `compat`
            // block can still ask for it.
            assistantAfterToolResult: false,
            thinkingAsText: false,
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
            sessionAffinityFormat: $isOpenRouter ? 'openrouter' : 'openai',
            supportsLongCacheRetention: !($isTogether || $isCloudflareWorkersAi || $isCloudflareAiGateway || $isNvidia || $isAntLing),
            sendSessionAffinityHeaders: $isOpenRouter,
            cacheControlFormat: $provider === 'openrouter' && str_starts_with($modelId, 'anthropic/') ? 'anthropic' : null,
            supportsUsageInStreaming: true,
            supportsFinishReason: true,
            zaiToolStream: false,
            thinkingTokenBudgetField: null,
            supportsThinkingTokenBudget: false,
            supportsMidConvoSystemMessages: false,
            supportsMidConvoToolAdditions: false,
        );
    }
}
