<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Providers\GoogleGeminiCli;

/**
 * Every model this can talk to, by provider and id.
 *
 * Upstream generates `models.generated.ts` from models.dev: 7105 lines, 414 models,
 * twelve providers. Here are the 178 whose protocol is ported — Anthropic's 21, OpenAI's own 33 on
 * the Responses API, Google's 21, the 72 across five providers that speak `openai-completions`,
 * GitHub Copilot's 19, Code Assist's 5 and Antigravity's 7. A model that could be selected and then
 * not talked to is a worse answer than "no such model". **What is left out is OpenRouter's 236**,
 * because that list is a directory of everyone else's models and goes stale fastest.
 *
 * The figures are upstream's at the anchor commit, which is the source a port should
 * agree with rather than whatever models.dev says today. Every row was checked against that file
 * field for field — id, name, api, provider, base URL, reasoning, accepted input, context window,
 * max tokens and all four prices — and `ModelsTest` pins the per-provider counts and a row per
 * provider so a hand-transcribed table cannot drift quietly.
 *
 * Adding a provider is adding a table and one line in `table()`, not changing the rest.
 *
 * Ported from upstream's `models.ts` plus the matching slices of `models.generated.ts`.
 */
final class Models
{
    public const string ANTHROPIC = 'anthropic';

    public const string COPILOT = 'github-copilot';

    public const string GEMINI_CLI = 'google-gemini-cli';
    public const string ANTIGRAVITY = 'google-antigravity';

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
     */
    private const array RESOLD = [self::COPILOT, self::GEMINI_CLI, self::ANTIGRAVITY];

    /**
     * id => [name, context window, max tokens, reasoning]
     *
     * Google Cloud Code Assist — Gemini through a subscription. All five take images, all five
     * answer at the one endpoint, and the pricing is zeroes across the board because a
     * subscription is not metered per token. `Providers\GoogleGeminiCli` is the protocol.
     *
     * Their ids are Gemini's own, which is why this provider is in `RESOLD`: a bare
     * `gemini-2.5-pro` means Google's public endpoint, and Code Assist's is
     * `google-gemini-cli/gemini-2.5-pro`.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool}>
     */
    private const array GEMINI_CLI_MODELS = [
        'gemini-2.0-flash' => ['Gemini 2.0 Flash (Cloud Code Assist)', 1_048_576, 8_192, false],
        'gemini-2.5-flash' => ['Gemini 2.5 Flash (Cloud Code Assist)', 1_048_576, 65_535, true],
        'gemini-2.5-pro' => ['Gemini 2.5 Pro (Cloud Code Assist)', 1_048_576, 65_535, true],
        'gemini-3-flash-preview' => ['Gemini 3 Flash Preview (Cloud Code Assist)', 1_048_576, 65_535, true],
        'gemini-3-pro-preview' => ['Gemini 3 Pro Preview (Cloud Code Assist)', 1_048_576, 65_535, true],
    ];

    /**
     * Antigravity's seven, which are the reason it exists: not Google's models.
     *
     * Two of Anthropic's, three Geminis and an open-weights one, all behind Google's sandbox
     * deployment of Code Assist and all billed to a subscription rather than per token. The
     * thinking variants are **separate ids** rather than a level on one model — `-thinking` is
     * how Antigravity spells it and there is no flag that turns it on, so a model here either
     * reasons or does not.
     *
     * In `RESOLD`, and it matters more here than anywhere: `claude-sonnet-4-5` is Anthropic's
     * own id. A bare one has to keep meaning Anthropic's, or somebody with an Antigravity
     * sign-in would find their `--model sonnet` quietly going through Google.
     *
     * [name, context window, max output, reasoning, accepts images]
     */
    private const array ANTIGRAVITY_MODELS = [
        'claude-opus-4-5-thinking' => ['Claude Opus 4.5 Thinking (Antigravity)', 200_000, 64_000, true, true],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5 (Antigravity)', 200_000, 64_000, false, true],
        'claude-sonnet-4-5-thinking' => ['Claude Sonnet 4.5 Thinking (Antigravity)', 200_000, 64_000, true, true],
        'gemini-3-flash' => ['Gemini 3 Flash (Antigravity)', 1_048_576, 65_535, true, true],
        'gemini-3-pro-high' => ['Gemini 3 Pro High (Antigravity)', 1_048_576, 65_535, true, true],
        'gemini-3-pro-low' => ['Gemini 3 Pro Low (Antigravity)', 1_048_576, 65_535, true, true],
        'gpt-oss-120b-medium' => ['GPT-OSS 120B Medium (Antigravity)', 131_072, 32_768, false, false],
    ];

