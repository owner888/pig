<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Model;
use Pig\Ai\Models;

/**
 * Turning what someone typed into a model.
 *
 * `--model sonnet` has to mean something, or the flag is a list of twenty-one ids nobody
 * remembers. So a pattern matches on id or name, and when several match, the alias beats
 * the dated build behind it: someone who types `sonnet` wants the current one, not the
 * one from June 2024 that happens to sort first.
 *
 * `:high` on the end sets the thinking level in the same breath, which is the only place
 * a person wants to say it — at the moment they choose the model.
 *
 * Ported from upstream's `core/model-resolver.ts`. Not ported: the glob patterns and
 * multi-model scopes (`--model 'anthropic/*:high'`), which exist for running several
 * models against one task; PHP's `fnmatch()` is the whole of what `minimatch` was doing
 * there, so they are rows of work rather than a dependency when something wants them.
 */
final class ModelResolver
{
    /**
     * What a pattern resolves to, or null when nothing matches.
     *
     * @param list<Model>|null $available defaults to every model there is
     */
    public static function parse(string $pattern, ?array $available = null): ?ModelChoice
    {
        $available ??= Models::all();

        $model = self::match($pattern, $available);

        if ($model !== null) {
            return new ModelChoice($model, ThinkingLevel::Off);
        }

        // No match. An id can itself contain a colon — OpenRouter's `:exacto` — so the
        // pattern is only split once the whole of it has been tried.
        $colon = strrpos($pattern, ':');

        if ($colon === false) {
            return null;
        }

        $prefix = substr($pattern, 0, $colon);
        $suffix = substr($pattern, $colon + 1);
        $level = ThinkingLevel::tryFrom($suffix);
        $choice = self::parse($prefix, $available);

        if ($choice === null) {
            return null;
        }

        if ($level !== null) {
            // A warning deeper in means a suffix was already thrown away, and stacking a
            // level on top of that would be guessing at what was meant.
            return $choice->warning !== null
                ? $choice
                : new ModelChoice($choice->model, $level);
        }

        return new ModelChoice(
            $choice->model,
            ThinkingLevel::Off,
            "No thinking level called \"{$suffix}\" in \"{$pattern}\". Using \"off\".",
        );
    }

    /**
     * The best model for a pattern.
     *
     * @param list<Model> $available
     */
    private static function match(string $pattern, array $available): ?Model
    {
        // Nothing matches nothing. Without this, `--model ""` substring-matches every
        // model and quietly picks one, which is money spent on a model nobody named.
        if ($pattern === '') {
            return null;
        }

        $wanted = strtolower($pattern);
        $slash = strpos($pattern, '/');

        if ($slash !== false) {
            $provider = strtolower(substr($pattern, 0, $slash));
            $id = strtolower(substr($pattern, $slash + 1));

            foreach ($available as $model) {
                if (strtolower($model->provider) === $provider && strtolower($model->id) === $id) {
                    return $model;
                }
            }
        }

        foreach ($available as $model) {
            if (strtolower($model->id) === $wanted) {
                return $model;
            }
        }

        $aliases = $dated = [];

        foreach ($available as $model) {
            if (str_contains(strtolower($model->id), $wanted) || str_contains(strtolower($model->name), $wanted)) {
                if (self::isAlias($model->id)) {
                    $aliases[] = $model;
                } else {
                    $dated[] = $model;
                }
            }
        }

        // The alias wins, and among aliases the one that sorts highest: `sonnet` means
        // the current Sonnet, not whichever one happens to come first alphabetically.
        $candidates = $aliases !== [] ? $aliases : $dated;

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (Model $a, Model $b): int => strcmp($b->id, $a->id));

        return $candidates[0];
    }

    /** An id with no date on the end points at whatever is current. */
    private static function isAlias(string $id): bool
    {
        return str_ends_with($id, '-latest') || preg_match('/-\d{8}$/', $id) !== 1;
    }
}
