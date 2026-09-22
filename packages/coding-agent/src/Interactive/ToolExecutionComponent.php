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
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\Style;

/**
 * One tool call and what came back, redrawn as both arrive.
 *
 * The background carries the state — pending, then green or red — because that is the
 * one thing someone scrolling wants to see without reading anything. Each tool gets a
 * heading naming what it did to what, and output cut to a few lines unless expanded,
 * since a thousand-line read would bury the conversation it belongs to.
 *
 * Ported from upstream's `components/tool-execution.ts`. Not ported: custom tools, which
 * would render themselves, and images, which want `pig/tui`'s `Image` and arrive with it.
 */
final class ToolExecutionComponent extends Container
{
    /** How much of a file to show before it is expanded. */
    private const int FILE_LINES = 10;

    /** A listing is scanned, not read, so more of it fits usefully. */
    private const int LIST_LINES = 20;

    private const int MATCH_LINES = 15;

    /** A command says what happened at the end, so only the end is kept. */
    private const int BASH_LINES = 5;

    /** Tabs are three spaces here: a diff or a listing is narrow enough already. */
    private const string TAB = '   ';

    private readonly Box $box;

    private readonly Text $body;

    private readonly BashOutputComponent $bash;

    private ?AgentToolResult $result = null;

    private bool $failed = false;

    private bool $partial = true;

    private bool $expanded = false;

    /**
     * @param array<string, mixed> $arguments still arriving, so any of them may be missing
     */
    public function __construct(
        private readonly string $tool,
        private array $arguments,
        private readonly Palette $palette,
    ) {
        $this->addChild(new Spacer(1));

        $this->box = new Box(1, 1, $palette->of('toolPendingBg'));
        $this->body = new Text('', 1, 1, $palette->of('toolPendingBg'));
        $this->bash = new BashOutputComponent(
            self::BASH_LINES,
            fn (int $dropped): string => $palette->fg('toolOutput', "... ({$dropped} earlier lines)"),
        );

        // bash is the one tool whose output has to be cut at render width rather than by
        // newlines, so it is the one that needs a box with a component inside it.
        $this->addChild($this->tool === 'bash' ? $this->box : $this->body);

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
        $background = $this->palette->of(match (true) {
            $this->partial => 'toolPendingBg',
            $this->failed => 'toolErrorBg',
            default => 'toolSuccessBg',
        });

        if ($this->tool === 'bash') {
            $this->box->setBackground($background);
            $this->box->clear();
            $this->drawBash();

            return;
        }

        $this->body->setBackground($background);
        $this->body->setText($this->format());
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

        $this->bash->setRows($this->expanded ? PHP_INT_MAX : self::BASH_LINES);
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

        return implode("\n", array_slice($lines, 0, $keep))
            . $this->palette->fg('toolOutput', "\n... ({$rest} more lines)");
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

            if ($block instanceof ImageContent) {
                // Named rather than drawn, until Image is wired in here.
                $parts[] = '[' . $block->mimeType . ' image]';
            }
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