    /** provider => where its OpenAI-compatible endpoint lives. */
    private const array OPENAI_COMPATIBLE = [
        'cerebras' => 'https://api.cerebras.ai/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'mistral' => 'https://api.mistral.ai/v1',
        'xai' => 'https://api.x.ai/v1',
        'zai' => 'https://api.z.ai/api/coding/paas/v4',
    ];

    /**
     * id => [name, context window, max tokens, reasoning, $/Mtok in, out, cache read, cache write]
     *
     * A table rather than 21 constructor calls: the shape is the same every time, and a
     * column that is wrong is easier to see in a column than in a paragraph.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: float, 5: float, 6: float, 7: float}>
     */
    private const array ANTHROPIC_MODELS = [
        'claude-3-5-haiku-20241022' => ['Claude Haiku 3.5', 200_000, 8_192, false, 0.8, 4.0, 0.08, 1.0],
        'claude-3-5-haiku-latest' => ['Claude Haiku 3.5 (latest)', 200_000, 8_192, false, 0.8, 4.0, 0.08, 1.0],
        'claude-3-5-sonnet-20240620' => ['Claude Sonnet 3.5', 200_000, 8_192, false, 3.0, 15.0, 0.3, 3.75],
        'claude-3-5-sonnet-20241022' => ['Claude Sonnet 3.5 v2', 200_000, 8_192, false, 3.0, 15.0, 0.3, 3.75],
        'claude-3-7-sonnet-20250219' => ['Claude Sonnet 3.7', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-3-7-sonnet-latest' => ['Claude Sonnet 3.7 (latest)', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-3-haiku-20240307' => ['Claude Haiku 3', 200_000, 4_096, false, 0.25, 1.25, 0.03, 0.3],
        'claude-3-opus-20240229' => ['Claude Opus 3', 200_000, 4_096, false, 15.0, 75.0, 1.5, 18.75],
        'claude-3-sonnet-20240229' => ['Claude Sonnet 3', 200_000, 4_096, false, 3.0, 15.0, 0.3, 0.3],
        'claude-haiku-4-5' => ['Claude Haiku 4.5 (latest)', 200_000, 64_000, true, 1.0, 5.0, 0.1, 1.25],
        'claude-haiku-4-5-20251001' => ['Claude Haiku 4.5', 200_000, 64_000, true, 1.0, 5.0, 0.1, 1.25],
        'claude-opus-4-0' => ['Claude Opus 4 (latest)', 200_000, 32_000, true, 15.0, 75.0, 1.5, 18.75],
        'claude-opus-4-1' => ['Claude Opus 4.1 (latest)', 200_000, 32_000, true, 15.0, 75.0, 1.5, 18.75],
        'claude-opus-4-1-20250805' => ['Claude Opus 4.1', 200_000, 32_000, true, 15.0, 75.0, 1.5, 18.75],
        'claude-opus-4-20250514' => ['Claude Opus 4', 200_000, 32_000, true, 15.0, 75.0, 1.5, 18.75],
        'claude-opus-4-5' => ['Claude Opus 4.5 (latest)', 200_000, 64_000, true, 5.0, 25.0, 0.5, 6.25],
        'claude-opus-4-5-20251101' => ['Claude Opus 4.5', 200_000, 64_000, true, 5.0, 25.0, 0.5, 6.25],
        'claude-sonnet-4-0' => ['Claude Sonnet 4 (latest)', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-4-20250514' => ['Claude Sonnet 4', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-4-5' => ['Claude Sonnet 4.5 (latest)', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-4-5-20250929' => ['Claude Sonnet 4.5', 200_000, 64_000, true, 3.0, 15.0, 0.3, 3.75],
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array CEREBRAS_MODELS = [
        'gpt-oss-120b' => ['GPT OSS 120B', 131_072, 32_768, true, false, 0.25, 0.69, 0.0, 0.0],
        'qwen-3-235b-a22b-instruct-2507' => ['Qwen 3 235B Instruct', 131_000, 32_000, false, false, 0.6, 1.2, 0.0, 0.0],
        'zai-glm-4.6' => ['Z.AI GLM-4.6', 131_072, 40_960, false, false, 0.0, 0.0, 0.0, 0.0],
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array GROQ_MODELS = [
        'deepseek-r1-distill-llama-70b' => ['DeepSeek R1 Distill Llama 70B', 131_072, 8_192, true, false, 0.75, 0.99, 0.0, 0.0],
        'gemma2-9b-it' => ['Gemma 2 9B', 8_192, 8_192, false, false, 0.2, 0.2, 0.0, 0.0],
        'llama-3.1-8b-instant' => ['Llama 3.1 8B Instant', 131_072, 8_192, false, false, 0.05, 0.08, 0.0, 0.0],
        'llama-3.3-70b-versatile' => ['Llama 3.3 70B Versatile', 131_072, 32_768, false, false, 0.59, 0.79, 0.0, 0.0],
        'llama3-70b-8192' => ['Llama 3 70B', 8_192, 8_192, false, false, 0.59, 0.79, 0.0, 0.0],
        'llama3-8b-8192' => ['Llama 3 8B', 8_192, 8_192, false, false, 0.05, 0.08, 0.0, 0.0],
        'meta-llama/llama-4-maverick-17b-128e-instruct' => ['Llama 4 Maverick 17B', 131_072, 8_192, false, true, 0.2, 0.6, 0.0, 0.0],
        'meta-llama/llama-4-scout-17b-16e-instruct' => ['Llama 4 Scout 17B', 131_072, 8_192, false, true, 0.11, 0.34, 0.0, 0.0],
        'mistral-saba-24b' => ['Mistral Saba 24B', 32_768, 32_768, false, false, 0.79, 0.79, 0.0, 0.0],
        'moonshotai/kimi-k2-instruct' => ['Kimi K2 Instruct', 131_072, 16_384, false, false, 1.0, 3.0, 0.0, 0.0],
        'moonshotai/kimi-k2-instruct-0905' => ['Kimi K2 Instruct 0905', 262_144, 16_384, false, false, 1.0, 3.0, 0.0, 0.0],
        'openai/gpt-oss-120b' => ['GPT OSS 120B', 131_072, 32_768, true, false, 0.15, 0.75, 0.0, 0.0],
        'openai/gpt-oss-20b' => ['GPT OSS 20B', 131_072, 32_768, true, false, 0.1, 0.5, 0.0, 0.0],
        'qwen-qwq-32b' => ['Qwen QwQ 32B', 131_072, 16_384, true, false, 0.29, 0.39, 0.0, 0.0],
        'qwen/qwen3-32b' => ['Qwen3 32B', 131_072, 16_384, true, false, 0.29, 0.59, 0.0, 0.0],
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array MISTRAL_MODELS = [
        'codestral-latest' => ['Codestral', 256_000, 4_096, false, false, 0.3, 0.9, 0.0, 0.0],
        'devstral-2512' => ['Devstral 2', 262_144, 262_144, false, false, 0.0, 0.0, 0.0, 0.0],
        'devstral-medium-2507' => ['Devstral Medium', 128_000, 128_000, false, false, 0.4, 2.0, 0.0, 0.0],
        'devstral-medium-latest' => ['Devstral 2', 262_144, 262_144, false, false, 0.4, 2.0, 0.0, 0.0],
        'devstral-small-2505' => ['Devstral Small 2505', 128_000, 128_000, false, false, 0.1, 0.3, 0.0, 0.0],
        'devstral-small-2507' => ['Devstral Small', 128_000, 128_000, false, false, 0.1, 0.3, 0.0, 0.0],
        'labs-devstral-small-2512' => ['Devstral Small 2', 256_000, 256_000, false, true, 0.0, 0.0, 0.0, 0.0],
        'magistral-medium-latest' => ['Magistral Medium', 128_000, 16_384, true, false, 2.0, 5.0, 0.0, 0.0],
        'magistral-small' => ['Magistral Small', 128_000, 128_000, true, false, 0.5, 1.5, 0.0, 0.0],
        'ministral-3b-latest' => ['Ministral 3B', 128_000, 128_000, false, false, 0.04, 0.04, 0.0, 0.0],
        'ministral-8b-latest' => ['Ministral 8B', 128_000, 128_000, false, false, 0.1, 0.1, 0.0, 0.0],
        'mistral-large-2411' => ['Mistral Large 2.1', 131_072, 16_384, false, false, 2.0, 6.0, 0.0, 0.0],
        'mistral-large-2512' => ['Mistral Large 3', 262_144, 262_144, false, true, 0.5, 1.5, 0.0, 0.0],
        'mistral-large-latest' => ['Mistral Large', 262_144, 262_144, false, true, 0.5, 1.5, 0.0, 0.0],
        'mistral-medium-2505' => ['Mistral Medium 3', 131_072, 131_072, false, true, 0.4, 2.0, 0.0, 0.0],
        'mistral-medium-2508' => ['Mistral Medium 3.1', 262_144, 262_144, false, true, 0.4, 2.0, 0.0, 0.0],
        'mistral-medium-latest' => ['Mistral Medium', 128_000, 16_384, false, true, 0.4, 2.0, 0.0, 0.0],
        'mistral-nemo' => ['Mistral Nemo', 128_000, 128_000, false, false, 0.15, 0.15, 0.0, 0.0],
        'mistral-small-2506' => ['Mistral Small 3.2', 128_000, 16_384, false, true, 0.1, 0.3, 0.0, 0.0],
        'mistral-small-latest' => ['Mistral Small', 128_000, 16_384, false, true, 0.1, 0.3, 0.0, 0.0],
        'open-mistral-7b' => ['Mistral 7B', 8_000, 8_000, false, false, 0.25, 0.25, 0.0, 0.0],
        'open-mixtral-8x22b' => ['Mixtral 8x22B', 64_000, 64_000, false, false, 2.0, 6.0, 0.0, 0.0],
        'open-mixtral-8x7b' => ['Mixtral 8x7B', 32_000, 32_000, false, false, 0.7, 0.7, 0.0, 0.0],
        'pixtral-12b' => ['Pixtral 12B', 128_000, 128_000, false, true, 0.15, 0.15, 0.0, 0.0],
        'pixtral-large-latest' => ['Pixtral Large', 128_000, 128_000, false, true, 2.0, 6.0, 0.0, 0.0],
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array XAI_MODELS = [
        'grok-2' => ['Grok 2', 131_072, 8_192, false, false, 2.0, 10.0, 2.0, 0.0],
        'grok-2-1212' => ['Grok 2 (1212)', 131_072, 8_192, false, false, 2.0, 10.0, 2.0, 0.0],
        'grok-2-latest' => ['Grok 2 Latest', 131_072, 8_192, false, false, 2.0, 10.0, 2.0, 0.0],
        'grok-2-vision' => ['Grok 2 Vision', 8_192, 4_096, false, true, 2.0, 10.0, 2.0, 0.0],
        'grok-2-vision-1212' => ['Grok 2 Vision (1212)', 8_192, 4_096, false, true, 2.0, 10.0, 2.0, 0.0],
        'grok-2-vision-latest' => ['Grok 2 Vision Latest', 8_192, 4_096, false, true, 2.0, 10.0, 2.0, 0.0],
        'grok-3' => ['Grok 3', 131_072, 8_192, false, false, 3.0, 15.0, 0.75, 0.0],
        'grok-3-fast' => ['Grok 3 Fast', 131_072, 8_192, false, false, 5.0, 25.0, 1.25, 0.0],
        'grok-3-fast-latest' => ['Grok 3 Fast Latest', 131_072, 8_192, false, false, 5.0, 25.0, 1.25, 0.0],
        'grok-3-latest' => ['Grok 3 Latest', 131_072, 8_192, false, false, 3.0, 15.0, 0.75, 0.0],
        'grok-3-mini' => ['Grok 3 Mini', 131_072, 8_192, true, false, 0.3, 0.5, 0.075, 0.0],
        'grok-3-mini-fast' => ['Grok 3 Mini Fast', 131_072, 8_192, true, false, 0.6, 4.0, 0.15, 0.0],
        'grok-3-mini-fast-latest' => ['Grok 3 Mini Fast Latest', 131_072, 8_192, true, false, 0.6, 4.0, 0.15, 0.0],
        'grok-3-mini-latest' => ['Grok 3 Mini Latest', 131_072, 8_192, true, false, 0.3, 0.5, 0.075, 0.0],
        'grok-4' => ['Grok 4', 256_000, 64_000, true, false, 3.0, 15.0, 0.75, 0.0],
        'grok-4-1-fast' => ['Grok 4.1 Fast', 2_000_000, 30_000, true, true, 0.2, 0.5, 0.05, 0.0],
        'grok-4-1-fast-non-reasoning' => ['Grok 4.1 Fast (Non-Reasoning)', 2_000_000, 30_000, false, true, 0.2, 0.5, 0.05, 0.0],
        'grok-4-fast' => ['Grok 4 Fast', 2_000_000, 30_000, true, true, 0.2, 0.5, 0.05, 0.0],
        'grok-4-fast-non-reasoning' => ['Grok 4 Fast (Non-Reasoning)', 2_000_000, 30_000, false, true, 0.2, 0.5, 0.05, 0.0],
        'grok-beta' => ['Grok Beta', 131_072, 4_096, false, false, 5.0, 15.0, 5.0, 0.0],
        'grok-code-fast-1' => ['Grok Code Fast 1', 256_000, 10_000, true, false, 0.2, 1.5, 0.02, 0.0],
        'grok-vision-beta' => ['Grok Vision Beta', 8_192, 4_096, false, true, 5.0, 15.0, 5.0, 0.0],
    ];

    /**
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array ZAI_MODELS = [
        'glm-4.5' => ['GLM-4.5', 131_072, 98_304, true, false, 0.6, 2.2, 0.11, 0.0],
        'glm-4.5-air' => ['GLM-4.5-Air', 131_072, 98_304, true, false, 0.2, 1.1, 0.03, 0.0],
        'glm-4.5-flash' => ['GLM-4.5-Flash', 131_072, 98_304, true, false, 0.0, 0.0, 0.0, 0.0],
        'glm-4.5v' => ['GLM-4.5V', 64_000, 16_384, true, true, 0.6, 1.8, 0.0, 0.0],
        'glm-4.6' => ['GLM-4.6', 204_800, 131_072, true, false, 0.6, 2.2, 0.11, 0.0],
        'glm-4.6v' => ['GLM-4.6V', 128_000, 32_768, true, true, 0.3, 0.9, 0.0, 0.0],
        'glm-4.7' => ['GLM-4.7', 204_800, 131_072, true, false, 0.6, 2.2, 0.11, 0.0],
    ];

    /**
     * OpenAI's own, which speak the Responses API rather than chat-completions.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array OPENAI_MODELS = [
        'codex-mini-latest' => ['Codex Mini', 200_000, 100_000, true, false, 1.5, 6.0, 0.375, 0.0],
        'gpt-4' => ['GPT-4', 8_192, 8_192, false, false, 30.0, 60.0, 0.0, 0.0],
        'gpt-4-turbo' => ['GPT-4 Turbo', 128_000, 4_096, false, true, 10.0, 30.0, 0.0, 0.0],
        'gpt-4.1' => ['GPT-4.1', 1_047_576, 32_768, false, true, 2.0, 8.0, 0.5, 0.0],
        'gpt-4.1-mini' => ['GPT-4.1 mini', 1_047_576, 32_768, false, true, 0.4, 1.6, 0.1, 0.0],
        'gpt-4.1-nano' => ['GPT-4.1 nano', 1_047_576, 32_768, false, true, 0.1, 0.4, 0.03, 0.0],
        'gpt-4o' => ['GPT-4o', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-2024-05-13' => ['GPT-4o (2024-05-13)', 128_000, 4_096, false, true, 5.0, 15.0, 0.0, 0.0],
        'gpt-4o-2024-08-06' => ['GPT-4o (2024-08-06)', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-2024-11-20' => ['GPT-4o (2024-11-20)', 128_000, 16_384, false, true, 2.5, 10.0, 1.25, 0.0],
        'gpt-4o-mini' => ['GPT-4o mini', 128_000, 16_384, false, true, 0.15, 0.6, 0.08, 0.0],
        'gpt-5' => ['GPT-5', 400_000, 128_000, true, true, 1.25, 10.0, 0.13, 0.0],
        'gpt-5-chat-latest' => ['GPT-5 Chat Latest', 128_000, 16_384, false, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5-codex' => ['GPT-5-Codex', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5-mini' => ['GPT-5 Mini', 400_000, 128_000, true, true, 0.25, 2.0, 0.03, 0.0],
        'gpt-5-nano' => ['GPT-5 Nano', 400_000, 128_000, true, true, 0.05, 0.4, 0.01, 0.0],
        'gpt-5-pro' => ['GPT-5 Pro', 400_000, 272_000, true, true, 15.0, 120.0, 0.0, 0.0],
        'gpt-5.1' => ['GPT-5.1', 400_000, 128_000, true, true, 1.25, 10.0, 0.13, 0.0],
        'gpt-5.1-chat-latest' => ['GPT-5.1 Chat', 128_000, 16_384, true, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5.1-codex' => ['GPT-5.1 Codex', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5.1-codex-max' => ['GPT-5.1 Codex Max', 400_000, 128_000, true, true, 1.25, 10.0, 0.125, 0.0],
        'gpt-5.1-codex-mini' => ['GPT-5.1 Codex mini', 400_000, 128_000, true, true, 0.25, 2.0, 0.025, 0.0],
        'gpt-5.2' => ['GPT-5.2', 400_000, 128_000, true, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.2-chat-latest' => ['GPT-5.2 Chat', 128_000, 16_384, true, true, 1.75, 14.0, 0.175, 0.0],
        'gpt-5.2-pro' => ['GPT-5.2 Pro', 400_000, 128_000, true, true, 21.0, 168.0, 0.0, 0.0],
        'o1' => ['o1', 200_000, 100_000, true, true, 15.0, 60.0, 7.5, 0.0],
        'o1-pro' => ['o1-pro', 200_000, 100_000, true, true, 150.0, 600.0, 0.0, 0.0],
        'o3' => ['o3', 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0],
        'o3-deep-research' => ['o3-deep-research', 200_000, 100_000, true, true, 10.0, 40.0, 2.5, 0.0],
        'o3-mini' => ['o3-mini', 200_000, 100_000, true, false, 1.1, 4.4, 0.55, 0.0],
        'o3-pro' => ['o3-pro', 200_000, 100_000, true, true, 20.0, 80.0, 0.0, 0.0],
        'o4-mini' => ['o4-mini', 200_000, 100_000, true, true, 1.1, 4.4, 0.28, 0.0],
        'o4-mini-deep-research' => ['o4-mini-deep-research', 200_000, 100_000, true, true, 2.0, 8.0, 0.5, 0.0],
    ];

    /**
     * Google's, on the Generative Language API.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: float, 6: float, 7: float, 8: float}>
     */
    private const array GOOGLE_MODELS = [
        'gemini-1.5-flash' => ['Gemini 1.5 Flash', 1_000_000, 8_192, false, true, 0.075, 0.3, 0.01875, 0.0],
        'gemini-1.5-flash-8b' => ['Gemini 1.5 Flash-8B', 1_000_000, 8_192, false, true, 0.0375, 0.15, 0.01, 0.0],
        'gemini-1.5-pro' => ['Gemini 1.5 Pro', 1_000_000, 8_192, false, true, 1.25, 5.0, 0.3125, 0.0],
        'gemini-2.0-flash' => ['Gemini 2.0 Flash', 1_048_576, 8_192, false, true, 0.1, 0.4, 0.025, 0.0],
        'gemini-2.0-flash-lite' => ['Gemini 2.0 Flash Lite', 1_048_576, 8_192, false, true, 0.075, 0.3, 0.0, 0.0],
        'gemini-2.5-flash' => ['Gemini 2.5 Flash', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.075, 0.0],
        'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash Lite', 1_048_576, 65_536, true, true, 0.1, 0.4, 0.025, 0.0],
        'gemini-2.5-flash-lite-preview-06-17' => ['Gemini 2.5 Flash Lite Preview 06-17', 1_048_576, 65_536, true, true, 0.1, 0.4, 0.025, 0.0],
        'gemini-2.5-flash-lite-preview-09-2025' => ['Gemini 2.5 Flash Lite Preview 09-25', 1_048_576, 65_536, true, true, 0.1, 0.4, 0.025, 0.0],
        'gemini-2.5-flash-preview-04-17' => ['Gemini 2.5 Flash Preview 04-17', 1_048_576, 65_536, true, true, 0.15, 0.6, 0.0375, 0.0],
        'gemini-2.5-flash-preview-05-20' => ['Gemini 2.5 Flash Preview 05-20', 1_048_576, 65_536, true, true, 0.15, 0.6, 0.0375, 0.0],
        'gemini-2.5-flash-preview-09-2025' => ['Gemini 2.5 Flash Preview 09-25', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.075, 0.0],
        'gemini-2.5-pro' => ['Gemini 2.5 Pro', 1_048_576, 65_536, true, true, 1.25, 10.0, 0.31, 0.0],
        'gemini-2.5-pro-preview-05-06' => ['Gemini 2.5 Pro Preview 05-06', 1_048_576, 65_536, true, true, 1.25, 10.0, 0.31, 0.0],
        'gemini-2.5-pro-preview-06-05' => ['Gemini 2.5 Pro Preview 06-05', 1_048_576, 65_536, true, true, 1.25, 10.0, 0.31, 0.0],
        'gemini-3-flash-preview' => ['Gemini 3 Flash Preview', 1_048_576, 65_536, true, true, 0.5, 3.0, 0.05, 0.0],
        'gemini-3-pro-preview' => ['Gemini 3 Pro Preview', 1_000_000, 64_000, true, true, 2.0, 12.0, 0.2, 0.0],
        'gemini-flash-latest' => ['Gemini Flash Latest', 1_048_576, 65_536, true, true, 0.3, 2.5, 0.075, 0.0],
        'gemini-flash-lite-latest' => ['Gemini Flash-Lite Latest', 1_048_576, 65_536, true, true, 0.1, 0.4, 0.025, 0.0],
        'gemini-live-2.5-flash' => ['Gemini Live 2.5 Flash', 128_000, 8_000, true, true, 0.5, 2.0, 0.0, 0.0],
        'gemini-live-2.5-flash-preview-native-audio' => ['Gemini Live 2.5 Flash Preview Native Audio', 131_072, 65_536, true, false, 0.5, 2.0, 0.0, 0.0],
    ];

    /**
     * id => [name, which API it speaks, context window, max tokens, reasoning, images]
     *
     * A column for the API, which none of the other tables needs: Copilot serves ten of these
     * through the completions shape and nine through the Responses one, and which it is is a
     * fact about the model rather than about the provider.
     *
     * **No pricing column.** Copilot is a subscription, so upstream's table is zeroes all the
     * way across and so is this — `/session` says $0.00 for a Copilot conversation, which is
     * the truth and not a number nobody filled in.
     *
     * @var array<string, array{0: string, 1: Api, 2: int, 3: int, 4: bool, 5: bool}>
     */
    private const array COPILOT_MODELS = [
        'claude-haiku-4.5'       => ['Claude Haiku 4.5', Api::OpenAiCompletions, 128_000, 16_000, true, true],
        'claude-opus-4.5'        => ['Claude Opus 4.5', Api::OpenAiCompletions, 128_000, 16_000, true, true],
        'claude-sonnet-4'        => ['Claude Sonnet 4', Api::OpenAiCompletions, 128_000, 16_000, true, true],
        'claude-sonnet-4.5'      => ['Claude Sonnet 4.5', Api::OpenAiCompletions, 128_000, 16_000, true, true],
        'gemini-2.5-pro'         => ['Gemini 2.5 Pro', Api::OpenAiCompletions, 128_000, 64_000, false, true],
        'gemini-3-flash-preview' => ['Gemini 3 Flash', Api::OpenAiCompletions, 128_000, 64_000, true, true],
        'gemini-3-pro-preview'   => ['Gemini 3 Pro Preview', Api::OpenAiCompletions, 128_000, 64_000, true, true],
        'gpt-4.1'                => ['GPT-4.1', Api::OpenAiCompletions, 128_000, 16_384, false, true],
        'gpt-4o'                 => ['GPT-4o', Api::OpenAiCompletions, 64_000, 16_384, false, true],
        'gpt-5'                  => ['GPT-5', Api::OpenAiResponses, 128_000, 128_000, true, true],
        'gpt-5-codex'            => ['GPT-5-Codex', Api::OpenAiResponses, 128_000, 128_000, true, true],
        'gpt-5-mini'             => ['GPT-5-mini', Api::OpenAiResponses, 128_000, 64_000, true, true],
        'gpt-5.1'                => ['GPT-5.1', Api::OpenAiResponses, 128_000, 128_000, true, true],
        'gpt-5.1-codex'          => ['GPT-5.1-Codex', Api::OpenAiResponses, 128_000, 128_000, true, true],
        'gpt-5.1-codex-max'      => ['GPT-5.1-Codex-max', Api::OpenAiResponses, 128_000, 128_000, true, true],
        'gpt-5.1-codex-mini'     => ['GPT-5.1-Codex-mini', Api::OpenAiResponses, 128_000, 100_000, true, true],
        'gpt-5.2'                => ['GPT-5.2', Api::OpenAiResponses, 128_000, 64_000, true, true],
        'grok-code-fast-1'       => ['Grok Code Fast 1', Api::OpenAiCompletions, 128_000, 64_000, true, false],
        'oswe-vscode-prime'      => ['Raptor Mini (Preview)', Api::OpenAiResponses, 200_000, 64_000, true, true],
    ];

    /** @var array<string, Model>|null built once, on the first lookup that needs it */
    private static ?array $models = null;

    /**
     * @var list<Model> declared somewhere else and handed over — see `register()`
     */
    private static array $registered = [];

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
     * @param list<Model> $models
     */
    public static function register(array $models): void
    {
        self::$registered = [...self::$registered, ...$models];
        self::$models = null;
    }

    /** Forget what `register()` added. For tests, which must not leak models into each other. */
    public static function forgetRegistered(): void
    {
        self::$registered = [];
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
        return in_array($provider, self::RESOLD, true);
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
        $input = $model->pricing->input / 1_000_000 * $usage->input;
        $output = $model->pricing->output / 1_000_000 * $usage->output;
        $cacheRead = $model->pricing->cacheRead / 1_000_000 * $usage->cacheRead;
        $cacheWrite = $model->pricing->cacheWrite / 1_000_000 * $usage->cacheWrite;

        return new Cost($input, $output, $cacheRead, $cacheWrite, $input + $output + $cacheRead + $cacheWrite);
    }

    /** @return array<string, Model> keyed by "provider/id", which is unique by construction */
    private static function table(): array
    {
        if (self::$models !== null) {
            return self::$models;
        }

        $models = [];

        foreach (self::ANTHROPIC_MODELS as $id => [$name, $window, $maxTokens, $reasoning, $in, $out, $read, $write]) {
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
                new Pricing($in, $out, $read, $write),
            );
        }

        foreach (self::OPENAI_MODELS as $id => [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write]) {
            $models['openai/' . $id] = new Model(
                $id,
                $name,
                Api::OpenAiResponses,
                'openai',
                'https://api.openai.com/v1',
                $window,
                $maxTokens,
                $reasoning,
                $images ? ['text', 'image'] : ['text'],
                new Pricing($in, $out, $read, $write),
            );
        }

        foreach (self::GOOGLE_MODELS as $id => [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write]) {
            $models['google/' . $id] = new Model(
                $id,
                $name,
                Api::GoogleGenerativeAi,
                'google',
                'https://generativelanguage.googleapis.com/v1beta',
                $window,
                $maxTokens,
                $reasoning,
                $images ? ['text', 'image'] : ['text'],
                new Pricing($in, $out, $read, $write),
            );
        }

        $compatible = [
            'cerebras' => self::CEREBRAS_MODELS,
            'groq' => self::GROQ_MODELS,
            'mistral' => self::MISTRAL_MODELS,
            'xai' => self::XAI_MODELS,
            'zai' => self::ZAI_MODELS,
        ];

        foreach ($compatible as $provider => $table) {
            foreach ($table as $id => [$name, $window, $maxTokens, $reasoning, $images, $in, $out, $read, $write]) {
                $models[$provider . '/' . $id] = new Model(
                    $id,
                    $name,
                    Api::OpenAiCompletions,
                    $provider,
                    self::OPENAI_COMPATIBLE[$provider],
                    $window,
                    $maxTokens,
                    $reasoning,
                    $images ? ['text', 'image'] : ['text'],
                    new Pricing($in, $out, $read, $write),
                );
            }
        }

        // Last, so the table reads direct providers first — which is not what decides a bare
        // id (`RESOLD` is), but does decide the order `--models` and `/model` list them in.
        foreach (self::COPILOT_MODELS as $id => [$name, $api, $window, $maxTokens, $reasoning, $images]) {
            $models[self::COPILOT . '/' . $id] = new Model(
                $id,
                $name,
                $api,
                self::COPILOT,
                self::COPILOT_BASE_URL,
                $window,
                $maxTokens,
                $reasoning,
                $images ? ['text', 'image'] : ['text'],
                new Pricing(),
                self::COPILOT_HEADERS,
                $api === Api::OpenAiCompletions ? self::copilotCompat() : null,
            );
        }

        foreach (self::GEMINI_CLI_MODELS as $id => [$name, $window, $maxTokens, $reasoning]) {
            $models[self::GEMINI_CLI . '/' . $id] = new Model(
                $id,
                $name,
                Api::GoogleGeminiCli,
                self::GEMINI_CLI,
                GoogleGeminiCli::ENDPOINT,
                $window,
                $maxTokens,
                $reasoning,
                ['text', 'image'],
                new Pricing(),
            );
        }

        foreach (self::ANTIGRAVITY_MODELS as $id => [$name, $window, $maxTokens, $reasoning, $images]) {
            $models[self::ANTIGRAVITY . '/' . $id] = new Model(
                $id,
                $name,
                // The same protocol as Gemini CLI — the envelope, the endpoint path and the
                // chunk shape are identical. What differs is the deployment it is sent to and
                // the `User-Agent` that deployment insists on, both of which travel with the
                // model rather than with the code.
                Api::GoogleGeminiCli,
                self::ANTIGRAVITY,
                GoogleGeminiCli::SANDBOX_ENDPOINT,
                $window,
                $maxTokens,
                $reasoning,
                $images ? ['text', 'image'] : ['text'],
                new Pricing(),
            );
        }

        // Last, and only where nothing is already: see `register()`. A built-in wins.
        foreach (self::$registered as $model) {
            $models[$model->provider . '/' . $model->id] ??= $model;
        }

        return self::$models = $models;
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
    private static function copilotCompat(): OpenAiCompat
    {
        return new OpenAiCompat(store: false, developerRole: false, reasoningEffort: false);
    }
}
