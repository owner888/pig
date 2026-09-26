<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;

/**
 * Working out what an edit changed, and drawing it.
 *
 * Upstream uses the `diff` npm package. Nothing general is needed here: an edit replaces
 * one contiguous run of text with another, so the lines before the change are identical
 * and so are the lines after it. Trimming the common head and tail gives the exact
 * minimal diff for that, in one pass and without an LCS table.
 *
 * It is not a general diff and does not pretend to be. Given two files that differ in
 * several places it produces one block covering all of them — correct, but not the
 * smallest description. If something ever needs a real diff, that is the point to write
 * one rather than to stretch this.
 */
final class EditDiff
{
    /** Lines of unchanged text shown either side of a change. */
    public const int CONTEXT = 4;

    /**
     * The line ending the file already uses.
     *
     * Whichever kind comes first wins: a file with one stray CRLF near the end is an LF
     * file, and rewriting all of it would turn a two-line edit into a whole-file diff in
     * the person's version control.
     */
    public static function lineEnding(string $content): string
    {
        $crlf = strpos($content, "\r\n");
        $lf = strpos($content, "\n");

        if ($lf === false || $crlf === false) {
            return "\n";
        }

        return $crlf < $lf ? "\r\n" : "\n";
    }

    public static function toLf(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function restore(string $text, string $ending): string
    {
        return $ending === "\r\n" ? str_replace("\n", "\r\n", $text) : $text;
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
     * - **Several separate edits come out as one block** (152 pairs), which is the limitation in
     *   the class docblock above and cannot arise from `EditTool`: an edit replaces one
     *   contiguous run, so those pairs only exist because the corpus made them.
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

        $removed = array_slice($oldLines, $prefix, count($oldLines) - $suffix - $prefix);
        $added = array_slice($newLines, $prefix, count($newLines) - $suffix - $prefix);

        if ($removed === [] && $added === []) {
            return ['', null];
        }

        $width = strlen((string) max(count($oldLines), count($newLines)));
        $blank = str_repeat(' ', $width);
        $output = [];

        // Leading context: the last few unchanged lines, with an ellipsis for the rest.
        $leading = min($prefix, $context);

        if ($prefix > $leading) {
            $output[] = " {$blank} ...";
        }

        for ($index = $prefix - $leading; $index < $prefix; $index++) {
            $output[] = ' ' . self::number($index + 1, $width) . ' ' . $oldLines[$index];
        }

        foreach ($removed as $offset => $line) {
            $output[] = '-' . self::number($prefix + $offset + 1, $width) . ' ' . $line;
        }

        foreach ($added as $offset => $line) {
            $output[] = '+' . self::number($prefix + $offset + 1, $width) . ' ' . $line;
        }

        $trailing = min($suffix, $context);
        $start = count($newLines) - $suffix;

        for ($index = $start; $index < $start + $trailing; $index++) {
            $output[] = ' ' . self::number($index + 1, $width) . ' ' . $newLines[$index];
        }

        if ($suffix > $trailing) {
            $output[] = " {$blank} ...";
        }

        return [implode("\n", $output), $prefix + 1];
    }

    private static function number(int $line, int $width): string
    {
        return str_pad((string) $line, $width, ' ', STR_PAD_LEFT);
    }

    /**
     * The content with the one occurrence of $find swapped for $replace.
     *
     * Shared with `preview()` below and with `EditTool` rather than written twice: what
     * counts as a match, how many is too many and what to say about it are the same three
     * questions whether or not the file is about to be written. Upstream has two readers
     * too, and the sentences have already drifted — `edit.ts` says `No changes made to`
     * where `computeEditDiff()` says `No changes would be made to`, which is one fact
     * worded twice and the beginning of them meaning different things.
     *
     * @param string $path as the model wrote it — it is in every message
     * @throws AgentError when the text is missing, is not unique, or changes nothing
     */
    public static function apply(string $content, string $find, string $replace, string $path): string
    {
        if ($find === '') {
            throw new AgentError('oldText is empty. Give the exact text to replace.');
        }

        $count = substr_count($content, $find);

        if ($count === 0) {
            throw new AgentError(
                "Could not find that exact text in {$path}. It must match the file exactly, "
                    . 'including whitespace and line breaks — read the file and copy the text from it.',
            );
        }

        if ($count > 1) {
            throw new AgentError(
                "Found {$count} occurrences of that text in {$path}. It has to be unique — "
                    . 'include the lines around it so there is only one match.',
            );
        }

        $at = strpos($content, $find);

        // Spliced rather than replaced: str_replace is fine, but preg_replace and friends
        // read `$1` in a replacement, and a model editing a shell script or a regex will
        // sooner or later hand one over.
        $new = substr($content, 0, (int) $at) . $replace . substr($content, (int) $at + strlen($find));

        if ($new === $content) {
            throw new AgentError("No change made to {$path}: newText is identical to oldText.");
        }

        return $new;
    }

    /**
     * What that edit would do, read off the file as it stands, without doing it.
     *
     * Upstream's `computeEditDiff`, and it is the reason this file reads from disk at all.
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
     * @return array{0: string, 1: int|null} the diff, and the line the change starts on
     * @throws AgentError
     */
    public static function preview(string $path, string $find, string $replace, string $cwd): array
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

        return self::render($old, self::apply($old, self::toLf($find), self::toLf($replace), $path));
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
