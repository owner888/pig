<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Agent\ToolExecutionUpdateEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\ExternalTool;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\SlashCommand;
use Pig\Tui\Clipboard\SystemClipboard;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\Loader;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\TruncatedText;
use Pig\Tui\Container;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Style;
use Pig\Tui\Terminal;
use Pig\Tui\Tui;
use Throwable;

/**
 * The agent with a terminal around it.
 *
 * Everything here is arrangement: the session decides what happens, `pig/tui` decides how
 * a frame is drawn, and this decides which component an event turns into and which key
 * means what. Keeping that split is what makes the whole thing testable — the session has
 * no idea a terminal exists, and the components are handed finished messages.
 *
 * Ported from upstream's `interactive-mode.ts`, which is 2439 lines. The difference is
 * the selectors: upstream has twenty-five of them — models, sessions, settings, hooks,
 * OAuth, branch trees — and each one needs a subsystem that is not ported. What is here
 * is the loop that makes it an agent you can talk to.
 */
final class InteractiveMode
{
    /** Two presses inside this many seconds mean the second one. */
    private const float DOUBLE_PRESS = 0.5;

    private readonly Tui $tui;

    private readonly Container $chat;

    private readonly Container $pending;

    private readonly Container $status;

    private readonly CustomEditor $editor;

    private readonly FooterComponent $footer;

    private ?Loader $working = null;

    private ?AssistantMessageComponent $streaming = null;

    /** @var array<string, ToolExecutionComponent> by tool call id */
    private array $tools = [];

    /** ctrl+o: more of everything — tool output, and the full key list. */
    private bool $expanded = false;

    private bool $hideThinking = false;

    /** The editor's text starts with `!`, so it is a command and not a prompt. */
    private bool $bashMode = false;

    private float $lastCtrlC = 0.0;

    private bool $running = false;

    /** Which of the two built-in themes is on, so /theme knows what to switch to. */
    private string $theme = 'dark';

    /** @var list<ContextFile> what the system prompt was given, so it can be shown */
    private array $contextFiles = [];

    private ?Text $banner = null;

    /** The session picker, while it is open. */
    private ?SelectList $picker = null;

    /** Set while the summariser is running, so escape can call it off. */
    private ?AbortController $compaction = null;

    public function __construct(
        private readonly AgentSession $session,
        private Palette $palette,
        private readonly string $cwd,
        private readonly string $version,
        string $theme = 'dark',
        ?Terminal $terminal = null,
        array $contextFiles = [],
    ) {
        $this->theme = $theme;
        $this->contextFiles = $contextFiles;

        // Injected so a test can drive this without a terminal, the same way the editor
        // takes its clipboard: everything below here is arrangement, and arrangement is
        // exactly what is worth testing.
        $this->tui = new Tui($terminal ?? new ProcessTerminal());
        $this->chat = new Container();
        $this->pending = new Container();
        $this->status = new Container();
        $this->editor = new CustomEditor(new Editor($palette->editorTheme()));
        $this->footer = new FooterComponent($session, $palette, $cwd);
    }

    /** Wire everything up and draw the first frame. */
    public function start(): void
    {
        $this->layout();
        $this->bindKeys();
        $this->bindEditor();
        $this->session->subscribe($this->onEvent(...));

        $this->replay();
        $this->running = true;
        $this->tui->start();
    }

    /**
     * Draw a conversation that already happened.
     *
     * Built from the messages rather than from anything saved about the screen: a
     * transcript is a view of the conversation, and keeping a second copy of it on disk
     * is how the two end up disagreeing.
     */
    private function replay(): void
    {
        $tools = [];

        foreach ($this->session->messages() as $message) {
            if ($message instanceof UserMessage) {
                $this->chat->addChild(new UserMessageComponent(self::textOf($message), $this->palette));

                continue;
            }

            if ($message instanceof BashExecution) {
                $this->replayBash($message);

                continue;
            }

            if ($message instanceof CompactionSummary) {
                $this->chat->addChild(new CompactionComponent($message, $this->palette, $this->expanded));

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $this->chat->addChild(new AssistantMessageComponent($this->palette, $message, $this->hideThinking));

                foreach ($message->content as $block) {
                    if ($block instanceof ToolCall) {
                        $tools[$block->id] = $this->addTool($block->id, $block->name, $block->arguments);
                    }
                }

                continue;
            }

            if ($message instanceof ToolResultMessage && isset($tools[$message->toolCallId])) {
                $tools[$message->toolCallId]->updateResult(
                    new AgentToolResult($message->content, $message->details),
                    $message->isError,
                );
            }
        }

        // Nothing is left pending: every tool in a saved conversation has already run,
        // and one still showing as running would never stop.
        $this->tools = [];
    }

