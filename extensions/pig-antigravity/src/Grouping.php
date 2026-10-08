<?php

declare(strict_types=1);

namespace PigAntigravity;

/**
 * pi-antigravity's `models/grouping.ts`: the runtime models `fetchAvailableModels` lists, folded
 * into the public model ids people pick from, with the routing that sends each thinking level to
 * one of those runtime ids.
 *
 * The shapes are the extension's own, as `models-store.json` holds them, so that what this builds
 * is what gets filed and what `Catalog` reads back:
 *
 * - a model is `{id, name, reasoning, thinkingLevelMap?, input, cost, contextWindow, maxTokens}`
 *   (upstream's `ProviderModelConfig`);
 * - a routing entry is `{off?, routing?: {level: runtime id}, defaultRequestId?}`
 *   (`AntigravityRouting`);
 * - a catalog is `{models: list<model>, routing: {public id: routing entry}}` (`AntigravityCatalog`).
 *
 * Nothing here talks to the network or to pig's registry; `Discovery` does both.
 */
final class Grouping
{
    /** The suffixes a runtime id carries for a thinking level, in the order they are tried. */
    private const array THINKING_SUFFIXES = [
        ['extra-low', 'low'],
        ['extra-high', 'xhigh'],
        ['thinking', 'high'],
        ['minimal', 'minimal'],
        ['medium', 'medium'],
        ['high', 'high'],
        ['low', 'low'],
    ];

    /** A display name's parenthesised level, in the order they are tried. */
    private const array DISPLAY_LEVELS = [
        ['/\(\s*extra\s*low\s*\)/i', 'low'],
        ['/\(\s*extra\s*high\s*\)/i', 'xhigh'],
        ['/\(\s*thinking\s*\)/i', 'high'],
        ['/\(\s*minimal\s*\)/i', 'minimal'],
        ['/\(\s*medium\s*\)/i', 'medium'],
        ['/\(\s*high\s*\)/i', 'high'],
        ['/\(\s*low\s*\)/i', 'low'],
    ];

    /** "Known backend aliases whose runtime IDs do not share a thinking suffix with the public family." */
    private const array RUNTIME_ALIASES = [
        'gemini-3-flash-agent' => ['publicId' => 'gemini-3.5-flash', 'level' => 'high'],
        'gemini-pro-agent' => ['publicId' => 'gemini-3.1-pro', 'level' => 'high'],
    ];

