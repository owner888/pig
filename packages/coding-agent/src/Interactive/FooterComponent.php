<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Width;

/**
 * Two dim lines under everything: where you are, and what this has cost.
 *
 * The one number that changes a decision is the context percentage — it is the
 * difference between "ask one more thing" and "start a new session" — so it is the only
 * thing here that takes a colour, and only once it is worth looking at.
 *
 * Ported from upstream's `components/footer.ts`. Not ported: the auto-compaction marker,
 * which has nothing to report yet, and the watcher on `.git/HEAD` — the branch is re-read
 * on every invalidate, which is what the watcher was arranging anyway.
 */
final class FooterComponent implements Component
{
    /** Past this, the context is worth worrying about. */
    private const float WARN_AT = 70.0;

    private const float ALARM_AT = 90.0;

    /** false once looked for and not found, null when it has not been looked for. */
    private string|false|null $branch = null;

    /** @var array<string, string> what hooks and custom tools have to say, by key */
    private array $statuses = [];

    public function __construct(
        private readonly AgentSession $session,
        private readonly Palette $palette,
        private readonly string $cwd,
    ) {
    }

    #[\Override]
    public function invalidate(): void
    {
        // The branch is the only thing cached, and a checkout is exactly the kind of
        // thing that happens between two frames.
        $this->branch = null;
    }

    /**
     * What a hook or a custom tool wants kept on screen, under a key of its own.
     *
     * Keyed rather than appended so a hook that updates its line replaces it instead of
     * adding another, and null clears it. Newlines and tabs are flattened: this is one
     * line in a fixed layout, and a hook that sent two would push the editor off screen.
     */
    public function setStatus(string $key, ?string $text): void
    {
        if ($text === null || trim($text) === '') {
            unset($this->statuses[$key]);

            return;
        }

        $this->statuses[$key] = (string) preg_replace('/[\r\n\t]+/', ' ', $text);
    }

    #[\Override]
    public function render(int $width): array
    {
        $lines = [
            $this->palette->fg('dim', $this->where($width)),
            $this->status($width),
        ];

        // A third line only when there is something on it, so a session with no hooks has
        // the footer it always had.
        if ($this->statuses !== []) {
            $lines[] = $this->trim(implode(' · ', $this->statuses), $width);
        }

        return $lines;
    }

    /** One line's worth, measured by what is visible rather than by bytes. */
    private function trim(string $text, int $width): string
    {
        return Width::visible($text) <= $width
            ? $text
            : substr(Ansi::strip($text), 0, max(0, $width - 3)) . '...';
    }

    // ---- the top line ----------------------------------------------------------------

    private function where(int $width): string
    {
        $home = getenv('HOME');
        $path = $this->cwd;

        if (is_string($home) && $home !== '' && str_starts_with($path, $home)) {
            $path = '~' . substr($path, strlen($home));
        }

        $branch = $this->currentBranch();

        if ($branch !== false) {
            $path .= " ({$branch})";
        }

        return Width::visible($path) <= $width ? $path : self::elide($path, $width);
    }

    /**
     * Cut from the middle: the end of a path says more than its middle does.
     *
     * Measured in columns, not bytes or characters — a directory named in Chinese is
     * two columns per character, and cutting by length overflows the line.
     */
    private static function elide(string $path, int $width): string
    {
        $half = intdiv($width, 2) - 2;

        if ($half <= 0) {
            return Width::truncate($path, max(1, $width));
        }

        $head = Width::truncate($path, $half);
        $tail = self::lastColumns($path, $half - 1);

        return $head . '...' . $tail;
    }

    /** The last $columns columns of $text. */
    private static function lastColumns(string $text, int $columns): string
    {
        $characters = mb_str_split($text);
        $tail = '';

        while ($characters !== [] && Width::visible(end($characters) . $tail) <= $columns) {
            $tail = array_pop($characters) . $tail;
        }

        return $tail;
    }

    /**
     * The branch, read straight out of `.git/HEAD`.
     *
     * Not by running `git`: this is re-read whenever the footer is invalidated, which is
     * on nearly every frame, and a process launch per frame is not something to do for a
     * word in the corner.
     */
    private function currentBranch(): string|false
    {
        if ($this->branch !== null) {
            return $this->branch;
        }

        $head = $this->headFile();

        if ($head === null) {
            return $this->branch = false;
        }

        $content = file_get_contents($head);

        if ($content === false) {
            return $this->branch = false;
        }

        $content = trim($content);

        return $this->branch = str_starts_with($content, 'ref: refs/heads/')
            ? substr($content, 16)
            : 'detached';
    }

