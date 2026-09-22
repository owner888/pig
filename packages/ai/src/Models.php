<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Every model this can talk to, by id.
 *
 * Upstream generates `models.generated.ts` from models.dev: 7105 lines, 414 models,
 * twelve providers. Only the Anthropic provider is ported, so only Anthropic's models
 * are here — the other 393 could be selected and then not talked to, which is a worse
 * answer than "no such model". The figures are upstream's at the anchor commit, which
 * is the source a port should agree with rather than whatever models.dev says today.
 *
 * Adding a provider is adding rows and a `use` of its own table, not changing this class.
 *
 * Ported from upstream's `models.ts` plus the Anthropic slice of `models.generated.ts`.
 */
final class Models
{
    public const string ANTHROPIC = 'anthropic';

    private const string ANTHROPIC_BASE_URL = 'https://api.anthropic.com';

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

    /** @var array<string, Model>|null built once, on the first lookup that needs it */
    private static ?array $models = null;

    /**
     * One model by id, or null when there is no such model.
     *
     * The id alone, not provider and id: two providers serving the same id is a problem
     * this has not got yet, and `find()` takes a provider for when it does.
     */
    public static function get(string $id): ?Model
    {
        return self::table()[$id] ?? null;
    }

    public static function find(string $provider, string $id): ?Model
    {
        $model = self::get($id);

        return $model?->provider === $provider ? $model : null;
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

    /** @return array<string, Model> */
    private static function table(): array
    {
        if (self::$models !== null) {
            return self::$models;
        }

        $models = [];

        foreach (self::ANTHROPIC_MODELS as $id => [$name, $window, $maxTokens, $reasoning, $in, $out, $read, $write]) {
            $models[$id] = new Model(
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

        return self::$models = $models;
    }
}
