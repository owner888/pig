<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Theme\Highlight;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\Paths;
use Pig\Tui\Ansi;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\Image;
use Pig\Tui\Components\ImageTheme;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Images\TerminalImage;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\Tui\Component;
use Pig\Tui\Container;
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

    /**
     * A command says what happened at the end, so only the end is kept.
     *
     * Upstream has two numbers here, in two components this one stands in for both of:
     * `tool-execution.ts`'s `BASH_PREVIEW_LINES = 5` for a command the *model* ran, and
     * `bash-execution.ts`'s `PREVIEW_LINES = 20` for one typed with `!`. The difference has
     * a reason — a `!` command is the thing the person just asked for and is looking at,
     * where a model's is one step of something else — so both numbers are here.
     */
    private const int BASH_LINES = 5;
    public const int TYPED_BASH_LINES = 20;

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
     * @param array<string, mixed>          $arguments still arriving, so any of them may be missing
     * @param CustomTool|null               $custom    the declaration, when this is a tool
     *        somebody wrote — for `renderCall` and `renderResult`
     * @param Closure(string): void|null    $onError   told once when a renderer throws
     * @param int  $bashLines  how much command output to keep unexpanded — `TYPED_BASH_LINES`
     *        for a `!` command, the default for one the model ran
     * @param bool $showImages the person's `terminal.showImages`. Off draws the same label a
     *        terminal that cannot draw pictures gets, so the two look alike
     */
    public function __construct(
        private readonly string $tool,
        private array $arguments,
        private readonly Palette $palette,
        private readonly ?CustomTool $custom = null,
        private readonly ?Closure $onError = null,
        // Trailing, and they stay trailing: everything before them is passed positionally
        // from three call sites and a test, and a parameter inserted above this line is a
        // handful of silent off-by-ones.
        private readonly int $bashLines = self::BASH_LINES,
        private bool $showImages = true,
    ) {
        $this->addChild(new Spacer(1));

        $this->box = new Box(1, 1, $palette->of('toolPendingBg'));
        $this->body = new Text('', 1, 1, $palette->of('toolPendingBg'));
        $this->bash = new BashOutputComponent(
            $bashLines,
            fn (int $dropped): string => $palette->fg('toolOutput', "... ({$dropped} earlier lines)"),
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

    public function updateResult(AgentToolResult $result, bool $failed = false, bool $partial = false): void
    {
        $this->result = $result;
        $this->failed = $failed;
        $this->partial = $partial;
        $this->draw();
    }

    /** What a tool that never finished shows — an abort, or a failed turn. */
    public function fail(string $message): void
    {
        $this->updateResult(new AgentToolResult([new TextContent($message)]), true);
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->draw();
    }

    private function draw(): void
    {
        $this->drawImages();

        $background = $this->palette->of(match (true) {
            $this->partial => 'toolPendingBg',
            $this->failed => 'toolErrorBg',
            default => 'toolSuccessBg',
        });

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

        foreach ($this->result?->content ?? [] as $block) {
            if (!$block instanceof ImageContent) {
                continue;
            }

            if (!$this->showImages) {
                // The same label a terminal that cannot draw pictures gets, from the same
                // function, so turning pictures off and not being able to draw them look
                // alike — and the result still says an image came back rather than
                // pretending none did.
                $this->images->addChild(new Text(
                    $this->palette->fg('toolOutput', TerminalImage::fallback(
                        $block->mimeType,
                        ImageDimensions::of($block->data, $block->mimeType),
                    )),
                    1,
                    0,
                ));

                continue;
            }

            $this->images->addChild(new Image(
                $block->data,
                $block->mimeType,
                new ImageTheme(fn (string $text): string => $this->palette->fg('toolOutput', $text)),
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
        $this->drawImages();
    }

    /** Whether this tool brought at least one renderer of its own. */
    private function drawsItself(): bool
    {
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
        $heading = $this->drawnBy(
            'renderCall',
            fn (): mixed => ($this->custom->renderCall)($this->arguments, $this->palette),
        );

        $this->box->addChild($heading ?? new Text(
            $this->palette->fg('toolTitle', Style::bold($this->custom?->label ?? $this->tool)),
            0,
            0,
        ));

        if ($this->result === null) {
            return;
        }

        $body = $this->drawnBy(
            'renderResult',
            fn (): mixed => ($this->custom->renderResult)(
                $this->result,
                new RenderOptions($this->expanded, $this->partial),
                $this->palette,
            ),
        );

        if ($body !== null) {
            $this->box->addChild(new Spacer(1));
            $this->box->addChild($body);

            return;
        }

        // No renderer for the result, or one that failed: the text it produced, which is
        // what every other tool without a special case shows.
        $output = trim($this->output());

        if ($output !== '') {
            $this->box->addChild(new Spacer(1));
            $this->box->addChild(new Text($this->palette->fg('toolOutput', $output), 0, 0));
        }
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
        $shown = $command === '' ? $this->palette->fg('toolOutput', '...') : $command;

        $this->box->addChild(new Text($this->palette->fg('toolTitle', Style::bold('$ ' . $shown)), 0, 0));

        $output = trim($this->output());

        if ($output === '') {
            return;
        }

        $this->bash->setRows($this->expanded ? PHP_INT_MAX : $this->bashLines);
        $this->bash->setText(implode("\n", array_map(
            fn (string $line): string => $this->palette->fg('toolOutput', self::tabs($line)),
            explode("\n", $output),
        )));

        $this->box->addChild(new Spacer(1));
        $this->box->addChild($this->bash);
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

    private function read(): string
    {
        $path = $this->path();
        $text = $this->heading('read') . ' ' . $this->target($path);

        $offset = $this->arguments['offset'] ?? null;
        $limit = $this->arguments['limit'] ?? null;

        if ($offset !== null || $limit !== null) {
            $start = (int) ($offset ?? 1);
            $end = $limit === null ? '' : '-' . ($start + (int) $limit - 1);
            $text .= $this->palette->fg('warning', ":{$start}{$end}");
        }

        return $this->result === null ? $text : $text . "\n\n" . $this->source($this->output(), $path);
    }

    private function write(): string
    {
        $path = $this->path();
        $text = $this->heading('write') . ' ' . $this->target($path);
        $content = (string) ($this->arguments['content'] ?? '');

        // Drawn from the arguments, not from the result: the point of showing a write is
        // seeing what is being written, and by the time it has been written it is too
        // late to care.
        return $content === '' ? $text : $text . "\n\n" . $this->source($content, $path);
    }

    private function edit(): string
    {
        $path = $this->path();
        $details = is_array($this->result?->details) ? $this->result->details : [];
        $line = $details['firstChangedLine'] ?? null;

        $text = $this->heading('edit') . ' ' . $this->target($path)
            . ($line === null ? '' : $this->palette->fg('warning', ":{$line}"));

        if ($this->failed) {
            $said = $this->output();

            return $said === '' ? $text : $text . "\n\n" . $this->palette->fg('error', $said);
        }

        $diff = $details['diff'] ?? '';

        // The diff comes from the tool, which built it from the file it actually wrote.
        // Upstream previews it from the arguments while they are still streaming; doing
        // that here would mean a second copy of the replace logic, which is one copy too
        // many for a preview that arrives a second early.
        return $diff === '' ? $text : $text . "\n\n" . DiffView::render($diff, $this->palette);
    }

    private function find(): string
    {
        $pattern = (string) ($this->arguments['pattern'] ?? '');
        $heading = $this->heading('find') . ' ' . $this->palette->fg('accent', $pattern)
            . $this->palette->fg('toolOutput', ' in ' . $this->path('.'));

        return $this->withOutput($heading . $this->limit(), self::LIST_LINES);
    }

    private function grep(): string
    {
        $pattern = (string) ($this->arguments['pattern'] ?? '');
        $glob = $this->arguments['glob'] ?? null;

        $heading = $this->heading('grep') . ' ' . $this->palette->fg('accent', "/{$pattern}/")
            . $this->palette->fg('toolOutput', ' in ' . $this->path('.'))
            . ($glob === null ? '' : $this->palette->fg('toolOutput', " ({$glob})"));

        return $this->withOutput($heading . $this->limit(), self::MATCH_LINES);
    }

    private function listing(string $name, string $path, int $lines): string
    {
        $heading = $this->heading($name) . ' ' . $this->palette->fg('accent', $path);

        return $this->withOutput($heading . $this->limit(), $lines);
    }

    /** A tool with no display of its own: its arguments, then whatever it said. */
    private function generic(): string
    {
        $text = $this->heading($this->tool);
        $arguments = json_encode($this->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($arguments) && $arguments !== '{}') {
            $text .= "\n\n" . $this->palette->fg('toolOutput', $arguments);
        }

        return $this->withOutput($text, self::LIST_LINES);
    }

    // ---- the pieces they share --------------------------------------------------------

    private function heading(string $name): string
    {
        return $this->palette->fg('toolTitle', Style::bold($name));
    }

    /** A path, or a placeholder while the model is still writing one. */
    private function target(string $path): string
    {
        return $path === ''
            ? $this->palette->fg('toolOutput', '...')
            : $this->palette->fg('accent', $path);
    }

    private function limit(): string
    {
        $limit = $this->arguments['limit'] ?? null;

        return $limit === null ? '' : $this->palette->fg('toolOutput', " (limit {$limit})");
    }

    private function withOutput(string $heading, int $lines): string
    {
        if ($this->result === null) {
            return $heading;
        }

        $output = trim($this->output());

        if ($output === '') {
            return $heading;
        }

        $painted = $this->failed ? $this->palette->of('error') : $this->palette->of('toolOutput');

        return $heading . "\n\n" . $this->cut(
            array_map(static fn (string $line): string => $painted(self::tabs($line)), explode("\n", $output)),
            $lines,
        );
    }

    /** A file's contents, syntax-coloured when the name says what they are. */
    private function source(string $content, string $path): string
    {
        $language = Highlight::languageFromPath($path);

        $lines = $language === null
            ? array_map(fn (string $line): string => $this->palette->fg('toolOutput', self::tabs($line)), explode("\n", $content))
            : Highlight::lines(self::tabs($content), $language, $this->palette->highlightTheme());

        return $this->cut($lines, self::FILE_LINES);
    }

    /**
     * @param list<string> $lines
     */
    private function cut(array $lines, int $keep): string
    {
        if ($this->expanded || count($lines) <= $keep) {
            return implode("\n", $lines);
        }

        $rest = count($lines) - $keep;
        $notice = $this->notice();

        return implode("\n", array_slice($lines, 0, $keep))
            . $this->palette->fg('toolOutput', "\n... ({$rest} more lines)")
            . ($notice === null ? '' : "\n" . $this->palette->fg('warning', "[{$notice}]"));
    }

    /**
     * What the tool says it left out, or null.
     *
     * **Said twice on purpose, and this is the copy for the person.** A tool that cuts its own
     * output puts the notice at the end of the text, where the model reads it last — and the
     * collapsed view keeps the front, so that one line is exactly the line that goes. Upstream
     * has the same two copies for the same reason.
     *
     * Only while the view is cut, which is where upstream and pig part: upstream prints its
     * warning either way, and since pig shows the tool's own sentence rather than a second
     * wording of it, an expanded view would carry the same line twice and read as a fault.
     */
    private function notice(): ?string
    {
        $details = $this->result?->details;
        $notice = is_array($details) ? ($details['notice'] ?? null) : null;

        return is_string($notice) && $notice !== '' ? $notice : null;
    }

    /**
     * What the tool said, as text.
     *
     * Escape sequences are stripped: a tool's output is data, and a command that prints
     * its own colours would otherwise paint over the component's — including the
     * background that says whether it succeeded.
     */
    private function output(): string
    {
        if ($this->result === null) {
            return '';
        }

        $parts = [];

        foreach ($this->result->content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = str_replace("\r", '', Ansi::strip($block->text));

                continue;
            }

            // An image is drawn below rather than named here; a terminal that cannot
            // draw it gets a label from `Image` itself, so naming it twice is all this
            // would add.
        }

        return implode("\n", $parts);
    }

    /** The path argument, shortened to `~` where it is under home. */
    private function path(string $fallback = ''): string
    {
        $path = (string) ($this->arguments['path'] ?? $fallback);

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
