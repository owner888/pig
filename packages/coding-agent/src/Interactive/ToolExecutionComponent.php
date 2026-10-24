<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\Utils\Utf8;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\Theme\Highlight;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\EditDiff;
use Pig\CodingAgent\Tools\Paths;
use Pig\CodingAgent\Tools\Shell;
use Pig\CodingAgent\Tools\Truncate;
use Pig\CodingAgent\Tools\Truncation;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\Image;
use Pig\Tui\Components\ImageOptions;
use Pig\Tui\Components\ImageTheme;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\Style;
use Throwable;

/**
 * One tool call and what came back, redrawn as both arrive.
 *
 * The background carries the state — pending, then green or red — because that is the
 * one thing someone scrolling wants to see without reading anything. Each tool gets a
 * heading naming what it did to what, and output cut to a few lines unless expanded,
 * since a thousand-line read would bury the conversation it belongs to.
 *
 * An image in the result is drawn rather than named, on terminals that can draw one —
 * `pig/tui`'s `Image` falls back to a label by itself elsewhere, so there is nothing to
 * decide here. It sits under the text, because the text is what says which image it is.
 *
 * A custom tool may draw its own heading and its own result, and then none of the
 * formatting below applies to it — a tool whose result is a table is not served by a tool
 * view built for files and commands. A renderer that throws falls back to the default view
 * *and says so once*, because a picture that silently turns into plain text is a bug nobody
 * reports.
 *
 * Ported from upstream's `components/tool-execution.ts`.
 */
final class ToolExecutionComponent extends Container
{
    /** How much of a file to show before it is expanded. */
    private const int FILE_LINES = 10;

    /** A listing is scanned, not read, so more of it fits usefully. */
    private const int LIST_LINES = 20;

    private const int MATCH_LINES = 15;

    /** Upstream's `FALLBACK_PREVIEW_LINES`, for a tool with no display of its own. */
    private const int FALLBACK_LINES = 10;

    /**
     * A command says what happened at the end, so only the end is kept.
     *
     * Upstream's `tool-execution.ts` `BASH_PREVIEW_LINES`, for a command the *model* ran. One
     * typed with `!` is `BashExecutionComponent`, with upstream's own twenty.
     */
    private const int BASH_LINES = 5;

    /** Tabs are three spaces here: a diff or a listing is narrow enough already. */
    private const string TAB = '   ';

    private readonly Box $box;

    private readonly Text $body;

    private readonly BashOutputComponent $bash;

    /** Pictures from the result, under the text. Empty for every tool that returns none. */
    private readonly Container $images;

    private ?AgentToolResult $result = null;

    private bool $failed = false;

    private bool $partial = true;

    private bool $expanded = false;

    /** One complaint per call, however many times a broken renderer is asked. */
    private bool $reported = false;

    /**
     * The three arguments the preview was computed for, or null before there is one.
     *
     * Compared rather than a flag, because `setArgsComplete()` can be called again for the
     * same call and the file read is not free — upstream keys the same cache on a
     * `JSON.stringify` of the three.
     *
     * @var array{0: string, 1: string, 2: string}|null
     */
    private ?array $previewOf = null;

    private string $previewDiff = '';

    private ?int $previewLine = null;

    private ?string $previewError = null;

    private ?float $endedAt = null;

    /** Upstream's `result.durationMs`: the recorded execution time of a final result, when it has one. */
    private ?int $durationMs = null;

