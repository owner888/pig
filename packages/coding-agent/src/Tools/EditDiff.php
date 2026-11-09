<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;

/**
 * Working out what an edit changed, and drawing it.
 *
 * Upstream uses the `diff` npm package. Nothing general is needed here: every edit replaces
 * a run of text whose place in the file is known, so the lines between two edits are
 * identical by construction. Each group of edits that touches the same lines becomes one
 * hunk, trimmed of its common head and tail — the exact minimal diff for that, without an
 * LCS table.
 *
 * `render()` is not a general diff and does not pretend to be. Given two files that differ
 * in several places it produces one block covering all of them — correct, but not the
 * smallest description. That is why `renderEdits()` takes the replacements rather than
 * only the two texts: with several edits in one call the places are known, and a block
 * from line 10 to line 900 would show 890 untouched lines as removed and re-added.
 */
final class EditDiff
{
    /** Lines of unchanged text shown either side of a change. */
    public const int CONTEXT = 4;

    /** Every line break as LF: CRLF first, then a lone CR — the text the edits are matched in. */
    public static function toLf(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * The replacements `apply()` matched in `toLf($original)`, made in $original itself — upstream's
     * `applyReplacementsToOriginal()` (#11751). Text outside the replaced ranges keeps its bytes,
     * line endings included: the file used to be written back whole with the first line ending it
     * held, so a two-line edit of a mixed file rewrote every other line's ending, and a lone CR
     * anywhere became an LF.
     *
     * Only a CRLF changes length in `toLf()`, so a position in the LF text plus the CRLFs before
     * it is the position in the original, and a range covering an LF covers its whole CRLF. A line
     * break in the new text takes the ending of the line the range starts on (`lineEndingAt()`).
     * A piece ending in CR followed by one starting with LF would merge into one CRLF and join two
     * lines the diff shows apart, so an LF goes in at that seam — where the replaced text was.
     *
     * @param list<array{0: int, 1: int, 2: string}> $replacements `apply()`'s, in file order
     */
    public static function applyToOriginal(string $original, array $replacements): string
    {
        $crlfBefore = 0;
        $nextCrlf = strpos($original, "\r\n");
        $toOriginal = static function (int $offset) use ($original, &$crlfBefore, &$nextCrlf): int {
            while ($nextCrlf !== false && $nextCrlf - $crlfBefore < $offset) {
                $crlfBefore++;
                $nextCrlf = strpos($original, "\r\n", $nextCrlf + 2);
            }

            return $offset + $crlfBefore;
        };

        $out = '';
        $push = static function (string $part) use (&$out): void {
            if ($part === '') {
                return;
            }

            if ($part[0] === "\n" && str_ends_with($out, "\r")) {
                $out .= "\n";
            }

            $out .= $part;
        };

        $position = 0;

        foreach ($replacements as [$at, $length, $text]) {
            $start = $toOriginal($at);
            $push(substr($original, $position, $start - $position));
            $ending = self::lineEndingAt($original, $start);
            $push($ending === "\n" ? $text : str_replace("\n", $ending, $text));
            $position = $toOriginal($at + $length);
        }

        $push(substr($original, $position));

        return $out;
    }

    /**
     * The ending of the line holding $offset: its own LF or CRLF, or the line before's for a last
     * line with none. A lone CR counts only in a file with no LF at all — classic Mac endings;
     * anywhere else it is likelier a control character inside a line. Upstream's `getLineEndingAt()`.
     */
    private static function lineEndingAt(string $content, int $offset): string
    {
        $lineFeed = strpos($content, "\n", $offset);

        if ($lineFeed === false) {
            $lineFeed = strrpos($content, "\n");
        }

        if ($lineFeed === false) {
            return str_contains($content, "\r") ? "\r" : "\n";
        }

        return $lineFeed > 0 && $content[$lineFeed - 1] === "\r" ? "\r\n" : "\n";
    }

    /**
     * The byte-order mark, if the file has one, and the rest.
     *
     * Taken off before matching: it is invisible, so a model reproducing the first line
     * of a file will not have included it, and leaving it on makes that match fail for a
     * reason nothing in the error would explain.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitBom(string $content): array
    {
        return str_starts_with($content, "\u{FEFF}")
            ? ["\u{FEFF}", substr($content, 3)]
            : ['', $content];
    }

    /**
     * A diff with line numbers, and the line the change starts on in the new file.
     *
     * Run against upstream's `generateDiffString` over 515 pairs. Three differences, all of
     * them deliberate once looked at, and they are worth stating because the numbers make it
     * sound worse than it is:
     *
     * - **Trailing context is numbered in the new file** (259 pairs). Upstream numbers every
     *   context line with its *old* number, which after the change points into a file that no
     *   longer exists; the `+` lines are new numbers, the file on disk is the new file, and a
     *   number you can go and look at is worth more than one you cannot. Leading context is
     *   unambiguous either way, since old and new agree before the change.
     * - **A trailing newline is an empty last line, consistently** (2 pairs). Upstream pops the
     *   final empty element of every part, which hides the difference in the one place it
     *   matters: `"a\nb\n"` against `"a\nb"` comes out of upstream as `-2 b` then `+2 b` — the
     *   same text removed and re-added, a diff that says a line changed while showing it did
     *   not. Here the empty last line goes away and is shown going away.
     * - **Several separate changes come out as one block** (152 pairs), which is the limitation in
     *   the class docblock above. `EditTool` does not reach it: an edit call with several
     *   separate changes is drawn by `renderEdits()`, one hunk each.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function render(string $old, string $new, int $context = self::CONTEXT): array
    {
        // An empty document has no lines, where `explode("\n", '')` gives one empty one. Without
        // this, an edit that emptied a file drew a `+1 ` under its removals — a blank line
        // nobody wrote — and filling an empty file drew a `-1 ` for one nobody deleted. Note
        // what it must *not* swallow: the last element of `"a\nb\n"` is a real empty line, so
        // losing a trailing newline is still a removed line and still shown as one.
        $oldLines = $old === '' ? [] : explode("\n", $old);
        $newLines = $new === '' ? [] : explode("\n", $new);

        $prefix = self::commonPrefix($oldLines, $newLines);
        $suffix = self::commonSuffix($oldLines, $newLines, $prefix);

        return self::emit($oldLines, $newLines, [[
            $prefix,
            count($oldLines) - $suffix - $prefix,
            $prefix,
            count($newLines) - $suffix - $prefix,
        ]], $context);
    }

    /**
     * The diff of an edit call, one hunk per group of replacements that share a line.
     *
     * Upstream's `generateDiffString()` gets the same hunks from a general line diff; here they
     * are read off the replacements `apply()` matched, since each says where it is. A single
     * group is `render()`'s case and goes there, so one edit draws exactly as it always has.
     *
     * Context between two hunks follows upstream: all of it when there are no more than twice
     * `$context` lines, otherwise `$context` after the first, an ellipsis and `$context` before
     * the second. Context is numbered in the new file, as `render()` explains.
     *
     * @param string                                  $old          the LF text the edits matched
     * @param string                                  $new          what `apply()` made of it
     * @param list<array{0: int, 1: int, 2: string}> $replacements `apply()`'s, in file order
     * @return array{0: string, 1: int|null}
     */
    public static function renderEdits(string $old, string $new, array $replacements, int $context = self::CONTEXT): array
    {
        // Each replacement as the lines of the old text it touches: from the line it starts on to
        // the line its end lands on, which is the next line when it ends on a line break — the
        // replacement's tail is joined to whatever follows it there.
        $groups = [];

        foreach ($replacements as [$at, $length, $text]) {
            $first = substr_count($old, "\n", 0, $at);
            $last = substr_count($old, "\n", 0, $at + $length);
            $current = count($groups) - 1;

            if ($current >= 0 && $first <= $groups[$current][1]) {
                $groups[$current][1] = max($groups[$current][1], $last);
                $groups[$current][2][] = [$at, $length, $text];
                continue;
            }

            $groups[] = [$first, $last, [[$at, $length, $text]]];
        }

        if (count($groups) <= 1) {
            return self::render($old, $new, $context);
        }

        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        $starts = [0];

        foreach ($oldLines as $index => $line) {
            $starts[$index + 1] = $starts[$index] + strlen($line) + 1;
        }

        $hunks = [];
        $shift = 0;

        foreach ($groups as [$first, $last, $inside]) {
            $from = $starts[$first];
            $region = substr($old, $from, $starts[$last + 1] - 1 - $from);

            // Back to front, so each offset still points where it did in the original.
            foreach (array_reverse($inside) as [$at, $length, $text]) {
                $region = substr($region, 0, $at - $from) . $text . substr($region, $at - $from + $length);
            }

            $removed = array_slice($oldLines, $first, $last - $first + 1);
            $added = explode("\n", $region);
            $prefix = self::commonPrefix($removed, $added);
            $suffix = self::commonSuffix($removed, $added, $prefix);

            $hunks[] = [
                $first + $prefix,
                count($removed) - $suffix - $prefix,
                $first + $shift + $prefix,
                count($added) - $suffix - $prefix,
            ];
            $shift += count($added) - count($removed);
        }

        return self::emit($oldLines, $newLines, $hunks, $context);
    }

    /**
     * Draw hunks, each `[old start, old count, new start, new count]` with 0-based starts. A hunk
     * that changes nothing — a group whose replacements gave back their own text — is left out.
     *
     * @param list<string>                             $oldLines
     * @param list<string>                             $newLines
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $hunks in file order
     * @return array{0: string, 1: int|null}
     */
    private static function emit(array $oldLines, array $newLines, array $hunks, int $context): array
    {
        $hunks = array_values(array_filter($hunks, static fn (array $hunk): bool => $hunk[1] > 0 || $hunk[3] > 0));

        if ($hunks === []) {
            return ['', null];
        }

        $width = strlen((string) max(count($oldLines), count($newLines)));
        $blank = str_repeat(' ', $width);
        $output = [];
        $line = static fn (string $mark, int $index, string $text): string
            => $mark . self::number($index + 1, $width) . ' ' . $text;
        // Where the unchanged run before the next hunk starts, in the new file.
        $after = null;

        foreach ($hunks as [$oldStart, $oldCount, $newStart, $newCount]) {
            $gap = $newStart - ($after ?? 0);

            if ($after === null) {
                // Leading context: the last few unchanged lines, with an ellipsis for the rest.
                $leading = min($gap, $context);

                if ($gap > $leading) {
                    $output[] = " {$blank} ...";
                }

                for ($index = $newStart - $leading; $index < $newStart; $index++) {
                    $output[] = $line(' ', $index, $newLines[$index]);
                }
            } elseif ($gap <= $context * 2) {
                for ($index = $after; $index < $newStart; $index++) {
                    $output[] = $line(' ', $index, $newLines[$index]);
                }
            } else {
                for ($index = $after; $index < $after + $context; $index++) {
                    $output[] = $line(' ', $index, $newLines[$index]);
                }

                $output[] = " {$blank} ...";

                for ($index = $newStart - $context; $index < $newStart; $index++) {
                    $output[] = $line(' ', $index, $newLines[$index]);
                }
            }

            for ($index = $oldStart; $index < $oldStart + $oldCount; $index++) {
                $output[] = $line('-', $index, $oldLines[$index]);
            }

            for ($index = $newStart; $index < $newStart + $newCount; $index++) {
                $output[] = $line('+', $index, $newLines[$index]);
            }

            $after = $newStart + $newCount;
        }

        $suffix = count($newLines) - $after;
        $trailing = min($suffix, $context);

        for ($index = $after; $index < $after + $trailing; $index++) {
            $output[] = $line(' ', $index, $newLines[$index]);
        }

        if ($suffix > $trailing) {
            $output[] = " {$blank} ...";
        }

        return [implode("\n", $output), $hunks[0][2] + 1];
    }

    private static function number(int $line, int $width): string
    {
        return str_pad((string) $line, $width, ' ', STR_PAD_LEFT);
    }

    /**
     * The content with every edit's `oldText` swapped for its `newText`.
     *
     * Upstream's `applyEditsToNormalizedContent()`: **every edit is matched against the content
     * as it was handed in, not against what the edits before it made of it**, each must match
     * exactly once, and two edits whose matches overlap are refused rather than applied in some
     * order — the model meant two regions, and if they overlap it does not know which text it
     * is replacing. The same edit twice is that refusal too: both match the one place.
     *
     * Shared with `preview()` below and with `EditTool` rather than written twice: what
     * counts as a match, how many is too many and what to say about it are the same
     * questions whether or not the file is about to be written. Upstream has two readers
     * too, and the sentences have already drifted — `edit.ts` says `No changes made to`
     * where `computeEditDiff()` says `No changes would be made to`, which is one fact
     * worded twice and the beginning of them meaning different things.
     *
     * Without upstream's fuzzy pass (trailing whitespace, smart quotes, Unicode dashes), as
     * before: a byte that is not UTF-8 survives here because nothing is normalised.
     *
     * @param string                                          $content LF text
     * @param list<array{oldText: string, newText: string}> $edits   line endings are made LF here
     * @param string                                          $path    as the model wrote it — it is in every message
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: string}>} the new content, and each
     *         replacement as `[offset, length, newText]` in file order, for `renderEdits()`
     * @throws AgentError when a text is empty, missing or not unique, two overlap, or nothing changes
     */
    public static function apply(string $content, array $edits, string $path): array
    {
        $several = count($edits) > 1;
        $replacements = [];

        foreach (array_values($edits) as $index => $edit) {
            $find = self::toLf($edit['oldText']);
            // Which edit, when there is more than one to choose from.
            $which = $several ? "edits[{$index}].oldText" : 'oldText';

            if ($find === '') {
                throw new AgentError("{$which} is empty. Give the exact text to replace.");
            }

            $count = substr_count($content, $find);

            if ($count === 0) {
                throw new AgentError(
                    ($several ? "Could not find {$which} in {$path}." : "Could not find that exact text in {$path}.")
                        . ' It must match the file exactly, including whitespace and line breaks — '
                        . 'read the file and copy the text from it.',
                );
            }

            if ($count > 1) {
                throw new AgentError(
                    ($several ? "Found {$count} occurrences of {$which} in {$path}." : "Found {$count} occurrences of that text in {$path}.")
                        . ' It has to be unique — include the lines around it so there is only one match.',
                );
            }

            $replacements[] = [(int) strpos($content, $find), strlen($find), self::toLf($edit['newText']), $index];
        }

        usort($replacements, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        for ($next = 1; $next < count($replacements); $next++) {
            [$at, $length, , $earlier] = $replacements[$next - 1];

            if ($at + $length > $replacements[$next][0]) {
                throw new AgentError(
                    "edits[{$earlier}] and edits[{$replacements[$next][3]}] overlap in {$path}. "
                        . 'Merge them into one edit, or make each oldText cover a separate region.',
                );
            }
        }

        // Spliced rather than replaced, back to front so each offset still holds: str_replace
        // is fine, but preg_replace and friends read `$1` in a replacement, and a model editing
        // a shell script or a regex will sooner or later hand one over.
        $new = $content;

        foreach (array_reverse($replacements) as [$at, $length, $replace]) {
            $new = substr($new, 0, $at) . $replace . substr($new, $at + $length);
        }

        if ($new === $content) {
            throw new AgentError($several
                ? "No change made to {$path}: every newText is identical to its oldText."
                : "No change made to {$path}: newText is identical to oldText.");
        }

        return [$new, array_map(static fn (array $replacement): array => array_slice($replacement, 0, 3), $replacements)];
    }

    /**
     * What those edits would do, read off the file as it stands, without doing them.
     *
     * Upstream's `computeEditsDiff`, and it is the reason this file reads from disk at all.
     * The diff is drawn as soon as the model has finished *writing* the call rather than
     * when the call returns, and the gap between those two is not the moment it sounds
     * like: it is however long a `tool_call` hook takes to ask whether the edit may run.
     * Without this, that question is answered with nothing on screen but a path — which is
     * the one screen where knowing what is about to change is the whole point.
     *
     * Read-only, so it asks only for readability where `EditTool` asks for both. A refusal
     * comes back as the same `AgentError` the tool would raise, from the same place, so the
     * preview cannot say one thing and the attempt another.
     *
     * @param list<array{oldText: string, newText: string}> $edits
     * @return array{0: string, 1: int|null} the diff, and the line the change starts on
     * @throws AgentError
     */
    public static function preview(string $path, array $edits, string $cwd): array
    {
        $absolute = Paths::resolve($path, $cwd);

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new AgentError("File not found: {$path}");
        }

        $raw = file_get_contents($absolute);

        if ($raw === false) {
            throw new AgentError("Could not read {$path}");
        }

        // No line-ending detection: nothing is written, and the diff is of the normalised
        // text either way. The mark still comes off, or a match on the first line fails.
        [, $content] = self::splitBom($raw);
        $old = self::toLf($content);
        [$new, $replacements] = self::apply($old, $edits, $path);

        return self::renderEdits($old, $new, $replacements);
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     */
    private static function commonPrefix(array $old, array $new): int
    {
        $limit = min(count($old), count($new));
        $count = 0;

        while ($count < $limit && $old[$count] === $new[$count]) {
            $count++;
        }

        return $count;
    }

    /**
     * Matching lines at the end, never overlapping the prefix.
     *
     * Without that guard, a file of identical lines would count the same lines twice and
     * the middle would come out negative.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private static function commonSuffix(array $old, array $new, int $prefix): int
    {
        $limit = min(count($old), count($new)) - $prefix;
        $count = 0;

        while (
            $count < $limit
            && $old[count($old) - 1 - $count] === $new[count($new) - 1 - $count]
        ) {
            $count++;
        }

        return $count;
    }
}
