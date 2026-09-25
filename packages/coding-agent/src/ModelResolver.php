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
     * The next model along, wrapping round at either end.
     *
     * Upstream's `cycleModel()` is what ctrl+p and shift+ctrl+p do, and it is the model half of
     * `cycleThinkingLevel()`: a keystroke to move along the list rather than a picker to open.
     * Here rather than on `AgentSession` because everything else it needs is already done for it
     * — `setModel()` checks the key, writes the file and remembers the choice — so what is left
     * is which model comes next, and that is arithmetic over a list that both the terminal and
     * RPC have to do the same way.
     *
     * Null when there is nowhere to go: one model, or none. A current model that is not in the
     * list at all — pinned with `--model` for a provider whose key has since gone — counts as
     * being at the start, which is upstream's rule and means ctrl+p from there lands on the
     * *second* model in the list.
     *
     * @param list<Model> $available in the registry's order, which is what the list shows
     */
    public static function next(array $available, ?Model $current, bool $backward = false): ?Model
    {
        $count = count($available);

        if ($count <= 1) {
            return null;
        }

        $at = 0;

        foreach ($available as $index => $model) {
            if ($current !== null && $model->is($current)) {
                $at = $index;

                break;
            }
        }

        return $available[($at + ($backward ? -1 : 1) + $count) % $count];
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

        // An exact id, and `RESOLD` decides which of two providers claiming it is meant:
        // `gpt-5` is OpenAI's, and Copilot's is `github-copilot/gpt-5` above. A resold match
        // is kept rather than skipped, because some of Copilot's ids are only Copilot's.
        $resold = null;

        foreach ($available as $model) {
            if (strtolower($model->id) !== $wanted) {
                continue;
            }

            if (!Models::isResold($model->provider)) {
                return $model;
            }

            $resold ??= $model;
        }

        if ($resold !== null) {
            return $resold;
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

        // Direct before resold first, for the same reason the exact pass prefers it: `codex`
        // matching both OpenAI's and Copilot's should mean the one an API key reaches.
        usort($candidates, static function (Model $a, Model $b): int {
            $resold = (int) Models::isResold($a->provider) <=> (int) Models::isResold($b->provider);

            return $resold !== 0 ? $resold : strcmp($b->id, $a->id);
        });

        return $candidates[0];
    }

    /** An id with no date on the end points at whatever is current. */
    private static function isAlias(string $id): bool
    {
        return str_ends_with($id, '-latest') || preg_match('/-\d{8}$/', $id) !== 1;
    }
}
