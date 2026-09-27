<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Pig\Agent\AgentError;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Utf8;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Theme\Palette;

/**
 * A conversation as one HTML file that opens anywhere.
 *
 * Self-contained, which for upstream means 160KB of vendored JavaScript that renders the
 * session in the browser, and here means the rendering already happened: the markdown is
 * markdown this project already knows how to read, and the file it writes has no script
 * in it at all. Which also makes it readable with JavaScript off, printable, and
 * greppable — none of which the JS version is.
 *
 * Thinking and tool output are `<details>`, closed. That is the one piece of interaction
 * upstream's JavaScript provided that is worth keeping, and HTML has had it for years.
 *
 * Ported from upstream's `core/export-html/`.
 */
final class HtmlExport
{
    /**
     * Longer than this is folded rather than shown; a 4000-line read is not a document.
     *
     * Characters and not lines: a paragraph of prose comes out of the renderer as one
     * long line, so counting newlines folds every code block and never folds any
     * thinking — which is exactly backwards.
     */
    private const int FOLD_CHARACTERS = 600;

    /**
     * Write a session to $path, and answer where it went.
     *
     * @throws AgentError when there is nothing to export, or it cannot be written
     */
    public static function write(SessionManager $session, string $path, string $theme = 'dark'): string
    {
        $messages = $session->messages();

        if ($messages === []) {
            throw new AgentError('Nothing to export yet — say something first.');
        }

        $html = self::render($messages, $session->cwd, $theme);

        if (file_put_contents($path, $html) === false) {
            throw new AgentError("Could not write {$path}");
        }

        return $path;
    }

    /**
     * Export a session file without opening it, which is what `bin/pig --export` is.
     *
     * Upstream's `exportFromFile()`, and it lives here rather than in the entry point for the
     * reason four other classes in `Cli\` exist: a script that ends in `exit()` cannot be called
     * twice by a test. Somebody with a `.jsonl` and no wish to reopen the conversation had no way
     * to get the HTML out of it — `/export` needs a running session and the RPC `export` command
     * needs a host.
     *
     * A missing file is named rather than reported as an empty session, because "nothing to
     * export" about a path that does not exist sends the reader looking at the wrong thing.
     *
     * @param string|null $path where to write, or null for `defaultPath()` in $directory
     * @return string where it went
     * @throws AgentError when the session cannot be read, is empty, or cannot be written
     */
    public static function fromFile(
        string $session,
        ?string $path = null,
        string $directory = '.',
        string $theme = 'dark',
    ): string {
        if (!is_file($session)) {
            throw new AgentError("No session file at {$session}");
        }

        $store = SessionManager::open($session);

        return self::write($store, $path ?? self::defaultPath($store, $directory), $theme);
    }

    /** Where an export goes when nobody said: beside the session, named after it. */
    public static function defaultPath(SessionManager $session, string $directory): string
    {
        $name = basename($session->path, '.jsonl');

        return rtrim($directory, '/') . "/pig-session-{$name}.html";
    }

    /**
     * The whole document.
     *
     * @param list<mixed> $messages
     */
    public static function render(array $messages, string $cwd, string $theme = 'dark'): string
    {
        $title = 'pig — ' . self::opening($messages);
        $body = '';
        $calls = [];

        foreach ($messages as $message) {
            $body .= self::message($message, $calls);
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en" data-theme="' . MarkdownHtml::escape($theme) . '">' . "\n"
            . "<head>\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . MarkdownHtml::escape($title) . "</title>\n"
            . "<style>\n" . self::style($theme) . "</style>\n"
            . "</head>\n<body>\n"
            . '<header><h1>' . MarkdownHtml::escape(self::opening($messages)) . '</h1>'
            . '<p class="where">' . MarkdownHtml::escape($cwd) . '</p></header>' . "\n"
            . "<main>\n" . $body . "</main>\n"
            . "</body>\n</html>\n";
    }

    /** @param list<mixed> $messages */
    private static function opening(array $messages): string
    {
        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                // Sanitised first: `/\s+/u` answers **null** on malformed UTF-8 and `(string) null`
                // is `''`, so the heading fell through to "a conversation" and the page lost what
                // it was about. A `/u` pattern over text from outside is a silent truncation the
                // same way `htmlspecialchars()` is.
                $clean = Utf8::sanitize(self::textOf($message->content));
                $text = trim((string) preg_replace('/\s+/u', ' ', $clean));

                if ($text !== '') {
                    return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '...' : $text;
                }
            }
        }