    private function replayBash(BashExecution $execution): void
    {
        $shown = new ToolExecutionComponent('bash', ['command' => $execution->command], $this->palette);
        $shown->setExpanded($this->expanded);
        $shown->updateResult(
            new AgentToolResult([new TextContent($execution->output)]),
            $execution->cancelled || ($execution->exitCode ?? 0) !== 0,
        );

        $this->chat->addChild($shown);
    }

    /** Draw, then hand the terminal over until someone quits. */
    public function run(): void
    {
        $this->start();

        try {
            Loop::get()->run();
        } finally {
            // Through stop() rather than straight to the terminal, so a quit from a key
            // handler and a loop that ended on its own both take the same path — and the
            // terminal is not stopped twice, which showed up as two STOPs in a trace.
            $this->stop();
        }
    }

    /** @internal for tests, which drive the terminal rather than the loop */
    public function screen(): Tui
    {
        return $this->tui;
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        $this->working?->stop();
        $this->session->dispose();

        // Stopped here and not only in run()'s finally: whoever calls this wants the
        // terminal back — raw mode off, cursor shown — whether or not the loop is what
        // they are waiting on.
        $this->tui->stop();
        Loop::get()->stop();
    }

    // ---- putting it on the screen ------------------------------------------------------

    private function layout(): void
    {
        $this->banner = new Text($this->banner(), 1, 0);

        $this->tui->addChild(new Spacer(1));
        $this->tui->addChild($this->banner);
        $this->tui->addChild(new Spacer(1));
        $this->tui->addChild($this->chat);
        $this->tui->addChild($this->pending);
        $this->tui->addChild($this->status);
        $this->tui->addChild(new Spacer(1));
        $this->tui->addChild($this->editor);
        $this->tui->addChild($this->footer);
        $this->tui->setFocus($this->editor);
    }

    /**
     * The three lines at the top, and the full list behind ctrl+o.
     *
     * One line of keys rather than a column of thirteen, which is what upstream settled
     * on: the list was taller than most of the conversations it sat above. The rest is
     * still there, one key away, for the session where someone needs it.
     */
    private function banner(): string
    {
        $lines = [
            Style::bold($this->palette->fg('accent', 'pig')) . $this->palette->fg('dim', " v{$this->version}"),
            $this->palette->fg('muted', implode(' · ', self::SUMMARY)),
        ];

        if (!$this->expanded) {
            $lines[] = $this->palette->fg('dim', 'Press ctrl+o for the full list of keys, and what is loaded.');

            return implode("\n", $lines);
        }

        $lines[] = '';

        foreach (self::KEYS as $key => $does) {
            $lines[] = $this->palette->fg('dim', str_pad($key, 12)) . $this->palette->fg('muted', $does);
        }

        $lines[] = '';

        foreach (self::COMMANDS as [$name, $does]) {
            $lines[] = $this->palette->fg('dim', str_pad('/' . $name, 12)) . $this->palette->fg('muted', $does);
        }

        $loaded = $this->loaded();

        return implode("\n", $lines) . ($loaded === '' ? '' : "\n\n" . $loaded);
    }

    /**
     * What was loaded into this session, as sections.
     *
     * A section with nothing in it is not drawn. Upstream lists skills and extensions
     * here too; neither is ported, so neither has a heading to be empty under.
     */
    private function loaded(): string
    {
        if ($this->contextFiles === []) {
            return '';
        }

        $names = array_map(
            static fn (ContextFile $file): string => basename($file->path),
            $this->contextFiles,
        );

        return $this->palette->fg('mdHeading', '[Context]') . "\n"
            . $this->palette->fg('muted', '  ' . implode(', ', array_unique($names)));
    }