    /**
     * @param array<string, mixed>          $arguments still arriving, so any of them may be missing
     * @param CustomTool|null               $custom    the declaration, when this is a tool
     *        somebody wrote — for `renderCall` and `renderResult`
     * @param Closure(string): void|null    $onError   told once when a renderer throws
     * @param int  $bashLines  how much command output to keep unexpanded
     * @param bool $showImages the person's `terminal.showImages`. Off draws the same label a
     *        terminal that cannot draw pictures gets, so the two look alike
     * @param string|null $cwd where the project is, for `setArgsComplete()` to read the file
     *        an edit is about. Null means nobody said, and then there is no preview — which
     *        is what the two `!command` call sites want, since neither is ever an edit
     */
    public function __construct(
        private readonly string $tool,
        private array $arguments,
        private readonly ?CustomTool $custom = null,
        private readonly ?Closure $onError = null,
        // Trailing, and they stay trailing: everything before them is passed positionally
        // from three call sites and a test, and a parameter inserted above this line is a
        // handful of silent off-by-ones.
        private readonly int $bashLines = self::BASH_LINES,
        private bool $showImages = true,
        private readonly ?string $cwd = null,
        /** @var array{renderCall?: Closure, renderResult?: Closure}|null */
        private readonly ?array $toolRenderers = null,
        private ?float $startedAt = null,
        /** Upstream's `options.outputPad`: the blank column either side of the box. */
        private int $outputPad = 1,
        /** Upstream's `options.imageWidthCells`: how wide a picture is drawn, in cells. */
        private int $imageWidthCells = 60,
        /** `keyText("app.tools.expand")`, for the `ctrl+o to expand` hints. */
        private readonly string $expandKey = 'ctrl+o',
    ) {
        $this->addChild(new Spacer(1));

        $this->box = new Box($outputPad, 1, static fn (string $text): string => Themes::theme()->bg('toolPendingBg', $text));
        $this->body = new Text('', $outputPad, 1, static fn (string $text): string => Themes::theme()->bg('toolPendingBg', $text));
        // Upstream's `formatHint`: `... (N earlier lines, ctrl+o to expand)`.
        $this->bash = new BashOutputComponent(
            $bashLines,
            fn (int $dropped): string => Themes::theme()->fg('muted', "... ({$dropped} earlier lines,")
                . ' ' . $this->keyHint('to expand') . Themes::theme()->fg('muted', ')'),
        );

        // bash is the one tool whose output has to be cut at render width rather than by
        // newlines, so it is the one that needs a box with a component inside it — and a
        // custom tool hands back components too, so it needs the same.
        $this->addChild($this->tool === 'bash' || $this->drawsItself() ? $this->box : $this->body);

        $this->images = new Container();
        $this->addChild($this->images);

        $this->draw();
    }

    /** @param array<string, mixed> $arguments */
    public function updateArgs(array $arguments): void
    {
        $this->arguments = $arguments;
        $this->draw();
    }

    /**
     * The arguments have stopped arriving, so an edit can be shown before it is made.
     *
     * Upstream's `setArgsComplete()`, called once the assistant message has ended — every
     * tool call in it is then whole, and the ones that are edits can be read off the file
     * as it still stands. See `EditDiff::preview()` for what that window is worth.
     *
     * A refusal is kept and drawn in place of the diff, because "Found 3 occurrences" is
     * worth reading before the call runs rather than after it has failed.
     */
    public function setArgsComplete(): void
    {
        if ($this->tool !== 'edit' || $this->cwd === null) {
            return;
        }

        $path = $this->arguments['path'] ?? null;
        $old = $this->arguments['oldText'] ?? null;
        $new = $this->arguments['newText'] ?? null;

        // Any of the three may still be missing: a turn can end on a call whose arguments
        // never finished arriving, and then there is nothing to preview.
        if (!is_string($path) || !is_string($old) || !is_string($new)) {
            return;
        }

        if ($this->previewOf === [$path, $old, $new]) {
            return;
        }

        $this->previewOf = [$path, $old, $new];

        try {
            [$this->previewDiff, $this->previewLine] = EditDiff::preview($path, $old, $new, $this->cwd);
            $this->previewError = null;
        } catch (AgentError $problem) {
            $this->previewDiff = '';
            $this->previewLine = null;
            $this->previewError = $problem->getMessage();
        }

        $this->draw();
    }

    /** @param int|null $durationMs the execution time of a final result — the end event's, or the saved message's */
    public function updateResult(AgentToolResult $result, bool $failed = false, bool $partial = false, ?int $durationMs = null): void
    {
        $this->result = $result;
        $this->failed = $failed;
        $this->partial = $partial;
        $this->durationMs = $partial ? null : $durationMs;
        if (!$partial || $failed) {
            $this->endedAt ??= microtime(true);
        }
        $this->draw();
    }

    /** What a tool that never finished shows — an abort, or a failed turn. */
    public function fail(string $message): void
    {
        $this->endedAt ??= microtime(true);
        $this->updateResult(new AgentToolResult([new TextContent($message)]), true);
    }

    /** Redrawn so a theme change reaches the text drawn into it, as upstream's `invalidate()` does. */
    #[\Override]
    public function invalidate(): void
    {
        parent::invalidate();
        $this->draw();
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->draw();
    }

    /** Upstream's `setOutputPad()`: both shells, since either may be the one on screen. */
    public function setOutputPad(int $outputPad): void
    {
        $this->outputPad = $outputPad;
        $this->box->setPaddingX($outputPad);
        $this->body->setPaddingX($outputPad);
        $this->draw();
    }

