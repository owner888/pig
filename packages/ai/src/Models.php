<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Every model this can talk to, by provider and id.
 *
 * Upstream generates `models.generated.ts` from models.dev: 7105 lines, 414 models,
 * twelve providers. Here are the ones whose protocol is ported — Anthropic's 21, OpenAI's
 * own 33 on the Responses API, and the 72 across five providers that speak
 * `openai-completions`. A model that could be selected and then not talked to is a worse
 * answer than "no such model", so the rest arrive with their protocols. OpenRouter's 236
 * speak a ported protocol and are still left out: that list is a directory of everyone
 * else's models and goes stale fastest, and GitHub Copilot's 19 need an OAuth device flow
 * that is not ported.
 *
 * The figures are upstream's at the anchor commit, which is the source a port should
 * agree with rather than whatever models.dev says today.
 *
 * Adding a provider is adding a table and one line in `table()`, not changing the rest.
 *
 * Ported from upstream's `models.ts` plus the matching slices of `models.generated.ts`.
 */
final class Models
{
    public const string ANTHROPIC = 'anthropic';

    private const string ANTHROPIC_BASE_URL = 'https://api.anthropic.com';

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

    /** @var array<string, Model>|null built once, on the first lookup that needs it */
    private static ?array $models = null;

    /**
     * One model by id, or null when there is no such model.
     *
     * Ids are unique across the providers here, checked when each table was added, so an
     * id alone is enough to name a model. `find()` is the one to use when it might not
     * be — after another provider's table lands, say.
     */
    public static function get(string $id): ?Model
    {
        foreach (self::table() as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        return null;
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

        return self::$models = $models;
    }
}