    /** The keys worth knowing before the first prompt, on one line. */
    private const array SUMMARY = [
        'escape interrupt',
        'ctrl+c/ctrl+d clear/exit',
        '/ commands',
        '! bash',
        'ctrl+o more',
    ];

    /** @var array<string, string> */
    private const array KEYS = [
        'esc' => 'interrupt the agent',
        'ctrl+c' => 'clear the prompt, twice to exit',
        'ctrl+d' => 'exit from an empty prompt',
        'ctrl+z' => 'suspend',
        'ctrl+v' => 'paste, including an image from the clipboard',
        'shift+tab' => 'cycle the thinking level',
        'ctrl+o' => 'show more: tool output, and this list',
        'ctrl+t' => 'show or hide thinking',
        '/' => 'commands',
        '@' => 'files',
        '!' => 'run a command, and let the model see the output',
        '!!' => 'run a command and keep it out of the conversation',
    ];

    // ---- keys ---------------------------------------------------------------------------

    private function bindKeys(): void
    {
        $this->editor->on('escape', $this->interrupt(...));
        $this->editor->on('ctrl+c', $this->onCtrlC(...));
        $this->editor->on('ctrl+d', $this->stop(...));
        $this->editor->on('ctrl+z', $this->suspend(...));
        $this->editor->on('shift+tab', $this->cycleThinking(...));

        $this->editor->on('ctrl+o', function (): void {
            $this->expanded = !$this->expanded;
            $this->banner?->setText($this->banner());

            foreach ($this->chat->children() as $child) {
                if ($child instanceof ToolExecutionComponent || $child instanceof CompactionComponent) {
                    $child->setExpanded($this->expanded);
                }
            }

            $this->tui->requestRender();
        });

        $this->editor->on('ctrl+t', function (): void {
            $this->hideThinking = !$this->hideThinking;

            foreach ($this->chat->children() as $child) {
                if ($child instanceof AssistantMessageComponent) {
                    $child->setHideThinking($this->hideThinking);
                }
            }

            $this->say($this->hideThinking ? 'Thinking hidden' : 'Thinking shown');
        });
    }

    /**
     * Stop the agent, and give back whatever was queued.
     *
     * Into the editor, in front of the text already there: someone who interrupts has
     * changed their mind about what they asked for, and the words they typed while
     * waiting are the start of what they want instead.
     */
    private function interrupt(): void
    {
        if ($this->compaction !== null) {
            $this->compaction->abort('Cancelled');

            return;
        }

        if ($this->session->isBashRunning()) {
            $this->session->abortBash();

            return;
        }

        if ($this->working === null) {
            return;
        }

        $queued = $this->session->clearQueue();
        $text = implode("\n\n", array_filter([...$queued, $this->editor->text()], static fn (string $t): bool => trim($t) !== ''));

        $this->editor->setText($text);
        $this->showQueue();
        $this->session->agent->abort();
    }

    private function onCtrlC(): void
    {
        $now = microtime(true);

        if ($now - $this->lastCtrlC < self::DOUBLE_PRESS) {
            $this->stop();

            return;
        }

        $this->lastCtrlC = $now;
        $this->editor->setText('');
        $this->tui->requestRender();
    }

    /**
     * Hand the terminal back to the shell until someone types `fg`.
     *
     * The TUI has to be stopped first — raw mode and a hidden cursor belong to a program
     * that is running, and a suspended one leaves the shell with neither.
     */
    private function suspend(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            $this->say('Suspending needs ext-posix and ext-pcntl');

            return;
        }

        $this->tui->stop();

        pcntl_signal(SIGCONT, function (): void {
            $this->tui->start();
            $this->tui->requestRender(true);
        });

