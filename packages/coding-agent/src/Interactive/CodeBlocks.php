<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

/**
 * The fenced code blocks of an answer, for `/copycode` to offer one at a time.
 *
 * Line by line rather than one regular expression, because a fence is a CommonMark rule and not a
 * pattern: ``` or ~~~, three or more, closed only by the same character at least as many times — so
 * a ```` block that shows a ``` block inside it stays one block, and a block left open at the end
 * of the answer runs to the end, as the screen draws it. A block indented inside a list item comes
 * back without the indentation it shares, since that belongs to the list and not to the code.
 */
final class CodeBlocks
{
    /** How much of a block's first line names it in a list. */
    private const int PREVIEW_LENGTH = 50;

    /**
     * @return list<array{language: string, code: string, lines: int, preview: string}> empty
     *         blocks left out
     */
    public static function extract(string $markdown): array
    {
        $blocks = [];
        $open = null;

        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            if ($open === null) {
                if (preg_match('/^([ \t]*)(`{3,}|~{3,})(.*)$/', $line, $match) === 1 && ($match[2][0] === '~' || !str_contains($match[3], '`'))) {
                    $open = ['fence' => $match[2], 'language' => strtolower(strtok(trim($match[3]), " \t{") ?: ''), 'lines' => []];
                }

                continue;
            }

            $fence = $open['fence'];
            if (preg_match('/^[ \t]*(' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',})[ \t]*$/', $line) === 1) {
                $blocks[] = $open;
                $open = null;

                continue;
            }

            $open['lines'][] = $line;
        }

        if ($open !== null) {
            $blocks[] = $open;
        }

        $result = [];
        foreach ($blocks as $block) {
            $code = self::dedent($block['lines']);
            if (trim($code) === '') {
                continue;
            }

            $lines = explode("\n", $code);
            $first = '';
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $first = trim($line);
                    break;
                }
            }

            $result[] = [
                'language' => $block['language'] !== '' ? $block['language'] : 'code',
                'code' => $code,
                'lines' => count($lines),
                'preview' => mb_strlen($first) > self::PREVIEW_LENGTH ? mb_substr($first, 0, self::PREVIEW_LENGTH) . '…' : $first,
            ];
        }

        return $result;
    }

    /** @param list<string> $lines */
    private static function dedent(array $lines): string
    {
        $indent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $width = strspn($line, " \t");
            $indent = $indent === null ? $width : min($indent, $width);
        }

        if ($indent !== null && $indent > 0) {
            $lines = array_map(static fn (string $line): string => substr($line, min($indent, strspn($line, " \t"))), $lines);
        }

        return implode("\n", $lines);
    }
}
