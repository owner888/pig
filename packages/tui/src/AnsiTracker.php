<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The styles currently in force, so wrapping can restart them on the next line.
 *
 * A terminal's SGR state is global, not scoped to a line: when a wrap falls in the middle
 * of bold red text, the second line starts with no styling at all unless the codes are
 * emitted again. Replaying the original codes verbatim would also replay the ones that
 * were since turned off, so each attribute is tracked on its own and re-emitted as one
 * fresh sequence.
 */
final class AnsiTracker
{
    /** @var array<int, bool> SGR parameter => whether it is on */
    private array $flags = [1 => false, 2 => false, 3 => false, 4 => false, 5 => false, 7 => false, 8 => false, 9 => false];

    /** Full parameter string, e.g. "31" or "38;5;240". */
    private ?string $foreground = null;

    private ?string $background = null;

    /**
     * The attributes a terminal draws on an empty cell, and the code that turns each off.
     *
     * In SGR order, so the sequence reads `24;27;29` rather than in whatever order this was
     * written. See `lineEndReset()` for why these three and nothing else.
     */
    private const array PAINTS_A_BLANK_CELL = [4 => 24, 7 => 27, 9 => 29];

    /** Attributes turned off by a code other than their own. */
    private const array TURNS_OFF = [
        21 => [1],
        22 => [1, 2],
        23 => [3],
        24 => [4],
        25 => [5],
        27 => [7],
        28 => [8],
        29 => [9],
    ];

    /** Feed one escape sequence. Anything that is not an SGR sequence is ignored. */
    public function process(string $code): void
    {
        if (!str_ends_with($code, 'm') || preg_match('/\x1b\[([\d;]*)m/', $code, $match) !== 1) {
            return;
        }

        if ($match[1] === '' || $match[1] === '0') {
            $this->reset();

            return;
        }

        $parts = explode(';', $match[1]);
        $index = 0;

        while ($index < count($parts)) {
            $index += $this->apply($parts, $index);
        }
    }

    /** Feed a run of text, picking out whatever escape sequences it holds. */
    public function processText(string $text): void
    {
        $pos = 0;
        $length = strlen($text);

        while ($pos < $length) {
            $code = Ansi::at($text, $pos);

            if ($code === null) {
                $pos++;

                continue;
            }

            $this->process($code[0]);
            $pos += $code[1];
        }
    }

    /**
     * Apply the parameter at $index.
     *
     * @param list<string> $parts
     * @return int how many parameters were consumed
     */
    private function apply(array $parts, int $index): int
    {
        $code = (int) $parts[$index];

        // 38 and 48 introduce an extended colour and swallow the parameters that follow.
        if ($code === 38 || $code === 48) {
            $consumed = match ($parts[$index + 1] ?? null) {
                '5' => isset($parts[$index + 2]) ? 3 : 0,
                '2' => isset($parts[$index + 4]) ? 5 : 0,
                default => 0,
            };

            if ($consumed !== 0) {
                $colour = implode(';', array_slice($parts, $index, $consumed));

                if ($code === 38) {
                    $this->foreground = $colour;
                } else {
                    $this->background = $colour;
                }

                return $consumed;
            }
        }

        if ($code === 0) {
            $this->reset();

            return 1;
        }

        if (isset($this->flags[$code])) {
            $this->flags[$code] = true;

            return 1;
        }

        foreach (self::TURNS_OFF[$code] ?? [] as $off) {
            $this->flags[$off] = false;
        }

        match (true) {
            $code === 39 => $this->foreground = null,
            $code === 49 => $this->background = null,
            ($code >= 30 && $code <= 37) || ($code >= 90 && $code <= 97) => $this->foreground = (string) $code,
            ($code >= 40 && $code <= 47) || ($code >= 100 && $code <= 107) => $this->background = (string) $code,
            default => null,
        };

        return 1;
    }

    private function reset(): void
    {
        foreach (array_keys($this->flags) as $flag) {
            $this->flags[$flag] = false;
        }

        $this->foreground = null;
        $this->background = null;
    }

    /** One sequence that puts a fresh terminal into the state tracked here. */
    public function activeCodes(): string
    {
        $codes = [];

        foreach ($this->flags as $code => $on) {
            if ($on) {
                $codes[] = (string) $code;
            }
        }

        if ($this->foreground !== null) {
            $codes[] = $this->foreground;
        }

        if ($this->background !== null) {
            $codes[] = $this->background;
        }

        return $codes === [] ? '' : "\x1b[" . implode(';', $codes) . 'm';
    }

    /** The active background alone, as one sequence — upstream's `getActiveBackgroundCode()`. */
    public function activeBackgroundCode(): string
    {
        return $this->background === null ? '' : "\x1b[{$this->background}m";
    }

    public function hasActiveCodes(): bool
    {
        return $this->activeCodes() !== '';
    }

    /**
     * What to turn off before the line ends.
     *
     * The test is whether the attribute **paints a blank cell**, because what follows the text on
     * a wrapped line is padding: underline and strikethrough are drawn through a space like a
     * rule, and inverse swaps the foreground in, so a space becomes a block of colour. All three
     * leave a mark running to the right edge and all three are closed here, with their own
     * off-codes in one sequence.
     *
     * Everything else is left alone and carries to the next line intact. Bold, dim and italic do
     * nothing to a space; blink and conceal are invisible on one; a foreground colour stops where
     * the characters stop; and a **background** is the one that has to survive, since a caller
     * padding a coloured row wants the colour to reach the edge.
     *
     * Upstream closes underline only, and its comment gives the reason as "Other attributes like
     * colors don't visually bleed to padding" — true of a colour and false of SGR 7 and SGR 9.
     * `DiffView` marks a changed run with inverse and `Markdown` strikes through `~~text~~`, so
     * both were reachable: an edited line long enough to wrap painted the rest of two rows solid.
     */
    public function lineEndReset(): string
    {
        $codes = [];

        foreach (self::PAINTS_A_BLANK_CELL as $flag => $off) {
            if ($this->flags[$flag]) {
                $codes[] = (string) $off;
            }
        }

        return $codes === [] ? '' : "\x1b[" . implode(';', $codes) . 'm';
    }
}