        return 'a conversation';
    }

    /** @param array<string, string> $calls tool call id => name, filled in as they are seen */
    private static function message(mixed $message, array &$calls): string
    {
        return match (true) {
            $message instanceof UserMessage => self::user($message),
            $message instanceof AssistantMessage => self::assistant($message, $calls),
            $message instanceof ToolResultMessage => self::result($message, $calls),
            $message instanceof BashExecution => self::bash($message),
            $message instanceof CompactionSummary => self::compaction($message),
            $message instanceof BranchSummary => self::branch($message),
            $message instanceof HookMessage => self::hook($message),
            default => '',
        };
    }

    private static function user(UserMessage $message): string
    {
        $text = self::textOf($message->content);
        $images = self::images($message->content);

        if (trim($text) === '' && $images === '') {
            return '';
        }

        return "<section class=\"turn user\">\n<h2>You</h2>\n"
            . MarkdownHtml::render($text) . $images
            . "</section>\n";
    }

    /** @param array<string, string> $calls */
    private static function assistant(AssistantMessage $message, array &$calls): string
    {
        $parts = '';

        foreach ($message->content as $block) {
            if ($block instanceof ThinkingContent && trim($block->thinking) !== '') {
                $parts .= self::folded('Thinking', MarkdownHtml::render($block->thinking), 'thinking');

                continue;
            }

            if ($block instanceof TextContent && trim($block->text) !== '') {
                $parts .= MarkdownHtml::render($block->text);

                continue;
            }

            if ($block instanceof ToolCall) {
                $calls[$block->id] = $block->name;
                $parts .= self::call($block);
            }
        }

        if ($message->stopReason === StopReason::Error && $message->errorMessage !== null) {
            $parts .= '<p class="failed">' . MarkdownHtml::escape($message->errorMessage) . '</p>';
        }

        if ($parts === '') {
            return '';
        }

        return "<section class=\"turn assistant\">\n<h2>" . MarkdownHtml::escape($message->model) . "</h2>\n"
            . $parts . "</section>\n";
    }

    private static function call(ToolCall $call): string
    {
        $arguments = json_encode($call->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::folded(
            MarkdownHtml::escape($call->name) . ' ' . MarkdownHtml::escape(self::summarise($call)),
            MarkdownHtml::code($arguments === false ? '{}' : $arguments, 'json'),
            'call',
        );
    }

    /** The one argument worth putting in the summary line, if there is one. */
    private static function summarise(ToolCall $call): string
    {
        foreach (['path', 'command', 'pattern', 'query'] as $name) {
            $value = $call->arguments[$name] ?? null;

            if (is_string($value) && $value !== '') {
                return mb_strlen($value) > 90 ? mb_substr($value, 0, 90) . '...' : $value;
            }
        }

        return '';
    }

    /** @param array<string, string> $calls */
    private static function result(ToolResultMessage $message, array &$calls): string
    {
        $name = $calls[$message->toolCallId] ?? $message->toolName;
        $text = self::textOf($message->content);
        $images = self::images($message->content);

        $body = ($text === '' ? '' : MarkdownHtml::code($text, ''))
            . $images;

        return self::folded(
            MarkdownHtml::escape($name) . ($message->isError ? ' — failed' : ''),
            $body === '' ? '<p class="empty">(nothing)</p>' : $body,
            $message->isError ? 'result failed' : 'result',
        );
    }

    private static function bash(BashExecution $execution): string
    {
        $status = $execution->cancelled
            ? ' — cancelled'
            : (($execution->exitCode ?? 0) === 0 ? '' : ' — exited ' . $execution->exitCode);

        return "<section class=\"turn user\">\n<h2>You ran</h2>\n"
            . MarkdownHtml::code('$ ' . $execution->command, 'bash')
            . self::folded(
                'Output' . MarkdownHtml::escape($status),
                MarkdownHtml::code($execution->output, ''),
                'result',
            )
            . "</section>\n";
    }

    private static function compaction(CompactionSummary $summary): string
    {
        return "<section class=\"turn compaction\">\n"
            . '<h2>Compacted — ' . number_format($summary->replaced) . " earlier messages summarised</h2>\n"
            . self::folded('The summary', MarkdownHtml::render($summary->summary), 'thinking')
            . "</section>\n";
    }

    private static function branch(BranchSummary $summary): string
    {
        $files = count($summary->readFiles) + count($summary->modifiedFiles);

        return "<section class=\"turn compaction\">\n"
            . '<h2>Branch summarised'
            . ($summary->fromHook ? ' by a hook' : '')
            . ($files > 0 ? ' — ' . $files . ' files remembered' : '')
            . "</h2>\n"
            . self::folded('The summary', MarkdownHtml::render($summary->summary), 'thinking')
            . "</section>\n";
    }

    /**
     * A hook's message, labelled as one.
     *
     * Not drawn as a user message, for the reason `HookMessageComponent` gives: it reaches
     * the model as one, and showing it as one puts words in the person's mouth.
     *
     * `display: false` is honoured here too. A hook's private note to the model is not part
     * of what a person would recognise as their conversation, and an export is something
     * they send to somebody else.
     */
    private static function hook(HookMessage $message): string
    {
        if (!$message->display || trim($message->toText()) === '') {
            return '';
        }

        return "<section class=\"turn hook\">\n"
            . '<h2>' . MarkdownHtml::escape($message->customType) . "</h2>\n"
            . MarkdownHtml::render($message->toText())
            . "</section>\n";
    }

    /**
     * Something foldable, closed.
     *
     * Closed because a transcript is read for the conversation: tool output and thinking
     * are what you open when the conversation stops making sense without them. Anything
     * short enough to read at a glance is left open instead, since folding three lines
     * costs a click and saves nothing.
     */
    private static function folded(string $summary, string $body, string $class): string
    {
        // Measured without the markup, which is most of the bytes and none of the
        // reading.
        $open = mb_strlen(strip_tags($body)) <= self::FOLD_CHARACTERS ? ' open' : '';

        return "<details class=\"{$class}\"{$open}><summary>{$summary}</summary>\n{$body}</details>\n";
    }

    /** @param list<mixed> $content */
    private static function textOf(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /**
     * Images, inline as data URLs.
     *
     * The whole point of one file is that it is one file: an export whose pictures live
     * next to it is an export that arrives without them.
     *
     * @param list<mixed> $content
     */
    private static function images(array $content): string
    {
        $html = '';

        foreach ($content as $block) {
            if ($block instanceof ImageContent) {
                $html .= '<img alt="" src="data:' . MarkdownHtml::escape($block->mimeType)
                    . ';base64,' . MarkdownHtml::escape($block->data) . '">' . "\n";
            }
        }

        return $html;
    }

    /**
     * The stylesheet.
     *
     * Written out rather than kept in a file beside the class: an export is one file by
     * definition, and a template that has to be found at run time is one more thing that
     * can be missing from an installation.
     */
    /**
     * The stylesheet, with the theme's own colours in it.
     *
     * The two page palettes are written out because the export is a document and not a
     * terminal — a card needs a border and a shadow that no terminal theme has an opinion
     * about. **The syntax colours are not**: those are the theme's, and they used to be six
     * hand-written hex values belonging to neither of pig's two palettes. What that cost is
     * visible the moment somebody on the light theme exports anything with code in it — pale
     * blue keywords and mauve numbers on white — and on the dark theme it meant an export that
     * did not match the terminal the person had just been reading.
     *
     * `Highlight` writes `<span class="hl-keyword">` for the terminal's sake as well, so the
     * class names are the seam and this is the one place that has to know what they mean.
     */
    private static function style(string $theme): string
    {
        $palette = Palette::named(in_array($theme, Palette::names(), true) ? $theme : 'dark', false);
        $syntax = '';

        foreach (self::SYNTAX as $class => $colour) {
            $syntax .= "        .hl-{$class} { color: " . $palette->hex($colour) . "; }\n";
        }

        return <<<'CSS'
        :root { --bg: #16161d; --fg: #d8d8e0; --dim: #8a8a99; --card: #1e1e26; --line: #2c2c38;
          --user: #2a2a3a; --accent: #8abeb7; --error: #cc6666; --code: #14141a; }
        [data-theme="light"] { --bg: #fbfbfd; --fg: #2a2a33; --dim: #6a6a78; --card: #ffffff;
          --line: #e2e2ea; --user: #eef1f6; --accent: #2a7d74; --error: #a33; --code: #f4f4f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0 1rem 4rem; background: var(--bg); color: var(--fg);
          font: 15px/1.6 ui-sans-serif, -apple-system, "Segoe UI", system-ui, sans-serif; }
        header, main { max-width: 52rem; margin: 0 auto; }
        header { padding: 2rem 0 1rem; border-bottom: 1px solid var(--line); margin-bottom: 1rem; }
        h1 { font-size: 1.3rem; margin: 0; }
        .where { color: var(--dim); font-size: .85rem; margin: .3rem 0 0; }
        .turn { padding: .75rem 1rem; border-radius: 8px; margin: 1rem 0; background: var(--card); }
        .turn.user { background: var(--user); }
        .turn.compaction { background: transparent; border: 1px dashed var(--line); }
        .turn h2 { font-size: .75rem; text-transform: uppercase; letter-spacing: .08em;
          color: var(--dim); margin: 0 0 .5rem; font-weight: 600; }
        p { margin: .6rem 0; }
        pre { background: var(--code); border: 1px solid var(--line); border-radius: 6px;
          padding: .6rem .8rem; overflow-x: auto; margin: .6rem 0; }
        code { font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; }
        p code, li code, td code { background: var(--code); padding: .1em .35em; border-radius: 4px; }
        details { margin: .5rem 0; border: 1px solid var(--line); border-radius: 6px; padding: .4rem .6rem; }
        summary { cursor: pointer; color: var(--dim); font: 13px/1.5 ui-monospace, Menlo, monospace; }
        details.failed { border-color: var(--error); }
        details.failed > summary { color: var(--error); }
        .failed { color: var(--error); }
        .empty, .href { color: var(--dim); }
        blockquote { border-left: 3px solid var(--line); margin: .6rem 0; padding: 0 0 0 .8rem; color: var(--dim); }
        table { border-collapse: collapse; margin: .6rem 0; }
        th, td { border: 1px solid var(--line); padding: .3rem .6rem; text-align: left; }
        img { max-width: 100%; border-radius: 6px; margin: .5rem 0; }
        a { color: var(--accent); }
        hr { border: 0; border-top: 1px solid var(--line); margin: 1.2rem 0; }
        .hl-comment { font-style: italic; }
        CSS . "\n" . $syntax;
    }

    /**
     * The highlighter's class names, and which palette colour each one is.
     *
     * `Highlight` paints `syntaxOperator` and `syntaxPunctuation` as plain text — it has no
     * category for either — so neither has a class here, which is the same shortfall stated in
     * CLAUDE.md about the terminal.
     */
    private const array SYNTAX = [
        'comment' => 'syntaxComment',
        'string' => 'syntaxString',
        'number' => 'syntaxNumber',
        'keyword' => 'syntaxKeyword',
        'type' => 'syntaxType',
        'function' => 'syntaxFunction',
        'variable' => 'syntaxVariable',
    ];
}
