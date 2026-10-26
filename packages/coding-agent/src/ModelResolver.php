<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
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
 * Ported from upstream's `core/model-resolver.ts`, `resolveModelScope()` included — see
 * `scope()`. `fnmatch()` is the whole of what `minimatch` was doing there, which is why that
 * half was rows of work rather than a dependency.
 */
final class ModelResolver
{
    /**
     * Upstream's `defaultModelPerProvider`: the model `/login` switches to when there is none yet.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_MODEL_PER_PROVIDER = [
        'amazon-bedrock' => 'us.anthropic.claude-opus-4-6-v1',
        'ant-ling' => 'Ring-2.6-1T',
        'anthropic' => 'claude-opus-4-8',
        'openai' => 'gpt-5.5',
        'azure' => 'gpt-5.4',
        'openai-codex' => 'gpt-6.1-sol',
        'radius' => 'balanced',
        'nvidia' => 'nvidia/nemotron-3.5-super-vl-120b-a12b',
        'deepseek' => 'deepseek-v4-pro',
        'google' => 'gemini-3.1-pro-preview',
        'google-vertex' => 'gemini-3.1-pro-preview',
        'github-copilot' => 'gpt-6.1-sol',
        'openrouter' => 'moonshotai/kimi-k2.6',
        'vercel-ai-gateway' => 'zai/glm-5.1',
        'xai' => 'grok-4.7',
        'groq' => 'openai/gpt-oss-120b',
        'cerebras' => 'gpt-oss-120b',
        'zai' => 'glm-5.3',
        'zai-coding-cn' => 'glm-5.3',
        'mistral' => 'devstral-medium-latest',
        'minimax' => 'MiniMax-M2.7',
        'minimax-cn' => 'MiniMax-M2.7',
        'moonshotai' => 'kimi-k2.6',
        'moonshotai-cn' => 'kimi-k2.6',
        'huggingface' => 'moonshotai/Kimi-K2.6',
        'fireworks' => 'accounts/fireworks/models/kimi-k3',
        'together' => 'moonshotai/Kimi-K3',
        'baseten' => 'zai-org/GLM-5.2',
        'opencode' => 'kimi-k2.6',
        'opencode-go' => 'kimi-k3',
        'kimi-coding' => 'kimi-for-coding',
        'meta' => 'muse-spark-1.3',
        'cloudflare-workers-ai' => '@cf/moonshotai/kimi-k2.6',
        'cloudflare-ai-gateway' => 'workers-ai/@cf/moonshotai/kimi-k2.6',
        'qwen-token-plan' => 'qwen3.7-max',
        'qwen-token-plan-cn' => 'qwen3.7-max',
        'qwen-token-plan-individual' => 'qwen3.8-max',
        'xiaomi' => 'mimo-v2.6-pro',
        'xiaomi-token-plan-cn' => 'mimo-v2.6-pro',
        'xiaomi-token-plan-ams' => 'mimo-v2.6-pro',
        'xiaomi-token-plan-sgp' => 'mimo-v2.6-pro',
    ];

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
                : new ModelChoice($choice->model, $level, explicitThinking: true);
        }

        return new ModelChoice(
            $choice->model,
            ThinkingLevel::Off,
            "No thinking level called \"{$suffix}\" in \"{$pattern}\". Using \"off\".",
        );
    }

    /**
     * What `--models sonnet:high,'anthropic/*'` and `enabledModels` narrow the session to —
     * upstream's `resolveModelScopeFromModels()`.
     *
     * "Every model in `models` is usable, so credentials cannot choose between providers offering the
     * same id. All of them are added, with a warning so the user can pick one with `provider/id`."
     * A pattern is a glob when it holds `*`, `?` or `[` — unless it is an id as it stands ("Ids such as
     * `bracketed-model[1m]` are not globs"); a glob is matched against `provider/id` and the bare id,
     * case-insensitively. Anything else goes through `reference()`. A pattern that matches nothing
     * is a warning, not a refusal.
     *
     * @param list<string> $patterns already split on commas by whoever read the flag
     * @param list<Model>  $available the models a key actually reaches
     * @return array{0: list<ModelChoice>, 1: list<string>} the scope in the order given, and the warnings
     */
    public static function scope(array $patterns, array $available): array
    {
        $scope = [];
        $warnings = [];
        $everyone = static fn (): bool => true;
        $add = static function (Model $model, ?ThinkingLevel $thinking) use (&$scope): void {
            foreach ($scope as $already) {
                if ($already->model->is($model)) {
                    return;
                }
            }

            $scope[] = new ModelChoice($model, $thinking ?? ThinkingLevel::Off, explicitThinking: $thinking !== null);
        };
        $ambiguous = static function (string $pattern, array $candidates) use (&$warnings): void {
            $providers = array_values(array_unique(array_map(static fn (Model $model): string => $model->provider, $candidates)));
            sort($providers);
            $warnings[] = "Pattern \"{$pattern}\" matches several providers: " . implode(', ', $providers) . '. Use provider/id to choose one.';
        };

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '[')) {
                // Extract optional thinking level suffix (e.g., "provider/*:high")
                $thinking = self::splitThinkingSuffix($pattern);
                $glob = $thinking[0] ?? $pattern;
                $exact = self::reference($glob, $available, $everyone, thinkingSuffix: 'none', fuzzy: false);

                if ($exact->status === ModelReference::RESOLVED && $exact->model !== null) {
                    $matches = [$exact->model];
                } elseif ($exact->status === ModelReference::AMBIGUOUS) {
                    $matches = $exact->candidates;
                    $ambiguous($pattern, $matches);
                } else {
                    $matches = array_values(array_filter($available, static fn (Model $model): bool => fnmatch($glob, "{$model->provider}/{$model->id}", FNM_CASEFOLD)
                        || fnmatch($glob, $model->id, FNM_CASEFOLD)));
                }

                if ($matches === []) {
                    $warnings[] = "No models match pattern \"{$pattern}\"";
                }

                foreach ($matches as $model) {
                    $add($model, $thinking[1] ?? null);
                }

                continue;
            }

            $result = self::reference($pattern, $available, $everyone, thinkingSuffix: 'lenient', fuzzy: true);

            if ($result->status === ModelReference::NOT_FOUND) {
                $warnings[] = "No models match pattern \"{$pattern}\"";

                continue;
            }

            if ($result->warning !== null) {
                $warnings[] = $result->warning;
            }

            if ($result->status === ModelReference::AMBIGUOUS) {
                $ambiguous($pattern, $result->candidates);
            }

            foreach ($result->model !== null ? [$result->model] : $result->candidates as $model) {
                $add($model, $result->thinking);
            }
        }

        return [$scope, $warnings];
    }

    /**
     * One model from `--model` (and `--provider`) — upstream's `resolveCliModel()`.
     *
     * Every model is looked at, not only those with a key, "so `--api-key` can be used for first-time
     * setup"; credentials only break ties between providers offering the match, and "an inline key
     * goes to whichever provider is selected, so stored credentials must not choose it". A
     * `provider/id` the provider does not list becomes a custom model id on that provider, built
     * from its default model.
     *
     * @param list<Model>           $models  every model there is
     * @param Closure(string): bool $hasAuth whether a provider has credentials configured
     * @return array{0: ModelChoice|null, 1: string|null} the choice (its warning set when there is
     *         one), or the error to refuse with
     */
    public static function cli(?string $cliProvider, string $cliModel, ?ThinkingLevel $cliThinking, bool $inlineApiKey, array $models, Closure $hasAuth): array
    {
        if ($models === []) {
            return [null, 'No models available. Check your installation or add models to models.json.'];
        }

        $provider = null;

        if ($cliProvider !== null) {
            foreach ($models as $model) {
                if (strtolower($model->provider) === strtolower($cliProvider)) {
                    $provider = $model->provider;

                    break;
                }
            }

            if ($provider === null) {
                return [null, "Unknown provider \"{$cliProvider}\". Use --list-models to see available providers/models."];
            }
        }

        $result = self::reference($cliModel, $models, $inlineApiKey ? static fn (): bool => false : $hasAuth, $provider, 'strict', true);

        if ($result->status === ModelReference::RESOLVED && $result->model !== null) {
            return [new ModelChoice($result->model, $result->thinking ?? ThinkingLevel::Off, $result->warning, explicitThinking: $result->thinking !== null), null];
        }

        // pig's own last tie-break, after credentials have had their say: of providers offering the
        // same id, the one that makes the model beats the ones reselling it (`Models::isResold()`) —
        // `--model gpt-5` is OpenAI's, Copilot's is `github-copilot/gpt-5`. Upstream reports these as
        // ambiguous; pig keeps its resold rule as the last word, so only a tie it cannot break is reported.
        if ($result->status === ModelReference::AMBIGUOUS) {
            $direct = array_values(array_filter($result->candidates, static fn (Model $model): bool => !Models::isResold($model->provider)));

            if ($direct !== [] && count(array_unique(array_map(static fn (Model $model): string => $model->provider, $direct))) === 1) {
                return [new ModelChoice($direct[0], $result->thinking ?? ThinkingLevel::Off, $result->warning, explicitThinking: $result->thinking !== null), null];
            }
        }

        if ($result->status === ModelReference::AMBIGUOUS) {
            $matches = array_map(static fn (Model $model): string => "{$model->provider}/{$model->id}", $result->candidates);
            sort($matches);
            $authHint = match (true) {
                $inlineApiKey => '--api-key does not choose a provider.',
                $result->usableCount === 0 => 'No matching provider is authenticated.',
                default => 'More than one matching provider is authenticated.',
            };

            return [null, "Model \"{$cliModel}\" is ambiguous across providers: " . implode(', ', $matches) . ". {$authHint} Use --provider or provider/model."];
        }

        $target = $result->target;

        if ($target === null) {
            return [null, "Model \"{$cliModel}\" not found. Use --list-models to see available models."];
        }

        // "Use the thinking level suffix only when --thinking is not explicitly provided; otherwise
        // it stays part of the custom model id."
        $modelId = $cliThinking !== null ? $target->modelText : $target->modelId;
        $fallbackThinking = $cliThinking !== null ? null : $target->thinking;
        $requested = $cliThinking ?? $fallbackThinking;
        $model = self::fallbackModel($target->providerModels, $modelId, $requested !== null && $requested !== ThinkingLevel::Off);

        return [new ModelChoice(
            $model,
            $fallbackThinking ?? ThinkingLevel::Off,
            "Model \"{$modelId}\" not found for provider \"{$target->provider}\". Using custom model id.",
            explicitThinking: $fallbackThinking !== null,
        ), null];
    }

    /**
     * Upstream's `resolveModelReference()`. "Candidates are collected before a provider is chosen, so
     * catalog order never decides the provider and an ambiguous match is reported instead of falling
     * through to a weaker one." `$usable` "only breaks ties between providers, so a unique match
     * resolves even without credentials".
     *
     * `$thinkingSuffix`: a trailing `:<suffix>` is only considered when the full text matches no
     * model, since ids such as OpenRouter's `qwen/qwen3-coder:exacto` contain colons — `none` keeps
     * it part of the id, `strict` parses a valid level and keeps anything else, `lenient` also drops
     * an invalid one with a warning.
     *
     * @param list<Model>               $models
     * @param Closure(string): bool     $usable
     * @param 'none'|'strict'|'lenient' $thinkingSuffix
     */
    public static function reference(string $text, array $models, Closure $usable, ?string $provider = null, string $thinkingSuffix = 'lenient', bool $fuzzy = true): ModelReference
    {
        $text = trim($text);

        if ($text === '') {
            return ModelReference::notFound();
        }

        $constraint = $provider === null ? null : strtolower($provider);
        $pool = $constraint === null
            ? array_values($models)
            : array_values(array_filter($models, static fn (Model $model): bool => strtolower($model->provider) === $constraint));
        $result = self::resolveText($text, $thinkingSuffix, $fuzzy, $pool, $usable);

        if ($result->status !== ModelReference::NOT_FOUND) {
            return $result;
        }

        $split = self::splitProviderPrefix($text, $pool);
        $anchor = null;

        foreach ($pool as $model) {
            if ($constraint !== null || ($split !== null && $model->provider === $split[0])) {
                $anchor = $model;

                break;
            }
        }

        if ($anchor === null) {
            return $result;
        }

        $modelText = $split[1] ?? $text;
        $suffix = $thinkingSuffix === 'none' ? null : self::splitThinkingSuffix($modelText);
        $providerModels = [$anchor];

        foreach ($pool as $model) {
            if ($model->provider === $anchor->provider && $model !== $anchor) {
                $providerModels[] = $model;
            }
        }

        return ModelReference::notFound(new ModelReferenceTarget($anchor->provider, $providerModels, $modelText, $suffix[0] ?? $modelText, $suffix[1] ?? null));
    }

    /**
     * Upstream's `findExactModelReferenceMatch()`: exact `provider/id` or bare id, without thinking
     * suffixes or fuzzy matching.
     *
     * @param list<Model> $models
     */
    public static function findExact(string $reference, array $models): ?Model
    {
        $result = self::reference($reference, $models, static fn (): bool => true, thinkingSuffix: 'none', fuzzy: false);

        return $result->status === ModelReference::RESOLVED ? $result->model : null;
    }

    /**
     * @param 'none'|'strict'|'lenient' $thinkingSuffix
     * @param list<Model>               $pool
     * @param Closure(string): bool     $usable
     */
    private static function resolveText(string $text, string $thinkingSuffix, bool $fuzzy, array $pool, Closure $usable): ModelReference
    {
        $reference = self::selectReference($text, $pool, $fuzzy, $usable);

        if ($reference !== null) {
            return $reference;
        }

        if ($fuzzy) {
            $match = self::selectFuzzy(self::fuzzyMatches($text, $pool), $usable);

            if ($match !== null) {
                return $match;
            }
        }

        if ($thinkingSuffix === 'none') {
            return ModelReference::notFound();
        }

        $thinking = self::splitThinkingSuffix($text);

        if ($thinking !== null) {
            $inner = self::resolveText($thinking[0], $thinkingSuffix, $fuzzy, $pool, $usable);

            // "Only use this thinking level if no warning from inner recursion"
            return $inner->status === ModelReference::NOT_FOUND ? $inner : $inner->with($inner->warning !== null ? null : $thinking[1], $inner->warning);
        }

        // "Keep an invalid suffix as part of the id and fail, rather than resolve a different model"
        $colon = strrpos($text, ':');

        if ($thinkingSuffix === 'strict' || $colon === false) {
            return ModelReference::notFound();
        }

        $suffix = substr($text, $colon + 1);
        $inner = self::resolveText(substr($text, 0, $colon), $thinkingSuffix, $fuzzy, $pool, $usable);

        return $inner->status === ModelReference::NOT_FOUND
            ? $inner
            : $inner->with(null, "Invalid thinking level \"{$suffix}\" in pattern \"{$text}\". Using default instead.");
    }

    /**
     * Upstream's `selectReference()`: "`provider/rest` is only a hint: the whole text may also be a
     * model id on another provider, such as gateway id `zai/glm-5`. The named provider wins when
     * usable, since that is what the syntax usually means; otherwise a sole usable raw match wins,
     * since the named provider could not serve the request. An unusable named match is still
     * preferred over unusable raw matches, so `--api-key` can set up the named provider."
     *
     * @param list<Model>           $pool
     * @param Closure(string): bool $usable
     */
    private static function selectReference(string $text, array $pool, bool $fuzzy, Closure $usable): ?ModelReference
    {
        $lowerText = strtolower($text);
        $raw = array_values(array_filter($pool, static fn (Model $model): bool => strtolower($model->id) === $lowerText));
        $split = self::splitProviderPrefix($text, $pool);
        $named = null;

        if ($split !== null) {
            $providerModels = array_values(array_filter($pool, static fn (Model $model): bool => $model->provider === $split[0]));
            $lowerModelText = strtolower($split[1]);

            // "The provider's own literal id (OpenRouter's `openrouter/free`) beats its fuzzy matches"
            foreach ($providerModels as $model) {
                if (strtolower($model->id) === $lowerModelText) {
                    $named = $model;

                    break;
                }
            }

            foreach ($named === null ? $raw : [] as $model) {
                if ($model->provider === $split[0]) {
                    $named = $model;

                    break;
                }
            }

            if ($named === null && $fuzzy) {
                $named = self::bestFuzzyMatch(self::fuzzyMatches($split[1], $providerModels));
            }
        }

        $others = array_values(array_filter($raw, static fn (Model $model): bool => $model !== $named));
        $candidates = $named !== null ? [$named, ...$others] : $others;

        if ($candidates === []) {
            return null;
        }

        if (count(array_unique(array_map(static fn (Model $model): string => $model->provider, $candidates))) === 1) {
            return ModelReference::resolved($candidates[0]);
        }

        if ($named !== null && $usable($named->provider)) {
            return ModelReference::resolved($named);
        }

        $usableOthers = array_values(array_filter($others, static fn (Model $model): bool => $usable($model->provider)));

        if (count($usableOthers) === 1) {
            return ModelReference::resolved($usableOthers[0]);
        }

        if ($usableOthers === [] && $named !== null) {
            return ModelReference::resolved($named);
        }

        return ModelReference::ambiguous($candidates, count($usableOthers));
    }

    /**
     * @param list<Model> $models
     * @return list<Model>
     */
    private static function fuzzyMatches(string $text, array $models): array
    {
        $lowerText = strtolower($text);

        return array_values(array_filter($models, static fn (Model $model): bool => str_contains(strtolower($model->id), $lowerText)
            || str_contains(strtolower($model->name), $lowerText)));
    }

    /**
     * @param list<Model>           $matches
     * @param Closure(string): bool $usable
     */
    private static function selectFuzzy(array $matches, Closure $usable): ?ModelReference
    {
        if ($matches === []) {
            return null;
        }

        $multipleProviders = count(array_unique(array_map(static fn (Model $model): string => $model->provider, $matches))) > 1;
        $usableMatches = $multipleProviders ? array_values(array_filter($matches, static fn (Model $model): bool => $usable($model->provider))) : [];
        $pool = $usableMatches !== [] ? $usableMatches : $matches;
        $best = self::bestFuzzyMatch($pool);

        if ($best === null) {
            return null;
        }

        // "The same id on several providers would otherwise be decided by catalog order"
        $tied = array_values(array_filter($pool, static fn (Model $model): bool => $model->id === $best->id));

        if (count(array_unique(array_map(static fn (Model $model): string => $model->provider, $tied))) === 1) {
            return ModelReference::resolved($best);
        }

        return ModelReference::ambiguous($tied, $usableMatches !== [] ? count($tied) : 0);
    }

    /**
     * "Prefer aliases (`claude-sonnet-4-5`) over dated versions (`claude-sonnet-4-5-20250929`), then the latest."
     *
     * @param list<Model> $matches
     */
    private static function bestFuzzyMatch(array $matches): ?Model
    {
        $aliases = array_values(array_filter($matches, static fn (Model $model): bool => self::isAlias($model->id)));
        $pool = $aliases !== [] ? $aliases : $matches;
        usort($pool, static fn (Model $a, Model $b): int => strcmp($b->id, $a->id));

        return $pool[0] ?? null;
    }

    /** @return array{0: string, 1: ThinkingLevel}|null the text before the last `:` and the level after it, when it is one */
    private static function splitThinkingSuffix(string $text): ?array
    {
        $colon = strrpos($text, ':');
        $level = $colon === false ? null : ThinkingLevel::tryFrom(substr($text, $colon + 1));

        return $level === null ? null : [substr($text, 0, (int) $colon), $level];
    }

    /**
     * @param list<Model> $models
     * @return array{0: string, 1: string}|null the provider a `provider/` prefix names, as the models spell it, and the rest
     */
    private static function splitProviderPrefix(string $text, array $models): ?array
    {
        // "Provider ids may contain "/" themselves (e.g. URL-derived ids), so match known ids,
        // longest first": `llama-server=http://host:10800/qwen` split at its first slash named a
        // provider `llama-server=http:` that nothing has.
        $lowerText = strtolower($text);
        $best = null;

        foreach ($models as $model) {
            $prefix = strtolower(trim($model->provider));

            if ($prefix === '' || ($best !== null && strlen($best[0]) >= strlen($model->provider))) {
                continue;
            }

            $slash = strpos($lowerText, '/', min(strlen($prefix), strlen($lowerText)));

            if ($slash === false || trim(substr($lowerText, 0, $slash)) !== $prefix) {
                continue;
            }

            $modelText = trim(substr($text, $slash + 1));

            if ($modelText !== '') {
                $best = [$model->provider, $modelText];
            }
        }

        return $best;
    }

    /**
     * Upstream's `buildFallbackModel()`: a custom id on a provider, shaped like its default model.
     *
     * @param non-empty-list<Model> $providerModels
     */
    private static function fallbackModel(array $providerModels, string $modelId, bool $reasoning): Model
    {
        $defaultId = self::DEFAULT_MODEL_PER_PROVIDER[$providerModels[0]->provider] ?? null;
        $base = $providerModels[0];

        foreach ($defaultId === null ? [] : $providerModels as $model) {
            if ($model->id === $defaultId) {
                $base = $model;

                break;
            }
        }

        return new Model(
            $modelId,
            $modelId,
            $base->api,
            $base->provider,
            $base->baseUrl,
            $base->contextWindow,
            $base->maxTokens,
            $reasoning || $base->reasoning,
            $base->input,
            $base->pricing,
            $base->headers,
            $base->compat,
            $base->thinkingLevelMap,
            $base->inputLimits,
            $base->promptCache,
        );
    }

    /**
     * The next model along, wrapping round at either end.
     *
     * The arithmetic under `AgentSession::cycleModel()`, which is what ctrl+p, shift+ctrl+p and
     * RPC's `cycle_model` all go through. Only the arithmetic: which list, whether a key reaches
     * the answer, and the thinking level a scope entry carries are the session's, because a
     * caller that had to remember the level is a caller that will not.
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

        // `provider/id` whole rather than split at a slash: a provider can have one in its id
        // (`llama=http://host:10800`), and so can a model (`openrouter/free`).
        if (str_contains($pattern, '/')) {
            foreach ($available as $model) {
                if (strtolower($model->provider . '/' . $model->id) === $wanted) {
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
