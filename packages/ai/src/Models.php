<?php

declare(strict_types=1);

namespace Pig\Ai;


/**
 * Every model this can talk to, by provider and id.
 *
 * Every model whose **protocol is ported** — a model that could be selected and then not talked to
 * is a worse answer than "no such model". Chat models are `all()`/`find()`; the classifier and image
 * models are tables of their own (`CLASSIFIER_MODELS`, `IMAGE_MODELS`, reached by `findOfType()` and
 * `allOfType()` and used through `classify()` and `generateImages()`), as upstream keeps "chat and
 * classifier entries with the same provider and id separate".
 *
 * ### The rows are generated; everything around them is not
 *
 * Each table below carries a `>>> generated` / `<<< generated` pair, and
 * `scripts/generate-models.php` replaces what lies between them from models.dev. Everything else
 * here — the base URLs, `RESOLD`, Copilot's headers, the two subscription tables, `table()` — is
 * hand-written and stays that way.
 *
 * **This used to be a transcription, and the docblock called the freeze fidelity**: *"the figures
 * are upstream's at the anchor commit, which is the source a port should agree with rather than
 * whatever models.dev says today"*. That is fidelity to the bytes and not to the mechanism.
 * Upstream has never kept this table by hand — the file pig copied from opens with *"This file is
 * auto-generated … Do not edit manually - run 'npm run generate-models' to update"*, and by
 * upstream HEAD the data is not in git at all. So pig froze a generator's output and then treated
 * the freeze as a decision, and the bill arrived twice: Groq answered `404 … does not exist` for a
 * row `--list-models` still offered, and `gemini-3.8-flash` had to be hand-declared in
 * `models.json` to be reachable. **Neither would have been fixed by moving the port anchor**,
 * because upstream HEAD has no table to move to.
 *
 * So the pin that remains is the one that means something: `d0a4c37` decides every line of
 * *protocol*, and the registry goes back to being what it always was — a directory of what
 * providers currently sell, regenerated when somebody wants it current.
 *
 * ### What the tables can and cannot say
 *
 * Three shapes, and the generator writes what is there rather than widening them:
 * `ANTHROPIC_MODELS` has no images column, because everything Anthropic sells takes images — an
 * assumption the generator **checks and complains about** rather than leaving to be wrong one day;
 * `COPILOT_MODELS` and `AZURE_MODELS` carry an api per row before the rest, because Copilot serves
 * three APIs (Anthropic's for its Claude models) and Azure two (Chat Completions for DeepSeek), and so
 * do the `CATALOGUE_PROVIDERS` tables of Fireworks, both OpenCodes and OpenRouter; the others carry
 * the full nine columns.
 *
 * Adding a provider is adding a table, one line in `table()` (or in `CATALOGUE_PROVIDERS`) and one
 * row in the generator's `DIRECT` — not changing the rest.
 *
 * `ModelsTest` pins a row per provider and the collision rule, so a regeneration that moved
 * something load-bearing is a red test rather than a surprise.
 *
 * Ported from upstream's `models.ts`; the rows come from where upstream's come from.
 */
final class Models
{
    public const string ANTHROPIC = 'anthropic';

    public const string COPILOT = 'github-copilot';

    public const string GOOGLE_VERTEX = 'google-vertex';

    public const string AMAZON_BEDROCK = 'amazon-bedrock';

    public const string AZURE = 'azure';

    public const string OPENAI_CODEX = 'openai-codex';

    public const string RADIUS = 'radius';

    /** Upstream's generator `CODEX_BASE_URL`. */
    private const string OPENAI_CODEX_BASE_URL = 'https://chatgpt.com/backend-api';

    /** The `baseUrl` of the Radius gateway's `/v1/config` (`getRadiusModelsFromConfig()` gives it to every row). */
    public const string RADIUS_BASE_URL = 'https://radius.pi.dev/v1';

    /** Upstream's generator `VERTEX_BASE_URL`, a template `Providers\GoogleVertex` fills from the location. */
    private const string VERTEX_BASE_URL = 'https://{location}-aiplatform.googleapis.com';

    private const string ANTHROPIC_BASE_URL = 'https://api.anthropic.com';

    /**
     * Where Copilot answers for an ordinary account.
     *
     * A token carries the real one: `proxy-ep=proxy.individual.githubcopilot.com` in its
     * claims becomes `api.individual.…`, and an enterprise install answers at
     * `copilot-api.<domain>` instead. So this is the default and not the truth — which is
     * also why Copilot's models carry their `compat` explicitly rather than letting
     * `OpenAiCompat::detect()` work it out from the host: two of the three URLs it can end
     * up with contain no `githubcopilot.com` at all.
     */
    private const string COPILOT_BASE_URL = 'https://api.individual.githubcopilot.com';

    /**
     * Who Copilot is told it is talking to.
     *
     * Upstream's four headers, copied. They are not decoration: the endpoint is VS Code's,
     * and it answers a request that does not claim to be VS Code with a 4xx.
     */
    private const array COPILOT_HEADERS = [
        'User-Agent' => 'GitHubCopilotChat/0.35.0',
        'Editor-Version' => 'vscode/1.107.0',
        'Editor-Plugin-Version' => 'copilot-chat/0.35.0',
        'Copilot-Integration-Id' => 'vscode-chat',
    ];

    /**
     * Providers that answer with somebody else's models, under somebody else's ids.
     *
     * Copilot serves OpenAI's, Anthropic's and Google's models at their own ids, so `gpt-5`
     * now names two things. **A bare id means the direct provider**; Copilot's is reached as
     * `github-copilot/gpt-5`. Written down as a rule rather than left to the order the tables
     * happen to be built in — that gave the same answer and would have stopped doing so the
     * first time somebody moved a `foreach`.
     *
     * **Vertex AI is here for the same reason**: it serves Google's Gemini models under Google's own
     * ids, so `gemini-2.5-flash` names two things again and the bare id stays the Gemini API's; Vertex's
     * is `google-vertex/gemini-2.5-flash`. Bedrock is not — its ids carry their vendor
     * (`anthropic.claude-…`) and collide with nobody's.
     *
     * **Azure, ChatGPT's Codex backend and the Radius gateway too**: Azure's table is a clone of
     * OpenAI's, Codex serves OpenAI's models and Radius OpenAI's and Anthropic's, all under the ids their
     * makers use — so `gpt-5.4` stays OpenAI's and `azure/gpt-5.4` is Azure's. An id only one of them
     * has (Radius's `balanced`) is still found, as a resold id always is.
     *
     * **And every provider of `CATALOGUE_PROVIDERS` that serves another maker's models**: the gateways
     * (OpenRouter, Vercel's AI Gateway, OpenCode Zen and Go), the hosts (Together, Fireworks, Baseten,
     * Hugging Face, NVIDIA), the Token Plans (Qwen's three, which serve GLM, DeepSeek and Kimi as well,
     * and Xiaomi's), and the China endpoints of a maker whose own endpoint is here too (MiniMax,
     * Moonshot, z.ai). So `kimi-k2.6` is Moonshot's, `glm-5.3` z.ai's, `mimo-v2.5-pro` Xiaomi's, and
     * `openrouter/moonshotai/kimi-k2.6` OpenRouter's. The makers' own — DeepSeek, Ant Ling, Meta,
     * MiniMax, Moonshot, Xiaomi, Kimi For Coding — are not. This is pig's rule, not upstream's: pi
     * refuses a bare id more than one provider claims (`findExactModelReferenceMatch()`) and falls
     * through to its partial matching.
     */
    private const array RESOLD = [
        self::COPILOT,
        self::GOOGLE_VERTEX,
        self::AZURE,
        self::OPENAI_CODEX,
        self::RADIUS,
        'baseten',
        'cloudflare-ai-gateway',
        'cloudflare-workers-ai',
        'fireworks',
        'huggingface',
        'minimax-cn',
        'moonshotai-cn',
        'nvidia',
        'opencode',
        'opencode-go',
        'openrouter',
        'qwen-token-plan',
        'qwen-token-plan-cn',
        'qwen-token-plan-individual',
        'together',
        'vercel-ai-gateway',
        'xiaomi-token-plan-ams',
        'xiaomi-token-plan-cn',
        'xiaomi-token-plan-sgp',
        'zai-coding-cn',
    ];

    /**
     * Upstream's generator `OPENAI_CODEX_ADDITIONAL_TOOLS_MODEL_IDS`: the Codex models that take
     * message-anchored `additional_tools` (`applyOpenAIToolSearchMetadata()`).
     */
    private const array OPENAI_CODEX_ADDITIONAL_TOOLS_MODEL_IDS = [
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-6-astra',
        'gpt-6-sol',
        'gpt-6-luna',
        'gpt-6.1-sol',
    ];

    /**
     * Upstream's generator `DEEPSEEK_V4_THINKING_LEVEL_MAP`, `DEEPSEEK_V4_FLASH_THINKING_LEVEL_MAP` and
     * `AZURE_DEEPSEEK_V4_THINKING_LEVEL_MAP` ("Azure Foundry rejects DeepSeek's own max effort"), for
     * `applyThinkingLevelMetadata()`'s DeepSeek V4 arm.
     */
    private const array DEEPSEEK_V4_THINKING_LEVEL_MAP = ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'max' => 'max'];

    private const array DEEPSEEK_V4_FLASH_THINKING_LEVEL_MAP = ['minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'max' => 'max'];

    private const array AZURE_DEEPSEEK_V4_THINKING_LEVEL_MAP = ['minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null];

    /**
     * Upstream's generator `GITHUB_COPILOT_THINKING_LEVEL_OVERRIDES`, copied with its comment:
     * "Checked manually against the authenticated GitHub Copilot /models endpoint on 2026-06-15.
     * Keep this to narrow corrections over models.dev metadata instead of snapshotting Copilot's
     * catalog."
     *
     * @var array<string, array<string, string|null>>
     */
    private const array COPILOT_THINKING_LEVEL_OVERRIDES = [
        'claude-opus-4.7' => ['minimal' => 'low'],
        'claude-opus-4.8' => ['minimal' => 'low'],
        'claude-opus-5' => ['minimal' => 'low'],
        'claude-sonnet-4.6' => ['minimal' => 'low', 'max' => 'max'],
    ];

    /**
     * Upstream's generator `OPENAI_RESPONSES_NONE_REASONING_MODELS`: the `openai` Responses models
     * whose `off` is the effort `none` (`applyThinkingLevelMetadata()`).
     */
    private const array OPENAI_RESPONSES_NONE_REASONING_MODELS = [
        'gpt-5.1',
        'gpt-5.2',
        'gpt-5.3-codex',
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.4-nano',
        'gpt-5.5',
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-6-sol',
        'gpt-6-luna',
    ];

    /**
     * Upstream's generator `ANTHROPIC_ALLOWED_FALLBACK_MODELS`: model => the models Anthropic may
     * answer with in its place (server-side refusal fallback). Each becomes an
     * `AnthropicCompat::$allowedFallbackModels` entry with the fallback row's own price, and for a
     * `supportsMidConvoEffort` model only fallbacks that are managed-effort too — so Opus 5's single
     * candidate, Opus 4.8, is dropped and Opus 5 sends no `fallbacks` at all.
     */
    private const array ANTHROPIC_ALLOWED_FALLBACK_MODELS = [
        'claude-fable-5' => ['claude-opus-4-8', 'claude-opus-5'],
        'claude-opus-5' => ['claude-opus-4-8'],
    ];

    /**
     * Upstream's generator `ANTHROPIC_PROMPT_CACHE`, seconds per retention tier: "Anthropic ephemeral
     * entries have a hard five-minute lifetime; `ttl: "1h"` extends it to one hour. Only direct
     * Anthropic is annotated so cache warming does not assume equivalent behavior through proxies."
     */
    private const array ANTHROPIC_PROMPT_CACHE = ['short' => 300, 'long' => 3600];

    /** Upstream's generator `DEFAULT_IMAGE_RESIZE`: 2000 × 2000, 4.5 MiB, JPEG quality 80. */
    private const array DEFAULT_IMAGE_RESIZE = [
        'maxWidth' => 2000,
        'maxHeight' => 2000,
        'maxBytes' => 4_718_592,
        'jpegQuality' => 80,
    ];

    /** provider => where its OpenAI-compatible endpoint lives. */
    private const array OPENAI_COMPATIBLE = [
        'cerebras' => 'https://api.cerebras.ai/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'zai' => 'https://api.z.ai/api/coding/paas/v4',
    ];

    /** Upstream's `xaiProvider()` `baseUrl`, which its generator writes on every xAI row. */
    private const string XAI_BASE_URL = 'https://api.x.ai/v1';

    /**
     * Upstream's generator `XAI_RESPONSES_COMPAT`, the compat its `loadModelsDevData()` writes on every
     * xAI row: xAI's Responses API takes no `prompt_cache_retention`.
     */
    private const array XAI_RESPONSES_COMPAT = ['supportsLongCacheRetention' => false];

    /**
     * Where Mistral's own API lives — upstream's generator writes `baseUrl: "https://api.mistral.ai"`
     * and `api: "mistral-conversations"` on every Mistral model; `Providers\Mistral` adds
     * `/v1/chat/completions`. pig used to send these models to `/v1` on the OpenAI-compatible API.
     */
    private const string MISTRAL_BASE_URL = 'https://api.mistral.ai';

    /**
     * Upstream's generator `ZAI_TOOL_STREAM_UNSUPPORTED_MODELS`: every other z.ai model gets
     * `zaiToolStream: true` in its compat, so its tool calls stream (`tool_stream: true`).
     */
    private const array ZAI_TOOL_STREAM_UNSUPPORTED_MODELS = ['glm-4.5', 'glm-4.5-air', 'glm-4.5-flash', 'glm-4.5v'];

    /**
     * Upstream's generator `OPENAI_TOOL_SEARCH_MODEL_IDS`, which is also its
     * `OPENAI_ADDITIONAL_TOOLS_MODEL_IDS` and `OPENAI_MID_CONVO_SYSTEM_MESSAGE_MODEL_IDS`: the OpenAI
     * models that take client-executed tool search, message-anchored `additional_tools`, and system
     * messages after the conversation has started. See `transcriptCompat()`.
     */
    private const array OPENAI_TOOL_SEARCH_MODEL_IDS = [
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.4-pro',
        'gpt-5.5',
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-6-astra',
        'gpt-6-sol',
        'gpt-6-luna',
        'gpt-6.1-sol',
    ];

    /**
     * id => [name, context window, max tokens, reasoning, $/Mtok in, out, cache read, cache write]
     *
     * A table rather than 21 constructor calls: the shape is the same every time, and a
     * column that is wrong is easier to see in a column than in a paragraph.
     *
     * A row priced in tiers carries them under the key `tiers`, in every table: one
     * `[input tokens above, in, out, cache read, cache write]` per tier — upstream's `cost.tiers`.
     * And a row whose models.dev entry lists verified efforts carries their map under the key
     * `effortLevelMap`, in every table — the generator's `getEffortThinkingLevelMap()`, which
     * `thinkingLevelMap()` merges where upstream's `applyModelsDevReasoningOptionMetadata()` would.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: float, 5: float, 6: float, 7: float, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>, effortLevelMap?: array<string, string|null>}>
     */
    private const array ANTHROPIC_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'claude-fable-5' => ['Claude Fable 5', 1_000_000, 128_000, true, 10.0, 50.0, 1.0, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-fable-5-1' => ['Claude Fable 5.1', 1_000_000, 128_000, true, 10.0, 50.0, 0.25, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-haiku-4-5' => ['Claude Haiku 4.5 (latest)', 200_000, 64_000, true, 1.0, 5.0, 0.1, 1.25],
        'claude-haiku-4-5-20251001' => ['Claude Haiku 4.5', 200_000, 64_000, true, 1.0, 5.0, 0.1, 1.25],
        'claude-haiku-5-5' => ['Claude Haiku 5.5', 1_000_000, 128_000, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4-5' => ['Claude Opus 4.5 (latest)', 200_000, 64_000, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'claude-opus-4-5-20251101' => ['Claude Opus 4.5', 200_000, 64_000, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'claude-opus-4-6' => ['Claude Opus 4.6', 1_000_000, 128_000, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-opus-4-7' => ['Claude Opus 4.7', 1_000_000, 128_000, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4-8' => ['Claude Opus 4.8', 1_000_000, 128_000, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5' => ['Claude Opus 5', 1_000_000, 128_000, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5-5' => ['Claude Opus 5.5', 1_000_000, 128_000, true, 4.0, 20.0, 0.2, 5.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5 (latest)', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-4-5-20250929' => ['Claude Sonnet 4.5', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-4-6' => ['Claude Sonnet 4.6', 1_000_000, 128_000, true, 3.0, 15.0, 0.3, 3.75, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-sonnet-5' => ['Claude Sonnet 5', 1_000_000, 128_000, true, 2.0, 10.0, 0.2, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 1_000_000, 128_000, true, 2.0, 10.0, 0.1, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        // <<< generated
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array CEREBRAS_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'gpt-oss-120b' => ['GPT OSS 120B', 131_072, 40_960, true, false, 0.35, 0.75, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'qwen-3.8-27b' => ['Qwen3.8 27B', 131_072, 40_960, true, true, 0.99, 1.49, 0.99, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array GROQ_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'llama-3.1-8b-instant' => ['Llama 3.1 8B', 131_072, 131_072, false, false, 0.05, 0.08, 0.0, 0.0],
        'llama-3.3-70b-versatile' => ['Llama 3.3 70B', 131_072, 32_768, false, false, 0.59, 0.79, 0.0, 0.0],
        'openai/gpt-oss-120b' => ['GPT OSS 120B', 131_072, 65_536, true, false, 0.15, 0.6, 0.075, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-20b' => ['GPT OSS 20B', 131_072, 65_536, true, false, 0.075, 0.3, 0.0375, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-safeguard-20b' => ['Safety GPT OSS 20B', 131_072, 65_536, true, false, 0.075, 0.3, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'qwen/qwen3.6-27b' => ['Qwen3.6 27B', 131_072, 16_384, true, true, 0.6, 3.0, 0.3, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => null, 'xhigh' => null, 'max' => null]],
        'qwen/qwen3.8-27b' => ['Qwen3.8 27B', 131_042, 16_384, true, true, 0.8, 4.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /**
     * Mistral's own API, `mistral-conversations` (`Providers\Mistral`), as upstream's generator
     * routes them. A reasoning row's `thinkingLevelMap` is its verified efforts — "Models with
     * effort values use `reasoning_effort` with these levels. Reasoning models without them
     * (Magistral) use `prompt_mode`." The maps and `mistral-medium-3.5` (upstream's hand-added row)
     * were written from upstream's published catalogue (`@earendil-works/pi-ai` 1.1.0) as models.dev
     * was not reachable; the rest of each row is the last regeneration's, so the generator's
     * tenth-of-input cache-read price for a model models.dev gives none arrives with the next one.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, thinkingLevelMap?: array<string, string|null>}>
     */
    private const array MISTRAL_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'codestral-2508' => ['Codestral 25.08', 256_000, 8_192, false, false, 0.3, 0.9, 0.03, 0.0],
        'codestral-latest' => ['Codestral (latest)', 256_000, 4_096, false, false, 0.3, 0.9, 0.03, 0.0],
        'devstral-2512' => ['Devstral 2', 262_144, 262_144, false, false, 0.4, 2.0, 0.04, 0.0],
        'devstral-latest' => ['Devstral 2', 262_144, 262_144, false, false, 0.4, 2.0, 0.04, 0.0],
        'devstral-medium-2507' => ['Devstral Medium', 128_000, 128_000, false, false, 0.4, 2.0, 0.04, 0.0],
        'devstral-medium-latest' => ['Devstral 2 (latest)', 262_144, 262_144, false, false, 0.4, 2.0, 0.04, 0.0],
        'devstral-small-2505' => ['Devstral Small 2505', 128_000, 128_000, false, false, 0.1, 0.3, 0.01, 0.0],
        'devstral-small-2507' => ['Devstral Small', 128_000, 128_000, false, false, 0.1, 0.3, 0.01, 0.0],
        'glm-5-2' => ['GLM-5.2', 1_048_576, 131_072, true, false, 1.4, 4.4, 0.14, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'labs-devstral-small-2512' => ['Devstral Small 2', 256_000, 256_000, false, true, 0.0, 0.0, 0.0, 0.0],
        'labs-leanstral-1-5-1' => ['Leanstral 1.5', 262_144, 128_000, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'magistral-medium-latest' => ['Magistral Medium (latest)', 262_144, 16_384, true, true, 2.0, 5.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'ministral-14b-2512' => ['Ministral 3 14B', 262_144, 262_144, false, true, 0.2, 0.2, 0.02, 0.0],
        'ministral-3b-2512' => ['Ministral 3 3B', 131_072, 262_144, false, true, 0.1, 0.1, 0.01, 0.0],
        'ministral-3b-latest' => ['Ministral 3B (latest)', 131_072, 128_000, false, true, 0.04, 0.04, 0.004, 0.0],
        'ministral-8b-2512' => ['Ministral 3 8B', 262_144, 262_144, false, true, 0.15, 0.15, 0.015, 0.0],
        'ministral-8b-latest' => ['Ministral 8B (latest)', 262_144, 128_000, false, true, 0.1, 0.1, 0.01, 0.0],
        'mistral-large-2411' => ['Mistral Large 2.1', 131_072, 16_384, false, false, 2.0, 6.0, 0.2, 0.0],
        'mistral-large-2512' => ['Mistral Large 3', 262_144, 262_144, false, true, 0.5, 1.5, 0.05, 0.0],
        'mistral-large-4' => ['Mistral Large 4', 1_048_576, 262_144, true, true, 0.68, 2.09, 0.07, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistral-large-latest' => ['Mistral Large (latest)', 262_144, 262_144, false, true, 0.5, 1.5, 0.05, 0.0],
        'mistral-medium-2505' => ['Mistral Medium 3', 131_072, 131_072, false, true, 0.4, 2.0, 0.04, 0.0],
        'mistral-medium-2508' => ['Mistral Medium 3.1', 262_144, 262_144, false, true, 0.4, 2.0, 0.04, 0.0],
        'mistral-medium-2604' => ['Mistral Medium 3.5', 262_144, 262_144, true, true, 1.5, 7.5, 0.15, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistral-medium-3.5' => ['Mistral Medium 3.5', 262_144, 262_144, true, true, 1.5, 7.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistral-medium-latest' => ['Mistral Medium (latest)', 262_144, 262_144, true, true, 1.5, 7.5, 0.15, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistral-nemo' => ['Mistral Nemo', 128_000, 128_000, false, false, 0.15, 0.15, 0.015, 0.0],
        'mistral-small-2506' => ['Mistral Small 3.2', 128_000, 16_384, false, true, 0.1, 0.3, 0.01, 0.0],
        'mistral-small-2603' => ['Mistral Small 4', 262_144, 256_000, true, true, 0.15, 0.6, 0.015, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistral-small-latest' => ['Mistral Small (latest)', 262_144, 256_000, true, true, 0.15, 0.6, 0.015, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'open-mistral-7b' => ['Mistral 7B', 8_000, 8_000, false, false, 0.25, 0.25, 0.025, 0.0],
        'open-mistral-nemo' => ['Open Mistral Nemo', 128_000, 128_000, false, false, 0.15, 0.15, 0.015, 0.0],
        'open-mixtral-8x22b' => ['Mixtral 8x22B', 64_000, 64_000, false, false, 2.0, 6.0, 0.2, 0.0],
        'open-mixtral-8x7b' => ['Mixtral 8x7B', 32_000, 32_000, false, false, 0.7, 0.7, 0.07, 0.0],
        'pixtral-12b' => ['Pixtral 12B', 128_000, 128_000, false, true, 0.15, 0.15, 0.015, 0.0],
        'pixtral-large-latest' => ['Pixtral Large (latest)', 128_000, 128_000, false, true, 2.0, 6.0, 0.2, 0.0],
        'voxtral-small-2507' => ['Voxtral Small', 32_768, 32_000, false, false, 0.1, 0.4, 0.01, 0.0],
        'voxtral-small-latest' => ['Voxtral Small (latest)', 32_768, 32_000, false, false, 0.1, 0.3, 0.01, 0.0],
        'zai-glm-5-2' => ['GLM-5.2', 1_048_576, 131_072, true, false, 1.4, 4.4, 0.14, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'zai-glm-5-3' => ['GLM-5.3', 1_048_576, 131_072, true, false, 1.4, 4.4, 0.14, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array XAI_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'grok-4.3' => ['Grok 4.3', 1_000_000, 30_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'grok-4.5' => ['Grok 4.5', 500_000, 500_000, true, true, 2.0, 6.0, 0.3, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 0.6, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'grok-4.6' => ['Grok 4.6', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'grok-4.7' => ['Grok 4.7', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        // <<< generated
    ];

    /**
     * z.ai's coding plan. The generator reads models.dev's `zai-coding-plan` entry for these (the
     * plan's own list, priced from `zai` where that lists the model) and writes each one's verified
     * efforts as its `thinkingLevelMap` — GLM-5.2's with `off: "none"`. The rows below are from before
     * that, so they carry no maps and list the pay-as-you-go API's models; the next regeneration
     * replaces them. `table()` gives a row with a map `supportsReasoningEffort`, as upstream does.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, thinkingLevelMap?: array<string, string|null>}>
     */
    private const array ZAI_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'glm-4.7' => ['GLM-4.7', 204_800, 131_072, true, false, 0.6, 2.2, 0.11, 0.0],
        'glm-5-turbo' => ['GLM-5-Turbo', 200_000, 131_072, true, false, 1.2, 4.0, 0.24, 0.0],
        'glm-5.2' => ['GLM-5.2', 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.2-highspeed' => ['GLM-5.2 Highspeed', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3' => ['GLM-5.3', 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3-flash' => ['GLM-5.3-Flash', 1_000_000, 131_072, true, true, 0.15, 0.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3-highspeed' => ['GLM-5.3 Highspeed', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * OpenAI's own, which speak the Responses API rather than chat-completions.
     *
     * The windows of gpt-5.4, gpt-5.5, the GPT-5.6 trio and GPT-6 are the generator's 272k cap
     * (`openAiTemporaryOverrides()`, upstream's `OPENAI_SHORT_CONTEXT_CAPPED_MODEL_IDS`) and not
     * models.dev's 1,050,000 — the window is where compaction fires, so a conversation is compacted
     * before it reaches OpenAI's long-context price, which the `tiers` on those rows record.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>, effortLevelMap?: array<string, string|null>}>
     */
    private const array OPENAI_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'gpt-4' => ['GPT-4', 8_192, 8_192, false, false, 30.0, 60.0, 0.0, 0.0],
        'gpt-4-turbo' => ['GPT-4 Turbo', 128_000, 4_096, false, true, 10.0, 30.0, 0.0, 0.0],
        'gpt-4.1' => ['GPT-4.1', 1_047_576, 32_768, false, true, 2.0, 8.0, 0.5, 0.0],
        'gpt-4.1-mini' => ['GPT-4.1 mini', 1_047_576, 32_768, false, true, 0.4, 1.6, 0.1, 0.0],
        'gpt-4.1-nano' => ['GPT-4.1 nano', 1_047_576, 32_768, false, true, 0.1, 0.4, 0.025, 0.0],
        'gpt-4o' => ['GPT-4o', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-2024-05-13' => ['GPT-4o (2024-05-13)', 128_000, 4_096, false, true, 5.0, 15.0, 0.0, 0.0],
        'gpt-4o-2024-08-06' => ['GPT-4o (2024-08-06)', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-2024-11-20' => ['GPT-4o (2024-11-20)', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-mini' => ['GPT-4o mini', 128_000, 16_384, false, true, 0.15, 0.6, 0.075, 0.0],
        'gpt-5' => ['GPT-5', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-chat-latest' => ['GPT-5 Chat Latest', 128_000, 16_384, false, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5-mini' => ['GPT-5 Mini', 400_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-nano' => ['GPT-5 Nano', 400_000, 128_000, true, true, 0.05, 0.4, 0.005, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-pro' => ['GPT-5 Pro', 400_000, 128_000, true, true, 15.0, 120.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.1' => ['GPT-5.1', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.2' => ['GPT-5.2', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.2-chat-latest' => ['GPT-5.2 Chat', 128_000, 16_384, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => null, 'xhigh' => null, 'max' => null]],
        'gpt-5.2-pro' => ['GPT-5.2 Pro', 400_000, 128_000, true, true, 21.0, 168.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.3-chat-latest' => ['GPT-5.3 Chat (latest)', 128_000, 16_384, false, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.3-codex' => ['GPT-5.3 Codex', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.3-codex-spark' => ['GPT-5.3 Codex Spark', 128_000, 32_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4' => ['GPT-5.4', 272_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'tiers' => [[272_000, 5.0, 22.5, 0.5, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-mini' => ['GPT-5.4 mini', 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-nano' => ['GPT-5.4 nano', 400_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-pro' => ['GPT-5.4 Pro', 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'tiers' => [[272_000, 60.0, 270.0, 0.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5' => ['GPT-5.5', 272_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5-pro' => ['GPT-5.5 Pro', 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'tiers' => [[272_000, 60.0, 270.0, 0.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', 272_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-sol' => ['GPT-5.6 Sol', 272_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-terra' => ['GPT-5.6 Terra', 272_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-astra' => ['GPT-6 Astra', 272_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-luna' => ['GPT-6 Luna', 272_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-sol' => ['GPT-6 Sol', 272_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6.1-sol' => ['GPT-6.1 Sol', 272_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-daybreak-blue-latest' => ['Daybreak Blue', 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-daybreak-red-latest' => ['Daybreak Red', 400_000, 128_000, true, true, 12.5, 75.0, 1.25, 15.625, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-realtime-2.1' => ['GPT-Realtime-2.1', 128_000, 32_000, true, true, 4.0, 24.0, 0.4, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'o1' => ['o1', 200_000, 100_000, true, true, 15.0, 60.0, 7.5, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o1-pro' => ['o1-pro', 200_000, 100_000, true, true, 150.0, 600.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o3' => ['o3', 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o3-mini' => ['o3-mini', 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o3-pro' => ['o3-pro', 200_000, 100_000, true, true, 20.0, 80.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o4-mini' => ['o4-mini', 200_000, 100_000, true, true, 1.1, 4.4, 0.275, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /**
     * Google's, on the Generative Language API.
     *
     * A row's `thinkingLevelMap` is the generator's `getGoogleThinkingLevelMap()`, upstream's: the
     * verified efforts models.dev lists for the model, or Gemma 4's fixed map — only `MINIMAL` and
     * `HIGH`, and no `off` — with the generator's measured corrections merged over it for the rows
     * whose endpoint refuses a level (see `scripts/generate-models.php`). Until this round the
     * generator wrote only the corrections, so Gemma 4 was offered `low` and `medium` and sent them.
     * The maps here were written from upstream's published catalogue (`@earendil-works/pi-ai`
     * 1.1.0, which its generator built from models.dev), as models.dev was not reachable to
     * regenerate; the other columns are the last regeneration's. `gemini-flash-latest` and
     * `gemini-flash-lite-latest` read their prices from the models they alias, as the generator does.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, thinkingLevelMap?: array<string, string|null>, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>}>
     */
    private const array GOOGLE_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'deep-research-max-preview-04-2026' => ['Deep Research Max Preview (Apr-21-2026)', 131_072, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.0]]],
        'deep-research-preview-04-2026' => ['Deep Research Preview (Apr-21-2026)', 131_072, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.0]]],
        'gemini-2.5-computer-use-preview-10-2025' => ['Gemini 2.5 Computer Use Preview 10-2025', 128_000, 64_000, true, true, 1.25, 10.0, 0.0, 0.0, 'tiers' => [[200_000, 2.5, 15.0, 0.0, 0.0]]],
        'gemini-2.5-flash' => ['Gemini 2.5 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.0],
        'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash-Lite', 1_048_576, 65_536, true, true, 0.1, 0.4, 0.01, 0.0],
        'gemini-2.5-pro' => ['Gemini 2.5 Pro', 1_048_576, 65_536, true, true, 1.25, 10.0, 0.125, 0.0, 'tiers' => [[200_000, 2.5, 15.0, 0.25, 0.0]]],
        'gemini-3-flash-preview' => ['Gemini 3 Flash Preview', 1_048_576, 65_536, true, true, 0.5, 3.0, 0.05, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-flash-lite' => ['Gemini 3.1 Flash Lite', 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-flash-lite-image' => ['Nano Banana 2 Lite', 65_536, 4_096, true, true, 0.25, 30.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-flash-lite-preview' => ['Gemini 3.1 Flash Lite Preview', 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-flash-live-preview' => ['Gemini 3.1 Flash Live Preview', 131_072, 65_536, true, true, 0.75, 4.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-pro-preview' => ['Gemini 3.1 Pro Preview', 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.0]]],
        'gemini-3.1-pro-preview-customtools' => ['Gemini 3.1 Pro Preview Custom Tools', 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.0]]],
        'gemini-3.5-flash' => ['Gemini 3.5 Flash', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash Lite', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.6-flash' => ['Gemini 3.6 Flash', 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.7-flash' => ['Gemini 3.7 Flash', 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.8-flash' => ['Gemini 3.8 Flash', 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-flash-latest' => ['Gemini Flash Latest', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-flash-lite-latest' => ['Gemini Flash-Lite Latest', 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemma-4-26b-a4b-it' => ['Gemma 4 26B A4B IT', 262_144, 32_768, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'MINIMAL', 'low' => null, 'medium' => null, 'high' => 'HIGH']],
        'gemma-4-31b-it' => ['Gemma 4 31B IT', 262_144, 32_768, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'MINIMAL', 'low' => null, 'medium' => null, 'high' => 'HIGH']],
        // <<< generated
    ];

    /**
     * Gemini on Vertex AI (`Providers\GoogleVertex`), as upstream's generator writes the `google-vertex`
     * provider (`processGoogleModels()`'s second half): "The google-vertex models.dev catalog also
     * includes Claude, OpenAI, and other MaaS models that do not use the @google/genai Gemini streaming
     * path", so only the `gemini-` ids that take tools, `gemini-3.1-flash-lite-preview` left out; the
     * two `-latest` aliases read the models they alias; the map is `getGoogleThinkingLevelMap()`; and the
     * cost is the input, output and cache-read rates alone — no tiers, no cache writes, and 0.03 for
     * Gemini 2.5 Flash's cache reads, "models.dev reports Vertex cache_read/cache_write values for
     * Gemini 2.5 Flash that do not match the official Gemini API standard pricing table".
     *
     * Every row is served at `https://{location}-aiplatform.googleapis.com`, a template the provider
     * fills from the location (`resolveCustomBaseUrl()` treats it as no base URL at all).
     *
     * The rows were written from upstream's published catalogue (`@earendil-works/pi-ai` 1.1.0, which
     * its generator built from models.dev), as models.dev was not reachable to regenerate.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, thinkingLevelMap?: array<string, string|null>}>
     */
    private const array GOOGLE_VERTEX_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'gemini-2.5-flash' => ['Gemini 2.5 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.0],
        'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash-Lite', 1_048_576, 65_535, true, true, 0.1, 0.4, 0.01, 0.0],
        'gemini-2.5-pro' => ['Gemini 2.5 Pro', 1_048_576, 65_536, true, true, 1.25, 10.0, 0.125, 0.0],
        'gemini-3-flash-preview' => ['Gemini 3 Flash Preview', 1_048_576, 65_536, true, true, 0.5, 3.0, 0.05, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-flash-lite' => ['Gemini 3.1 Flash Lite', 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-pro-preview' => ['Gemini 3.1 Pro Preview', 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-pro-preview-customtools' => ['Gemini 3.1 Pro Preview Custom Tools', 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash' => ['Gemini 3.5 Flash', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash Lite', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.6-flash' => ['Gemini 3.6 Flash', 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.7-flash' => ['Gemini 3.7 Flash', 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.8-flash' => ['Gemini 3.8 Flash', 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-flash-latest' => ['Gemini Flash Latest', 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-flash-lite-latest' => ['Gemini Flash-Lite Latest', 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /**
     * Amazon Bedrock's (`Providers\Bedrock`), as upstream's generator writes the `amazon-bedrock`
     * provider: every model models.dev lists that takes tools, except the ones only an inference
     * profile serves (`BEDROCK_INFERENCE_PROFILE_ONLY_MODEL_IDS`, `anthropic.claude-opus-5`), AI21's
     * Jamba ("These models doesn't support tool use in streaming mode") and Mistral 7B Instruct v0
     * ("These models doesn't support system messages"); priced with models.dev's tiers; and
     * `compat: {supportsStrictMode: true}` — the row's `strictMode` — where models.dev says
     * `structured_output`. The thinking maps are not in the rows: upstream's generator writes them from
     * the ids (`applyThinkingLevelMetadata()`), which is `thinkingLevelMap()` below.
     *
     * The base URL is per row, upstream's `getBedrockBaseUrl()`: `eu.` inference profiles at
     * `bedrock-runtime.eu-central-1.amazonaws.com`, everything else at `us-east-1`'s — which the provider
     * only pins when no region or profile is configured.
     *
     * The rows were written from upstream's published catalogue (`@earendil-works/pi-ai` 1.1.0), as
     * models.dev was not reachable to regenerate.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>, strictMode?: true}>
     */
    private const array AMAZON_BEDROCK_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'amazon.nova-2-lite-v1:0' => ['Nova 2 Lite', 1_000_000, 65_535, true, true, 0.33, 2.75, 0.0825, 0.33],
        'amazon.nova-lite-v1:0' => ['Nova Lite', 300_000, 10_000, false, true, 0.06, 0.24, 0.015, 0.06],
        'amazon.nova-micro-v1:0' => ['Nova Micro', 128_000, 10_000, false, false, 0.035, 0.14, 0.00875, 0.035],
        'amazon.nova-pro-v1:0' => ['Nova Pro', 300_000, 10_000, false, true, 0.8, 3.2, 0.2, 0.8],
        'anthropic.claude-fable-5' => ['Claude Fable 5', 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5],
        'anthropic.claude-fable-5-1' => ['Claude Fable 5.1', 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5],
        'anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5', 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25, 'strictMode' => true],
        'anthropic.claude-haiku-5-5' => ['Claude Haiku 5.5', 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],
        'anthropic.claude-opus-4-1-20250805-v1:0' => ['Claude Opus 4.1', 200_000, 32_000, true, true, 15.0, 75.0, 1.5, 18.75],
        'anthropic.claude-opus-4-5-20251101-v1:0' => ['Claude Opus 4.5', 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25, 'strictMode' => true],
        'anthropic.claude-opus-4-6-v1' => ['Claude Opus 4.6', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875, 'strictMode' => true],
        'anthropic.claude-opus-4-7' => ['Claude Opus 4.7', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic.claude-opus-4-8' => ['Claude Opus 4.8', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic.claude-opus-5-5' => ['Claude Opus 5.5', 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0],
        'anthropic.claude-sonnet-4-5-20250929-v1:0' => ['Claude Sonnet 4.5', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'strictMode' => true],
        'anthropic.claude-sonnet-4-6' => ['Claude Sonnet 4.6', 1_000_000, 128_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'anthropic.claude-sonnet-5' => ['Claude Sonnet 5', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5],
        'anthropic.claude-sonnet-5-5' => ['Claude Sonnet 5.5', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5],
        'apac.amazon.nova-lite-v1:0' => ['Nova Lite (APAC)', 300_000, 10_000, false, true, 0.063, 0.252, 0.01575, 0.063],
        'apac.amazon.nova-micro-v1:0' => ['Nova Micro (APAC)', 128_000, 10_000, false, false, 0.037, 0.148, 0.00925, 0.037],
        'apac.amazon.nova-pro-v1:0' => ['Nova Pro (APAC)', 300_000, 10_000, false, true, 0.84, 3.36, 0.21, 0.84],
        'apac.anthropic.claude-sonnet-4-20250514-v1:0' => ['Claude Sonnet 4 (APAC)', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'au.anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5 (AU)', 200_000, 64_000, true, true, 1.1, 5.5, 0.11, 1.375, 'strictMode' => true],
        'au.anthropic.claude-haiku-5-5' => ['Claude Haiku 5.5 (AU)', 1_000_000, 128_000, true, true, 0.11, 0.55, 0.011, 0.1375, 'tiers' => [[100_000, 0.55, 2.75, 0.055, 0.6875]]],
        'au.anthropic.claude-opus-4-6-v1' => ['AU Anthropic Claude Opus 4.6', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875, 'strictMode' => true],
        'au.anthropic.claude-opus-4-7' => ['Claude Opus 4.7 (AU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'au.anthropic.claude-opus-4-8' => ['Claude Opus 4.8 (AU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'au.anthropic.claude-opus-5' => ['Claude Opus 5 (AU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'au.anthropic.claude-opus-5-5' => ['Claude Opus 5.5 (AU)', 1_000_000, 128_000, true, true, 4.4, 22.0, 0.22, 5.5],
        'au.anthropic.claude-sonnet-4-5-20250929-v1:0' => ['Claude Sonnet 4.5 (AU)', 200_000, 64_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'au.anthropic.claude-sonnet-4-6' => ['AU Anthropic Claude Sonnet 4.6', 1_000_000, 128_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'au.anthropic.claude-sonnet-5' => ['Claude Sonnet 5 (AU)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75],
        'ca.amazon.nova-lite-v1:0' => ['Nova Lite (CA)', 300_000, 10_000, false, true, 0.064, 0.256, 0.016, 0.064],
        'deepseek.v3-v1:0' => ['DeepSeek-V3.1', 163_840, 81_920, true, false, 0.58, 1.68, 0.0, 0.0, 'strictMode' => true],
        'deepseek.v3.2' => ['DeepSeek V3.2', 163_840, 81_920, true, false, 0.62, 1.85, 0.0, 0.0, 'strictMode' => true],
        'eu.amazon.nova-2-lite-v1:0' => ['Nova 2 Lite (EU)', 1_000_000, 65_535, true, true, 0.374, 3.157, 0.0935, 0.374],
        'eu.amazon.nova-lite-v1:0' => ['Nova Lite (EU)', 300_000, 10_000, false, true, 0.069, 0.276, 0.01725, 0.069],
        'eu.amazon.nova-micro-v1:0' => ['Nova Micro (EU)', 128_000, 10_000, false, false, 0.04, 0.16, 0.01, 0.04],
        'eu.amazon.nova-pro-v1:0' => ['Nova Pro (EU)', 300_000, 10_000, false, true, 0.92, 3.68, 0.23, 0.92],
        'eu.anthropic.claude-fable-5' => ['Claude Fable 5 (EU)', 1_000_000, 128_000, true, true, 11.0, 55.0, 1.1, 13.75],
        'eu.anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5 (EU)', 200_000, 64_000, true, true, 1.1, 5.5, 0.11, 1.375, 'strictMode' => true],
        'eu.anthropic.claude-haiku-5-5' => ['Claude Haiku 5.5 (EU)', 1_000_000, 128_000, true, true, 0.11, 0.55, 0.011, 0.1375, 'tiers' => [[100_000, 0.55, 2.75, 0.055, 0.6875]]],
        'eu.anthropic.claude-opus-4-5-20251101-v1:0' => ['Claude Opus 4.5 (EU)', 200_000, 64_000, true, true, 5.5, 27.5, 0.55, 6.875, 'strictMode' => true],
        'eu.anthropic.claude-opus-4-6-v1' => ['Claude Opus 4.6 (EU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875, 'strictMode' => true],
        'eu.anthropic.claude-opus-4-7' => ['Claude Opus 4.7 (EU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'eu.anthropic.claude-opus-4-8' => ['Claude Opus 4.8 (EU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'eu.anthropic.claude-opus-5' => ['Claude Opus 5 (EU)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'eu.anthropic.claude-opus-5-5' => ['Claude Opus 5.5 (EU)', 1_000_000, 128_000, true, true, 4.4, 22.0, 0.22, 5.5],
        'eu.anthropic.claude-sonnet-4-20250514-v1:0' => ['Claude Sonnet 4 (EU)', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'eu.anthropic.claude-sonnet-4-5-20250929-v1:0' => ['Claude Sonnet 4.5 (EU)', 200_000, 64_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'eu.anthropic.claude-sonnet-4-6' => ['Claude Sonnet 4.6 (EU)', 1_000_000, 128_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'eu.anthropic.claude-sonnet-5' => ['Claude Sonnet 5 (EU)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75],
        'eu.anthropic.claude-sonnet-5-5' => ['Claude Sonnet 5.5 (EU)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.11, 2.75],
        'eu.mistral.pixtral-large-2502-v1:0' => ['Pixtral Large (25.02) (EU)', 128_000, 8_192, false, true, 2.0, 6.0, 0.0, 0.0],
        'global.amazon.nova-2-lite-v1:0' => ['Nova 2 Lite (Global)', 1_000_000, 65_535, true, true, 0.3, 2.5, 0.075, 0.3],
        'global.anthropic.claude-fable-5' => ['Claude Fable 5 (Global)', 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5],
        'global.anthropic.claude-fable-5-1' => ['Claude Fable 5.1 (Global)', 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5],
        'global.anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5 (Global)', 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25, 'strictMode' => true],
        'global.anthropic.claude-haiku-5-5' => ['Claude Haiku 5.5 (Global)', 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],
        'global.anthropic.claude-opus-4-5-20251101-v1:0' => ['Claude Opus 4.5 (Global)', 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25, 'strictMode' => true],
        'global.anthropic.claude-opus-4-6-v1' => ['Claude Opus 4.6 (Global)', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'strictMode' => true],
        'global.anthropic.claude-opus-4-7' => ['Claude Opus 4.7 (Global)', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'global.anthropic.claude-opus-4-8' => ['Claude Opus 4.8 (Global)', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'global.anthropic.claude-opus-5' => ['Claude Opus 5 (Global)', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'global.anthropic.claude-opus-5-5' => ['Claude Opus 5.5 (Global)', 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0],
        'global.anthropic.claude-sonnet-4-20250514-v1:0' => ['Claude Sonnet 4 (Global)', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'global.anthropic.claude-sonnet-4-5-20250929-v1:0' => ['Claude Sonnet 4.5 (Global)', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'strictMode' => true],
        'global.anthropic.claude-sonnet-4-6' => ['Claude Sonnet 4.6 (Global)', 1_000_000, 128_000, true, true, 3.0, 15.0, 0.3, 3.75, 'strictMode' => true],
        'global.anthropic.claude-sonnet-5' => ['Claude Sonnet 5 (Global)', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5],
        'global.anthropic.claude-sonnet-5-5' => ['Claude Sonnet 5.5 (Global)', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5],
        'global.moonshotai.kimi-k3' => ['Kimi K3 (Global)', 1_048_576, 128_000, true, true, 3.0, 15.0, 0.3, 3.75, 'strictMode' => true],
        'global.openai.gpt-5.6-luna' => ['GPT-5.6 Luna (Global)', 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]], 'strictMode' => true],
        'global.openai.gpt-5.6-sol' => ['GPT-5.6 Sol (Global)', 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]]],
        'global.openai.gpt-5.6-terra' => ['GPT-5.6 Terra (Global)', 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]]],
        'global.openai.gpt-6-astra' => ['GPT-6 Astra (Global)', 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]], 'strictMode' => true],
        'global.openai.gpt-6-luna' => ['GPT-6 Luna (Global)', 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]], 'strictMode' => true],
        'global.openai.gpt-6-sol' => ['GPT-6 Sol (Global)', 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]], 'strictMode' => true],
        'global.openai.gpt-6.1-sol' => ['GPT-6.1 Sol (Global)', 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]], 'strictMode' => true],
        'global.xai.grok-4.6' => ['Grok 4.6 (Global)', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0],
        'global.xai.grok-4.7' => ['Grok 4.7 (Global)', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'strictMode' => true],
        'global.zai.glm-5.3' => ['GLM-5.3 (Global)', 1_000_000, 128_000, true, false, 1.68, 5.28, 0.312, 2.1, 'strictMode' => true],
        'google.gemma-4-26b-a4b' => ['Gemma 4 26B A4B IT', 262_144, 32_768, true, true, 0.13, 0.4, 0.0, 0.0, 'strictMode' => true],
        'google.gemma-4-31b' => ['Gemma 4 31B IT', 262_144, 32_768, true, true, 0.14, 0.4, 0.0, 0.0, 'strictMode' => true],
        'google.gemma-4-e2b' => ['Gemma 4 E2B IT', 131_072, 8_192, true, true, 0.04, 0.08, 0.0, 0.0, 'strictMode' => true],
        'in.anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5 (India)', 200_000, 64_000, true, true, 1.1, 5.5, 0.11, 1.375],
        'in.anthropic.claude-opus-5' => ['Claude Opus 5 (India)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'in.anthropic.claude-sonnet-5' => ['Claude Sonnet 5 (India)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75],
        'in.openai.gpt-5.6-luna' => ['GPT-5.6 Luna (India)', 1_050_000, 128_000, true, true, 0.22, 1.32, 0.022, 0.275, 'tiers' => [[272_000, 0.44, 1.98, 0.044, 0.55]], 'strictMode' => true],
        'in.openai.gpt-5.6-terra' => ['GPT-5.6 Terra (India)', 1_050_000, 128_000, true, true, 2.2, 13.2, 0.22, 2.75, 'tiers' => [[272_000, 4.4, 19.8, 0.44, 5.5]]],
        'jp.amazon.nova-2-lite-v1:0' => ['Nova 2 Lite (JP)', 1_000_000, 65_535, true, true, 0.396, 3.311, 0.099, 0.396],
        'jp.anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5 (JP)', 200_000, 64_000, true, true, 1.1, 5.5, 0.11, 1.375, 'strictMode' => true],
        'jp.anthropic.claude-haiku-5-5' => ['Claude Haiku 5.5 (JP)', 1_000_000, 128_000, true, true, 0.11, 0.55, 0.011, 0.1375, 'tiers' => [[100_000, 0.55, 2.75, 0.055, 0.6875]]],
        'jp.anthropic.claude-opus-4-7' => ['Claude Opus 4.7 (JP)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'jp.anthropic.claude-opus-4-8' => ['Claude Opus 4.8 (JP)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'jp.anthropic.claude-opus-5' => ['Claude Opus 5 (JP)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'jp.anthropic.claude-opus-5-5' => ['Claude Opus 5.5 (JP)', 1_000_000, 128_000, true, true, 4.4, 22.0, 0.22, 5.5],
        'jp.anthropic.claude-sonnet-4-5-20250929-v1:0' => ['Claude Sonnet 4.5 (JP)', 200_000, 64_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'jp.anthropic.claude-sonnet-4-6' => ['Claude Sonnet 4.6 (JP)', 1_000_000, 128_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'jp.anthropic.claude-sonnet-5' => ['Claude Sonnet 5 (JP)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75],
        'meta.llama3-1-70b-instruct-v1:0' => ['Llama 3.1 70B Instruct', 128_000, 4_096, false, false, 0.72, 0.72, 0.0, 0.0],
        'meta.llama3-1-8b-instruct-v1:0' => ['Llama 3.1 8B Instruct', 128_000, 4_096, false, false, 0.22, 0.22, 0.0, 0.0],
        'meta.llama3-3-70b-instruct-v1:0' => ['Llama 3.3 70B Instruct', 128_000, 4_096, false, false, 0.72, 0.72, 0.0, 0.0],
        'meta.llama4-maverick-17b-instruct-v1:0' => ['Llama 4 Maverick 17B Instruct', 1_000_000, 8_192, false, true, 0.24, 0.97, 0.0, 0.0],
        'meta.llama4-scout-17b-instruct-v1:0' => ['Llama 4 Scout 17B Instruct', 10_000_000, 8_192, false, true, 0.17, 0.66, 0.0, 0.0],
        'minimax.minimax-m2' => ['MiniMax-M2', 204_608, 128_000, true, false, 0.3, 1.2, 0.0, 0.0, 'strictMode' => true],
        'minimax.minimax-m2.1' => ['MiniMax-M2.1', 196_608, 131_072, true, false, 0.3, 1.2, 0.0, 0.0, 'strictMode' => true],
        'minimax.minimax-m2.5' => ['MiniMax-M2.5', 196_608, 98_304, true, false, 0.3, 1.2, 0.0, 0.0, 'strictMode' => true],
        'mistral.devstral-2-123b' => ['Devstral 2 123B', 262_144, 8_192, false, false, 0.4, 2.0, 0.0, 0.0, 'strictMode' => true],
        'mistral.magistral-small-2509' => ['Magistral Small 1.2', 128_000, 40_000, true, true, 0.5, 1.5, 0.0, 0.0, 'strictMode' => true],
        'mistral.ministral-3-14b-instruct' => ['Ministral 14B 3.0', 128_000, 4_096, false, true, 0.2, 0.2, 0.0, 0.0, 'strictMode' => true],
        'mistral.ministral-3-3b-instruct' => ['Ministral 3 3B', 256_000, 8_192, false, true, 0.1, 0.1, 0.0, 0.0, 'strictMode' => true],
        'mistral.ministral-3-8b-instruct' => ['Ministral 3 8B', 128_000, 4_096, false, true, 0.15, 0.15, 0.0, 0.0, 'strictMode' => true],
        'mistral.mistral-large-3-675b-instruct' => ['Mistral Large 3', 262_144, 8_192, false, true, 0.5, 1.5, 0.0, 0.0, 'strictMode' => true],
        'mistral.pixtral-large-2502-v1:0' => ['Pixtral Large (25.02)', 128_000, 8_192, false, true, 2.0, 6.0, 0.0, 0.0],
        'mistral.voxtral-mini-3b-2507' => ['Voxtral Mini 3B 2507', 32_768, 4_096, false, false, 0.04, 0.04, 0.0, 0.0, 'strictMode' => true],
        'mistral.voxtral-small-24b-2507' => ['Voxtral Small 24B 2507', 32_768, 8_192, false, false, 0.1, 0.3, 0.0, 0.0, 'strictMode' => true],
        'moonshot.kimi-k2-thinking' => ['Kimi K2 Thinking', 262_144, 16_000, true, false, 0.6, 2.5, 0.0, 0.0, 'strictMode' => true],
        'moonshotai.kimi-k2.5' => ['Kimi K2.5', 262_144, 16_384, true, true, 0.6, 3.0, 0.0, 0.0, 'strictMode' => true],
        'nvidia.nemotron-nano-12b-v2' => ['NVIDIA Nemotron Nano 12B v2 VL BF16', 131_072, 8_192, false, true, 0.2, 0.6, 0.0, 0.0, 'strictMode' => true],
        'nvidia.nemotron-nano-3-30b' => ['NVIDIA Nemotron Nano 3 30B', 262_144, 8_192, true, false, 0.06, 0.24, 0.0, 0.0, 'strictMode' => true],
        'nvidia.nemotron-nano-9b-v2' => ['NVIDIA Nemotron Nano 9B v2', 131_072, 8_192, false, false, 0.06, 0.23, 0.0, 0.0, 'strictMode' => true],
        'nvidia.nemotron-super-3-120b' => ['NVIDIA Nemotron 3 Super 120B A12B', 262_144, 131_072, true, false, 0.15, 0.65, 0.0, 0.0, 'strictMode' => true],
        'openai.gpt-5.4' => ['GPT-5.4', 1_000_000, 128_000, true, true, 2.75, 16.5, 0.275, 0.0, 'strictMode' => true],
        'openai.gpt-5.5' => ['GPT-5.5', 1_000_000, 128_000, true, true, 5.5, 33.0, 0.55, 0.0, 'strictMode' => true],
        'openai.gpt-5.6-luna' => ['GPT-5.6 Luna', 1_050_000, 128_000, true, true, 0.22, 1.32, 0.022, 0.275, 'tiers' => [[272_000, 0.44, 1.98, 0.044, 0.55]], 'strictMode' => true],
        'openai.gpt-5.6-sol' => ['GPT-5.6 Sol', 1_050_000, 128_000, true, true, 4.4, 22.0, 0.44, 5.5, 'tiers' => [[272_000, 8.8, 33.0, 0.88, 11.0]], 'strictMode' => true],
        'openai.gpt-5.6-terra' => ['GPT-5.6 Terra', 1_050_000, 128_000, true, true, 2.2, 13.2, 0.22, 2.75, 'tiers' => [[272_000, 4.4, 19.8, 0.44, 5.5]], 'strictMode' => true],
        'openai.gpt-6-astra' => ['GPT-6 Astra', 1_050_000, 128_000, true, true, 11.0, 55.0, 1.1, 13.75, 'tiers' => [[272_000, 22.0, 82.5, 2.2, 27.5]], 'strictMode' => true],
        'openai.gpt-6-luna' => ['GPT-6 Luna', 1_050_000, 128_000, true, true, 0.11, 0.55, 0.011, 0.1375, 'tiers' => [[272_000, 0.22, 0.825, 0.022, 0.275]], 'strictMode' => true],
        'openai.gpt-6-sol' => ['GPT-6 Sol', 1_050_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75, 'tiers' => [[272_000, 4.4, 16.5, 0.44, 5.5]], 'strictMode' => true],
        'openai.gpt-6.1-sol' => ['GPT-6.1 Sol', 1_050_000, 128_000, true, true, 2.2, 11.0, 0.11, 2.75, 'tiers' => [[272_000, 4.4, 16.5, 0.22, 5.5]], 'strictMode' => true],
        'openai.gpt-oss-120b' => ['gpt-oss-120b', 131_072, 131_072, true, false, 0.15, 0.6, 0.0, 0.0, 'strictMode' => true],
        'openai.gpt-oss-120b-1:0' => ['gpt-oss-120b', 131_072, 128_000, true, false, 0.15, 0.6, 0.0, 0.0, 'strictMode' => true],
        'openai.gpt-oss-20b' => ['gpt-oss-20b', 131_072, 131_072, true, false, 0.07, 0.3, 0.0, 0.0, 'strictMode' => true],
        'openai.gpt-oss-20b-1:0' => ['gpt-oss-20b', 131_072, 128_000, true, false, 0.07, 0.3, 0.0, 0.0, 'strictMode' => true],
        'openai.gpt-oss-safeguard-120b' => ['GPT OSS Safeguard 120B', 128_000, 16_384, true, false, 0.15, 0.6, 0.0, 0.0, 'strictMode' => true],
        'openai.gpt-oss-safeguard-20b' => ['GPT OSS Safeguard 20B', 128_000, 16_384, true, false, 0.07, 0.2, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-235b-a22b-2507-v1:0' => ['Qwen3 235B-A22B Instruct 2507', 262_144, 131_072, false, false, 0.22, 0.88, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-32b-v1:0' => ['Qwen3 32B', 32_768, 16_384, true, false, 0.15, 0.6, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-coder-30b-a3b-v1:0' => ['Qwen3-Coder 30B-A3B Instruct', 262_144, 131_072, false, false, 0.15, 0.6, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-coder-480b-a35b-v1:0' => ['Qwen3-Coder 480B-A35B Instruct', 131_072, 65_536, false, false, 0.45, 1.8, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-coder-next' => ['Qwen3 Coder Next', 262_144, 65_536, false, false, 0.5, 1.2, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-next-80b-a3b' => ['Qwen3-Next 80B-A3B Instruct', 262_144, 262_000, false, false, 0.15, 1.2, 0.0, 0.0, 'strictMode' => true],
        'qwen.qwen3-vl-235b-a22b' => ['Qwen3 VL 235B A22B Instruct', 262_144, 262_000, false, true, 0.53, 2.66, 0.0, 0.0, 'strictMode' => true],
        'us-gov.openai.gpt-oss-120b-1:0' => ['gpt-oss-120b (GovCloud)', 128_000, 16_384, true, false, 0.18, 0.72, 0.0, 0.0, 'strictMode' => true],
        'us-gov.openai.gpt-oss-20b-1:0' => ['gpt-oss-20b (GovCloud)', 128_000, 16_384, true, false, 0.084, 0.36, 0.0, 0.0, 'strictMode' => true],
        'us.amazon.nova-2-lite-v1:0' => ['Nova 2 Lite (US)', 1_000_000, 65_535, true, true, 0.33, 2.75, 0.0825, 0.33],
        'us.amazon.nova-lite-v1:0' => ['Nova Lite (US)', 300_000, 10_000, false, true, 0.06, 0.24, 0.015, 0.06],
        'us.amazon.nova-micro-v1:0' => ['Nova Micro (US)', 128_000, 10_000, false, false, 0.035, 0.14, 0.00875, 0.035],
        'us.amazon.nova-premier-v1:0' => ['Nova Premier (US)', 1_000_000, 10_000, false, true, 2.5, 12.5, 0.625, 2.5],
        'us.amazon.nova-pro-v1:0' => ['Nova Pro (US)', 300_000, 10_000, false, true, 0.8, 3.2, 0.2, 0.8],
        'us.anthropic.claude-fable-5' => ['Claude Fable 5 (US)', 1_000_000, 128_000, true, true, 11.0, 55.0, 1.1, 13.75],
        'us.anthropic.claude-fable-5-1' => ['Claude Fable 5.1 (US)', 1_000_000, 128_000, true, true, 11.0, 55.0, 0.275, 13.75],
        'us.anthropic.claude-haiku-4-5-20251001-v1:0' => ['Claude Haiku 4.5 (US)', 200_000, 64_000, true, true, 1.1, 5.5, 0.11, 1.375, 'strictMode' => true],
        'us.anthropic.claude-haiku-5-5' => ['Claude Haiku 5.5 (US)', 1_000_000, 128_000, true, true, 0.11, 0.55, 0.011, 0.1375, 'tiers' => [[100_000, 0.55, 2.75, 0.055, 0.6875]]],
        'us.anthropic.claude-opus-4-1-20250805-v1:0' => ['Claude Opus 4.1 (US)', 200_000, 32_000, true, true, 15.0, 75.0, 1.5, 18.75],
        'us.anthropic.claude-opus-4-5-20251101-v1:0' => ['Claude Opus 4.5 (US)', 200_000, 64_000, true, true, 5.5, 27.5, 0.55, 6.875, 'strictMode' => true],
        'us.anthropic.claude-opus-4-6-v1' => ['Claude Opus 4.6 (US)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875, 'strictMode' => true],
        'us.anthropic.claude-opus-4-7' => ['Claude Opus 4.7 (US)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'us.anthropic.claude-opus-4-8' => ['Claude Opus 4.8 (US)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'us.anthropic.claude-opus-5' => ['Claude Opus 5 (US)', 1_000_000, 128_000, true, true, 5.5, 27.5, 0.55, 6.875],
        'us.anthropic.claude-opus-5-5' => ['Claude Opus 5.5 (US)', 1_000_000, 128_000, true, true, 4.4, 22.0, 0.22, 5.5],
        'us.anthropic.claude-sonnet-4-20250514-v1:0' => ['Claude Sonnet 4 (US)', 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'us.anthropic.claude-sonnet-4-5-20250929-v1:0' => ['Claude Sonnet 4.5 (US)', 200_000, 64_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'us.anthropic.claude-sonnet-4-6' => ['Claude Sonnet 4.6 (US)', 1_000_000, 128_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'us.anthropic.claude-sonnet-5' => ['Claude Sonnet 5 (US)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75],
        'us.anthropic.claude-sonnet-5-5' => ['Claude Sonnet 5.5 (US)', 1_000_000, 128_000, true, true, 2.2, 11.0, 0.11, 2.75],
        'us.meta.llama3-1-70b-instruct-v1:0' => ['Llama 3.1 70B Instruct (US)', 128_000, 4_096, false, false, 0.72, 0.72, 0.0, 0.0],
        'us.meta.llama3-1-8b-instruct-v1:0' => ['Llama 3.1 8B Instruct (US)', 128_000, 4_096, false, false, 0.22, 0.22, 0.0, 0.0],
        'us.meta.llama3-3-70b-instruct-v1:0' => ['Llama 3.3 70B Instruct (US)', 128_000, 4_096, false, false, 0.72, 0.72, 0.0, 0.0],
        'us.meta.llama4-maverick-17b-instruct-v1:0' => ['Llama 4 Maverick 17B Instruct (US)', 1_000_000, 8_192, false, true, 0.24, 0.97, 0.0, 0.0],
        'us.meta.llama4-scout-17b-instruct-v1:0' => ['Llama 4 Scout 17B Instruct (US)', 10_000_000, 8_192, false, true, 0.17, 0.66, 0.0, 0.0],
        'us.mistral.pixtral-large-2502-v1:0' => ['Pixtral Large (25.02) (US)', 128_000, 8_192, false, true, 2.0, 6.0, 0.0, 0.0],
        'us.moonshotai.kimi-k3' => ['Kimi K3 (US)', 1_048_576, 128_000, true, true, 3.3, 16.5, 0.33, 4.125, 'strictMode' => true],
        'us.openai.gpt-5.6-luna' => ['GPT-5.6 Luna (US)', 1_050_000, 128_000, true, true, 0.22, 1.32, 0.022, 0.275, 'tiers' => [[272_000, 0.44, 1.98, 0.044, 0.55]], 'strictMode' => true],
        'us.openai.gpt-5.6-sol' => ['GPT-5.6 Sol (US)', 1_050_000, 128_000, true, true, 4.4, 22.0, 0.44, 5.5, 'tiers' => [[272_000, 8.8, 33.0, 0.88, 11.0]]],
        'us.openai.gpt-5.6-terra' => ['GPT-5.6 Terra (US)', 1_050_000, 128_000, true, true, 2.2, 13.2, 0.22, 2.75, 'tiers' => [[272_000, 4.4, 19.8, 0.44, 5.5]]],
        'us.openai.gpt-6-astra' => ['GPT-6 Astra (US)', 1_050_000, 128_000, true, true, 11.0, 55.0, 1.1, 13.75, 'tiers' => [[272_000, 22.0, 82.5, 2.2, 27.5]], 'strictMode' => true],
        'us.openai.gpt-6-luna' => ['GPT-6 Luna (US)', 1_050_000, 128_000, true, true, 0.11, 0.55, 0.011, 0.1375, 'tiers' => [[272_000, 0.22, 0.825, 0.022, 0.275]], 'strictMode' => true],
        'us.openai.gpt-6-sol' => ['GPT-6 Sol (US)', 1_050_000, 128_000, true, true, 2.2, 11.0, 0.22, 2.75, 'tiers' => [[272_000, 4.4, 16.5, 0.44, 5.5]], 'strictMode' => true],
        'us.openai.gpt-6.1-sol' => ['GPT-6.1 Sol (US)', 1_050_000, 128_000, true, true, 2.2, 11.0, 0.11, 2.75, 'tiers' => [[272_000, 4.4, 16.5, 0.22, 5.5]], 'strictMode' => true],
        'us.writer.palmyra-x4-v1:0' => ['Palmyra X4 (US)', 122_880, 8_192, true, false, 2.5, 10.0, 0.0, 0.0],
        'us.writer.palmyra-x5-v1:0' => ['Palmyra X5 (US)', 1_040_000, 8_192, true, false, 0.6, 6.0, 0.0, 0.0],
        'us.xai.grok-4.6' => ['Grok 4.6 (US)', 500_000, 500_000, true, true, 2.2, 6.6, 0.55, 0.0],
        'us.xai.grok-4.7' => ['Grok 4.7 (US)', 500_000, 500_000, true, true, 2.2, 6.6, 0.55, 0.0, 'strictMode' => true],
        'us.zai.glm-5.3' => ['GLM-5.3 (US)', 1_000_000, 128_000, true, false, 1.848, 5.808, 0.3432, 2.31, 'strictMode' => true],
        'writer.palmyra-x4-v1:0' => ['Palmyra X4', 122_880, 8_192, true, false, 2.5, 10.0, 0.0, 0.0],
        'writer.palmyra-x5-v1:0' => ['Palmyra X5', 1_040_000, 8_192, true, false, 0.6, 6.0, 0.0, 0.0],
        'xai.grok-4.3' => ['Grok 4.3', 1_000_000, 131_072, true, true, 1.25, 2.5, 0.2, 0.0, 'strictMode' => true],
        'xai.grok-4.6' => ['Grok 4.6', 500_000, 500_000, true, true, 2.2, 6.6, 0.55, 0.0, 'strictMode' => true],
        'zai.glm-4.7' => ['GLM-4.7', 202_752, 131_072, true, false, 0.6, 2.2, 0.0, 0.0, 'strictMode' => true],
        'zai.glm-4.7-flash' => ['GLM-4.7-Flash', 202_752, 131_072, true, false, 0.07, 0.4, 0.0, 0.0, 'strictMode' => true],
        'zai.glm-5' => ['GLM-5', 202_752, 131_072, true, false, 1.0, 3.2, 0.0, 0.0, 'strictMode' => true],
        // <<< generated
    ];

    /**
     * id => [name, which API it speaks, context window, max tokens, reasoning, images]
     *
     * A column for the API, which none of the other tables needs: Copilot serves its Claude models
     * through Anthropic's Messages API, `gpt-5…` through the Responses one and the rest through the
     * completions shape, and which it is is a fact about the model rather than about the provider
     * — the generator's `copilotApi()`, upstream's rule.
     *
     * **Priced at models.dev's list prices**, as upstream's generator writes them
     * (`cost: getModelsDevCost(m.cost)`): Copilot is a subscription, and pig used to carry no price
     * here, so `/session` said $0.00 for a conversation pi reports at what its tokens are worth.
     * The windows are upstream's `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS` rule — 1,000,000 for the ids
     * GitHub lists, whatever models.dev says (200,000, 400,000 or 1,050,000) — and `gpt-6-sol`,
     * `gpt-6-luna` and `claude-opus-5.5` would be upstream's hand-added rows if models.dev dropped
     * them. The prices were written from upstream's published catalogue (`@earendil-works/pi-ai`
     * 1.1.0) as models.dev was not reachable; the other columns are the last regeneration's.
     *
     * @var array<string, array{0: string, 1: Api, 2: int, 3: int, 4: bool, 5: bool, 6: float, 7: float, 8: float, 9: float, thinkingLevelMap?: array<string, string|null>, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>, effortLevelMap?: array<string, string|null>}>
     */
    private const array COPILOT_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'claude-fable-5' => ['Claude Fable 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-fable-5.1' => ['Claude Fable 5.1', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-haiku-4.5' => ['Claude Haiku 4.5 (latest)', Api::AnthropicMessages, 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25],
        'claude-haiku-5.5' => ['Claude Haiku 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4.7' => ['Claude Opus 4.7', Api::AnthropicMessages, 1_000_000, 32_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4.8' => ['Claude Opus 4.8', Api::AnthropicMessages, 1_000_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5' => ['Claude Opus 5', Api::AnthropicMessages, 1_000_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5.5' => ['Claude Opus 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-4.6' => ['Claude Sonnet 4.6', Api::AnthropicMessages, 1_000_000, 32_000, true, true, 3.0, 15.0, 0.3, 3.75, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-sonnet-5' => ['Claude Sonnet 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-5.5' => ['Claude Sonnet 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gemini-3.5-flash' => ['Gemini 3.5 Flash', Api::OpenAiCompletions, 200_000, 64_000, true, true, 1.5, 9.0, 0.15, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.6-flash' => ['Gemini 3.6 Flash', Api::OpenAiCompletions, 1_000_000, 64_000, true, true, 0.75, 3.75, 0.075, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.7-flash' => ['Gemini 3.7 Flash', Api::OpenAiCompletions, 1_000_000, 64_000, true, true, 0.75, 3.75, 0.075, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.8-flash' => ['Gemini 3.8 Flash', Api::OpenAiCompletions, 1_000_000, 64_000, true, true, 0.75, 3.75, 0.075, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-mini' => ['GPT-5 Mini', Api::OpenAiResponses, 264_000, 64_000, true, true, 0.25, 2.0, 0.025, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.3-codex' => ['GPT-5.3 Codex', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4' => ['GPT-5.4', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'tiers' => [[272_000, 5.0, 22.5, 0.5, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-mini' => ['GPT-5.4 mini', Api::OpenAiResponses, 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-nano' => ['GPT-5.4 nano', Api::OpenAiResponses, 400_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0],
        'gpt-5.5' => ['GPT-5.5', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[200_000, 0.4, 1.8, 0.04, 0.5]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-sol' => ['GPT-5.6 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-terra' => ['GPT-5.6 Terra', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-astra' => ['GPT-6 Astra', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-luna' => ['GPT-6 Luna', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-sol' => ['GPT-6 Sol', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6.1-sol' => ['GPT-6.1 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'grok-4.5' => ['Grok 4.5', Api::OpenAiResponses, 500_000, 128_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'grok-4.6' => ['Grok 4.6', Api::OpenAiResponses, 500_000, 128_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'grok-4.7' => ['Grok 4.7', Api::OpenAiResponses, 500_000, 128_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', Api::OpenAiCompletions, 256_000, 32_000, true, true, 0.95, 4.0, 0.19, 0.0],
        'kimi-k3' => ['Kimi K3', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'mai-code-1-flash-picker' => ['MAI-Code-1-Flash', Api::OpenAiResponses, 256_000, 128_000, true, false, 0.75, 4.5, 0.075, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mai-code-1.1-flash' => ['MAI-Code-1.1-Flash', Api::OpenAiResponses, 256_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /**
     * Azure OpenAI's (`Providers\AzureOpenAiResponses`, and `Providers\Azure` for the one Chat
     * Completions row), as upstream's generator writes the `azure` provider: **a clone of every
     * `openai` Responses row** on `azure-openai-responses` — taken before the compat and thinking
     * metadata are applied, so no `supportsStrictMode`, no tool search and no models.dev efforts, and
     * with the cost's four rates only (no tiers) — at `AZURE_CONTEXT_WINDOW_OVERRIDES`' window where it
     * names one ("Azure Foundry deploys these with larger context windows than OpenAI's own short-tier
     * defaults"); and DeepSeek V4 Pro on Chat Completions at Azure's own rates ("Azure resells DeepSeek
     * at its own rates. US data zone, checked 2026-09-16"). An api per row, as Copilot's table has.
     *
     * "Azure models ship without a baseUrl: one resource per user, resolved per request" — every row's
     * is `''`, and `AzureOpenAiConfig` works the endpoint out per request.
     *
     * The rows were written from upstream's published catalogue (`@earendil-works/pi-ai` 1.1.0), as
     * models.dev was not reachable to regenerate.
     *
     * @var array<string, array{0: string, 1: Api, 2: int, 3: int, 4: bool, 5: bool, 6: float, 7: float, 8: float, 9: float}>
     */
    private const array AZURE_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'deepseek-v4-pro' => ['DeepSeek V4 Pro', Api::OpenAiCompletions, 1_000_000, 384_000, true, false, 1.925, 3.828, 0.165, 0.0],
        'gpt-4' => ['GPT-4', Api::AzureOpenAiResponses, 8_192, 8_192, false, false, 30.0, 60.0, 0.0, 0.0],
        'gpt-4-turbo' => ['GPT-4 Turbo', Api::AzureOpenAiResponses, 128_000, 4_096, false, true, 10.0, 30.0, 0.0, 0.0],
        'gpt-4.1' => ['GPT-4.1', Api::AzureOpenAiResponses, 1_047_576, 32_768, false, true, 2.0, 8.0, 0.5, 0.0],
        'gpt-4.1-mini' => ['GPT-4.1 mini', Api::AzureOpenAiResponses, 1_047_576, 32_768, false, true, 0.4, 1.6, 0.1, 0.0],
        'gpt-4.1-nano' => ['GPT-4.1 nano', Api::AzureOpenAiResponses, 1_047_576, 32_768, false, true, 0.1, 0.4, 0.025, 0.0],
        'gpt-4o' => ['GPT-4o', Api::AzureOpenAiResponses, 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-2024-05-13' => ['GPT-4o (2024-05-13)', Api::AzureOpenAiResponses, 128_000, 4_096, false, true, 5.0, 15.0, 0.0, 0.0],
        'gpt-4o-2024-08-06' => ['GPT-4o (2024-08-06)', Api::AzureOpenAiResponses, 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-2024-11-20' => ['GPT-4o (2024-11-20)', Api::AzureOpenAiResponses, 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-mini' => ['GPT-4o mini', Api::AzureOpenAiResponses, 128_000, 16_384, false, true, 0.15, 0.6, 0.075, 0.0],
        'gpt-5' => ['GPT-5', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5-chat-latest' => ['GPT-5 Chat Latest', Api::AzureOpenAiResponses, 128_000, 16_384, false, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5-mini' => ['GPT-5 Mini', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0],
        'gpt-5-nano' => ['GPT-5 Nano', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 0.05, 0.4, 0.005, 0.0],
        'gpt-5-pro' => ['GPT-5 Pro', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 15.0, 120.0, 0.0, 0.0],
        'gpt-5.1' => ['GPT-5.1', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5.2' => ['GPT-5.2', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.2-chat-latest' => ['GPT-5.2 Chat', Api::AzureOpenAiResponses, 128_000, 16_384, true, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.2-pro' => ['GPT-5.2 Pro', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 21.0, 168.0, 0.0, 0.0],
        'gpt-5.3-chat-latest' => ['GPT-5.3 Chat (latest)', Api::AzureOpenAiResponses, 128_000, 16_384, false, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.3-codex' => ['GPT-5.3 Codex', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.3-codex-spark' => ['GPT-5.3 Codex Spark', Api::AzureOpenAiResponses, 128_000, 32_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.4' => ['GPT-5.4', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0],
        'gpt-5.4-mini' => ['GPT-5.4 mini', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0],
        'gpt-5.4-nano' => ['GPT-5.4 nano', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0],
        'gpt-5.4-pro' => ['GPT-5.4 Pro', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0],
        'gpt-5.5' => ['GPT-5.5', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0],
        'gpt-5.5-pro' => ['GPT-5.5 Pro', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25],
        'gpt-5.6-sol' => ['GPT-5.6 Sol', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0],
        'gpt-5.6-terra' => ['GPT-5.6 Terra', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5],
        'gpt-6-astra' => ['GPT-6 Astra', Api::AzureOpenAiResponses, 272_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5],
        'gpt-6-luna' => ['GPT-6 Luna', Api::AzureOpenAiResponses, 272_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125],
        'gpt-6-sol' => ['GPT-6 Sol', Api::AzureOpenAiResponses, 272_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5],
        'gpt-6.1-sol' => ['GPT-6.1 Sol', Api::AzureOpenAiResponses, 272_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5],
        'gpt-daybreak-blue-latest' => ['Daybreak Blue', Api::AzureOpenAiResponses, 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0],
        'gpt-daybreak-red-latest' => ['Daybreak Red', Api::AzureOpenAiResponses, 400_000, 128_000, true, true, 12.5, 75.0, 1.25, 15.625],
        'gpt-realtime-2.1' => ['GPT-Realtime-2.1', Api::AzureOpenAiResponses, 128_000, 32_000, true, true, 4.0, 24.0, 0.4, 0.0],
        'o1' => ['o1', Api::AzureOpenAiResponses, 200_000, 100_000, true, true, 15.0, 60.0, 7.5, 0.0],
        'o1-pro' => ['o1-pro', Api::AzureOpenAiResponses, 200_000, 100_000, true, true, 150.0, 600.0, 0.0, 0.0],
        'o3' => ['o3', Api::AzureOpenAiResponses, 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0],
        'o3-mini' => ['o3-mini', Api::AzureOpenAiResponses, 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0],
        'o3-pro' => ['o3-pro', Api::AzureOpenAiResponses, 200_000, 100_000, true, true, 20.0, 80.0, 0.0, 0.0],
        'o4-mini' => ['o4-mini', Api::AzureOpenAiResponses, 200_000, 100_000, true, true, 1.1, 4.4, 0.275, 0.0],
        // <<< generated
    ];

    /**
     * ChatGPT's Codex backend (`Providers\OpenAiCodexResponses`), signed in with a ChatGPT Plus/Pro
     * subscription. Not from models.dev: upstream's generator keeps "a small, explicit list to avoid
     * aliases" (`codexModels`), at Codex's 272k window ("Older model limits are based on observed server
     * behavior; GPT-5.6 and GPT-6 use Codex's 272k default catalog limit") and 128k output, priced at
     * `withOpenAiLongContextPricing(OPENAI_STANDARD_COSTS[id])` — the generator's `CODEX_MODELS`.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>}>
     */
    private const array OPENAI_CODEX_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'gpt-5.3-codex-spark' => ['GPT-5.3 Codex Spark', 128_000, 128_000, true, false, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.5' => ['GPT-5.5', 272_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', 272_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]]],
        'gpt-5.6-sol' => ['GPT-5.6 Sol', 272_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]]],
        'gpt-5.6-terra' => ['GPT-5.6 Terra', 272_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]]],
        'gpt-6-astra' => ['GPT-6 Astra', 272_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]]],
        'gpt-6-luna' => ['GPT-6 Luna', 272_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],
        'gpt-6-sol' => ['GPT-6 Sol', 272_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'gpt-6.1-sol' => ['GPT-6.1 Sol', 272_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]]],
        // <<< generated
    ];

    /**
     * Every built-in classifier model, keyed `provider/id` — upstream's `CLASSIFIER_MODELS`: models.dev's
     * decision model `typesafe/jev-latest` as `typesafe/jev-latest` (`loadModelsDevClassifierModels()`,
     * "The canonical models.dev entry has no direct-provider pricing … so classify() results carry
     * token counts but price them at zero"), OpenRouter's `?output_modalities=decisions` listing and
     * Vercel AI Gateway's `decision` models (both on TypeSafe's System One), OpenCode Zen's hand-kept
     * Jev rows ("Neither its /zen/v1/models listing nor models.dev carries metadata for it") and
     * Workers AI's ("Workers AI has no unauthenticated catalog and models.dev does not list its
     * System One models yet").
     *
     * Each row is `[name, api, baseUrl, contextWindow, input, $in, $out, $cacheRead, $cacheWrite]`. No
     * `inputLimits`: the generator's `applyImageInputMetadata()` never sees a classifier.
     *
     * Not here: `openai/gpt-6-luna` on the `openai-decisions` API, which the published 1.1.0 catalogue
     * carries and the reference commit (98d2e1947) does not have.
     *
     * @var array<string, array{0: string, 1: ClassifierApi, 2: string, 3: int, 4: list<string>, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array CLASSIFIER_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'cloudflare-workers-ai/@cf/cloudflare/clef' => ['Clef', ClassifierApi::CloudflareWorkersAiSystemOne, 'https://api.cloudflare.com/client/v4/accounts/{CLOUDFLARE_ACCOUNT_ID}/ai', 65_536, ['text'], 0.24, 0.0, 0.0, 0.0],
        'cloudflare-workers-ai/@cf/cloudflare/clef-flash' => ['Clef Flash', ClassifierApi::CloudflareWorkersAiSystemOne, 'https://api.cloudflare.com/client/v4/accounts/{CLOUDFLARE_ACCOUNT_ID}/ai', 65_536, ['text'], 0.09, 0.0, 0.0, 0.0],
        'cloudflare-workers-ai/typesafe/jev' => ['Jev', ClassifierApi::CloudflareWorkersAiSystemOne, 'https://api.cloudflare.com/client/v4/accounts/{CLOUDFLARE_ACCOUNT_ID}/ai', 32_000, ['text'], 0.0, 0.0, 0.0, 0.0],
        'opencode/jev-1.13' => ['Jev 1.13', ClassifierApi::TypesafeSystemOne, 'https://opencode.ai/zen/v1', 32_000, ['text'], 0.042, 0.0, 0.0, 0.0],
        'opencode/jev-1.13-free' => ['Jev 1.13 Free', ClassifierApi::TypesafeSystemOne, 'https://opencode.ai/zen/v1', 32_000, ['text'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/cloudflare/clef' => ['Cloudflare: Clef', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 65_536, ['text', 'image'], 0.24, 0.0, 0.0, 0.0],
        'openrouter/cloudflare/clef-flash' => ['Cloudflare: Clef Flash', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 16_384, ['text', 'image'], 0.021, 0.0, 0.0, 0.0],
        'openrouter/inception/mercury-decide:free' => ['Inception: Mercury Decide (free)', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 32_768, ['text'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/jaredpalmer/kev-4b' => ['Jared Palmer: Kev 4B', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 8_192, ['text'], 0.042, 0.0, 0.0, 0.0],
        'openrouter/liquid/d1' => ['LiquidAI: d1', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 65_536, ['text'], 0.04, 0.0, 0.04, 0.0],
        'openrouter/openai/gpt-6-luna-decisions' => ['OpenAI: GPT-6 Luna Decisions', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 1_050_000, ['text', 'image'], 0.1, 0.0, 0.0, 0.0],
        'openrouter/perplexity/pplx-decider-v1.1-27b' => ['Perplexity: Decider V1.1 27B', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 262_144, ['text', 'image'], 0.02, 0.0, 0.0, 0.0],
        'openrouter/respan/span-01' => ['Respan: Span-01', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 4_096, ['text'], 0.02, 0.0, 0.0, 0.0],
        'openrouter/respan/span-01-lite' => ['Respan: Span-01 Lite', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 4_096, ['text'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/respan/span-01-lite:free' => ['Respan: Span-01 Lite (free)', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 4_096, ['text'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/togethercomputer/tev1-4b-experimental' => ['Together: Tev1 4B Experimental', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 32_768, ['text'], 0.042, 0.0, 0.0, 0.0],
        'openrouter/typesafe/jev-1.13' => ['TypeSafe: Jev 1.13', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 64_000, ['text'], 0.042, 0.0, 0.0, 0.0],
        'openrouter/upstage/solar-decide' => ['Upstage: Solar Decide', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 524_288, ['text'], 0.05, 0.0, 0.05, 0.0],
        'openrouter/upstage/solar-decide-flash' => ['Upstage: Solar Decide Flash', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 524_288, ['text'], 0.05, 0.0, 0.05, 0.0],
        'openrouter/~typesafe/jev-latest' => ['TypeSafe: Jev Latest', ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1', 64_000, ['text'], 0.042, 0.0, 0.0, 0.0],
        'typesafe/jev-latest' => ['Jev', ClassifierApi::TypesafeSystemOne, 'https://api.typesafe.ai/v1/', 64_000, ['text'], 0.0, 0.0, 0.0, 0.0],
        'vercel-ai-gateway/convaiinnovations/laya' => ['Laya', ClassifierApi::TypesafeSystemOne, 'https://ai-gateway.vercel.sh/typesafe/v1', 8_192, ['text'], 0.0, 0.0, 0.0, 0.0],
        'vercel-ai-gateway/convaiinnovations/laya-free' => ['Laya (Free)', ClassifierApi::TypesafeSystemOne, 'https://ai-gateway.vercel.sh/typesafe/v1', 8_192, ['text'], 0.0, 0.0, 0.0, 0.0],
        'vercel-ai-gateway/liquid/d1' => ['Liquid d1', ClassifierApi::TypesafeSystemOne, 'https://ai-gateway.vercel.sh/typesafe/v1', 65_536, ['text'], 0.04, 0.0, 0.0, 0.0],
        'vercel-ai-gateway/openai/gpt-6-luna-decisions' => ['GPT-6 Luna Decisions', ClassifierApi::TypesafeSystemOne, 'https://ai-gateway.vercel.sh/typesafe/v1', 1_050_000, ['text'], 0.1, 0.0, 0.0, 0.0],
        'vercel-ai-gateway/typesafe-ai/jev' => ['Jev', ClassifierApi::TypesafeSystemOne, 'https://ai-gateway.vercel.sh/typesafe/v1', 32_000, ['text'], 0.042, 0.0, 0.0, 0.0],
        // <<< generated
    ];

    /**
     * Every built-in image model, keyed `provider/id` — upstream's `IMAGE_MODELS`: OpenRouter's
     * `?output_modalities=image` listing (`buildOpenRouterCatalog()`), each model whose output
     * modalities include `image`, on the `openrouter-images` API.
     *
     * Each row is `[name, api, baseUrl, input, output, $in, $out, $cacheRead, $cacheWrite]`; the
     * `inputLimits` are `applyImageInputMetadata()`'s, worked out from `input` (`inputLimits()`).
     *
     * @var array<string, array{0: string, 1: ImageApi, 2: string, 3: list<string>, 4: list<string>, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array IMAGE_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'openrouter/black-forest-labs/flux-3-image' => ['Black Forest Labs: FLUX.3 Image', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/black-forest-labs/flux.2-flex' => ['Black Forest Labs: FLUX.2 Flex', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/black-forest-labs/flux.2-klein-4b' => ['Black Forest Labs: FLUX.2 Klein 4B', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/black-forest-labs/flux.2-max' => ['Black Forest Labs: FLUX.2 Max', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/black-forest-labs/flux.2-pro' => ['Black Forest Labs: FLUX.2 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/bytedance-seed/seedream-4.5' => ['ByteDance Seed: Seedream 4.5', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/bytedance-seed/seedream-5-0-flash' => ['ByteDance Seed: Seedream 5.0 Flash', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/bytedance-seed/seedream-5-0-lite' => ['ByteDance Seed: Seedream 5.0 Lite', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/bytedance-seed/seedream-5-0-pro' => ['ByteDance Seed: Seedream 5.0 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/google/gemini-2.5-flash-image' => ['Google: Nano Banana (Gemini 2.5 Flash Image)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 0.3, 2.5, 0.03, 0.083333],
        'openrouter/google/gemini-3-pro-image' => ['Google: Nano Banana Pro (Gemini 3 Pro Image)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 2.0, 12.0, 0.2, 0.375],
        'openrouter/google/gemini-3-pro-image-preview' => ['Google: Nano Banana Pro (Gemini 3 Pro Image Preview)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 2.0, 12.0, 0.2, 0.375],
        'openrouter/google/gemini-3.1-flash-image' => ['Google: Nano Banana 2 (Gemini 3.1 Flash Image)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 0.5, 3.0, 0.0, 0.0],
        'openrouter/google/gemini-3.1-flash-image-preview' => ['Google: Nano Banana 2 (Gemini 3.1 Flash Image Preview)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 0.5, 3.0, 0.0, 0.0],
        'openrouter/google/gemini-3.1-flash-lite-image' => ['Google: Nano Banana 2 Lite (Gemini 3.1 Flash Lite Image)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 0.25, 1.5, 0.0, 0.0],
        'openrouter/google/gemini-nano-banana-2.1' => ['Google: Nano Banana 2.1', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 1.5, 7.5, 0.0, 0.0],
        'openrouter/inclusionai/ming-image-0.1-design' => ['inclusionAI: Ming Image 0.1 Design', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/inclusionai/ming-image-0.1-design-layer' => ['inclusionAI: Ming Image 0.1 Design Layer', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/krea/krea-2-large' => ['Krea: Krea 2 Large', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/krea/krea-2-medium' => ['Krea: Krea 2 Medium', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/krea/krea-2-medium-turbo' => ['Krea: Krea 2 Medium Turbo', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/meta/muse-image' => ['Meta: Muse Image', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/microsoft/mai-image-2.5' => ['Microsoft AI: MAI-Image-2.5', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 5.0, 0.0, 0.0, 0.0],
        'openrouter/microsoft/mai-image-2.5-pro' => ['Microsoft AI: MAI-Image-2.5 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 5.0, 0.0, 0.0, 0.0],
        'openrouter/microsoft/mai-image-2.6' => ['Microsoft AI: MAI-Image-2.6', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 5.0, 0.0, 0.0, 0.0],
        'openrouter/microsoft/mai-image-2.6-flash' => ['Microsoft AI: MAI-Image-2.6 Flash', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 1.75, 0.0, 0.0, 0.0],
        'openrouter/openai/gpt-5-image' => ['OpenAI: GPT-5 Image', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 10.0, 10.0, 1.25, 0.0],
        'openrouter/openai/gpt-5-image-mini' => ['OpenAI: GPT-5 Image Mini', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 2.5, 2.0, 0.25, 0.0],
        'openrouter/openai/gpt-5.4-image-2' => ['OpenAI: GPT-5.4 Image 2', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['image', 'text'], ['image', 'text'], 8.0, 15.0, 2.0, 0.0],
        'openrouter/openai/gpt-image-1' => ['OpenAI: GPT Image 1', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 10.0, 10.0, 1.25, 0.0],
        'openrouter/openai/gpt-image-1-mini' => ['OpenAI: GPT Image 1 Mini', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 2.5, 2.5, 0.25, 0.0],
        'openrouter/openai/gpt-image-2' => ['OpenAI: GPT Image 2', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 8.0, 8.0, 2.0, 0.0],
        'openrouter/openai/gpt-image-2.5-flare' => ['OpenAI: GPT Image 2.5 Flare', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 8.0, 8.0, 2.0, 0.0],
        'openrouter/openai/gpt-image-2.5-sunburst' => ['OpenAI: GPT Image 2.5 Sunburst', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 8.0, 8.0, 2.0, 0.0],
        'openrouter/openrouter/auto' => ['Auto Router', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['text', 'image'], -1000000.0, -1000000.0, 0.0, 0.0],
        'openrouter/openrouter/auto-beta' => ['Auto Router (Beta)', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['text', 'image'], -1000000.0, -1000000.0, 0.0, 0.0],
        'openrouter/qwen/qwen-image-3' => ['Qwen: Qwen Image 3', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/qwen/qwen-image-3-pro' => ['Qwen: Qwen Image 3 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v3' => ['Recraft: Recraft V3', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4' => ['Recraft: Recraft V4', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-pro' => ['Recraft: Recraft V4 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-pro-vector' => ['Recraft: Recraft V4 Pro Vector', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-styles' => ['Recraft: Recraft V4 Styles', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-styles-pro' => ['Recraft: Recraft V4 Styles Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-styles-pro-vector' => ['Recraft: Recraft V4 Styles Pro Vector', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-styles-vector' => ['Recraft: Recraft V4 Styles Vector', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4-vector' => ['Recraft: Recraft V4 Vector', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1' => ['Recraft: Recraft V4.1', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1-flash' => ['Recraft: Recraft V4.1 Flash', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1-pro' => ['Recraft: Recraft V4.1 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1-pro-vector' => ['Recraft: Recraft V4.1 Pro Vector', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1-utility' => ['Recraft: Recraft V4.1 Utility', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1-utility-pro' => ['Recraft: Recraft V4.1 Utility Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/recraft/recraft-v4.1-vector' => ['Recraft: Recraft V4.1 Vector', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/sourceful/riverflow-v2-fast' => ['Sourceful: Riverflow V2 Fast', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/sourceful/riverflow-v2-pro' => ['Sourceful: Riverflow V2 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/sourceful/riverflow-v2.5-fast' => ['Sourceful: Riverflow V2.5 Fast', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/sourceful/riverflow-v2.5-pro' => ['Sourceful: Riverflow V2.5 Pro', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/tencent/hy-image-v3.5-preview' => ['Tencent: Hy Image 3.5 Preview', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/x-ai/grok-imagine-image-2.0' => ['xAI: Grok Imagine Image 2.0', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        'openrouter/x-ai/grok-imagine-image-quality' => ['SpaceXAI: Grok Imagine Image Quality', ImageApi::OpenRouterImages, 'https://openrouter.ai/api/v1', ['text', 'image'], ['image'], 0.0, 0.0, 0.0, 0.0],
        // <<< generated
    ];

    /**
     * The Radius gateway's (`Providers\PiMessages`): "its unauthenticated public catalog;
     * authenticated clients overlay it at runtime" — the generator reads `https://radius.pi.dev/v1/config`
     * (upstream's `fetchRadiusModels()`), and each row is the gateway's model as it sent it, its own
     * `thinkingLevelMap` included. The runtime overlay (`radiusProvider().refreshModels()`, which asks
     * the gateway again with the account's key) is not ported: pig has no models store for it to
     * publish into.
     *
     * The rows were written from upstream's published catalogue (`@earendil-works/pi-ai` 1.1.0), as the
     * gateway was not reachable to regenerate.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float, thinkingLevelMap?: array<string, string|null>, tiers?: list<array{0: int, 1: float, 2: float, 3: float, 4: float}>}>
     */
    private const array RADIUS_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'balanced' => ['Balanced', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'cheap' => ['Cheap', 1_000_000, 32_768, true, true, 0.3, 1.2, 0.006, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-fable-5' => ['Claude Fable 5', 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'thinkingLevelMap' => ['off' => null, 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-fable-5-1' => ['Claude Fable 5.1', 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5, 'thinkingLevelMap' => ['off' => null, 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-haiku-4-5' => ['Claude Haiku 4.5', 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25],
        'claude-haiku-5-5' => ['Claude Haiku 5.5', 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],
        'claude-opus-4-5' => ['Claude Opus 4.5', 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'claude-opus-4-8' => ['Claude Opus 4.8', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5' => ['Claude Opus 5', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => null, 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5-5' => ['Claude Opus 5.5', 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5', 1_000_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-5' => ['Claude Sonnet 5', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', 1_000_000, 32_768, true, true, 0.3, 1.2, 0.006, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3' => ['GLM-5.3', 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'xhigh']],
        'glm-5.3-flash' => ['GLM-5.3 Flash', 1_000_000, 131_072, true, false, 0.15, 0.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'gpt-5.3-codex' => ['GPT 5.3 Codex', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4' => ['GPT 5.4', 272_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 5.0, 22.5, 0.5, 0.0]]],
        'gpt-5.4-mini' => ['GPT 5.4 Mini', 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5' => ['GPT 5.5', 272_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'gpt-5.6-luna' => ['GPT 5.6 Luna', 272_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]]],
        'gpt-5.6-sol' => ['GPT 5.6 Sol', 272_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]]],
        'gpt-5.6-terra' => ['GPT 5.6 Terra', 272_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]]],
        'gpt-6-astra' => ['GPT 6 Astra', 272_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]]],
        'gpt-6-luna' => ['GPT 6 Luna', 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],
        'gpt-6-sol' => ['GPT 6 Sol', 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'grok-4.7' => ['Grok 4.7', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]]],
        'kimi-k3' => ['Kimi K3', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'precise' => ['Precise', 272_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]]],
        // <<< generated
    ];

    /**
     * The rest of upstream's built-in providers that speak an API pig already speaks
     * (`openai-completions`, `anthropic-messages`, `openai-responses`, `google-generative-ai`), in
     * the order `providers/all.ts`' `builtinProviders()` lists them: provider => its table, and its
     * base URL per API — the `baseUrl` upstream's generator writes on every row of that provider and
     * API (`providers/<id>.ts` names the same one as the provider's own).
     *
     * One table per provider, every table the same shape: the nine columns, with an api column after
     * the name in the four whose provider serves more than one API (Fireworks, both OpenCodes,
     * OpenRouter), as Copilot's and Azure's tables have. A row says only what the catalogue said
     * about the model; everything upstream's generator works out from the provider and the id — the
     * compat, the level-map rules, the image limits — is `addCatalogueModels()`'s, the way it is
     * `table()`'s for the tables above. The keys a row may carry besides the columns:
     *
     * - `thinkingLevelMap`: the map the generator built for the row before its metadata passes
     *   (OpenRouter's from its `reasoning` field, Baseten's, Together's, the Token Plans', z.ai's,
     *   Google's on OpenCode, a hand-added row's own);
     * - `effortLevelMap`: models.dev's verified efforts, for `applyModelsDevReasoningOptionMetadata()`;
     * - `tiers`: the cost tiers;
     * - `supportsToggle` / `supportsEffort`: models.dev lists a `toggle` / an `effort` reasoning option,
     *   which Baseten's and Fireworks' compat and Fireworks' level map are decided by;
     * - `cacheControlFormat`: an OpenCode model models.dev serves through `@ai-sdk/alibaba`.
     *
     * **The rows were written from upstream's published catalogue** (`@earendil-works/pi-ai` 1.1.0),
     * because neither models.dev nor OpenRouter's, Vercel's or NVIDIA's model lists were reachable to
     * regenerate. That catalogue is the generator's *output*, after its metadata passes, so a row
     * written from it carries the level map the model ended with wherever the id rules alone do not
     * give it back — models.dev's efforts already merged in, under `thinkingLevelMap` — and carries no
     * `effortLevelMap`; the toggle/effort flags are read back off the compat it ended with. The next
     * regeneration writes each key from its source.
     */
    private const array CATALOGUE_PROVIDERS = [
        'ant-ling' => [self::ANT_LING_MODELS, ['openai-completions' => 'https://api.ant-ling.com/v1']],
        'baseten' => [self::BASETEN_MODELS, ['openai-completions' => 'https://inference.baseten.co/v1']],
        // `{CLOUDFLARE_ACCOUNT_ID}` and `{CLOUDFLARE_GATEWAY_ID}` are filled in per request
        // (`Providers\Cloudflare`); see `api/cloudflare.ts` for what each endpoint is.
        'cloudflare-ai-gateway' => [self::CLOUDFLARE_AI_GATEWAY_MODELS, [
            'anthropic-messages' => Providers\Cloudflare::CLOUDFLARE_AI_GATEWAY_ANTHROPIC_BASE_URL,
            'openai-completions' => Providers\Cloudflare::CLOUDFLARE_AI_GATEWAY_COMPAT_BASE_URL,
            'openai-responses' => Providers\Cloudflare::CLOUDFLARE_AI_GATEWAY_OPENAI_BASE_URL,
        ]],
        'cloudflare-workers-ai' => [self::CLOUDFLARE_WORKERS_AI_MODELS, ['openai-completions' => Providers\Cloudflare::CLOUDFLARE_WORKERS_AI_BASE_URL]],
        'deepseek' => [self::DEEPSEEK_MODELS, ['openai-completions' => 'https://api.deepseek.com']],
        'fireworks' => [self::FIREWORKS_MODELS, [
            // "Fireworks Anthropic-compatible API - SDK appends /v1/messages."
            'anthropic-messages' => 'https://api.fireworks.ai/inference',
            'openai-completions' => 'https://api.fireworks.ai/inference/v1',
        ]],
        'huggingface' => [self::HUGGINGFACE_MODELS, ['openai-completions' => 'https://router.huggingface.co/v1']],
        // "Kimi For Coding's Anthropic-compatible API - SDK appends /v1/messages"
        'kimi-coding' => [self::KIMI_CODING_MODELS, ['anthropic-messages' => 'https://api.kimi.com/coding']],
        'meta' => [self::META_MODELS, ['openai-responses' => 'https://api.meta.ai/v1']],
        // "MiniMax's Anthropic-compatible API - SDK appends /v1/messages"
        'minimax' => [self::MINIMAX_MODELS, ['anthropic-messages' => 'https://api.minimax.io/anthropic']],
        'minimax-cn' => [self::MINIMAX_CN_MODELS, ['anthropic-messages' => 'https://api.minimaxi.com/anthropic']],
        'moonshotai' => [self::MOONSHOTAI_MODELS, ['openai-completions' => 'https://api.moonshot.ai/v1']],
        'moonshotai-cn' => [self::MOONSHOTAI_CN_MODELS, ['openai-completions' => 'https://api.moonshot.cn/v1']],
        'nvidia' => [self::NVIDIA_MODELS, ['openai-completions' => 'https://integrate.api.nvidia.com/v1']],
        // "Anthropic SDK appends /v1/messages to baseURL"; the other three APIs are under `/v1`.
        'opencode' => [self::OPENCODE_MODELS, [
            'anthropic-messages' => 'https://opencode.ai/zen',
            'google-generative-ai' => 'https://opencode.ai/zen/v1',
            'openai-completions' => 'https://opencode.ai/zen/v1',
            'openai-responses' => 'https://opencode.ai/zen/v1',
        ]],
        'opencode-go' => [self::OPENCODE_GO_MODELS, [
            'anthropic-messages' => 'https://opencode.ai/zen/go',
            'google-generative-ai' => 'https://opencode.ai/zen/go/v1',
            'openai-completions' => 'https://opencode.ai/zen/go/v1',
            'openai-responses' => 'https://opencode.ai/zen/go/v1',
        ]],
        // `buildOpenRouterCatalog()`: `anthropic/…` models (but not `:batch` ones) on the Messages API.
        'openrouter' => [self::OPENROUTER_MODELS, [
            'anthropic-messages' => 'https://openrouter.ai/api',
            'openai-completions' => 'https://openrouter.ai/api/v1',
        ]],
        'qwen-token-plan' => [self::QWEN_TOKEN_PLAN_MODELS, ['openai-completions' => 'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1']],
        'qwen-token-plan-cn' => [self::QWEN_TOKEN_PLAN_CN_MODELS, ['openai-completions' => 'https://token-plan.cn-beijing.maas.aliyuncs.com/compatible-mode/v1']],
        'qwen-token-plan-individual' => [self::QWEN_TOKEN_PLAN_INDIVIDUAL_MODELS, ['openai-completions' => 'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1']],
        'together' => [self::TOGETHER_MODELS, ['openai-completions' => 'https://api.together.ai/v1']],
        'vercel-ai-gateway' => [self::VERCEL_AI_GATEWAY_MODELS, ['anthropic-messages' => 'https://ai-gateway.vercel.sh']],
        'xiaomi' => [self::XIAOMI_MODELS, ['openai-completions' => 'https://api.xiaomimimo.com/v1']],
        'xiaomi-token-plan-ams' => [self::XIAOMI_TOKEN_PLAN_AMS_MODELS, ['openai-completions' => 'https://token-plan-ams.xiaomimimo.com/v1']],
        'xiaomi-token-plan-cn' => [self::XIAOMI_TOKEN_PLAN_CN_MODELS, ['openai-completions' => 'https://token-plan-cn.xiaomimimo.com/v1']],
        'xiaomi-token-plan-sgp' => [self::XIAOMI_TOKEN_PLAN_SGP_MODELS, ['openai-completions' => 'https://token-plan-sgp.xiaomimimo.com/v1']],
        'zai-coding-cn' => [self::ZAI_CODING_CN_MODELS, ['openai-completions' => 'https://open.bigmodel.cn/api/coding/paas/v4']],
    ];

    /** Upstream's generator `NVIDIA_HEADERS`, on every NVIDIA row. */
    private const array NVIDIA_HEADERS = ['NVCF-POLL-SECONDS' => '3600'];

    /**
     * Upstream's generator `TOGETHER_REASONING_ONLY_MODELS`, `TOGETHER_REASONING_EFFORT_MODELS` and
     * `TOGETHER_TOGGLE_REASONING_EFFORT_MODELS`, which pick a Together model's compat
     * (`getTogetherCompat()`) — the level maps they pick are the generator's, written into the rows.
     * The first is also its `detectOpenAICompletionsCompat()`'s `isTogetherReasoningOnly`.
     */
    private const array TOGETHER_REASONING_ONLY_MODELS = ['deepseek-ai/DeepSeek-R1', 'MiniMaxAI/MiniMax-M2.7'];

    private const array TOGETHER_REASONING_EFFORT_MODELS = ['openai/gpt-oss-20b', 'openai/gpt-oss-120b'];

    private const array TOGETHER_TOGGLE_REASONING_EFFORT_MODELS = ['deepseek-ai/DeepSeek-V4-Pro-0813'];

    /**
     * Upstream's generator `FIREWORKS_ADAPTIVE_THINKING_FALLBACK_MODELS`: "Verified against Fireworks
     * Messages raw_output on 2026-09-10 (#9323). Fall back to verified support when models.dev omits
     * effort metadata; this is not an allowlist. Any Fireworks Messages model advertising effort uses
     * adaptive thinking."
     */
    private const array FIREWORKS_ADAPTIVE_THINKING_FALLBACK_MODELS = [
        'accounts/fireworks/models/deepseek-v4-flash-0731',
        'accounts/fireworks/models/deepseek-v4-flash-vision-exp',
        'accounts/fireworks/models/deepseek-v4-pro-0813',
        'accounts/fireworks/models/qwen3p8-max',
        'accounts/fireworks/models/qwen3p8-2p4t-a95b',
    ];

    /** Upstream's generator `OPENCODE_OPENAI_COMPLETIONS_LONG_CACHE_RETENTION_UNSUPPORTED_MODELS`, keyed `provider:id`. */
    private const array OPENCODE_OPENAI_COMPLETIONS_LONG_CACHE_RETENTION_UNSUPPORTED_MODELS = [
        'opencode:deepseek-v4-flash',
        'opencode:deepseek-v4-pro',
        'opencode:kimi-k2.5',
        'opencode:kimi-k2.6',
        'opencode:minimax-m2.7',
        'opencode-go:kimi-k2.6',
    ];

    /** Upstream's generator `QWEN_TOKEN_PLAN_PROVIDER_IDS`. */
    private const array QWEN_TOKEN_PLAN_PROVIDER_IDS = ['qwen-token-plan', 'qwen-token-plan-cn', 'qwen-token-plan-individual'];

    /** Upstream's generator `OPENCODE_GO_GLM52_THINKING_LEVEL_MAP`. */
    private const array OPENCODE_GO_GLM52_THINKING_LEVEL_MAP = ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'max' => 'max'];

    /** Upstream's generator `ANT_LING_RING_THINKING_LEVEL_MAP`: "Ring reasons by default. Only high/xhigh have documented explicit effort controls." */
    private const array ANT_LING_RING_THINKING_LEVEL_MAP = ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh'];

    /**
     * Ant Ling's (`api.ant-ling.com`): upstream's generator's own three rows (`antLingModels`), not
     * models.dev's.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array ANT_LING_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'Ling-2.6-1T' => ['Ling 2.6 1T', 262_144, 65_536, false, false, 0.06, 0.25, 0.0, 0.0],
        'Ling-2.6-flash' => ['Ling 2.6 Flash', 262_144, 65_536, false, false, 0.01, 0.02, 0.0, 0.0],
        'Ring-2.6-1T' => ['Ring 2.6 1T', 262_144, 65_536, true, false, 0.06, 0.25, 0.0, 0.0],
        // <<< generated
    ];

    /**
     * Baseten's Model APIs: models.dev's `baseten` entry, deprecated models left out
     * (`processBasetenModels()`), the level map the generator builds — GLM-5.2's fixed one, an
     * explicit `off: "off"`/`high` toggle where models.dev lists a toggle, else the verified efforts.
     * "Baseten's GLM-5.2 endpoints are text-only despite models.dev reporting image input."
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array BASETEN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'deepseek-ai/DeepSeek-V4-Flash-0731' => ['DeepSeek V4 Flash 0731', 1_048_576, 384_000, true, false, 0.13, 0.26, 0.028, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'supportsEffort' => true],
        'deepseek-ai/DeepSeek-V4-Pro' => ['DeepSeek V4 Pro', 1_048_576, 262_144, true, false, 1.74, 3.48, 0.145, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'supportsEffort' => true],
        'deepseek-ai/DeepSeek-V4-Pro-0813' => ['DeepSeek V4 Pro 0813', 1_048_576, 262_144, true, false, 1.32, 3.96, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'deepseek-ai/DeepSeek-V4.1-Flash' => ['DeepSeek V4.1 Flash', 1_048_576, 32_768, true, true, 0.3, 1.2, 0.03, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'deepseek-ai/DeepSeek-V4.1-Flash-Fast' => ['deepseek-ai/DeepSeek-V4.1-Flash-Fast', 1_048_576, 32_768, true, true, 0.6, 2.4, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'moonshotai/Kimi-K2.5' => ['Kimi K2.5', 262_000, 262_000, true, true, 0.6, 3.0, 0.12, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'moonshotai/Kimi-K2.6' => ['Kimi K2.6', 262_000, 262_000, true, true, 0.95, 4.0, 0.16, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'moonshotai/Kimi-K2.7-Code' => ['Kimi K2.7 Code', 262_000, 262_000, true, true, 0.95, 4.0, 0.16, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'moonshotai/Kimi-K3' => ['Kimi K3', 1_048_576, 262_144, true, true, 3.0, 15.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'nvidia/NVIDIA-Nemotron-3-Ultra-550B-A55B' => ['Nemotron Ultra', 202_800, 202_800, true, false, 0.6, 2.4, 0.12, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'nvidia/Nemotron-120B-A12B' => ['Nemotron Super', 202_800, 202_800, true, false, 0.3, 0.75, 0.06, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'openai/gpt-oss-120b' => ['OpenAI GPT 120B', 128_072, 128_072, true, false, 0.1, 0.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'supportsEffort' => true],
        'thinkingmachines/inkling' => ['Inkling', 1_048_576, 32_768, true, true, 1.0, 4.05, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'supportsEffort' => true],
        'thinkingmachines/inkling-small' => ['Inkling Small', 1_048_576, 32_768, true, true, 0.5, 1.2, 0.1, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'supportsEffort' => true],
        'zai-org/GLM-4.7' => ['GLM 4.7', 200_000, 200_000, true, false, 0.6, 2.2, 0.12, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'zai-org/GLM-5' => ['GLM 5', 202_800, 202_800, true, false, 0.95, 3.15, 0.2, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'zai-org/GLM-5.1' => ['GLM 5.1', 202_800, 202_800, true, false, 1.3, 4.3, 0.26, 0.0, 'thinkingLevelMap' => ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsToggle' => true],
        'zai-org/GLM-5.2' => ['GLM 5.2', 1_048_576, 262_144, true, false, 1.4, 4.4, 0.3, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'zai-org/GLM-5.2-Fast' => ['GLM 5.2 Fast', 1_048_576, 262_144, true, false, 2.1, 6.6, 0.21, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'zai-org/GLM-5.3' => ['GLM 5.3', 1_048_576, 262_144, true, true, 1.4, 4.4, 0.14, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'zai-org/GLM-5.3-Fast' => ['GLM 5.3 Fast', 1_048_576, 262_144, true, true, 2.1, 6.6, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'zai-org/GLM-5.3-Flash' => ['GLM 5.3 Flash', 1_048_576, 131_072, true, true, 0.15, 0.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        // <<< generated
    ];

    /**
     * Upstream's gateway catalogue: models.dev's `cloudflare-ai-gateway` entry, `openai/…` on the
     * Responses passthrough, `anthropic/…` on the Messages one under the dashed id Anthropic accepts,
     * `workers-ai/…` on `/compat` — and the Workers AI catalogue mirrored there under that prefix,
     * "so the gateway keeps its OpenAI-compatible models stable". OpenAI's ids at OpenAI's list prices
     * ("Cloudflare AI Gateway passes OpenAI usage through at OpenAI list prices").
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array CLOUDFLARE_AI_GATEWAY_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'claude-fable-5' => ['Claude Fable 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-fable-5-1' => ['Claude Fable 5.1', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-haiku-4-5' => ['Claude Haiku 4.5 (latest)', Api::AnthropicMessages, 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25],
        'claude-opus-4-5' => ['Claude Opus 4.5 (latest)', Api::AnthropicMessages, 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'claude-opus-4-6' => ['Claude Opus 4.6', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-opus-4-7' => ['Claude Opus 4.7', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4-8' => ['Claude Opus 4.8', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5' => ['Claude Opus 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5-5' => ['Claude Opus 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5 (latest)', Api::AnthropicMessages, 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-4-6' => ['Claude Sonnet 4.6', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 3.0, 15.0, 0.3, 3.75, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-sonnet-5' => ['Claude Sonnet 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-4.1' => ['GPT-4.1', Api::OpenAiResponses, 1_047_576, 32_768, false, true, 2.0, 8.0, 0.5, 0.0],
        'gpt-4.1-mini' => ['GPT-4.1 mini', Api::OpenAiResponses, 1_047_576, 32_768, false, true, 0.4, 1.6, 0.1, 0.0],
        'gpt-4.1-nano' => ['GPT-4.1 nano', Api::OpenAiResponses, 1_000_000, 32_768, false, true, 0.1, 0.4, 0.025, 0.0],
        'gpt-4o' => ['GPT-4o', Api::OpenAiResponses, 128_000, 16_384, false, true, 1.25, 5.0, 0.625, 0.0],
        'gpt-4o-mini' => ['GPT-4o mini', Api::OpenAiResponses, 128_000, 16_384, false, true, 0.075, 0.3, 0.0375, 0.0],
        'gpt-5' => ['GPT-5', Api::OpenAiResponses, 128_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-mini' => ['GPT-5 Mini', Api::OpenAiResponses, 128_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-nano' => ['GPT-5 Nano', Api::OpenAiResponses, 128_000, 128_000, true, true, 0.05, 0.4, 0.005, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.1' => ['GPT-5.1', Api::OpenAiResponses, 128_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.4' => ['GPT-5.4', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-mini' => ['GPT-5.4 mini', Api::OpenAiResponses, 128_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-nano' => ['GPT-5.4 nano', Api::OpenAiResponses, 128_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-pro' => ['GPT-5.4 Pro', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5' => ['GPT-5.5', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 5.0, 30.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5-pro' => ['GPT-5.5 Pro', Api::OpenAiResponses, 1_000_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-sol' => ['GPT-5.6 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-terra' => ['GPT-5.6 Terra', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-astra' => ['GPT-6 Astra', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-6-luna' => ['GPT-6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-sol' => ['GPT-6 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6.1-sol' => ['GPT-6.1 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'o3' => ['o3', Api::OpenAiResponses, 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o3-mini' => ['o3-mini', Api::OpenAiResponses, 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'o4-mini' => ['o4-mini', Api::OpenAiResponses, 200_000, 100_000, true, true, 1.1, 4.4, 0.275, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'workers-ai/@cf/deepseek-ai/deepseek-v4-flash-0731' => ['DeepSeek V4 Flash 0731', Api::OpenAiCompletions, 1_048_576, 1_048_576, true, false, 0.44, 1.32, 0.014, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'workers-ai/@cf/deepseek-ai/deepseek-v4-pro-0813' => ['DeepSeek V4 Pro 0813', Api::OpenAiCompletions, 1_048_576, 1_048_576, true, false, 1.32, 3.96, 0.044, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'workers-ai/@cf/google/gemma-4-26b-a4b-it' => ['Gemma 4 26B A4B IT', Api::OpenAiCompletions, 256_000, 16_384, true, true, 0.1, 0.3, 0.05, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'workers-ai/@cf/ibm-granite/granite-4.0-h-micro' => ['Granite 4.0 H Micro', Api::OpenAiCompletions, 131_000, 131_000, false, false, 0.017, 0.112, 0.0, 0.0],
        'workers-ai/@cf/meta/llama-3.3-70b-instruct-fp8-fast' => ['Llama 3.3 70B Instruct fp8 Fast', Api::OpenAiCompletions, 24_000, 24_000, false, false, 0.293, 2.253, 0.0, 0.0],
        'workers-ai/@cf/meta/llama-4-scout-17b-16e-instruct' => ['Llama 4 Scout 17B 16E Instruct', Api::OpenAiCompletions, 131_000, 16_384, false, true, 0.27, 0.85, 0.0, 0.0],
        'workers-ai/@cf/mistralai/mistral-small-3.1-24b-instruct' => ['Mistral Small 3.1 24B Instruct', Api::OpenAiCompletions, 128_000, 128_000, false, false, 0.351, 0.555, 0.0, 0.0],
        'workers-ai/@cf/moonshotai/kimi-k2.6' => ['Kimi K2.6', Api::OpenAiCompletions, 262_144, 256_000, true, true, 0.95, 4.0, 0.16, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'workers-ai/@cf/moonshotai/kimi-k2.7-code' => ['Kimi K2.7 Code', Api::OpenAiCompletions, 262_144, 262_144, true, true, 0.95, 4.0, 0.19, 0.0],
        'workers-ai/@cf/nvidia/nemotron-3-120b-a12b' => ['Nemotron 3 Super 120B', Api::OpenAiCompletions, 256_000, 256_000, true, false, 0.5, 1.5, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'workers-ai/@cf/openai/gpt-oss-120b' => ['GPT OSS 120B', Api::OpenAiCompletions, 128_000, 16_384, true, false, 0.35, 0.75, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'workers-ai/@cf/openai/gpt-oss-20b' => ['GPT OSS 20B', Api::OpenAiCompletions, 128_000, 16_384, true, false, 0.2, 0.3, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'workers-ai/@cf/qwen/qwen3-30b-a3b-fp8' => ['Qwen3 30B A3b fp8', Api::OpenAiCompletions, 32_768, 32_768, true, false, 0.0509, 0.335, 0.0, 0.0],
        'workers-ai/@cf/qwen/qwen3.8-27b' => ['Qwen3.8 27B', Api::OpenAiCompletions, 262_144, 262_144, true, true, 0.45, 3.2, 0.05, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'workers-ai/@cf/zai-org/glm-4.7-flash' => ['GLM-4.7-Flash', Api::OpenAiCompletions, 131_072, 131_072, true, false, 0.0605, 0.4, 0.0, 0.0],
        'workers-ai/@cf/zai-org/glm-5.2' => ['Glm 5.2', Api::OpenAiCompletions, 262_144, 256_000, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'workers-ai/@cf/zai-org/glm-5.3' => ['Glm 5.3', Api::OpenAiCompletions, 1_048_576, 1_048_576, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'workers-ai/@cf/zai-org/glm-5.3-flash' => ['Glm 5.3 Flash', Api::OpenAiCompletions, 1_048_576, 1_048_576, true, true, 0.15, 0.5, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * Upstream's Workers AI catalogue: models.dev's `cloudflare-workers-ai` entry on the
     * OpenAI-compatible `/ai/v1` endpoint.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array CLOUDFLARE_WORKERS_AI_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        '@cf/deepseek-ai/deepseek-v4-flash-0731' => ['DeepSeek V4 Flash 0731', 1_048_576, 1_048_576, true, false, 0.44, 1.32, 0.014, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '@cf/deepseek-ai/deepseek-v4-pro-0813' => ['DeepSeek V4 Pro 0813', 1_048_576, 1_048_576, true, false, 1.32, 3.96, 0.044, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '@cf/google/gemma-4-26b-a4b-it' => ['Gemma 4 26B A4B IT', 256_000, 16_384, true, true, 0.1, 0.3, 0.05, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        '@cf/ibm-granite/granite-4.0-h-micro' => ['Granite 4.0 H Micro', 131_000, 131_000, false, false, 0.017, 0.112, 0.0, 0.0],
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast' => ['Llama 3.3 70B Instruct fp8 Fast', 24_000, 24_000, false, false, 0.293, 2.253, 0.0, 0.0],
        '@cf/meta/llama-4-scout-17b-16e-instruct' => ['Llama 4 Scout 17B 16E Instruct', 131_000, 16_384, false, true, 0.27, 0.85, 0.0, 0.0],
        '@cf/mistralai/mistral-small-3.1-24b-instruct' => ['Mistral Small 3.1 24B Instruct', 128_000, 128_000, false, false, 0.351, 0.555, 0.0, 0.0],
        '@cf/moonshotai/kimi-k2.6' => ['Kimi K2.6', 262_144, 256_000, true, true, 0.95, 4.0, 0.16, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        '@cf/moonshotai/kimi-k2.7-code' => ['Kimi K2.7 Code', 262_144, 262_144, true, true, 0.95, 4.0, 0.19, 0.0],
        '@cf/nvidia/nemotron-3-120b-a12b' => ['Nemotron 3 Super 120B', 256_000, 256_000, true, false, 0.5, 1.5, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        '@cf/openai/gpt-oss-120b' => ['GPT OSS 120B', 128_000, 16_384, true, false, 0.35, 0.75, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        '@cf/openai/gpt-oss-20b' => ['GPT OSS 20B', 128_000, 16_384, true, false, 0.2, 0.3, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        '@cf/qwen/qwen3-30b-a3b-fp8' => ['Qwen3 30B A3b fp8', 32_768, 32_768, true, false, 0.0509, 0.335, 0.0, 0.0],
        '@cf/qwen/qwen3.8-27b' => ['Qwen3.8 27B', 262_144, 262_144, true, true, 0.45, 3.2, 0.05, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        '@cf/zai-org/glm-4.7-flash' => ['GLM-4.7-Flash', 131_072, 131_072, true, false, 0.0605, 0.4, 0.0, 0.0],
        '@cf/zai-org/glm-5.2' => ['Glm 5.2', 262_144, 256_000, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '@cf/zai-org/glm-5.3' => ['Glm 5.3', 1_048_576, 1_048_576, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '@cf/zai-org/glm-5.3-flash' => ['Glm 5.3 Flash', 1_048_576, 1_048_576, true, true, 0.15, 0.5, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * DeepSeek's own API: upstream's generator's two hand-written rows (`deepseekModels`). "DeepSeek
     * also offers time-based off-peak rates, which the cost schema cannot represent yet."
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array DEEPSEEK_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'deepseek-flash' => ['DeepSeek V4.1 Flash', 1_000_000, 384_000, true, true, 0.3, 1.2, 0.006, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'max' => 'max']],
        'deepseek-v4-pro' => ['DeepSeek V4 Pro', 1_000_000, 384_000, true, false, 1.32, 3.96, 0.044, 0.0],
        // <<< generated
    ];

    /**
     * Fireworks (`processFireworksModels()`, models.dev's `fireworks-ai`): GLM and Kimi K3 models on
     * Chat Completions, everything else on its Anthropic-compatible Messages API.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array FIREWORKS_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'accounts/fireworks/models/deepseek-v4p1-flash' => ['DeepSeek V4.1 Flash', Api::AnthropicMessages, 1_000_000, 384_000, true, true, 0.3, 1.2, 0.006, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/models/ember-1' => ['Ember-1', Api::AnthropicMessages, 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/models/glm-5p3' => ['GLM 5.3', Api::OpenAiCompletions, 1_048_573, 262_144, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'accounts/fireworks/models/glm-5p3-flash' => ['GLM 5.3 Flash', Api::OpenAiCompletions, 1_048_573, 131_072, true, true, 0.15, 0.5, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'accounts/fireworks/models/gpt-oss-120b' => ['GPT OSS 120B', Api::AnthropicMessages, 131_072, 32_768, true, false, 0.15, 0.6, 0.015, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsEffort' => true],
        'accounts/fireworks/models/inkling' => ['Inkling', Api::AnthropicMessages, 1_048_576, 1_048_576, true, true, 1.0, 4.05, 0.17, 0.0],
        'accounts/fireworks/models/kimi-k3' => ['Kimi K3', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/models/minimax-m3' => ['MiniMax-M3', Api::AnthropicMessages, 512_000, 512_000, true, false, 0.3, 1.2, 0.06, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsEffort' => true],
        'accounts/fireworks/models/nemotron-3-ultra-nvfp4' => ['Nemotron 3 Ultra 550B A55B', Api::AnthropicMessages, 262_144, 128_000, true, false, 0.6, 2.4, 0.12, 0.0, 'supportsToggle' => true],
        'accounts/fireworks/models/nemotron-lightning-3p5-30b-a3b' => ['Nemotron 3.5 Lightning 30B A3B', Api::AnthropicMessages, 262_144, 262_144, true, false, 0.05, 0.2, 0.01, 0.0, 'supportsToggle' => true],
        'accounts/fireworks/models/qwen3p8-2p4t-a95b' => ['Qwen3.8 2.4T A95B', Api::AnthropicMessages, 262_144, 131_072, true, false, 2.0, 6.0, 0.25, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null], 'supportsEffort' => true],
        'accounts/fireworks/models/qwen3p8-max' => ['Qwen3.8 Max', Api::AnthropicMessages, 262_144, 131_072, true, true, 2.0, 6.0, 0.25, 0.0, 'supportsToggle' => true],
        'accounts/fireworks/routers/deepseek-flash-latest' => ['DeepSeek Flash Latest', Api::AnthropicMessages, 1_000_000, 384_000, true, true, 0.3, 1.2, 0.006, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/routers/glm-5p3-fast' => ['GLM 5.3 Fast', Api::OpenAiCompletions, 1_048_572, 262_144, true, false, 2.1, 6.6, 0.39, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'accounts/fireworks/routers/glm-fast-latest' => ['GLM 5.3 Fast (Latest)', Api::OpenAiCompletions, 1_048_572, 262_144, true, false, 2.1, 6.6, 0.39, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'accounts/fireworks/routers/glm-flash-latest' => ['GLM Flash Latest (GLM 5.3 Flash)', Api::OpenAiCompletions, 1_048_573, 131_072, true, true, 0.15, 0.5, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'accounts/fireworks/routers/glm-latest' => ['GLM Latest', Api::OpenAiCompletions, 1_048_573, 262_144, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsEffort' => true],
        'accounts/fireworks/routers/kimi-fast-latest' => ['Kimi Fast Latest', Api::AnthropicMessages, 1_048_576, 131_072, true, true, 4.5, 22.5, 0.45, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/routers/kimi-k3-fast' => ['Kimi K3 Fast', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 4.5, 22.5, 0.45, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/routers/kimi-latest' => ['Kimi Latest', Api::AnthropicMessages, 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'supportsToggle' => true, 'supportsEffort' => true],
        'accounts/fireworks/routers/minimax-latest' => ['MiniMax Latest', Api::AnthropicMessages, 512_000, 512_000, true, false, 0.3, 1.2, 0.06, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'supportsEffort' => true],
        'accounts/fireworks/routers/qwen-max-latest' => ['Qwen Max Latest (Qwen3.8 Max)', Api::AnthropicMessages, 262_144, 131_072, true, true, 2.0, 6.0, 0.25, 0.0, 'supportsToggle' => true],
        // <<< generated
    ];

    /**
     * Hugging Face's router: models.dev's `huggingface` entry.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array HUGGINGFACE_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'MiniMaxAI/MiniMax-M2' => ['MiniMax-M2', 204_800, 131_072, true, false, 0.3, 1.2, 0.0, 0.0],
        'MiniMaxAI/MiniMax-M2.1' => ['MiniMax-M2.1', 204_800, 131_072, true, false, 0.3, 1.2, 0.0, 0.0],
        'MiniMaxAI/MiniMax-M2.5' => ['MiniMax-M2.5', 204_800, 131_072, true, false, 0.3, 1.2, 0.03, 0.0],
        'MiniMaxAI/MiniMax-M2.7' => ['MiniMax-M2.7', 204_800, 131_072, true, false, 0.3, 1.2, 0.06, 0.0],
        'MiniMaxAI/MiniMax-M3' => ['MiniMax-M3', 524_288, 512_000, true, true, 0.3, 1.2, 0.0, 0.0],
        'Qwen/Qwen2.5-Coder-32B-Instruct' => ['Qwen2.5-Coder-32B-Instruct', 131_072, 8_192, false, false, 0.06, 0.2, 0.0, 0.0],
        'Qwen/Qwen3-235B-A22B' => ['Qwen3 235B-A22B', 40_960, 16_384, true, false, 0.2, 0.8, 0.0, 0.0],
        'Qwen/Qwen3-235B-A22B-Instruct-2507' => ['Qwen3 235B-A22B Instruct 2507', 262_144, 16_384, false, false, 0.855, 2.565, 0.0, 0.0],
        'Qwen/Qwen3-235B-A22B-Thinking-2507' => ['Qwen3-235B-A22B-Thinking-2507', 262_144, 131_072, true, false, 0.3, 3.0, 0.0, 0.0],
        'Qwen/Qwen3-30B-A3B' => ['Qwen3 30B A3B', 40_960, 16_384, true, false, 0.12, 0.5, 0.0, 0.0],
        'Qwen/Qwen3-32B' => ['Qwen3 32B', 131_072, 16_384, true, false, 0.29, 0.59, 0.0, 0.0],
        'Qwen/Qwen3-Coder-30B-A3B-Instruct' => ['Qwen3-Coder 30B-A3B Instruct', 262_144, 65_536, false, false, 0.07, 0.26, 0.0, 0.0],
        'Qwen/Qwen3-Coder-480B-A35B-Instruct' => ['Qwen3-Coder-480B-A35B-Instruct', 262_144, 66_536, false, false, 2.0, 2.0, 0.0, 0.0],
        'Qwen/Qwen3-Coder-Next' => ['Qwen3-Coder-Next', 262_144, 65_536, false, false, 0.2, 1.5, 0.0, 0.0],
        'Qwen/Qwen3-Next-80B-A3B-Instruct' => ['Qwen3-Next-80B-A3B-Instruct', 262_144, 66_536, false, false, 0.25, 1.0, 0.0, 0.0],
        'Qwen/Qwen3-Next-80B-A3B-Thinking' => ['Qwen3-Next-80B-A3B-Thinking', 262_144, 131_072, false, false, 0.3, 2.0, 0.0, 0.0],
        'Qwen/Qwen3-VL-235B-A22B-Instruct' => ['Qwen3 VL 235B A22B Instruct', 131_072, 32_768, false, true, 0.3, 1.5, 0.0, 0.0],
        'Qwen/Qwen3-VL-235B-A22B-Thinking' => ['Qwen3 VL 235B A22B Thinking', 131_072, 32_768, true, true, 0.98, 3.95, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'Qwen/Qwen3.5-122B-A10B' => ['Qwen3.5 122B-A10B', 262_144, 65_536, true, true, 0.4, 3.2, 0.0, 0.0],
        'Qwen/Qwen3.5-27B' => ['Qwen3.5 27B', 262_144, 65_536, true, true, 0.3, 2.4, 0.0, 0.0],
        'Qwen/Qwen3.5-35B-A3B' => ['Qwen3.5 35B-A3B', 262_144, 65_536, true, true, 0.25, 2.0, 0.0, 0.0],
        'Qwen/Qwen3.5-397B-A17B' => ['Qwen3.5-397B-A17B', 262_144, 32_768, true, true, 0.6, 3.6, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'Qwen/Qwen3.5-9B' => ['Qwen3.5 9B', 262_144, 65_536, true, true, 0.17, 0.25, 0.0, 0.0],
        'Qwen/Qwen3.6-27B' => ['Qwen3.6 27B', 262_144, 65_536, true, true, 0.47, 3.19, 0.0, 0.0],
        'Qwen/Qwen3.6-35B-A3B' => ['Qwen3.6 35B-A3B', 262_144, 65_536, true, true, 0.15, 0.95, 0.0, 0.0],
        'Qwen/Qwen3.8-2.4T-A95B' => ['Qwen3.8 2.4T A95B', 262_144, 131_072, true, false, 2.5, 6.25, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'Qwen/Qwen3.8-27B' => ['Qwen3.8 27B', 262_144, 32_768, true, true, 0.4, 3.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'XiaomiMiMo/MiMo-V2-Flash' => ['MiMo-V2-Flash', 262_144, 4_096, true, false, 0.1, 0.3, 0.0, 0.0],
        'XiaomiMiMo/MiMo-V2.5' => ['MiMo-V2.5', 262_144, 131_072, true, false, 0.4, 2.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'XiaomiMiMo/MiMo-V2.5-Pro' => ['MiMo-V2.5-Pro', 1_048_576, 131_072, true, false, 1.0, 3.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'deepseek-ai/DeepSeek-R1' => ['DeepSeek-R1', 64_000, 32_768, true, false, 0.7, 2.5, 0.0, 0.0],
        'deepseek-ai/DeepSeek-R1-0528' => ['DeepSeek-R1-0528', 163_840, 163_840, true, false, 3.0, 5.0, 0.0, 0.0],
        'deepseek-ai/DeepSeek-V3' => ['DeepSeek-V3', 64_000, 8_192, false, false, 0.4, 1.3, 0.0, 0.0],
        'deepseek-ai/DeepSeek-V3-0324' => ['DeepSeek V3 0324', 163_840, 163_840, false, false, 0.27, 1.12, 0.0, 0.0],
        'deepseek-ai/DeepSeek-V3.1' => ['DeepSeek-V3.1', 131_072, 8_192, true, false, 0.27, 1.0, 0.0, 0.0],
        'deepseek-ai/DeepSeek-V3.2' => ['DeepSeek-V3.2', 163_840, 65_536, true, false, 0.28, 0.4, 0.0, 0.0],
        'deepseek-ai/DeepSeek-V4-Flash' => ['DeepSeek V4 Flash', 1_048_576, 384_000, true, false, 0.14, 0.28, 0.0, 0.0],
        'deepseek-ai/DeepSeek-V4-Flash-0731' => ['DeepSeek V4 Flash 0731', 1_048_576, 384_000, true, false, 0.14, 0.28, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-ai/DeepSeek-V4-Flash-Vision-Exp' => ['DeepSeek V4 Flash Vision Exp', 1_048_576, 384_000, true, true, 0.44, 1.32, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-ai/DeepSeek-V4-Pro' => ['DeepSeek V4 Pro', 1_048_576, 393_216, true, false, 0.435, 0.87, 0.003625, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'deepseek-ai/DeepSeek-V4-Pro-0813' => ['DeepSeek V4 Pro 0813', 1_000_000, 384_000, true, false, 1.32, 3.96, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-ai/DeepSeek-V4.1-Flash' => ['DeepSeek V4.1 Flash', 1_048_576, 384_000, true, true, 0.3, 1.2, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'google/gemma-3-12b-it' => ['Gemma 3 12B IT', 131_072, 131_072, false, true, 0.05, 0.15, 0.0, 0.0],
        'google/gemma-3-27b-it' => ['Gemma 3 27B IT', 131_072, 131_072, false, true, 0.08, 0.16, 0.0, 0.0],
        'google/gemma-3-4b-it' => ['Gemma 3 4B IT', 131_072, 131_072, false, true, 0.05, 0.1, 0.0, 0.0],
        'google/gemma-4-26B-A4B-it' => ['Gemma 4 26B A4B IT', 262_144, 32_768, true, true, 0.13, 0.4, 0.0, 0.0],
        'google/gemma-4-31B-it' => ['Gemma 4 31B IT', 262_144, 32_768, true, true, 0.14, 0.4, 0.0, 0.0],
        'meta-llama/Llama-3.1-8B-Instruct' => ['Llama-3.1-8B-Instruct', 131_072, 4_096, false, false, 0.06, 0.06, 0.0, 0.0],
        'meta-llama/Llama-3.3-70B-Instruct' => ['Llama-3.3-70B-Instruct', 131_072, 4_096, false, false, 0.59, 0.79, 0.0, 0.0],
        'moonshotai/Kimi-K2-Instruct' => ['Kimi-K2-Instruct', 131_072, 16_384, false, false, 1.0, 3.0, 0.0, 0.0],
        'moonshotai/Kimi-K2-Instruct-0905' => ['Kimi-K2-Instruct-0905', 262_144, 16_384, false, false, 1.0, 3.0, 0.0, 0.0],
        'moonshotai/Kimi-K2-Thinking' => ['Kimi-K2-Thinking', 262_144, 262_144, true, false, 0.6, 2.5, 0.15, 0.0],
        'moonshotai/Kimi-K2.5' => ['Kimi-K2.5', 262_144, 262_144, true, true, 0.6, 3.0, 0.1, 0.0],
        'moonshotai/Kimi-K2.6' => ['Kimi-K2.6', 262_144, 262_144, true, true, 0.95, 4.0, 0.16, 0.0],
        'moonshotai/Kimi-K2.7-Code' => ['Kimi K2.7 Code', 262_144, 262_144, true, true, 0.95, 4.0, 0.0, 0.0],
        'moonshotai/Kimi-K3' => ['Kimi K3', 1_000_000, 131_072, true, true, 3.0, 15.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'openai/gpt-oss-120b' => ['GPT OSS 120B', 131_072, 32_768, true, false, 0.25, 0.69, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-20b' => ['GPT OSS 20B', 131_072, 32_768, true, false, 0.1, 0.5, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'stepfun-ai/Step-3.5-Flash' => ['Step 3.5 Flash', 262_144, 256_000, true, false, 0.1, 0.3, 0.0, 0.0],
        'stepfun-ai/Step-3.7-Flash' => ['Step 3.7 Flash', 262_144, 256_000, true, true, 0.2, 1.15, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'tencent/Hy3' => ['Hy3', 262_144, 128_000, true, false, 0.14, 0.58, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'tencent/Hy4-preview' => ['Hy4 preview', 1_000_000, 64_000, true, false, 0.834, 2.501, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'thinkingmachines/Inkling' => ['Inkling', 1_048_576, 1_048_576, true, true, 1.0, 4.05, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'thinkingmachines/Inkling-Small' => ['Inkling Small', 524_288, 1_048_576, true, true, 0.5, 1.2, 0.0, 0.0],
        'zai-org/GLM-4.5' => ['GLM-4.5', 131_072, 98_304, true, false, 0.6, 2.2, 0.0, 0.0],
        'zai-org/GLM-4.5-Air' => ['GLM-4.5-Air', 131_072, 98_304, true, false, 0.13, 0.85, 0.0, 0.0],
        'zai-org/GLM-4.5V' => ['GLM-4.5V', 65_536, 16_384, true, true, 0.6, 1.8, 0.0, 0.0],
        'zai-org/GLM-4.6' => ['GLM-4.6', 204_800, 131_072, true, false, 0.55, 2.2, 0.0, 0.0],
        'zai-org/GLM-4.6V-Flash' => ['GLM-4.6V-Flash', 131_072, 32_768, true, true, 0.3, 0.9, 0.0, 0.0],
        'zai-org/GLM-4.7' => ['GLM-4.7', 204_800, 131_072, true, false, 0.6, 2.2, 0.11, 0.0],
        'zai-org/GLM-4.7-Flash' => ['GLM-4.7-Flash', 200_000, 128_000, true, false, 0.0, 0.0, 0.0, 0.0],
        'zai-org/GLM-5' => ['GLM-5', 202_752, 131_072, true, false, 1.0, 3.2, 0.2, 0.0],
        'zai-org/GLM-5.1' => ['GLM-5.1', 202_752, 131_072, true, false, 1.0, 3.2, 0.2, 0.0],
        'zai-org/GLM-5.2' => ['GLM-5.2', 262_144, 131_072, true, false, 1.4, 4.4, 0.0, 0.0],
        'zai-org/GLM-5.3' => ['GLM-5.3', 1_048_576, 131_072, true, false, 1.4, 4.4, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'zai-org/GLM-5.3-Flash' => ['GLM-5.3-Flash', 1_048_576, 131_072, true, true, 0.15, 0.5, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * Kimi For Coding: models.dev's `kimi-code-plan-global`, its `k2p5`/`k2p6`/`k2p7` aliases folded
     * into `kimi-for-coding`. "Kimi Coding is subscription-backed, so models.dev reports zero cost.
     * Use the equivalent Moonshot API rates to estimate the value of subscription usage"
     * (`KIMI_CODING_IMPLIED_COSTS`).
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array KIMI_CODING_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'k3' => ['Kimi K3', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'k3-256k' => ['Kimi K3-256K', 262_144, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'kimi-for-coding' => ['kimi-for-coding', 1_048_576, 32_768, true, true, 0.95, 4.0, 0.19, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'kimi-for-coding-highspeed' => ['Kimi For Coding HighSpeed', 262_144, 32_768, true, true, 1.9, 8.0, 0.38, 0.0],
        // <<< generated
    ];

    /**
     * Meta's Model API, on the Responses API: models.dev's `meta` entry.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array META_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'muse-spark-1.1' => ['Muse Spark 1.1', 1_048_576, 131_072, true, true, 1.25, 4.25, 0.15, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'muse-spark-1.2' => ['Muse Spark 1.2', 1_048_576, 131_072, true, true, 1.25, 4.25, 0.15, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'muse-spark-1.2-contributor' => ['Muse Spark 1.2 Contributor', 1_048_576, 131_072, true, true, 0.1, 0.2, 0.002, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'muse-spark-1.3' => ['Muse Spark 1.3', 1_048_576, 131_072, true, true, 1.25, 4.25, 0.15, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'muse-spark-1.3-contributor' => ['Muse Spark 1.3 Contributor', 1_048_576, 131_072, true, true, 0.1, 0.2, 0.002, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        // <<< generated
    ];

    /**
     * MiniMax's Anthropic-compatible API, international and China: only the ids upstream's generator
     * keeps (`minimaxDirectSupportedIds`).
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array MINIMAX_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'MiniMax-M2.7' => ['MiniMax-M2.7', 204_800, 131_072, true, false, 0.3, 1.2, 0.06, 0.375],
        'MiniMax-M2.7-highspeed' => ['MiniMax-M2.7-highspeed', 204_800, 131_072, true, false, 0.6, 2.4, 0.06, 0.375],
        'MiniMax-M3' => ['MiniMax-M3', 1_000_000, 512_000, true, true, 0.3, 1.2, 0.06, 0.0, 'tiers' => [[512_000, 0.6, 2.4, 0.12, 0.0]]],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array MINIMAX_CN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'MiniMax-M2.7' => ['MiniMax-M2.7', 204_800, 131_072, true, false, 0.3, 1.2, 0.06, 0.375],
        'MiniMax-M2.7-highspeed' => ['MiniMax-M2.7-highspeed', 204_800, 131_072, true, false, 0.6, 2.4, 0.06, 0.375],
        'MiniMax-M3' => ['MiniMax-M3', 1_000_000, 512_000, true, true, 0.3, 1.2, 0.06, 0.0, 'tiers' => [[512_000, 0.6, 2.4, 0.12, 0.0]]],
        // <<< generated
    ];

    /**
     * Moonshot AI's own API, international and China. "Moonshot does not bill cache writes; models.dev
     * lists the input rate as cache_write for Kimi K3" — K3 is priced at `KIMI_K3_COST`.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array MOONSHOTAI_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'kimi-k2.6' => ['Kimi K2.6', 262_144, 262_144, true, true, 0.95, 4.0, 0.16, 0.0],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', 262_144, 262_144, true, true, 0.95, 4.0, 0.19, 0.0],
        'kimi-k2.7-code-highspeed' => ['Kimi K2.7 Code HighSpeed', 262_144, 262_144, true, true, 1.9, 8.0, 0.38, 0.0],
        'kimi-k3' => ['Kimi K3', 1_048_576, 1_048_576, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array MOONSHOTAI_CN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'kimi-k2.6' => ['Kimi K2.6', 262_144, 262_144, true, true, 0.95, 4.0, 0.16, 0.0],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', 262_144, 262_144, true, true, 0.95, 4.0, 0.19, 0.0],
        'kimi-k2.7-code-highspeed' => ['Kimi K2.7 Code HighSpeed', 262_144, 262_144, true, true, 1.9, 8.0, 0.38, 0.0],
        'kimi-k3' => ['Kimi K3', 1_048_576, 1_048_576, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * NVIDIA NIM: models.dev's `nvidia` models that take text in and out, under the id NIM's own
     * `/v1/models` lists (`fetchNvidiaNimModelIds()`), less `NVIDIA_NIM_UNSUPPORTED_MODELS`.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array NVIDIA_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'deepseek-ai/deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', 1_000_000, 384_000, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'google/diffusiongemma-26b-a4b-it' => ['DiffusionGemma 26B A4B IT', 250_000, 32_768, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'google/gemma-3-12b-it' => ['Gemma 3 12B IT', 131_072, 16_384, false, true, 0.0, 0.0, 0.0, 0.0],
        'google/gemma-3-4b-it' => ['Gemma 3 4B IT', 131_072, 16_384, false, true, 0.0, 0.0, 0.0, 0.0],
        'meta/llama-3.2-11b-vision-instruct' => ['Llama 3.2 11b Vision Instruct', 128_000, 4_096, false, true, 0.0, 0.0, 0.0, 0.0],
        'meta/llama-3.2-90b-vision-instruct' => ['Llama-3.2-90B-Vision-Instruct', 128_000, 8_192, false, true, 0.0, 0.0, 0.0, 0.0],
        'meta/muse-glimmer-30b' => ['Muse Glimmer 30B', 131_072, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'mistralai/mistral-7b-instruct-v0.3' => ['Mistral-7B-Instruct-v0.3', 65_536, 65_536, false, false, 0.0, 0.0, 0.0, 0.0],
        'moonshotai/kimi-k2.6' => ['Kimi K2.6', 262_144, 262_144, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'moonshotai/kimi-k3' => ['Kimi K3', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'nvidia/cosmos-reason2-8b' => ['Cosmos Reason2 8B', 131_072, 16_384, true, true, 0.0, 0.0, 0.0, 0.0],
        'nvidia/llama-3.1-nemotron-70b-instruct' => ['Llama 3.1 Nemotron 70B Instruct', 128_000, 8_192, false, false, 0.0, 0.0, 0.0, 0.0],
        'nvidia/llama-3.1-nemotron-ultra-253b-v1' => ['Llama 3.1 Nemotron Ultra 253B', 128_000, 16_384, true, false, 0.0, 0.0, 0.0, 0.0],
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning' => ['Nemotron 3 Nano Omni', 256_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'nvidia/nemotron-3-super-120b-a12b' => ['Nemotron 3 Super', 262_144, 262_144, true, false, 0.2, 0.8, 0.0, 0.0],
        'nvidia/nemotron-3-ultra-550b-a55b' => ['Nemotron 3 Ultra 550B A55B', 1_000_000, 65_536, true, false, 0.5, 2.5, 0.15, 0.0],
        'nvidia/nemotron-3.5-lightning-30b-a3b' => ['Nemotron 3.5 Lightning 30B A3B', 262_144, 262_144, true, false, 0.0, 0.0, 0.0, 0.0],
        'openai/gpt-oss-20b' => ['GPT OSS 20B', 131_072, 32_768, true, false, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'poolside/laguna-xs-2.1' => ['Laguna XS 2.1', 262_144, 16_384, true, false, 0.0, 0.0, 0.0, 0.0],
        'z-ai/glm-5.3' => ['GLM-5.3', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5.3-flash' => ['GLM-5.3-Flash', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * OpenCode Zen and OpenCode Go: models.dev's `opencode` and `opencode-go`, the API per model from
     * its `provider.npm` (`@ai-sdk/openai` Responses, `@ai-sdk/anthropic` Messages, `@ai-sdk/google`
     * Gemini, anything else Chat Completions), with the generator's corrections for OpenCode Go's
     * MiniMax M2.7 and Qwen 3.5/3.6 Plus, its windows for Claude and GPT-5.4, and no GPT-5.3 Codex
     * Spark. Every request carries `x-opencode-session` (`Stream`, upstream's `opencode-headers.ts`).
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array OPENCODE_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'big-pickle' => ['Big Pickle', Api::OpenAiCompletions, 200_000, 32_000, true, false, 0.0, 0.0, 0.0, 0.0],
        'claude-fable-5' => ['Claude Fable 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-fable-5-1' => ['Claude Fable 5.1', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-haiku-4-5' => ['Claude Haiku 4.5', Api::AnthropicMessages, 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25],
        'claude-haiku-5-5' => ['Claude Haiku 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4-5' => ['Claude Opus 4.5', Api::AnthropicMessages, 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'claude-opus-4-6' => ['Claude Opus 4.6', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-opus-4-7' => ['Claude Opus 4.7', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-4-8' => ['Claude Opus 4.8', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5' => ['Claude Opus 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-opus-5-5' => ['Claude Opus 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-4' => ['Claude Sonnet 4', Api::AnthropicMessages, 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.6, 7.5]]],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5', Api::AnthropicMessages, 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.6, 7.5]]],
        'claude-sonnet-4-6' => ['Claude Sonnet 4.6', Api::AnthropicMessages, 1_000_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'claude-sonnet-5' => ['Claude Sonnet 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'deepseek-v4-flash' => ['DeepSeek V4 Flash', Api::OpenAiCompletions, 1_000_000, 384_000, true, false, 0.14, 0.28, 0.028, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-flash-vision-exp' => ['DeepSeek V4 Flash Vision Exp', Api::OpenAiCompletions, 1_000_000, 384_000, true, true, 0.14, 0.28, 0.028, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro' => ['DeepSeek V4 Pro', Api::OpenAiCompletions, 1_000_000, 384_000, true, false, 1.74, 3.84, 0.145, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', Api::OpenAiCompletions, 1_000_000, 384_000, true, true, 0.3, 1.2, 0.006, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'exo-free' => ['Exo Free', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3-flash' => ['Gemini 3 Flash', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 0.5, 3.0, 0.05, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-pro' => ['Gemini 3.1 Pro Preview', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash' => ['Gemini 3.5 Flash', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash Lite', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.6-flash' => ['Gemini 3.6 Flash', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 1.5, 7.5, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.7-flash' => ['Gemini 3.7 Flash', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 1.5, 7.5, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.8-flash' => ['Gemini 3.8 Flash', Api::GoogleGenerativeAi, 1_048_576, 65_536, true, true, 1.5, 7.5, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'glm-5' => ['GLM-5', Api::OpenAiCompletions, 204_800, 131_072, true, false, 1.0, 3.2, 0.2, 0.0],
        'glm-5.1' => ['GLM-5.1', Api::OpenAiCompletions, 204_800, 131_072, true, false, 1.4, 4.4, 0.26, 0.0],
        'glm-5.2' => ['GLM-5.2', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3' => ['GLM-5.3', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3-flash' => ['GLM-5.3-Flash', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.15, 0.5, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'gpt-5' => ['GPT-5', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.07, 8.5, 0.107, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-codex' => ['GPT-5 Codex', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.07, 8.5, 0.107, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5-nano' => ['GPT-5 Nano', Api::OpenAiResponses, 400_000, 128_000, true, true, 0.05, 0.4, 0.005, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.1' => ['GPT-5.1', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.07, 8.5, 0.107, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.1-codex' => ['GPT-5.1 Codex', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.07, 8.5, 0.107, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.1-codex-max' => ['GPT-5.1 Codex Max', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.1-codex-mini' => ['GPT-5.1 Codex Mini', Api::OpenAiResponses, 400_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-5.2' => ['GPT-5.2', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.2-codex' => ['GPT-5.2 Codex', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.3-codex' => ['GPT-5.3 Codex', Api::OpenAiResponses, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4' => ['GPT-5.4', Api::OpenAiResponses, 272_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'tiers' => [[272_000, 5.0, 22.5, 0.5, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-mini' => ['GPT-5.4 Mini', Api::OpenAiResponses, 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-nano' => ['GPT-5.4 Nano', Api::OpenAiResponses, 400_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.4-pro' => ['GPT-5.4 Pro', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 30.0, 180.0, 30.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5' => ['GPT-5.5', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.5-pro' => ['GPT-5.5 Pro', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 30.0, 180.0, 30.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-sol' => ['GPT-5.6 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-5.6-terra' => ['GPT-5.6 Terra', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.5, 15.0, 0.25, 3.125, 'tiers' => [[272_000, 5.0, 22.5, 0.5, 6.25]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-astra' => ['GPT-6 Astra', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-luna' => ['GPT-6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-sol' => ['GPT-6 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6.1-sol' => ['GPT-6.1 Sol', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'grok-4.5' => ['Grok 4.5', Api::OpenAiResponses, 500_000, 500_000, true, true, 2.0, 6.0, 0.3, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 0.6, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'grok-4.6' => ['Grok 4.6', Api::OpenAiResponses, 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'grok-4.7' => ['Grok 4.7', Api::OpenAiResponses, 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'grok-build-0.1' => ['Grok Build 0.1', Api::OpenAiResponses, 256_000, 256_000, true, true, 1.0, 2.0, 0.2, 0.0],
        'kimi-k2.5' => ['Kimi K2.5', Api::OpenAiCompletions, 262_144, 65_536, true, true, 0.6, 3.0, 0.08, 0.0],
        'kimi-k2.6' => ['Kimi K2.6', Api::OpenAiCompletions, 262_144, 65_536, true, true, 0.95, 4.0, 0.16, 0.0],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', Api::OpenAiCompletions, 262_144, 262_144, true, true, 0.95, 4.0, 0.19, 0.0],
        'kimi-k3' => ['Kimi K3', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => null, 'xhigh' => null, 'max' => 'max']],
        'ling-3.0-flash-fin-free' => ['Ling 3.0 Flash Fin Free', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'ling-3.1-flash-free' => ['Ling 3.1 Flash Free', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'longcat-2.5-preview-free' => ['LongCat 2.5 Preview Free', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-flash-free' => ['MiMo-V2.6-Flash Free', Api::OpenAiCompletions, 200_000, 32_000, true, true, 0.0, 0.0, 0.0, 0.0],
        'minimax-m2.5' => ['MiniMax-M2.5', Api::OpenAiCompletions, 204_800, 131_072, true, false, 0.3, 1.2, 0.06, 0.0],
        'minimax-m2.7' => ['MiniMax-M2.7', Api::OpenAiCompletions, 204_800, 131_072, true, false, 0.3, 1.2, 0.06, 0.0],
        'minimax-m3' => ['MiniMax-M3', Api::OpenAiCompletions, 512_000, 128_000, true, true, 0.3, 1.2, 0.06, 0.0],
        'mistral-large-4' => ['Mistral Large 4', Api::OpenAiCompletions, 524_288, 262_144, true, true, 0.68, 2.09, 0.07, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'muse-spark-1.2' => ['Muse Spark 1.2', Api::OpenAiResponses, 1_048_576, 131_072, true, true, 1.25, 4.25, 0.15, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'muse-spark-1.3' => ['Muse Spark 1.3', Api::OpenAiResponses, 1_048_576, 131_072, true, true, 1.25, 4.25, 0.15, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'muse-spark-1.3-contributor-free' => ['Muse Spark 1.3 Free', Api::OpenAiResponses, 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'nemotron-3-ultra-free' => ['Nemotron 3 Ultra Free', Api::OpenAiCompletions, 1_000_000, 128_000, true, false, 0.0, 0.0, 0.0, 0.0],
        'nemotron-3.5-lightning-free' => ['Nemotron 3.5 Lightning Free', Api::OpenAiCompletions, 262_144, 262_144, true, false, 0.0, 0.0, 0.0, 0.0],
        'qwen3.5-plus' => ['Qwen3.5 Plus', Api::AnthropicMessages, 262_144, 65_536, true, true, 0.2, 1.2, 0.02, 0.25],
        'qwen3.6-plus' => ['Qwen3.6 Plus', Api::AnthropicMessages, 262_144, 65_536, true, true, 0.5, 3.0, 0.05, 0.625],
        'qwen3.8-flash' => ['Qwen3.8 Flash', Api::AnthropicMessages, 1_000_000, 131_072, true, true, 0.15, 0.47, 0.016, 0.2, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen3.8-max' => ['Qwen3.8 Max', Api::OpenAiCompletions, 262_144, 131_072, true, true, 2.0, 6.0, 0.25, 2.5],
        'space-bunny-free' => ['Space Bunny Free', Api::OpenAiCompletions, 1_048_576, 524_288, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'step-5-preview-free' => ['Step 5 Preview Free', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array OPENCODE_GO_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'claude-haiku-5-5' => ['Claude Haiku 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'deepseek-v4-flash' => ['DeepSeek V4 Flash', Api::OpenAiCompletions, 1_000_000, 384_000, true, false, 0.15, 0.6, 0.003, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-flash-vision-exp' => ['DeepSeek V4 Flash Vision Exp', Api::OpenAiCompletions, 1_000_000, 384_000, true, true, 0.15, 0.6, 0.003, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro' => ['DeepSeek V4 Pro (New)', Api::OpenAiCompletions, 1_000_000, 384_000, true, false, 0.66, 1.98, 0.022, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', Api::OpenAiCompletions, 1_000_000, 384_000, true, true, 0.15, 0.6, 0.003, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.2' => ['GLM-5.2', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3' => ['GLM-5.3', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3-flash' => ['GLM-5.3-Flash', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.15, 0.5, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'gpt-5.6-luna' => ['GPT-5.6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'gpt-6-luna' => ['GPT-6 Luna', Api::OpenAiResponses, 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'grok-4.6' => ['Grok 4.6', Api::OpenAiResponses, 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'grok-4.7' => ['Grok 4.7', Api::OpenAiResponses, 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'hy3' => ['Hy3', Api::OpenAiCompletions, 256_000, 128_000, true, false, 0.14, 0.58, 0.035, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'hy4-preview' => ['Hy4 preview', Api::OpenAiCompletions, 1_024_000, 64_000, true, false, 0.834, 2.501, 0.042, 0.0, 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', Api::OpenAiCompletions, 262_144, 262_144, true, true, 0.95, 4.0, 0.19, 0.0],
        'kimi-k3' => ['Kimi K3', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => null, 'xhigh' => null, 'max' => 'max']],
        'longcat-2.0' => ['LongCat-2.0', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 0.3, 1.2, 0.006, 0.0],
        'longcat-2.5-preview-free' => ['LongCat 2.5 Preview Free', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.5' => ['MiMo V2.5', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 0.14, 0.28, 0.0028, 0.0],
        'mimo-v2.5-pro' => ['MiMo V2.5 Pro', Api::OpenAiCompletions, 1_048_576, 128_000, true, false, 0.435, 0.87, 0.003625, 0.0],
        'mimo-v2.6-flash' => ['MiMo-V2.6-Flash', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.14, 0.28, 0.0028, 0.0],
        'mimo-v2.6-pro' => ['MiMo-V2.6-Pro', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.435, 0.87, 0.003625, 0.0],
        'minimax-m2.7' => ['MiniMax-M2.7', Api::OpenAiCompletions, 204_800, 131_072, true, false, 0.3, 1.2, 0.06, 0.375],
        'minimax-m3' => ['MiniMax-M3', Api::AnthropicMessages, 1_000_000, 131_072, true, true, 0.3, 1.2, 0.06, 0.0, 'tiers' => [[512_000, 0.6, 2.4, 0.12, 0.0]]],
        'muse-spark-1.2-contributor' => ['Muse Spark 1.2 Contributor', Api::OpenAiResponses, 1_048_576, 131_072, true, true, 0.1, 0.2, 0.002, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'muse-spark-1.3-contributor' => ['Muse Spark 1.3 Contributor', Api::OpenAiResponses, 1_048_576, 131_072, true, true, 0.1, 0.2, 0.002, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'qwen3.7-plus' => ['Qwen3.7 Plus', Api::AnthropicMessages, 1_000_000, 65_536, true, true, 0.4, 1.6, 0.04, 0.5, 'tiers' => [[256_000, 1.2, 4.8, 0.12, 1.5]]],
        'qwen3.8-flash' => ['Qwen3.8 Flash', Api::AnthropicMessages, 1_000_000, 131_072, true, true, 0.15, 0.47, 0.016, 0.2, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen3.8-max' => ['Qwen3.8 Max', Api::AnthropicMessages, 1_000_000, 131_072, true, true, 2.0, 6.0, 0.25, 2.5, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'space-bunny' => ['Space Bunny', Api::OpenAiCompletions, 1_048_576, 524_288, true, true, 0.15, 0.6, 0.03, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'step-5-preview-free' => ['Step 5 Preview Free', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0, 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        // <<< generated
    ];

    /**
     * OpenRouter's chat models: its `/api/v1/models` listing, the models that take tools
     * (`buildOpenRouterCatalog()`), priced from its per-token prices, the level map from its
     * `reasoning` field (`getOpenRouterThinkingLevelMap()`), plus upstream's `auto` and
     * `openrouter/fusion` aliases. Its image and classifier models are another API.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array OPENROUTER_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'aion-labs/aion-2.0' => ['AionLabs: Aion-2.0', Api::OpenAiCompletions, 131_072, 32_768, true, false, 0.8, 1.6, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'aion-labs/aion-3.0' => ['AionLabs: Aion-3.0', Api::OpenAiCompletions, 131_072, 32_768, true, false, 3.0, 6.0, 0.75, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'aion-labs/aion-3.0-mini' => ['AionLabs: Aion-3.0-Mini', Api::OpenAiCompletions, 131_072, 32_768, true, false, 0.7, 1.4, 0.18, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'aion-labs/aion-3.5' => ['AionLabs: Aion 3.5', Api::OpenAiCompletions, 262_144, 32_768, true, false, 3.0, 6.0, 0.75, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'aion-labs/aion-3.5-mini' => ['AionLabs: Aion 3.5 Mini', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.7, 1.4, 0.18, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'amazon/nova-2-lite-v1' => ['Amazon: Nova 2 Lite', Api::OpenAiCompletions, 1_000_000, 65_535, true, true, 0.3, 2.5, 0.0, 0.0],
        'amazon/nova-lite-v1' => ['Amazon: Nova Lite 1.0', Api::OpenAiCompletions, 300_000, 5_120, false, true, 0.06, 0.24, 0.0, 0.0],
        'amazon/nova-micro-v1' => ['Amazon: Nova Micro 1.0', Api::OpenAiCompletions, 128_000, 5_120, false, false, 0.035, 0.14, 0.0, 0.0],
        'amazon/nova-premier-v1' => ['Amazon: Nova Premier 1.0', Api::OpenAiCompletions, 1_000_000, 32_000, false, true, 2.5, 12.5, 0.625, 0.0],
        'amazon/nova-pro-v1' => ['Amazon: Nova Pro 1.0', Api::OpenAiCompletions, 300_000, 5_120, false, true, 0.8, 3.2, 0.0, 0.0],
        'anthropic/claude-fable-5' => ['Anthropic: Claude Fable 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-fable-5.1' => ['Anthropic: Claude Fable 5.1', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-fable-5.1:batch' => ['Anthropic: Claude Fable 5.1 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.125, 6.25, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-fable-5:batch' => ['Anthropic: Claude Fable 5 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-haiku-4.5' => ['Anthropic: Claude Haiku 4.5', Api::AnthropicMessages, 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25],
        'anthropic/claude-haiku-4.5:batch' => ['Anthropic: Claude Haiku 4.5 (batch)', Api::OpenAiCompletions, 200_000, 64_000, true, true, 0.5, 2.5, 0.05, 0.625],
        'anthropic/claude-haiku-5.5' => ['Anthropic: Claude Haiku 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],
        'anthropic/claude-haiku-5.5:batch' => ['Anthropic: Claude Haiku 5.5 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 0.05, 0.25, 0.005, 0.0625, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[100_000, 0.25, 1.25, 0.025, 0.3125]]],
        'anthropic/claude-opus-4.1' => ['Anthropic: Claude Opus 4.1', Api::AnthropicMessages, 200_000, 32_000, true, true, 15.0, 75.0, 1.5, 18.75],
        'anthropic/claude-opus-4.1:batch' => ['Anthropic: Claude Opus 4.1 (batch)', Api::OpenAiCompletions, 200_000, 32_000, true, true, 7.5, 37.5, 0.75, 9.375],
        'anthropic/claude-opus-4.5' => ['Anthropic: Claude Opus 4.5', Api::AnthropicMessages, 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic/claude-opus-4.5:batch' => ['Anthropic: Claude Opus 4.5 (batch)', Api::OpenAiCompletions, 200_000, 64_000, true, true, 2.5, 12.5, 0.25, 3.125],
        'anthropic/claude-opus-4.6' => ['Anthropic: Claude Opus 4.6', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'anthropic/claude-opus-4.6:batch' => ['Anthropic: Claude Opus 4.6 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.5, 12.5, 0.25, 3.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'anthropic/claude-opus-4.7' => ['Anthropic: Claude Opus 4.7', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-4.7:batch' => ['Anthropic: Claude Opus 4.7 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.5, 12.5, 0.25, 3.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-4.8' => ['Anthropic: Claude Opus 4.8', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-4.8:batch' => ['Anthropic: Claude Opus 4.8 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.5, 12.5, 0.25, 3.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-5' => ['Anthropic: Claude Opus 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-5.5' => ['Anthropic: Claude Opus 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-5.5:batch' => ['Anthropic: Claude Opus 5.5 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-opus-5:batch' => ['Anthropic: Claude Opus 5 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.5, 12.5, 0.25, 3.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-sonnet-4' => ['Anthropic: Claude Sonnet 4', Api::AnthropicMessages, 200_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.6, 7.5]]],
        'anthropic/claude-sonnet-4.5' => ['Anthropic: Claude Sonnet 4.5', Api::AnthropicMessages, 1_000_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.6, 7.5]]],
        'anthropic/claude-sonnet-4.5:batch' => ['Anthropic: Claude Sonnet 4.5 (batch)', Api::OpenAiCompletions, 1_000_000, 64_000, true, true, 1.5, 7.5, 0.15, 1.875, 'tiers' => [[200_000, 3.0, 11.25, 0.3, 3.75]]],
        'anthropic/claude-sonnet-4.6' => ['Anthropic: Claude Sonnet 4.6', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 3.0, 15.0, 0.3, 3.75, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'anthropic/claude-sonnet-4.6:batch' => ['Anthropic: Claude Sonnet 4.6 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 1.5, 7.5, 0.15, 1.875, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'anthropic/claude-sonnet-5' => ['Anthropic: Claude Sonnet 5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-sonnet-5.5' => ['Anthropic: Claude Sonnet 5.5', Api::AnthropicMessages, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-sonnet-5.5:batch' => ['Anthropic: Claude Sonnet 5.5 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 1.0, 5.0, 0.05, 1.25, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'anthropic/claude-sonnet-5:batch' => ['Anthropic: Claude Sonnet 5 (batch)', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 1.0, 5.0, 0.1, 1.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'apodex/apodex-1.1-mini:free' => ['Apodex: Apodex 1.1 Mini (free)', Api::OpenAiCompletions, 262_144, 235_929, true, false, 0.0, 0.0, 0.0, 0.0],
        'arcee-ai/trinity-large-thinking' => ['Arcee AI: Trinity Large Thinking', Api::OpenAiCompletions, 262_144, 80_000, true, false, 0.25, 0.8, 0.06, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'auto' => ['Auto', Api::OpenAiCompletions, 2_000_000, 30_000, true, true, 0.0, 0.0, 0.0, 0.0],
        'bytedance-seed/seed-1.6' => ['ByteDance Seed: Seed 1.6', Api::OpenAiCompletions, 262_144, 32_768, true, true, 0.25, 2.0, 0.0, 0.0, 'tiers' => [[128_000, 0.5, 4.0, 0.0, 0.0]]],
        'bytedance-seed/seed-1.6-flash' => ['ByteDance Seed: Seed 1.6 Flash', Api::OpenAiCompletions, 262_144, 32_768, true, true, 0.075, 0.3, 0.0, 0.0, 'tiers' => [[128_000, 0.1, 0.8, 0.0, 0.0]]],
        'bytedance-seed/seed-2-1-turbo' => ['ByteDance Seed: Seed 2.1 Turbo', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.5, 2.5, 0.0, 0.0],
        'bytedance-seed/seed-2.0-code' => ['ByteDance Seed: Seed-2.0-Code', Api::OpenAiCompletions, 262_144, 131_072, true, true, 0.5, 3.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[128_000, 1.0, 6.0, 0.0, 0.0]]],
        'bytedance-seed/seed-2.0-lite' => ['ByteDance Seed: Seed-2.0-Lite', Api::OpenAiCompletions, 262_144, 131_072, true, true, 0.25, 2.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[128_000, 0.5, 4.0, 0.0, 0.0]]],
        'bytedance-seed/seed-2.0-mini' => ['ByteDance Seed: Seed-2.0-Mini', Api::OpenAiCompletions, 262_144, 131_072, true, true, 0.1, 0.4, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[128_000, 0.2, 0.8, 0.0, 0.0]]],
        'cohere/command-a-plus' => ['Cohere: Command A+', Api::OpenAiCompletions, 192_000, 64_000, true, true, 0.3, 1.5, 0.15, 0.0],
        'cohere/command-r-08-2024' => ['Cohere: Command R (08-2024)', Api::OpenAiCompletions, 128_000, 4_000, false, false, 0.15, 0.6, 0.0, 0.0],
        'cohere/command-r-plus-08-2024' => ['Cohere: Command R+ (08-2024)', Api::OpenAiCompletions, 128_000, 4_000, false, false, 2.5, 10.0, 0.0, 0.0],
        'cohere/north-mini-code:free' => ['Cohere: North Mini Code (free)', Api::OpenAiCompletions, 256_000, 64_000, true, false, 0.0, 0.0, 0.0, 0.0],
        'deepseek/deepseek-chat' => ['DeepSeek: DeepSeek V3', Api::OpenAiCompletions, 128_000, 16_000, false, false, 0.2574, 1.0287, 0.0, 0.0],
        'deepseek/deepseek-chat-v3-0324' => ['DeepSeek: DeepSeek V3 0324', Api::OpenAiCompletions, 128_000, 115_200, false, false, 0.29, 1.14, 0.11, 0.0],
        'deepseek/deepseek-chat-v3.1' => ['DeepSeek: DeepSeek V3.1', Api::OpenAiCompletions, 163_840, 32_768, true, false, 0.25, 0.95, 0.13, 0.0],
        'deepseek/deepseek-r1' => ['DeepSeek: R1', Api::OpenAiCompletions, 64_000, 16_000, true, false, 0.7, 2.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'deepseek/deepseek-r1-0528' => ['DeepSeek: R1 0528', Api::OpenAiCompletions, 163_840, 32_768, true, false, 0.5, 2.15, 0.35, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'deepseek/deepseek-v3.1-terminus' => ['DeepSeek: DeepSeek V3.1 Terminus', Api::OpenAiCompletions, 163_840, 147_456, true, false, 0.27, 1.0, 0.0, 0.0],
        'deepseek/deepseek-v3.2' => ['DeepSeek: DeepSeek V3.2', Api::OpenAiCompletions, 163_840, 147_456, true, false, 0.259, 0.42, 0.135, 0.0],
        'deepseek/deepseek-v3.2-exp' => ['DeepSeek: DeepSeek V3.2 Exp', Api::OpenAiCompletions, 163_840, 147_456, true, false, 0.27, 0.41, 0.0, 0.0],
        'deepseek/deepseek-v4-flash' => ['DeepSeek: DeepSeek V4 Flash 0423', Api::OpenAiCompletions, 1_048_576, 943_718, true, false, 0.0173, 1.28, 0.0173, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'deepseek/deepseek-v4-flash-0731' => ['DeepSeek: DeepSeek V4 Flash 0731', Api::OpenAiCompletions, 1_048_576, 943_718, true, false, 0.0046, 1.28, 0.0046, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek/deepseek-v4-flash-vision-exp' => ['DeepSeek: DeepSeek V4 Flash Vision Exp', Api::OpenAiCompletions, 1_048_576, 262_144, true, true, 0.2156, 0.6468, 0.00686, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek/deepseek-v4-pro' => ['DeepSeek: DeepSeek V4 Pro 0423', Api::OpenAiCompletions, 1_024_000, 384_000, true, false, 0.287274, 0.574548, 0.02394, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'deepseek/deepseek-v4-pro-0813' => ['DeepSeek: DeepSeek V4 Pro 0813', Api::OpenAiCompletions, 1_048_576, 393_216, true, false, 0.66, 1.98, 0.022, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek/deepseek-v4.1-flash' => ['DeepSeek: DeepSeek V4.1 Flash', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 0.3, 1.2, 0.006, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek/deepseek-v4.1-flash:batch' => ['DeepSeek: DeepSeek V4.1 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.112, 0.336, 0.00336, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'dots-studio/dots-3-note-preview:free' => ['Dots Studio: Dots3-Note Preview (free)', Api::OpenAiCompletions, 512_000, 460_800, true, true, 0.0, 0.0, 0.0, 0.0],
        'fireworks/ember-1' => ['Fireworks: Ember-1', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 3.0, 15.0, 0.3, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'google/gemini-2.5-flash' => ['Google: Gemini 2.5 Flash', Api::OpenAiCompletions, 1_048_576, 65_535, true, true, 0.3, 2.5, 0.03, 0.083333],
        'google/gemini-2.5-flash-lite' => ['Google: Gemini 2.5 Flash Lite', Api::OpenAiCompletions, 1_048_576, 65_535, true, true, 0.1, 0.4, 0.01, 0.083333],
        'google/gemini-2.5-flash-lite:batch' => ['Google: Gemini 2.5 Flash Lite (batch)', Api::OpenAiCompletions, 1_048_576, 65_535, true, true, 0.05, 0.2, 0.01, 0.0],
        'google/gemini-2.5-flash:batch' => ['Google: Gemini 2.5 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 65_535, true, true, 0.15, 1.25, 0.03, 0.0],
        'google/gemini-2.5-pro' => ['Google: Gemini 2.5 Pro', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 1.25, 10.0, 0.125, 0.375, 'thinkingLevelMap' => ['off' => null], 'tiers' => [[200_000, 2.5, 15.0, 0.25, 0.375]]],
        'google/gemini-2.5-pro-preview' => ['Google: Gemini 2.5 Pro Preview 06-05', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 1.25, 10.0, 0.125, 0.375, 'thinkingLevelMap' => ['off' => null], 'tiers' => [[200_000, 2.5, 15.0, 0.25, 0.375]]],
        'google/gemini-2.5-pro:batch' => ['Google: Gemini 2.5 Pro (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.625, 5.0, 0.125, 0.0, 'thinkingLevelMap' => ['off' => null], 'tiers' => [[200_000, 1.25, 7.5, 0.25, 0.0]]],
        'google/gemini-3-flash-preview' => ['Google: Gemini 3 Flash Preview', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.5, 3.0, 0.05, 0.083333, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3-flash-preview:batch' => ['Google: Gemini 3 Flash Preview (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.25, 1.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3-pro-image' => ['Google: Nano Banana Pro (Gemini 3 Pro Image)', Api::OpenAiCompletions, 65_536, 32_768, true, true, 2.0, 12.0, 0.2, 0.375, 'thinkingLevelMap' => ['off' => null]],
        'google/gemini-3.1-flash-lite' => ['Google: Gemini 3.1 Flash Lite', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.083333, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.1-flash-lite-preview' => ['Google: Gemini 3.1 Flash Lite Preview', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.25, 1.5, 0.025, 0.083333, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.1-flash-lite:batch' => ['Google: Gemini 3.1 Flash Lite (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.125, 0.75, 0.0125, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.1-pro-preview' => ['Google: Gemini 3.1 Pro Preview', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.375, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.375]]],
        'google/gemini-3.1-pro-preview-customtools' => ['Google: Gemini 3.1 Pro Preview Custom Tools', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.375, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.375]]],
        'google/gemini-3.1-pro-preview:batch' => ['Google: Gemini 3.1 Pro Preview (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 1.0, 6.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 2.0, 9.0, 0.0, 0.0]]],
        'google/gemini-3.5-flash' => ['Google: Gemini 3.5 Flash', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 1.5, 9.0, 0.15, 0.083333, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.5-flash-lite' => ['Google: Gemini 3.5 Flash Lite', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.3, 2.5, 0.03, 0.083333, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.5-flash-lite:batch' => ['Google: Gemini 3.5 Flash Lite (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.15, 1.25, 0.015, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.5-flash:batch' => ['Google: Gemini 3.5 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.75, 4.5, 0.075, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.6-flash' => ['Google: Gemini 3.6 Flash', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.6-flash:batch' => ['Google: Gemini 3.6 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.375, 1.875, 0.0375, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.7-flash' => ['Google: Gemini 3.7 Flash', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.7-flash:batch' => ['Google: Gemini 3.7 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.375, 1.875, 0.0375, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.8-flash' => ['Google: Gemini 3.8 Flash', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-3.8-flash:batch' => ['Google: Gemini 3.8 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.375, 1.875, 0.0375, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemini-nano-banana-2.1' => ['Google: Nano Banana 2.1', Api::OpenAiCompletions, 65_536, 58_982, true, true, 1.5, 7.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'google/gemma-3-12b-it' => ['Google: Gemma 3 12B', Api::OpenAiCompletions, 131_072, 16_384, false, true, 0.05, 0.15, 0.0, 0.0],
        'google/gemma-3-27b-it' => ['Google: Gemma 3 27B', Api::OpenAiCompletions, 131_072, 117_964, false, true, 0.08, 0.45, 0.04, 0.0],
        'google/gemma-4-26b-a4b-it' => ['Google: Gemma 4 26B A4B ', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.09, 0.3, 0.05, 0.0],
        'google/gemma-4-26b-a4b-it:free' => ['Google: Gemma 4 26B A4B  (free)', Api::OpenAiCompletions, 262_144, 32_768, true, true, 0.0, 0.0, 0.0, 0.0],
        'google/gemma-4-31b-it' => ['Google: Gemma 4 31B', Api::OpenAiCompletions, 262_144, 16_384, true, true, 0.09, 0.34, 0.05, 0.0],
        'google/gemma-4-31b-it:free' => ['Google: Gemma 4 31B (free)', Api::OpenAiCompletions, 262_144, 32_768, true, true, 0.0, 0.0, 0.0, 0.0],
        'ibm-granite/granite-4.2-8b' => ['IBM: Granite 4.2 8B', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.06, 0.25, 0.015, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'inception/mercury-2' => ['Inception: Mercury 2', Api::OpenAiCompletions, 128_000, 50_000, true, false, 0.25, 0.75, 0.025, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'inception/mercury-2.5' => ['Inception: Mercury 2.5', Api::OpenAiCompletions, 260_000, 65_536, true, false, 0.04, 0.15, 0.004, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'inclusionai/ling-3.0-flash' => ['inclusionAI: Ling 3.0 Flash', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.021, 0.063, 0.0042, 0.0],
        'inclusionai/ling-3.0-flash-fin' => ['inclusionAI: Ling 3.0 Flash Fin', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.042, 0.1232, 0.0084, 0.0],
        'inclusionai/ling-3.0-flash-sante' => ['inclusionAI: Ling 3.0 Flash Sante', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.042, 0.1232, 0.0084, 0.0],
        'inclusionai/ling-3.0-flash-vl' => ['inclusionAI: Ling 3.0 Flash VL', Api::OpenAiCompletions, 262_144, 32_768, true, true, 0.021, 0.0616, 0.0042, 0.0],
        'inclusionai/ling-3.1-flash' => ['inclusionAI: Ling 3.1 Flash', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'liquid/lfm-2.5-2.6b:free' => ['LiquidAI: LFM2.5-2.6B (free)', Api::OpenAiCompletions, 65_536, 8_192, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'meituan/longcat-2.0' => ['Meituan: LongCat 2.0', Api::OpenAiCompletions, 1_048_756, 262_144, true, false, 0.3, 1.2, 0.006, 0.0],
        'meta-llama/llama-3.1-70b-instruct' => ['Meta: Llama 3.1 70B Instruct', Api::OpenAiCompletions, 131_072, 16_384, false, false, 0.4, 0.4, 0.0, 0.0],
        'meta-llama/llama-3.1-8b-instruct' => ['Meta: Llama 3.1 8B Instruct', Api::OpenAiCompletions, 131_072, 117_964, false, false, 0.05, 0.08, 0.025, 0.0],
        'meta-llama/llama-3.3-70b-instruct' => ['Meta: Llama 3.3 70B Instruct', Api::OpenAiCompletions, 131_072, 16_384, false, false, 0.1, 0.32, 0.0, 0.0],
        'meta-llama/llama-4-maverick' => ['Meta: Llama 4 Maverick', Api::OpenAiCompletions, 128_000, 16_384, false, true, 0.1875, 0.6525, 0.05, 0.0],
        'meta-llama/llama-4-scout' => ['Meta: Llama 4 Scout', Api::OpenAiCompletions, 327_680, 16_384, false, true, 0.1, 0.3, 0.0, 0.0],
        'meta/muse-glimmer-30b' => ['Meta: Muse Glimmer 30B', Api::OpenAiCompletions, 131_072, 16_384, true, true, 0.3, 1.2, 0.04, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'meta/muse-spark-1.1' => ['Meta: Muse Spark 1.1', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 1.25, 4.25, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'meta/muse-spark-1.2' => ['Meta: Muse Spark 1.2', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 1.25, 4.25, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'meta/muse-spark-1.2-contributor' => ['Meta: Muse Spark 1.2 Contributor', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 0.1, 0.2, 0.002, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'meta/muse-spark-1.3' => ['Meta: Muse Spark 1.3', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 1.25, 4.25, 0.15, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'meta/muse-spark-1.3-contributor' => ['Meta: Muse Spark 1.3 Contributor', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 0.1, 0.2, 0.002, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'minimax/minimax-m1' => ['MiniMax: MiniMax M1', Api::OpenAiCompletions, 1_000_000, 40_000, true, false, 0.55, 2.2, 0.0, 0.0],
        'minimax/minimax-m2' => ['MiniMax: MiniMax M2', Api::OpenAiCompletions, 196_608, 176_947, true, false, 0.3, 1.2, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'minimax/minimax-m2.1' => ['MiniMax: MiniMax M2.1', Api::OpenAiCompletions, 204_800, 131_072, true, false, 0.3, 1.2, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'minimax/minimax-m2.5' => ['MiniMax: MiniMax M2.5', Api::OpenAiCompletions, 200_000, 128_000, true, false, 0.27, 1.08, 0.027, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'minimax/minimax-m2.7' => ['MiniMax: MiniMax M2.7', Api::OpenAiCompletions, 196_608, 176_947, true, false, 0.21, 0.84, 0.042, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'minimax/minimax-m3' => ['MiniMax: MiniMax M3', Api::OpenAiCompletions, 524_288, 512_000, true, true, 0.3, 1.2, 0.06, 0.0],
        'mistralai/codestral-2508' => ['Mistral: Codestral 2508', Api::OpenAiCompletions, 256_000, 204_800, false, false, 0.3, 0.9, 0.03, 0.0],
        'mistralai/codestral-2508:batch' => ['Mistral: Codestral 2508 (batch)', Api::OpenAiCompletions, 256_000, 204_800, false, false, 0.15, 0.45, 0.015, 0.0],
        'mistralai/devstral-2512' => ['Mistral: Devstral 2 2512', Api::OpenAiCompletions, 262_144, 209_715, false, false, 0.4, 2.0, 0.04, 0.0],
        'mistralai/ministral-14b-2512' => ['Mistral: Ministral 3 14B 2512', Api::OpenAiCompletions, 262_144, 209_715, false, true, 0.2, 0.2, 0.02, 0.0],
        'mistralai/ministral-3b-2512' => ['Mistral: Ministral 3 3B 2512', Api::OpenAiCompletions, 131_072, 104_857, false, true, 0.1, 0.1, 0.01, 0.0],
        'mistralai/ministral-8b-2512' => ['Mistral: Ministral 3 8B 2512', Api::OpenAiCompletions, 262_144, 209_715, false, true, 0.15, 0.15, 0.015, 0.0],
        'mistralai/ministral-8b-2512:batch' => ['Mistral: Ministral 3 8B 2512 (batch)', Api::OpenAiCompletions, 262_144, 209_715, false, true, 0.075, 0.075, 0.0075, 0.0],
        'mistralai/mistral-large' => ['Mistral Large', Api::OpenAiCompletions, 128_000, 102_400, false, false, 2.0, 6.0, 0.2, 0.0],
        'mistralai/mistral-large-2407' => ['Mistral Large 2407', Api::OpenAiCompletions, 131_072, 104_857, false, false, 2.0, 6.0, 0.2, 0.0],
        'mistralai/mistral-large-2512' => ['Mistral: Mistral Large 3 2512', Api::OpenAiCompletions, 262_144, 209_715, false, true, 0.5, 1.5, 0.05, 0.0],
        'mistralai/mistral-large-2512:batch' => ['Mistral: Mistral Large 3 2512 (batch)', Api::OpenAiCompletions, 262_144, 209_715, false, true, 0.25, 0.75, 0.025, 0.0],
        'mistralai/mistral-large-4-0' => ['Mistral: Mistral Large 4', Api::OpenAiCompletions, 1_048_576, 262_144, true, true, 0.68, 2.09, 0.07, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistralai/mistral-medium-3' => ['Mistral: Mistral Medium 3', Api::OpenAiCompletions, 131_072, 104_857, false, true, 0.4, 2.0, 0.04, 0.0],
        'mistralai/mistral-medium-3-5' => ['Mistral: Mistral Medium 3.5', Api::OpenAiCompletions, 262_144, 209_715, true, true, 1.5, 7.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistralai/mistral-medium-3-5:batch' => ['Mistral: Mistral Medium 3.5 (batch)', Api::OpenAiCompletions, 262_144, 209_715, true, true, 0.75, 3.75, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistralai/mistral-medium-3.1' => ['Mistral: Mistral Medium 3.1', Api::OpenAiCompletions, 131_072, 104_857, false, true, 0.4, 2.0, 0.04, 0.0],
        'mistralai/mistral-medium-3.1:batch' => ['Mistral: Mistral Medium 3.1 (batch)', Api::OpenAiCompletions, 131_072, 104_857, false, true, 0.2, 1.0, 0.02, 0.0],
        'mistralai/mistral-nemo' => ['Mistral: Mistral Nemo', Api::OpenAiCompletions, 131_072, 16_384, false, false, 0.019, 0.03, 0.0, 0.0],
        'mistralai/mistral-saba' => ['Mistral: Saba', Api::OpenAiCompletions, 32_768, 26_214, false, false, 0.2, 0.6, 0.02, 0.0],
        'mistralai/mistral-small-2603' => ['Mistral: Mistral Small 4', Api::OpenAiCompletions, 262_144, 209_715, true, true, 0.15, 0.6, 0.015, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistralai/mistral-small-2603:batch' => ['Mistral: Mistral Small 4 (batch)', Api::OpenAiCompletions, 262_144, 209_715, true, true, 0.075, 0.3, 0.0075, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'mistralai/mistral-small-3.1-24b-instruct' => ['Mistral: Mistral Small 3.1 24B', Api::OpenAiCompletions, 128_000, 102_400, false, true, 0.351, 0.555, 0.0, 0.0],
        'mistralai/mistral-small-3.2-24b-instruct' => ['Mistral: Mistral Small 3.2 24B', Api::OpenAiCompletions, 256_000, 16_384, false, true, 0.09375, 0.25, 0.0, 0.0],
        'mistralai/mixtral-8x22b-instruct' => ['Mistral: Mixtral 8x22B Instruct', Api::OpenAiCompletions, 65_536, 52_428, false, false, 2.0, 6.0, 0.2, 0.0],
        'mistralai/voxtral-small-24b-2507' => ['Mistral: Voxtral Small 24B 2507', Api::OpenAiCompletions, 32_768, 26_214, false, false, 0.1, 0.3, 0.01, 0.0],
        'moonshotai/kimi-k2' => ['MoonshotAI: Kimi K2 0711', Api::OpenAiCompletions, 131_072, 98_304, false, false, 0.57, 2.3, 0.0, 0.0],
        'moonshotai/kimi-k2-0905' => ['MoonshotAI: Kimi K2 0905', Api::OpenAiCompletions, 262_144, 98_304, false, false, 0.6, 2.5, 0.0, 0.0],
        'moonshotai/kimi-k2-thinking' => ['MoonshotAI: Kimi K2 Thinking', Api::OpenAiCompletions, 262_144, 235_929, true, false, 0.6, 2.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'moonshotai/kimi-k2.5' => ['MoonshotAI: Kimi K2.5', Api::OpenAiCompletions, 262_144, 4_096, true, true, 0.41, 2.06, 0.07, 0.0],
        'moonshotai/kimi-k2.6' => ['MoonshotAI: Kimi K2.6', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.4375, 2.45, 0.1211, 0.0],
        'moonshotai/kimi-k2.7-code' => ['MoonshotAI: Kimi K2.7 Code', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.6712, 3.35, 0.18, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'moonshotai/kimi-k3' => ['MoonshotAI: Kimi K3', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.99, 14.0, 0.66, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'moonshotai/kimi-k3:batch' => ['MoonshotAI: Kimi K3 (batch)', Api::OpenAiCompletions, 1_048_576, 16_384, true, true, 2.28, 11.4, 0.228, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'nex-agi/nex-n2.5-pro' => ['Nex AGI: Nex-N2.5-Pro', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.075, 0.25, 0.015, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'nvidia/nemotron-3-nano-30b-a3b' => ['NVIDIA: Nemotron 3 Nano 30B A3B', Api::OpenAiCompletions, 262_144, 235_929, true, false, 0.06, 0.24, 0.0, 0.0],
        'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free' => ['NVIDIA: Nemotron 3 Nano Omni (free)', Api::OpenAiCompletions, 256_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'nvidia/nemotron-3-super-120b-a12b' => ['NVIDIA: Nemotron 3 Super', Api::OpenAiCompletions, 262_144, 235_929, true, false, 0.08, 0.45, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => null, 'max' => null]],
        'nvidia/nemotron-3-super-120b-a12b:free' => ['NVIDIA: Nemotron 3 Super (free)', Api::OpenAiCompletions, 262_144, 235_929, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => null, 'max' => null]],
        'nvidia/nemotron-3-ultra-550b-a55b' => ['NVIDIA: Nemotron 3 Ultra', Api::OpenAiCompletions, 262_144, 16_384, true, false, 0.5, 2.2, 0.1, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'nvidia/nemotron-3-ultra-550b-a55b:free' => ['NVIDIA: Nemotron 3 Ultra (free)', Api::OpenAiCompletions, 1_000_000, 65_536, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'nvidia/nemotron-3.5-lightning' => ['NVIDIA: Nemotron 3.5 Lightning', Api::OpenAiCompletions, 262_144, 131_072, true, false, 0.049, 0.14, 0.0245, 0.0],
        'nvidia/nemotron-3.5-lightning:free' => ['NVIDIA: Nemotron 3.5 Lightning (free)', Api::OpenAiCompletions, 1_000_000, 65_536, true, false, 0.0, 0.0, 0.0, 0.0],
        'openai/gpt-3.5-turbo' => ['OpenAI: GPT-3.5 Turbo', Api::OpenAiCompletions, 16_385, 4_096, false, false, 0.5, 1.5, 0.0, 0.0],
        'openai/gpt-3.5-turbo-0613' => ['OpenAI: GPT-3.5 Turbo (older v0613)', Api::OpenAiCompletions, 4_095, 3_685, false, false, 1.0, 2.0, 0.0, 0.0],
        'openai/gpt-3.5-turbo-16k' => ['OpenAI: GPT-3.5 Turbo 16k', Api::OpenAiCompletions, 16_385, 4_096, false, false, 3.0, 4.0, 0.0, 0.0],
        'openai/gpt-3.5-turbo:batch' => ['OpenAI: GPT-3.5 Turbo (batch)', Api::OpenAiCompletions, 16_385, 4_096, false, false, 0.25, 0.75, 0.0, 0.0],
        'openai/gpt-4' => ['OpenAI: GPT-4', Api::OpenAiCompletions, 8_191, 4_096, false, false, 30.0, 60.0, 0.0, 0.0],
        'openai/gpt-4-turbo' => ['OpenAI: GPT-4 Turbo', Api::OpenAiCompletions, 128_000, 4_096, false, true, 10.0, 30.0, 0.0, 0.0],
        'openai/gpt-4-turbo:batch' => ['OpenAI: GPT-4 Turbo (batch)', Api::OpenAiCompletions, 128_000, 4_096, false, true, 5.0, 15.0, 0.0, 0.0],
        'openai/gpt-4.1' => ['OpenAI: GPT-4.1', Api::OpenAiCompletions, 1_047_576, 32_768, false, true, 2.0, 8.0, 0.5, 0.0],
        'openai/gpt-4.1-mini' => ['OpenAI: GPT-4.1 Mini', Api::OpenAiCompletions, 1_047_576, 32_768, false, true, 0.4, 1.6, 0.1, 0.0],
        'openai/gpt-4.1-mini:batch' => ['OpenAI: GPT-4.1 Mini (batch)', Api::OpenAiCompletions, 1_047_576, 32_768, false, true, 0.2, 0.8, 0.05, 0.0],
        'openai/gpt-4.1-nano' => ['OpenAI: GPT-4.1 Nano', Api::OpenAiCompletions, 1_047_576, 32_768, false, true, 0.1, 0.4, 0.025, 0.0],
        'openai/gpt-4.1-nano:batch' => ['OpenAI: GPT-4.1 Nano (batch)', Api::OpenAiCompletions, 1_047_576, 32_768, false, true, 0.05, 0.2, 0.0125, 0.0],
        'openai/gpt-4.1:batch' => ['OpenAI: GPT-4.1 (batch)', Api::OpenAiCompletions, 1_047_576, 32_768, false, true, 1.0, 4.0, 0.25, 0.0],
        'openai/gpt-4o' => ['OpenAI: GPT-4o', Api::OpenAiCompletions, 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'openai/gpt-4o-2024-05-13' => ['OpenAI: GPT-4o (2024-05-13)', Api::OpenAiCompletions, 128_000, 4_096, false, true, 5.0, 15.0, 0.0, 0.0],
        'openai/gpt-4o-2024-08-06' => ['OpenAI: GPT-4o (2024-08-06)', Api::OpenAiCompletions, 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'openai/gpt-4o-2024-11-20' => ['OpenAI: GPT-4o (2024-11-20)', Api::OpenAiCompletions, 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'openai/gpt-4o-mini' => ['OpenAI: GPT-4o-mini', Api::OpenAiCompletions, 128_000, 16_384, false, true, 0.15, 0.6, 0.075, 0.0],
        'openai/gpt-4o-mini-2024-07-18' => ['OpenAI: GPT-4o-mini (2024-07-18)', Api::OpenAiCompletions, 128_000, 16_384, false, true, 0.15, 0.6, 0.075, 0.0],
        'openai/gpt-4o-mini:batch' => ['OpenAI: GPT-4o-mini (batch)', Api::OpenAiCompletions, 128_000, 16_384, false, true, 0.075, 0.3, 0.0375, 0.0],
        'openai/gpt-4o:batch' => ['OpenAI: GPT-4o (batch)', Api::OpenAiCompletions, 128_000, 16_384, false, true, 1.25, 5.0, 0.625, 0.0],
        'openai/gpt-5' => ['OpenAI: GPT-5', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5-mini' => ['OpenAI: GPT-5 Mini', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5-mini:batch' => ['OpenAI: GPT-5 Mini (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.125, 1.0, 0.0125, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5-nano' => ['OpenAI: GPT-5 Nano', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.05, 0.4, 0.005, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5-nano:batch' => ['OpenAI: GPT-5 Nano (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.025, 0.2, 0.0025, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5-pro' => ['OpenAI: GPT-5 Pro', Api::OpenAiCompletions, 400_000, 128_000, true, true, 15.0, 120.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5-pro:batch' => ['OpenAI: GPT-5 Pro (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 7.5, 60.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5.1' => ['OpenAI: GPT-5.1', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5.1-codex' => ['OpenAI: GPT-5.1-Codex', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.25, 10.0, 0.13, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5.1-codex-max' => ['OpenAI: GPT-5.1-Codex-Max', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.1-codex-mini' => ['OpenAI: GPT-5.1-Codex-Mini', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.25, 2.0, 0.03, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5.1:batch' => ['OpenAI: GPT-5.1 (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.625, 5.0, 0.0625, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-5.2' => ['OpenAI: GPT-5.2', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.2-chat' => ['OpenAI: GPT-5.2 Chat', Api::OpenAiCompletions, 128_000, 32_000, false, true, 1.75, 14.0, 0.175, 0.0],
        'openai/gpt-5.2-codex' => ['OpenAI: GPT-5.2-Codex', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.2-pro' => ['OpenAI: GPT-5.2 Pro', Api::OpenAiCompletions, 400_000, 128_000, true, true, 21.0, 168.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.2-pro:batch' => ['OpenAI: GPT-5.2 Pro (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 10.5, 84.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.2:batch' => ['OpenAI: GPT-5.2 (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.875, 7.0, 0.0875, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.3-codex' => ['OpenAI: GPT-5.3-Codex', Api::OpenAiCompletions, 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.4' => ['OpenAI: GPT-5.4', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 5.0, 22.5, 0.5, 0.0]]],
        'openai/gpt-5.4-mini' => ['OpenAI: GPT-5.4 Mini', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.4-mini:batch' => ['OpenAI: GPT-5.4 Mini (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.375, 2.25, 0.0375, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.4-nano' => ['OpenAI: GPT-5.4 Nano', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.4-nano:batch' => ['OpenAI: GPT-5.4 Nano (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.1, 0.625, 0.01, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'openai/gpt-5.4-pro' => ['OpenAI: GPT-5.4 Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 60.0, 270.0, 0.0, 0.0]]],
        'openai/gpt-5.4-pro:batch' => ['OpenAI: GPT-5.4 Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 15.0, 90.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 30.0, 135.0, 0.0, 0.0]]],
        'openai/gpt-5.4:batch' => ['OpenAI: GPT-5.4 (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.25, 7.5, 0.125, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 2.5, 11.25, 0.25, 0.0]]],
        'openai/gpt-5.5' => ['OpenAI: GPT-5.5', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'openai/gpt-5.5-pro' => ['OpenAI: GPT-5.5 Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 60.0, 270.0, 0.0, 0.0]]],
        'openai/gpt-5.5-pro:batch' => ['OpenAI: GPT-5.5 Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 15.0, 90.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 30.0, 135.0, 0.0, 0.0]]],
        'openai/gpt-5.5:batch' => ['OpenAI: GPT-5.5 (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[272_000, 5.0, 22.5, 0.5, 0.0]]],
        'openai/gpt-5.6-luna' => ['OpenAI: GPT-5.6 Luna', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]]],
        'openai/gpt-5.6-luna-pro' => ['OpenAI: GPT-5.6 Luna Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.4, 1.8, 0.04, 0.5]]],
        'openai/gpt-5.6-luna-pro:batch' => ['OpenAI: GPT-5.6 Luna Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.1, 0.6, 0.01, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.2, 0.9, 0.02, 0.0]]],
        'openai/gpt-5.6-luna:batch' => ['OpenAI: GPT-5.6 Luna (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.1, 0.6, 0.01, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.2, 0.9, 0.02, 0.0]]],
        'openai/gpt-5.6-sol' => ['OpenAI: GPT-5.6 Sol', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'openai/gpt-5.6-sol-pro' => ['OpenAI: GPT-5.6 Sol Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'openai/gpt-5.6-sol-pro:batch' => ['OpenAI: GPT-5.6 Sol Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.0, 5.0, 0.1, 1.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 2.0, 7.5, 0.2, 2.5]]],
        'openai/gpt-5.6-sol:batch' => ['OpenAI: GPT-5.6 Sol (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.0, 5.0, 0.1, 1.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 2.0, 7.5, 0.2, 2.5]]],
        'openai/gpt-5.6-terra' => ['OpenAI: GPT-5.6 Terra', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]]],
        'openai/gpt-5.6-terra-pro' => ['OpenAI: GPT-5.6 Terra Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]]],
        'openai/gpt-5.6-terra-pro:batch' => ['OpenAI: GPT-5.6 Terra Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.0, 6.0, 0.1, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 2.0, 9.0, 0.2, 0.0]]],
        'openai/gpt-5.6-terra:batch' => ['OpenAI: GPT-5.6 Terra (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.0, 6.0, 0.1, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 2.0, 9.0, 0.2, 0.0]]],
        'openai/gpt-5:batch' => ['OpenAI: GPT-5 (batch)', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.625, 5.0, 0.0625, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-6-astra' => ['OpenAI: GPT-6 Astra', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]]],
        'openai/gpt-6-astra-pro' => ['OpenAI: GPT-6 Astra Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]]],
        'openai/gpt-6-astra-pro:batch' => ['OpenAI: GPT-6 Astra Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 10.0, 37.5, 1.0, 12.5]]],
        'openai/gpt-6-astra:batch' => ['OpenAI: GPT-6 Astra (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 10.0, 37.5, 1.0, 12.5]]],
        'openai/gpt-6-luna' => ['OpenAI: GPT-6 Luna', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],
        'openai/gpt-6-luna-pro' => ['OpenAI: GPT-6 Luna Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],
        'openai/gpt-6-luna-pro:batch' => ['OpenAI: GPT-6 Luna Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.05, 0.25, 0.005, 0.0625, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.1, 0.375, 0.01, 0.125]]],
        'openai/gpt-6-luna:batch' => ['OpenAI: GPT-6 Luna (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.05, 0.25, 0.005, 0.0625, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.1, 0.375, 0.01, 0.125]]],
        'openai/gpt-6-sol' => ['OpenAI: GPT-6 Sol', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'openai/gpt-6-sol-pro' => ['OpenAI: GPT-6 Sol Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'openai/gpt-6-sol-pro:batch' => ['OpenAI: GPT-6 Sol Pro (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.0, 5.0, 0.1, 1.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 2.0, 7.5, 0.2, 2.5]]],
        'openai/gpt-6-sol:batch' => ['OpenAI: GPT-6 Sol (batch)', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 1.0, 5.0, 0.1, 1.25, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 2.0, 7.5, 0.2, 2.5]]],
        'openai/gpt-6.1-sol' => ['OpenAI: GPT-6.1 Sol', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]]],
        'openai/gpt-6.1-sol-pro' => ['OpenAI: GPT-6.1 Sol Pro', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]]],
        'openai/gpt-audio' => ['OpenAI: GPT Audio', Api::OpenAiCompletions, 128_000, 16_384, false, false, 2.5, 10.0, 0.0, 0.0],
        'openai/gpt-audio-mini' => ['OpenAI: GPT Audio Mini', Api::OpenAiCompletions, 128_000, 16_384, false, false, 0.6, 2.4, 0.0, 0.0],
        'openai/gpt-chat-latest' => ['OpenAI: GPT Chat Latest', Api::OpenAiCompletions, 400_000, 128_000, false, true, 5.0, 30.0, 0.5, 0.0],
        'openai/gpt-oss-120b' => ['OpenAI: gpt-oss-120b', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.037, 0.17, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-120b:batch' => ['OpenAI: gpt-oss-120b (batch)', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.0296, 0.136, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-20b' => ['OpenAI: gpt-oss-20b', Api::OpenAiCompletions, 131_072, 32_768, true, false, 0.018, 0.09, 0.009, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-20b:batch' => ['OpenAI: gpt-oss-20b (batch)', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.024, 0.112, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/gpt-oss-safeguard-20b' => ['OpenAI: gpt-oss-safeguard-20b', Api::OpenAiCompletions, 131_072, 65_536, true, false, 0.075, 0.3, 0.0375, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'openai/o1' => ['OpenAI: o1', Api::OpenAiCompletions, 200_000, 100_000, true, true, 15.0, 60.0, 7.5, 0.0],
        'openai/o3' => ['OpenAI: o3', Api::OpenAiCompletions, 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0],
        'openai/o3-mini' => ['OpenAI: o3 Mini', Api::OpenAiCompletions, 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0],
        'openai/o3-mini-high' => ['OpenAI: o3 Mini High', Api::OpenAiCompletions, 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/o3-mini:batch' => ['OpenAI: o3 Mini (batch)', Api::OpenAiCompletions, 200_000, 100_000, true, false, 0.55, 2.2, 0.275, 0.0],
        'openai/o3-pro' => ['OpenAI: o3 Pro', Api::OpenAiCompletions, 200_000, 100_000, true, true, 20.0, 80.0, 0.0, 0.0],
        'openai/o3:batch' => ['OpenAI: o3 (batch)', Api::OpenAiCompletions, 200_000, 100_000, true, true, 1.0, 4.0, 0.25, 0.0],
        'openai/o4-mini' => ['OpenAI: o4 Mini', Api::OpenAiCompletions, 200_000, 100_000, true, true, 1.1, 4.4, 0.275, 0.0],
        'openai/o4-mini-high' => ['OpenAI: o4 Mini High', Api::OpenAiCompletions, 200_000, 100_000, true, true, 1.1, 4.4, 0.275, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'openai/o4-mini:batch' => ['OpenAI: o4 Mini (batch)', Api::OpenAiCompletions, 200_000, 100_000, true, true, 0.55, 2.2, 0.1375, 0.0],
        'openrouter/auto' => ['Auto Router', Api::OpenAiCompletions, 2_000_000, 4_096, true, true, -1000000.0, -1000000.0, 0.0, 0.0],
        'openrouter/auto-beta' => ['Auto Router (Beta)', Api::OpenAiCompletions, 2_000_000, 4_096, true, true, -1000000.0, -1000000.0, 0.0, 0.0],
        'openrouter/free' => ['Free Models Router', Api::OpenAiCompletions, 200_000, 4_096, true, true, 0.0, 0.0, 0.0, 0.0],
        'openrouter/fusion' => ['OpenRouter: Fusion', Api::OpenAiCompletions, 1_000_000, 30_000, true, false, 0.0, 0.0, 0.0, 0.0],
        'perceptron/perceptron-mk1.5' => ['Perceptron: Perceptron Mk1.5', Api::OpenAiCompletions, 36_864, 8_192, true, true, 0.15, 1.5, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'poolside/laguna-s-2.1' => ['Poolside: Laguna S 2.1', Api::OpenAiCompletions, 1_048_576, 131_072, true, false, 0.09, 0.18, 0.009, 0.0],
        'poolside/laguna-s-2.1:free' => ['Poolside: Laguna S 2.1 (free)', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'poolside/laguna-xs-2.1' => ['Poolside: Laguna XS 2.1', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.06, 0.12, 0.03, 0.0],
        'poolside/laguna-xs-2.1:free' => ['Poolside: Laguna XS 2.1 (free)', Api::OpenAiCompletions, 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'prism-ml/ternary-bonsai-2-27b' => ['PrismML: Ternary Bonsai 2 27B', Api::OpenAiCompletions, 262_144, 32_768, true, true, 0.075, 0.5, 0.0375, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen/qwen-2.5-72b-instruct' => ['Qwen2.5 72B Instruct', Api::OpenAiCompletions, 32_768, 16_384, false, false, 0.36, 0.4, 0.0, 0.0],
        'qwen/qwen-2.5-7b-instruct' => ['Qwen: Qwen2.5 7B Instruct', Api::OpenAiCompletions, 32_768, 29_491, false, false, 0.1, 0.2, 0.0, 0.0],
        'qwen/qwen-plus' => ['Qwen: Qwen-Plus', Api::OpenAiCompletions, 1_000_000, 32_768, false, false, 0.26, 0.78, 0.052, 0.325, 'tiers' => [[256_000, 0.78, 2.34, 0.156, 0.975]]],
        'qwen/qwen-plus-2025-07-28' => ['Qwen: Qwen Plus 0728', Api::OpenAiCompletions, 1_000_000, 32_768, false, false, 0.26, 0.78, 0.0, 0.0, 'tiers' => [[256_000, 0.78, 2.34, 0.0, 0.0]]],
        'qwen/qwen3-14b' => ['Qwen: Qwen3 14B', Api::OpenAiCompletions, 40_960, 16_384, true, false, 0.12, 0.24, 0.0, 0.0],
        'qwen/qwen3-235b-a22b' => ['Qwen: Qwen3 235B A22B', Api::OpenAiCompletions, 131_072, 8_192, true, false, 0.455, 1.82, 0.0, 0.0],
        'qwen/qwen3-235b-a22b-2507' => ['Qwen: Qwen3 235B A22B Instruct 2507', Api::OpenAiCompletions, 262_144, 16_384, false, false, 0.09, 0.55, 0.0, 0.0],
        'qwen/qwen3-235b-a22b-thinking-2507' => ['Qwen: Qwen3 235B A22B Thinking 2507', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.23, 2.3, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'qwen/qwen3-30b-a3b' => ['Qwen: Qwen3 30B A3B', Api::OpenAiCompletions, 40_960, 16_384, true, false, 0.12, 0.5, 0.0, 0.0],
        'qwen/qwen3-30b-a3b-instruct-2507' => ['Qwen: Qwen3 30B A3B Instruct 2507', Api::OpenAiCompletions, 262_144, 235_929, false, false, 0.1, 0.3, 0.0, 0.0],
        'qwen/qwen3-30b-a3b-thinking-2507' => ['Qwen: Qwen3 30B A3B Thinking 2507', Api::OpenAiCompletions, 81_920, 32_768, true, false, 0.2, 2.4, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'qwen/qwen3-32b' => ['Qwen: Qwen3 32B', Api::OpenAiCompletions, 40_960, 16_384, true, false, 0.08, 0.28, 0.0, 0.0],
        'qwen/qwen3-8b' => ['Qwen: Qwen3 8B', Api::OpenAiCompletions, 131_072, 8_192, true, false, 0.117, 0.455, 0.0, 0.0],
        'qwen/qwen3-coder' => ['Qwen: Qwen3 Coder 480B A35B', Api::OpenAiCompletions, 262_144, 65_536, false, false, 0.3, 1.0, 0.1, 0.0],
        'qwen/qwen3-coder-30b-a3b-instruct' => ['Qwen: Qwen3 Coder 30B A3B Instruct', Api::OpenAiCompletions, 262_144, 235_929, false, false, 0.07, 0.28, 0.0, 0.0],
        'qwen/qwen3-coder-flash' => ['Qwen: Qwen3 Coder Flash', Api::OpenAiCompletions, 1_000_000, 65_536, false, false, 0.195, 0.975, 0.039, 0.24375, 'tiers' => [[32_000, 0.325, 1.625, 0.065, 0.40625], [128_000, 0.52, 2.6, 0.104, 0.65]]],
        'qwen/qwen3-coder-next' => ['Qwen: Qwen3 Coder Next', Api::OpenAiCompletions, 262_144, 235_929, false, false, 0.12, 0.8, 0.07, 0.0],
        'qwen/qwen3-coder-plus' => ['Qwen: Qwen3 Coder Plus', Api::OpenAiCompletions, 1_000_000, 65_536, false, false, 0.65, 3.25, 0.13, 0.8125, 'tiers' => [[32_000, 1.17, 5.85, 0.234, 1.4625], [128_000, 1.95, 9.75, 0.39, 2.4375]]],
        'qwen/qwen3-max' => ['Qwen: Qwen3 Max', Api::OpenAiCompletions, 262_144, 65_536, false, false, 0.78, 3.9, 0.156, 0.975, 'tiers' => [[32_000, 1.56, 7.8, 0.312, 1.95], [128_000, 1.95, 9.75, 0.39, 2.4375]]],
        'qwen/qwen3-max-thinking' => ['Qwen: Qwen3 Max Thinking', Api::OpenAiCompletions, 262_144, 65_536, true, false, 0.78, 3.9, 0.0, 0.0, 'tiers' => [[32_000, 1.56, 7.8, 0.0, 0.0], [128_000, 1.95, 9.75, 0.0, 0.0]]],
        'qwen/qwen3-next-80b-a3b-instruct' => ['Qwen: Qwen3 Next 80B A3B Instruct', Api::OpenAiCompletions, 262_144, 16_384, false, false, 0.09, 1.1, 0.0, 0.0],
        'qwen/qwen3-next-80b-a3b-thinking' => ['Qwen: Qwen3 Next 80B A3B Thinking', Api::OpenAiCompletions, 131_072, 32_768, true, false, 0.15, 1.2, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'qwen/qwen3-vl-235b-a22b-instruct' => ['Qwen: Qwen3 VL 235B A22B Instruct', Api::OpenAiCompletions, 131_072, 32_768, false, true, 0.21, 1.9, 0.1, 0.0],
        'qwen/qwen3-vl-235b-a22b-thinking' => ['Qwen: Qwen3 VL 235B A22B Thinking', Api::OpenAiCompletions, 131_072, 32_768, true, true, 0.4, 4.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'qwen/qwen3-vl-30b-a3b-instruct' => ['Qwen: Qwen3 VL 30B A3B Instruct', Api::OpenAiCompletions, 262_144, 16_384, false, true, 0.15, 0.6, 0.0, 0.0],
        'qwen/qwen3-vl-30b-a3b-thinking' => ['Qwen: Qwen3 VL 30B A3B Thinking', Api::OpenAiCompletions, 131_072, 32_768, true, true, 0.2, 2.4, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'qwen/qwen3-vl-32b-instruct' => ['Qwen: Qwen3 VL 32B Instruct', Api::OpenAiCompletions, 131_072, 32_768, false, true, 0.104, 0.416, 0.0, 0.0],
        'qwen/qwen3-vl-8b-instruct' => ['Qwen: Qwen3 VL 8B Instruct', Api::OpenAiCompletions, 131_072, 32_768, false, true, 0.117, 0.455, 0.0, 0.0],
        'qwen/qwen3-vl-8b-thinking' => ['Qwen: Qwen3 VL 8B Thinking', Api::OpenAiCompletions, 131_072, 32_768, true, true, 0.18, 2.1, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'qwen/qwen3.5-122b-a10b' => ['Qwen: Qwen3.5-122B-A10B', Api::OpenAiCompletions, 262_144, 65_536, true, true, 0.26, 2.08, 0.0, 0.0],
        'qwen/qwen3.5-27b' => ['Qwen: Qwen3.5-27B', Api::OpenAiCompletions, 262_144, 81_920, true, true, 0.26, 2.6, 0.0, 0.0],
        'qwen/qwen3.5-35b-a3b' => ['Qwen: Qwen3.5-35B-A3B', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.15, 1.0, 0.05, 0.0],
        'qwen/qwen3.5-397b-a17b' => ['Qwen: Qwen3.5 397B A17B', Api::OpenAiCompletions, 262_144, 81_920, true, true, 0.45, 3.0, 0.22, 0.0],
        'qwen/qwen3.5-9b' => ['Qwen: Qwen3.5-9B', Api::OpenAiCompletions, 256_000, 32_768, true, true, 0.1, 0.15, 0.0, 0.0],
        'qwen/qwen3.5-flash-02-23' => ['Qwen: Qwen3.5-Flash', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.065, 0.26, 0.0, 0.0],
        'qwen/qwen3.5-plus-02-15' => ['Qwen: Qwen3.5 Plus 2026-02-15', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.26, 1.56, 0.0, 0.0, 'tiers' => [[256_000, 0.325, 1.95, 0.0, 0.0]]],
        'qwen/qwen3.5-plus-20260420' => ['Qwen: Qwen3.5 Plus 2026-04-20', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.3, 1.8, 0.0, 0.375, 'tiers' => [[256_000, 0.375, 2.25, 0.0, 0.46875]]],
        'qwen/qwen3.6-27b' => ['Qwen: Qwen3.6 27B', Api::OpenAiCompletions, 262_144, 65_536, true, true, 0.3, 2.0, 0.03, 0.0],
        'qwen/qwen3.6-35b-a3b' => ['Qwen: Qwen3.6 35B A3B', Api::OpenAiCompletions, 262_144, 235_929, true, true, 0.15, 1.0, 0.05, 0.0],
        'qwen/qwen3.6-flash' => ['Qwen: Qwen3.6 Flash', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.1875, 1.125, 0.0, 0.234375, 'tiers' => [[256_000, 0.75, 3.0, 0.0, 0.9375]]],
        'qwen/qwen3.6-max-preview' => ['Qwen: Qwen3.6 Max Preview', Api::OpenAiCompletions, 262_144, 65_536, true, false, 1.027, 6.162, 0.0, 1.28375, 'tiers' => [[128_000, 1.58, 9.48, 0.0, 1.975]]],
        'qwen/qwen3.6-plus' => ['Qwen: Qwen3.6 Plus', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.325, 1.95, 0.0, 0.40625, 'tiers' => [[256_000, 1.3, 3.9, 0.0, 1.625]]],
        'qwen/qwen3.7-flash' => ['Qwen: Qwen3.7 Flash', Api::OpenAiCompletions, 1_000_000, 65_536, true, true, 0.03, 0.13, 0.006, 0.038, 'tiers' => [[32_000, 0.1, 0.4, 0.02, 0.125], [256_000, 0.2, 0.8, 0.04, 0.25]]],
        'qwen/qwen3.7-max' => ['Qwen: Qwen3.7 Max', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 1.475, 4.425, 0.295, 1.84375],
        'qwen/qwen3.7-plus' => ['Qwen: Qwen3.7 Plus', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.32, 1.28, 0.064, 0.4, 'tiers' => [[256_000, 0.96, 3.84, 0.192, 1.2]]],
        'qwen/qwen3.8-2.4t-a95b' => ['Qwen: Qwen3.8 2.4T A95B', Api::OpenAiCompletions, 1_048_576, 131_072, true, false, 2.0, 6.0, 0.25, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen/qwen3.8-27b' => ['Qwen: Qwen3.8 27B', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.425, 2.55, 0.085, 0.53125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen/qwen3.8-flash' => ['Qwen: Qwen3.8 Flash', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.15, 0.47, 0.016, 0.2],
        'qwen/qwen3.8-max-0902' => ['Qwen: Qwen3.8 Max (0902)', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 2.0, 6.0, 0.25, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'qwen/qwen3.8-max-prime' => ['Qwen: Qwen3.8 Max Prime', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 4.0, 12.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'qwen/qwen3.8-omni-flash' => ['Qwen: Qwen3.8 Omni Flash', Api::OpenAiCompletions, 1_000_000, 131_072, true, true, 0.15, 0.47, 0.016, 0.0],
        'rekaai/reka-edge' => ['Reka Edge', Api::OpenAiCompletions, 16_384, 14_745, false, true, 0.1, 0.1, 0.0, 0.0],
        'relace/relace-search' => ['Relace: Relace Search', Api::OpenAiCompletions, 256_000, 128_000, false, false, 1.0, 3.0, 0.0, 0.0],
        'sakana/fugu-max' => ['Sakana: Fugu Max', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.0, 6.0, 0.25, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'sakana/fugu-ultra' => ['Sakana: Fugu Ultra', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'sakana/fugu-ultra-v2' => ['Sakana: Fugu Ultra v2', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'sakana/sakana-namazu' => ['Sakana: Sakana Namazu', Api::OpenAiCompletions, 262_144, 65_536, true, true, 0.95, 4.0, 0.15, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'stepfun/step-3.5-flash' => ['StepFun: Step 3.5 Flash', Api::OpenAiCompletions, 262_144, 65_536, true, false, 0.1, 0.3, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null]],
        'stepfun/step-3.7-flash' => ['StepFun: Step 3.7 Flash', Api::OpenAiCompletions, 256_000, 230_400, true, true, 0.2, 1.15, 0.04, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'stepfun/step-5-preview' => ['StepFun: Step 5 Preview', Api::OpenAiCompletions, 1_000_000, 64_000, true, true, 1.0, 2.7, 0.05, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'tencent/hy3' => ['Tencent: Hy3', Api::OpenAiCompletions, 262_144, 128_000, true, false, 0.0825, 0.33, 0.020625, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'tencent/hy3-preview' => ['Tencent: Hy3 preview', Api::OpenAiCompletions, 262_144, 235_929, true, false, 0.18, 0.6, 0.06, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'tencent/hy4-preview' => ['Tencent: Hy4 preview', Api::OpenAiCompletions, 1_048_576, 64_000, true, false, 0.7506, 2.2509, 0.0378, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'thinkingmachines/inkling' => ['Thinking Machines: Inkling', Api::OpenAiCompletions, 524_288, 471_859, true, true, 1.0, 4.05, 0.17, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'thinkingmachines/inkling-small' => ['Thinking Machines: Inkling Small', Api::OpenAiCompletions, 524_288, 262_144, true, true, 0.45, 1.2, 0.1, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'thinkingmachines/inkling-small:free' => ['Thinking Machines: Inkling Small (free)', Api::OpenAiCompletions, 1_048_576, 262_144, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'thinkingmachines/inkling:free' => ['Thinking Machines: Inkling (free)', Api::OpenAiCompletions, 1_048_576, 262_144, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'typesafe/jev-router' => ['TypeSafe: Jev Router', Api::OpenAiCompletions, 1_000_000, 4_096, true, true, -1000000.0, -1000000.0, 0.0, 0.0],
        'unbiased/pareto' => ['Pareto', Api::OpenAiCompletions, 262_144, 131_072, false, true, 2.5, 7.5, 0.25, 0.0],
        'unbiased/pareto-26.10-preview' => ['Pareto 26.10 Preview', Api::OpenAiCompletions, 1_048_576, 131_072, false, true, 0.8, 3.2, 0.03, 0.0],
        'upstage/solar-mini4' => ['Upstage: Solar Mini 4', Api::OpenAiCompletions, 524_288, 131_072, true, false, 0.05, 0.2, 0.005, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'upstage/solar-pro-3' => ['Upstage: Solar Pro 3', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.15, 0.6, 0.015, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'upstage/solar-pro4' => ['Upstage: Solar Pro 4', Api::OpenAiCompletions, 524_288, 131_072, true, false, 0.09, 0.36, 0.018, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'x-ai/grok-4.20' => ['SpaceXAI: Grok 4.20', Api::OpenAiCompletions, 2_000_000, 1_800_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'x-ai/grok-4.3' => ['SpaceXAI: Grok 4.3', Api::OpenAiCompletions, 1_000_000, 900_000, true, true, 1.25, 2.5, 0.2, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'x-ai/grok-4.3:batch' => ['SpaceXAI: Grok 4.3 (batch)', Api::OpenAiCompletions, 1_000_000, 900_000, true, true, 1.0, 2.0, 0.16, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 2.0, 4.0, 0.32, 0.0]]],
        'x-ai/grok-4.5' => ['SpaceXAI: Grok 4.5', Api::OpenAiCompletions, 500_000, 450_000, true, true, 2.0, 6.0, 0.3, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 12.0, 0.6, 0.0]]],
        'x-ai/grok-4.6' => ['SpaceXAI: Grok 4.6', Api::OpenAiCompletions, 500_000, 450_000, true, true, 2.0, 6.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]]],
        'x-ai/grok-4.7' => ['SpaceXAI: Grok 4.7', Api::OpenAiCompletions, 500_000, 450_000, true, true, 2.0, 6.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]]],
        'x-ai/grok-build-0.1' => ['SpaceXAI: Grok Build 0.1', Api::OpenAiCompletions, 256_000, 230_400, true, true, 1.0, 2.0, 0.2, 0.0, 'thinkingLevelMap' => ['off' => null], 'tiers' => [[200_000, 2.0, 4.0, 0.4, 0.0]]],
        'xiaomi/mimo-v2.5' => ['Xiaomi: MiMo-V2.5', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.14, 0.28, 0.0028, 0.0],
        'xiaomi/mimo-v2.5-pro' => ['Xiaomi: MiMo-V2.5-Pro', Api::OpenAiCompletions, 1_048_576, 131_072, true, false, 0.435, 0.87, 0.0036, 0.0],
        'xiaomi/mimo-v2.6-flash' => ['Xiaomi: MiMo-V2.6-Flash', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.14, 0.28, 0.0028, 0.0],
        'xiaomi/mimo-v2.6-pro' => ['Xiaomi: MiMo-V2.6-Pro', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.435, 0.87, 0.0036, 0.0],
        'xiaomi/mimo-v2.6-pro-ultraspeed' => ['Xiaomi: MiMo-V2.6-Pro-UltraSpeed', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 4.35, 8.7, 0.036, 0.0],
        'z-ai/glm-4.5' => ['Z.ai: GLM 4.5', Api::OpenAiCompletions, 131_072, 98_304, true, false, 0.6, 2.2, 0.11, 0.0],
        'z-ai/glm-4.5-air' => ['Z.ai: GLM 4.5 Air', Api::OpenAiCompletions, 131_072, 98_304, true, false, 0.13, 0.85, 0.025, 0.0],
        'z-ai/glm-4.5v' => ['Z.ai: GLM 4.5V', Api::OpenAiCompletions, 65_536, 16_384, true, true, 0.6, 1.8, 0.11, 0.0],
        'z-ai/glm-4.6' => ['Z.ai: GLM 4.6', Api::OpenAiCompletions, 198_000, 16_384, true, false, 0.43, 1.75, 0.08, 0.0],
        'z-ai/glm-4.6v' => ['Z.ai: GLM 4.6V', Api::OpenAiCompletions, 131_072, 32_768, true, true, 0.3, 0.9, 0.055, 0.0],
        'z-ai/glm-4.7' => ['Z.ai: GLM 4.7', Api::OpenAiCompletions, 202_752, 131_072, true, false, 0.6, 2.2, 0.11, 0.0],
        'z-ai/glm-4.7-flash' => ['Z.ai: GLM 4.7 Flash', Api::OpenAiCompletions, 131_072, 117_964, true, false, 0.0605, 0.4, 0.0, 0.0],
        'z-ai/glm-5' => ['Z.ai: GLM 5', Api::OpenAiCompletions, 198_000, 128_000, true, false, 0.6, 1.9, 0.119, 0.0],
        'z-ai/glm-5-turbo' => ['Z.ai: GLM 5 Turbo', Api::OpenAiCompletions, 202_752, 131_072, true, false, 1.2, 4.0, 0.24, 0.0],
        'z-ai/glm-5.1' => ['Z.ai: GLM 5.1', Api::OpenAiCompletions, 200_000, 128_000, true, false, 0.966, 3.036, 0.1794, 0.0],
        'z-ai/glm-5.2' => ['Z.ai: GLM 5.2', Api::OpenAiCompletions, 1_048_576, 131_072, true, false, 0.03, 10.0, 0.03, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        'z-ai/glm-5.3' => ['Z.ai: GLM 5.3', Api::OpenAiCompletions, 1_048_576, 943_718, true, false, 0.1, 4.2, 0.048, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5.3-flash' => ['Z.ai: GLM 5.3 Flash', Api::OpenAiCompletions, 1_048_575, 943_717, true, true, 0.15, 0.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5.3-flash:batch' => ['Z.ai: GLM 5.3 Flash (batch)', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.06, 0.2, 0.012, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5.3-flashx' => ['Z.ai: GLM 5.3 FlashX', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.37, 1.25, 0.09, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5.3-prime' => ['Z.ai: GLM 5.3 Prime', Api::OpenAiCompletions, 1_000_000, 131_072, true, false, 2.8, 8.8, 0.56, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5.3:batch' => ['Z.ai: GLM 5.3 (batch)', Api::OpenAiCompletions, 1_048_576, 131_072, true, false, 0.45, 2.0, 0.1, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'z-ai/glm-5v-turbo' => ['Z.ai: GLM 5V Turbo', Api::OpenAiCompletions, 202_752, 131_072, true, true, 1.2, 4.0, 0.24, 0.0],
        '~anthropic/claude-fable-latest' => ['Anthropic: Claude Fable Latest', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        '~anthropic/claude-haiku-latest' => ['Anthropic: Claude Haiku Latest', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],
        '~anthropic/claude-opus-latest' => ['Anthropic: Claude Opus Latest', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        '~anthropic/claude-sonnet-latest' => ['Anthropic: Claude Sonnet Latest', Api::OpenAiCompletions, 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        '~deepseek/deepseek-flash-latest' => ['DeepSeek: DeepSeek Flash Latest', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 0.0283, 1.0, 0.01, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '~deepseek/deepseek-pro-latest' => ['DeepSeek: DeepSeek Pro Latest', Api::OpenAiCompletions, 1_048_576, 393_216, true, false, 0.1271, 8.0, 0.0953, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '~deepseek/deepseek-v4-flash-latest' => ['DeepSeek: DeepSeek V4 Flash Latest', Api::OpenAiCompletions, 1_048_576, 943_718, true, false, 0.0046, 1.28, 0.0046, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '~google/gemini-flash-latest' => ['Google: Gemini Flash Latest', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 0.75, 3.75, 0.075, 0.041667, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        '~google/gemini-pro-latest' => ['Google: Gemini Pro Latest', Api::OpenAiCompletions, 1_048_576, 65_536, true, true, 2.0, 12.0, 0.2, 0.375, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null], 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.375]]],
        '~moonshotai/kimi-latest' => ['MoonshotAI: Kimi Latest', Api::OpenAiCompletions, 1_048_576, 131_072, true, true, 0.83, 13.0, 0.45, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '~openai/gpt-astra-latest' => ['OpenAI: GPT Astra Latest', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]]],
        '~openai/gpt-luna-latest' => ['OpenAI: GPT Luna Latest', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],
        '~openai/gpt-mini-latest' => ['OpenAI: GPT Mini Latest', Api::OpenAiCompletions, 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null]],
        '~openai/gpt-sol-latest' => ['OpenAI: GPT Sol Latest', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]]],
        '~openai/gpt-terra-latest' => ['OpenAI: GPT Terra Latest', Api::OpenAiCompletions, 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'thinkingLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'], 'tiers' => [[272_000, 4.0, 18.0, 0.4, 5.0]]],
        '~x-ai/grok-latest' => ['xAI: Grok Latest', Api::OpenAiCompletions, 500_000, 450_000, true, true, 2.0, 6.0, 0.5, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => null], 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]]],
        '~z-ai/glm-flash-latest' => ['Z.ai: GLM Flash Latest', Api::OpenAiCompletions, 1_048_576, 943_718, true, true, 0.032, 3.52495, 0.02, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        '~z-ai/glm-latest' => ['Z.ai: GLM Latest', Api::OpenAiCompletions, 1_048_576, 131_072, true, false, 0.06, 12.0, 0.0558, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * Alibaba Cloud Model Studio's Token Plan, international, China and Individual: models.dev's
     * `alibaba-token-plan` and `alibaba-token-plan-cn` ("pi exposes them as qwen-token-plan[-cn] plus
     * the Individual catalog view"), the Individual plan narrowed to `QWEN_TOKEN_PLAN_INDIVIDUAL_MODEL_IDS`.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array QWEN_TOKEN_PLAN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'MiniMax-M2.5' => ['MiniMax-M2.5', 196_608, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'deepseek-v3.2' => ['DeepSeek V3.2', 131_072, 65_536, true, false, 0.0, 0.0, 0.0, 0.0],
        'deepseek-v4-flash' => ['DeepSeek V4 Flash', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-flash-0731' => ['DeepSeek V4 Flash 0731', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro' => ['DeepSeek V4 Pro', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro-0813' => ['DeepSeek V4 Pro 0813', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', 1_000_000, 384_000, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5' => ['GLM-5', 202_752, 16_384, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.1' => ['GLM-5.1', 202_752, 128_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.2' => ['GLM-5.2', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3' => ['GLM-5.3', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'kimi-k2.5' => ['Kimi K2.5', 262_144, 98_304, true, true, 0.0, 0.0, 0.0, 0.0],
        'kimi-k2.6' => ['Kimi K2.6', 262_144, 262_144, true, true, 0.0, 0.0, 0.0, 0.0],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', 262_144, 262_144, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.6-flash' => ['Qwen3.6 Flash', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.6-plus' => ['Qwen3.6 Plus', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.7-max' => ['Qwen3.7 Max', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0],
        'qwen3.7-plus' => ['Qwen3.7 Plus', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.8-flash' => ['Qwen3.8 Flash', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen3.8-max' => ['Qwen3.8 Max', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array QWEN_TOKEN_PLAN_CN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'MiniMax-M2.5' => ['MiniMax-M2.5', 196_608, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'deepseek-v3.2' => ['DeepSeek V3.2', 131_072, 65_536, true, false, 0.0, 0.0, 0.0, 0.0],
        'deepseek-v4-flash' => ['DeepSeek V4 Flash', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-flash-0731' => ['DeepSeek V4 Flash 0731', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro' => ['DeepSeek V4 Pro', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro-0813' => ['DeepSeek V4 Pro 0813', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', 1_000_000, 384_000, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5' => ['GLM-5', 202_752, 16_384, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.1' => ['GLM-5.1', 202_752, 128_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.2' => ['GLM-5.2', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3' => ['GLM-5.3', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'kimi-k2.5' => ['Kimi K2.5', 262_144, 98_304, true, true, 0.0, 0.0, 0.0, 0.0],
        'kimi-k2.6' => ['Kimi K2.6', 262_144, 262_144, true, true, 0.0, 0.0, 0.0, 0.0],
        'kimi-k2.7-code' => ['Kimi K2.7 Code', 262_144, 262_144, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.6-flash' => ['Qwen3.6 Flash', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.6-plus' => ['Qwen3.6 Plus', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.7-max' => ['Qwen3.7 Max', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0],
        'qwen3.7-plus' => ['Qwen3.7 Plus', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.8-flash' => ['Qwen3.8 Flash', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen3.8-max' => ['Qwen3.8 Max', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array QWEN_TOKEN_PLAN_INDIVIDUAL_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'deepseek-v4-flash-0731' => ['DeepSeek V4 Flash 0731', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro' => ['DeepSeek V4 Pro', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-v4-pro-0813' => ['DeepSeek V4 Pro 0813', 1_000_000, 384_000, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.2' => ['GLM-5.2', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'qwen3.6-flash' => ['Qwen3.6 Flash', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.7-max' => ['Qwen3.7 Max', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0],
        'qwen3.7-plus' => ['Qwen3.7 Plus', 1_000_000, 65_536, true, true, 0.0, 0.0, 0.0, 0.0],
        'qwen3.8-flash' => ['Qwen3.8 Flash', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        'qwen3.8-max' => ['Qwen3.8 Max', 1_000_000, 131_072, true, true, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null]],
        // <<< generated
    ];

    /**
     * Together AI: models.dev's `together` entry, deprecated models left out, each row's map
     * `getTogetherThinkingLevelMap()`'s.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array TOGETHER_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'MiniMaxAI/MiniMax-M2.7' => ['MiniMax-M2.7', 196_608, 131_072, true, false, 0.3, 1.2, 0.06, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null]],
        'MiniMaxAI/MiniMax-M3' => ['MiniMax-M3', 524_288, 250_000, true, true, 0.3, 1.2, 0.06, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null]],
        'Qwen/Qwen2.5-7B-Instruct-Turbo' => ['Qwen 2.5 7B Instruct Turbo', 32_768, 32_768, false, false, 0.3, 0.3, 0.0, 0.0],
        'Qwen/Qwen3.5-9B' => ['Qwen3.5 9B', 262_144, 65_536, true, true, 0.17, 0.25, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null]],
        'Qwen/Qwen3.6-Plus' => ['Qwen3.6 Plus', 1_000_000, 500_000, true, false, 0.5, 3.0, 0.0, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null]],
        'Qwen/Qwen3.7-Max' => ['Qwen3.7 Max', 1_000_000, 500_000, false, false, 1.25, 3.75, 0.125, 0.0],
        'deepseek-ai/DeepSeek-V4-Flash-0731' => ['DeepSeek V4 Flash 0731', 1_048_576, 384_000, true, false, 0.14, 0.28, 0.03, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-ai/DeepSeek-V4-Pro-0813' => ['DeepSeek V4 Pro 0813', 1_048_576, 384_000, true, false, 1.32, 3.96, 0.13, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'deepseek-ai/DeepSeek-V4.1-Flash' => ['DeepSeek V4.1 Flash', 1_048_576, 384_000, true, true, 0.3, 1.2, 0.006, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'meta-llama/Llama-3.3-70B-Instruct-Turbo' => ['Llama 3.3 70B', 131_072, 131_072, false, false, 1.04, 1.04, 0.0, 0.0],
        'moonshotai/Kimi-K3' => ['Kimi K3', 1_048_576, 131_072, true, true, 3.0, 15.0, 0.3, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'nvidia/nemotron-3-ultra-550b-a55b' => ['Nemotron 3 Ultra 550B A55B', 512_300, 512_300, true, false, 0.6, 3.6, 0.2, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null]],
        'openai/gpt-oss-120b' => ['GPT OSS 120B', 131_072, 131_072, true, false, 0.15, 0.6, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'thinkingmachines/Inkling' => ['Inkling', 524_288, 131_072, true, true, 1.0, 4.05, 0.17, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
        'zai-org/GLM-5.2' => ['GLM-5.2', 1_048_575, 164_000, true, false, 1.4, 4.4, 0.26, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'zai-org/GLM-5.3' => ['GLM-5.3', 1_048_576, 262_144, true, false, 1.4, 4.4, 0.26, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'zai-org/GLM-5.3-Flash' => ['GLM-5.3-Flash', 1_048_575, 400_000, true, true, 0.15, 0.5, 0.03, 0.0, 'thinkingLevelMap' => ['minimal' => null, 'low' => null, 'medium' => null], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /**
     * Vercel AI Gateway: its `/v1/models` listing's models tagged `tool-use` (`fetchAiGatewayModels()`),
     * every one on its Anthropic-compatible Messages API, priced by `getAiGatewayCost()`.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array VERCEL_AI_GATEWAY_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'alibaba/qwen-3-14b' => ['Qwen3-14B', 40_960, 16_384, true, false, 0.12, 0.24, 0.0, 0.0],
        'alibaba/qwen-3-235b' => ['Qwen3 235B A22B', 262_144, 16_384, true, false, 0.22, 0.88, 0.0, 0.0],
        'alibaba/qwen-3-30b' => ['Qwen3-30B-A3B', 40_960, 16_384, true, false, 0.12, 0.5, 0.0, 0.0],
        'alibaba/qwen-3-32b' => ['Qwen 3 32B', 128_000, 8_192, true, false, 0.16, 0.64, 0.0, 0.0],
        'alibaba/qwen-3.6-max-preview' => ['Qwen 3.6 Max Preview', 240_000, 64_000, true, false, 1.3, 7.8, 0.13, 1.625, 'tiers' => [[127_999, 2.0, 12.0, 0.2, 2.5]]],
        'alibaba/qwen3-235b-a22b-thinking' => ['Qwen3 VL 235B A22B Thinking', 131_072, 32_768, true, true, 0.4, 4.0, 0.0, 0.0],
        'alibaba/qwen3-coder' => ['Qwen3 Coder 480B A35B Instruct', 262_144, 65_536, false, false, 1.5, 7.5, 0.3, 0.0, 'tiers' => [[32_000, 2.7, 13.5, 0.54, 0.0], [128_000, 4.5, 22.5, 0.9, 0.0]]],
        'alibaba/qwen3-coder-30b-a3b' => ['Qwen 3 Coder 30B A3B Instruct', 262_144, 8_192, false, false, 0.15, 0.6, 0.0, 0.0],
        'alibaba/qwen3-coder-next' => ['Qwen3 Coder Next', 256_000, 256_000, false, false, 0.5, 1.2, 0.0, 0.0],
        'alibaba/qwen3-coder-plus' => ['Qwen3 Coder Plus', 1_000_000, 65_536, false, false, 1.0, 5.0, 0.2, 0.0, 'tiers' => [[32_000, 1.8, 9.0, 0.36, 0.0], [128_000, 3.0, 15.0, 0.6, 0.0], [256_000, 6.0, 60.0, 1.2, 0.0]]],
        'alibaba/qwen3-max' => ['Qwen3 Max', 262_144, 32_768, false, false, 1.2, 6.0, 0.24, 0.0, 'tiers' => [[32_000, 2.4, 12.0, 0.48, 0.0], [128_000, 3.0, 15.0, 0.6, 0.0]]],
        'alibaba/qwen3-max-preview' => ['Qwen3 Max Preview', 262_144, 32_768, false, false, 1.2, 6.0, 0.24, 0.0, 'tiers' => [[32_000, 2.4, 12.0, 0.48, 0.0], [128_000, 3.0, 15.0, 0.6, 0.0]]],
        'alibaba/qwen3-max-thinking' => ['Qwen 3 Max Thinking', 256_000, 65_536, true, false, 1.2, 6.0, 0.24, 0.0, 'tiers' => [[32_000, 2.4, 12.0, 0.48, 0.0], [128_000, 3.0, 15.0, 0.6, 0.0]]],
        'alibaba/qwen3-next-80b-a3b-instruct' => ['Qwen3 Next 80B A3B Instruct', 262_114, 262_114, false, false, 0.15, 1.2, 0.0, 0.0],
        'alibaba/qwen3-next-80b-a3b-thinking' => ['Qwen3 Next 80B A3B Thinking', 262_144, 262_144, true, false, 0.15, 1.2, 0.0, 0.0],
        'alibaba/qwen3-vl-235b-a22b-instruct' => ['Qwen3 VL 235B A22B Instruct', 131_072, 129_024, false, true, 0.4, 1.6, 0.0, 0.0],
        'alibaba/qwen3-vl-instruct' => ['Qwen3 VL 235B A22B Instruct', 131_072, 129_024, false, true, 0.4, 1.6, 0.0, 0.0],
        'alibaba/qwen3-vl-thinking' => ['Qwen3 VL 235B A22B Thinking', 131_072, 32_768, true, true, 0.4, 4.0, 0.0, 0.0],
        'alibaba/qwen3.5-flash' => ['Qwen 3.5 Flash', 1_000_000, 64_000, true, true, 0.1, 0.4, 0.01, 0.125],
        'alibaba/qwen3.5-plus' => ['Qwen 3.5 Plus', 1_000_000, 64_000, true, true, 0.4, 2.4, 0.04, 0.5, 'tiers' => [[256_000, 0.5, 3.0, 0.05, 0.625]]],
        'alibaba/qwen3.6-27b' => ['Qwen 3.6 27B', 256_000, 256_000, true, true, 0.6, 3.6, 0.0, 0.0],
        'alibaba/qwen3.6-plus' => ['Qwen 3.6 Plus', 1_000_000, 64_000, true, true, 0.5, 3.0, 0.05, 0.625, 'tiers' => [[255_999, 2.0, 6.0, 0.2, 2.5]]],
        'alibaba/qwen3.7-flash' => ['Qwen 3.7 Flash', 991_000, 64_000, true, true, 0.03, 0.13, 0.006, 0.038, 'tiers' => [[31_999, 0.1, 0.4, 0.02, 0.125], [255_999, 0.2, 0.8, 0.04, 0.25]]],
        'alibaba/qwen3.7-max' => ['Qwen 3.7 Max', 991_000, 64_000, true, false, 2.5, 7.5, 0.5, 3.125],
        'alibaba/qwen3.7-plus' => ['Qwen 3.7 Plus', 1_000_000, 64_000, true, true, 0.4, 1.6, 0.08, 0.5, 'tiers' => [[255_999, 1.2, 4.8, 0.24, 1.5]]],
        'alibaba/qwen3.8-2.4t-a95b' => ['Qwen3.8 2.4T A95B', 262_144, 128_000, true, true, 2.0, 6.0, 0.25, 0.0],
        'alibaba/qwen3.8-27b' => ['Qwen3.8 27B', 1_000_000, 131_072, true, true, 0.5, 3.0, 0.1, 0.625],
        'alibaba/qwen3.8-flash' => ['Qwen 3.8 Flash', 991_000, 128_000, true, true, 0.15, 0.47, 0.016, 0.2],
        'alibaba/qwen3.8-max' => ['Qwen 3.8 Max', 262_144, 128_000, true, true, 2.0, 6.0, 0.25, 0.0],
        'alibaba/qwen3.8-max-0902' => ['Qwen3.8 Max 0902', 991_000, 128_000, true, true, 2.0, 6.0, 0.25, 2.5],
        'alibaba/qwen3.8-max-prime' => ['Qwen 3.8 Max Prime', 1_000_000, 131_072, true, true, 4.0, 12.0, 0.5, 5.0],
        'alibaba/qwen3.8-omni-flash' => ['Qwen 3.8 Omni Flash', 1_000_000, 131_072, true, true, 0.15, 0.47, 0.016, 0.0],
        'amazon/nova-2-lite' => ['Nova 2 Lite', 1_000_000, 1_000_000, true, true, 0.3, 2.5, 0.075, 0.0],
        'amazon/nova-lite' => ['Nova Lite', 300_000, 8_192, false, true, 0.06, 0.24, 0.0, 0.0],
        'amazon/nova-micro' => ['Nova Micro', 128_000, 8_192, false, false, 0.035, 0.14, 0.0, 0.0],
        'amazon/nova-pro' => ['Nova Pro', 300_000, 8_192, false, true, 0.8, 3.2, 0.0, 0.0],
        'anthropic/claude-3-haiku' => ['Claude 3 Haiku', 200_000, 4_096, false, true, 0.25, 1.25, 0.03, 0.3],
        'anthropic/claude-fable-5' => ['Claude Fable 5', 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5],
        'anthropic/claude-fable-5.1' => ['Claude Fable 5.1', 1_000_000, 128_000, true, true, 10.0, 50.0, 0.25, 12.5],
        'anthropic/claude-haiku-4.5' => ['Claude Haiku 4.5', 200_000, 64_000, true, true, 1.0, 5.0, 0.1, 1.25],
        'anthropic/claude-haiku-5.5' => ['Claude Haiku 5.5', 1_000_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[100_000, 0.5, 2.5, 0.05, 0.625]]],
        'anthropic/claude-opus-4' => ['Claude Opus 4', 200_000, 32_000, true, true, 15.0, 75.0, 1.5, 18.75],
        'anthropic/claude-opus-4.5' => ['Claude Opus 4.5', 200_000, 64_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic/claude-opus-4.6' => ['Claude Opus 4.6', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic/claude-opus-4.7' => ['Claude Opus 4.7', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic/claude-opus-4.8' => ['Claude Opus 4.8', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic/claude-opus-4.8-fast' => ['Claude Opus 4.8 (Fast)', 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5],
        'anthropic/claude-opus-5' => ['Claude Opus 5', 1_000_000, 128_000, true, true, 5.0, 25.0, 0.5, 6.25],
        'anthropic/claude-opus-5-fast' => ['Claude Opus 5 (Fast)', 1_000_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5],
        'anthropic/claude-opus-5.5' => ['Claude Opus 5.5', 1_000_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0],
        'anthropic/claude-opus-5.5-fast' => ['Claude Opus 5.5 (Fast)', 1_000_000, 128_000, true, true, 8.0, 40.0, 0.4, 10.0],
        'anthropic/claude-sonnet-4' => ['Claude Sonnet 4', 1_000_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.6, 7.5]]],
        'anthropic/claude-sonnet-4.5' => ['Claude Sonnet 4.5', 1_000_000, 64_000, true, true, 3.0, 15.0, 0.3, 3.75, 'tiers' => [[200_000, 6.0, 22.5, 0.6, 7.5]]],
        'anthropic/claude-sonnet-4.6' => ['Claude Sonnet 4.6', 1_000_000, 128_000, true, true, 3.0, 15.0, 0.3, 3.75],
        'anthropic/claude-sonnet-5' => ['Claude Sonnet 5', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5],
        'anthropic/claude-sonnet-5.5' => ['Claude Sonnet 5.5', 1_000_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5],
        'arcee-ai/trinity-large-thinking' => ['Trinity Large Thinking', 262_100, 80_000, true, false, 0.25, 0.8, 0.0, 0.0],
        'bytedance/seed-1.6' => ['Seed 1.6', 256_000, 32_000, true, true, 0.25, 2.0, 0.05, 0.0, 'tiers' => [[128_000, 0.5, 4.0, 0.05, 0.0]]],
        'bytedance/seed-1.8' => ['Bytedance Seed 1.8', 256_000, 64_000, true, true, 0.25, 2.0, 0.05, 0.0, 'tiers' => [[128_000, 0.5, 4.0, 0.05, 0.0]]],
        'bytedance/seed-2.1-turbo' => ['Seed 2.1 Turbo', 262_144, 262_144, true, true, 0.5, 2.5, 0.1, 0.0],
        'cohere/command-a' => ['Command A', 256_000, 8_000, false, false, 2.5, 10.0, 0.0, 0.0],
        'deepseek/deepseek-r1' => ['DeepSeek-R1', 128_000, 8_192, true, false, 1.35, 5.4, 0.0, 0.0],
        'deepseek/deepseek-v3.1' => ['DeepSeek V3.1', 163_840, 128_000, true, false, 0.25, 0.95, 0.13, 0.0],
        'deepseek/deepseek-v3.2' => ['DeepSeek V3.2', 128_000, 8_000, false, false, 0.62, 1.85, 0.0, 0.0],
        'deepseek/deepseek-v3.2-thinking' => ['DeepSeek V3.2 Thinking', 128_000, 8_000, false, false, 0.62, 1.85, 0.0, 0.0],
        'deepseek/deepseek-v4-flash' => ['DeepSeek V4 Flash', 1_000_000, 384_000, true, false, 0.13, 0.26, 0.028, 0.0],
        'deepseek/deepseek-v4-flash-0731' => ['DeepSeek V4 Flash 0731', 1_000_000, 384_000, true, false, 0.076, 0.153, 0.014, 0.0],
        'deepseek/deepseek-v4-flash-vision-exp' => ['DeepSeek V4 Flash Vision Exp', 1_048_576, 1_048_576, true, true, 0.2156, 0.6468, 0.0068, 0.0],
        'deepseek/deepseek-v4-pro' => ['DeepSeek V4 Pro', 1_000_000, 384_000, true, false, 0.66, 1.98, 0.022, 0.0],
        'deepseek/deepseek-v4-pro-0813' => ['DeepSeek V4 Pro 0813', 1_000_000, 384_000, true, false, 0.66, 1.98, 0.066, 0.0],
        'deepseek/deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', 1_048_576, 32_768, true, true, 0.3, 1.2, 0.007, 0.0],
        'fireworks/ember-1' => ['Ember-1', 1_048_576, 1_048_576, true, true, 3.0, 15.0, 0.3, 0.0],
        'google/gemini-2.5-flash' => ['Gemini 2.5 Flash', 1_000_000, 65_535, true, true, 0.3, 2.5, 0.03, 0.0],
        'google/gemini-2.5-flash-lite' => ['Gemini 2.5 Flash Lite', 1_048_576, 65_535, true, true, 0.1, 0.4, 0.01, 0.0],
        'google/gemini-2.5-pro' => ['Gemini 2.5 Pro', 1_048_576, 65_535, true, true, 1.25, 10.0, 0.125, 0.0, 'tiers' => [[200_000, 2.5, 15.0, 0.25, 0.0]]],
        'google/gemini-3-flash' => ['Gemini 3 Flash', 1_000_000, 65_000, true, true, 0.5, 3.0, 0.05, 0.0, 'tiers' => [[200_000, 0.5, 3.0, 0.05, 0.0]]],
        'google/gemini-3.1-flash-lite' => ['Gemini 3.1 Flash Lite', 1_000_000, 65_000, true, true, 0.25, 1.5, 0.03, 0.0],
        'google/gemini-3.1-pro-preview' => ['Gemini 3.1 Pro Preview', 1_000_000, 64_000, true, true, 2.0, 12.0, 0.2, 0.0, 'tiers' => [[200_000, 4.0, 18.0, 0.4, 0.0]]],
        'google/gemini-3.5-flash' => ['Gemini 3.5 Flash', 1_000_000, 64_000, true, true, 1.5, 9.0, 0.15, 0.0],
        'google/gemini-3.5-flash-lite' => ['Gemini 3.5 Flash Lite', 1_000_000, 65_000, true, true, 0.3, 2.5, 0.03, 0.0],
        'google/gemini-3.6-flash' => ['Gemini 3.6 Flash', 1_000_000, 64_000, true, true, 0.75, 3.75, 0.075, 0.0],
        'google/gemini-3.7-flash' => ['Gemini 3.7 Flash', 1_000_000, 65_535, true, true, 0.75, 3.75, 0.075, 0.0],
        'google/gemini-3.8-flash' => ['Gemini 3.8 Flash', 1_000_000, 65_535, true, true, 0.75, 3.75, 0.075, 0.0],
        'google/gemma-4-26b-a4b-it' => ['Google Gemma 4 26B A4B', 262_144, 131_072, true, true, 0.15, 0.6, 0.015, 0.0],
        'google/gemma-4-31b-it' => ['Gemma 4 31B IT', 262_144, 131_072, true, true, 0.14, 0.4, 0.0, 0.0],
        'inception/mercury-2' => ['Mercury 2', 128_000, 128_000, true, false, 0.25, 0.75, 0.025, 0.0],
        'inception/mercury-2.5' => ['Mercury 2.5', 260_000, 65_536, true, false, 0.04, 0.15, 0.004, 0.0],
        'inception/mercury-coder-small' => ['Mercury Coder Small Beta', 32_000, 16_384, false, false, 0.25, 1.0, 0.0, 0.0],
        'inclusionai/ling-3.0-flash' => ['Ling 3.0 Flash', 256_000, 32_000, true, false, 0.021, 0.063, 0.0042, 0.0],
        'inclusionai/ling-3.0-flash-fin' => ['Ling 3.0 Flash Fin', 256_000, 32_000, true, false, 0.075, 0.22, 0.015, 0.0],
        'inclusionai/ling-3.0-flash-sante' => ['Ling 3.0 Flash Sante', 256_000, 32_000, true, false, 0.075, 0.22, 0.015, 0.0],
        'inclusionai/ling-3.0-flash-vl' => ['Ling 3.0 Flash VL', 256_000, 32_000, true, true, 0.075, 0.22, 0.015, 0.0],
        'inclusionai/ling-3.1-flash' => ['Ling 3.1 Flash', 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'inclusionai/ling-3.1-flash-free' => ['Ling 3.1 Flash (Free)', 262_144, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'interfaze/interfaze-beta' => ['Interfaze Beta', 1_000_000, 32_000, true, true, 1.5, 3.5, 0.0, 0.0],
        'meituan/longcat-2.5-preview' => ['LongCat 2.5 Preview', 1_048_576, 131_072, true, true, 0.3, 1.2, 0.006, 0.0],
        'meta/llama-3.1-70b' => ['Llama 3.1 70B Instruct', 128_000, 8_192, false, false, 0.72, 0.72, 0.0, 0.0],
        'meta/llama-3.1-8b' => ['Llama 3.1 8B Instruct', 128_000, 8_192, false, false, 0.22, 0.22, 0.0, 0.0],
        'meta/llama-3.3-70b' => ['Llama 3.3 70B Instruct', 128_000, 8_192, false, false, 0.72, 0.72, 0.0, 0.0],
        'meta/llama-4-maverick' => ['Llama 4 Maverick 17B Instruct', 128_000, 8_192, false, true, 0.24, 0.97, 0.0, 0.0],
        'meta/llama-4-scout' => ['Llama 4 Scout 17B Instruct', 128_000, 8_192, false, true, 0.17, 0.66, 0.0, 0.0],
        'meta/muse-glimmer-30b' => ['Muse Glimmer 30B', 131_072, 131_072, true, true, 0.35, 1.5, 0.04, 0.0],
        'meta/muse-spark-1.1' => ['Muse Spark 1.1', 1_048_576, 1_048_576, true, true, 1.25, 4.25, 0.15, 0.0],
        'meta/muse-spark-1.2' => ['Muse Spark 1.2', 1_048_576, 1_048_576, true, true, 1.25, 4.25, 0.15, 0.0],
        'meta/muse-spark-1.2-contributor' => ['Muse Spark 1.2 Contributor', 1_048_576, 1_048_576, true, true, 0.1, 0.2, 0.002, 0.0],
        'meta/muse-spark-1.3' => ['Muse Spark 1.3', 1_048_576, 1_048_576, true, true, 1.25, 4.25, 0.15, 0.0],
        'meta/muse-spark-1.3-contributor' => ['Muse Spark 1.3 Contributor', 1_048_576, 1_048_576, true, true, 0.1, 0.2, 0.002, 0.0],
        'minimax/minimax-m2' => ['MiniMax M2', 205_000, 205_000, true, false, 0.3, 1.2, 0.03, 0.375],
        'minimax/minimax-m2.1' => ['MiniMax M2.1', 204_800, 131_072, true, false, 0.3, 1.2, 0.03, 0.375],
        'minimax/minimax-m2.1-lightning' => ['MiniMax M2.1 Lightning', 204_800, 131_072, true, false, 0.3, 2.4, 0.03, 0.375],
        'minimax/minimax-m2.5' => ['MiniMax M2.5', 204_800, 131_000, true, false, 0.3, 1.2, 0.03, 0.375],
        'minimax/minimax-m2.5-highspeed' => ['MiniMax M2.5 High Speed', 204_800, 131_000, true, false, 0.6, 2.4, 0.03, 0.375],
        'minimax/minimax-m2.7' => ['MiniMax M2.7', 204_800, 131_000, true, false, 0.3, 1.2, 0.06, 0.375],
        'minimax/minimax-m2.7-highspeed' => ['MiniMax M2.7 High Speed', 204_800, 131_100, true, false, 0.6, 2.4, 0.06, 0.375],
        'minimax/minimax-m3' => ['MiniMax M3', 512_000, 512_000, true, true, 0.3, 1.2, 0.06, 0.0],
        'mistral/codestral' => ['Mistral Codestral', 128_000, 4_000, false, false, 0.3, 0.9, 0.03, 0.0],
        'mistral/ministral-14b' => ['Ministral 14B', 262_144, 256_000, false, true, 0.2, 0.2, 0.02, 0.0],
        'mistral/ministral-3b' => ['Ministral 3B', 131_072, 4_000, false, true, 0.1, 0.1, 0.01, 0.0],
        'mistral/ministral-8b' => ['Ministral 8B', 262_144, 4_000, false, true, 0.15, 0.15, 0.015, 0.0],
        'mistral/mistral-large-3' => ['Mistral Large 3', 262_144, 256_000, false, true, 0.5, 1.5, 0.05, 0.0],
        'mistral/mistral-large-4' => ['Mistral Large 4', 524_288, 262_144, true, true, 0.68, 2.09, 0.07, 0.0],
        'mistral/mistral-medium-3.5' => ['Mistral Medium Latest', 262_144, 256_000, true, true, 1.5, 7.5, 0.15, 0.0],
        'mistral/mistral-nemo' => ['Mistral Nemo 12B', 60_288, 16_000, false, false, 0.04, 0.17, 0.0, 0.0],
        'mistral/mistral-small' => ['Mistral Small', 262_144, 4_000, false, true, 0.15, 0.6, 0.015, 0.0],
        'mixedbread/toast-1' => ['Toast 1', 131_000, 4_000, false, false, 0.3, 0.72, 0.036, 0.0],
        'moonshotai/kimi-k2' => ['Kimi K2 Instruct', 131_072, 131_072, false, false, 0.57, 2.3, 0.0, 0.0],
        'moonshotai/kimi-k2.5' => ['Kimi K2.5', 256_000, 256_000, true, true, 0.6, 3.0, 0.0, 0.0],
        'moonshotai/kimi-k2.6' => ['Kimi K2.6', 262_000, 262_000, true, true, 0.95, 4.0, 0.16, 0.0],
        'moonshotai/kimi-k2.7-code' => ['Kimi K2.7 Code', 256_000, 32_768, true, true, 0.95, 4.0, 0.19, 0.0],
        'moonshotai/kimi-k2.7-code-highspeed' => ['Kimi K2.7 Code High Speed', 262_144, 32_768, true, true, 1.9, 8.0, 0.38, 0.0],
        'moonshotai/kimi-k3' => ['Kimi K3', 1_000_000, 131_072, true, true, 3.0, 15.0, 0.3, 0.0],
        'moonshotai/kimi-k3-fast' => ['Kimi K3 Fast', 1_000_000, 131_072, true, true, 4.5, 22.5, 0.45, 0.0],
        'nvidia/nemotron-3-nano-30b-a3b' => ['Nemotron 3 Nano 30B A3B', 262_144, 262_144, true, false, 0.05, 0.2, 0.025, 0.0],
        'nvidia/nemotron-3-super-120b-a12b' => ['NVIDIA Nemotron 3 Super 120B A12B', 256_000, 32_000, true, false, 0.15, 0.65, 0.0, 0.0],
        'nvidia/nemotron-3-ultra-550b-a55b' => ['Nemotron 3 Ultra', 1_000_000, 65_000, true, false, 0.6, 2.4, 0.12, 0.0],
        'nvidia/nemotron-3.5-lightning' => ['Nemotron 3.5 Lightning 30B', 262_144, 131_072, true, false, 0.05, 0.2, 0.01, 0.0],
        'nvidia/nemotron-nano-12b-v2-vl' => ['Nvidia Nemotron Nano 12B V2 VL', 131_072, 131_072, true, true, 0.2, 0.6, 0.0, 0.0],
        'nvidia/nemotron-nano-9b-v2' => ['Nvidia Nemotron Nano 9B V2', 131_072, 131_072, true, false, 0.06, 0.23, 0.0, 0.0],
        'openai/gpt-3.5-turbo' => ['GPT-3.5 Turbo', 16_385, 4_096, false, false, 0.5, 1.5, 0.0, 0.0],
        'openai/gpt-4-turbo' => ['GPT-4 Turbo', 128_000, 4_096, false, true, 10.0, 30.0, 0.0, 0.0],
        'openai/gpt-4.1' => ['GPT-4.1', 1_047_576, 32_768, false, true, 2.0, 8.0, 0.5, 0.0],
        'openai/gpt-4.1-fast' => ['GPT-4.1 (Fast)', 1_047_576, 32_768, false, true, 3.5, 14.0, 0.875, 0.0],
        'openai/gpt-4.1-mini' => ['GPT-4.1 mini', 1_047_576, 32_768, false, true, 0.4, 1.6, 0.1, 0.0],
        'openai/gpt-4.1-mini-fast' => ['GPT-4.1 mini (Fast)', 1_047_576, 32_768, false, true, 0.7, 2.8, 0.175, 0.0],
        'openai/gpt-4.1-nano' => ['GPT-4.1 nano', 1_047_576, 32_768, false, true, 0.1, 0.4, 0.025, 0.0],
        'openai/gpt-4.1-nano-fast' => ['GPT-4.1 nano (Fast)', 1_047_576, 32_768, false, true, 0.2, 0.8, 0.05, 0.0],
        'openai/gpt-4o' => ['GPT-4o', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'openai/gpt-4o-fast' => ['GPT-4o (Fast)', 128_000, 16_384, false, true, 4.25, 17.0, 2.125, 0.0],
        'openai/gpt-4o-mini' => ['GPT-4o mini', 128_000, 16_384, false, true, 0.15, 0.6, 0.075, 0.0],
        'openai/gpt-4o-mini-fast' => ['GPT-4o mini (Fast)', 128_000, 16_384, false, true, 0.25, 1.0, 0.125, 0.0],
        'openai/gpt-5' => ['GPT-5', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'openai/gpt-5-codex' => ['GPT-5-Codex', 400_000, 128_000, true, true, 1.25, 10.0, 0.13, 0.0],
        'openai/gpt-5-fast' => ['GPT-5 (Fast)', 400_000, 128_000, true, true, 2.5, 20.0, 0.25, 0.0],
        'openai/gpt-5-mini' => ['GPT-5 mini', 400_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0],
        'openai/gpt-5-mini-fast' => ['GPT-5 mini (Fast)', 400_000, 128_000, true, true, 0.45, 3.6, 0.045, 0.0],
        'openai/gpt-5-nano' => ['GPT-5 nano', 400_000, 128_000, true, true, 0.05, 0.4, 0.005, 0.0],
        'openai/gpt-5-pro' => ['GPT-5 pro', 400_000, 272_000, true, true, 15.0, 120.0, 0.0, 0.0],
        'openai/gpt-5.1-codex' => ['GPT-5.1-Codex', 400_000, 128_000, true, true, 1.25, 10.0, 0.13, 0.0],
        'openai/gpt-5.1-codex-max' => ['GPT 5.1 Codex Max', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'openai/gpt-5.1-codex-mini' => ['GPT 5.1 Codex Mini', 400_000, 128_000, true, true, 0.25, 2.0, 0.03, 0.0],
        'openai/gpt-5.1-thinking' => ['GPT 5.1 Thinking', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'openai/gpt-5.1-thinking-fast' => ['GPT 5.1 Thinking (Fast)', 400_000, 128_000, true, true, 2.5, 20.0, 0.25, 0.0],
        'openai/gpt-5.2' => ['GPT 5.2', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'openai/gpt-5.2-codex' => ['GPT 5.2 Codex', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'openai/gpt-5.2-fast' => ['GPT 5.2 (Fast)', 400_000, 128_000, true, true, 3.5, 28.0, 0.35, 0.0],
        'openai/gpt-5.2-pro' => ['GPT 5.2 ', 400_000, 128_000, true, true, 21.0, 168.0, 0.0, 0.0],
        'openai/gpt-5.3-codex' => ['GPT 5.3 Codex', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'openai/gpt-5.3-codex-fast' => ['GPT 5.3 Codex (Fast)', 400_000, 128_000, true, true, 3.5, 28.0, 0.35, 0.0],
        'openai/gpt-5.4' => ['GPT 5.4', 1_050_000, 128_000, true, true, 2.5, 15.0, 0.25, 0.0, 'tiers' => [[271_999, 5.0, 22.5, 0.5, 0.0]]],
        'openai/gpt-5.4-fast' => ['GPT 5.4 (Fast)', 1_050_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0],
        'openai/gpt-5.4-mini' => ['GPT 5.4 Mini', 400_000, 128_000, true, true, 0.75, 4.5, 0.075, 0.0],
        'openai/gpt-5.4-mini-fast' => ['GPT 5.4 Mini (Fast)', 400_000, 128_000, true, true, 1.5, 9.0, 0.15, 0.0],
        'openai/gpt-5.4-nano' => ['GPT 5.4 Nano', 400_000, 128_000, true, true, 0.2, 1.25, 0.02, 0.0],
        'openai/gpt-5.4-pro' => ['GPT 5.4 Pro', 1_050_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'tiers' => [[271_999, 60.0, 270.0, 0.0, 0.0]]],
        'openai/gpt-5.5' => ['GPT 5.5', 1_000_000, 128_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[271_999, 10.0, 45.0, 1.0, 0.0]]],
        'openai/gpt-5.5-fast' => ['GPT 5.5 (Fast)', 1_000_000, 128_000, true, true, 12.5, 75.0, 1.25, 0.0],
        'openai/gpt-5.5-pro' => ['GPT 5.5 Pro', 1_000_000, 128_000, true, true, 30.0, 180.0, 0.0, 0.0, 'tiers' => [[271_999, 60.0, 270.0, 0.0, 0.0]]],
        'openai/gpt-5.6-luna' => ['GPT 5.6 Luna', 1_050_000, 128_000, true, true, 0.2, 1.2, 0.02, 0.25, 'tiers' => [[271_999, 0.4, 1.8, 0.04, 0.5]]],
        'openai/gpt-5.6-luna-fast' => ['GPT 5.6 Luna (Fast)', 1_050_000, 128_000, true, true, 0.4, 2.4, 0.04, 0.5, 'tiers' => [[271_999, 0.8, 3.6, 0.08, 1.0]]],
        'openai/gpt-5.6-sol' => ['GPT 5.6 Sol', 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[271_999, 8.0, 30.0, 0.8, 10.0]]],
        'openai/gpt-5.6-sol-fast' => ['GPT 5.6 Sol (Fast)', 1_050_000, 128_000, true, true, 8.0, 40.0, 0.8, 10.0, 'tiers' => [[271_999, 16.0, 60.0, 1.6, 20.0]]],
        'openai/gpt-5.6-terra' => ['GPT 5.6 Terra', 1_050_000, 128_000, true, true, 2.0, 12.0, 0.2, 2.5, 'tiers' => [[271_999, 4.0, 18.0, 0.4, 5.0]]],
        'openai/gpt-5.6-terra-fast' => ['GPT 5.6 Terra (Fast)', 1_050_000, 128_000, true, true, 4.0, 24.0, 0.4, 5.0, 'tiers' => [[271_999, 8.0, 36.0, 0.8, 10.0]]],
        'openai/gpt-6-astra' => ['GPT-6 Astra', 1_050_000, 128_000, true, true, 10.0, 50.0, 1.0, 12.5, 'tiers' => [[272_000, 20.0, 75.0, 2.0, 25.0]]],
        'openai/gpt-6-astra-fast' => ['GPT-6 Astra (Fast)', 1_050_000, 128_000, true, true, 20.0, 100.0, 2.0, 25.0, 'tiers' => [[272_000, 40.0, 150.0, 4.0, 50.0]]],
        'openai/gpt-6-luna' => ['GPT-6 Luna', 1_050_000, 128_000, true, true, 0.1, 0.5, 0.01, 0.125, 'tiers' => [[272_000, 0.2, 0.75, 0.02, 0.25]]],
        'openai/gpt-6-luna-fast' => ['GPT-6 Luna (Fast)', 1_050_000, 128_000, true, true, 0.2, 1.0, 0.02, 0.25, 'tiers' => [[272_000, 0.4, 1.5, 0.04, 0.5]]],
        'openai/gpt-6-sol' => ['GPT-6 Sol', 1_050_000, 128_000, true, true, 2.0, 10.0, 0.2, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.4, 5.0]]],
        'openai/gpt-6-sol-fast' => ['GPT-6 Sol (Fast)', 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]]],
        'openai/gpt-6.1-sol' => ['GPT-6.1 Sol', 1_050_000, 128_000, true, true, 2.0, 10.0, 0.1, 2.5, 'tiers' => [[272_000, 4.0, 15.0, 0.2, 5.0]]],
        'openai/gpt-6.1-sol-fast' => ['GPT-6.1 Sol (Fast)', 1_050_000, 128_000, true, true, 4.0, 20.0, 0.2, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.4, 10.0]]],
        'openai/gpt-oss-120b' => ['GPT OSS 120B', 131_072, 131_072, true, false, 0.1, 0.5, 0.1, 0.0],
        'openai/gpt-oss-20b' => ['GPT OSS 20B', 131_072, 8_192, true, false, 0.03, 0.14, 0.0, 0.0],
        'openai/gpt-oss-safeguard-120b' => ['GPT OSS Safeguard 120B', 128_000, 16_000, true, false, 0.15, 0.6, 0.0, 0.0],
        'openai/gpt-oss-safeguard-20b' => ['GPT OSS Safeguard 20B', 128_000, 16_000, true, false, 0.07, 0.2, 0.0, 0.0],
        'openai/o1' => ['o1', 200_000, 100_000, true, true, 15.0, 60.0, 7.5, 0.0],
        'openai/o3' => ['o3', 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0],
        'openai/o3-fast' => ['o3 (Fast)', 200_000, 100_000, true, true, 3.5, 14.0, 0.875, 0.0],
        'openai/o3-mini' => ['o3-mini', 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0],
        'openai/o3-pro' => ['o3 Pro', 200_000, 100_000, true, true, 20.0, 80.0, 0.0, 0.0],
        'openai/o4-mini' => ['o4-mini', 200_000, 100_000, true, true, 1.1, 4.4, 0.275, 0.0],
        'openai/o4-mini-fast' => ['o4-mini (Fast)', 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0],
        'poolside/laguna-s-2.1' => ['Laguna S 2.1', 1_000_000, 131_072, true, false, 0.09, 0.18, 0.009, 0.0],
        'poolside/laguna-s-2.1-free' => ['Laguna S 2.1 Free', 256_000, 32_768, true, false, 0.0, 0.0, 0.0, 0.0],
        'quiverai/arrow-2' => ['Arrow 2', 131_072, 131_072, true, true, 4.0, 20.0, 0.4, 5.0],
        'quiverai/arrow-2-telos' => ['Arrow 2 Telos', 131_072, 131_072, true, true, 6.0, 30.0, 0.6, 7.5],
        'sakana/fugu-max' => ['Fugu Max', 1_000_000, 1_000_000, true, true, 2.0, 6.0, 0.25, 0.0],
        'sakana/fugu-ultra' => ['Fugu Ultra', 1_000_000, 1_000_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'sakana/fugu-ultra-v2' => ['Fugu Ultra v2', 1_000_000, 1_000_000, true, true, 5.0, 30.0, 0.5, 0.0, 'tiers' => [[272_000, 10.0, 45.0, 1.0, 0.0]]],
        'sakana/namazu' => ['Sakana Namazu', 256_000, 256_000, true, true, 0.95, 4.0, 0.15, 0.0],
        'spacexai/grok-4.1-fast-non-reasoning' => ['Grok 4.1 Fast Non-Reasoning', 1_000_000, 1_000_000, false, true, 0.2, 0.5, 0.05, 0.0],
        'spacexai/grok-4.1-fast-reasoning' => ['Grok 4.1 Fast Reasoning', 1_000_000, 1_000_000, true, true, 0.2, 0.5, 0.05, 0.0],
        'spacexai/grok-4.20-multi-agent' => ['Grok 4.20 Multi-Agent', 2_000_000, 2_000_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.20-multi-agent-beta' => ['Grok 4.20 Multi Agent Beta', 2_000_000, 2_000_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.20-non-reasoning' => ['Grok 4.20 Non-Reasoning', 2_000_000, 2_000_000, false, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.20-non-reasoning-beta' => ['Grok 4.20 Beta Non-Reasoning', 2_000_000, 2_000_000, false, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.20-reasoning' => ['Grok 4.20 Reasoning', 2_000_000, 2_000_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.20-reasoning-beta' => ['Grok 4.20 Beta Reasoning', 2_000_000, 2_000_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.3' => ['Grok 4.3', 1_000_000, 1_000_000, true, true, 1.25, 2.5, 0.2, 0.0, 'tiers' => [[200_000, 2.5, 5.0, 0.4, 0.0]]],
        'spacexai/grok-4.5' => ['Grok 4.5', 500_000, 500_000, true, true, 2.0, 6.0, 0.3, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 0.6, 0.0]]],
        'spacexai/grok-4.6' => ['Grok 4.6', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]]],
        'spacexai/grok-4.7' => ['Grok 4.7', 500_000, 500_000, true, true, 2.0, 6.0, 0.5, 0.0, 'tiers' => [[200_000, 4.0, 12.0, 1.0, 0.0]]],
        'spacexai/grok-build-0.1' => ['Grok Build 0.1', 256_000, 256_000, true, true, 1.0, 2.0, 0.2, 0.0, 'tiers' => [[200_000, 2.0, 4.0, 0.4, 0.0]]],
        'stealth/glyph-cluster' => ['Glyph Cluster', 256_000, 256_000, true, false, 0.0, 0.0, 0.0, 0.0],
        'stepfun/step-3.7-flash' => ['Step 3.7 Flash', 256_000, 256_000, true, true, 0.2, 1.15, 0.04, 0.0],
        'tencent/hy3' => ['Hy3', 262_144, 262_144, true, false, 0.14, 0.58, 0.035, 0.0],
        'tencent/hy4-preview' => ['Tencent Hy4 Preview', 1_024_000, 64_000, true, false, 0.834, 2.501, 0.042, 0.0],
        'thinkingmachines/inkling' => ['Inkling', 256_000, 256_000, true, true, 1.0, 4.05, 0.17, 0.0],
        'thinkingmachines/inkling-small' => ['Inkling Small', 1_000_000, 1_000_000, true, true, 0.45, 1.2, 0.1, 0.0],
        'xiaomi/mimo-v2.5' => ['MiMo M2.5', 1_050_000, 131_100, true, true, 0.14, 0.28, 0.0028, 0.0],
        'xiaomi/mimo-v2.5-pro' => ['MiMo V2.5 Pro', 1_050_000, 131_000, true, false, 0.435, 0.87, 0.0036, 0.0],
        'xiaomi/mimo-v2.6-flash' => ['MiMo V2.6 Flash', 1_048_576, 131_072, true, true, 0.04, 1.28, 0.04, 0.0],
        'xiaomi/mimo-v2.6-pro' => ['MiMo V2.6 Pro', 1_048_576, 131_072, true, true, 0.435, 0.87, 0.0036, 0.0],
        'xiaomi/mimo-v2.6-pro-ultraspeed' => ['MiMo V2.6 Pro UltraSpeed', 1_048_576, 131_072, true, true, 4.35, 8.7, 0.036, 0.0],
        'zai/glm-4.5' => ['GLM 4.5', 128_000, 96_000, true, false, 0.6, 2.2, 0.11, 0.0],
        'zai/glm-4.5-air' => ['GLM 4.5 Air', 128_000, 96_000, true, false, 0.2, 1.1, 0.03, 0.0],
        'zai/glm-4.5v' => ['GLM 4.5V', 66_000, 16_000, true, true, 0.6, 1.8, 0.11, 0.0],
        'zai/glm-4.6' => ['GLM 4.6', 200_000, 96_000, true, false, 0.6, 2.2, 0.11, 0.0],
        'zai/glm-4.7' => ['GLM 4.7', 200_000, 120_000, true, false, 0.6, 2.2, 0.0, 0.0],
        'zai/glm-4.7-flash' => ['GLM 4.7 Flash', 200_000, 131_000, true, false, 0.07, 0.4, 0.0, 0.0],
        'zai/glm-4.7-flashx' => ['GLM 4.7 FlashX', 200_000, 128_000, true, false, 0.06, 0.4, 0.01, 0.0],
        'zai/glm-5' => ['GLM 5', 202_800, 131_100, true, false, 1.0, 3.2, 0.0, 0.0],
        'zai/glm-5-turbo' => ['GLM 5 Turbo', 202_800, 131_100, true, false, 1.2, 4.0, 0.24, 0.0],
        'zai/glm-5.1' => ['GLM 5.1', 202_800, 64_000, true, false, 1.4, 4.4, 0.26, 0.0],
        'zai/glm-5.2' => ['GLM 5.2', 1_000_000, 128_000, true, false, 0.8, 2.55, 0.16, 0.0],
        'zai/glm-5.2-fast' => ['GLM 5.2 Fast', 1_000_000, 128_000, true, false, 2.8, 8.8, 0.56, 0.0],
        'zai/glm-5.3' => ['GLM 5.3', 1_000_000, 1_000_000, true, false, 1.4, 4.4, 0.14, 0.0],
        'zai/glm-5.3-fast' => ['GLM 5.3 Fast', 1_048_576, 262_144, true, false, 2.1, 6.6, 0.21, 0.0],
        'zai/glm-5.3-flash' => ['GLM 5.3 Flash', 1_000_000, 131_000, true, true, 0.15, 0.5, 0.03, 0.0],
        'zai/glm-5.3-flashx' => ['GLM 5.3 FlashX', 1_000_000, 131_072, true, true, 0.37, 1.25, 0.075, 0.0],
        'zai/glm-5v-turbo' => ['GLM 5V Turbo', 200_000, 128_000, true, true, 1.2, 4.0, 0.24, 0.0],
        // <<< generated
    ];

    /**
     * Xiaomi MiMo: "Built-in `xiaomi` targets the API billing endpoint (single stable URL, keys from
     * platform.xiaomimimo.com). The three `xiaomi-token-plan-*` providers cover prepaid Token Plan
     * endpoints in cn / ams / sgp." Deprecated models left out.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array XIAOMI_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'mimo-v2.5' => ['MiMo-V2.5', 1_048_576, 131_072, true, true, 0.14, 0.28, 0.0028, 0.0],
        'mimo-v2.5-pro' => ['MiMo-V2.5-Pro', 1_048_576, 131_072, true, false, 0.435, 0.87, 0.0036, 0.0],
        'mimo-v2.5-pro-ultraspeed' => ['MiMo-V2.5-Pro-UltraSpeed', 1_048_576, 131_072, true, false, 1.305, 2.61, 0.0108, 0.0],
        'mimo-v2.6-flash' => ['MiMo-V2.6-Flash', 1_048_576, 131_072, true, true, 0.14, 0.28, 0.0028, 0.0],
        'mimo-v2.6-pro' => ['MiMo-V2.6-Pro', 1_048_576, 131_072, true, true, 0.435, 0.87, 0.0036, 0.0],
        'mimo-v2.6-pro-ultraspeed' => ['MiMo-V2.6-Pro-UltraSpeed', 1_048_576, 131_072, true, true, 4.35, 8.7, 0.036, 0.0],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array XIAOMI_TOKEN_PLAN_AMS_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'mimo-v2.5' => ['MiMo-V2.5', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.5-pro' => ['MiMo-V2.5-Pro', 1_048_576, 131_072, true, false, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-flash' => ['MiMo-V2.6-Flash', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-pro' => ['MiMo-V2.6-Pro', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array XIAOMI_TOKEN_PLAN_CN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'mimo-v2.5' => ['MiMo-V2.5', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.5-pro' => ['MiMo-V2.5-Pro', 1_048_576, 131_072, true, false, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-flash' => ['MiMo-V2.6-Flash', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-pro' => ['MiMo-V2.6-Pro', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        // <<< generated
    ];

    /** @var array<string, array<int|string, mixed>> */
    private const array XIAOMI_TOKEN_PLAN_SGP_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'mimo-v2.5' => ['MiMo-V2.5', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.5-pro' => ['MiMo-V2.5-Pro', 1_048_576, 131_072, true, false, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-flash' => ['MiMo-V2.6-Flash', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        'mimo-v2.6-pro' => ['MiMo-V2.6-Pro', 1_048_576, 131_072, true, true, 0.0, 0.0, 0.0, 0.0],
        // <<< generated
    ];

    /**
     * Z.ai's China Coding Plan (`open.bigmodel.cn`): `processZaiModels()`' second variant, models.dev's
     * `zhipuai-coding-plan` priced from `zai`'s entry where it lists the model — the same processing as
     * `ZAI_MODELS`.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private const array ZAI_CODING_CN_MODELS = [
        // >>> generated from models.dev — rewritten by scripts/generate-models.php
        'glm-4.6v' => ['GLM-4.6V', 128_000, 32_768, true, true, 0.3, 0.9, 0.0, 0.0],
        'glm-5.3' => ['GLM-5.3', 1_000_000, 131_072, true, false, 1.4, 4.4, 0.26, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3-flash' => ['GLM-5.3-Flash', 1_000_000, 131_072, true, true, 0.15, 0.5, 0.03, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        'glm-5.3-highspeed' => ['GLM-5.3 Highspeed', 1_000_000, 131_072, true, false, 0.0, 0.0, 0.0, 0.0, 'thinkingLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'], 'effortLevelMap' => ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max']],
        // <<< generated
    ];

    /** @var array<string, Model>|null built once, on the first lookup that needs it */
    private static ?array $models = null;

    /** @var array<string, ClassifierModel>|null `CLASSIFIER_MODELS`, built once */
    private static ?array $classifiers = null;

    /** @var array<string, ImageModel>|null `IMAGE_MODELS`, built once */
    private static ?array $images = null;

    /**
     * @var list<Model> declared somewhere else and handed over — see `register()`
     */
    private static array $registered = [];

    /**
     * @var list<Model> handed over by something entitled to overwrite a built-in row — see the
     *      `$replace` argument on `register()`, which has exactly one caller
     */
    private static array $replacing = [];

    /**
     * Add models the table does not know about.
     *
     * For `models.json`: somebody's own endpoint, or a local server, declared in a file rather
     * than waiting for a release. `CodingAgent\CustomModels` is what reads that file; this end
     * knows nothing about files and only takes finished `Model` objects.
     *
     * **They go in at the end, so a built-in always wins a collision.** Both `find()` and the
     * table are keyed `provider/id`, so a file declaring `anthropic/claude-sonnet-4-5` would
     * otherwise quietly replace the real one — a config file being able to redefine a shipped
     * model is a bug report nobody could read. A custom provider with a name of its own
     * collides with nothing and is the normal case.
     *
     * Static because `Models` is: everything downstream — `--model`, `/model`, restoring a
     * session, `RpcMode` — asks this class by id, and a model that only some of them could see
     * would be a model that works until you save the conversation.
     *
     * **`$replace` is the one exception to that rule and it has one caller**: the Antigravity
     * extension's `Catalog`, reading the catalogue pi keeps refreshed on disk, over the fallback
     * table the same extension registers first. The rule above exists so a *person's* file cannot
     * redefine a shipped model; there both sides have one author — the deployment's own catalogue
     * endpoint — and the fallback is itself a frozen print of it, so the two differ only in when
     * they were taken. A snapshot beating a live reading of the same source is the wrong way
     * round. Nothing else may pass true, and a second caller is the moment to ask whether this is
     * still an exception or has become a rule.
     *
     * @param list<Model> $models
     */
    public static function register(array $models, bool $replace = false): void
    {
        if ($replace) {
            self::$replacing = [...self::$replacing, ...$models];
        } else {
            self::$registered = [...self::$registered, ...$models];
        }

        self::$models = null;
    }

    /** Forget what `register()` added. For tests, which must not leak models into each other. */
    public static function forgetRegistered(): void
    {
        self::$registered = [];
        self::$replacing = [];
        self::$models = null;
    }

    /** Forget one provider's registered models — `ProviderRegistry::unregister()`'s half. */
    public static function forgetProvider(string $provider): void
    {
        $keep = static fn (Model $model): bool => $model->provider !== $provider;
        self::$registered = array_values(array_filter(self::$registered, $keep));
        self::$replacing = array_values(array_filter(self::$replacing, $keep));
        self::$models = null;
    }

    /**
     * One model by id, or null when there is no such model.
     *
     * Ids were unique across the providers here until Copilot's table landed; it serves
     * `gpt-5` and `gemini-2.5-pro` under the same names their own providers use. **A bare id
     * means the direct provider**, and Copilot's is reached through `find()` or through
     * `github-copilot/gpt-5` on the command line. See `RESOLD`.
     */
    public static function get(string $id): ?Model
    {
        $resold = null;

        foreach (self::table() as $model) {
            if ($model->id !== $id) {
                continue;
            }

            if (!self::isResold($model->provider)) {
                return $model;
            }

            $resold ??= $model;
        }

        // Only when nobody sells it directly. `grok-code-fast-1` is Copilot's alone here,
        // because xAI's own table does not carry it — so a resold id is still an answer, it
        // is just never the first one.
        return $resold;
    }

    /**
     * Whether a provider answers with somebody else's models under their own ids.
     *
     * Public because `ModelResolver` needs the same rule and two copies of it would disagree
     * the first time one of them was edited.
     */
    public static function isResold(string $provider): bool
    {
        return in_array($provider, self::RESOLD, true) || Extension\ProviderRegistry::isResold($provider);
    }

    public static function find(string $provider, string $id): ?Model
    {
        return self::table()[$provider . '/' . $id] ?? null;
    }

    /** @return list<Model> every model, in the order the table lists them */
    public static function all(): array
    {
        return array_values(self::table());
    }

    /** @return list<string> the providers there are models for */
    public static function providers(): array
    {
        $providers = [];

        foreach (self::table() as $model) {
            if (!in_array($model->provider, $providers, true)) {
                $providers[] = $model->provider;
            }
        }

        return $providers;
    }

    /**
     * One model of a type, or null — upstream's `getModelOfType(type, provider, id)`. A chat id and a
     * classifier or image id may be the same string under the same provider; each type is its own
     * table, as upstream keeps them apart.
     */
    public static function findOfType(ModelType $type, string $provider, string $id): Model|ImageModel|ClassifierModel|null
    {
        return match ($type) {
            ModelType::Chat => self::find($provider, $id),
            ModelType::Image => self::imageTable()[$provider . '/' . $id] ?? null,
            ModelType::Classifier => self::classifierTable()[$provider . '/' . $id] ?? null,
        };
    }

    /**
     * Every model of a type — upstream's `getModelsOfType(type)`; `all()` is the chat ones.
     *
     * @return list<Model>|list<ImageModel>|list<ClassifierModel>
     */
    public static function allOfType(ModelType $type): array
    {
        return match ($type) {
            ModelType::Chat => self::all(),
            ModelType::Image => array_values(self::imageTable()),
            ModelType::Classifier => array_values(self::classifierTable()),
        };
    }

    /**
     * "Classify structured state through the owning provider. Never rejects." — upstream's
     * `Models.classify()`: the provider's auth applied (`applyAuth()`: the key the caller gave, else the
     * environment's; for Workers AI the account id into the env and the base URL, as
     * `cloudflareClassifier()` wraps it), then the classifier API the model names. Every failure,
     * an unconfigured provider included, is an error result.
     *
     * Dispatched by the model's API rather than by its provider's `classifiers` table, as `Stream`
     * dispatches a chat model: upstream's providers each register `typesafe-system-one`, or Workers
     * AI's own, under the API name, so the two pick the same implementation for every built-in row.
     * A llama.cpp model needs no key — upstream's llama extension resolves it keyless.
     */
    public static function classify(ClassifierModel $model, ClassifierContext $context, ?ClassifierOptions $options = null): ClassifierResult
    {
        try {
            [$requestModel, $requestOptions] = self::applyClassifierAuth($model, $options);

            return match ($model->api) {
                ClassifierApi::TypesafeSystemOne => (new Providers\TypesafeSystemOne())->classify($requestModel, $context, $requestOptions),
                ClassifierApi::CloudflareWorkersAiSystemOne => (new Providers\CloudflareWorkersAiSystemOne())->classify($requestModel, $context, $requestOptions),
                ClassifierApi::LlamaCppClassify => (new Providers\LlamaCppClassify())->classify($requestModel, $context, $requestOptions),
            };
        } catch (\Throwable $error) {
            return ClassifierResult::error($model, $error, $options?->signal?->aborted() ?? false);
        }
    }

    /**
     * "Generate images through the owning provider with auth resolved like `stream()`. Never rejects:
     * unknown providers, unconfigured auth, and providers without `generateImages` return an error
     * `AssistantImages`." — upstream's `Models.generateImages()`, dispatched by the model's API.
     */
    public static function generateImages(ImageModel $model, ImagesContext $context, ?ImagesOptions $options = null): AssistantImages
    {
        try {
            $apiKey = $options?->apiKey !== null && $options->apiKey !== ''
                ? $options->apiKey
                : Stream::envApiKey($model->provider, $options?->env);

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("Provider is not configured: {$model->provider}");
            }

            $requestOptions = new ImagesOptions(...[...($options ?? new ImagesOptions())->args(), 'apiKey' => $apiKey]);

            return match ($model->api) {
                ImageApi::OpenRouterImages => (new Providers\OpenRouterImages())->generateImages($model, $context, $requestOptions),
            };
        } catch (\Throwable $error) {
            return AssistantImages::error($model, $error, $options?->signal?->aborted() ?? false);
        }
    }

    /**
     * `applyAuth()` for a classifier: the key, and Workers AI's account id resolved into the env and
     * the base URL (`cloudflareWorkersAIAuth()` and `cloudflareClassifier()`). "Provider is not
     * configured" when nothing answers, as upstream's `ModelsError("auth")` says it.
     *
     * @return array{0: ClassifierModel, 1: ClassifierOptions}
     */
    private static function applyClassifierAuth(ClassifierModel $model, ?ClassifierOptions $options): array
    {
        $args = ($options ?? new ClassifierOptions())->args();
        $apiKey = $options?->apiKey !== null && $options->apiKey !== ''
            ? $options->apiKey
            : Stream::envApiKey($model->provider, $options?->env);

        if (Providers\Cloudflare::isCloudflare($model->provider)) {
            $resolved = Providers\Cloudflare::resolveCloudflareEnv($model->provider, $apiKey, $options?->env)
                ?? throw new ProviderError("Provider is not configured: {$model->provider}");
            $env = [...$resolved['env'], ...($options?->env ?? [])];
            $requestModel = Providers\Cloudflare::resolveCloudflareModel($model, $env);
            \assert($requestModel instanceof ClassifierModel);

            return [$requestModel, new ClassifierOptions(...[...$args, 'apiKey' => $resolved['apiKey'], 'env' => $env])];
        }

        if (($apiKey === null || $apiKey === '') && $model->api !== ClassifierApi::LlamaCppClassify) {
            throw new ProviderError("Provider is not configured: {$model->provider}");
        }

        return [$model, new ClassifierOptions(...[...$args, 'apiKey' => $apiKey])];
    }

    /** @return array<string, ClassifierModel> keyed by "provider/id" */
    private static function classifierTable(): array
    {
        if (self::$classifiers !== null) {
            return self::$classifiers;
        }

        $models = [];

        foreach (self::CLASSIFIER_MODELS as $key => [$name, $api, $baseUrl, $window, $input, $in, $out, $read, $write]) {
            [$provider, $id] = explode('/', $key, 2);
            $models[$key] = new ClassifierModel($id, $name, $api, $provider, $baseUrl, $window, $input, self::pricing($in, $out, $read, $write));
        }

        return self::$classifiers = $models;
    }

    /** @return array<string, ImageModel> keyed by "provider/id" */
    private static function imageTable(): array
    {
        if (self::$images !== null) {
            return self::$images;
        }

        $models = [];

        foreach (self::IMAGE_MODELS as $key => [$name, $api, $baseUrl, $input, $output, $in, $out, $read, $write]) {
            [$provider, $id] = explode('/', $key, 2);
            // `applyImageInputMetadata(model)`, which an image model gets with no context window.
            $models[$key] = new ImageModel($id, $name, $api, $provider, $baseUrl, $input, $output, self::pricing($in, $out, $read, $write), inputLimits: self::inputLimits($provider, $input, 0));
        }

        return self::$images = $models;
    }

    /**
     * What a provider's models cost to run this usage.
     *
     * Upstream's `calculateCost()` mutates the usage it is given; this returns the cost,
     * because a function that quietly rewrites its argument is the kind of thing that
     * makes a total wrong twice.
     */
    public static function cost(Model $model, Usage $usage): Cost
    {
        // One rule, `Usage::withCost()`'s — tiers and the one-hour write included — rather than a
        // second copy here that knew neither.
        return $usage->withCost($model)->cost;
    }

    /** @return array<string, Model> keyed by "provider/id", which is unique by construction */
    private static function table(): array
    {
        if (self::$models !== null) {
            return self::$models;
        }

        $models = [];

        foreach (self::ANTHROPIC_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $in, $out, $read, $write] = $row;
            // Upstream's generator: `supportsStrictTools: true` on every `anthropic` provider
            // model (`applyStrictToolCompatMetadata()`), `forceAdaptiveThinking` and
            // `supportsTemperature: false` by id, `supportsMidConvoEffort` by id — absent, not
            // false, where it writes nothing. See `AnthropicCompat::forBuiltIn()`.
            $compat = AnthropicCompat::forBuiltIn(self::ANTHROPIC, $id);
            $models[self::ANTHROPIC . '/' . $id] = new Model(
                $id,
                $name,
                Api::AnthropicMessages,
                self::ANTHROPIC,
                self::ANTHROPIC_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                ['text', 'image'],
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                compat: $compat,
                thinkingLevelMap: self::thinkingLevelMap(
                    self::ANTHROPIC,
                    Api::AnthropicMessages,
                    $id,
                    // The gate reads the compat as `applyModelsDevReasoningOptionMetadata()` finds it:
                    // the `anthropic` arm of `loadModelsDevData()` writes none, and
                    // `forceAdaptiveThinking` arrives only in `applyThinkingLevelMetadata()`, which
                    // runs after it. So models.dev's efforts never reach these rows.
                    effortLevelMap: self::supportsDirectReasoningEffort(Api::AnthropicMessages, self::ANTHROPIC, self::ANTHROPIC_BASE_URL, $id, null) ? ($row['effortLevelMap'] ?? null) : null,
                ),
                inputLimits: self::inputLimits(self::ANTHROPIC, ['text', 'image'], $window),
                promptCache: self::promptCache(self::ANTHROPIC, Api::AnthropicMessages),
            );
        }

        $models = self::withAllowedFallbackModels($models);

        foreach (self::OPENAI_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $models['openai/' . $id] = new Model(
                $id,
                $name,
                Api::OpenAiResponses,
                'openai',
                'https://api.openai.com/v1',
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                // Upstream's generator (`applyStrictToolCompatMetadata()`) gives every `openai`
                // provider model on the Responses API `supportsStrictMode: true`,
                // `applyOpenAIGrammarToolCompatMetadata()` gives `gpt-<n>` with n >= 5
                // `supportsOpenAIGrammarTools: true`, and `applyOpenAIExplicitPromptCacheMetadata()`
                // gives the ones that charge for cache writes (GPT-5.6 on) `supportsExplicitPromptCacheMode`.
                compat: new OpenAiCompat(
                    strictMode: true,
                    grammarTools: self::isGrammarToolModel($id) ? true : null,
                    supportsExplicitPromptCacheMode: $write > 0 ? true : null,
                    // `applyOpenAIToolSearchMetadata()` and `applyOpenAIResponsesTranscriptMetadata()`.
                    supportsMidConvoSystemMessages: in_array($id, self::OPENAI_TOOL_SEARCH_MODEL_IDS, true) ? true : null,
                    supportsToolSearch: in_array($id, self::OPENAI_TOOL_SEARCH_MODEL_IDS, true) ? true : null,
                    supportsAdditionalTools: in_array($id, self::OPENAI_TOOL_SEARCH_MODEL_IDS, true) ? true : null,
                ),
                // `supportsDirectReasoningEffort()` is true for every Responses model.
                thinkingLevelMap: self::thinkingLevelMap('openai', Api::OpenAiResponses, $id, effortLevelMap: $row['effortLevelMap'] ?? null),
                inputLimits: self::inputLimits('openai', $input, $window),
            );
        }

        foreach (self::GOOGLE_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];

            $models['google/' . $id] = new Model(
                $id,
                $name,
                Api::GoogleGenerativeAi,
                'google',
                'https://generativelanguage.googleapis.com/v1beta',
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                // `getGoogleThinkingLevelMap()` with the measured corrections over it, as the
                // generator writes it; see the table's docblock.
                thinkingLevelMap: self::thinkingLevelMap('google', Api::GoogleGenerativeAi, $id, $row['thinkingLevelMap'] ?? []),
                inputLimits: self::inputLimits('google', $input, $window),
            );
        }

        foreach (self::GOOGLE_VERTEX_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];

            $models[self::GOOGLE_VERTEX . '/' . $id] = new Model(
                $id,
                $name,
                Api::GoogleVertex,
                self::GOOGLE_VERTEX,
                self::VERTEX_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write),
                thinkingLevelMap: self::thinkingLevelMap(self::GOOGLE_VERTEX, Api::GoogleVertex, $id, $row['thinkingLevelMap'] ?? []),
                inputLimits: self::inputLimits(self::GOOGLE_VERTEX, $input, $window),
            );
        }

        // In the order the tables have always been listed in, which is the order `--list-models`
        // and `/model` show them: Mistral sits between Groq and xAI though it is no longer one of the
        // OpenAI-compatible ones.
        $compatible = [
            'cerebras' => self::CEREBRAS_MODELS,
            'groq' => self::GROQ_MODELS,
            'mistral' => self::MISTRAL_MODELS,
            'xai' => self::XAI_MODELS,
            'zai' => self::ZAI_MODELS,
        ];

        foreach ($compatible as $provider => $table) {
            if ($provider === 'mistral') {
                self::addMistralModels($models);

                continue;
            }

            if ($provider === 'xai') {
                self::addXaiModels($models);

                continue;
            }

            foreach ($table as $id => $row) {
                [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
                $input = $images ? ['text', 'image'] : ['text'];
                // Upstream's `processZaiModels()` compat for z.ai, nothing of their own for the other
                // two, and `applyOpenAICompletionsCompatMetadata()` under it for every one.
                $compat = self::completionsCompat(
                    $provider,
                    self::OPENAI_COMPATIBLE[$provider],
                    $id,
                    $provider === 'zai' ? self::zaiCompat($id, isset($row['thinkingLevelMap'])) : null,
                );
                $models[$provider . '/' . $id] = new Model(
                    $id,
                    $name,
                    Api::OpenAiCompletions,
                    $provider,
                    self::OPENAI_COMPATIBLE[$provider],
                    $window,
                    $maxTokens,
                    $reasoning,
                    $input,
                    self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                    compat: $compat,
                    thinkingLevelMap: self::thinkingLevelMap(
                        $provider,
                        Api::OpenAiCompletions,
                        $id,
                        $row['thinkingLevelMap'] ?? [],
                        effortLevelMap: self::supportsDirectReasoningEffort(Api::OpenAiCompletions, $provider, self::OPENAI_COMPATIBLE[$provider], $id, $compat) ? ($row['effortLevelMap'] ?? null) : null,
                    ),
                    inputLimits: self::inputLimits($provider, $input, $window),
                );
            }
        }

        foreach (self::AMAZON_BEDROCK_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];

            $models[self::AMAZON_BEDROCK . '/' . $id] = new Model(
                $id,
                $name,
                Api::BedrockConverseStream,
                self::AMAZON_BEDROCK,
                // Upstream's generator `getBedrockBaseUrl()`.
                str_starts_with($id, 'eu.') ? 'https://bedrock-runtime.eu-central-1.amazonaws.com' : 'https://bedrock-runtime.us-east-1.amazonaws.com',
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                compat: ($row['strictMode'] ?? false) === true ? new BedrockCompat(supportsStrictMode: true) : null,
                thinkingLevelMap: self::thinkingLevelMap(self::AMAZON_BEDROCK, Api::BedrockConverseStream, $id),
                inputLimits: self::inputLimits(self::AMAZON_BEDROCK, $input, $window),
            );
        }

        foreach (self::AZURE_MODELS as $id => $row) {
            [$name, $api, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $models[self::AZURE . '/' . $id] = new Model(
                $id,
                $name,
                $api,
                self::AZURE,
                '',
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write),
                compat: self::azureCompat($api, $id),
                thinkingLevelMap: self::thinkingLevelMap(self::AZURE, $api, $id),
                inputLimits: self::inputLimits(self::AZURE, $input, $window),
            );
        }

        foreach (self::OPENAI_CODEX_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $models[self::OPENAI_CODEX . '/' . $id] = new Model(
                $id,
                $name,
                Api::OpenAiCodexResponses,
                self::OPENAI_CODEX,
                self::OPENAI_CODEX_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                compat: self::codexCompat($id),
                thinkingLevelMap: self::thinkingLevelMap(self::OPENAI_CODEX, Api::OpenAiCodexResponses, $id),
                inputLimits: self::inputLimits(self::OPENAI_CODEX, $input, $window),
            );
        }

        foreach (self::RADIUS_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $models[self::RADIUS . '/' . $id] = new Model(
                $id,
                $name,
                Api::PiMessages,
                self::RADIUS,
                self::RADIUS_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                thinkingLevelMap: self::thinkingLevelMap(self::RADIUS, Api::PiMessages, $id, $row['thinkingLevelMap'] ?? []),
                inputLimits: self::inputLimits(self::RADIUS, $input, $window),
            );
        }

        self::addCatalogueModels($models);

        // Last, so the table reads direct providers first — which is not what decides a bare
        // id (`RESOLD` is), but does decide the order `--list-models` and `/model` list them in.
        foreach (self::COPILOT_MODELS as $id => $row) {
            [$name, $api, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $compat = match ($api) {
                Api::OpenAiCompletions => self::copilotCompat($id),
                // Upstream's generator for a Copilot Claude (`api: "anthropic-messages"`):
                // `forceAdaptiveThinking` and `supportsTemperature: false` by the same id rules
                // as on Anthropic's own models, `supportsEagerToolInputStreaming: false` on the
                // three it lists, `supportsMidConvoSystemMessages` (but not `…ToolChanges`, which
                // Copilot rejects) on the ids that take it — and no `supportsStrictTools` (that is
                // `provider === "anthropic"` only) or `supportsMidConvoEffort` (`anthropic`/
                // `openrouter` only).
                Api::AnthropicMessages => AnthropicCompat::forBuiltIn(self::COPILOT, $id),
                // `applyOpenAIGrammarToolCompatMetadata()`: Copilot passes OpenAI's custom
                // grammar tools through on the Responses API, for `gpt-<n>` with n >= 5; and
                // `applyOpenAIResponsesTranscriptMetadata()`.
                Api::OpenAiResponses => self::copilotResponsesCompat($id),
                default => null,
            };
            $models[self::COPILOT . '/' . $id] = new Model(
                $id,
                $name,
                $api,
                self::COPILOT,
                self::COPILOT_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                self::COPILOT_HEADERS,
                $compat,
                thinkingLevelMap: self::thinkingLevelMap(
                    self::COPILOT,
                    $api,
                    $id,
                    $row['thinkingLevelMap'] ?? [],
                    // The compat as the gate finds it: a Claude row's is `getAnthropicMessagesCompat()`
                    // alone (no `forceAdaptiveThinking` yet, see the Anthropic rows), the others' is
                    // what `loadModelsDevData()` wrote, which is what they end with.
                    effortLevelMap: self::supportsDirectReasoningEffort($api, self::COPILOT, self::COPILOT_BASE_URL, $id, $api === Api::AnthropicMessages ? null : $compat) ? ($row['effortLevelMap'] ?? null) : null,
                ),
                inputLimits: self::inputLimits(self::COPILOT, $input, $window),
            );
        }

        // Last, and only where nothing is already: see `register()`. A built-in wins.
        foreach (self::$registered as $model) {
            $models[$model->provider . '/' . $model->id] ??= $model;
        }

        // And after even that, the one kind that is allowed to overwrite a row already there — see
        // the `$replace` argument on `register()` for why Antigravity's catalogue is it.
        foreach (self::$replacing as $model) {
            $models[$model->provider . '/' . $model->id] = $model;
        }

        return self::$models = $models;
    }

    /**
     * xAI's own, on the Responses API — upstream's `xaiProvider()` serves `openAIResponsesApi()` and
     * its generator writes `api: "openai-responses"`, `baseUrl: "https://api.x.ai/v1"` and
     * `XAI_RESPONSES_COMPAT` on every row. models.dev's efforts apply (`supportsDirectReasoningEffort()`
     * is true for every Responses model), and a row without them gets `{off: null, minimal: null}`
     * from `applyThinkingLevelMetadata()` ("xAI models without verified effort options must not send
     * the undocumented "none"/"minimal" efforts"). pig used to send these to Chat Completions.
     *
     * @param array<string, Model> $models
     */
    private static function addXaiModels(array &$models): void
    {
        foreach (self::XAI_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $models['xai/' . $id] = new Model(
                $id,
                $name,
                Api::OpenAiResponses,
                'xai',
                self::XAI_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                compat: self::responsesCompat('xai', $id, new OpenAiCompat(...self::XAI_RESPONSES_COMPAT)),
                thinkingLevelMap: self::thinkingLevelMap('xai', Api::OpenAiResponses, $id, effortLevelMap: $row['effortLevelMap'] ?? null),
                inputLimits: self::inputLimits('xai', $input, $window),
            );
        }
    }

    /**
     * Mistral's own API. Upstream writes no `compat` on these (`MistralConversationsCompat` has only
     * `supportsMidConvoSystemMessages`, which no built-in sets) and records no `reasoning_options`,
     * so the row's own map is the whole of it.
     *
     * @param array<string, Model> $models
     */
    private static function addMistralModels(array &$models): void
    {
        foreach (self::MISTRAL_MODELS as $id => $row) {
            [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
            $input = $images ? ['text', 'image'] : ['text'];
            $models['mistral/' . $id] = new Model(
                $id,
                $name,
                Api::MistralConversations,
                'mistral',
                self::MISTRAL_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $input,
                self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
                thinkingLevelMap: self::thinkingLevelMap('mistral', Api::MistralConversations, $id, $row['thinkingLevelMap'] ?? []),
                inputLimits: self::inputLimits('mistral', $input, $window),
            );
        }
    }

    /**
     * `CATALOGUE_PROVIDERS`' tables, each row through the metadata passes upstream's generator runs on
     * every model (`generateModels()`' last loop), in its order: the provider's own compat
     * (`catalogueCompat()`, what the generator's processing of that provider wrote), then
     * `applyOpenAICompletionsCompatMetadata()` (`completionsCompat()`), the Anthropic compat arm and
     * `applyThinkingLevelMetadata()`'s two compat keys (`AnthropicCompat::forBuiltIn()`),
     * `applyModelsDevReasoningOptionMetadata()` gated by `supportsDirectReasoningEffort()` — on the
     * compat the model had *before* the thinking metadata, which is when upstream asks, so a Claude that
     * thinks adaptively only by its id takes no efforts from models.dev — the level-map rules
     * (`thinkingLevelMap()`), the grammar-tool and transcript passes, and `applyImageInputMetadata()`.
     *
     * @param array<string, Model> $models
     */
    private static function addCatalogueModels(array &$models): void
    {
        foreach (self::CATALOGUE_PROVIDERS as $provider => [$table]) {
            foreach ($table as $id => $row) {
                $models[$provider . '/' . $id] = self::catalogueModel($provider, $id, $row);
            }
        }
    }

    /**
     * One row of `CATALOGUE_PROVIDERS` as the model upstream's generator makes of it — see
     * `addCatalogueModels()`.
     *
     * @param array<int|string, mixed> $row
     */
    private static function catalogueModel(string $provider, string $id, array $row): Model
    {
        $baseUrls = self::CATALOGUE_PROVIDERS[$provider][1];
        $cells = array_values(array_filter($row, is_int(...), ARRAY_FILTER_USE_KEY));
        $api = $cells[1] instanceof Api ? $cells[1] : Api::from((string) array_key_first($baseUrls));

        if ($cells[1] instanceof Api) {
            array_splice($cells, 1, 1);
        }

        [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $cells;
        $baseUrl = $baseUrls[$api->value];
        $input = $images ? ['text', 'image'] : ['text'];
        $own = self::catalogueCompat($provider, $api, $id, $reasoning, $row);
        $compat = match ($api) {
            Api::OpenAiCompletions => self::completionsCompat($provider, $baseUrl, $id, $own instanceof OpenAiCompat ? $own : null),
            Api::AnthropicMessages => AnthropicCompat::forBuiltIn($provider, $id, $own instanceof AnthropicCompat ? $own : null),
            Api::OpenAiResponses => self::responsesCompat($provider, $id, $own instanceof OpenAiCompat ? $own : null),
            Api::GoogleGenerativeAi => null,
        };
        $direct = self::supportsDirectReasoningEffort($api, $provider, $baseUrl, $id, $api === Api::AnthropicMessages ? $own : $compat);

        return new Model(
            $id,
            $name,
            $api,
            $provider,
            $baseUrl,
            $window,
            $maxTokens,
            $reasoning,
            $input,
            self::pricing($in, $out, $read, $write, $row['tiers'] ?? []),
            $provider === 'nvidia' ? self::NVIDIA_HEADERS : [],
            $compat,
            thinkingLevelMap: self::thinkingLevelMap(
                $provider,
                $api,
                $id,
                $row['thinkingLevelMap'] ?? [],
                effortLevelMap: $direct ? ($row['effortLevelMap'] ?? null) : null,
                reasoning: $reasoning,
                supportsToggle: ($row['supportsToggle'] ?? false) === true,
                forceAdaptiveThinking: $compat instanceof AnthropicCompat && $compat->forceAdaptiveThinking === true,
            ),
            inputLimits: self::inputLimits($provider, $input, $window),
        );
    }

    /**
     * What upstream's generator writes into a model's `compat` before its metadata passes: the
     * provider's own processing (`processBasetenModels()`, `processFireworksModels()`, the OpenCode,
     * Moonshot, Kimi, Xiaomi and Token Plan arms of `loadModelsDevData()`, `fetchAiGatewayModels()`,
     * the hand-written DeepSeek and Ant Ling rows), then `generateModels()`' two compat loops — the
     * temporary override for OpenRouter's Kimi K2.6 and the DeepSeek V4 one: "a Chat Completions
     * `deepseek-v4…` outside the Token Plans gets DeepSeek's compat, OpenRouter and OpenCode only its
     * `requiresReasoningContentOnAssistantMessages`" (`preservesNativeReasoningEffort`).
     *
     * @param array<int|string, mixed> $row
     */
    private static function catalogueCompat(string $provider, Api $api, string $id, bool $reasoning, array $row): OpenAiCompat|AnthropicCompat|null
    {
        $own = match ($provider) {
            // `antLingCompat`, and the Ring row's `thinkingFormat: "ant-ling"` — the one row of the
            // three that reasons.
            'ant-ling' => new OpenAiCompat(
                store: false,
                developerRole: false,
                reasoningEffort: false,
                maxTokensField: 'max_tokens',
                thinkingFormat: $reasoning ? 'ant-ling' : null,
                supportsLongCacheRetention: false,
            ),
            'baseten' => self::basetenCompat($id, $row),
            // The Workers AI arm of `loadModelsDevData()` writes `{sendSessionAffinityHeaders: true}` on
            // every row; the gateway's on its `anthropic/…` and `workers-ai/…` rows — "Gateway
            // passthroughs forward session affinity headers to upstreams that use them for
            // cache/routing affinity" — and nothing on its OpenAI ones.
            'cloudflare-workers-ai' => new OpenAiCompat(sendSessionAffinityHeaders: true),
            'cloudflare-ai-gateway' => match ($api) {
                Api::AnthropicMessages => new AnthropicCompat(sendSessionAffinityHeaders: true),
                Api::OpenAiCompletions => new OpenAiCompat(sendSessionAffinityHeaders: true),
                default => null,
            },
            // `deepseekCompat`.
            'deepseek' => new OpenAiCompat(reasoningContentOnAssistantMessages: true, thinkingFormat: 'deepseek'),
            'fireworks' => self::fireworksCompat($api, $id, $row),
            'huggingface' => new OpenAiCompat(developerRole: false),
            'kimi-coding' => new AnthropicCompat(
                forceAdaptiveThinking: true,
                allowEmptySignature: $id === 'k3' || $id === 'kimi-for-coding' ? true : null,
            ),
            'meta', 'minimax', 'minimax-cn' => null,
            // `moonshotCompat`, and Kimi K3's: `requiresReasoningContentOnAssistantMessages`, the
            // `openai` format, `supportsReasoningEffort`.
            'moonshotai', 'moonshotai-cn' => new OpenAiCompat(
                store: false,
                developerRole: false,
                reasoningEffort: $id === 'kimi-k3',
                maxTokensField: 'max_tokens',
                reasoningContentOnAssistantMessages: $id === 'kimi-k3' ? true : null,
                strictMode: false,
                thinkingFormat: $id === 'kimi-k3' ? 'openai' : 'deepseek',
            ),
            // `NVIDIA_OPENAI_COMPAT`.
            'nvidia' => new OpenAiCompat(
                store: false,
                developerRole: false,
                reasoningEffort: false,
                maxTokensField: 'max_tokens',
                strictMode: false,
                supportsLongCacheRetention: false,
            ),
            'opencode', 'opencode-go' => self::opencodeCompat($provider, $api, $id, $row),
            // "Keep selected OpenRouter model metadata stable until upstream settles."
            'openrouter' => str_starts_with($id, 'moonshotai/kimi-k2.6')
                ? new OpenAiCompat(developerRole: false, reasoningContentOnAssistantMessages: true)
                : null,
            // `qwenTokenPlanCompat`, without `supportsReasoningEffort` for a model with no level map.
            'qwen-token-plan', 'qwen-token-plan-cn', 'qwen-token-plan-individual' => new OpenAiCompat(
                store: false,
                developerRole: false,
                reasoningEffort: isset($row['thinkingLevelMap']),
                thinkingFormat: 'qwen',
            ),
            'together' => self::togetherCompat($id, $reasoning),
            'vercel-ai-gateway' => new AnthropicCompat(allowEmptySignature: true),
            // `xiaomiCompat`.
            'xiaomi', 'xiaomi-token-plan-ams', 'xiaomi-token-plan-cn', 'xiaomi-token-plan-sgp' => new OpenAiCompat(
                reasoningContentOnAssistantMessages: true,
                thinkingFormat: 'deepseek',
            ),
            'zai-coding-cn' => self::zaiCompat($id, isset($row['thinkingLevelMap'])),
        };

        if ($api === Api::OpenAiCompletions && str_contains($id, 'deepseek-v4') && !in_array($provider, self::QWEN_TOKEN_PLAN_PROVIDER_IDS, true)) {
            $own = self::overlaid(
                $own instanceof OpenAiCompat ? $own : new OpenAiCompat(),
                $provider === 'openrouter' || $provider === 'opencode'
                    ? new OpenAiCompat(reasoningContentOnAssistantMessages: true)
                    : new OpenAiCompat(reasoningContentOnAssistantMessages: true, thinkingFormat: 'deepseek'),
            );
        }

        return $own;
    }

    /**
     * Upstream's generator `getTogetherCompat()`: `TOGETHER_BASE_COMPAT` for a model that does not
     * reason or reasons always, `openai` effort for gpt-oss, Together's toggle plus effort for DeepSeek
     * V4 Pro, Together's toggle for the rest.
     */
    private static function togetherCompat(string $id, bool $reasoning): OpenAiCompat
    {
        $effort = $reasoning && in_array($id, self::TOGETHER_REASONING_EFFORT_MODELS, true);
        $toggleEffort = $reasoning && in_array($id, self::TOGETHER_TOGGLE_REASONING_EFFORT_MODELS, true);
        $toggle = $reasoning && !$effort && !in_array($id, self::TOGETHER_REASONING_ONLY_MODELS, true);

        return new OpenAiCompat(
            store: false,
            developerRole: false,
            reasoningEffort: $effort || $toggleEffort,
            maxTokensField: 'max_tokens',
            strictMode: false,
            thinkingFormat: $effort ? 'openai' : ($toggle ? 'together' : null),
            supportsLongCacheRetention: false,
        );
    }

    /**
     * `processBasetenModels()`' four compats, by whether models.dev lists a toggle and an effort for the
     * model — both for the two GLM-5.2 endpoints whatever it lists. "Baseten automatic prompt caching
     * needs session affinity so related requests land on the same replica."
     *
     * @param array<int|string, mixed> $row
     */
    private static function basetenCompat(string $id, array $row): OpenAiCompat
    {
        $isGlm52 = $id === 'zai-org/GLM-5.2' || $id === 'zai-org/GLM-5.2-Fast';
        $toggle = ($row['supportsToggle'] ?? false) === true || $isGlm52;
        $effort = ($row['supportsEffort'] ?? false) === true || $isGlm52;

        return new OpenAiCompat(
            store: false,
            developerRole: false,
            reasoningEffort: $effort,
            maxTokensField: 'max_tokens',
            strictMode: true,
            thinkingFormat: $toggle ? 'baseten' : ($effort ? 'openai' : null),
            chatTemplateArgs: $toggle ? ['enable_thinking' => ['$var' => 'thinking.enabled']] : null,
            supportsLongCacheRetention: false,
            sendSessionAffinityHeaders: true,
            supportsUsageInStreaming: true,
        );
    }

    /**
     * `processFireworksModels()`' compats: on the Messages API "Fireworks prompt caching uses automatic
     * prefix matching + session affinity … cache_control on tools and eager_input_streaming are not
     * supported", with adaptive thinking where models.dev lists an effort or the fallback list names
     * the model; on Chat Completions the GLM compat, and
     * Kimi K3's with `requiresReasoningContentOnAssistantMessages` and the `openai` format.
     *
     * @param array<int|string, mixed> $row
     */
    private static function fireworksCompat(Api $api, string $id, array $row): OpenAiCompat|AnthropicCompat
    {
        if ($api === Api::AnthropicMessages) {
            return new AnthropicCompat(
                // "Use adaptive thinking for cataloged effort controls, with verified fallbacks where
                // models.dev is incomplete. New models need no allowlist entry."
                forceAdaptiveThinking: ($row['supportsEffort'] ?? false) === true || in_array($id, self::FIREWORKS_ADAPTIVE_THINKING_FALLBACK_MODELS, true) ? true : null,
                supportsEagerToolInputStreaming: false,
                supportsLongCacheRetention: false,
                sendSessionAffinityHeaders: true,
                supportsCacheControlOnTools: false,
                allowEmptySignature: true,
            );
        }

        $kimiK3 = str_contains($id, 'kimi-k3');

        return new OpenAiCompat(
            store: false,
            developerRole: false,
            reasoningContentOnAssistantMessages: $kimiK3 ? true : null,
            thinkingFormat: $kimiK3 ? 'openai' : null,
            supportsLongCacheRetention: false,
            sendSessionAffinityHeaders: true,
        );
    }

    /**
     * The OpenCode arm of `loadModelsDevData()`: `openai-nosession` session affinity on the Responses
     * API, Anthropic's cache control for an `@ai-sdk/alibaba` model, OpenCode Zen's Grok Build without
     * `supportsReasoningEffort`, Kimi K2.6's DeepSeek-style thinking ("accepts Anthropic-style thinking
     * objects and rejects string thinking values or combined reasoning_effort"), OpenCode Go's Qwen
     * 3.5/3.6 Plus with `enable_thinking` ("Qwen/DashScope uses enable_thinking at the top level"), and on
     * Chat Completions `max_tokens` and the long-retention list.
     *
     * @param array<int|string, mixed> $row
     */
    private static function opencodeCompat(string $provider, Api $api, string $id, array $row): ?OpenAiCompat
    {
        if ($api === Api::AnthropicMessages || $api === Api::GoogleGenerativeAi) {
            return null;
        }

        $kimiK26 = $id === 'kimi-k2.6';
        $qwenPlus = $provider === 'opencode-go' && ($id === 'qwen3.5-plus' || $id === 'qwen3.6-plus');
        $completions = $api === Api::OpenAiCompletions;

        return new OpenAiCompat(
            reasoningEffort: ($provider === 'opencode' && $id === 'grok-build-0.1') || $kimiK26 ? false : null,
            maxTokensField: $completions ? 'max_tokens' : null,
            thinkingFormat: $kimiK26 ? 'deepseek' : ($qwenPlus ? 'qwen' : null),
            sessionAffinityFormat: $api === Api::OpenAiResponses ? 'openai-nosession' : null,
            supportsLongCacheRetention: $completions && in_array("{$provider}:{$id}", self::OPENCODE_OPENAI_COMPLETIONS_LONG_CACHE_RETENTION_UNSUPPORTED_MODELS, true) ? false : null,
            cacheControlFormat: is_string($row['cacheControlFormat'] ?? null) ? $row['cacheControlFormat'] : null,
        );
    }

    /**
     * A built-in Chat Completions model's compat: upstream's generator `applyOpenAICompletionsCompatMetadata()`
     * — what `detectedCompletionsCompat()` finds that differs from the runtime defaults, with the
     * model's own compat laid over it — then `applyOpenAICompletionsTranscriptMetadata()`: "Moonshot
     * Kimi K2.6/K2.7 accept system text after the conversation starts but reject tool-bearing system
     * messages. Kimi K3 accepts both forms; Fireworks and OpenCode pass its tool-bearing form through.
     * … DeepSeek V4 Pro and OpenAI models behind OpenRouter also accept plain system text in place."
     * Null when nothing is left, as upstream deletes an empty `compat`.
     */
    private static function completionsCompat(string $provider, string $baseUrl, string $id, ?OpenAiCompat $own): ?OpenAiCompat
    {
        $compat = self::overlaid(self::detectedCompletionsCompat($provider, $baseUrl, $id), $own);

        $kimiK3 = (str_starts_with($provider, 'moonshot') && $id === 'kimi-k3')
            || ($provider === 'fireworks' && str_contains($id, 'kimi-k3'))
            || (($provider === 'opencode' || $provider === 'opencode-go') && $id === 'kimi-k3');
        $textOnly = (str_starts_with($provider, 'moonshot') && in_array($id, ['kimi-k2.6', 'kimi-k2.7-code', 'kimi-k2.7-code-highspeed'], true))
            || ($provider === 'deepseek' && $id === 'deepseek-v4-pro')
            || ($provider === 'openrouter' && str_starts_with($id, 'openai/') && in_array(substr($id, strlen('openai/')), self::OPENAI_TOOL_SEARCH_MODEL_IDS, true));

        if ($kimiK3 || $textOnly) {
            $compat = self::overlaid($compat, new OpenAiCompat(
                supportsMidConvoSystemMessages: true,
                supportsMidConvoToolAdditions: $kimiK3 ? true : null,
            ));
        }

        return array_filter(get_object_vars($compat), static fn (mixed $value): bool => $value !== null) === [] ? null : $compat;
    }

    /**
     * Upstream's generator `detectOpenAICompletionsCompat()` through `openAICompletionsCompatDelta()`:
     * the keys whose detected value is not `OPENAI_COMPLETIONS_DEFAULT_COMPAT`'s, every other key null.
     *
     * Not `OpenAiCompat::detect()`, which is the runtime `detectCompat()`: the generator's copy differs
     * in three places, all copied — `supportsStrictMode` is "built-in behavior as explicit metadata
     * against the conservative runtime default" (true but for Moonshot, Together, Cloudflare's gateway,
     * NVIDIA and Cerebras), a Together model that reasons always (`TOGETHER_REASONING_ONLY_MODELS`) is
     * not given Together's thinking format, and OpenRouter's `~anthropic/` aliases get Anthropic's cache
     * control too (`/^~?anthropic\//`). Its `sessionAffinityFormat` is not detected at all.
     */
    private static function detectedCompletionsCompat(string $provider, string $baseUrl, string $id): OpenAiCompat
    {
        $isZai = $provider === 'zai' || $provider === 'zai-coding-cn' || str_contains($baseUrl, 'api.z.ai') || str_contains($baseUrl, 'open.bigmodel.cn');
        $isTogether = $provider === 'together' || str_contains($baseUrl, 'api.together.ai') || str_contains($baseUrl, 'api.together.xyz');
        $isMoonshot = $provider === 'moonshotai' || $provider === 'moonshotai-cn' || str_contains($baseUrl, 'api.moonshot.');
        $isOpenRouter = $provider === 'openrouter' || str_contains($baseUrl, 'openrouter.ai');
        $isCloudflareWorkersAi = $provider === 'cloudflare-workers-ai' || str_contains($baseUrl, 'api.cloudflare.com');
        $isCloudflareAiGateway = $provider === 'cloudflare-ai-gateway' || str_contains($baseUrl, 'gateway.ai.cloudflare.com');
        $isNvidia = $provider === 'nvidia' || str_contains($baseUrl, 'integrate.api.nvidia.com');
        $isAntLing = $provider === 'ant-ling' || str_contains($baseUrl, 'api.ant-ling.com');
        $isCerebras = $provider === 'cerebras' || str_contains($baseUrl, 'cerebras.ai');
        $isTogetherReasoningOnly = $isTogether && in_array($id, self::TOGETHER_REASONING_ONLY_MODELS, true);
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
        $useMaxTokens = str_contains($baseUrl, 'chutes.ai')
            || $isDeepSeek
            || $isMoonshot
            || $isCloudflareAiGateway
            || $isTogether
            || $isNvidia
            || $isAntLing
            || $isZai;
        $isGrok = $provider === 'xai' || str_contains($baseUrl, 'api.x.ai');
        $isOpenRouterDeveloperRoleModel = $isOpenRouter && (str_starts_with($id, 'anthropic/') || str_starts_with($id, 'openai/'));
        $thinkingFormat = match (true) {
            $isDeepSeek => 'deepseek',
            $isZai => 'zai',
            $isTogether && !$isTogetherReasoningOnly => 'together',
            $isAntLing => 'ant-ling',
            $isOpenRouter => 'openrouter',
            default => 'openai',
        };
        $supportsReasoningEffort = !$isGrok && !$isZai && !$isMoonshot && !$isTogether && !$isCloudflareAiGateway && !$isNvidia && !$isAntLing;
        $supportsLongCacheRetention = !($isTogether || $isCloudflareWorkersAi || $isCloudflareAiGateway || $isNvidia || $isAntLing);
        $supportsDeveloperRole = $isOpenRouterDeveloperRoleModel || (!$isNonStandard && !$isOpenRouter);

        // Each key only where it is not `OPENAI_COMPLETIONS_DEFAULT_COMPAT`'s value.
        return new OpenAiCompat(
            store: $isNonStandard ? false : null,
            developerRole: $supportsDeveloperRole ? null : false,
            reasoningEffort: $supportsReasoningEffort ? null : false,
            maxTokensField: $useMaxTokens ? 'max_tokens' : null,
            reasoningContentOnAssistantMessages: $isDeepSeek ? true : null,
            strictMode: !$isMoonshot && !$isTogether && !$isCloudflareAiGateway && !$isNvidia && !$isCerebras ? true : null,
            thinkingFormat: $thinkingFormat === 'openai' ? null : $thinkingFormat,
            supportsLongCacheRetention: $supportsLongCacheRetention ? null : false,
            sendSessionAffinityHeaders: $isOpenRouter ? true : null,
            cacheControlFormat: $provider === 'openrouter' && preg_match('#^~?anthropic/#', $id) === 1 ? 'anthropic' : null,
        );
    }

    /**
     * A built-in Responses model's compat beyond `openai`'s own: `applyStrictToolCompatMetadata()` for
     * Cloudflare's gateway, `applyOpenAIGrammarToolCompatMetadata()` (OpenCode and the gateway are among
     * `OPENAI_GRAMMAR_TOOL_PROVIDERS`), and `applyOpenAIResponsesTranscriptMetadata()`
     * for the proxies — "OpenCode Zen, OpenCode Go, and GitHub Copilot pass both those messages and
     * `additional_tools` items through to OpenAI unchanged".
     */
    private static function responsesCompat(string $provider, string $id, ?OpenAiCompat $own): ?OpenAiCompat
    {
        $compat = $own ?? new OpenAiCompat();

        // `applyStrictToolCompatMetadata()`: `supportsStrictMode` on the gateway's Responses rows as on
        // `openai`'s ("Cloudflare AI Gateway" is a documented passthrough of OpenAI's own).
        if ($provider === 'cloudflare-ai-gateway') {
            $compat = self::overlaid($compat, new OpenAiCompat(strictMode: true));
        }

        if (($provider === 'opencode' || $provider === 'cloudflare-ai-gateway') && self::isGrammarToolModel($id)) {
            $compat = self::overlaid($compat, new OpenAiCompat(grammarTools: true));
        }

        if (($provider === 'opencode' || $provider === 'opencode-go') && in_array($id, self::OPENAI_TOOL_SEARCH_MODEL_IDS, true)) {
            $compat = self::overlaid($compat, new OpenAiCompat(supportsMidConvoSystemMessages: true, supportsAdditionalTools: true));
        }

        return array_filter(get_object_vars($compat), static fn (mixed $value): bool => $value !== null) === [] ? null : $compat;
    }

    /** `{...under, ...over}`: every key `$over` says, over `$under`'s. */
    private static function overlaid(OpenAiCompat $under, ?OpenAiCompat $over): OpenAiCompat
    {
        if ($over === null) {
            return $under;
        }

        return new OpenAiCompat(...[
            ...get_object_vars($under),
            ...array_filter(get_object_vars($over), static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * What upstream's generator writes into a built-in model's `thinkingLevelMap`, merge for merge
     * and in its order (a later merge wins a key), over `$base` — the map a generated row already
     * carries (the `thinkingLevelMap` the generator builds for Google, Mistral and z.ai rows, with any
     * measured correction merged in, and a hand-added row's own):
     *
     * 1. the temporary 5.5 override in `generateModels()` — `anthropic/claude-opus-5-5`,
     *    `claude-sonnet-5-5`, `claude-haiku-5-5` and Copilot's `claude-opus-5.5` get the whole map
     *    `{off: null, minimal: null, low, medium, high, xhigh, max}`;
     * 2. `applyAnthropicMessagesCompatMetadata()` — `{off: null}` on a `supportsMidConvoEffort` model;
     * 3. `applyThinkingLevelMetadata()`, every arm that can match a provider pig has:
     *    - a Responses or Azure Responses `gpt-5…` gets `{off: null}`; GPT-6 Astra/Sol/Luna and 6.1 Sol
     *      on the Responses, Azure Responses or Codex API get `{off: "none" | null, minimal: null, low,
     *      medium, high, xhigh, max}` (Astra and 6.1 Sol reject `none`, so `off: null`);
     *    - a Copilot `gpt-5…` gets `{minimal: "low"}`;
     *    - `openai`'s Responses models in `OPENAI_RESPONSES_NONE_REASONING_MODELS` get `{off: "none"}`;
     *    - an xAI Responses model with no map yet gets `{off: null, minimal: null}`;
     *    - `supportsOpenAiXhigh()` ids — gpt-5.2/5.3/5.4/5.5/5.6, gpt-6 — get `{xhigh}` whatever their
     *      API, and `supportsOpenAiMax()` ones — gpt-5.6 and gpt-6 on one of the four OpenAI APIs —
     *      `{max}`;
     *    - `openai/gpt-5.5` gets `{minimal: null}`, a `…gpt-5.5-pro` `{off, minimal, low: null}`;
     *    - the Anthropic arms — `{max}` on Opus/Sonnet 4.6, `{xhigh, max}` on Opus 4.7/4.8/5, Sonnet 5
     *      and Haiku 5, `{off: null, xhigh, max}` on Fable 5;
     *    - a Chat Completions `deepseek-v4…` with no map yet gets DeepSeek's V4 map — Azure's
     *      (`AZURE_DEEPSEEK_V4_THINKING_LEVEL_MAP`), OpenRouter's, Flash's, or the default;
     *    - `groq/qwen/qwen3.6-27b` gets `{minimal, low, medium: null, high: "default"}`;
     *    - an `openai-codex` model `supportsOpenAiXhigh()` names gets `{minimal: "low"}`;
     *    - Moonshot's Kimi K2.7 Code gets `{off: null}` ("always-thinking");
     *    - OpenRouter's Mercury 2 gets `{off: null}` ("instant mode … disables tool calling") and its
     *      GLM-5.2 `{xhigh}`;
     *    - the Fireworks arm: on the Messages API with adaptive thinking (`$forceAdaptiveThinking`, the
     *      compat as the arm finds it), Qwen Max's fallback map when it has none, `{off: "none"}` where
     *      models.dev lists a toggle (`$supportsToggle`) or for the 2.4T alias, `{low}` for DeepSeek V4
     *      Pro; on either API GLM 5.2's `{off: "none", minimal, low, medium: null, max}` and Kimi K3's
     *      `{medium: null}`;
     *    - OpenCode Go's GLM-5.2 (`OPENCODE_GO_GLM52_THINKING_LEVEL_MAP`) and Kimi K2.6 (on/off only),
     *      OpenCode Zen's Grok Build (no explicit effort below high);
     *    - an Ant Ling model that reasons (`$reasoning`) gets `ANT_LING_RING_THINKING_LEVEL_MAP`;
     *    - and last `GITHUB_COPILOT_THINKING_LEVEL_OVERRIDES` for a Copilot id.
     *
     * A string value is what is sent for that level, null means the model does not have the level,
     * and a level the map leaves out is offered under its own name — except `xhigh` and `max`,
     * which are offered only when the map names them (`Model::supportedThinkingLevels()`). pig's
     * agent has no `max` level, so those entries are carried as upstream writes them and nothing
     * offers them.
     *
     * Between 2 and 3, `applyModelsDevReasoningOptionMetadata()`: `$effortLevelMap`, the map the
     * generator made of models.dev's `reasoning_options` (`getEffortThinkingLevelMap()`, written as
     * the row's `effortLevelMap`), merged when the caller found `supportsDirectReasoningEffort()` true
     * and null otherwise. The tables carry none until the next regeneration writes them.
     *
     * @param array<string, string|null> $base
     * @param array<string, string|null>|null $effortLevelMap
     * @return array<string, string|null>
     */
    private static function thinkingLevelMap(
        string $provider,
        Api $api,
        string $id,
        array $base = [],
        ?array $effortLevelMap = null,
        bool $reasoning = false,
        bool $supportsToggle = false,
        bool $forceAdaptiveThinking = false,
    ): array {
        $map = $base;
        $responses = $api === Api::OpenAiResponses;
        $azure = $api === Api::AzureOpenAiResponses;
        $codex = $api === Api::OpenAiCodexResponses;

        // 1. `// models.dev may list Opus 5.5, Sonnet 5.5, and Haiku 5.5 before their effort metadata is complete.`
        if (($provider === self::ANTHROPIC && in_array($id, ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], true))
            || ($provider === self::COPILOT && $id === 'claude-opus-5.5')) {
            $map = [...$map, 'off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'];
        }

        // 2.
        if ($api === Api::AnthropicMessages && AnthropicCompat::forBuiltIn($provider, $id)?->supportsMidConvoEffort === true) {
            $map = [...$map, 'off' => null];
        }

        // `applyModelsDevReasoningOptionMetadata()`: `if (thinkingLevelMap) mergeThinkingLevelMap(…)`.
        if ($effortLevelMap !== null) {
            $map = [...$map, ...$effortLevelMap];
        }

        // 3.
        if (($responses || $azure) && str_starts_with($id, 'gpt-5')) {
            $map = [...$map, 'off' => null];
        }

        if (($responses || $azure || $codex) && in_array($id, ['gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-6.1-sol'], true)) {
            $map = [
                ...$map,
                // `// GPT-6 Astra and GPT-6.1 Sol reject reasoning.effort "none".`
                'off' => $id === 'gpt-6-astra' || $id === 'gpt-6.1-sol' ? null : 'none',
                'minimal' => null,
                'low' => 'low',
                'medium' => 'medium',
                'high' => 'high',
                'xhigh' => 'xhigh',
                'max' => 'max',
            ];
        }

        if ($provider === self::COPILOT && str_starts_with($id, 'gpt-5')) {
            $map = [...$map, 'minimal' => 'low'];
        }

        if ($responses && $provider === 'openai' && in_array($id, self::OPENAI_RESPONSES_NONE_REASONING_MODELS, true)) {
            $map = [...$map, 'off' => 'none'];
        }

        // `// xAI models without verified effort options must not send the undocumented "none"/"minimal" efforts.`
        if ($provider === 'xai' && $responses && $map === []) {
            $map = ['off' => null, 'minimal' => null];
        }

        // `supportsOpenAiXhigh(model.id)`.
        if (self::containsAny($id, ['gpt-5.2', 'gpt-5.3', 'gpt-5.4', 'gpt-5.5', 'gpt-5.6', 'gpt-6'])) {
            $map = [...$map, 'xhigh' => 'xhigh'];
        }

        // `supportsOpenAiMax(model)`: the four OpenAI APIs.
        if (self::containsAny($id, ['gpt-5.6', 'gpt-6']) && ($responses || $azure || $codex || $api === Api::OpenAiCompletions)) {
            $map = [...$map, 'max' => 'max'];
        }

        if ($provider === 'openai' && $id === 'gpt-5.5') {
            $map = [...$map, 'minimal' => null];
        }

        if (str_ends_with($id, 'gpt-5.5-pro')) {
            $map = [...$map, 'off' => null, 'minimal' => null, 'low' => null];
        }

        // `// - "max" is available on all adaptive-thinking Claude models.`
        // `// - "xhigh" is only available on Opus 4.7/4.8/5, Sonnet 5, Haiku 5.5, and Fable 5.`
        if (self::containsAny($id, ['opus-4-6', 'opus-4.6', 'sonnet-4-6', 'sonnet-4.6'])) {
            $map = [...$map, 'max' => 'max'];
        }

        if (self::containsAny($id, ['opus-4-7', 'opus-4.7', 'opus-4-8', 'opus-4.8', 'opus-5', 'opus.5', 'sonnet-5', 'sonnet.5', 'haiku-5', 'haiku.5'])) {
            $map = [...$map, 'xhigh' => 'xhigh', 'max' => 'max'];
        }

        if (str_contains($id, 'fable-5')) {
            $map = [...$map, 'off' => null, 'xhigh' => 'xhigh', 'max' => 'max'];
        }

        if ($api === Api::OpenAiCompletions && str_contains($id, 'deepseek-v4') && $map === []) {
            $map = match (true) {
                $provider === 'openrouter' => [...self::DEEPSEEK_V4_THINKING_LEVEL_MAP, 'xhigh' => 'xhigh', 'max' => null],
                $provider === self::AZURE => self::AZURE_DEEPSEEK_V4_THINKING_LEVEL_MAP,
                in_array($provider, ['deepseek', 'opencode', 'opencode-go'], true) && str_contains($id, 'deepseek-v4-flash') => self::DEEPSEEK_V4_FLASH_THINKING_LEVEL_MAP,
                default => self::DEEPSEEK_V4_THINKING_LEVEL_MAP,
            };
        }

        if ($provider === 'groq' && $id === 'qwen/qwen3.6-27b') {
            $map = [...$map, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'default'];
        }

        if ($provider === self::OPENAI_CODEX && self::containsAny($id, ['gpt-5.2', 'gpt-5.3', 'gpt-5.4', 'gpt-5.5', 'gpt-5.6', 'gpt-6'])) {
            $map = [...$map, 'minimal' => 'low'];
        }

        // "Kimi K2.7 Code is always-thinking. Official docs say `thinking: { type: "disabled" }` is
        // rejected, and callers can omit the thinking parameter to use the enabled default."
        if (($provider === 'moonshotai' || $provider === 'moonshotai-cn') && ($id === 'kimi-k2.7-code' || $id === 'kimi-k2.7-code-highspeed')) {
            $map = [...$map, 'off' => null];
        }

        // "Mercury 2 in instant mode (reasoning_effort: "none") disables tool calling. Mark "off"
        // unsupported so the openai-completions provider omits the reasoning param instead of
        // defaulting to {reasoning:{effort:"none"}}."
        if ($provider === 'openrouter' && str_starts_with($id, 'inception/mercury-2')) {
            $map = [...$map, 'off' => null];
        }

        if ($provider === 'openrouter' && $id === 'z-ai/glm-5.2') {
            $map = [...$map, 'xhigh' => 'xhigh'];
        }

        if ($provider === 'fireworks') {
            if ($api === Api::AnthropicMessages && $forceAdaptiveThinking) {
                // "Qwen Max currently advertises only a toggle. Prefer upstream effort metadata once
                // available instead of replacing it with this fallback." —
                // `getEffortThinkingLevelMap([{type: "effort", values: ["low", "medium", "xhigh"]}])`.
                if ($id === 'accounts/fireworks/models/qwen3p8-max' && $map === []) {
                    $map = ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => null, 'xhigh' => 'xhigh', 'max' => null];
                }

                // "The 2.4T alias omits the verified toggle in models.dev."
                if ($supportsToggle || $id === 'accounts/fireworks/models/qwen3p8-2p4t-a95b') {
                    $map = [...$map, 'off' => 'none'];
                }

                if ($id === 'accounts/fireworks/models/deepseek-v4-pro-0813') {
                    $map = [...$map, 'low' => 'low'];
                }
            }

            // "GLM 5.2 and its fast router support off/high/max. Fireworks maps low and medium to high,
            // so do not expose those aliases as distinct levels."
            if (str_contains($id, 'glm-5p2')) {
                $map = [...$map, 'off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'max' => 'max'];
            }

            // "Fireworks maps medium to high on both APIs; do not expose it as a distinct level."
            if (str_contains($id, 'kimi-k3')) {
                $map = [...$map, 'medium' => null];
            }
        }

        if ($provider === 'opencode-go' && $id === 'glm-5.2') {
            $map = [...$map, ...self::OPENCODE_GO_GLM52_THINKING_LEVEL_MAP];
        }

        // "OpenCode Go exposes Kimi K2.6 thinking as on/off, not distinct effort tiers."
        if ($provider === 'opencode-go' && $id === 'kimi-k2.6') {
            $map = [...$map, 'minimal' => null, 'low' => null, 'medium' => null];
        }

        // "OpenCode Zen Grok Build reasons by default but rejects explicit reasoningEffort."
        if ($provider === 'opencode' && $id === 'grok-build-0.1') {
            $map = [...$map, 'off' => null, 'minimal' => null, 'low' => null, 'medium' => null];
        }

        if ($provider === 'ant-ling' && $reasoning) {
            $map = [...$map, ...self::ANT_LING_RING_THINKING_LEVEL_MAP];
        }

        if ($provider === self::COPILOT) {
            $map = [...$map, ...(self::COPILOT_THINKING_LEVEL_OVERRIDES[$id] ?? [])];
        }

        return $map;
    }

    /**
     * A row's price: the four rates, and the tiers a row carries under `tiers`.
     *
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: float}> $tiers
     */
    private static function pricing(float $in, float $out, float $read, float $write, array $tiers = []): Pricing
    {
        return new Pricing($in, $out, $read, $write, array_map(
            static fn (array $tier): PricingTier => new PricingTier(...$tier),
            $tiers,
        ));
    }

    /**
     * Upstream's generator `supportsDirectReasoningEffort(model)`, the gate on
     * `applyModelsDevReasoningOptionMetadata()`: an Anthropic Messages model only with
     * `forceAdaptiveThinking`, every Responses model (OpenAI's, Azure's, Codex's), and a completions model whose compat — detected,
     * then its own laid over it, as `OpenAiCompat::resolve()` does — says `thinkingFormat: "openai"`
     * and `supportsReasoningEffort`. Any other API is false.
     */
    private static function supportsDirectReasoningEffort(Api $api, string $provider, string $baseUrl, string $id, OpenAiCompat|AnthropicCompat|BedrockCompat|null $compat): bool
    {
        if ($api === Api::AnthropicMessages) {
            return $compat instanceof AnthropicCompat && $compat->forceAdaptiveThinking === true;
        }

        if ($api === Api::OpenAiResponses || $api === Api::AzureOpenAiResponses || $api === Api::OpenAiCodexResponses) {
            return true;
        }

        if ($api !== Api::OpenAiCompletions) {
            return false;
        }

        $detected = OpenAiCompat::detect($baseUrl, $provider, $id);
        $explicit = $compat instanceof OpenAiCompat ? $compat : null;

        return ($explicit?->thinkingFormat ?? $detected->thinkingFormat) === 'openai'
            && ($explicit?->reasoningEffort ?? $detected->reasoningEffort) === true;
    }

    /**
     * Upstream's generator `applyImageInputMetadata()`, for a built-in model: nothing for a model that
     * takes no images; otherwise the provider's limits — Anthropic `{maxRequestBytes: 32 MiB, images:
     * {maxPerRequest: 100 at a 200,000 window, else 600}}`, OpenAI `{maxRequestBytes: 512 MiB, images:
     * {maxPerRequest: 1500}}`, Google `{maxRequestBytes: 20 MiB, images: {maxPerRequest: 3600}}`, Amazon
     * Bedrock `{images: {maxPerMessage: 20}}` — with `DEFAULT_IMAGE_RESIZE` as the images' `resize`
     * on every one: "Keep the generated default no less restrictive than coding-agent's historical
     * image preprocessing. Provider limits can narrow this profile, but unknown providers retain the
     * cache-safe 2000px / 4.5 MiB behavior."
     *
     * Public for the models a provider extension declares, which upstream's generator would have
     * given the same treatment: `pig-antigravity`'s catalogue gets the default branch.
     *
     * @param list<string> $input
     * @return array<string, mixed>|null
     */
    public static function inputLimits(string $provider, array $input, int $contextWindow): ?array
    {
        if (!in_array('image', $input, true)) {
            return null;
        }

        $providerLimits = match ($provider) {
            self::ANTHROPIC => ['maxRequestBytes' => 32 * 1024 * 1024, 'images' => ['maxPerRequest' => $contextWindow === 200_000 ? 100 : 600]],
            self::AMAZON_BEDROCK => ['images' => ['maxPerMessage' => 20]],
            'openai' => ['maxRequestBytes' => 512 * 1024 * 1024, 'images' => ['maxPerRequest' => 1500]],
            'google' => ['maxRequestBytes' => 20 * 1024 * 1024, 'images' => ['maxPerRequest' => 3600]],
            default => [],
        };

        return [
            ...$providerLimits,
            'images' => [
                ...($providerLimits['images'] ?? []),
                'resize' => self::DEFAULT_IMAGE_RESIZE,
            ],
        ];
    }

    /**
     * Upstream's generator `applyPromptCacheMetadata()`: direct Anthropic on its own API gets
     * `ANTHROPIC_PROMPT_CACHE`, and nothing else gets anything — "Do not add OpenAI lifetimes yet."
     *
     * @return array{short: int, long: int}|null
     */
    private static function promptCache(string $provider, Api $api): ?array
    {
        return $provider === self::ANTHROPIC && $api === Api::AnthropicMessages ? self::ANTHROPIC_PROMPT_CACHE : null;
    }

    /**
     * Upstream's generator `applyAnthropicAllowedFallbackModelMetadata()`, over the `anthropic` rows:
     * each model in `ANTHROPIC_ALLOWED_FALLBACK_MODELS` that exists gets the fallbacks that exist —
     * only managed-effort ones for a managed-effort model — with their own prices, and nothing when
     * none is left.
     *
     * @param array<string, Model> $models
     * @return array<string, Model>
     */
    private static function withAllowedFallbackModels(array $models): array
    {
        foreach (self::ANTHROPIC_ALLOWED_FALLBACK_MODELS as $id => $fallbackIds) {
            $model = $models[self::ANTHROPIC . '/' . $id] ?? null;

            if ($model === null || !$model->compat instanceof AnthropicCompat) {
                continue;
            }

            if ($model->compat->supportsMidConvoEffort === true) {
                $fallbackIds = array_values(array_filter($fallbackIds, AnthropicCompat::supportsMidConvoEffortModel(...)));
            }

            $allowed = [];

            foreach ($fallbackIds as $fallbackId) {
                $fallback = $models[self::ANTHROPIC . '/' . $fallbackId] ?? null;

                if ($fallback !== null) {
                    $allowed[] = ['provider' => $fallback->provider, 'model' => $fallback->id, 'cost' => $fallback->pricing];
                }
            }

            if ($allowed === []) {
                continue;
            }

            $models[self::ANTHROPIC . '/' . $id] = new Model(
                $model->id,
                $model->name,
                $model->api,
                $model->provider,
                $model->baseUrl,
                $model->contextWindow,
                $model->maxTokens,
                $model->reasoning,
                $model->input,
                $model->pricing,
                $model->headers,
                $model->compat->withAllowedFallbackModels($allowed),
                $model->thinkingLevelMap,
                $model->inputLimits,
                $model->promptCache,
            );
        }

        return $models;
    }

    /**
     * Upstream's generator `applyOpenAIGrammarToolCompatMetadata()`, the id half: `/^gpt-(\d+)/` with
     * the number at least 5 — "OpenAI rejects `type: "custom"` tools for pre-GPT-5 models (gpt-4.x,
     * gpt-4o, o-series)". The provider half (`openai`, `github-copilot`, … on a Responses API) is
     * where this is called from.
     */
    private static function isGrammarToolModel(string $id): bool
    {
        return preg_match('/^gpt-(\d+)/', $id, $match) === 1 && (int) $match[1] >= 5;
    }

    /**
     * A Copilot Responses model's compat: the grammar-tools flag where it applies, and upstream's
     * `applyOpenAIResponsesTranscriptMetadata()` — "OpenCode Zen, OpenCode Go, and GitHub Copilot pass
     * both those messages and `additional_tools` items through to OpenAI unchanged; tool search is not
     * verified through those proxies" — so mid-conversation system messages and additional tools for
     * the ids in `OPENAI_TOOL_SEARCH_MODEL_IDS`, and no tool search.
     */
    private static function copilotResponsesCompat(string $id): ?OpenAiCompat
    {
        $grammar = self::isGrammarToolModel($id);
        $transcript = in_array($id, self::OPENAI_TOOL_SEARCH_MODEL_IDS, true);

        if (!$grammar && !$transcript) {
            return null;
        }

        return new OpenAiCompat(
            grammarTools: $grammar ? true : null,
            supportsMidConvoSystemMessages: $transcript ? true : null,
            supportsAdditionalTools: $transcript ? true : null,
        );
    }

    /**
     * Upstream's `processZaiModels()` compat for a z.ai model, on both of its plans (`zai` and
     * `zai-coding-cn`): no `developer` role, the `zai` thinking format, `supportsReasoningEffort` when the
     * row has verified efforts (its `thinkingLevelMap`), and `zaiToolStream` for every model but the
     * four that do not take it. `completionsCompat()` lays it over what the generator detects.
     */
    private static function zaiCompat(string $id, bool $hasThinkingLevelMap): OpenAiCompat
    {
        return new OpenAiCompat(
            developerRole: false,
            reasoningEffort: $hasThinkingLevelMap ? true : null,
            thinkingFormat: 'zai',
            zaiToolStream: in_array($id, self::ZAI_TOOL_STREAM_UNSUPPORTED_MODELS, true) ? null : true,
        );
    }

    /** @param list<string> $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An Azure row's compat, as upstream's generator leaves the clone: `applyOpenAIGrammarToolCompatMetadata()`
     * (`azure` is one of `OPENAI_GRAMMAR_TOOL_PROVIDERS`) for `gpt-<n>` with n >= 5 on the Responses API,
     * and nothing else there; and for DeepSeek V4 Pro on Chat Completions the DeepSeek row's compat
     * (`requiresReasoningContentOnAssistantMessages`, and `supportsStrictMode` from detection) under
     * Azure's own: "Azure 400s on DeepSeek's `thinking` field and on every prompt cache parameter,
     * discards a `developer` system message unbilled once reasoning_effort is set, and honours mid-convo
     * ones (#9645)."
     */
    private static function azureCompat(Api $api, string $id): ?OpenAiCompat
    {
        if ($api === Api::OpenAiCompletions) {
            return new OpenAiCompat(
                developerRole: false,
                reasoningContentOnAssistantMessages: true,
                strictMode: true,
                thinkingFormat: 'openai',
                supportsLongCacheRetention: false,
                supportsMidConvoSystemMessages: true,
            );
        }

        return self::isGrammarToolModel($id) ? new OpenAiCompat(grammarTools: true) : null;
    }

    /**
     * A Codex row's compat: `applyOpenAIGrammarToolCompatMetadata()` for `gpt-<n>` with n >= 5,
     * `applyOpenAIToolSearchMetadata()` — tool search for `OPENAI_TOOL_SEARCH_MODEL_IDS`, and
     * `additional_tools` for `OPENAI_CODEX_ADDITIONAL_TOOLS_MODEL_IDS` — and
     * `applyOpenAIResponsesTranscriptMetadata()`'s mid-conversation system messages for the same ids.
     */
    private static function codexCompat(string $id): ?OpenAiCompat
    {
        $grammar = self::isGrammarToolModel($id);
        $toolSearch = in_array($id, self::OPENAI_TOOL_SEARCH_MODEL_IDS, true);

        if (!$grammar && !$toolSearch) {
            return null;
        }

        return new OpenAiCompat(
            grammarTools: $grammar ? true : null,
            supportsMidConvoSystemMessages: $toolSearch ? true : null,
            supportsToolSearch: $toolSearch ? true : null,
            supportsAdditionalTools: $toolSearch && in_array($id, self::OPENAI_CODEX_ADDITIONAL_TOOLS_MODEL_IDS, true) ? true : null,
        );
    }

    /**
     * What Copilot rejects, attached to the model rather than guessed from the host.
     *
     * `store`, the `developer` role and `reasoning_effort` are all refused. A method and not a
     * constant because `new` is not allowed in a class constant, and explicit rather than
     * `OpenAiCompat::detect()` because Copilot's base URL is whatever the token says it is —
     * an enterprise install answers at `copilot-api.<domain>`, which contains no
     * `githubcopilot.com` for a host check to find.
     */
    private static function copilotCompat(string $id): OpenAiCompat
    {
        // `strictMode` is upstream's generated metadata rather than its Copilot block: detection
        // at generation time gives every Copilot completions model `supportsStrictMode: true`.
        // `applyOpenAICompletionsTranscriptMetadata()`: "GitHub Copilot forwards K3 text but
        // silently drops its tool-bearing message" — system text mid-conversation, no tool additions.
        return new OpenAiCompat(
            store: false,
            developerRole: false,
            reasoningEffort: false,
            strictMode: true,
            supportsMidConvoSystemMessages: $id === 'kimi-k3' ? true : null,
        );
    }
}