    private function draw(): void
    {
        $this->drawImages();

        $backgroundToken = match (true) {
            $this->partial => 'toolPendingBg',
            $this->failed => 'toolErrorBg',
            default => 'toolSuccessBg',
        };
        $background = static fn (string $text): string => Themes::theme()->bg($backgroundToken, $text);

        if ($this->drawsItself()) {
            $this->box->setBackground($background);
            $this->box->clear();
            $this->drawCustom();

            return;
        }

        if ($this->tool === 'bash') {
            $this->box->setBackground($background);
            $this->box->clear();
            $this->drawBash();

            return;
        }

        $this->body->setBackground($background);
        $this->body->setText($this->format());
    }

    /**
     * Rebuild the pictures from the result.
     *
     * Rebuilt rather than added to, because a result arrives more than once while a tool
     * is still running and the same image would otherwise be drawn on every update.
     */
    private function drawImages(): void
    {
        $this->images->clear();

        // Upstream's: a picture is drawn, a blank line above it, only where the terminal can draw
        // one and the person wants it; otherwise its label is a line of the text (`output()`).
        if (!$this->drawsPictures()) {
            return;
        }

        foreach ($this->result?->content ?? [] as $block) {
            if (!$block instanceof ImageContent) {
                continue;
            }

            $this->images->addChild(new Spacer(1));
            $this->images->addChild(new Image(
                $block->data,
                $block->mimeType,
                new ImageTheme(fn (string $text): string => Themes::theme()->fg('toolOutput', $text)),
                new ImageOptions(maxWidthCells: $this->imageWidthCells),
            ));
        }
    }

    /**
     * Draw pictures, or name them.
     *
     * Upstream's `setShowImages`, and it exists for the same reason `setExpanded` does: the
     * setting is changed from `/settings` while a transcript is already on screen, and a
     * transcript that keeps the answer from when it was drawn is a setting that only applies
     * to what happens next.
     */
    public function setShowImages(bool $show): void
    {
        if ($show === $this->showImages) {
            return;
        }

        $this->showImages = $show;
        $this->draw();
    }

    /** Upstream's `setImageWidthCells()`, for the `/settings` row while a transcript is on screen. */
    public function setImageWidthCells(int $width): void
    {
        $width = max(1, $width);

        if ($width === $this->imageWidthCells) {
            return;
        }

        $this->imageWidthCells = $width;
        $this->drawImages();
    }

    private function drawsPictures(): bool
    {
        return $this->showImages && TerminalImage::getCapabilities()->images !== null;
    }

    /** Whether this tool brought at least one renderer of its own. */
    private function drawsItself(): bool
    {
        // `!==` binds tighter than `??`: without the parentheses this read `a ?? ((false || b) ?? false)`,
        // reaching `b` outside any `??` and warning on the `[]` a tool with no renderers resolves to.
        if ($this->toolRenderers !== null && (($this->toolRenderers['renderCall'] ?? null) !== null || ($this->toolRenderers['renderResult'] ?? null) !== null)) {
            return true;
        }

        return $this->custom !== null
            && ($this->custom->renderCall !== null || $this->custom->renderResult !== null);
    }

    // ---- a tool that draws itself ------------------------------------------------------

    /**
     * The tool's own heading and its own result, each falling back on its own.
     *
     * Two independent halves, as upstream has them: a tool may draw its heading and leave
     * the result to the default text, or the other way round.
     */
    private function drawCustom(): void
    {
        $renderCall = $this->toolRenderers['renderCall'] ?? $this->custom?->renderCall;
        $heading = $renderCall === null ? null : $this->drawnBy(
            'renderCall',
            fn (): mixed => $renderCall($this->arguments, Themes::theme(), $this->renderOptions()),
        );

        // Upstream's `createCallFallback()`: the name and its arguments.
        $this->box->addChild($heading ?? new Text($this->callWithArgs(), 0, 0));

        if ($this->result === null) {
            return;
        }

        $renderResult = $this->toolRenderers['renderResult'] ?? $this->custom?->renderResult;
        $body = $renderResult === null ? null : $this->drawnBy(
            'renderResult',
            fn (): mixed => $renderResult(
                $this->result,
                $this->renderOptions(),
                Themes::theme(),
            ),
        );

        // Straight under the heading, as upstream adds it: a renderer that wants a gap draws one.
        if ($body !== null) {
            $this->box->addChild($body);

            return;
        }

        // No renderer for the result, or one that failed: upstream's `createResultFallback()`.
        $fallback = $this->resultFallback();

        if ($fallback !== '') {
            $this->box->addChild(new Text($fallback, 0, 0));
        }
    }

