<?php

declare(strict_types=1);

namespace Pig\Test;

use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;

/**
 * A small family of models a test owns, so that resolution can be tested without the catalogue.
 *
 * **Why this exists.** `Ai\Models`' rows are generated from models.dev now, and the first
 * regeneration turned thirty-three tests red without a single thing being wrong with pig: they
 * asserted that `sonnet` resolves to `claude-sonnet-4-5`, that `opus-4-1` resolves at all, that
 * Copilot resells `gpt-5`. Every one of those is a fact about what Anthropic, OpenAI and GitHub
 * were selling on the day it was written. A test that names a live model id is a test with an
 * expiry date, and the rules under test — an alias beats the dated build behind it, a bare id
 * means the direct provider, a colon sets the thinking level — have nothing to do with which
 * models exist.
 *
 * So a test that needs *a* model with some property registers these instead. The ids all begin
 * `zzp-`, which no real id or display name contains, so substring matching cannot reach past them
 * into the real table and the real table cannot reach into them.
 *
 * They go under **real providers**, deliberately: `Models::providers()` is a hand-written list of
 * the protocols that are ported, and a made-up provider in the table would be a model nothing
 * knows how to authenticate — which `ModelsTest` asserts against.
 *
 * Whoever registers must forget: `Models::register()` is static, because `Models` is, and a
 * registration that outlives its test is a model the next one can see.
 */
trait ProbeModels
{
    /** The family, registered. Call `Models::forgetRegistered()` in `tearDown`. */
    protected static function registerProbeModels(): void
    {
        Models::register([
            // An alias and two dated builds behind it: `zzp-alpha` must win over both.
            self::probe('anthropic', 'zzp-alpha', 'Probe Alpha'),
            self::probe('anthropic', 'zzp-alpha-20240101', 'Probe Alpha (2024-01-01)'),
            self::probe('anthropic', 'zzp-alpha-20250101', 'Probe Alpha (2025-01-01)'),

            // Dated builds and no alias: the newest wins.
            self::probe('anthropic', 'zzp-beta-20240101', 'Probe Beta (2024-01-01)'),
            self::probe('anthropic', 'zzp-beta-20250101', 'Probe Beta (2025-01-01)'),

            // A model that cannot reason, for the clamping rules.
            self::probe('anthropic', 'zzp-plain', 'Probe Plain', reasoning: false),

            // One id two providers claim, which is what `RESOLD` arbitrates, and one only the
            // reseller has, which is the fallback in `get()`.
            self::probe('openai', 'zzp-shared', 'Probe Shared', api: Api::OpenAiResponses),
            self::probe('github-copilot', 'zzp-shared', 'Probe Shared (resold)', api: Api::OpenAiResponses),
            self::probe('github-copilot', 'zzp-only', 'Probe Only Resold', api: Api::OpenAiResponses),
        ]);
    }

    /** The three the cycling tests walk, in a known order. */
    protected static function probeThree(): array
    {
        return [
            Models::find('anthropic', 'zzp-alpha') ?? throw new \RuntimeException('no zzp-alpha'),
            Models::find('anthropic', 'zzp-beta-20250101') ?? throw new \RuntimeException('no zzp-beta'),
            Models::find('anthropic', 'zzp-plain') ?? throw new \RuntimeException('no zzp-plain'),
        ];
    }

    private static function probe(
        string $provider,
        string $id,
        string $name,
        bool $reasoning = true,
        Api $api = Api::AnthropicMessages,
    ): Model {
        return new Model(
            $id,
            $name,
            $api,
            $provider,
            'https://probe.invalid/v1',
            200_000,
            64_000,
            $reasoning,
            ['text'],
            new Pricing(1.0, 2.0, 0.1, 0.5),
        );
    }
}
