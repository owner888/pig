<?php

declare(strict_types=1);

namespace Pig\Ai;


/**
 * Every model this can talk to, by provider and id.
 *
 * Every model whose **protocol is ported** — a model that could be selected and then not talked to
 * is a worse answer than "no such model". What is left out is OpenRouter's, because that list is a
 * directory of everyone else's models and goes stale fastest.
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
 * `COPILOT_MODELS` carries an api per row before the rest, because Copilot serves three APIs
 * (Anthropic's for its Claude models); the other seven carry the full nine columns.
 *
 * Adding a provider is adding a table, one line in `table()` and one row in the generator's
 * `DIRECT` — not changing the rest.
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
     * OpenRouter is the next one to belong here, whenever its 236 arrive.
     *
     * **Vertex AI is here for the same reason**: it serves Google's Gemini models under Google's own
     * ids, so `gemini-2.5-flash` names two things again and the bare id stays the Gemini API's; Vertex's
     * is `google-vertex/gemini-2.5-flash`. Bedrock is not — its ids carry their vendor
     * (`anthropic.claude-…`) and collide with nobody's.
     */
    private const array RESOLD = [self::COPILOT, self::GOOGLE_VERTEX];

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

    /**
     * provider => whether its built-in models take strict tools — upstream's generator, which
     * writes `supportsStrictMode: !isMoonshot && !isTogether && !isCloudflareAiGateway && !isNvidia
     * && !isCerebras` into every built-in `openai-completions` model's `compat`, against a runtime
     * default of false. Cerebras is the one here it leaves out.
     */
    private const array STRICT_MODE = [
        'cerebras' => false,
        'groq' => true,
        'xai' => true,
        'zai' => true,
    ];

    /** provider => where its OpenAI-compatible endpoint lives. */
    private const array OPENAI_COMPATIBLE = [
        'cerebras' => 'https://api.cerebras.ai/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'xai' => 'https://api.x.ai/v1',
        'zai' => 'https://api.z.ai/api/coding/paas/v4',
    ];

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
        'gpt-5.6' => ['GPT-5.6', 1_050_000, 128_000, true, true, 4.0, 20.0, 0.4, 5.0, 'tiers' => [[272_000, 8.0, 30.0, 0.8, 10.0]], 'effortLevelMap' => ['off' => 'none', 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max']],
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

    /** @var array<string, Model>|null built once, on the first lookup that needs it */
    private static ?array $models = null;

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
                    effortLevelMap: self::supportsDirectReasoningEffort(Api::AnthropicMessages, self::ANTHROPIC, self::ANTHROPIC_BASE_URL, $id, $compat) ? ($row['effortLevelMap'] ?? null) : null,
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

            foreach ($table as $id => $row) {
                [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write] = $row;
                $input = $images ? ['text', 'image'] : ['text'];
                $compat = $provider === 'zai'
                    ? self::zaiCompat($id, isset($row['thinkingLevelMap']))
                    : (self::STRICT_MODE[$provider] ? new OpenAiCompat(strictMode: true) : null);
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
                    effortLevelMap: self::supportsDirectReasoningEffort($api, self::COPILOT, self::COPILOT_BASE_URL, $id, $compat) ? ($row['effortLevelMap'] ?? null) : null,
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
     *    - a Responses `gpt-5…` gets `{off: null}`; GPT-6 Astra/Sol/Luna and 6.1 Sol on the Responses
     *      API get `{off: "none" | null, minimal: null, low, medium, high, xhigh, max}` (Astra and
     *      6.1 Sol reject `none`, so `off: null`);
     *    - a Copilot `gpt-5…` gets `{minimal: "low"}`;
     *    - `openai`'s Responses models in `OPENAI_RESPONSES_NONE_REASONING_MODELS` get `{off: "none"}`;
     *    - an xAI Responses model with no map yet gets `{off: null, minimal: null}` (pig's xAI rows are
     *      on completions, so this only matters for a future table);
     *    - `supportsOpenAiXhigh()` ids — gpt-5.2/5.3/5.4/5.5/5.6, gpt-6 — get `{xhigh}` whatever their
     *      API, and `supportsOpenAiMax()` ones — gpt-5.6 and gpt-6 on an OpenAI API — `{max}`;
     *    - `openai/gpt-5.5` gets `{minimal: null}`, a `…gpt-5.5-pro` `{off, minimal, low: null}`;
     *    - the Anthropic arms — `{max}` on Opus/Sonnet 4.6, `{xhigh, max}` on Opus 4.7/4.8/5, Sonnet 5
     *      and Haiku 5, `{off: null, xhigh, max}` on Fable 5;
     *    - `groq/qwen/qwen3.6-27b` gets `{minimal, low, medium: null, high: "default"}`;
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
    private static function thinkingLevelMap(string $provider, Api $api, string $id, array $base = [], ?array $effortLevelMap = null): array
    {
        $map = $base;
        $responses = $api === Api::OpenAiResponses;

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
        if ($responses && str_starts_with($id, 'gpt-5')) {
            $map = [...$map, 'off' => null];
        }

        if ($responses && in_array($id, ['gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-6.1-sol'], true)) {
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

        // `supportsOpenAiMax(model)`: the four OpenAI APIs upstream has; pig has two of them.
        if (self::containsAny($id, ['gpt-5.6', 'gpt-6']) && ($responses || $api === Api::OpenAiCompletions)) {
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

        if ($provider === 'groq' && $id === 'qwen/qwen3.6-27b') {
            $map = [...$map, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'default'];
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
     * `forceAdaptiveThinking`, every Responses model, and a completions model whose compat — detected,
     * then its own laid over it, as `OpenAiCompat::resolve()` does — says `thinkingFormat: "openai"`
     * and `supportsReasoningEffort`. Any other API is false.
     */
    private static function supportsDirectReasoningEffort(Api $api, string $provider, string $baseUrl, string $id, OpenAiCompat|AnthropicCompat|BedrockCompat|null $compat): bool
    {
        if ($api === Api::AnthropicMessages) {
            return $compat instanceof AnthropicCompat && $compat->forceAdaptiveThinking === true;
        }

        if ($api === Api::OpenAiResponses) {
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
     * Upstream's `processZaiModels()` compat for a z.ai model: no `developer` role, the `zai` thinking
     * format, `supportsReasoningEffort` when the row has verified efforts (its `thinkingLevelMap`), and
     * `zaiToolStream` for every model but the four that do not take it — over the strict tools every
     * built-in completions model of a strict provider gets (`STRICT_MODE`).
     */
    private static function zaiCompat(string $id, bool $hasThinkingLevelMap): OpenAiCompat
    {
        return new OpenAiCompat(
            developerRole: false,
            reasoningEffort: $hasThinkingLevelMap ? true : null,
            strictMode: self::STRICT_MODE['zai'],
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