    private function renderOptions(): RenderOptions
    {
        return new RenderOptions($this->expanded, $this->partial, $this->failed, $this->timerText(), $this->durationMs, $this->outputPad);
    }

    /**
     * Upstream's `toolTimerText()`: "A final result's recorded duration wins: it is monotonic and
     * survives reloads. The renderer's own clock is the fallback for live progress and for results
     * stored without one."
     */
    private function timerText(): ?string
    {
        if (!$this->partial && $this->durationMs !== null) {
            return 'Took ' . self::formatDuration($this->durationMs / 1000);
        }

        if ($this->startedAt === null) {
            return null;
        }

        return ($this->partial ? 'Elapsed' : 'Took') . ' ' . self::formatDuration(($this->endedAt ?? microtime(true)) - $this->startedAt);
    }

    /**
     * Call one of the tool's renderers, or answer null.
     *
     * Null for three different reasons on purpose — no renderer, a renderer that returned
     * nothing, a renderer that threw — because the caller does the same thing in all three:
     * draw the default. The difference is that the third is reported, once per call, since
     * `draw()` runs again on every update and a broken renderer would otherwise fill the
     * transcript with its own failure.
     *
     * @param Closure(): mixed $call
     */
    private function drawnBy(string $which, Closure $call): ?Component
    {
        if ($this->custom?->{$which} === null) {
            return null;
        }

        try {
            $drawn = $call();
        } catch (Throwable $error) {
            $this->complain("{$which} failed: " . $error::class . ': ' . $error->getMessage());

            return null;
        }

        if ($drawn === null || $drawn instanceof Component) {
            return $drawn;
        }

        $this->complain("{$which} returned " . get_debug_type($drawn) . ', expected a component');

        return null;
    }

    private function complain(string $message): void
    {
        if ($this->reported || $this->onError === null) {
            return;
        }

        $this->reported = true;
        ($this->onError)($message);
    }

    // ---- bash ----------------------------------------------------------------------

    private function drawBash(): void
    {
        $command = (string) ($this->arguments['command'] ?? '');
        $shown = $command === '' ? Themes::theme()->fg('toolOutput', '...') : $command;
        $timeout = $this->arguments['timeout'] ?? null;
        $timeoutSuffix = is_numeric($timeout) && $timeout > 0 ? Themes::theme()->fg('muted', " (timeout {$timeout}s)") : '';

        $this->box->addChild(new Text(Themes::theme()->fg('toolTitle', Style::bold('$ ' . $shown)) . $timeoutSuffix, 0, 0));

        $output = trim($this->output());
        $details = $this->details();
        $truncation = self::truncation($details['truncation'] ?? null);
        $fullOutputPath = is_string($details['fullOutputPath'] ?? null) ? $details['fullOutputPath'] : null;

        // Upstream's: the tool's own `[… Full output: …]` footer goes, since the warning below says it.
        if (!$this->partial && ($truncation['truncated'] ?? false) && $fullOutputPath !== null && str_ends_with($output, ']')) {
            $footer = strrpos($output, "\n\n[");

            if ($footer !== false && str_contains(substr($output, $footer), $fullOutputPath)) {
                $output = rtrim(substr($output, 0, $footer));
            }
        }

        if ($output !== '') {
            $this->bash->setRows($this->expanded ? PHP_INT_MAX : $this->bashLines);
            $this->bash->setText(implode("\n", array_map(
                fn (string $line): string => Themes::theme()->fg('toolOutput', self::tabs($line)),
                explode("\n", $output),
            )));

            $this->box->addChild(new Spacer(1));
            $this->box->addChild($this->bash);
        }

        $this->drawBashStatus($truncation, $fullOutputPath);
    }

