<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Sequence;
use Pig\CodingAgent\Mermaid\SequenceLayout;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/sequence.ts`: `sequenceDiagram` — participants, messages, notes,
 * activations and block dividers. Lenient: an unreadable statement is dropped and recorded in
 * `warnings`. Sequence diagrams have their own model (`Sequence`) and geometry
 * (`SequenceLayout`).
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class SequenceDiagram implements Diagram
{
    /** Message operators, longest first so `-->>` wins over `-->`: [op, dashed, head]. */
    private const array SEQ_OPS = [
        ['-->>', true, 'arrow'],
        ['->>', false, 'arrow'],
        ['--x', true, 'cross'],
        ['-x', false, 'cross'],
        ['--)', true, 'arrow'],
        ['-)', false, 'arrow'],
        ['-->', true, 'arrow'],
        ['->', false, 'arrow'],
    ];

    private const int MAX_SEQ_OP = 4;

    #[\Override]
    public function kind(): string
    {
        return 'sequence';
    }

    #[\Override]
    public function headers(): array
    {
        return ['sequencediagram'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $seq = $this->parseSequence($src);

        if ($seq === null) {
            return null;
        }

        $canvas = SequenceLayout::layoutSequence($seq);

        return $canvas === null ? null : ['canvas' => $canvas, 'warnings' => $seq->warnings, 'classDefs' => []];
    }

    public function parseSequence(string $src): ?Sequence
    {
        $statements = Statements::statementsOf($src);
        $kind = Statements::headerKind($statements);

        if ($kind === null || !in_array($kind, $this->headers(), true)) {
            return null;
        }

        $seq = new Sequence();
        $autonumber = false;
        $msgCount = 0;
        // One entry per open block; true when it draws a divider on `end`.
        $blocks = [];

        foreach (array_slice($statements, 1) as $st) {
            $first = Statements::firstWord($st);
            $lower = Labels::asciiLower($first);

            if ($lower === 'participant' || $lower === 'actor') {
                $rest = Labels::trim(substr($st, strlen($first)));

                if ($rest === '') {
                    $seq->drop($st);
                } else {
                    $as = Statements::splitOnce($rest, ' as ');
                    $seq->participant($as !== null ? Labels::trim($as[0]) : $rest, $as !== null ? Labels::cleanLabel($as[1]) : null);
                }
            } elseif ($lower === 'autonumber') {
                $autonumber = true;
            } elseif ($lower === 'activate' || $lower === 'deactivate') {
                // Applies to the preceding message's row.
                $who = $seq->find(Labels::trim(substr($st, strlen($first))));

                if ($who !== null && $seq->items !== []) {
                    if ($lower === 'activate') {
                        $seq->activate($who, count($seq->items) - 1);
                    } else {
                        $seq->deactivate($who, count($seq->items) - 1);
                    }
                }
            } elseif (in_array($lower, ['create', 'destroy', 'title', 'acctitle', 'accdescr', 'links', 'link', 'properties'], true)) {
                // No layout meaning.
            } elseif ($lower === 'note') {
                $note = self::parseNoteAnchor(Labels::trim(substr($st, strlen($first))), $seq);

                if ($note === null) {
                    $seq->drop($st);
                } else {
                    $seq->pushItem(['kind' => 'note', 'anchor' => $note['anchor'], 'text' => $note['text']]);
                }
            } elseif (in_array($lower, ['loop', 'alt', 'opt', 'par', 'critical', 'break', 'else', 'and', 'option'], true)) {
                // A continuation only divides a block that opened one.
                $continues = in_array($lower, ['else', 'and', 'option'], true);

                if (!$continues) {
                    $blocks[] = true;
                }

                if (!$continues || ($blocks !== [] && $blocks[count($blocks) - 1] === true)) {
                    $seq->pushItem(['kind' => 'divider', 'text' => Labels::decodeHtmlEntities($st)]);
                }
            } elseif ($lower === 'rect' || $lower === 'box') {
                $blocks[] = false;
            } elseif ($lower === 'end') {
                if (array_pop($blocks) === true) {
                    $seq->pushItem(['kind' => 'divider', 'text' => 'end']);
                }
            } else {
                $msg = self::parseSeqMessage($st, $seq);

                if ($msg === null) {
                    $seq->drop($st);
                } else {
                    $text = $msg['text'];

                    if ($autonumber) {
                        $msgCount++;
                        $text = $text === null ? "{$msgCount}." : "{$msgCount}. {$text}";
                    }

                    $item = count($seq->items);
                    $seq->pushItem([
                        'kind' => 'message',
                        'from' => $msg['from'],
                        'to' => $msg['to'],
                        'text' => $text,
                        'dashed' => $msg['dashed'],
                        'head' => $msg['head'],
                    ]);

                    // `+` activates the receiver on this row, `-` deactivates the sender — only
                    // once the item was accepted, or the activation would point past the cap.
                    if (count($seq->items) === $item + 1) {
                        if (str_contains($msg['marks'], '+')) {
                            $seq->activate($msg['to'], $item);
                        }

                        if (str_contains($msg['marks'], '-')) {
                            $seq->deactivate($msg['from'], $item);
                        }
                    }
                }
            }

            if ($seq->truncated !== null) {
                $seq->warnings[] = "diagram truncated: {$seq->truncated}";

                break;
            }
        }

        return $seq->labels === [] ? null : $seq;
    }

    /** @return array{text: string, anchor: array<string, mixed>}|null */
    private static function parseNoteAnchor(string $rest, Sequence $seq): ?array
    {
        $lower = Labels::asciiLower($rest);

        if (str_starts_with($lower, 'over ')) {
            $kind = 'over';
            $idsAndText = substr($rest, strlen('over '));
        } elseif (str_starts_with($lower, 'left of ')) {
            $kind = 'left';
            $idsAndText = substr($rest, strlen('left of '));
        } elseif (str_starts_with($lower, 'right of ')) {
            $kind = 'right';
            $idsAndText = substr($rest, strlen('right of '));
        } else {
            return null;
        }

        $split = Statements::splitOnce($idsAndText, ':');

        if ($split === null) {
            return null;
        }

        $text = Labels::decodeHtmlEntities(Labels::trim($split[1]));
        $parts = array_values(array_filter(array_map(Labels::trim(...), explode(',', $split[0])), static fn (string $s): bool => $s !== ''));

        if ($parts === []) {
            return null;
        }

        $a = $seq->participant($parts[0], null);

        if ($a === null) {
            return null;
        }

        if ($kind !== 'over') {
            return ['text' => $text, 'anchor' => ['kind' => $kind, 'at' => $a]];
        }

        $b = $a;

        if (isset($parts[1])) {
            $second = $seq->participant($parts[1], null);

            if ($second === null) {
                return null;
            }

            $b = $second;
        }

        return ['text' => $text, 'anchor' => ['kind' => 'over', 'from' => min($a, $b), 'to' => max($a, $b)]];
    }

    /** @return array{from: int, to: int, text: ?string, dashed: bool, head: string, marks: string}|null */
    private static function parseSeqMessage(string $st, Sequence $seq): ?array
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $n = count($chars);
        $found = null;

        for ($pos = 0; $pos < $n && $found === null; $pos++) {
            $tail = implode('', array_slice($chars, $pos, self::MAX_SEQ_OP));

            foreach (self::SEQ_OPS as [$op, $dashed, $head]) {
                if (!str_starts_with($tail, $op)) {
                    continue;
                }

                // `-x` / `-)` inside a hyphenated token (`pre-x->>B`) is not an operator — the
                // real one follows. A non-link character must come after it.
                $after = $chars[$pos + strlen($op)] ?? null;

                if ((str_ends_with($op, 'x') || str_ends_with($op, ')')) && ($after === '-' || $after === '>')) {
                    continue;
                }

                $found = ['pos' => $pos, 'op' => $op, 'dashed' => $dashed, 'head' => $head];

                break;
            }
        }

        if ($found === null) {
            return null;
        }

        $fromId = Labels::trim(implode('', array_slice($chars, 0, $found['pos'])));

        if ($fromId === '') {
            return null;
        }

        // `+` activates the receiver, `-` deactivates the sender.
        $afterOp = (string) preg_replace('/^' . Labels::SPACE . '+/u', '', implode('', array_slice($chars, $found['pos'] + strlen($found['op']))));
        $marks = preg_match('/^[+-]+/', $afterOp, $m) === 1 ? $m[0] : '';
        $rest = substr($afterOp, strlen($marks));

        $split = Statements::splitOnce($rest, ':');
        $toId = Labels::trim($split === null ? $rest : $split[0]);
        $text = $split === null ? null : Statements::nonEmpty(Labels::decodeHtmlEntities(Labels::trim($split[1])));

        if ($toId === '') {
            return null;
        }

        $from = $seq->participant($fromId, null);

        if ($from === null) {
            return null;
        }

        $to = $seq->participant($toId, null);

        if ($to === null) {
            return null;
        }

        return ['from' => $from, 'to' => $to, 'text' => $text, 'dashed' => $found['dashed'], 'head' => $found['head'], 'marks' => $marks];
    }
}
