<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\Shell;
use Pig\Tui\Components\Loader;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\TUI;

/**
 * A command typed with `!` or `!!`, drawn as upstream's `bash-execution.ts` draws it.
 *
 * A blank row, a rule in the bash-mode colour, the command in bold, its output, then the loader
 * while it runs and the status parts once it has finished, and a rule to close. `!!` is drawn in
 * `dim` rather than `bashMode`, because its output is kept out of the conversation and the colour
 * is how the screen says so.
 *
 * This used to be `ToolExecutionComponent` with `TYPED_BASH_LINES` — one component for a command
 * the person typed and one the model ran — and the developer asked for upstream's two, to the
 * line. What the shared component had learned on the way (the exit code, escape, the spill path)
 * is all in `setComplete()`, which is where upstream keeps it.
 *
 * The output is the tail, cut by visual rows at the terminal's width (`BashOutputComponent`), as
 * upstream's `truncateToVisualLines` cuts it; the "more lines" count under it is the *logical*
 * count, as upstream counts it.
 */
final class BashExecutionComponent extends Container
{
    /** Upstream's `PREVIEW_LINES`: how much output a collapsed command shows. */
    public const int PREVIEW_LINES = 20;

    /** @var list<string> */
    private array $outputLines = [];

    /** @var 'running'|'complete'|'cancelled'|'error' */
    private string $status = 'running';

    private ?int $exitCode = null;

    private bool $truncated = false;

    private ?string $fullOutputPath = null;

    private bool $expanded = false;

    private readonly Container $content;

    private readonly Loader $loader;

    private BashOutputComponent $preview;

    /** `dim` marks `!!` commands, whose output is excluded from the model context. */
    private readonly string $colorKey;

    /**
     * @param string $cancelKey the interrupt key as a sentence names it — `Keybindings::keyText('app.interrupt')`
     * @param string $expandKey the expand key the same way, for the "more lines" hint
     */
    public function __construct(
        private readonly string $command,
        TUI $tui,
        bool $excludeFromContext = false,
        private int $outputPad = 1,
        string $cancelKey = 'escape',
        private readonly string $expandKey = 'ctrl+o',
    ) {
        $this->colorKey = $excludeFromContext ? 'dim' : 'bashMode';
        $border = fn (string $text): string => Themes::theme()->fg($this->colorKey, $text);

        $this->addChild(new Spacer(1));
        $this->addChild(new Rule($border));

        $this->content = new Container();
        $this->addChild($this->content);

        $this->loader = new Loader(
            $tui,
            fn (string $spinner): string => Themes::theme()->fg($this->colorKey, $spinner),
            static fn (string $text): string => Themes::theme()->fg('muted', $text),
            "Running... ({$cancelKey} to cancel)",
        );

        $this->preview = new BashOutputComponent(self::PREVIEW_LINES, paddingX: $outputPad);

        $this->addChild(new Rule($border));

        $this->updateDisplay();
    }

    /** Whether the output is expanded (all of it) or collapsed (the preview only). */
    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->updateDisplay();
    }

    public function setOutputPad(int $outputPad): void
    {
        $this->outputPad = $outputPad;
        // The preview wraps to the width between its pads, so a new pad is a new preview — and
        // its cache, which is keyed on the width, is stale with it.
        $this->preview = new BashOutputComponent(self::PREVIEW_LINES, paddingX: $outputPad);
        $this->updateDisplay();
    }

    /** Redrawn so a theme change reaches the text drawn into it, as upstream's `invalidate()` does. */
    #[\Override]
    public function invalidate(): void
    {
        parent::invalidate();
        $this->updateDisplay();
    }

    public function appendOutput(string $chunk): void
    {
        // Escapes and bytes that are not UTF-8 are cleaned at the pipe, as the tool's are; what
        // is left to do here is the line endings.
        $clean = str_replace(["\r\n", "\r"], "\n", Shell::sanitize($chunk));
        $newLines = explode("\n", $clean);

        if ($this->outputLines !== []) {
            // The first piece continues the last line, which was not complete.
            $this->outputLines[count($this->outputLines) - 1] .= $newLines[0];
            array_push($this->outputLines, ...array_slice($newLines, 1));
        } else {
            $this->outputLines = $newLines;
        }

        $this->updateDisplay();
    }

    public function setComplete(?int $exitCode, bool $cancelled, bool $truncated = false, ?string $fullOutputPath = null): void
    {
        $this->exitCode = $exitCode;
        $this->status = $cancelled
            ? 'cancelled'
            : ($exitCode !== null && $exitCode !== 0 ? 'error' : 'complete');
        $this->truncated = $truncated;
        $this->fullOutputPath = $fullOutputPath;

        $this->loader->stop();
        $this->updateDisplay();
    }

    /** The output as it stands, for the message that records the command. */
    public function output(): string
    {
        return implode("\n", $this->outputLines);
    }

    public function command(): string
    {
        return $this->command;
    }

    private function updateDisplay(): void
    {
        $this->content->clear();

        $this->content->addChild(new Text(
            Themes::theme()->fg($this->colorKey, Themes::theme()->bold('$ ' . $this->command)),
            $this->outputPad,
            0,
        ));

        // The lines there are to show. An output that is nothing but '' is no output,
        // and a trailing empty line from a final newline is not an extra line of output.
        $available = $this->outputLines === [''] ? [] : $this->outputLines;

        if (count($available) > 1 && $available[count($available) - 1] === '') {
            array_pop($available);
        }
        $hiddenLineCount = max(0, count($available) - self::PREVIEW_LINES);

        if ($available !== []) {
            $styled = implode("\n", array_map(static fn (string $line): string => Themes::theme()->fg('muted', $line), $available));

            if ($this->expanded) {
                $this->content->addChild(new Text("\n{$styled}", $this->outputPad, 0));
            } else {
                $this->content->addChild(new Spacer(1));
                $this->preview->setText($styled);
                $this->content->addChild($this->preview);
            }
        }

        if ($this->status === 'running') {
            $this->content->addChild($this->loader);

            return;
        }

        $parts = [];

        if ($hiddenLineCount > 0) {
            $parts[] = $this->expanded
                ? Themes::theme()->fg('muted', '(') . $this->keyHint('to collapse') . Themes::theme()->fg('muted', ')')
                : Themes::theme()->fg('muted', "... {$hiddenLineCount} more lines (") . $this->keyHint('to expand') . Themes::theme()->fg('muted', ')');
        }

        if ($this->status === 'cancelled') {
            $parts[] = Themes::theme()->fg('warning', '(cancelled)');
        } elseif ($this->status === 'error') {
            $parts[] = Themes::theme()->fg('error', "(exit {$this->exitCode})");
        }

        if ($this->truncated && $this->fullOutputPath !== null) {
            $parts[] = Themes::theme()->fg('warning', "Output truncated. Full output: {$this->fullOutputPath}");
        }

        if ($parts !== []) {
            $this->content->addChild(new Text("\n" . implode("\n", $parts), $this->outputPad, 0));
        }
    }

    /** Upstream's `keyHint()`: the key dim, the words muted. */
    private function keyHint(string $description): string
    {
        return Themes::theme()->fg('dim', $this->expandKey) . Themes::theme()->fg('muted', " {$description}");
    }
}
