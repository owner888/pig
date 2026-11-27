<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Usage;

use DateTimeImmutable;
use Pig\CodingAgent\Theme\Theme;

/**
 * How a usage report is drawn, once for every extension: the terminal's lines (`/usage`), the
 * footer's one line, and the web panel's blocks. The look is the one pig-antigravity's
 * `/antigravity.usage` had first — a bar coloured by how much is left, the limit, what is left and
 * when it resets — so a second extension's usage looks like the first's.
 */
final class UsageView
{
    public const int BAR_WIDTH = 20;

    /**
     * The report as lines for the transcript, coloured with `$theme` (plain without one, which is
     * what a pipe gets). With `$title`, the source's name heads it, as `/usage` lists several.
     */
    public static function text(UsageReport $report, ?Theme $theme = null, ?string $title = null, ?int $now = null): string
    {
        $paint = static fn (string $role, string $text): string => $theme === null ? $text : match ($role) {
            'title' => $theme->bold($theme->fg('accent', $text)),
            'heading' => $theme->bold($theme->fg('text', $text)),
            'note' => $theme->fg('muted', $text),
            'plenty' => $theme->fg('success', $text),
            'low' => $theme->fg('warning', $text),
            'nearlyOut' => $theme->fg('error', $text),
            default => $theme->fg('dim', $text),
        };
        $lines = [];

        if ($title !== null) {
            $lines[] = $paint('title', $title);
        }

        foreach ([$report->plan, $report->note] as $line) {
            if ($line !== null && $line !== '') {
                $lines[] = $paint('label', $line);
            }
        }

        foreach ($report->groups as $index => $group) {
            if ($index > 0 || $lines !== []) {
                $lines[] = '';
            }

            if ($group->name !== '') {
                $lines[] = $paint('heading', $group->name);
            }

            if ($group->note !== null && $group->note !== '') {
                $lines[] = '  ' . $paint('note', $group->note);
            }

            foreach ($group->meters as $meter) {
                $bar = $paint(self::barRole($meter->remaining), self::bar($meter->remaining));
                $lines[] = "  {$bar} " . $paint('label', "{$meter->label}: " . self::detail($meter, $now));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The footer's line: `OpenCode Go: 5h 100% · wk 99% · mo 100%`, and with several groups each
     * prefixed by its short name: `Antigravity: Gemini 5h 100% wk 99% · Claude 5h 100% wk 100%`.
     * A report with no meters says its note.
     */
    public static function statusLine(string $title, UsageReport $report): string
    {
        $groups = array_values(array_filter($report->groups, static fn (UsageGroup $group): bool => $group->meters !== []));

        if ($groups === []) {
            return "{$title}: " . self::firstLine($report->note ?? $report->plan ?? 'no limits reported');
        }

        $meters = static fn (UsageGroup $group, string $glue): string => implode($glue, array_map(
            static fn (UsageMeter $meter): string => ($meter->short ?? $meter->label) . ' ' . $meter->percentLeft() . '%',
            $group->meters,
        ));

        if (count($groups) === 1) {
            return "{$title}: " . $meters($groups[0], ' · ');
        }

        return "{$title}: " . implode(' · ', array_map(
            static fn (UsageGroup $group): string => trim(($group->short ?? $group->name) . ' ' . $meters($group, ' ')),
            $groups,
        ));
    }

    /** The footer's line for a source that could not be asked. */
    public static function statusError(string $title, string $message): string
    {
        return "{$title}: " . self::firstLine($message);
    }

    /**
     * The report as a web panel's blocks (`WebPanel`): a meter per limit, under a card per named
     * group.
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(UsageReport $report, string $locale, ?int $now = null): array
    {
        $blocks = [];

        foreach ([$report->plan, $report->note] as $line) {
            if ($line !== null && $line !== '') {
                $blocks[] = ['type' => 'text', 'text' => $line, 'tone' => 'muted'];
            }
        }

        foreach ($report->groups as $group) {
            $inner = [];

            if ($group->note !== null && $group->note !== '') {
                $inner[] = ['type' => 'text', 'text' => $group->note, 'tone' => 'muted'];
            }

            foreach ($group->meters as $meter) {
                $inner[] = ['type' => 'meter', 'label' => $meter->label, 'value' => $meter->remaining, 'detail' => self::detail($meter, $now, $locale)];
            }

            if ($group->name === '') {
                array_push($blocks, ...$inner);
            } else {
                $blocks[] = ['type' => 'card', 'title' => $group->name, 'blocks' => $inner];
            }
        }

        return $blocks;
    }

    /** `99.2% left · resets 5d 21h`, then the meter's own detail. */
    public static function detail(UsageMeter $meter, ?int $now = null, string $locale = 'en'): string
    {
        $zh = str_starts_with(strtolower($locale), 'zh');
        $reset = self::formatReset($meter->resetsAt, $now);
        $parts = [
            $zh ? "剩余 {$meter->percentLeft()}%" : "{$meter->percentLeft()}% left",
            $zh ? ($reset === 'n/a' ? '重置时间未知' : ($reset === 'now' ? '即将重置' : "{$reset} 后重置")) : "resets {$reset}",
        ];

        if ($meter->detail !== null && $meter->detail !== '') {
            $parts[] = $meter->detail;
        }

        return implode(' · ', $parts);
    }

    /** `[████████----]`, filled by what is left. */
    public static function bar(float $remaining, int $width = self::BAR_WIDTH): string
    {
        $filled = (int) round($remaining * $width);

        return '[' . str_repeat('█', $filled) . str_repeat('-', $width - $filled) . ']';
    }

    /** What a bar is coloured as: half or more left, a fifth or more, less than that. */
    public static function barRole(float $remaining): string
    {
        return match (true) {
            $remaining >= 0.5 => 'plenty',
            $remaining >= 0.2 => 'low',
            default => 'nearlyOut',
        };
    }

    /** `5d 21h`, `5h 0m`, `12m`, `now`; `n/a` when the provider does not say. */
    public static function formatReset(?DateTimeImmutable $resetsAt, ?int $now = null): string
    {
        if ($resetsAt === null) {
            return 'n/a';
        }

        $delta = $resetsAt->getTimestamp() - ($now ?? time());

        if ($delta <= 0) {
            return 'now';
        }

        $minutes = (int) round($delta / 60);
        $days = intdiv($minutes, 60 * 24);
        $hours = intdiv($minutes % (60 * 24), 60);
        $rest = $minutes % 60;

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h {$rest}m",
            default => "{$rest}m",
        };
    }

    private static function firstLine(string $text): string
    {
        return trim(strtok($text, "\n") ?: $text);
    }
}
