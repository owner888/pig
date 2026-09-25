<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Utils\Fuzzy;

/**
 * `--models`, and `--models <search>`.
 *
 * Upstream's `cli/list-models.ts`. Here rather than inline in `bin/pig` for the reason
 * `Arguments` gives: a script that calls `exit()` is not something a test can call twice. So
 * this hands back a string and `bin/pig` prints it.
 *
 * **Six columns, not three.** The point of the listing is to find the id to pass to `--model`,
 * and the three questions asked about a model right after finding it are how much it holds, how
 * much it can say, and whether it can think — so upstream puts them in the table and so does
 * this. The human name is gone with them: `Claude Sonnet 4.5` beside `claude-sonnet-4-5` is the
 * same string twice, and the one case where it said something the id did not — a model resold
 * under somebody else's id — is already answered by the provider column.
 *
 * Widths are measured, not guessed: the widest value in each column decides it, which is what
 * keeps `google-generative-ai` from pushing every row after it out of line.
 */
final class ModelList
{
    /**
     * The table, or a line saying why there is none.
     *
     * @param string $search fuzzy, over "provider id" — empty lists everything
     * @param Auth|null $auth the keys, so the listing is of models that can actually be talked
     *                        to — upstream's `listModels()` lists `getAvailable()`, and the point
     *                        of the table is to find an id to pass to `--model`. Null lists every
     *                        model there is, which is what a test wants and nothing else does.
     */
    public static function render(string $search = '', ?Auth $auth = null): string
    {
        $models = $auth?->availableModels() ?? Models::all();

        if ($models === []) {
            return $auth === null
                ? 'No models in the registry.'
                : 'No model has a key here. Sign in with `pig-ai login`, or set a provider key in the environment.';
        }

        $matched = $search === ''
            ? $models
            : Fuzzy::filter($models, $search, static fn (Model $m): string => "{$m->provider} {$m->id}");

        if ($matched === []) {
            return "No models matching \"{$search}\".";
        }

        // Provider then id. `Fuzzy::filter` hands back its own order — best match first — and
        // that is the wrong order for reading a table: a listing is scanned by provider.
        usort($matched, static fn (Model $a, Model $b): int => [$a->provider, $a->id] <=> [$b->provider, $b->id]);

        $rows = [['provider', 'model', 'context', 'max-out', 'thinking', 'images']];

        foreach ($matched as $model) {
            $rows[] = [
                $model->provider,
                $model->id,
                self::tokens($model->contextWindow),
                self::tokens($model->maxTokens),
                $model->reasoning ? 'yes' : 'no',
                in_array('image', $model->input, true) ? 'yes' : 'no',
            ];
        }

        $widths = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($value));
            }
        }

        $lines = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row as $column => $value) {
                $cells[] = str_pad($value, $widths[$column]);
            }

            // The last column is padded like the others and then trimmed off, so no row ends
            // in a run of spaces — which shows up the moment anybody pipes this into a diff.
            $lines[] = rtrim(implode('  ', $cells));
        }

        return implode("\n", $lines);
    }

    /** 200000 as `200K`, 1000000 as `1M`, 1500000 as `1.5M`. */
    private static function tokens(int $count): string
    {
        foreach ([1_000_000 => 'M', 1_000 => 'K'] as $unit => $suffix) {
            if ($count >= $unit) {
                // A whole number keeps no decimal: `200K`, not `200.0K`. Asked with integer
                // modulo rather than `$scaled === floor($scaled)`, which reads right and is
                // always false: PHP's `/` hands back an **int** when it divides evenly, and
                // `floor()` always hands back a float, so `200 === 200.0` compares an int to
                // a float and says no. Every round number came out as `200.0K`.
                return ($count % $unit === 0
                    ? (string) intdiv($count, $unit)
                    : number_format($count / $unit, 1)) . $suffix;
            }
        }

        return (string) $count;
    }
}
