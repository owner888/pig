<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

/**
 * What in a page about to be shared looks like a credential, so `/share` can list it and offer to
 * black it out first. pig's own (upstream's `/share` uploads without looking); see "Sharing on
 * pigagent.dev" in `docs/dev/decisions.md`.
 *
 * Two kinds of finding. **Known**: a value pig itself holds — an API key or token stored in
 * `auth.json`, or the value of an environment variable whose name says it is one (`*_API_KEY`,
 * `*_TOKEN`, `*_SECRET`, `*_PASSWORD`). **Pattern**: the shapes the major providers give their
 * keys (`sk-…`, `ghp_…`, `AKIA…`, `AIza…`, `xox…-`, a PEM private key block), which catches a key
 * pig never saw — one the model read out of a `.env`, say.
 *
 * A known value is looked for as it is and HTML-escaped, the way the export writes text.
 *
 * Inline images (`data:` URLs) are left out of the search: base64 is noise that a pattern can
 * match by chance, and an image is not where a key is written down.
 */
final class SecretScan
{
    /** Shorter than this, a stored value is too likely to occur by chance to call it a key. */
    private const int MIN_KNOWN = 12;

    /** The key shapes, by the name a finding is listed under. */
    private const array PATTERNS = [
        'Anthropic key' => '/sk-ant-[A-Za-z0-9_\-]{20,}/',
        'OpenAI-style key' => '/sk-(?!ant-)[A-Za-z0-9_\-]{20,}/',
        'GitHub token' => '/(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})/',
        'AWS access key' => '/(?:AKIA|ASIA)[0-9A-Z]{16}/',
        'Google API key' => '/AIza[0-9A-Za-z_\-]{35}/',
        'Slack token' => '/xox[abposr]-[A-Za-z0-9\-]{10,}/',
        'Private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
    ];

    /** What a finding is replaced with. */
    public const string REDACTED = '[redacted]';

    /**
     * The credentials in `$text`, each once, longest first so a redaction never leaves the tail of
     * a longer one behind.
     *
     * @param list<string> $known values pig holds that are secret wherever they turn up
     * @return list<array{kind: string, value: string, count: int}>
     */
    public static function find(string $text, array $known): array
    {
        $searched = (string) preg_replace('/data:[a-z0-9.+\-]+\/[a-z0-9.+\-]+;base64,[A-Za-z0-9+\/=]+/i', '', $text);
        $found = [];

        foreach ($known as $value) {
            if (strlen($value) < self::MIN_KNOWN) {
                continue;
            }

            // The page is HTML, so a value with `&`, `<` or a quote in it is there escaped.
            foreach (array_unique([$value, MarkdownHtml::escape($value)]) as $written) {
                if (str_contains($searched, $written)) {
                    $found[$written] ??= 'Stored credential';
                }
            }
        }

        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match_all($pattern, $searched, $matches) > 0) {
                foreach ($matches[0] as $value) {
                    $found[$value] ??= $kind;
                }
            }
        }

        $hits = [];

        foreach ($found as $value => $kind) {
            $value = (string) $value;
            $hits[] = ['kind' => $kind, 'value' => $value, 'count' => substr_count($searched, $value)];
        }

        usort($hits, static fn (array $a, array $b): int => strlen($b['value']) <=> strlen($a['value']));

        return $hits;
    }

    /**
     * `$text` with every finding replaced by `REDACTED`.
     *
     * @param list<array{kind: string, value: string, count: int}> $hits
     */
    public static function redact(string $text, array $hits): string
    {
        foreach ($hits as $hit) {
            $text = str_replace($hit['value'], self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * A finding as it is listed: its kind and enough of it to recognise, never the whole.
     *
     * @param array{kind: string, value: string, count: int} $hit
     */
    public static function describe(array $hit): string
    {
        $value = $hit['value'];
        $shown = strlen($value) <= 12 ? str_repeat('•', strlen($value)) : substr($value, 0, 6) . '…' . substr($value, -4);
        $times = $hit['count'] === 1 ? 'once' : "{$hit['count']} times";

        return "{$hit['kind']} {$shown} ({$times})";
    }

    /**
     * The values pig holds that are secret: every credential in `auth.json` (`Auth::storedSecrets()`)
     * and every environment variable named like one.
     *
     * @param list<string> $stored
     * @param array<string, string>|null $env the environment, or null for the process's own
     * @return list<string>
     */
    public static function knownSecrets(array $stored, ?array $env = null): array
    {
        $values = $stored;

        foreach ($env ?? getenv() as $name => $value) {
            if (is_string($value) && preg_match('/(?:_API_KEY|_TOKEN|_SECRET|_PASSWORD|_ACCESS_KEY)$/', (string) $name) === 1) {
                $values[] = $value;
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $v): bool => strlen($v) >= self::MIN_KNOWN)));
    }
}