    private const array PI_LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    private const array ZERO_COST = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];

    public static function isSelectableRuntimeModelId(string $id): bool
    {
        if (preg_match('/^(gemini-|claude-|gpt-oss-)/i', $id) !== 1 || preg_match('/\s/', $id) === 1 || preg_match('/^MODEL_/i', $id) === 1) {
            return false;
        }

        if (preg_match('/^(chat_|tab_)/i', $id) === 1) {
            return false;
        }

        if (preg_match('/image/i', $id) === 1) {
            return false;
        }

        return true;
    }

    /**
     * The discovered catalog when it has models, otherwise the current one.
     *
     * @param array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}|null $discovered
     * @param array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}      $current
     * @return array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}
     */
    public static function resolvedCatalog(?array $discovered, array $current): array
    {
        if ($discovered !== null && $discovered['models'] !== []) {
            return $discovered;
        }

        return $current;
    }

    /**
     * @param array<string, mixed>                                                                     $rawModels `fetchAvailableModels`' `models`, runtime id => info
     * @param array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>} $fallback
     * @return array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}
     */
    public static function buildAntigravityCatalog(array $rawModels, array $fallback): array
    {
        /** @var array<string, array{publicId: string, variants: array<string, string>, unsuffixed?: string, displayNames: list<string>, supportsThinking?: bool, supportsImages?: bool}> $groups */
        $groups = [];

        foreach ($rawModels as $runtimeId => $info) {
            $runtimeId = (string) $runtimeId;

            if (!self::isSelectableRuntimeModelId($runtimeId)) {
                continue;
            }

            $info = is_array($info) ? $info : null;

            if ($info !== null && self::truthy($info['isInternal'] ?? null)) {
                continue;
            }

            $displayName = self::modelDisplayName($info);

            if (str_ends_with($runtimeId, '-tiered')) {
                $baseId = substr($runtimeId, 0, -strlen('-tiered'));
                self::ensureGroup($groups, $baseId);
                self::absorbMetadata($groups[$baseId], $info, $displayName);

                if (!isset($groups[$baseId]['unsuffixed'])) {
                    $groups[$baseId]['unsuffixed'] = $runtimeId;
                }

                continue;
            }

            $alias = self::RUNTIME_ALIASES[$runtimeId] ?? null;
            $suffix = $alias !== null ? null : self::parseThinkingSuffix($runtimeId);
            $publicId = $alias['publicId'] ?? $suffix['baseId'] ?? $runtimeId;
            self::ensureGroup($groups, $publicId);
            self::absorbMetadata($groups[$publicId], $info, $displayName);

            $level = $alias['level'] ?? self::levelFromDisplayName($displayName) ?? $suffix['level'] ?? null;

            if ($level !== null) {
                $groups[$publicId]['variants'][$level] = $runtimeId;
            } else {
                $groups[$publicId]['unsuffixed'] = $runtimeId;
            }
        }

        self::mergeAgentSingletons($groups);

        if ($groups === []) {
            return $fallback;
        }

        $models = [];
        $routing = [];

        foreach ($groups as $group) {
            $fallbackModel = self::findModel($fallback['models'], static fn (array $model): bool => $model['id'] === $group['publicId']);
            $fallbackRouting = $fallback['routing'][$group['publicId']] ?? null;

            if ($fallbackModel !== null && $fallbackRouting !== null) {
                $models[] = $fallbackModel;
                $routing[$group['publicId']] = $fallbackRouting;

                continue;
            }

            [$model, $route] = self::synthesizeModel($group, $fallback['models']);
            $models[] = $model;
            $routing[$group['publicId']] = $route;
        }

        foreach ($fallback['models'] as $fallbackModel) {
            if (isset($routing[$fallbackModel['id']])) {
                continue;
            }

            $fallbackRouting = $fallback['routing'][$fallbackModel['id']] ?? null;

            if ($fallbackRouting === null) {
                continue;
            }

            $models[] = $fallbackModel;
            $routing[$fallbackModel['id']] = $fallbackRouting;
        }

        usort($models, self::comparePublicModels(...));

        return ['models' => $models, 'routing' => $routing];
    }

    public static function humanizePublicId(string $id): string
    {
        $tokens = explode('-', $id);
        $words = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '') {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;

            if ($token === 'gpt' && $next === 'oss') {
                $words[] = 'GPT-OSS';
                $i++;

                continue;
            }

            if (ctype_digit($token) && $next !== null && $next !== '' && ctype_digit($next)) {
                $words[] = "{$token}.{$next}";
                $i++;

                continue;
            }

            if (ctype_digit($token[0])) {
                $words[] = strtoupper($token);

                continue;
            }

            $words[] = ucfirst($token);
        }

        return implode(' ', $words);
    }

    /** @param array<string, array<string, mixed>> $groups */
    private static function ensureGroup(array &$groups, string $publicId): void
    {
        $groups[$publicId] ??= ['publicId' => $publicId, 'variants' => [], 'displayNames' => []];
    }

    /**
     * @param array<string, mixed>      $group
     * @param array<string, mixed>|null $info
     */
    private static function absorbMetadata(array &$group, ?array $info, ?string $displayName): void
    {
        if ($displayName !== null && $displayName !== '') {
            $group['displayNames'][] = $displayName;
        }

        if (($info['supportsThinking'] ?? null) === true) {
            $group['supportsThinking'] = true;
        } elseif (($info['supportsThinking'] ?? null) === false && ($group['supportsThinking'] ?? null) !== true) {
            $group['supportsThinking'] = false;
        }

        if (($info['supportsImages'] ?? null) === true) {
            $group['supportsImages'] = true;
        }

        if (($info['supportsImages'] ?? null) === false && !array_key_exists('supportsImages', $group)) {
            $group['supportsImages'] = false;
        }
    }

    /** @param array<string, array<string, mixed>> $groups */
    private static function mergeAgentSingletons(array &$groups): void
    {
        foreach (array_keys($groups) as $publicId) {
            $group = $groups[$publicId] ?? null;

            if ($group === null || !str_ends_with($publicId, '-agent') || $group['variants'] !== []) {
                continue;
            }

            $family = self::displayFamily($group['displayNames'][0] ?? null);

            if ($family === null || $family === '') {
                continue;
            }

            $target = null;

            foreach ($groups as $candidateId => $candidate) {
                if ($candidate['publicId'] !== $publicId && in_array($family, array_map(self::displayFamily(...), $candidate['displayNames']), true)) {
                    $target = $candidateId;

                    break;
                }
            }

            if ($target === null || !isset($group['unsuffixed'])) {
                continue;
            }

            $level = self::levelFromDisplayName($group['displayNames'][0] ?? null) ?? 'high';
            $groups[$target]['variants'][$level] = $group['unsuffixed'];
            self::absorbMetadata(
                $groups[$target],
                array_key_exists('supportsThinking', $group) ? ['supportsThinking' => $group['supportsThinking']] : [],
                $group['displayNames'][0] ?? null,
            );
            unset($groups[$publicId]);
        }
    }

    /** @return array{baseId: string, level: string}|null */
    private static function parseThinkingSuffix(string $runtimeId): ?array
    {
        $lower = strtolower($runtimeId);

        foreach (self::THINKING_SUFFIXES as [$suffix, $level]) {
            if (str_ends_with($lower, "-{$suffix}")) {
                return ['baseId' => substr($runtimeId, 0, -(strlen($suffix) + 1)), 'level' => $level];
            }
        }

        return null;
    }

    private static function levelFromDisplayName(?string $displayName): ?string
    {
        if ($displayName === null || $displayName === '') {
            return null;
        }

        foreach (self::DISPLAY_LEVELS as [$pattern, $level]) {
            if (preg_match($pattern, $displayName) === 1) {
                return $level;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $info */
    private static function modelDisplayName(?array $info): ?string
    {
        if ($info === null) {
            return null;
        }

        foreach (['displayName', 'label', 'modelName'] as $key) {
            if (is_string($info[$key] ?? null) && $info[$key] !== '') {
                return $info[$key];
            }
        }

        return null;
    }

    private static function displayFamily(?string $displayName): ?string
    {
        if ($displayName === null || $displayName === '') {
            return null;
        }

        $family = (string) preg_replace('/\s*\((?:extra\s*low|extra\s*high|low|medium|high|minimal|thinking)\)\s*$/i', '', $displayName);

        return strtolower(trim($family));
    }

    /**
     * @param array<string, mixed>              $group
     * @param list<array<string, mixed>>        $fallbackModels
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function synthesizeModel(array $group, array $fallbackModels): array
    {
        $template = self::familyTemplate($group['publicId'], $fallbackModels);
        $advertisedLevels = self::advertisedThinkingLevels($group);
        $routing = self::routingFromVariants($group['publicId'], $group['variants'], $group['unsuffixed'] ?? null);
        $supportsImages = $group['supportsImages'] ?? ($template !== null ? in_array('image', $template['input'], true) : true);
        $reasoning = $advertisedLevels !== []
            || ($group['supportsThinking'] ?? null) === true
            || (!array_key_exists('supportsThinking', $group) && ($template['reasoning'] ?? false) === true);

        $model = [
            'id' => $group['publicId'],
            'name' => self::publicModelName($group),
            'reasoning' => $reasoning,
        ];

        // `thinkingLevelMap: reasoning ? … : undefined` — absent, as JSON leaves an undefined out.
        if ($reasoning) {
            $model['thinkingLevelMap'] = self::thinkingLevelMapFromLevels($advertisedLevels);
        }

        $model['input'] = $supportsImages ? ['text', 'image'] : ['text'];
        $model['cost'] = $template['cost'] ?? self::ZERO_COST;
        $model['contextWindow'] = $template['contextWindow'] ?? 128000;
        $model['maxTokens'] = $template['maxTokens'] ?? 8192;

        return [$model, $routing];
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, true>
     */
    private static function advertisedThinkingLevels(array $group): array
    {
        $levels = array_fill_keys(array_keys($group['variants']), true);

        if ($levels !== []) {
            return $levels;
        }

        // "Explicit false from the catalog must not grow a fake High control."
        if (($group['supportsThinking'] ?? null) === false) {
            return $levels;
        }

        if (($group['supportsThinking'] ?? null) === true || isset($group['unsuffixed'])) {
            $levels['high'] = true;
        }

        return $levels;
    }

    /**
     * @param array<string, string> $variants
     * @return array{off: string, routing: array<string, string>, defaultRequestId: string}
     */
    private static function routingFromVariants(string $publicId, array $variants, ?string $unsuffixed): array
    {
        $defaultRequestId = $variants['low'] ?? $variants['minimal'] ?? $variants['medium'] ?? $variants['high'] ?? $unsuffixed ?? $publicId;

        $pick = static function (string ...$keys) use ($variants, $unsuffixed, $defaultRequestId): string {
            foreach ($keys as $key) {
                if (isset($variants[$key]) && $variants[$key] !== '') {
                    return $variants[$key];
                }
            }

            return $unsuffixed ?? $defaultRequestId;
        };

        return [
            'off' => $pick('low', 'minimal', 'medium', 'high'),
            'routing' => [
                'minimal' => $pick('minimal', 'low', 'medium', 'high'),
                'low' => $pick('low', 'minimal', 'medium', 'high'),
                'medium' => $pick('medium', 'low', 'high', 'minimal'),
                'high' => $pick('high', 'medium', 'low', 'minimal'),
                'xhigh' => $pick('xhigh', 'high', 'medium', 'low'),
            ],
            'defaultRequestId' => $defaultRequestId,
        ];
    }

    /**
     * @param array<string, true> $levels
     * @return array<string, string|null>
     */
    private static function thinkingLevelMapFromLevels(array $levels): array
    {
        $map = [];

        foreach (self::PI_LEVELS as $level) {
            $map[$level] = isset($levels[$level]) ? $level : null;
        }

        if ($levels === []) {
            $map['high'] = 'high';
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $fallbackModels
     * @return array<string, mixed>|null
     */
    private static function familyTemplate(string $publicId, array $fallbackModels): ?array
    {
        $first = static fn (string $pattern): ?array => self::findModel($fallbackModels, static fn (array $model): bool => preg_match($pattern, $model['id']) === 1);
        $starting = static fn (string $prefix): ?array => self::findModel($fallbackModels, static fn (array $model): bool => str_starts_with($model['id'], $prefix));

        return match (true) {
            preg_match('/^gemini-.*-flash/i', $publicId) === 1 => $first('/^gemini-.*-flash/i'),
            preg_match('/^gemini-.*-pro/i', $publicId) === 1 => $first('/^gemini-.*-pro/i'),
            str_starts_with($publicId, 'claude-opus') => $starting('claude-opus'),
            str_starts_with($publicId, 'claude-') => $starting('claude-sonnet') ?? $starting('claude-'),
            str_starts_with($publicId, 'gpt-oss') => $starting('gpt-oss'),
            str_starts_with($publicId, 'gemini-') => $starting('gemini-'),
            default => null,
        };
    }

    /** @param array<string, mixed> $group */
    private static function publicModelName(array $group): string
    {
        foreach ($group['displayNames'] as $name) {
            $family = self::displayFamily($name);

            if ($family !== null && $family !== '') {
                return self::titleCase($family) . ' (Antigravity)';
            }
        }

        return self::humanizePublicId($group['publicId']) . ' (Antigravity)';
    }

    private static function titleCase(string $value): string
    {
        return (string) preg_replace_callback('/\b([a-z])/', static fn (array $match): string => strtoupper($match[1]), $value);
    }

    private static function parseGeminiVersion(string $id): int
    {
        if (preg_match('/^gemini-(\d+)(?:\.(\d+))?/i', $id, $match) !== 1) {
            return 0;
        }

        return (int) $match[1] * 1000 + (int) ($match[2] ?? 0);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function comparePublicModels(array $a, array $b): int
    {
        $rankA = self::modelRank($a['id']);
        $rankB = self::modelRank($b['id']);

        if ($rankA[0] !== $rankB[0]) {
            return $rankA[0] <=> $rankB[0];
        }

        if ($rankA[1] !== $rankB[1]) {
            return $rankA[1] <=> $rankB[1];
        }

        return self::localeCompare($a['id'], $b['id']);
    }

    /** @return array{int, int} */
    private static function modelRank(string $id): array
    {
        $version = self::parseGeminiVersion($id);

        return match (true) {
            preg_match('/^gemini-.*flash/i', $id) === 1 && preg_match('/pro/i', $id) !== 1 => [0, -$version],
            str_starts_with($id, 'claude-opus') => [1, 0],
            str_starts_with($id, 'claude-sonnet') => [2, 0],
            str_starts_with($id, 'claude-') => [3, 0],
            preg_match('/^gemini-.*pro/i', $id) === 1 => [4, -$version],
            str_starts_with($id, 'gemini-') => [5, -$version],
            str_starts_with($id, 'gpt-oss') => [6, 0],
            default => [7, 0],
        };
    }

    /**
     * `a.localeCompare(b)` over a model id: the root collation's order of what ids are made of —
     * `_`, `-`, `.` before the digits, the digits before the letters, letters without regard to
     * case — and, between ids equal that way, lower case first. Written out rather than through
     * `ext-intl`, which pig does not require.
     */
    private static function localeCompare(string $a, string $b): int
    {
        $rank = static fn (string $char): int => match (true) {
            $char === '_' => 0,
            $char === '-' => 1,
            $char === '.' => 2,
            ctype_digit($char) => 10 + (int) $char,
            ctype_alpha($char) => 100 + ord(strtolower($char)),
            default => 300 + ord($char),
        };
        $shorter = min(strlen($a), strlen($b));

        for ($i = 0; $i < $shorter; $i++) {
            $order = $rank($a[$i]) <=> $rank($b[$i]);

            if ($order !== 0) {
                return $order;
            }
        }

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        for ($i = 0; $i < $shorter; $i++) {
            if ($a[$i] !== $b[$i]) {
                return ctype_lower($a[$i]) ? -1 : 1;
            }
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $models
     * @param \Closure(array<string, mixed>): bool $matches
     * @return array<string, mixed>|null
     */
    private static function findModel(array $models, \Closure $matches): ?array
    {
        foreach ($models as $model) {
            if ($matches($model)) {
                return $model;
            }
        }

        return null;
    }

    /** JavaScript's truthiness, for `info?.isInternal`. */
    private static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false, $value === 0, $value === 0.0, $value === '' => false,
            is_float($value) && is_nan($value) => false,
            default => true,
        };
    }
}