        posix_kill(posix_getpid(), SIGTSTP);
    }

    private function cycleThinking(): void
    {
        $level = $this->session->cycleThinkingLevel();

        if ($level === null) {
            $this->say('This model does not support thinking');

            return;
        }

        $this->paintBorder();
        $this->footer->invalidate();
        $this->say('Thinking: ' . $level->value);
    }

    // ---- what the person typed -------------------------------------------------------------

    private function bindEditor(): void
    {
        // Ctrl+V: an image on the clipboard is written to a temp file and its path
        // pasted, which is how a screenshot gets to the model.
        $this->editor->setClipboard(new SystemClipboard());

        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider(
            array_map(
                static fn (array $command): SlashCommand => new SlashCommand($command[0], $command[1]),
                self::COMMANDS,
            ),
            $this->cwd,
            // Only if it is already here. Reaching for the network to draw a completion
            // list is not something a keystroke should do.
            ExternalTool::has('fd') ? ExternalTool::fd() : null,
        ));

        // The border turns green the moment the line becomes a command, so there is no
        // way to press Enter thinking it was a prompt.
        $this->editor->setChangeHandler(function (string $text): void {
            $isBash = str_starts_with(ltrim($text), '!');

            if ($isBash === $this->bashMode) {
                return;
            }

            $this->bashMode = $isBash;
            $this->paintBorder();
        });

        $this->editor->setSubmitHandler(function (string $text): void {
            $text = trim($text);

            if ($text === '') {
                return;
            }

            $this->editor->addToHistory($text);

            if (str_starts_with($text, '!')) {
                $this->editor->setText('');
                $this->bashMode = false;
                $this->paintBorder();
                $this->runCommand($text);

                return;
            }

            if (str_starts_with($text, '/')) {
                $this->editor->setText('');
                $this->command($text);

                return;
            }

            // Typed while the agent is working: queued rather than refused, because the
            // person watching a tool run is exactly who has something to add.
            if ($this->session->isStreaming()) {
                $this->session->followUp($text);
                $this->editor->setText('');
                $this->showQueue();
                $this->tui->requestRender();

                return;
            }

            $this->editor->setText('');
            $this->send($text);
        });
    }

    /**
     * Run a `!` command and show it happening.
     *
     * `!` puts the result in the conversation, `!!` does not. Both show the same thing on
     * screen — what differs is whether the model sees it afterwards, which is worth being
     * told rather than left to remember.
     */
    private function runCommand(string $typed): void
    {
        $remember = !str_starts_with($typed, '!!');
        $command = trim(substr($typed, $remember ? 1 : 2));

        if ($command === '') {
            return;
        }

        if ($this->session->isBashRunning()) {
            $this->say($this->palette->fg('warning', 'A command is already running. Press esc to stop it.'));

            return;
        }

        $shown = new ToolExecutionComponent('bash', ['command' => $command], $this->palette);
        $shown->setExpanded($this->expanded);
        $this->chat->addChild($shown);
        $this->tui->requestRender();

        Async::spawn(function () use ($command, $remember, $shown): void {
            try {
                $execution = $this->session->executeBash(
                    $command,
                    $remember,
                    function (string $output) use ($shown): void {
                        $shown->updateResult(new AgentToolResult([new TextContent($output)]), false, true);
                        $this->tui->requestRender();
                    },
                );

                $shown->updateResult(
                    new AgentToolResult([new TextContent($execution->output)]),
                    $execution->cancelled || ($execution->exitCode ?? 0) !== 0,
                );

                if (!$remember) {
                    $this->say('Not added to the conversation');
                }
            } catch (Throwable $error) {
                $shown->fail($error->getMessage());
            }

            $this->footer->invalidate();
            $this->tui->requestRender();
        });
    }

    /** Green while the line is a command, otherwise the thinking level's colour. */
    private function paintBorder(): void
    {
        $colour = $this->bashMode
            ? $this->palette->of('bashMode')
            : $this->palette->thinkingBorder($this->session->thinkingLevel());

        $this->editor->setTheme(new EditorTheme($colour, $this->palette->selectListTheme()));
        $this->tui->requestRender();
    }

    /**
     * Send, in a fiber of its own.
     *
     * The submit handler is called from the input callback, which the loop is in the
     * middle of; a prompt that ran there would block every keystroke — including the
     * Escape meant to stop it.
     */
    private function send(string $text): void
    {
        Async::spawn(function () use ($text): void {
            try {
                // Before the turn rather than after the failure: a request that does not
                // fit comes back as an error from the provider, and by then the person
                // has already waited for it.
                if ($this->session->shouldCompact()) {
                    $this->say($this->palette->fg('muted', 'Context is nearly full — summarising first.'));
                    $this->compact();
                }

                $this->session->prompt($text);
            } catch (Throwable $error) {
                $this->say($this->palette->fg('error', $error->getMessage()));
            }
        });
    }

    /**
     * Summarise the older half of the conversation, with something on screen while it runs.
     *
     * Called straight from `/compact` and from `send()` when the window is nearly full.
     * Runs in whatever fiber it was called from — both of those are already off the input
     * callback — so escape still reaches the loop and can call it off.
     *
     * @param string|null $instructions from `/compact focus on the parser`
     */
    private function compact(?string $instructions = null): void
    {
        if ($this->compaction !== null) {
            return;
        }

        $this->working?->stop();
        $this->status->clear();
        $this->working = new Loader(
            $this->tui,
            $this->palette->of('accent'),
            $this->palette->of('muted'),
            'Summarising the conversation... (esc to cancel)',
        );
        $this->status->addChild($this->working);
        $this->tui->requestRender();

        $this->compaction = new AbortController();
        $signal = $this->compaction->signal;

        $failure = null;

        try {
            $summary = $this->session->compact($instructions, $signal);
        } catch (Throwable $error) {
            $summary = null;
            $failure = $error->getMessage();
        } finally {
            $this->compaction = null;
            $this->working?->stop();
            $this->working = null;
            $this->status->clear();
        }

        if ($failure !== null) {
            $this->sayError("Compaction failed: {$failure}");

            return;
        }

        if ($summary === null) {
            $this->sayError('Compaction cancelled');

            return;
        }

        // The transcript above is left where it is: it is what was said, and the summary
        // is a note about it, not a replacement for anyone's memory of reading it.
        $this->chat->addChild(new CompactionComponent($summary, $this->palette, $this->expanded));
        $this->footer->invalidate();
        $this->tui->requestRender();
    }

    /** @var list<array{0: string, 1: string}> */
    private const array COMMANDS = [
        ['help', 'Show the keys and commands'],
        ['new', 'Forget the conversation and start over'],
        ['session', 'What this session has cost'],
        ['compact', 'Summarise the conversation so far and carry on from the summary'],
        ['resume', 'Pick up an earlier conversation'],
        ['theme', 'Switch between dark and light'],
        ['exit', 'Quit'],
    ];

    private function command(string $text): void
    {
        $name = ltrim(strtok($text, " \t") ?: '', '/');

        match ($name) {
            'help' => $this->say($this->commandHelp()),
            'new' => $this->newSession(),
            'session' => $this->say($this->sessionSummary()),
            'compact' => $this->startCompaction(trim(substr($text, strlen($name) + 1))),
            'resume' => $this->showSessions(),
            'theme' => $this->switchTheme(),
            'exit', 'quit' => $this->stop(),
            default => $this->say($this->palette->fg('error', "No command called /{$name}. Try /help.")),
        };
    }

    /**
     * `/compact`, in a fiber of its own.
     *
     * Same reason as `send()`: a command runs inside the input callback, and summarising
     * there would block the escape meant to stop it.
     */
    private function startCompaction(string $instructions): void
    {
        if ($this->session->isStreaming()) {
            $this->say($this->palette->fg('warning', 'Still working. Press esc first.'));

            return;
        }

        Async::spawn(fn () => $this->compact($instructions === '' ? null : $instructions));
    }

    private function commandHelp(): string
    {
        $lines = [];

        foreach (self::KEYS as $key => $does) {
            $lines[] = $this->palette->fg('dim', str_pad($key, 12)) . $this->palette->fg('muted', $does);
        }

        $lines[] = '';

        foreach (self::COMMANDS as [$name, $does]) {
            $lines[] = $this->palette->fg('dim', str_pad('/' . $name, 12)) . $this->palette->fg('muted', $does);
        }

        $loaded = $this->loaded();

        return implode("\n", $lines) . ($loaded === '' ? '' : "\n\n" . $loaded);
    }

    private function newSession(): void
    {
        if ($this->session->isStreaming()) {
            $this->say($this->palette->fg('warning', 'Still working. Press esc first.'));

            return;
        }

        $this->session->agent->reset();
        $this->chat->clear();
        $this->pending->clear();
        $this->status->clear();
        $this->footer->invalidate();
        $this->say('New session');
    }

    private function sessionSummary(): string
    {
        $stats = $this->session->stats();

        return $this->palette->fg('muted', sprintf(
            '%d messages · %d tool calls · %s tokens · $%s',
            $stats->totalMessages,
            $stats->toolCalls,
            number_format($stats->totalTokens()),
            number_format($stats->cost, 4),
        ));
    }

    /**
     * Offer the earlier conversations in this directory.
     *
     * In this directory only: a session is about a project, and a list mixing three
     * projects together is a list nobody reads.
     */
    private function showSessions(): void
    {
        if ($this->session->isStreaming()) {
            $this->say($this->palette->fg('warning', 'Still working. Press esc first.'));

            return;
        }

        $sessions = SessionManager::listFor($this->cwd);

        if ($sessions === []) {
            $this->say('No earlier sessions here yet.');

            return;
        }

        $items = [];

        foreach ($sessions as $index => $info) {
            $items[] = new SelectItem(
                (string) $index,
                $info->opening === '' ? '(nothing was said)' : $info->opening,
                $info->when() . ' · ' . $info->messages . ' messages',
            );
        }

        $picker = new SelectList($items, 8, $this->palette->selectListTheme());
        $picker->setSelectHandler(function (SelectItem $item) use ($sessions): void {
            $this->closePicker();
            $this->resume($sessions[(int) $item->value]);
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->picker = $picker;
        $this->status->clear();
        $this->status->addChild(new Spacer(1));
        $this->status->addChild(new Text($this->palette->fg('muted', 'Pick a session — enter to open, esc to cancel'), 1, 0));
        $this->status->addChild($picker);

        // Focus moves to the list, so arrow keys reach it rather than the editor.
        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    private function closePicker(): void
    {
        $this->picker = null;
        $this->status->clear();
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    /** Replace this conversation with a saved one, and redraw it. */
    private function resume(SessionInfo $info): void
    {
        try {
            $saved = SessionManager::open($info->path);
        } catch (Throwable $error) {
            $this->say($this->palette->fg('error', $error->getMessage()));

            return;
        }

        $this->session->restore($saved->messages());
        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();

        // Said after the transcript, so it is the last thing on screen rather than the
        // first thing buried above a conversation.
        $this->say('Resumed ' . count($saved->messages()) . ' messages from ' . $info->when());
    }

    private function switchTheme(): void
    {
        $this->theme = $this->theme === 'dark' ? 'light' : 'dark';
        $wanted = $this->theme;
        $this->palette = Palette::named($wanted);

        // Everything already on screen keeps the colours it was drawn with: a component
        // holds its palette, and repainting the transcript would mean rebuilding it from
        // messages this session does not keep. New output comes out in the new theme.
        $this->editor->setTheme($this->palette->editorTheme());
        $this->say("Theme: {$wanted} — already-drawn output keeps its colours");
        $this->tui->requestRender(true);
    }

    // ---- what the agent is doing ----------------------------------------------------------

    private function onEvent(AgentEvent $event): void
    {
        $this->footer->invalidate();

        match (true) {
            $event instanceof AgentStartEvent => $this->onStart(),
            $event instanceof MessageStartEvent => $this->onMessageStart($event),
            $event instanceof MessageUpdateEvent => $this->onMessageUpdate($event),
            $event instanceof MessageEndEvent => $this->onMessageEnd($event),
            $event instanceof ToolExecutionStartEvent => $this->onToolStart($event),
            $event instanceof ToolExecutionUpdateEvent => $this->onToolUpdate($event),
            $event instanceof ToolExecutionEndEvent => $this->onToolEnd($event),
            $event instanceof AgentEndEvent => $this->onEnd(),
            default => null,
        };

        $this->tui->requestRender();
    }

    private function onStart(): void
    {
        $this->working?->stop();
        $this->status->clear();

        $this->working = new Loader(
            $this->tui,
            $this->palette->of('accent'),
            $this->palette->of('muted'),
            'Working... (esc to interrupt)',
        );

        $this->status->addChild($this->working);
    }

    private function onMessageStart(MessageStartEvent $event): void
    {
        if ($event->message instanceof UserMessage) {
            $this->chat->addChild(new UserMessageComponent(self::textOf($event->message), $this->palette));
            $this->editor->setText('');
            $this->showQueue();

            return;
        }

        if ($event->message instanceof AssistantMessage) {
            $this->streaming = new AssistantMessageComponent($this->palette, $event->message, $this->hideThinking);
            $this->chat->addChild($this->streaming);
        }
    }

    private function onMessageUpdate(MessageUpdateEvent $event): void
    {
        if ($this->streaming === null || !$event->message instanceof AssistantMessage) {
            return;
        }

        $this->streaming->update($event->message);

        // A tool call gets its component as soon as its name is known, so the arguments
        // can be watched arriving rather than appearing all at once when it runs.
        foreach ($event->message->content as $block) {
            if (!$block instanceof ToolCall) {
                continue;
            }

            if (isset($this->tools[$block->id])) {
                $this->tools[$block->id]->updateArgs($block->arguments);

                continue;
            }

            $this->addTool($block->id, $block->name, $block->arguments);
        }
    }

    private function onMessageEnd(MessageEndEvent $event): void
    {
        if (!$event->message instanceof AssistantMessage) {
            return;
        }

        $this->streaming?->update($event->message);

        // A turn that stopped early leaves its tools hanging: they were announced and
        // will never run, so each one is told rather than left pending forever.
        if (in_array($event->message->stopReason, [StopReason::Aborted, StopReason::Error], true)) {
            $said = $event->message->stopReason === StopReason::Aborted
                ? 'Operation aborted'
                : ($event->message->errorMessage ?? 'Error');

            foreach ($this->tools as $tool) {
                $tool->fail($said);
            }

            $this->tools = [];
        }

        $this->streaming = null;
    }

    private function onToolStart(ToolExecutionStartEvent $event): void
    {
        if (!isset($this->tools[$event->toolCallId])) {
            $this->addTool($event->toolCallId, $event->toolName, $event->arguments);
        }
    }

    private function onToolUpdate(ToolExecutionUpdateEvent $event): void
    {
        ($this->tools[$event->toolCallId] ?? null)?->updateResult($event->partialResult, false, true);
    }

    private function onToolEnd(ToolExecutionEndEvent $event): void
    {
        $tool = $this->tools[$event->toolCallId] ?? null;

        if ($tool === null) {
            return;
        }

        $tool->updateResult($event->result, $event->isError);
        unset($this->tools[$event->toolCallId]);
    }

    private function onEnd(): void
    {
        $this->working?->stop();
        $this->working = null;
        $this->status->clear();
        $this->streaming = null;
        $this->tools = [];
    }

    /** @param array<string, mixed> $arguments */
    private function addTool(string $id, string $name, array $arguments): ToolExecutionComponent
    {
        $tool = new ToolExecutionComponent($name, $arguments, $this->palette);
        $tool->setExpanded($this->expanded);
        $this->chat->addChild($tool);
        $this->tools[$id] = $tool;

        return $tool;
    }

    // ---- the two lines that are not the conversation ------------------------------------------

    /** What is waiting to be sent, above the editor. */
    private function showQueue(): void
    {
        $this->pending->clear();
        $queued = $this->session->queued();

        if ($queued === []) {
            return;
        }

        $this->pending->addChild(new Spacer(1));

        foreach ($queued as $message) {
            $this->pending->addChild(new TruncatedText($this->palette->fg('dim', "Queued: {$message}"), 1, 0));
        }
    }

    /** A note in the transcript — what a key did, or why something did not happen. */
    private function say(string $message): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Text($this->palette->fg('dim', $message), 1, 0));
        $this->tui->requestRender();
    }

    /**
     * Something went wrong, in red and labelled — upstream's `showError()`.
     *
     * The label is part of the message rather than a colour on its own, because a red
     * line in a transcript that is already full of colour is not a signal.
     */
    private function sayError(string $message): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Text($this->palette->fg('error', "Error: {$message}"), 1, 0));
        $this->tui->requestRender();
    }

    private static function textOf(UserMessage $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
