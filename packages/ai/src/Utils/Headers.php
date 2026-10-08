<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Upstream's `utils/headers.ts`, and the header merging its SDKs do.
 *
 * A header map here is upstream's `ProviderHeaders`: name => value, where a null value means
 * "remove any header of this name that an earlier source set".
 */
final class Headers
{
    /**
     * Upstream's `providerHeadersToRecord()`: merged case-insensitively, a later source replacing an
     * earlier header of the same name (keeping the later spelling) and a null deleting it; null when
     * nothing is left.
     *
     * @param array<string, string|null>|null ...$headerSources
     * @return array<string, string>|null
     */
    public static function providerHeadersToRecord(?array ...$headerSources): ?array
    {
        $merged = [];

        foreach ($headerSources as $source) {
            foreach ($source ?? [] as $name => $value) {
                $name = (string) $name;
                $normalizedName = strtolower($name);
                unset($merged[$normalizedName]);

                if ($value !== null) {
                    $merged[$normalizedName] = [$name, (string) $value];
                }
            }
        }

        if ($merged === []) {
            return null;
        }

        $record = [];

        foreach ($merged as [$name, $value]) {
            $record[$name] = $value;
        }

        return $record;
    }

    /**
     * The OpenAI and Anthropic SDKs' `buildHeaders()` over object sources: each source's names
     * replace an earlier header of the same name whatever its case, a null removes it, and the
     * result is what goes on the wire — names lowercased, as a `Headers` object holds them.
     * (`x-stainless-helper`'s comma-append is not ported: nothing pig sends sets it.)
     *
     * @param array<string, string|null>|null ...$headerSources
     * @return array{values: array<string, string>, nulls: array<string, true>}
     */
    public static function build(?array ...$headerSources): array
    {
        $values = [];
        $nulls = [];

        foreach ($headerSources as $source) {
            foreach ($source ?? [] as $name => $value) {
                $lowerName = strtolower((string) $name);
                unset($values[$lowerName]);

                if ($value === null) {
                    $nulls[$lowerName] = true;
                } else {
                    $values[$lowerName] = (string) $value;
                    unset($nulls[$lowerName]);
                }
            }
        }

        return ['values' => $values, 'nulls' => $nulls];
    }

    /**
     * Upstream's `hasHeader()` in the Anthropic and OpenAI adapters: a header of this name, any
     * case, whose value is not null and not blank.
     *
     * @param array<string, string|null>|null $headers
     */
    public static function has(?array $headers, string $name): bool
    {
        $expected = strtolower($name);

        foreach ($headers ?? [] as $key => $value) {
            if (strtolower((string) $key) === $expected && $value !== null && JsJson::trim($value) !== '') {
                return true;
            }
        }

        return false;
    }
}