    /**
     * Upstream's warning and timer lines under the output, each with a blank line above it:
     * `[Full output: <path>. Truncated: showing X of Y lines]`, then `Took 0.4s`.
     *
     * @param array<string, mixed>|null $truncation
     */
    private function drawBashStatus(?array $truncation, ?string $fullOutputPath): void
    {
        $warnings = [];

        if ($fullOutputPath !== null) {
            $warnings[] = "Full output: {$fullOutputPath}";
        }

        if ($truncation['truncated'] ?? false) {
            $warnings[] = ($truncation['truncatedBy'] ?? null) === 'lines'
                ? "Truncated: showing {$truncation['outputLines']} of {$truncation['totalLines']} lines"
                : "Truncated: {$truncation['outputLines']} lines shown (" . Truncate::size((int) ($truncation['maxBytes'] ?? Truncate::MAX_BYTES)) . ' limit)';
        }

        if ($warnings !== []) {
            $this->box->addChild(new Text("\n" . Themes::theme()->fg('warning', '[' . implode('. ', $warnings) . ']'), 0, 0));
        }

        $timer = $this->timerText();

        if ($timer !== null) {
            $this->box->addChild(new Text("\n" . Themes::theme()->fg('muted', $timer), 0, 0));
        }
    }

    public static function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $totalSeconds = (int) floor($seconds);
        $minutes = (int) floor($totalSeconds / 60);
        $remainder = $totalSeconds % 60;
        if ($minutes < 60) {
            return "{$minutes}m {$remainder}s";
        }

        $hours = (int) floor($minutes / 60);
        $remainderMinutes = $minutes % 60;

