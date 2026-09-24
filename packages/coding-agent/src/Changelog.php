<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

/**
 * What changed, read out of `CHANGELOG.md`.
 *
 * Upstream's `utils/changelog.ts`. Two readers: `/changelog` shows everything, and startup shows
 * only what is newer than the version this person last saw — which is the part that has to be
 * right, because a tool that greets you with its whole history every morning is a tool whose
 * greeting you stop reading.
 *
 * **A `##` heading with a version in it starts an entry**, and everything until the next `##`
 * belongs to it. A `##` without a parseable version does not start one, and **discards whatever
 * was being collected**: that is upstream's behaviour and it is the right one for a file people
 * edit by hand — an `## Unreleased` section is a section nobody has versioned yet, and folding it
 * into the release above would put unreleased notes under a released number.
 *
 * pig has no `CHANGELOG.md` yet, so every one of these answers nothing today. That is the same
 * answer the machinery gives a person who deleted theirs, which is why it is worth having working
 * before there is a file rather than written the day the file appears.
 */
final readonly class Changelog
{
    /**
     * @param string $version as the heading wrote it, `0.10.0` — compared with
     *        `version_compare()`, never as text
     */
    private function __construct(
        public string $version,
        public string $content,
    ) {
    }

    /** Where pig's own lives: beside the README, at the root of the repository. */
    public static function path(): string
    {
        return dirname(__DIR__, 3) . '/CHANGELOG.md';
    }

    /**
     * Every entry in the file, oldest first — the order they are written in is newest first,
     * and this hands them back as they came.
     *
     * A file that is not there is not a problem: most installations have no changelog and a
     * warning on every start is how people learn to skip warnings.
     *
     * @return list<self>
     */
    public static function parse(?string $path = null): array
    {
        $path ??= self::path();

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        $entries = [];
        $version = null;
        $collected = [];

        foreach (explode("\n", $raw) as $line) {
            if (!str_starts_with($line, '## ')) {
                if ($version !== null) {
                    $collected[] = $line;
                }

                continue;
            }

            // A heading ends whatever was being collected, whether or not it starts something
            // new — so the flush happens first and unconditionally.
            $entries = self::flush($entries, $version, $collected);
            [$version, $collected] = self::heading($line);
        }

        return self::flush($entries, $version, $collected);
    }

    /**
     * The entries newer than a version, which is what a startup banner is allowed to say.
     *
     * **`version_compare()` does the comparing**, which is the platform answering a question this
     * file has no business answering itself. Upstream splits the number into three and subtracts,
     * because JavaScript has nothing else; the first version here copied that and was worse than
     * the platform call in two ways that only showed up against real input — `0.2` read as
     * `0.2.0` and `0.2.0-beta` read as `0.2.0`, so both compared *equal* to a release they are
     * not. `version_compare` gets those right and `0.10.0 > 0.9.0` too, which is the one the
     * hand-rolled version existed for.
     *
     * A string that is no kind of version — an empty setting, a typo — comes out below every
     * release, so everything is new. That is the same end as upstream's `Number()` of a missing
     * part and it is the right way round: showing the whole file once beats silently showing
     * nothing because a settings file had a typo in it.
     *
     * @param list<self> $entries
     * @return list<self>
     */
    public static function newerThan(array $entries, string $version): array
    {
        return array_values(array_filter(
            $entries,
            static fn (self $entry): bool => $entry->isNewerThan($version),
        ));
    }

    public function isNewerThan(string $version): bool
    {
        return version_compare($this->version, $version, '>');
    }

    /**
     * Several entries as one piece of markdown.
     *
     * @param list<self> $entries
     */
    public static function join(array $entries): string
    {
        return implode("\n\n", array_map(static fn (self $entry): string => $entry->content, $entries));
    }

    /**
     * The version a `##` line names, and the line itself as the start of its content.
     *
     * `## [0.2.0] — 2026-01-02` and `## 0.2.0` both work: the brackets are Keep a Changelog's
     * and optional here, as they are upstream.
     *
     * @return array{0: self|null, 1: list<string>}
     */
    private static function heading(string $line): array
    {
        if (preg_match('/##\s+\[?(\d+\.\d+\.\d+)\]?/', $line, $match) !== 1) {
            return [null, []];
        }

        return [new self($match[1], ''), [$line]];
    }

    /**
     * @param list<self>   $entries
     * @param list<string> $collected
     * @return list<self>
     */
    private static function flush(array $entries, ?self $version, array $collected): array
    {
        if ($version === null || $collected === []) {
            return $entries;
        }

        $entries[] = new self($version->version, trim(implode("\n", $collected)));

        return $entries;
    }
}
