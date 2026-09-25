<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Agent\ToolExecutionUpdateEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Changelog;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\CustomTools\ToolProblem;
use Pig\CodingAgent\Hooks\Events\SessionBeforeSwitchEvent;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\Events\SessionSwitchEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\RegisteredCommand;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\AutoCompactionEndEvent;
use Pig\CodingAgent\Session\AutoCompactionStartEvent;
use Pig\CodingAgent\Session\RetryEndEvent;
use Pig\CodingAgent\Session\RetryStartEvent;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\TreeJump;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\ExternalTool;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\SlashCommand;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Component;
use Pig\Tui\Clipboard\SystemClipboard;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\Loader;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\SettingItem;
use Pig\Tui\Components\SettingsList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\TruncatedText;
use Pig\Tui\Container;
use Pig\Tui\Process;
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

    /**
     * What is *happening*: the working loader, a retry countdown, a summary in progress.
     *
     * Written from agent events, which arrive without anybody pressing a key.
     */
    private readonly Container $status;

    /**
     * What is being *asked*: a picker, a settings screen, a hook's dialog.
     *
     * Separate from `$status` because the two have different writers and the writers do not
     * know about each other. They shared one container once, and the bug that came out of it
     * is worth stating in full, because nothing about it looked wrong: a picker opened while
     * a turn was running, the turn ended, `onEnd()` cleared the container the picker was
     * in — and the picker vanished from the screen **with the focus still on it**. The next
     * keystroke went to an invisible list. Somebody typing what they thought was a message
     * into the editor was changing settings, one row at a time, with nothing on screen to
     * say so.
     *
     * Whatever holds the focus has to be in a container that only the thing that gave it the
     * focus ever clears. That is the whole of it, and it is why this field exists rather
     * than a flag saying a picker is open: a flag makes every *other* writer responsible for
     * checking it, which is the arrangement that failed.
     */
    private readonly Container $overlay;

    private readonly CustomEditor $editor;

    private readonly FooterComponent $footer;

    private ?Loader $working = null;

    private ?AssistantMessageComponent $streaming = null;

    /** @var array<string, ToolExecutionComponent> by tool call id */
    private array $tools = [];

    /** ctrl+o: more of everything — tool output, and the full key list. */
    private bool $expanded = false;

    private bool $hideThinking = false;

    /** `terminal.showImages` — off names a picture rather than drawing it. */
    private bool $showImages = true;

    /** The easter egg, while it is animating, so its frame timer can be stopped. */
    private ?ArminComponent $armin = null;

    /** The editor's text starts with `!`, so it is a command and not a prompt. */
    private bool $bashMode = false;

    private float $lastCtrlC = 0.0;

    private bool $running = false;

    /** Which of the two built-in themes is on, so /theme knows what to switch to. */
    private string $theme = 'dark';

    /** @var list<ContextFile> what the system prompt was given, so it can be shown */
    private array $contextFiles = [];

    /** @var list<Skill> likewise — shown, not discovered here */
    private array $skills = [];

    /** @var list<FileCommand> prompts kept as files, reachable as `/name` */
    private array $fileCommands = [];

    private ?HookRunner $hooks = null;

    private ?CustomToolSet $customTools = null;

    /** How a hook or a custom tool asks the person something. */
    private readonly TerminalUi $ui;

    /** @var array<string, RegisteredCommand> what the hooks added, by name */
    private array $hookCommands = [];

    /** What was chosen last time, and where the choices made here are remembered. */
    private readonly Settings $settings;

    private ?Text $banner = null;

    /** Set while a sign-in is waiting on a browser, so escape can end it. */
    private ?AbortController $signingIn = null;

    /** Set while the summariser is running, so escape can call it off. */
    private ?AbortController $compaction = null;

    /** Injected so a test can see what a copy would have put there. */
    private Clipboard $clipboard;

    /** @var array<string, Closure> what a hook draws its own messages with, by custom type */
    private array $messageRenderers = [];

    /**
     * @param list<string>                $initialMessages said before the first keystroke, in order
     * @param list<\Pig\Ai\ImageContent> $initialImages   attachments for the first of them
     */
    public function __construct(
        private readonly AgentSession $session,
        private Palette $palette,
        private readonly string $cwd,
        private readonly string $version,
        string $theme = 'dark',
        ?Terminal $terminal = null,
        array $contextFiles = [],
        array $skills = [],
        ?Clipboard $clipboard = null,
        array $fileCommands = [],
        ?Settings $settings = null,
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
        private readonly array $initialMessages = [],
        private readonly array $initialImages = [],
        // Last, because everything before it is passed positionally by a test and by
        // `bin/pig`, and a parameter inserted in the middle of that is fifteen silent
        // off-by-ones.
        private readonly ?Auth $auth = null,
        private readonly ?string $changelog = null,
    ) {
        $this->theme = $theme;
        $this->contextFiles = $contextFiles;
        $this->skills = $skills;
        $this->fileCommands = $fileCommands;
        $this->hooks = $hooks;
        $this->customTools = $customTools;
        $this->settings = $settings ?? Settings::inMemory();
        $this->hideThinking = $this->settings->hideThinking();
        $this->showImages = $this->settings->showImages();
        $this->clipboard = $clipboard ?? new SystemClipboard();

        // Injected so a test can drive this without a terminal, the same way the editor
        // takes its clipboard: everything below here is arrangement, and arrangement is
        // exactly what is worth testing.
        $this->tui = new Tui($terminal ?? new ProcessTerminal());
        $this->chat = new Container();
        $this->pending = new Container();
        $this->status = new Container();
        $this->overlay = new Container();
        $this->editor = new CustomEditor(new Editor($palette->editorTheme()));
        $this->footer = new FooterComponent($session, $palette, $cwd);

        // The palette as a closure: `/theme` replaces it, and a dialog opened afterwards
        // should be drawn in the colour that is on now.
        $this->ui = new TerminalUi(
            $this->tui,
            $this->chat,
            // The overlay, not the status area: a dialog holds the focus, so it belongs
            // where only the thing that opened it clears.
            $this->overlay,
            $this->editor,
            $this->footer,
            fn (): Palette => $this->palette,
            // The same `$VISUAL` hand-off Ctrl+G at the prompt uses, so the key means one
            // thing in both places and there is one piece of code to get right.
            fn (string $text): ?string => $this->externalEditor($text),
        );

        $hooks?->initialize(
            getModel: static fn () => $session->model(),
            isIdle: static fn (): bool => !$session->isStreaming(),
            abort: static function () use ($session): void {
                $session->abort();
            },
            hasQueuedMessages: static fn (): bool => $session->queued() !== [],
            ui: $this->ui,
            send: static function (HookMessage $message, bool $triggerTurn) use ($session): void {
                $session->sendHookMessage($message, $triggerTurn);
            },
            note: static function (string $customType, mixed $data) use ($session): void {
                $session->appendHookEntry($customType, $data);
            },
        );

        $customTools?->withUi($this->ui);
        $customTools?->withContext(fn () => $hooks?->context() ?? new HookContext($cwd, ui: $this->ui, hasUi: true));

        // Last, because reporting a hook's complaints needs the transcript to report
        // them into, and a name taken twice is a complaint made while reading the hooks.
        if ($hooks !== null) {
            $hooks->onError($this->sayHookError(...));
            $this->messageRenderers = $hooks->renderers();
            [$this->hookCommands, $clashes] = $hooks->commands();

            foreach ($clashes as $clash) {
                $hooks->emitError($clash);
            }
        }
    }

    /** Wire everything up and draw the first frame. */
    public function start(): void
    {
        $this->layout();
        $this->bindKeys();
        $this->bindEditor();
        $this->session->subscribe($this->onEvent(...));

        $this->replay();

        // After the replay, so an upgrade note sits under the conversation it is about rather
        // than above it. `bin/pig` is what decides there is one: it knows the version, holds the
        // settings the last-seen number is written to, and knows whether this is a resumed
        // session — where a release note nobody asked for is an interruption.
        if ($this->changelog !== null && $this->changelog !== '') {
            $this->sayChangelog($this->changelog);
        }

        $this->running = true;
        $this->hooks?->emit(new SessionStartEvent());
        $this->sayToolProblems($this->customTools?->notify('start') ?? []);
        $this->tui->start();

        // After the screen is up, so the first answer streams into a transcript that is
        // already being drawn rather than appearing all at once when it finishes. In a fiber
        // because each one is a whole turn, and the loop has to keep running underneath
        // them — escape has to reach a prompt that came from the command line too.
        if ($this->initialMessages !== []) {
            Async::spawn(function (): void {
                foreach ($this->initialMessages as $at => $message) {
                    $this->sendAndWait($message, $at === 0 ? $this->initialImages : []);
                }
            });
        }
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

            if ($message instanceof HookMessage) {
                $this->showHookMessage($message);

                continue;
            }

            if ($message instanceof CompactionSummary) {
                $this->chat->addChild(new CompactionComponent($message, $this->palette, $this->expanded));

                continue;
            }

            if ($message instanceof BranchSummary) {
                $this->chat->addChild(new BranchSummaryComponent($message, $this->palette, $this->expanded));

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
        $shown = new ToolExecutionComponent(
            'bash',
            ['command' => $execution->command],
            $this->palette,
            bashLines: ToolExecutionComponent::TYPED_BASH_LINES,
            showImages: $this->showImages,
        );
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
        // A frame timer outliving the screen it drew on keeps the loop from ever going idle.
        $this->armin?->dispose();

        // Before the session is let go of, so a hook that wants to write something down
        // still has a session to read. Nothing after this point is drawn — the terminal
        // is about to be handed back — so a hook that complains here complains to stderr
        // through whatever it uses itself.
        $this->hooks?->emit(new SessionShutdownEvent());

        // Nothing is drawn after this, so a tool that fails while letting go is reported
        // to stderr — the transcript is a moment away from being scrolled off.
        foreach ($this->customTools?->notify('shutdown') ?? [] as $problem) {
            fwrite(STDERR, "tool {$problem->toText()}\n");
        }

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
        // Below what is happening and directly above the editor, because it is the thing
        // being answered and the editor is where the eyes already are.
        $this->tui->addChild($this->overlay);
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
        $sections = [];

        if ($this->contextFiles !== []) {
            $names = array_map(
                static fn (ContextFile $file): string => basename($file->path),
                $this->contextFiles,
            );

            $sections[] = $this->palette->fg('mdHeading', '[Context]') . "\n"
                . $this->palette->fg('muted', '  ' . implode(', ', array_unique($names)));
        }

        if ($this->skills !== []) {
            $names = array_map(static fn (Skill $skill): string => $skill->name, $this->skills);

            $sections[] = $this->palette->fg('mdHeading', '[Skills]') . "\n"
                . $this->palette->fg('muted', '  ' . implode(', ', $names));
        }

        if ($this->hooks !== null && !$this->hooks->isEmpty()) {
            $names = array_map(basename(...), $this->hooks->paths());

            $sections[] = $this->palette->fg('mdHeading', '[Hooks]') . "\n"
                . $this->palette->fg('muted', '  ' . implode(', ', $names));
        }

        if ($this->customTools !== null && !$this->customTools->isEmpty()) {
            $sections[] = $this->palette->fg('mdHeading', '[Tools]') . "\n"
                . $this->palette->fg('muted', '  ' . implode(', ', $this->customTools->names()));
        }

        return implode("\n\n", $sections);
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
        'ctrl+g' => 'edit the prompt in $VISUAL or $EDITOR',
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
        $this->editor->on('ctrl+g', function (): void {
            // In a fiber of its own, the same reason `send()` is: this runs inside the
            // input callback, and it suspends — which would suspend the loop that called it.
            Async::spawn($this->editPromptExternally(...));
        });

        $this->editor->on('ctrl+o', function (): void {
            $this->expanded = !$this->expanded;
            $this->banner?->setText($this->banner());

            foreach ($this->chat->children() as $child) {
                if ($child instanceof ToolExecutionComponent
                    || $child instanceof CompactionComponent
                    || $child instanceof BranchSummaryComponent
                ) {
                    $child->setExpanded($this->expanded);
                }
            }

            $this->tui->requestRender();
        });

        $this->editor->on('ctrl+t', function (): void {
            $this->useHideThinking(!$this->hideThinking);
            $this->say($this->hideThinking ? 'Thinking hidden' : 'Thinking shown');
        });
    }

    /**
     * Show or hide thinking, everywhere it is already drawn as well as from here on.
     *
     * Split out of the ctrl+t handler so `/settings` sets the same thing the same way —
     * two places writing the setting and only one of them telling the components on
     * screen is how a toggle comes to half work.
     */
    private function useHideThinking(bool $hide): void
    {
        $this->hideThinking = $hide;
        $this->settings->setHideThinking($hide);

        foreach ($this->chat->children() as $child) {
            if ($child instanceof AssistantMessageComponent) {
                $child->setHideThinking($hide);
            }
        }
    }

    /**
     * Draw pictures, or name them — here and in everything already on screen.
     *
     * Same shape and same reason as `useHideThinking()`: the setting is one a transcript
     * already drawn has an opinion about, so every `ToolExecutionComponent` in it is told.
     */
    private function useShowImages(bool $show): void
    {
        $this->showImages = $show;
        $this->settings->setShowImages($show);

        foreach ($this->chat->children() as $child) {
            if ($child instanceof ToolExecutionComponent) {
                $child->setShowImages($show);
            }
        }
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
        // First, because it is the most recent thing the person started and the only one that
        // can be waiting on somebody else's server for a quarter of an hour.
        if ($this->signingIn !== null) {
            $this->signingIn->abort('Cancelled');

            return;
        }

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

    /**
     * Ctrl+G: edit what is in the prompt in a real editor.
     *
     * Upstream's `openExternalEditor()`. `CustomEditor` has reported this key since it was
     * ported and nothing was listening, so pressing it did nothing at all.
     *
     * Allowed while the agent is working, which is the case it is most wanted for: the
     * model is busy and the next message is going to be three paragraphs. That only works
     * because the loop keeps turning while the editor has the terminal — see
     * `externalEditor()`.
     */
    private function editPromptExternally(): void
    {
        $edited = $this->externalEditor($this->editor->text());

        if ($edited === null) {
            return;
        }

        $this->editor->setText($edited);
        $this->tui->requestRender(true);
    }

    /**
     * Write $text to a temp file, open it in the person's editor, read it back.
     *
     * Null when there is no editor configured, when it could not be started, or when it
     * exited non-zero — all three mean "keep what was there", which is what someone who
     * quit vim with `:cq` meant.
     *
     * The `.md` suffix is upstream's, and it is what makes an editor turn on the syntax
     * highlighting and the soft wrap that a prompt wants.
     *
     * **The loop keeps turning while the editor is open.** Upstream blocks on `spawnSync`,
     * which stops libuv for as long as the person takes — and a model streaming into a
     * socket nobody reads eventually has its connection reset. pig polls the child and
     * yields instead, so the turn in flight is read and appended exactly as it would have
     * been; the TUI is stopped, so none of it is drawn until the forced redraw on the way
     * back. Nothing pig can do about a *drawn* frame while vim owns the screen, but there
     * is nothing to lose by not reading.
     *
     * Must be called from a fiber. Both callers spawn one.
     */
    private function externalEditor(string $text): ?string
    {
        $command = getenv('VISUAL') ?: getenv('EDITOR');

        if ($command === false || trim($command) === '') {
            $this->sayWarning('No editor configured. Set $VISUAL or $EDITOR.');

            return null;
        }

        $file = sys_get_temp_dir() . '/pig-editor-' . bin2hex(random_bytes(4)) . '.md';

        if (file_put_contents($file, $text) === false) {
            $this->sayError("Could not write {$file}");

            return null;
        }

        // Split on spaces so `code --wait` works, which is how a GUI editor has to be
        // given: without `--wait` it returns immediately and the file is read back unchanged.
        $argv = [...preg_split('/\s+/', trim($command)), $file];

        $this->tui->stop();

        try {
            $exit = Process::interactive($argv, static function (): void {
                Async::delay(Process::ttyPollSeconds());
            });

            if ($exit !== 0) {
                return null;
            }

            $edited = file_get_contents($file);

            // One trailing newline removed, because every editor adds one and a prompt
            // that grew a blank line each time it was edited would be a nuisance.
            return $edited === false ? null : preg_replace('/\n$/', '', $edited);
        } finally {
            // Before the TUI comes back, so a failure to delete is not drawn over.
            if (is_file($file)) {
                unlink($file);
            }

            $this->tui->start();
            $this->tui->requestRender(true);
        }
    }

    private function cycleThinking(): void
    {
        $level = $this->session->cycleThinkingLevel();

        if ($level === null) {
            $this->say('This model does not support thinking');

            return;
        }

        $this->settings->setDefaultThinkingLevel($level);
        $this->paintBorder();
        $this->footer->invalidate();
        $this->say('Thinking: ' . $level->value);
    }

    // ---- what the person typed -------------------------------------------------------------

    private function bindEditor(): void
    {
        // Ctrl+V: an image on the clipboard is written to a temp file and its path
        // pasted, which is how a screenshot gets to the model.
        $this->editor->setClipboard($this->clipboard);

        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider(
            [
                ...array_map(
                    static fn (array $command): SlashCommand => new SlashCommand($command[0], $command[1]),
                    self::COMMANDS,
                ),
                ...array_map(
                    static fn (FileCommand $command): SlashCommand => new SlashCommand(
                        $command->name,
                        $command->description,
                    ),
                    $this->fileCommands,
                ),
                ...array_map(
                    static fn (RegisteredCommand $command): SlashCommand => new SlashCommand(
                        $command->name,
                        $command->description,
                    ),
                    array_values($this->hookCommands),
                ),
            ],
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

            // A name this session knows, or nothing. Never "starts with a slash", which is
            // what this used to be and what read a pasted picture's path as a command.
            $command = $this->commandName($text);

            if ($command !== null) {
                $this->editor->setText('');
                $this->command($text, $command);

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
            $this->sayWarning('A command is already running. Press esc to stop it.');

            return;
        }

        $shown = new ToolExecutionComponent(
            'bash',
            ['command' => $command],
            $this->palette,
            bashLines: ToolExecutionComponent::TYPED_BASH_LINES,
            showImages: $this->showImages,
        );
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
    private function send(string $text, array $images = []): void
    {
        Async::spawn(function () use ($text, $images): void {
            $this->sendAndWait($text, $images);
        });
    }

    /**
     * The same turn, in the caller's fiber.
     *
     * `send()` spawns because it is called from a key handler, and a key handler that
     * suspends suspends the loop that called it. The queue of command-line messages is
     * already in a fiber of its own and has to send them *in order*, which means waiting.
     *
     * @param list<\Pig\Ai\ImageContent> $images
     */
    private function sendAndWait(string $text, array $images = []): void
    {
        try {
            // Before the turn rather than after the failure: a request that does not
            // fit comes back as an error from the provider, and by then the person
            // has already waited for it.
            if ($this->session->shouldCompact()) {
                $this->say($this->palette->fg('muted', 'Context is nearly full — summarising first.'));
                $this->compact();
            }

            $this->session->prompt($text, $images);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());
        }
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
        ['model', 'Switch models, or say which one'],
        ['skills', 'What the model can reach for, and where it came from'],
        ['copy', 'Put the last answer on the clipboard'],
        ['export', 'Write this conversation out as an HTML file'],
        ['compact', 'Summarise the conversation so far and carry on from the summary'],
        ['resume', 'Pick up an earlier conversation'],
        ['tree', 'Go back to an earlier point and take it somewhere else'],
        ['label', 'Name this point, so /tree can find it again — /label with nothing clears it'],
        ['login', 'Sign in with a subscription instead of an API key'],
        ['logout', 'Forget a sign-in'],
        ['theme', 'Switch between dark and light'],
        ['settings', 'Change what is switchable, and see what it is set to'],
        ['changelog', 'What changed, release by release'],
        ['hooks', 'What hooks loaded, and what they added'],
        ['tools', 'What the model can call, built-in and loaded'],
        ['exit', 'Quit'],
    ];

    /**
     * The command this line names, or null — in which case it is a message.
     *
     * **Exact, against the names that exist.** Not "the line starts with a slash", and not
     * "the first word looks like a name": pasting a picture writes it to a temp file and puts
     * the *path* in the editor, so a line beginning with `/` is as likely to be
     * `/var/folders/…/pig-clipboard-x.png` as it is to be `/compact`. Both looser rules read
     * that path as a command, reported it as unknown, and swallowed the question typed after
     * it. Upstream's rule is this one, spelled out eighteen times (`text === "/settings"`); it
     * is spelled once here because the hooks' and the file commands' names are only known at
     * runtime.
     *
     * What follows from being exact: `/setings` is **not** a command, so it goes to the model
     * as the text it is. There is no "did you mean" and no error, because deciding that a typo
     * is a typo means guessing, and guessing is what this method exists to stop doing.
     */
    private function commandName(string $text): ?string
    {
        if (!str_starts_with($text, '/')) {
            return null;
        }

        $name = strtok(substr($text, 1), " \t") ?: '';

        return $this->knowsCommand($name) ? $name : null;
    }

    /** Whether anything answers to this name: a built-in, a hook's, or one kept as a file. */
    private function knowsCommand(string $name): bool
    {
        // Two that are known and not listed, because `COMMANDS` is also what `/help` prints and
        // what the autocomplete offers: `quit` is an alias for one that is listed, and
        // `arminsayshi` is an easter egg — upstream leaves it out of its own command list too,
        // and something you have to already know about is the whole idea.
        if ($name === 'quit' || $name === 'arminsayshi') {
            return true;
        }

        foreach (self::COMMANDS as [$builtIn]) {
            if ($builtIn === $name) {
                return true;
            }
        }

        if (isset($this->hookCommands[$name])) {
            return true;
        }

        foreach ($this->fileCommands as $command) {
            if ($command->name === $name) {
                return true;
            }
        }

        return false;
    }

    /** @param string $name already resolved by `commandName()`, so this does not parse it again */
    private function command(string $text, string $name): void
    {
        match ($name) {
            'help' => $this->say($this->commandHelp()),
            'new' => $this->newSession(),
            'session' => $this->say($this->sessionSummary()),
            'compact' => $this->startCompaction(trim(substr($text, strlen($name) + 1))),
            'model' => $this->showModels(trim(substr($text, strlen($name) + 1))),
            'skills' => $this->say($this->skillList()),
            'copy' => $this->copyLastAnswer(),
            'export' => $this->exportSession(trim(substr($text, strlen($name) + 1))),
            'resume' => $this->showSessions(),
            'tree' => $this->showTree(),
            'label' => $this->label(trim(substr($text, strlen($name) + 1))),
            'login' => $this->showSignIns('login'),
            'logout' => $this->showSignIns('logout'),
            'theme' => $this->switchTheme(),
            'settings' => $this->showSettings(),
            'changelog' => $this->showChangelog(),
            'arminsayshi' => $this->sayHi(),
            'hooks' => $this->say($this->hookList()),
            'tools' => $this->say($this->toolList()),
            'exit', 'quit' => $this->stop(),
            // A hook's or a file's, which is all that is left: `commandName()` only hands
            // over names something answers to, and the built-ins are above, so a built-in
            // can never be shadowed by something someone forgot they wrote.
            default => $this->runAddedCommand($text, $name),
        };
    }

    /**
     * A command a hook registered, or a prompt kept as a file, or neither.
     *
     * Hooks first: a hook command is code and a file command is a prompt, and the one
     * that can look at the arguments should get the chance to.
     */
    private function runAddedCommand(string $text, string $name): void
    {
        $command = $this->hookCommands[$name] ?? null;

        if ($command === null) {
            $this->runFileCommand($text, $name);

            return;
        }

        $arguments = trim(substr($text, strlen($name) + 1));

        try {
            ($command->handler)($arguments, $this->hooks?->context() ?? new HookContext($this->cwd));
        } catch (Throwable $error) {
            // Not fatal: a command that failed is one command, and the session it failed
            // in is still a session.
            $this->hooks?->emitError(new HookError($command->hookPath, "/{$name}", $error->getMessage()));
        }

        $this->tui->requestRender();
    }

    /** What hooks loaded, where from, and what each one is listening for. */
    private function hookList(): string
    {
        if ($this->hooks === null || $this->hooks->isEmpty()) {
            return 'No hooks loaded. A .php file in ~/.pig/hooks or .pig/hooks that returns a callable is one.';
        }

        $lines = [];

        foreach ($this->hooks->paths() as $path) {
            $lines[] = $this->palette->fg('muted', $path);
        }

        if ($this->hookCommands !== []) {
            $lines[] = '';

            foreach ($this->hookCommands as $name => $command) {
                $lines[] = $this->palette->fg('dim', str_pad('/' . $name, 12))
                    . $this->palette->fg('muted', $command->description);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Every tool the model can call this session, and where each came from.
     *
     * Read off the agent rather than off the two loaders, so what is listed is what the
     * model was actually given — a tool that failed to load is missing here, which is the
     * answer someone typing this is after.
     */
    private function toolList(): string
    {
        $custom = [];

        foreach ($this->customTools?->loaded() ?? [] as $one) {
            $custom[$one->tool->name] = $one->path;
        }

        $lines = [];

        foreach ($this->session->state()->tools as $tool) {
            $name = $tool->definition()->name;

            $lines[] = $this->palette->fg('dim', str_pad($name, 14))
                . $this->palette->fg('muted', $tool->label() . ' · ' . ($custom[$name] ?? 'built-in'));
        }

        return $lines === [] ? 'This session has no tools at all.' : implode("\n", $lines);
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
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        Async::spawn(fn () => $this->compact($instructions === '' ? null : $instructions));
    }

    /**
     * Send a prompt kept as a file, or say there is no such command.
     *
     * The expansion is sent as if it had been typed, because that is what it is: a
     * command here is a stored prompt, not a program.
     */
    private function runFileCommand(string $text, string $name): void
    {
        $expanded = SlashCommands::expand($text, $this->fileCommands);

        if ($expanded === null) {
            // Unreachable: `commandName()` resolved this name against the same list. Said
            // rather than thrown, because a session is worth more than a stack trace — and
            // said as the bug it would be rather than as something the person did wrong.
            $this->sayError("/{$name} is in the command list but has no prompt behind it.");

            return;
        }

        if ($this->session->isStreaming()) {
            $this->session->followUp($expanded);
            $this->showQueue();
            $this->tui->requestRender();

            return;
        }

        // Not drawn here: `onMessageStart` draws every user message, and drawing it
        // twice is what happens to anything that helpfully draws its own.
        $this->send($expanded);
    }

    /**
     * Write the conversation out as one HTML file.
     *
     * Only a saved session can be exported, because the file is built from what is on
     * disk rather than from what is on screen — a `--no-save` run has nothing to build
     * from, and saying so beats writing an empty page.
     *
     * @param string $path from `/export somewhere.html`; beside the session when empty
     */
    private function exportSession(string $path): void
    {
        $store = $this->session->store();

        if ($store === null) {
            $this->sayError('This session is not being saved, so there is nothing to export.');

            return;
        }

        try {
            $written = HtmlExport::write(
                $store,
                $path === '' ? HtmlExport::defaultPath($store, $this->cwd) : $path,
                $this->theme,
            );
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        $this->say('Exported to ' . $written);
    }

    /**
     * Put the last answer on the clipboard.
     *
     * The last thing the *assistant* said, not the last thing on screen: what someone
     * wants after reading an answer is the answer, not the status line under it.
     */
    private function copyLastAnswer(): void
    {
        $text = $this->session->lastAssistantText();

        if ($text === null) {
            $this->sayError('No agent messages to copy yet.');

            return;
        }

        if (!$this->clipboard->write($text)) {
            // A machine with no clipboard tool is not a broken machine, but it is worth
            // naming the thing to install rather than saying it did not work.
            $this->sayError('Could not copy. On Linux this needs wl-copy, xclip or xsel.');

            return;
        }

        $this->say('Copied the last answer — ' . number_format(mb_strlen($text)) . ' characters');
    }

    /**
     * The skills loaded, and which root each came from.
     *
     * The source is shown because the same name can live in four places and the one that
     * won is not obvious — a `~/.claude` skill shadowed by a project one looks like the
     * project one simply not working.
     */
    private function skillList(): string
    {
        if ($this->skills === []) {
            return 'No skills found. A skill is a folder with a SKILL.md in '
                . '~/.pig/skills, .pig/skills, ~/.claude/skills, .claude/skills or ~/.codex/skills.';
        }

        $lines = [];

        foreach ($this->skills as $skill) {
            $lines[] = $this->palette->fg('dim', str_pad($skill->name, 24))
                . $this->palette->fg('muted', $skill->description);
            $lines[] = $this->palette->fg('dim', str_repeat(' ', 24) . $skill->source . ' · ' . $skill->path);
        }

        return implode("\n", $lines);
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
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        if (!$this->mayLeave('new')) {
            return;
        }

        $previous = $this->session->store()?->path;

        // A new file, not just an empty screen. Keeping the old one would append this
        // conversation onto the last one as if they were the same, and the new session
        // would never exist as a session — which is what this used to do.
        if ($previous !== null) {
            try {
                $this->session->writeTo(SessionManager::create($this->cwd));
            } catch (Throwable $error) {
                $this->sayError('Could not start a new session file: ' . $error->getMessage());

                return;
            }
        }

        $this->session->agent->reset();
        $this->chat->clear();
        $this->pending->clear();
        $this->status->clear();
        $this->footer->invalidate();
        $this->say('New session');

        $this->hooks?->emit(new SessionSwitchEvent('new', $previous));
        $this->sayToolProblems($this->customTools?->notify('switch', $previous) ?? []);
    }

    /**
     * Ask the hooks whether this conversation may be left.
     *
     * `/new` throws the conversation away and `/resume` replaces it, and both are one
     * keystroke — which is exactly the kind of thing a hook is for.
     *
     * @param 'new'|'resume' $reason
     */
    private function mayLeave(string $reason, ?string $target = null): bool
    {
        $refusal = $this->hooks?->emitBeforeSwitch(new SessionBeforeSwitchEvent($reason, $target));

        if ($refusal === null || !$refusal->cancel) {
            return true;
        }

        $this->say('A hook stopped that.');

        return false;
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
            $this->sayWarning('Still working. Press esc first.');

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

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text($this->palette->fg('muted', 'Pick a session — enter to open, esc to cancel'), 1, 0));
        $this->overlay->addChild($picker);

        // Focus moves to the list, so arrow keys reach it rather than the editor.
        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /**
     * Offer the models there are, newest first.
     *
     * Every model rather than the ones an API key exists for: a list that silently hides
     * what you were looking for teaches nothing, and the missing key is something
     * `bin/pig` already says plainly when a turn is actually sent.
     *
     * @param string $pattern from `/model sonnet` — switches without opening the list
     */
    private function showModels(string $pattern = ''): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        if ($pattern !== '') {
            $this->pickModel($pattern);

            return;
        }

        $models = Models::all();
        $current = $this->session->model();
        $items = [];

        foreach ($models as $index => $model) {
            $items[] = new SelectItem(
                (string) $index,
                $model->id . ($current !== null && $model->is($current) ? ' ·' : ''),
                sprintf(
                    '%s · %s · %s in / %s out per Mtok%s',
                    $model->provider,
                    $model->name,
                    self::dollars($model->pricing->input),
                    self::dollars($model->pricing->output),
                    $model->reasoning ? ' · thinks' : '',
                ),
            );
        }

        $picker = new SelectList($items, 8, $this->palette->selectListTheme());
        $picker->setSelectHandler(function (SelectItem $item) use ($models): void {
            $this->closePicker();
            $this->useModel($models[(int) $item->value]);
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text($this->palette->fg('muted', 'Pick a model — enter to switch, esc to cancel'), 1, 0));
        $this->overlay->addChild($picker);

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /** `/model sonnet:high` — resolve it and switch, or say why not. */
    private function pickModel(string $pattern): void
    {
        $choice = ModelResolver::parse($pattern);

        if ($choice === null) {
            $this->sayError("No model matches \"{$pattern}\". Try /model on its own for the list.");

            return;
        }

        if ($choice->warning !== null) {
            $this->sayWarning($choice->warning);
        }

        $this->useModel($choice->model, $choice->thinking);
    }

    private function useModel(Model $model, ?ThinkingLevel $thinking = null): void
    {
        $this->session->setModel($model, $thinking);
        $this->settings->setDefaultModel($model->id, $model->provider);
        $this->settings->setDefaultThinkingLevel($this->session->thinkingLevel());
        $this->footer->invalidate();
        $this->paintBorder();

        $level = $this->session->thinkingLevel();

        // The level is said too, because switching models can change it under you — and
        // finding that out from a bill is worse than reading it here.
        $this->say($level === ThinkingLevel::Off
            ? "Model: {$model->id}"
            : "Model: {$model->id} · thinking {$level->value}");
    }

    /** Prices are per million tokens, and the cheap ones are cents. */
    private static function dollars(float $amount): string
    {
        return '$' . ($amount < 1.0 ? rtrim(rtrim(number_format($amount, 2), '0'), '.') : number_format($amount, 2));
    }

    /**
     * Offer the points in this conversation that can be gone back to.
     *
     * Newest first, because going back is nearly always going back a little. What is
     * gone back *to* stays; what came after it is left in the file, so a road not taken
     * is a road that can be taken again.
     */
    private function showTree(): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        $store = $this->session->store();

        if ($store === null) {
            $this->sayError('This session is not being saved, so there is nowhere to go back to.');

            return;
        }

        $points = array_reverse($store->branch());

        if (count($points) < 2) {
            $this->say('Nothing to go back to yet.');

            return;
        }

        $items = [];

        foreach ($points as $index => $point) {
            // A name, where somebody gave one: "4 back · 7 back · 12 back" is a list nobody
            // can choose from, and "before the refactor" is. The message stays beside it
            // rather than being replaced, because the name is what you were thinking and the
            // message is what was actually said.
            $said = self::describe($point['message']);
            $label = $point['label'];

            $items[] = new SelectItem(
                $point['id'],
                $label === null ? $said : $this->palette->fg('accent', $label) . ' · ' . $said,
                ($index === 0 ? 'where you are' : $index . ' back')
                    . ($point['branches'] > 1 ? ' · ' . $point['branches'] . ' ways from here' : ''),
            );
        }

        $picker = new SelectList($items, 8, $this->palette->selectListTheme());
        $picker->setSelectHandler(function (SelectItem $item): void {
            $this->closePicker();

            // In a fiber: going back may ask the model to summarise what is being left,
            // and that suspends. Same reason `send()` spawns.
            Async::spawn(fn () => $this->goBackTo($item->value));
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text(
            $this->palette->fg('muted', 'Go back to — enter to pick, esc to cancel'),
            1,
            0,
        ));
        $this->overlay->addChild($picker);

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /**
     * Name the point the conversation is at, for `/tree` to find later.
     *
     * The last thing that was said — so `/label before the refactor` typed now names the
     * place you are about to leave, which is when anybody thinks to name one.
     */
    private function label(string $name): void
    {
        $store = $this->session->store();

        if ($store === null) {
            $this->sayError('This session is not being saved, so a name would not survive it.');

            return;
        }

        // The last thing that was *said*, not the last entry. Writing a label advances the
        // leaf — the label entry becomes it — so `/label a name` followed by `/label` to
        // clear it would otherwise name the first label rather than clearing the message's.
        $points = $store->branch();
        $point = $points === [] ? null : $points[count($points) - 1]['id'];

        if ($point === null) {
            $this->say('Nothing has happened yet to name.');

            return;
        }

        $name = trim($name);

        try {
            $store->appendLabel($point, $name);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        $this->say($name === ''
            ? 'Name cleared.'
            : 'Named this point ' . $this->palette->fg('accent', $name) . '.');
    }

    /**
     * Go back, having first asked whether to write down what is being left.
     *
     * The question is only asked when there is something to answer it about: jumping to the
     * point you are already on, or to somewhere with nothing between, leaves nothing behind.
     */
    private function goBackTo(string $entryId): void
    {
        $leaving = $this->session->store()?->abandoning($entryId) ?? [];

        $wants = $leaving !== []
            && $this->session->model() !== null
            && $this->ui->confirm('Summarise the branch you are leaving?', self::summarise($leaving));

        try {
            $jump = $wants ? $this->summarisedJump($entryId) : $this->session->goTo($entryId);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        if (!$jump->moved) {
            // Called off part-way: nothing moved, so the list comes back rather than the
            // person being left wondering which branch they are on.
            $this->sayWarning('Branch summary cancelled — still where you were.');
            $this->showTree();

            return;
        }

        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();
        $this->say('Went back — anything you say now starts a new branch');

        if ($jump->summary !== null) {
            $this->chat->addChild(new BranchSummaryComponent($jump->summary, $this->palette, $this->expanded));
        }

        $this->sayToolProblems($this->customTools?->notify('tree') ?? []);
    }

    /**
     * The jump with a summary, under a spinner escape can stop.
     *
     * The same arrangement `/compact` uses, and for the same reason: a model call with
     * nothing on screen looks like a session that has frozen.
     */
    private function summarisedJump(string $entryId): TreeJump
    {
        $controller = new AbortController();
        $this->compaction = $controller;

        $loader = new Loader(
            $this->tui,
            fn (string $frame): string => $this->palette->fg('accent', $frame),
            fn (string $text): string => $this->palette->fg('muted', $text),
            'Summarising the branch... (esc to stop)',
        );

        $this->status->clear();
        $this->status->addChild(new Spacer(1));
        $this->status->addChild($loader);
        $this->tui->requestRender();

        try {
            return $this->session->goTo($entryId, summarise: true, signal: $controller->signal);
        } finally {
            $this->compaction = null;
            $loader->stop();
            $this->status->clear();
            $this->tui->requestRender();
        }
    }

    /**
     * What the confirm dialog says is at stake.
     *
     * @param list<mixed> $leaving
     */
    private static function summarise(array $leaving): string
    {
        return count($leaving) === 1 ? '1 message' : count($leaving) . ' messages';
    }

    /** One message, short enough to pick from a list. */
    private static function describe(mixed $message): string
    {
        $text = match (true) {
            $message instanceof UserMessage, $message instanceof AssistantMessage => self::textOf($message),
            $message instanceof BashExecution => '$ ' . $message->command,
            $message instanceof ToolResultMessage => $message->toolName . ' result',
            $message instanceof CompactionSummary => 'compacted',
            default => '',
        };

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '(nothing said)';
        }

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '...' : $text;
    }

    /**
     * Which subscription to sign in with, or which sign-in to forget.
     *
     * Upstream's `oauth-selector.ts` has a component of its own for this; here it is the same
     * `SelectList` in the same place as `/model`, `/resume` and `/tree`, because a fourth
     * picker that behaves like a fourth picker is worth more than a literal port of a
     * component that does less.
     *
     * A provider pig cannot sign in with is shown greyed and **says why** when it is chosen.
     * Upstream ignores the key, which reads as the list being broken.
     */
    private function showSignIns(string $mode): void
    {
        if ($this->auth === null) {
            $this->sayWarning('This session has nowhere to keep a sign-in.');

            return;
        }

        $signingIn = $mode === 'login';
        $items = [];
        $providers = [];

        foreach (Provider::cases() as $provider) {
            $signedIn = $this->auth->kind($provider->value) === 'oauth';

            if (!$signingIn && !$signedIn) {
                continue;
            }

            $providers[] = $provider;
            $items[] = new SelectItem(
                (string) (count($providers) - 1),
                $provider->available() ? $provider->label() : $this->palette->fg('dim', $provider->label()),
                $signedIn ? 'signed in' : ($provider->available() ? '' : 'not ported yet'),
            );
        }

        if ($items === []) {
            $this->say('Nothing is signed in. /login first.');

            return;
        }

        $picker = new SelectList($items, 8, $this->palette->selectListTheme());
        $picker->setSelectHandler(function (SelectItem $item) use ($providers, $signingIn): void {
            $this->closePicker();
            $provider = $providers[(int) $item->value];

            $signingIn ? $this->signIn($provider) : $this->signOut($provider);
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text($this->palette->fg(
            'muted',
            $signingIn ? 'Sign in with — enter to choose, esc to cancel' : 'Forget which sign-in — enter to choose, esc to cancel',
        ), 1, 0));
        $this->overlay->addChild($picker);

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /**
     * Show the URL, wait for what comes back, and keep the tokens.
     *
     * Spawned, because the paste box parks the fiber it is asked on and this is running
     * inside the loop's own input callback — suspending there suspends the loop that has to
     * deliver the keystrokes. Same reason `send()` and `startCompaction()` spawn.
     */
    private function signIn(Provider $provider): void
    {
        if (!$provider->available()) {
            $this->sayWarning("{$provider->label()} is not ported yet — pig cannot finish that sign-in.");

            return;
        }

        Async::spawn(function () use ($provider): void {
            // Escape has to be able to end this: Copilot's flow polls GitHub for up to fifteen
            // minutes, and a fiber waiting that long with nothing able to stop it is a session
            // that has to be killed. `interrupt()` reaches this controller.
            $controller = new AbortController();
            $this->signingIn = $controller;

            try {
                $credentials = $this->auth?->login(
                    $provider,
                    function (string $url, ?string $instructions): void {
                        $this->say(trim(
                            "Open this and approve it:\n\n" . self::link($url)
                            . "\n\n" . ($instructions ?? ''),
                        ));

                        // Said before it is opened, so the URL is on screen whatever the
                        // browser does — including not existing.
                        self::openInBrowser($url);
                    },
                    // `$allowEmpty` is part of upstream's contract and is enforced by the flow
                    // that asked, not here: an empty answer is `github.com` for Copilot's
                    // domain prompt and a cancellation for Anthropic's paste box.
                    fn (string $message, string $placeholder, bool $allowEmpty): ?string
                        => $this->ui->input($message, $placeholder),
                    function (string $note): void {
                        $this->say($note);
                    },
                    $controller->signal,
                );

                $this->say($credentials === null
                    ? 'Signing in was cancelled.'
                    : "Signed in with {$provider->label()}.");
            } catch (Throwable $problem) {
                $this->sayError($problem->getMessage());
            } finally {
                $this->signingIn = null;
            }

            $this->tui->requestRender();
        });
    }

    private function signOut(Provider $provider): void
    {
        try {
            $this->auth?->remove($provider->value);
            $this->say("Forgot the {$provider->label()} sign-in.");
        } catch (Throwable $problem) {
            $this->sayError($problem->getMessage());
        }
    }


    /**
     * A URL the terminal can be clicked on, where the terminal allows it.
     *
     * OSC 8, which upstream uses for the same thing. The link text is the URL itself rather
     * than upstream's "Click here to login": a terminal that does not understand the sequence
     * ignores it and shows the label, and a label is not something anybody can copy.
     * `Ansi::strip()` already knew about OSC 8, so the width of this line measures correctly.
     */
    private static function link(string $url): string
    {
        return "\e]8;;{$url}\x07" . $url . "\e]8;;\x07";
    }

    /**
     * Open it, if this machine has anything to open it with.
     *
     * Best effort on purpose: the URL is on screen, so a headless box or one without
     * `xdg-open` is not a broken sign-in — it is one extra copy-and-paste. Nothing is thrown
     * and nothing is swallowed either, because `Process::run()` reports an exit code rather
     * than raising; ignoring that code is the judgement, and this is where it is written down.
     *
     * Two seconds is a ceiling rather than a wait: all three of these commands hand off and
     * return at once, and anything that does not is not going to.
     */
    private static function openInBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            // The empty argument is the window title `start` otherwise takes the URL for.
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };

        Process::run($command, 2.0);
    }

    /**
     * Put the overlay away and give the editor the keys back.
     *
     * The two halves of closing a picker are that the overlay stops holding it and the focus
     * moves, and **they have to happen together** — the bug `$overlay` exists for was one
     * half happening on its own, from somewhere that had no idea a picker was open. Clearing
     * the overlay here is the only place either half happens without the other being right
     * beside it.
     */
    private function closePicker(): void
    {
        $this->overlay->clear();
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    /** Replace this conversation with a saved one, and redraw it. */
    private function resume(SessionInfo $info): void
    {
        if (!$this->mayLeave('resume', $info->path)) {
            return;
        }

        $previous = $this->session->store()?->path;

        try {
            $saved = SessionManager::open($info->path);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        // The file that was opened is the one written to from here on. Without this the
        // conversation on screen is the resumed one while everything said next is appended
        // to the file pig started with — two files, neither of them what happened.
        //
        // Only when this session was writing somewhere to begin with: `--no-save` means no
        // file, and resuming one to read it should not start saving.
        if ($previous !== null) {
            $this->session->writeTo($saved);
        }

        $this->session->restore($saved->messages());

        // And the model it was being had with. Nothing was typed here — `/resume` takes no
        // model — so the file wins outright, which is what "resume" means.
        $was = $this->session->model()?->id;
        $this->session->restoreSettings();

        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();

        // Said after the transcript, so it is the last thing on screen rather than the
        // first thing buried above a conversation.
        $this->say('Resumed ' . count($saved->messages()) . ' messages from ' . $info->when());

        $now = $this->session->model()?->id;

        if ($now !== null && $now !== $was) {
            // Worth a line: the model changing under someone without being told is how a
            // surprising answer becomes a puzzle.
            $this->say($this->palette->fg('muted', "This conversation was on {$now} — switched back to it."));
        }
        $this->hooks?->emit(new SessionSwitchEvent('resume', $previous));
        $this->sayToolProblems($this->customTools?->notify('switch', $previous) ?? []);
    }

    /**
     * `/arminsayshi` — the easter egg, animated into the transcript.
     *
     * Held onto so it can be stopped: every effect ends on its own now, but a session that is
     * quit or cleared mid-animation would otherwise leave a frame timer behind, and pi's own
     * never calls `dispose()` at all.
     */
    private function sayHi(): void
    {
        $this->armin?->dispose();
        $this->armin = new ArminComponent($this->tui, $this->palette);

        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild($this->armin);
        $this->tui->requestRender();
    }

    /**
     * `/changelog` — the whole file, newest last, between two rules.
     *
     * Reversed, as upstream reverses it: the file is written newest first and `parse()` hands it
     * back in that order, but this is appended to the bottom of a transcript somebody then reads
     * downwards. Rules around it because it is a block of somebody else's prose dropped into a
     * conversation, and markdown rather than text because that is what it is written in.
     */
    private function showChangelog(): void
    {
        $entries = Changelog::parse();

        $this->sayChangelog(
            $entries === [] ? 'No changelog entries found.' : Changelog::join(array_reverse($entries)),
        );
    }

    /**
     * A block of release notes in the transcript, titled and ruled off.
     *
     * Rules around it because it is a block of somebody else's prose dropped into a
     * conversation, and markdown rather than text because that is what it is written in. Both
     * callers — `/changelog` and the line `bin/pig` shows once after an upgrade — draw it the
     * same way, because they are the same thing arriving for two reasons.
     */
    private function sayChangelog(string $markdown): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Rule($this->palette->of('border')));
        $this->chat->addChild(new Text($this->palette->fg('accent', Style::bold('What\'s New')), 1, 0));
        $this->chat->addChild(new Markdown($markdown, 1, 1, $this->palette->markdownTheme()));
        $this->chat->addChild(new Rule($this->palette->of('border')));
        $this->tui->requestRender();
    }

    private function switchTheme(): void
    {
        $this->useTheme($this->theme === 'dark' ? 'light' : 'dark');
    }

    /**
     * `/settings` — everything that can be changed from inside a session, in one screen.
     *
     * **Only things that take effect.** Every row here is read again after it is changed, so
     * pressing Enter on it does something. `terminal.showImages` was nearly left off for
     * failing that — pig stored it and nothing read it — and the answer was to give it its
     * reader rather than a row that saves a value nobody looks at. Queue mode needed the same
     * kind of work and got it: `Agent` had no setter, because the anchor commit's queue split
     * removed upstream's — `Agent::setQueueMode()` says which of the two queues it picks and
     * why.
     *
     * Escape closes it. There is no cancel, because each change has already happened by
     * then — the same as upstream, and the same as every other toggle here.
     */
    private function showSettings(): void
    {
        $levels = $this->session->availableThinkingLevels();
        $rows = [
            new SettingItem(
                'theme',
                'Theme',
                $this->theme,
                'Already-drawn output keeps the colours it was drawn with.',
                values: ['dark', 'light'],
            ),
        ];

        // Only when the model can think at all: on a model that cannot, the level is
        // forced off and a row offering six of them would be six ways to change nothing.
        if ($levels !== []) {
            $rows[] = new SettingItem(
                'thinking',
                'Thinking',
                $this->session->thinkingLevel()->value,
                'How hard the model works before it answers. Costs tokens.',
                submenu: fn (string $current, Closure $done): Component
                    => $this->thinkingSubmenu($levels, $current, $done),
            );
        }

        $rows[] = new SettingItem(
            'hideThinking',
            'Thinking blocks',
            $this->hideThinking ? 'hidden' : 'shown',
            'Whether reasoning is drawn in the transcript. ctrl+t does this too.',
            values: ['shown', 'hidden'],
        );
        $rows[] = new SettingItem(
            'showImages',
            'Pictures',
            $this->showImages ? 'drawn' : 'named',
            'Whether a picture in a tool result is drawn, on terminals that can.',
            values: ['drawn', 'named'],
        );
        $rows[] = new SettingItem(
            'queueMode',
            'Queued messages',
            $this->session->queueMode()->value,
            'Whether what you type while it works goes over one at a time or together.',
            values: ['one-at-a-time', 'all'],
        );
        $rows[] = new SettingItem(
            'autoCompact',
            'Auto-compact',
            $this->settings->compactionEnabled() ? 'on' : 'off',
            'Summarise the conversation when the context window is nearly full.',
            values: ['on', 'off'],
        );
        $rows[] = new SettingItem(
            'autoRetry',
            'Auto-retry',
            $this->settings->retryEnabled() ? 'on' : 'off',
            'Wait out a provider that is briefly busy instead of losing the turn.',
            values: ['on', 'off'],
        );

        $list = new SettingsList($rows, 8, $this->palette->settingsListTheme());
        $list->setChangeHandler(function (string $id, string $value) use ($list): void {
            $this->applySetting($id, $value);

            // The theme is the one that changes how this very screen is painted, and the
            // list holds the old palette's closures. Redrawing it from here would mean
            // rebuilding it mid-keystroke, so the new colours arrive the next time it is
            // opened — which is what `/theme` already says about the transcript.
            $list->setValue($id, $value);
        });
        $list->setCloseHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text($this->palette->fg('muted', 'Settings — enter to change, esc when done'), 1, 0));
        $this->overlay->addChild($list);

        $this->tui->setFocus($list);
        $this->tui->requestRender();
    }

    /**
     * The thinking row's submenu: the levels this model offers, with what each one costs.
     *
     * A `SelectList`, which is what a submenu being "any component" is for — six options
     * with an explanation each is a list, not something to press Enter through.
     *
     * @param list<ThinkingLevel>     $levels
     * @param Closure(?string): void  $done   the chosen level, or null for escape
     */
    private function thinkingSubmenu(array $levels, string $current, Closure $done): Component
    {
        $descriptions = [
            'off' => 'No reasoning',
            'minimal' => 'Very brief reasoning (~1k tokens)',
            'low' => 'Light reasoning (~2k tokens)',
            'medium' => 'Moderate reasoning (~8k tokens)',
            'high' => 'Deep reasoning (~16k tokens)',
            'xhigh' => 'Maximum reasoning (~32k tokens)',
        ];

        $items = [];
        $at = 0;

        foreach ($levels as $index => $level) {
            $items[] = new SelectItem($level->value, $level->value, $descriptions[$level->value] ?? null);

            if ($level->value === $current) {
                $at = $index;
            }
        }

        $list = new SelectList($items, count($items), $this->palette->selectListTheme());
        $list->setSelectedIndex($at);
        $list->setSelectHandler(static function (SelectItem $item) use ($done): void {
            $done($item->value);
        });
        $list->setCancelHandler(static function () use ($done): void {
            $done(null);
        });

        return new SettingsSubmenu(
            $list,
            $this->palette->fg('accent', 'Thinking'),
            $this->palette->fg('muted', '  Enter to select · Esc to go back'),
        );
    }

    /** One row of `/settings`, applied. Unknown ids are impossible: this list built them. */
    private function applySetting(string $id, string $value): void
    {
        match ($id) {
            'theme' => $this->useTheme($value),
            'thinking' => $this->useThinkingLevel($value),
            'hideThinking' => $this->useHideThinking($value === 'hidden'),
            'showImages' => $this->useShowImages($value === 'drawn'),
            // Takes effect on the next queued message, which is the only time it is read —
            // nothing about the ones already waiting changes, and nothing should.
            'queueMode' => $this->session->setQueueMode(
                QueueMode::tryFrom($value) ?? QueueMode::OneAtATime,
            ),
            'autoCompact' => $this->settings->setCompactionEnabled($value === 'on'),
            'autoRetry' => $this->settings->setRetryEnabled($value === 'on'),
            default => null,
        };
    }

    private function useThinkingLevel(string $value): void
    {
        $level = ThinkingLevel::tryFrom($value);

        if ($level === null) {
            return;
        }

        $this->session->setThinkingLevel($level);
        $this->settings->setDefaultThinkingLevel($level);
        $this->footer->invalidate();
    }

    /** Split out of `switchTheme()` so `/settings` can name a theme rather than toggle. */
    private function useTheme(string $wanted): void
    {
        $this->theme = $wanted;
        $this->palette = Palette::named($wanted);
        $this->settings->setTheme($wanted);

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

            // The session's own, from between one run and the next. See `AgentEvent`.
            $event instanceof RetryStartEvent => $this->onRetryStart($event),
            $event instanceof RetryEndEvent => $this->onRetryEnd($event),
            $event instanceof AutoCompactionStartEvent => $this->onOverflow(),
            $event instanceof AutoCompactionEndEvent => $this->onOverflowHandled($event),

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
        if ($event->message instanceof HookMessage) {
            $this->showHookMessage($event->message);

            return;
        }

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

    // ---- picking a failed turn back up --------------------------------------------------

    /**
     * The provider said no, and the session is waiting before asking again.
     *
     * A loader rather than a line in the transcript, and on purpose: this is a thing that is
     * happening, not a thing that happened, and it has to say escape works — eight seconds
     * that look like a hang are eight seconds someone spends deciding whether to kill pig.
     * The reason is said as well as the countdown, because "503" and "rate limited" call for
     * different amounts of patience.
     */
    private function onRetryStart(RetryStartEvent $event): void
    {
        $this->working?->stop();
        $this->status->clear();

        $seconds = rtrim(rtrim(number_format($event->delaySeconds, 1), '0'), '.');

        $this->working = new Loader(
            $this->tui,
            $this->palette->of('accent'),
            $this->palette->of('muted'),
            "{$event->error} — trying again in {$seconds}s"
                . " ({$event->attempt}/{$event->maxAttempts}, esc to stop)",
        );

        $this->status->addChild($this->working);
    }

    private function onRetryEnd(RetryEndEvent $event): void
    {
        $this->working?->stop();
        $this->working = null;
        $this->status->clear();

        if ($event->succeeded) {
            // Nothing said: the answer it retried for is already on screen above this, and
            // "it worked" about something that never visibly failed is noise.
            return;
        }

        $this->sayError($event->error ?? 'Giving up after ' . $event->attempts . ' attempts.');
    }

    private function onOverflow(): void
    {
        $this->working?->stop();
        $this->status->clear();

        $this->working = new Loader(
            $this->tui,
            $this->palette->of('accent'),
            $this->palette->of('muted'),
            'Context is full — summarising, then trying again. (esc to cancel)',
        );

        $this->status->addChild($this->working);
    }

    private function onOverflowHandled(AutoCompactionEndEvent $event): void
    {
        $this->working?->stop();
        $this->working = null;
        $this->status->clear();

        if ($event->summary !== null) {
            $this->chat->addChild(new CompactionComponent($event->summary, $this->palette, $this->expanded));

            return;
        }

        $this->sayError($event->error ?? 'Could not summarise, so the turn could not be sent again.');
    }

    /**
     * Draw a hook's message — its own way if it registered one, otherwise the ordinary way.
     *
     * A message with `display: false` is not drawn at all. That is the point of the flag: a
     * hook that puts a reminder in front of every turn is talking to the model, and a person
     * who has to scroll past it every time will stop reading the screen.
     *
     * A renderer that throws or hands back something that is not a component falls back to
     * the default **and says so**, the same as a custom tool's does and for the same reason:
     * a picture that quietly turns into plain text is a bug nobody reports.
     */
    private function showHookMessage(HookMessage $message): void
    {
        if (!$message->display) {
            return;
        }

        $renderer = $this->messageRenderers[$message->customType] ?? null;

        if ($renderer !== null) {
            try {
                $drawn = $renderer($message, new RenderOptions($this->expanded), $this->palette);
            } catch (Throwable $problem) {
                $this->sayWarning("The renderer for '{$message->customType}' failed: {$problem->getMessage()}");
                $drawn = null;
            }

            if ($drawn instanceof Component) {
                $this->chat->addChild($drawn);

                return;
            }

            // Null is "draw it normally" and is not a complaint. Anything else is.
            if ($drawn !== null) {
                $this->sayWarning(
                    "The renderer for '{$message->customType}' returned " . get_debug_type($drawn) . ', not a component.',
                );
            }
        }

        $this->chat->addChild(new HookMessageComponent($message, $this->palette));
    }

    /** @param array<string, mixed> $arguments */
    private function addTool(string $id, string $name, array $arguments): ToolExecutionComponent
    {
        // A custom tool may draw its own call and result, so the declaration is looked up
        // rather than the name being enough. Null for every built-in, which is all of them
        // unless something was loaded.
        $tool = new ToolExecutionComponent(
            $name,
            $arguments,
            $this->palette,
            $this->customTools?->find($name),
            function (string $problem) use ($name): void {
                $this->sayWarning("tool {$name}: {$problem}");
            },
            showImages: $this->showImages,
        );
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
     * line in a transcript that is already full of colour is not a signal. Every error
     * this mode shows goes through here, so none of them has to be recognised by its
     * colour alone.
     */
    private function sayError(string $message): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Text($this->palette->fg('error', "Error: {$message}"), 1, 0));
        $this->tui->requestRender();
    }

    /**
     * A hook that failed, in the transcript.
     *
     * A warning and not an error, because the turn carried on: the one failure that does
     * stop something is a `tool_call` hook, and that one reaches the model as a blocked
     * tool and is drawn as the tool's own error.
     */
    private function sayHookError(HookError $error): void
    {
        $this->sayWarning('hook ' . $error->toText());
    }

    /**
     * Custom tools that failed on a session event, in the transcript.
     *
     * Collected and shown rather than listened for: a tool's session callbacks are called
     * from here, one call at a time, so there is nothing to subscribe to and nowhere else
     * the complaint could come from.
     *
     * @param list<ToolProblem> $problems
     */
    private function sayToolProblems(array $problems): void
    {
        foreach ($problems as $problem) {
            $this->sayWarning('tool ' . $problem->toText());
        }
    }

    /** Not an error, but not what was asked for either — upstream's `showWarning()`. */
    private function sayWarning(string $message): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Text($this->palette->fg('warning', "Warning: {$message}"), 1, 0));
        $this->tui->requestRender();
    }

    /**
     * The words in a message, whoever said them.
     *
     * Any message with `content` on it: an assistant's thinking and tool calls are in
     * there too, and neither is what a transcript line or a list entry is showing.
     */
    private static function textOf(mixed $message): string
    {
        $text = '';

        foreach ($message->content ?? [] as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