        return "{$hours}h {$remainderMinutes}m {$remainder}s";
    }

    // ---- everything else -------------------------------------------------------------

    private function format(): string
    {
        return match ($this->tool) {
            'read' => $this->read(),
            'write' => $this->write(),
            'edit' => $this->edit(),
            'ls' => $this->listing('ls', $this->path('.'), self::LIST_LINES),
            'find' => $this->find(),
            'grep' => $this->grep(),
            default => $this->generic(),
        };
    }

    /** Upstream's `COMPACT_RESOURCE_FILE_NAMES`: read as a one-line label until expanded. */
    private const array COMPACT_RESOURCE_FILES = ['AGENTS.override.md', 'AGENTS.md', 'AGENTS.MD', 'CLAUDE.md', 'CLAUDE.MD'];

    private function read(): string
    {
        $raw = $this->rawPath();
        $range = self::readRange($this->arguments['offset'] ?? null, $this->arguments['limit'] ?? null);
        $rangeText = $range === '' ? '' : Themes::theme()->fg('warning', $range);
        $compact = $this->expanded ? null : $this->compactRead($raw, $rangeText);
        $text = $compact ?? $this->heading('read') . ' ' . $this->target($this->path()) . $rangeText;

        // Collapsed, a read is its heading alone, as upstream's: the file is a ctrl+o away.
        if ($this->result === null || (!$this->expanded && !$this->failed)) {
            return $text;
        }

        $output = $this->output();
        $language = !$this->failed && $raw !== '' ? Highlight::languageFromPath($raw) : null;
        $lines = self::trimTrailingEmpty($language === null ? explode("\n", $output) : Themes::highlightCode(self::tabs($output), $language));
        $shown = $this->expanded ? $lines : array_slice($lines, 0, self::FILE_LINES);
        $remaining = count($lines) - count($shown);

        $text .= "\n\n" . implode("\n", array_map(
            fn (string $line): string => $language === null ? Themes::theme()->fg('toolOutput', self::tabs($line)) : self::tabs($line),
            $shown,
        ));

        if ($remaining > 0) {
            $text .= $this->moreLines("{$remaining} more lines");
        }

        $truncation = self::truncation($this->details()['truncation'] ?? null);

        if ($truncation['truncated'] ?? false) {
            $text .= "\n" . Themes::theme()->fg('warning', match (true) {
                (bool) ($truncation['firstLineExceedsLimit'] ?? false) => '[First line exceeds ' . Truncate::size((int) ($truncation['maxBytes'] ?? Truncate::MAX_BYTES)) . ' limit]',
                ($truncation['truncatedBy'] ?? null) === 'lines' => "[Truncated: showing {$truncation['outputLines']} of {$truncation['totalLines']} lines (" . ($truncation['maxLines'] ?? Truncate::MAX_LINES) . ' line limit)]',
                default => "[Truncated: {$truncation['outputLines']} lines shown (" . Truncate::size((int) ($truncation['maxBytes'] ?? Truncate::MAX_BYTES)) . ' limit)]',
            });
        }

        return $text;
    }

    /**
     * Upstream's `formatCompactReadCall()`: a skill, one of pig's own docs, or an AGENTS/CLAUDE
     * file, as one line with the expand key — null for any other file.
     */
    private function compactRead(string $raw, string $rangeText): ?string
    {
        if ($raw === '') {
            return null;
        }

        $absolute = Paths::resolve($raw, $this->cwd ?? (getcwd() ?: '.'));
        $file = basename($absolute);
        $hint = Themes::theme()->fg('dim', " ({$this->expandKey} to expand)");

        if ($file === 'SKILL.md') {
            $label = basename(dirname($absolute)) ?: $file;

            return Themes::theme()->fg('customMessageLabel', "\x1b[1m[skill]\x1b[22m ")
                . Themes::theme()->fg('customMessageText', $label) . $rangeText . $hint;
        }

        // Upstream's `getPiDocsClassification()`: the package's README, docs/ and examples/.
        $root = (string) realpath(dirname(__DIR__, 4));
        $relative = $root !== '' && str_starts_with($absolute, $root . '/') ? substr($absolute, strlen($root) + 1) : null;

        if ($relative !== null && ($relative === 'README.md' || str_starts_with($relative, 'docs/') || str_starts_with($relative, 'examples/'))) {
            return Themes::theme()->fg('toolTitle', Style::bold('read docs')) . ' ' . Themes::theme()->fg('accent', $relative) . $rangeText . $hint;
        }

        if (in_array($file, self::COMPACT_RESOURCE_FILES, true)) {
            $label = $this->cwd === null ? $absolute : Paths::relative($absolute, $this->cwd);

            return Themes::theme()->fg('toolTitle', Style::bold('read resource')) . ' ' . Themes::theme()->fg('accent', $label) . $rangeText . $hint;
        }

        return null;
    }

    /** Upstream's `formatReadLineRange()`: `:start`, `:start-end`, or nothing. */
    private static function readRange(mixed $offset, mixed $limit): string
    {
        if ($offset === null && $limit === null) {
            return '';
        }

        $start = $offset === null ? 1 : (is_numeric($offset) ? (int) $offset : null);

        if ($start === null) {
            return '';
        }

        if ($limit === null) {
            return ":{$start}";
        }

        if (!is_numeric($limit)) {
            return '';
        }

        $end = $start + (int) $limit - 1;

        return ":{$start}" . ($end !== 0 ? "-{$end}" : '');
    }

    private function write(): string
    {
        $text = $this->heading('write') . ' ' . $this->target($this->path());
        $content = $this->arguments['content'] ?? '';

        if (!is_string($content)) {
            $text .= "\n\n" . Themes::theme()->fg('error', '[invalid content arg - expected string]');
        } elseif ($content !== '') {
            // Drawn from the arguments, as upstream's: what is being written is the point.
            $raw = $this->rawPath();
            $language = $raw === '' ? null : Highlight::languageFromPath($raw);
            $display = str_replace("\r", '', $content);
            $lines = self::trimTrailingEmpty($language === null ? explode("\n", $display) : Themes::highlightCode(self::tabs($display), $language));
            $total = count($lines);
            $shown = $this->expanded ? $lines : array_slice($lines, 0, self::FILE_LINES);
            $remaining = $total - count($shown);

            $text .= "\n\n" . implode("\n", array_map(
                fn (string $line): string => $language === null ? Themes::theme()->fg('toolOutput', self::tabs($line)) : $line,
                $shown,
            ));

            if ($remaining > 0) {
                $text .= $this->moreLines("{$remaining} more lines, {$total} total");
            }
        }

        // Upstream's `formatWriteResult()`: only a failure says anything, in red, under the content.
        if ($this->failed && $this->result !== null) {
            $said = $this->output();

            if ($said !== '') {
                $text .= "\n\n" . Themes::theme()->fg('error', $said);
            }
        }

        return $text;
    }

    private function edit(): string
    {
        $details = $this->details();
        $text = $this->heading('edit') . ' ' . $this->target($this->path());

        if ($this->failed) {
            $said = $this->output();

            $said = $said === '' ? (string) $this->previewError : $said;

            return $said === '' ? $text : $text . "\n\n" . Themes::theme()->fg('error', $said);
        }

        // The result's diff once there is one — upstream draws it when it differs from the
        // preview — and the preview's until then.
        $diff = (string) ($details['diff'] ?? $this->previewDiff);

        if ($diff === '' && $this->previewError !== null) {
            return $text . "\n\n" . Themes::theme()->fg('error', $this->previewError);
        }

        return $diff === '' ? $text : $text . "\n\n" . DiffView::render($diff);
    }

    private function find(): string
    {
        $heading = $this->heading('find') . ' ' . Themes::theme()->fg('accent', (string) ($this->arguments['pattern'] ?? ''))
            . Themes::theme()->fg('toolOutput', ' in ' . $this->path('.'))
            . $this->limit(' (limit %s)');
        $details = $this->details();
        $warnings = array_values(array_filter([
            ($details['resultLimitReached'] ?? null) ? "{$details['resultLimitReached']} results limit" : null,
            $this->sizeLimitWarning($details),
        ]));

        return $this->withOutput($heading, self::LIST_LINES, $warnings);
    }

    private function grep(): string
    {
        $glob = $this->arguments['glob'] ?? null;
        $heading = $this->heading('grep') . ' ' . Themes::theme()->fg('accent', '/' . (string) ($this->arguments['pattern'] ?? '') . '/')
            . Themes::theme()->fg('toolOutput', ' in ' . $this->path('.'))
            . (is_string($glob) && $glob !== '' ? Themes::theme()->fg('toolOutput', " ({$glob})") : '')
            . $this->limit(' limit %s');
        $details = $this->details();
        $warnings = array_values(array_filter([
            ($details['matchLimitReached'] ?? null) ? "{$details['matchLimitReached']} matches limit" : null,
            $this->sizeLimitWarning($details),
            ($details['linesTruncated'] ?? null) ? 'some lines truncated' : null,
        ]));

        return $this->withOutput($heading, self::MATCH_LINES, $warnings);
    }

    private function listing(string $name, string $path, int $lines): string
    {
        $heading = $this->heading($name) . ' ' . $this->target($path) . $this->limit(' (limit %s)');
        $details = $this->details();
        $warnings = array_values(array_filter([
            ($details['entryLimitReached'] ?? null) ? "{$details['entryLimitReached']} entries limit" : null,
            $this->sizeLimitWarning($details),
        ]));

        return $this->withOutput($heading, $lines, $warnings);
    }

    /** Upstream's two fallbacks for a tool with no display of its own: the call, then the result straight under it. */
    private function generic(): string
    {
        $text = $this->callWithArgs();
        $result = $this->result === null ? '' : $this->resultFallback();

        return $result === '' ? $text : $text . "\n" . $result;
    }

    /**
     * Upstream's `formatToolCallWithArgs()`: collapsed, `key=value` pairs on the title line in
     * muted, cut to 100 characters; expanded, a `key: value` line each below it.
     */
    private function callWithArgs(): string
    {
        $header = $this->heading($this->tool);

        if ($this->arguments === []) {
            return $header;
        }

        $json = static fn (mixed $value, int $flags = 0): string => (string) json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($this->expanded) {
            $lines = [];

            foreach ($this->arguments as $key => $value) {
                $text = is_string($value) ? $value : $json($value, JSON_PRETTY_PRINT);
                $lines[] = "  {$key}: " . str_replace("\n", "\n    ", str_replace("\r", '', self::tabs($text)));
            }

            return $header . "\n" . Themes::theme()->fg('muted', implode("\n", $lines));
        }

        $pairs = implode(' ', array_map(
            static fn (string|int $key, mixed $value): string => "{$key}=" . $json($value),
            array_keys($this->arguments),
            $this->arguments,
        ));
        $preview = mb_strlen($pairs) > 100 ? mb_substr($pairs, 0, 97) . '...' : $pairs;

        return $header . ' ' . Themes::theme()->fg('muted', $preview);
    }

    /** Upstream's `createResultFallback()`: ten lines of the text, then the hint. */
    private function resultFallback(): string
    {
        $output = $this->output();

        if ($output === '') {
            return '';
        }

        $lines = explode("\n", $output);
        $shown = $this->expanded ? $lines : array_slice($lines, 0, self::FALLBACK_LINES);
        $remaining = count($lines) - count($shown);
        $text = implode("\n", array_map(static fn (string $line): string => Themes::theme()->fg('toolOutput', $line), $shown));

        return $remaining > 0 ? $text . $this->moreLines("{$remaining} more lines") : $text;
    }

    // ---- the pieces they share --------------------------------------------------------

    private function heading(string $name): string
    {
        return Themes::theme()->fg('toolTitle', Style::bold($name));
    }

    /** A path, or a placeholder while the model is still writing one. */
    private function target(string $path): string
    {
        return $path === ''
            ? Themes::theme()->fg('toolOutput', '...')
            : Themes::theme()->fg('accent', $path);
    }

    /** The `limit` argument as upstream writes it for this tool, or nothing. */
    private function limit(string $format): string
    {
        $limit = $this->arguments['limit'] ?? null;

        return $limit === null ? '' : Themes::theme()->fg('toolOutput', sprintf($format, (string) $limit));
    }

    /**
     * Upstream's ls / find / grep result: the text trimmed, the first $lines of it, the hint, and
     * `[Truncated: …]` for what the tool says it left out.
     *
     * @param list<string> $warnings
     */
    private function withOutput(string $heading, int $lines, array $warnings): string
    {
        if ($this->result === null) {
            return $heading;
        }

        $text = $heading;
        $output = trim($this->output());

        if ($output !== '') {
            $all = explode("\n", $output);
            $shown = $this->expanded ? $all : array_slice($all, 0, $lines);
            $remaining = count($all) - count($shown);
            $text .= "\n\n" . implode("\n", array_map(static fn (string $line): string => Themes::theme()->fg('toolOutput', $line), $shown));

            if ($remaining > 0) {
                $text .= $this->moreLines("{$remaining} more lines");
            }
        }

        if ($warnings !== []) {
            $text .= ($output === '' ? "\n\n" : "\n") . Themes::theme()->fg('warning', '[Truncated: ' . implode(', ', $warnings) . ']');
        }

        return $text;
    }

    /** `50KB limit` when the tool cut its output by size, as upstream's warnings say it. */
    private function sizeLimitWarning(array $details): ?string
    {
        $truncation = self::truncation($details['truncation'] ?? null);

        return ($truncation['truncated'] ?? false)
            ? Truncate::size((int) ($truncation['maxBytes'] ?? Truncate::MAX_BYTES)) . ' limit'
            : null;
    }

    /** Upstream's `... (N more lines, ctrl+o to expand)`, on a line of its own. */
    private function moreLines(string $counted): string
    {
        return Themes::theme()->fg('muted', "\n... ({$counted},") . ' ' . $this->keyHint('to expand') . Themes::theme()->fg('muted', ')');
    }

    /** Upstream's `keyHint()`: the key dim, the words muted. */
    private function keyHint(string $description): string
    {
        return Themes::theme()->fg('dim', $this->expandKey) . Themes::theme()->fg('muted', " {$description}");
    }

    /** @return array<string, mixed> */
    private function details(): array
    {
        $details = $this->result?->details;

        return is_array($details) ? $details : [];
    }

    /**
     * A `Truncation` as an array: the object while the session is live, its JSON once it was read
     * back from the file.
     *
     * @return array<string, mixed>|null
     */
    private static function truncation(mixed $truncation): ?array
    {
        return match (true) {
            $truncation instanceof Truncation => get_object_vars($truncation),
            is_array($truncation) => $truncation,
            default => null,
        };
    }

    /** @param list<string> $lines */
    private static function trimTrailingEmpty(array $lines): array
    {
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * What the tool said, as text a frame can hold.
     *
     * Every byte of it came from a file or a subprocess, so it goes through `Shell::sanitize()`, whose
     * docblock is where the three reasons are — and the reason there is a class rather than
     * three copies of the rule is that this was the only one of the three sites that had it.
     */
    private function output(): string
    {
        if ($this->result === null) {
            return '';
        }

        $parts = [];
        $labels = [];

        foreach ($this->result->content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = str_replace("\r", '', Shell::sanitize($block->text));

                continue;
            }

            // Upstream's `getTextOutput()`: a picture that is not drawn is named in the text.
            if ($block instanceof ImageContent && !$this->drawsPictures()) {
                $labels[] = TerminalImage::imageFallback($block->mimeType, TerminalImage::getImageDimensions($block->data, $block->mimeType));
            }
        }

        $text = implode("\n", $parts);

        if ($labels !== []) {
            $text = $text === '' ? implode("\n", $labels) : $text . "\n" . implode("\n", $labels);
        }

        return $text;
    }


    /** The path argument as the model wrote it — `file_path` too, as upstream reads it. */
    private function rawPath(): string
    {
        $path = $this->arguments['file_path'] ?? $this->arguments['path'] ?? '';

        return is_string($path) ? $path : '';
    }

    /** The path argument, shortened to `~` where it is under home. */
    private function path(string $fallback = ''): string
    {
        $path = $this->rawPath() ?: $fallback;

        if ($path === '') {
            return '';
        }

        $home = Paths::expand('~');

        return $home !== '~' && str_starts_with($path, $home) ? '~' . substr($path, strlen($home)) : $path;
    }

    private static function tabs(string $text): string
    {
        return str_replace("\t", self::TAB, $text);
    }
}