    private function headFile(): ?string
    {
        $directory = $this->cwd;

        while (true) {
            if (is_file($directory . '/.git/HEAD')) {
                return $directory . '/.git/HEAD';
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    // ---- the bottom line --------------------------------------------------------------

    private function status(int $width): string
    {
        $stats = $this->session->stats();
        $parts = [];

        foreach ([['↑', $stats->input], ['↓', $stats->output], ['R', $stats->cacheRead], ['W', $stats->cacheWrite]] as [$mark, $count]) {
            if ($count > 0) {
                $parts[] = $mark . self::tokens($count);
            }
        }

        if ($stats->cost > 0) {
            $parts[] = '$' . number_format($stats->cost, 3);
        }

        $parts[] = $this->context();

        $left = implode(' ', $parts);
        $right = $this->model();

        if (Width::visible($left) > $width) {
            $left = substr(Ansi::strip($left), 0, max(0, $width - 3)) . '...';
        }

        return $this->justify($left, $right, $width);
    }

    /**
     * Left and right on one line, both dimmed.
     *
     * Dimmed in two pieces rather than one, because the context percentage carries its
     * own colour and the reset that ends it would cancel a dim wrapped around the whole
     * line — leaving everything after it bright.
     */
    private function justify(string $left, string $right, int $width): string
    {
        $leftWidth = Width::visible($left);
        $rightWidth = Width::visible($right);

        if ($leftWidth + 2 + $rightWidth <= $width) {
            $gap = str_repeat(' ', $width - $leftWidth - $rightWidth);

            return $this->palette->fg('dim', $left) . $this->palette->fg('dim', $gap . $right);
        }

        $room = $width - $leftWidth - 2;

        if ($room <= 3) {
            return $this->palette->fg('dim', $left);
        }

        $cut = substr(Ansi::strip($right), 0, $room);
        $gap = str_repeat(' ', $width - $leftWidth - strlen($cut));

        return $this->palette->fg('dim', $left) . $this->palette->fg('dim', $gap . $cut);
    }

    private function context(): string
    {
        $model = $this->session->model();
        $window = $model?->contextWindow ?? 0;
        $used = $this->lastTurnTokens();
        $percent = $window > 0 ? $used / $window * 100 : 0.0;
        $display = number_format($percent, 1) . '%/' . self::tokens($window);

        return match (true) {
            $percent > self::ALARM_AT => $this->palette->fg('error', $display),
            $percent > self::WARN_AT => $this->palette->fg('warning', $display),
            default => $display,
        };
    }

    /**
     * What the last turn actually sent.
     *
     * The context is what the *next* request will carry, which is the last complete turn
     * — not the sum over the session, which counts every turn that has already gone.
     * An aborted turn is skipped: it was cut off, so its count is not what the next one
     * will look like.
     */
    private function lastTurnTokens(): int
    {
        foreach (array_reverse($this->session->messages()) as $message) {
            if (!$message instanceof AssistantMessage || $message->stopReason === StopReason::Aborted) {
                continue;
            }

            return $message->usage->input
                + $message->usage->output
                + $message->usage->cacheRead
                + $message->usage->cacheWrite;
        }

        return 0;
    }

    private function model(): string
    {
        $model = $this->session->model();

        if ($model === null) {
            return 'no-model';
        }

        $level = $this->session->thinkingLevel();

        return $model->reasoning && $level !== ThinkingLevel::Off
            ? $model->id . ' • ' . $level->value
            : $model->id;
    }

    /** Short enough to sit in a corner: 950, 9.5k, 95k, 9.5M. */
    private static function tokens(int $count): string
    {
        return match (true) {
            $count < 1_000 => (string) $count,
            $count < 10_000 => number_format($count / 1_000, 1) . 'k',
            $count < 1_000_000 => round($count / 1_000) . 'k',
            $count < 10_000_000 => number_format($count / 1_000_000, 1) . 'M',
            default => round($count / 1_000_000) . 'M',
        };
    }
}
