<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentTool;
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
use Pig\CodingAgent\Logger;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\BugReport;
use Pig\CodingAgent\CrashLog;
use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\TrustChoice;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Changelog;
use Pig\CodingAgent\Cli\UpdateCheck;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Cli\SessionList;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Export\MarkdownExport;
use Pig\CodingAgent\CustomTools\CustomToolApi;
use Pig\CodingAgent\CustomTools\CustomToolLoader;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\CustomTools\ToolProblem;
use Pig\CodingAgent\Extensions\ExtensionDiscovery;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookLoader;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\RegisteredCommand;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\ContextFiles;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Tui\Env;
use Pig\CodingAgent\Session\SessionCodec;
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
use Pig\Tui\Autocomplete\AutocompleteItem;
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
use Pig\Tui\Width;
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

    private ?string $commandHudTimer = null;

    private int $commandPressedMs = 0;

    private bool $commandHudOpen = false;

    /** Injected so a test can see what a copy would have put there. */
    private Clipboard $clipboard;

    /** @var array<string, Closure> what a hook draws its own messages with, by custom type */
    private array $messageRenderers = [];

    /** @var list<LoadedExtension> */
    private array $extensions = [];

    /** @var list<string> */
    private array $builtInTools = [];

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
        array $extensions = [],
        // What `ProjectTrust::resolve()` answered at startup, so the screen can say when the
        // project's `.pig/` was left out and `/trust` can show which way the saved answer goes.
        private readonly bool $projectTrusted = true,
        // Which key means which action — `keybindings.json`'s answer, or the defaults.
        private readonly Keybindings $keybindings = new Keybindings(),
    ) {
        $this->theme = $theme;
        $this->contextFiles = $contextFiles;
        $this->skills = $skills;
        $this->fileCommands = $fileCommands;
        $this->hooks = $hooks;
        $this->customTools = $customTools;
        $this->extensions = $extensions;
        $this->builtInTools = ToolSet::CODING;
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
        $this->editor = new CustomEditor(new Editor($palette->editorTheme()), $this->keybindings);
        // `$this->settings` rather than the argument: with none given it is the in-memory one
        // that `/settings` writes to, and the footer has to read what that screen changes.
        $this->footer = new FooterComponent($session, $palette, $cwd, $this->settings, $auth);

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
            $this->keybindings,
        );

        $hooks?->initialize(
            getModel: static fn () => $session->model(),
            isIdle: static fn (): bool => !$session->isStreaming(),
            abort: static function () use ($session): void {
                $session->abort();
            },
            hasQueuedMessages: static fn (): bool => $session->queued() !== [],
            signal: static fn () => $session->signal(),
            ui: $this->ui,
            send: static function (HookMessage $message, bool $triggerTurn) use ($session): void {
                $session->sendHookMessage($message, $triggerTurn);
            },
            note: static function (string $customType, mixed $data) use ($session): void {
                $session->appendHookEntry($customType, $data);
            },
            getApiKey: static fn (Model $m) => $session->keyFor($m),
            setSessionName: static fn (string $name) => $session->setSessionName($name),
            getSessionName: static fn () => $session->getSessionName(),
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
        // Suppress raw console log outputs in TUI to prevent screen corruption; logs continue writing to file
        Logger::setConsoleOutput(false);

        $this->layout();
        $this->bindKeys();
        $this->bindEditor();
        $this->reportLoopFailures();
        $this->watchCommandKey();
        $this->session->onSessionNameChanged(function (): void {
            $this->tui->requestRender();
        });
        $this->session->subscribe($this->onEvent(...));

        $this->replay();

        // After the replay, so an upgrade note sits under the conversation it is about rather
        // than above it. `bin/pig` is what decides there is one: it knows the version, holds the
        // settings the last-seen number is written to, and knows whether this is a resumed
        // session — where a release note nobody asked for is an interruption.
        if ($this->changelog !== null && $this->changelog !== '') {
            $this->sayChangelog($this->changelog);
        }

        // Upstream's `renderProjectTrustWarningIfNeeded()`: hooks that are not running have to
        // say so on the screen, or a guard somebody wrote reads as broken rather than off.
        if (!$this->projectTrusted && ProjectTrust::hasResources($this->cwd)) {
            $this->sayWarning(ProjectTrust::warning());
        }

        // Upstream's startup notice: the last time pig fell over, said once, with the way to
        // report it. A week old is too old to greet somebody with.
        $crash = CrashLog::takeUnnotified();

        if ($crash !== null) {
            $this->sayWarning(CrashLog::notice($crash));
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

    /** The command to resume this session, if it was persisted to disk. */
    public function resumeCommand(): ?string
    {
        return $this->session->resumeCommand();
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        Logger::setConsoleOutput(true);
        // Stopping the web server winds its children down and suspends while it does, and this
        // runs from a key handler — inside the loop's callback — so it goes in a fiber of its own.
        if ($this->webServer !== null) {
            $server = $this->webServer;
            $this->webServer = null;
            Async::spawn(static fn () => $server->stop());
        }
        $this->working?->stop();
        if ($this->commandHudTimer !== null) {
            Loop::get()->cancel($this->commandHudTimer);
            $this->commandHudTimer = null;
        }
        $this->closeCommandHud();
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

    /**
     * A throw out of a loop callback becomes a line in the transcript instead of the end.
     *
     * `Loop` rethrows by default, and this is the caller that says otherwise — the one place in
     * pig with somewhere to report to. What it buys is the five entries in the traps that all
     * begin the same way: a render that threw took the **whole session** with it, because
     * `render()` runs inside the loop's own callback and there was nothing above it to catch.
     * Each of those was fixed at its source; this is the net under the next one.
     *
     * **Deduplicated by message, and that is not a nicety.** A `render()` that throws throws
     * again on the next tick, so without this the transcript fills with one line per frame and
     * the thing it is trying to tell you scrolls away — the same reason
     * `ToolExecutionComponent`'s renderer fallback reports once per call rather than per draw.
     *
     * The session stays up, which is the whole point and also the risk: a component that cannot
     * draw will keep not drawing, and the person sees a red line rather than a crash. That is
     * the trade the developer chose, and it is the one workerman makes.
     */
    private function reportLoopFailures(): void
    {
        $said = [];

        Loop::get()->setErrorHandler(function (Throwable $error) use (&$said): void {
            $message = $error->getMessage();

            if (isset($said[$message])) {
                return;
            }

            $said[$message] = true;
            $this->sayError($message);
            Logger::error("Loop error: {$message}", ['trace' => $error->getTraceAsString()]);

            // Survived, drawn, and written down: a throw the loop had to catch is a pig bug by
            // definition, and `/bug` attaches these. Once per message, like the line above.
            CrashLog::record('loop_error', $error, $this->session->store()?->path, $this->cwd);
        });
    }

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
     * The three lines at the top, followed by compact loaded sections,
     * and the full list behind ctrl+o.
     *
     * One line of keys rather than a column of thirteen, which is what upstream settled
     * on: the list was taller than most of the conversations it sat above. The rest is
     * still there, one key away, for the session where someone needs it.
     */
    private function banner(): string
    {
        [$topLogo, $bottomLogo] = PigLogo::lines();

        $versionStr = Style::bold($this->palette->fg('accent', 'pig')) . $this->palette->fg('dim', " v{$this->version}");
        $summaryStr = $this->palette->fg('muted', implode(' · ', self::SUMMARY));

        $lines = [
            "{$topLogo} {$versionStr}",
            "{$bottomLogo}",
            $summaryStr,
        ];

        $onboarding = $this->palette->fg('dim', 'Pig can explain its own features and look up its docs. Ask it how to use or extend Pig.');

        if (!$this->expanded) {
            $lines[] = $this->palette->fg('dim', 'Press ctrl+o to show full startup help and loaded resources.');
            $lines[] = '';
            $lines[] = $onboarding;

            $loaded = $this->loaded(compact: true);
            if ($loaded !== '') {
                $lines[] = '';
                $lines[] = $loaded;
            }

            return implode("\n", $lines);
        }

        $loaded = $this->loaded(compact: false);

        return implode("\n", [
            ...$lines,
            '',
            $this->keysAndCommands(),
            '',
            $onboarding,
            ...($loaded === '' ? [] : ['', $loaded]),
        ]);
    }

    /**
     * What was loaded into this session, as sections.
     *
     * In compact mode (collapsed banner), displays compact lists under [Context], [Skills],
     * and [Extensions]. In expanded mode (ctrl+o), displays full scope or detail.
     */
    private function loaded(bool $compact = false): string
    {
        $sections = [];

        if ($this->contextFiles !== []) {
            $names = array_map(
                fn (ContextFile $file): string => $compact ? basename($file->path) : $this->formatDisplayPath($file->path),
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

        $extensions = $this->discoveredExtensions();
        if ($extensions !== []) {
            $sections[] = $this->palette->fg('mdHeading', '[Extensions]') . "\n"
                . $this->palette->fg('muted', '  ' . implode(', ', $extensions));
        }

        if ($this->customTools !== null && !$this->customTools->isEmpty()) {
            $sections[] = $this->palette->fg('mdHeading', '[Tools]') . "\n"
                . $this->palette->fg('muted', '  ' . implode(', ', $this->customTools->names()));
        }

        return implode("\n\n", $sections);
    }

    /**
     * Labels of loaded hooks and extensions from standard locations.
     *
     * @return list<string>
     */
    private function discoveredExtensions(): array
    {
        $extraPaths = [];
        if ($this->hooks !== null && !$this->hooks->isEmpty()) {
            $extraPaths = $this->hooks->paths();
        }

        foreach ($this->extensions as $ext) {
            $extraPaths[] = is_string($ext) ? $ext : $ext->path;
        }

        return ExtensionDiscovery::discover($this->cwd, $extraPaths);
    }

    /**
     * Format an absolute path with leading ~ for the user's home directory.
     */
    private function formatDisplayPath(string $path): string
    {
        $home = Env::home();
        if ($home !== null && $home !== '' && str_starts_with($path, $home)) {
            return '~' . substr($path, strlen($home));
        }

        return $path;
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
    /**
     * The keys the application binds, by action where `keybindings.json` can move them and by
     * key where it cannot (ctrl+v and shift+ctrl+d belong to the terminal layer, `/` `@` `!` to
     * the prompt). `keys()` turns this into the table the banner and `/help` print, with the
     * key each action is *actually* on — a help screen that names ctrl+o for a key somebody
     * moved to ctrl+e is a help screen that lies.
     */
    private const array KEYS = [
        'app.interrupt' => 'interrupt the agent',
        'app.clear' => 'clear the prompt, twice to exit',
        'app.exit' => 'exit from an empty prompt',
        'app.suspend' => 'suspend',
        'ctrl+v' => 'paste, including an image from the clipboard',
        'app.editor.external' => 'edit the prompt in $VISUAL or $EDITOR',
        'app.message.followUp' => 'while the agent works: queue for after the turn (enter steers)',
        'app.message.dequeue' => 'take the queued messages back into the prompt',
        'app.thinking.cycle' => 'cycle the thinking level',
        'shift+ctrl+d' => 'write a debug log: this frame, its line widths, the conversation',
        'app.model.cycleForward' => 'next model',
        'app.model.cycleBackward' => 'previous model',
        'app.model.select' => 'choose a model from the list',
        'app.tools.expand' => 'show more: tool output, and this list',
        'app.thinking.toggle' => 'show or hide thinking',
        '/' => 'commands',
        '@' => 'files',
        '!' => 'run a command, and let the model see the output',
        '!!' => 'run a command and keep it out of the conversation',
    ];

    /** @return array<string, string> key label => what it does, with the bindings applied */
    private function keys(): array
    {
        $rows = [];

        foreach (self::KEYS as $what => $does) {
            $label = str_starts_with($what, 'app.') ? $this->keybindings->label($what) : $what;
            $rows[$label] = $does;
        }

        return $rows;
    }

    /**
     * What the prompt itself answers to, which is upstream's `/hotkeys` table.
     *
     * **Every one of these worked before it was written down, and that is why it is here.**
     * `Editor` has answered to all of them since it was ported; `KEYS` above holds the keys the
     * *application* binds, so the editing ones were listed in neither place — a person had ctrl+w,
     * ctrl+u, ctrl+k, word movement and the prompt history and no way to find out. `/help` says it
     * shows "the keys and commands", and a claim a screen does not keep is the thing this
     * repository treats as a defect rather than as a missing nicety.
     *
     * One table rather than upstream's second command, because pig already prints the keys in two
     * places — `/help` and ctrl+o — and a third list of keys is a third thing to keep in step.
     * Kept by hand, as upstream's is: when `Editor::editingKey()` grows an arm, this is where it
     * gets its name, and `testEveryKeyTheEditorAnswersToIsNamedSomewhere` is what notices.
     */
    private const array EDITING_KEYS = [
        'enter' => 'send',
        'shift+enter' => 'a new line',
        'up' => 'the previous thing you said, from an empty prompt',
        'tab' => 'complete a path, or take what is offered',
        'ctrl+a' => 'start of the line, and home',
        'ctrl+e' => 'end of the line, and end',
        'alt+left' => 'back a word, and ctrl+left',
        'alt+right' => 'on a word, and ctrl+right',
        'ctrl+w' => 'delete the word behind, and alt+backspace',
        'ctrl+u' => 'delete back to the start of the line',
        'ctrl+k' => 'delete on to the end of the line',
    ];

    // ---- keys ---------------------------------------------------------------------------

    private function bindKeys(): void
    {
        // `Tui` has intercepted shift+ctrl+d since it was ported — the predicate, the field and the
        // setter were all there — and nothing ever called the setter, so the one key that works
        // whatever holds the focus did nothing. The same shape as ctrl+p: machinery wired at one
        // end. Not on the editor, because the point of it is that the editor may not be listening.
        $this->tui->setDebugHandler($this->writeDebugLog(...));

        // Bound by *action*, upstream's names: which key each one is on is `Keybindings`'
        // business, and the editor claims whatever keys the bindings say — so a `keybindings.json`
        // that moves ctrl+o to ctrl+e frees ctrl+o for the text field in the same move.
        $this->editor->on('app.interrupt', $this->interrupt(...));
        $this->editor->on('app.clear', $this->onCtrlC(...));
        $this->editor->on('app.exit', $this->stop(...));
        $this->editor->on('app.suspend', $this->suspend(...));
        $this->editor->on('app.thinking.cycle', $this->cycleThinking(...));

        // `CustomEditor` has always claimed these two — taken them off the text field — and
        // nothing was listening: upstream binds them to cycling the model, and pig had the keys
        // reserved for a method it never ported. A key that is taken away and then does nothing
        // is worse than either.
        $this->editor->on('app.model.cycleForward', fn () => $this->cycleModel());
        $this->editor->on('app.model.cycleBackward', fn () => $this->cycleModel(backward: true));

        // And ctrl+l, which was the third of them: claimed since it was ported, bound to nothing,
        // and the one key in the set a terminal already has a meaning for — so pressing it out of
        // habit to clear the screen did not even do that. Upstream's `onCtrlL` opens its model
        // selector, which is `/model` with nothing after it here.
        $this->editor->on('app.model.select', fn () => $this->showModels(''));
        $this->editor->on('app.message.followUp', $this->followUp(...));
        $this->editor->on('app.message.dequeue', $this->dequeue(...));
        $this->editor->on('app.editor.external', function (): void {
            // In a fiber of its own, the same reason `send()` is: this runs inside the
            // input callback, and it suspends — which would suspend the loop that called it.
            Async::spawn($this->editPromptExternally(...));
        });

        $this->editor->on('app.tools.expand', function (): void {
            $this->expanded = !$this->expanded;
            $this->banner?->setText($this->banner());

            foreach ($this->chat->children() as $child) {
                if ($child instanceof ToolExecutionComponent
                    || $child instanceof CompactionComponent
                    || $child instanceof BranchSummaryComponent
                    || $child instanceof HookMessageComponent
                ) {
                    $child->setExpanded($this->expanded);
                }
            }

            $this->tui->requestRender();
        });

        $this->editor->on('app.thinking.toggle', function (): void {
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

        // The session's and not the agent's, which is what this was and what made two labels on
        // this screen promise something the key did not do: a retry counting down says "esc to
        // stop" and a summariser says "esc to cancel", and both of them happen *between* runs,
        // where `$this->agent` has no controller to raise. `AgentSession::abort()` reaches all
        // three. The future is not awaited on purpose — this runs inside the loop's own input
        // callback, and waiting here would suspend the loop that has to deliver the next key.
        $this->session->abort();
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

    /** ctrl+p, and shift+ctrl+p the other way: the next model along, without opening the list. */
    private function cycleModel(bool $backward = false): void
    {
        try {
            $choice = $this->session->cycleModel($this->modelsWithAKey(), $backward);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        if ($choice === null) {
            // Which is a real state on a machine with one key, and a different one under
            // `--models`: a keystroke that does nothing needs to say why, or it reads as pig
            // having missed it.
            $this->say($this->session->modelScope() === []
                ? 'Only one model has a key here.'
                : 'Only one model in the scope --models set.');

            return;
        }

        $this->footer->invalidate();
        $this->paintBorder();

        // The level is said too, because switching models can change it under you — a scope
        // entry may name its own — and finding that out from a bill is worse than reading it here.
        $this->say($choice->thinking === ThinkingLevel::Off
            ? "Model: {$choice->model->id}"
            : "Model: {$choice->model->id} · thinking {$choice->thinking->value}");
    }

    /**
     * The models this session offers, which `--models` may have narrowed.
     *
     * This half is the terminal's: which models a key reaches, from `Auth`. The narrowing is the
     * session's, so a scope cannot be applied here and forgotten there.
     *
     * @return list<Model>
     */
    private function modelsWithAKey(): array
    {
        return $this->session->modelsOnOffer($this->auth?->availableModels() ?? Models::all());
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
        $this->editor->setClipboard($this->clipboard);

        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider(
            [
                ...array_map(
                    fn (array $command): SlashCommand => new SlashCommand(
                        $command[0],
                        $command[1],
                        // The built-ins whose argument is a known list rather than free text.
                        match ($command[0]) {
                            'model' => $this->modelCompletions(...),
                            'thinking' => $this->thinkingCompletions(...),
                            'theme' => $this->themeCompletions(...),
                            default => null,
                        },
                    ),
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
            $this->modifiedGitFiles(...),
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
            $this->closeCommandHud();
            $this->commandPressedMs = 0;
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

            // Typed while the agent is working: Enter **steers** — delivered after the tool that
            // is running now, which is upstream's `streamingBehavior: "steer"` and what somebody
            // means by typing "no, the other file" mid-run. Alt+Enter is the follow-up, below.
            if ($this->session->isStreaming()) {
                $this->session->steer($text);
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
     * Alt+Enter: queue what was typed for **after** the turn — upstream's `handleFollowUp()`.
     *
     * The other half of typing while the agent works. Enter steers, which cuts in after the
     * current tool; this waits until the whole turn is over, for the thing that is not a
     * correction but the next request. From an idle prompt it is Enter, as upstream has it,
     * because there is nothing to wait behind.
     */
    private function followUp(): void
    {
        $this->closeCommandHud();
        $this->commandPressedMs = 0;
        $text = trim($this->editor->text());

        if ($text === '') {
            return;
        }

        // Idle, or a `!` command or a built-in, which run now whichever key was pressed: the key
        // is Enter. Handed to the editor as the Enter byte rather than through a second door,
        // so the paste markers and the submit guard are the same ones Enter goes through.
        if (!$this->session->isStreaming() || str_starts_with($text, '!') || $this->commandName($text) !== null) {
            $this->editor->handleInput("\r");

            return;
        }

        $this->editor->addToHistory($text);
        $this->session->followUp($text);
        $this->editor->setText('');
        $this->showQueue();
        $this->tui->requestRender();
    }

    /**
     * Alt+Up: take everything queued back into the editor — upstream's `handleDequeue()`.
     *
     * Escape does the same on its way to stopping the turn; this is for changing your mind
     * about what was queued without stopping anything. The turn carries on and the editor
     * holds the queued text in front of whatever was already being typed.
     */
    private function dequeue(): void
    {
        $queued = $this->session->clearQueue();
        $this->showQueue();

        if ($queued === []) {
            $this->say('No queued messages to restore');

            return;
        }

        $text = implode("\n\n", array_filter([...$queued, $this->editor->text()], static fn (string $t): bool => trim($t) !== ''));
        $this->editor->setText($text);

        $count = count($queued);
        $this->say("Restored {$count} queued message" . ($count > 1 ? 's' : '') . ' to editor');
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

                // The details are what `drawBash()` reads its status line out of: the exit code,
                // whether escape stopped it, and where the whole output went when it was cut. All
                // three are on the execution already and none of them reached the screen —
                // `BashExecution::toText()` was telling the model and nothing was telling the
                // person. `truncation` and `fullOutputPath` are pi's own key names, the same two
                // the `bash` tool writes.
                $shown->updateResult(
                    new AgentToolResult(
                        [new TextContent($execution->output)],
                        details: [
                            'exitCode' => $execution->exitCode,
                            'cancelled' => $execution->cancelled,
                            'truncation' => $execution->truncated ? true : null,
                            'fullOutputPath' => $execution->spillPath,
                        ],
                    ),
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
    private function borderColour(): Closure
    {
        return $this->bashMode
            ? $this->palette->of('bashMode')
            : $this->palette->thinkingBorder($this->session->thinkingLevel());
    }

    private function paintBorder(): void
    {
        $this->editor->setTheme(new EditorTheme($this->borderColour(), $this->palette->selectListTheme()));
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

        $this->showLoader('Summarising the conversation... (esc to cancel)');

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
            $this->hideLoader();
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
        ['hotkeys', 'Show all keyboard shortcuts'],
        ['new', 'Forget the conversation and start over'],
        ['session', 'What this session has cost'],
        ['model', 'Switch models, or say which one'],
        ['thinking', 'Set thinking level, or choose from a list'],
        ['skills', 'What the model can reach for, and where it came from'],
        ['copy', 'Put the last answer on the clipboard'],
        ['export', 'Export conversation as HTML, Markdown, or PR description (/export [md|pr])'],
        ['compact', 'Summarise the conversation so far and carry on from the summary'],
        ['resume', 'Pick up an earlier conversation'],
        ['tree', 'Go back to an earlier point and take it somewhere else'],
        ['label', 'Name this point, so /tree can find it again — /label with nothing clears it'],
        ['name', 'Show or set the session name'],
        ['diff', 'Show git working tree changes (/diff [--staged])'],
        ['commit', 'Review changes and commit with Conventional Commits message'],
        ['web', 'Browser UI server & daemon (/web [start|stop|status|restart] [port])'],
        ['login', 'Sign in with a subscription or an account instead of an API key'],
        ['logout', 'Forget a sign-in'],
        ['theme', 'Switch themes (dark, light, labra, or custom) (/theme [name])'],
        ['settings', 'Change what is switchable, and see what it is set to'],
        ['changelog', 'What changed, release by release'],
        ['reload', 'Reload extensions, skills, commands, tools, and context files'],
        ['hooks', 'What hooks loaded, and what they added'],
        ['tools', 'What the model can call, built-in and loaded'],
        ['doctor', 'Inspect system health, PHP runtime, binaries, and auth tokens'],
        ['bug', 'Write a bug report and open a GitHub issue for it (/bug [what went wrong])'],
        ['trust', 'Decide whether this project\'s .pig/ hooks, tools and extensions may load'],
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
        // `debug` is a third: it is for reporting a fault rather than for using pig, and
        // shift+ctrl+d is the route worth advertising — it works whatever holds the focus, which
        // is the whole point of a key that captures the screen.
        if ($name === 'quit' || $name === 'hotkeys' || $name === 'arminsayshi' || $name === 'debug') {
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
            'help', 'hotkeys' => $this->say($this->commandHelp()),
            'new' => $this->newSession(),
            'session' => $this->say($this->sessionSummary()),
            'compact' => $this->startCompaction(trim(substr($text, strlen($name) + 1))),
            'model' => $this->showModels(trim(substr($text, strlen($name) + 1))),
            'thinking' => $this->handleThinkingCommand(trim(substr($text, strlen($name) + 1))),
            'skills' => $this->say($this->skillList()),
            'copy' => $this->copyLastAnswer(),
            'export' => $this->exportSession(trim(substr($text, strlen($name) + 1))),
            'resume' => $this->showSessions(),
            'tree' => $this->showTree(),
            'label' => $this->label(trim(substr($text, strlen($name) + 1))),
            'name' => $this->handleNameCommand(trim(substr($text, strlen($name) + 1))),
            'diff' => $this->handleDiffCommand(trim(substr($text, strlen($name) + 1))),
            'commit' => $this->handleCommitCommand(trim(substr($text, strlen($name) + 1))),
            'web' => $this->handleWebCommand(trim(substr($text, strlen($name) + 1))),
            'login' => $this->showSignIns('login'),
            'logout' => $this->showSignIns('logout'),
            'theme' => $this->handleThemeCommand(trim(substr($text, strlen($name) + 1))),
            'settings' => $this->showSettings(),
            'changelog' => $this->showChangelog(),
            'reload' => $this->reload(),
            'arminsayshi' => $this->sayHi(),
            'debug' => $this->writeDebugLog(),
            'hooks' => $this->say($this->hookList()),
            'tools' => $this->say($this->toolList()),
            'doctor' => $this->say($this->doctorReport()),
            'bug' => $this->reportBug(trim(substr($text, strlen($name) + 1))),
            'trust' => $this->showTrust(),
            'exit', 'quit' => $this->stop(),
            // A hook's or a file's, which is all that is left: `commandName()` only hands
            // over names something answers to, and the built-ins are above, so a built-in
            // can never be shadowed by something someone forgot they wrote.
            default => $this->runAddedCommand($text, $name),
        };
    }

    /**
     * A command a hook registered, or a prompt kept as a file.
     *
     * Both are the session's to carry out — `AgentSession::prompt()` runs the one and expands
     * the other — because a command that only worked in front of a screen is a command
     * `pig -p` and RPC could not reach. What is left here is which door the text goes in by,
     * and that is a question about this screen:
     *
     * - **A hook's command is code and runs now**, whatever the agent is doing. Spawned,
     *   because a handler may open a dialog and the submit handler is inside the loop's own
     *   input callback.
     * - **A file command is a stored prompt** and takes the ordinary path: steered while the
     *   agent is working, as Enter steers any other text, sent otherwise.
     */
    private function runAddedCommand(string $text, string $name): void
    {
        if (isset($this->hookCommands[$name])) {
            Async::spawn(function () use ($text): void {
                $this->session->prompt($text);
                $this->tui->requestRender();
            });

            return;
        }

        if ($this->session->isStreaming()) {
            $this->session->steer($text);
            $this->showQueue();
            $this->tui->requestRender();

            return;
        }

        // Not drawn here: `onMessageStart` draws every user message, and drawing it twice is
        // what happens to anything that helpfully draws its own.
        $this->send($text);
    }

    /** What hooks loaded, where from, and what each one is listening for. */
    private function hookList(): string
    {
        if ($this->hooks === null || $this->hooks->isEmpty()) {
            return 'No hooks loaded. A .php file in ~/.pig/agent/hooks or .pig/hooks that returns a callable is one.';
        }

        $lines = [];

        foreach ($this->hooks->paths() as $path) {
            $lines[] = $this->palette->fg('muted', $path);
        }

        if ($this->hookCommands !== []) {
            $lines[] = '';

            foreach ($this->hookCommands as $name => $command) {
                $lines[] = $this->palette->fg('dim', Width::pad('/' . $name, 12))
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

            $lines[] = $this->palette->fg('dim', Width::pad($name, 14))
                . $this->palette->fg('muted', $tool->label() . ' · ' . ($custom[$name] ?? 'built-in'));
        }

        return $lines === [] ? 'This session has no tools at all.' : implode("\n", $lines);
    }

    private function doctorReport(): string
    {
        $report = Doctor::inspect($this->session, $this->auth);

        return Doctor::renderTui($report, $this->palette);
    }

    /**
     * `/trust`: upstream's `showTrustSelector()`. Saves a decision for next time; the one in
     * force now stays, because what was already `require`d cannot be un-required and what was
     * left out cannot be loaded half way through a session — so the answer says to restart.
     */
    private function showTrust(): void
    {
        $saved = ProjectTrust::entry($this->cwd);
        $choices = array_values(array_filter(
            ProjectTrust::choices($this->cwd),
            // "This session only" has nothing to say mid-session: this session is already decided.
            static fn (TrustChoice $choice): bool => $choice->updates !== [],
        ));

        $items = [];

        foreach ($choices as $index => $choice) {
            $items[] = new SelectItem((string) $index, $choice->label);
        }

        $picker = new SelectList($items, count($items), $this->palette->selectListTheme());
        $picker->setSelectHandler(function (SelectItem $item) use ($choices): void {
            $this->closePicker();
            $choice = $choices[(int) $item->value];

            try {
                ProjectTrust::remember($choice->updates);
            } catch (Throwable $error) {
                $this->sayError('Could not save the trust decision: ' . $error->getMessage());

                return;
            }

            $this->say('Saved trust decision: ' . ($choice->trusted ? 'trusted' : 'untrusted') . '. Restart pig for this to take effect.');
        });
        $picker->setCancelHandler($this->closePicker(...));

        $now = $this->projectTrusted ? 'trusted' : 'not trusted';
        $was = $saved === null
            ? 'no saved decision'
            : 'saved: ' . ($saved['decision'] ? 'trusted' : 'untrusted') . " at {$saved['path']}";

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text($this->palette->fg('muted', "Trust {$this->cwd}? — this session: {$now}, {$was}"), 1, 0));
        $this->overlay->addChild($picker);

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /** Shown once per session, after an error that is neither retryable nor an abort. */
    private bool $bugHintShown = false;

    private function suggestBugReport(): void
    {
        if ($this->bugHintShown) {
            return;
        }

        $this->bugHintShown = true;
        $this->say('If this looks like a pig bug, /bug writes a report and opens a GitHub issue for it.');
    }

    /**
     * `/bug [hint]`: ask whether to attach the transcript, write the report, copy it, open GitHub.
     *
     * Spawned because the confirm parks the fiber it is asked on, and this runs inside the
     * loop's own input callback.
     */
    private function reportBug(string $hint): void
    {
        Async::spawn(function () use ($hint): void {
            $includeTranscript = $this->ui->confirm(
                'Include the session transcript?',
                'It holds your messages, the model\'s output, and every file and command result read this session.',
            );

            try {
                $report = BugReport::build($this->session, $this->auth, $hint, $includeTranscript);
                $path = BugReport::write($report);
            } catch (Throwable $error) {
                $this->sayError('Could not write the bug report: ' . $error->getMessage());

                return;
            }

            $this->clipboard->write($report);

            $serverUrl = BugReport::upload($hint, $report);
            $url = BugReport::issueUrl($hint, $report);

            $msg = $this->palette->fg('accent', '✓ Bug report written to ') . $path . "\n"
                . $this->palette->fg('dim', 'It is on the clipboard too.');

            if ($serverUrl !== null) {
                $msg .= "\n" . $this->palette->fg('accent', '✓ Uploaded to server: ') . $serverUrl;
            }

            $msg .= "\n" . $this->palette->fg('dim', 'GitHub issue URL (prefilled): ') . $this->palette->fg('accent', $url);
            $this->say($msg);

            $this->tui->requestRender();
        });
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
     * Write the conversation out as HTML, Markdown, or GitHub PR description.
     *
     * @param string $path from `/export [md|pr] [somewhere]`; beside the session when empty
     */
    private function exportSession(string $path): void
    {
        $path = trim($path);

        // 1. Export as GitHub PR description (/export pr [targetPath])
        if ($path === 'pr' || str_starts_with($path, 'pr ')) {
            $targetPath = trim(substr($path, 2));
            Async::spawn(function () use ($targetPath): void {
                $this->exportPrDescription($targetPath);
            });

            return;
        }

        $store = $this->session->store();

        if ($store === null) {
            $this->sayError('This session is not being saved, so there is nothing to export.');

            return;
        }

        // 2. Export as Markdown (/export md [targetPath])
        if ($path === 'md' || $path === 'markdown' || str_starts_with($path, 'md ') || str_starts_with($path, 'markdown ')) {
            $cleanArg = trim((string) preg_replace('/^(md|markdown)\s*/i', '', $path));
            $target = $cleanArg !== '' ? $cleanArg : MarkdownExport::defaultPath($store, $this->cwd);
            try {
                $written = MarkdownExport::write($store, $target);
                $this->say('Exported Markdown to ' . $written);
            } catch (Throwable $error) {
                $this->sayError($error->getMessage());
            }

            return;
        }

        // 3. Default: export as single-file HTML
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

    private function exportPrDescription(string $customPath): void
    {
        $this->say('Generating GitHub Pull Request description from session…');

        try {
            $prMarkdown = MarkdownExport::generatePrDescription($this->session);

            // Copy to clipboard automatically for convenient pasting into GitHub PR
            $this->clipboard->write($prMarkdown);

            $savePath = $customPath !== ''
                ? $customPath
                : $this->cwd . '/.pig/PULL_REQUEST.md';

            $dir = dirname($savePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($savePath, $prMarkdown);

            $this->say($this->palette->fg('accent', '✓ PR Description generated and copied to clipboard!'));
            $this->say($this->palette->fg('dim', "Saved to: {$savePath}\n\n") . $prMarkdown);
            $this->tui->requestRender();
        } catch (Throwable $e) {
            $this->sayError('Failed to generate PR description: ' . $e->getMessage());
        }
    }

    /**
     * Put the last answer on the clipboard.
     *
     * The last thing the *assistant* said, not the last thing on screen: what someone
     * wants after reading an answer is the answer, not the status line under it.
     */
    /**
     * Write down what is on the screen and what was said, for somebody to look at later.
     *
     * Upstream's `/debug`, on upstream's key, and the reason to port it rather than leave it is
     * that **pig already has every other end of it**: `Tui::frame()` is the dump `checkWidth()`
     * writes when a line is too wide, and every width bug in CLAUDE.md was found by reading
     * exactly that — from outside the repository, with a probe written for the occasion, because
     * from inside a real session there was no way to ask.
     *
     * `0600`, and the reason is not the file's own: a conversation holds whatever the model read,
     * which on a bad day is somebody's `.env`. The session file it duplicates lives in a `0700`
     * directory; this one sits at a predictable name in the home directory, so it says so itself.
     */
    private function writeDebugLog(): void
    {
        $path = Config::home() . '/pig-debug.log';

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o700, true) && !is_dir(dirname($path))) {
            $this->sayError("Could not make {$path}'s directory.");

            return;
        }

        $lines = [
            "pig {$this->version} — " . date('c'),
            '',
            '=== the frame ===',
            $this->tui->frame(),
            '=== the conversation ===',
        ];

        foreach ($this->session->messages() as $message) {
            $encoded = SessionCodec::encode($message);
            $lines[] = $encoded === null
                ? '(a message this session has no encoding for: ' . get_debug_type($message) . ')'
                : (string) json_encode($encoded, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        // The mode is set before there is anything to read, as `Auth::save()` does it, rather than
        // written and then chmodded — which leaves the file world-readable for as long as those
        // two calls take.
        touch($path);
        chmod($path, 0o600);

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            $this->sayError("Could not write {$path}.");

            return;
        }

        $this->say('Debug log written to ' . $path);
    }

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
                . '~/.pig/agent/skills, .pig/skills, ~/.claude/skills, .claude/skills, ~/.codex/skills, '
                . '~/.pi/agent/skills or .pi/skills.';
        }

        $lines = [];

        foreach ($this->skills as $skill) {
            $lines[] = $this->palette->fg('dim', Width::pad($skill->name, 24))
                . $this->palette->fg('muted', $skill->description);
            $lines[] = $this->palette->fg('dim', str_repeat(' ', 24) . $skill->source . ' · ' . $skill->path);
        }

        return implode("\n", $lines);
    }

    /**
     * The three tables and what is loaded, as one block.
     *
     * One implementation because there are two readers — `/help` and the banner under ctrl+o —
     * and they were the same ten lines twice. They could not have disagreed about the tables;
     * they could and would have disagreed about the column, which is the thing that moved when
     * the editing keys arrived with labels wider than the old hardcoded twelve.
     */
    private function keysAndCommands(): string
    {
        $keys = $this->keys();
        $labels = [
            ...array_keys($keys),
            ...array_keys(self::EDITING_KEYS),
            ...array_map(static fn (array $row): string => '/' . $row[0], self::COMMANDS),
        ];

        // Measured from the labels, not a constant: `shift+enter` is eleven columns and the old
        // `12` left it one space from its description while `esc` had nine. The same rule as
        // `--list-models`' columns, and measured with `Width` for the same reason.
        $column = max(array_map(Width::visible(...), $labels)) + 2;

        $rows = [];

        foreach ([$keys, self::EDITING_KEYS] as $table) {
            foreach ($table as $key => $does) {
                $rows[] = $this->palette->fg('dim', Width::pad($key, $column)) . $this->palette->fg('muted', $does);
            }

            $rows[] = '';
        }

        foreach (self::COMMANDS as [$name, $does]) {
            $rows[] = $this->palette->fg('dim', Width::pad('/' . $name, $column)) . $this->palette->fg('muted', $does);
        }

        return implode("\n", $rows);
    }

    private function commandHelp(): string
    {
        return $this->keysAndCommands();
    }

    private function newSession(): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        // The hook, the new file and the emptied queue are `startNew()`'s: /new here and
        // `new_session` over RPC had different ideas about all three, and one method is how
        // they stop having them.
        try {
            $switch = $this->session->startNew();
        } catch (Throwable $error) {
            $this->sayError('Could not start a new session file: ' . $error->getMessage());

            return;
        }

        if (!$switch->switched) {
            $this->say('A hook stopped that.');

            return;
        }

        $previous = $switch->previous;

        $this->chat->clear();
        $this->pending->clear();
        $this->status->clear();
        $this->footer->invalidate();
        $this->say('New session');

        // The `session_switch` hook fired inside `startNew()`, where every mode gets it. What
        // is left here is the custom tools, which this mode holds and the session does not.
        $this->sayToolProblems($this->customTools?->notify('switch', $previous) ?? []);
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

        // The same component `--resume` draws before the screen exists, search box and all.
        // A plain `SelectList` was here first and it is the wrong list for this one thing: a
        // project with thirty conversations gives eight rows of openings and the arrow keys,
        // and what anybody remembers three days later is a phrase from the middle.
        $picker = new SessionList($sessions, $this->palette, 8);

        $picker->setSelectHandler(function (string $path) use ($sessions): void {
            foreach ($sessions as $info) {
                if ($info->path === $path) {
                    $this->closePicker();
                    $this->resume($info);

                    return;
                }
            }
        });

        // Escape and ctrl+c both close it. They mean different things where the list is the
        // whole screen — `SessionList` keeps them apart for `bin/pig`, which quits on one of
        // them — but inside a running session there is nothing to quit to: the conversation is
        // still behind the overlay either way.
        $picker->setCancelHandler($this->closePicker(...));
        $picker->setQuitHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text(
            $this->palette->fg('muted', 'Pick a session — type to search, enter to open, esc to cancel'),
            1,
            0,
        ));
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
    /**
     * The models `/model <something>` could mean, for the completion list.
     *
     * The same list `/model` with nothing after it draws and for the same reason — the models there
     * is a key for — so the two cannot come to disagree about what is on offer. Matched as a
     * substring over `provider/id` rather than fuzzily: a completion list is read while typing and
     * a subsequence match puts `moonshotai/kimi-k2-instruct` under `haiku`, which `--list-models`
     * documents as the price of fuzzy matching on a *listing* nobody is choosing from with Tab.
     *
     * @return list<AutocompleteItem>
     */
    private function modelCompletions(string $typed): array
    {
        $wanted = mb_strtolower(trim($typed));
        $items = [];

        foreach ($this->modelsWithAKey() as $model) {
            if ($wanted !== '' && !str_contains(mb_strtolower("{$model->provider}/{$model->id}"), $wanted)) {
                continue;
            }

            $items[] = new AutocompleteItem($model->id, $model->id, $model->provider);
        }

        return $items;
    }

    /**
     * Argument completions for `/thinking`: supported thinking levels for the active model.
     *
     * @return list<AutocompleteItem>
     */
    private function thinkingCompletions(string $typed): array
    {
        $levels = $this->session->availableThinkingLevels();

        if ($levels === []) {
            return [];
        }

        $wanted = mb_strtolower(trim($typed));
        $items = [];

        $descriptions = [
            'off' => 'No reasoning',
            'minimal' => 'Very brief reasoning (~1k tokens)',
            'low' => 'Light reasoning (~2k tokens)',
            'medium' => 'Moderate reasoning (~8k tokens)',
            'high' => 'Deep reasoning (~16k tokens)',
            'xhigh' => 'Maximum reasoning (~32k tokens)',
        ];

        foreach ($levels as $level) {
            $name = $level->value;

            if ($wanted !== '' && !str_starts_with($name, $wanted)) {
                continue;
            }

            $items[] = new AutocompleteItem($name, $name, $descriptions[$name] ?? null);
        }

        return $items;
    }

    /**
     * Argument completions for `/theme`: available built-in themes.
     *
     * @return list<AutocompleteItem>
     */
    private function themeCompletions(string $typed): array
    {
        $wanted = mb_strtolower(trim($typed));
        $items = [];

        foreach (Palette::names() as $name) {
            if ($wanted !== '' && !str_starts_with($name, $wanted)) {
                continue;
            }

            $desc = match ($name) {
                'dark' => 'Default dark theme with ANSI 256/TrueColor support',
                'light' => 'Clean light terminal theme',
                default => null,
            };

            $items[] = new AutocompleteItem($name, $name, $desc);
        }

        return $items;
    }

    /**
     * Uncommitted or modified files in current git repository to prioritize in `@` autocomplete.
     *
     * @return list<string> project-relative modified file paths
     */
    private function modifiedGitFiles(): array
    {
        [$exit, $out] = Process::run(['git', 'status', '--porcelain', '-u'], timeout: 0.5, cwd: $this->cwd);

        if ($exit !== 0 || trim($out) === '') {
            return [];
        }

        $files = [];

        foreach (explode("\n", trim($out)) as $line) {
            if (strlen($line) > 3) {
                $statusPath = trim(substr($line, 3));
                // Handle renamed files: `R  old -> new`
                if (str_contains($statusPath, ' -> ')) {
                    [, $statusPath] = explode(' -> ', $statusPath, 2);
                }
                $cleanPath = trim($statusPath, '"');
                if ($cleanPath !== '') {
                    $files[] = $cleanPath;
                }
            }
        }

        return $files;
    }

    private function handleThinkingCommand(string $arg): void
    {
        $available = $this->session->availableThinkingLevels();

        if ($available === []) {
            $this->say('This model does not support thinking');

            return;
        }

        if ($arg === '') {
            $this->showThinkingSelector();

            return;
        }

        $level = ThinkingLevel::tryFrom(strtolower($arg));
        $supported = array_map(static fn (ThinkingLevel $l): string => $l->value, $available);

        if ($level === null || !in_array($level->value, $supported, true)) {
            $this->sayError("Unknown thinking level \"{$arg}\". Available levels: " . implode(', ', $supported) . '.');

            return;
        }

        $this->useThinkingLevel($level->value);
        $this->paintBorder();
        $this->say("Thinking level: {$level->value}");
    }

    private function showThinkingSelector(): void
    {
        $available = $this->session->availableThinkingLevels();

        if ($available === []) {
            $this->say('This model does not support thinking');

            return;
        }

        $current = $this->session->thinkingLevel()->value;

        $submenu = $this->thinkingSubmenu($available, $current, function (?string $value): void {
            $this->overlay->clear();
            $this->tui->setFocus($this->editor);

            if ($value !== null) {
                $this->useThinkingLevel($value);
                $this->paintBorder();
                $this->say("Thinking level: {$value}");
            }

            $this->tui->requestRender();
        });

        $this->overlay->clear();
        $this->overlay->addChild($submenu);
        $this->tui->setFocus($submenu);
        $this->tui->requestRender();
    }

    /**
     * Hot-reload keybindings, extensions, skills, commands, tools, and context files.
     *
     * Ported from upstream's `/reload` command in `interactive-mode.ts`.
     */
    private function reload(): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Wait for the current response to finish before reloading.');

            return;
        }

        if ($this->session->isCompacting()) {
            $this->sayWarning('Wait for compaction to finish before reloading.');

            return;
        }

        // 1. Reload context files
        [$contextFiles, $contextWarnings] = ContextFiles::loadWithWarnings($this->cwd);
        $this->contextFiles = $contextFiles;

        // 2. Reload skills
        [$skills, $skillWarnings] = Skills::load(
            $this->cwd,
            extraDirs: array_filter($this->settings->skillList('customDirectories')),
            ignored: $this->settings->skillList('ignoredSkills'),
            only: $this->settings->skillList('includeSkills'),
            roots: [
                'codex-user' => $this->settings->skillRoot('enableCodexUser'),
                'claude-user' => $this->settings->skillRoot('enableClaudeUser'),
                'claude-project' => $this->settings->skillRoot('enableClaudeProject'),
                'pi-user' => $this->settings->skillRoot('enablePiUser'),
                // The same gate `CodingAgent::session()` applies: `/reload` must not be the door
                // an untrusted project's `.pig/` comes in by.
                'pi-project' => $this->projectTrusted && $this->settings->skillRoot('enablePiProject'),
                'project' => $this->projectTrusted,
            ],
        );
        $this->skills = $skills;

        // 3. Reload file commands
        $this->fileCommands = SlashCommands::load($this->cwd, projectTrusted: $this->projectTrusted);
        $this->session->setFileCommands($this->fileCommands);

        // 4. Reload hooks, and extensions with theirs
        [$loadedHooks, $hookProblems] = HookLoader::load($this->cwd, $this->settings->hooks(), projectTrusted: $this->projectTrusted);
        $loadedTools = [];

        // The loader scans the three extension roots itself. The third argument is for paths
        // typed on the command line (`--extension`), which this mode is not given — and for a
        // while it was handed `ExtensionDiscovery::discover()`'s *display labels* instead, so every
        // reload complained that `copy.php` and `pig-antigravity` were not readable files under
        // the project directory. A label for a banner is not a path.
        [$loadedExtensions, $extensionProblems] = ExtensionLoader::load(
            $this->cwd,
            $this->settings->extensions(),
            auth: $this->auth,
            projectTrusted: $this->projectTrusted,
        );
        $this->extensions = $loadedExtensions;

        foreach ($loadedExtensions as $ext) {
            $loadedHooks[] = new LoadedHook($ext->path, $ext->resolved, $ext->api);
            foreach ($ext->api->tools() as $tool) {
                $loadedTools[] = new LoadedCustomTool($ext->path, $ext->resolved, $tool);
            }
        }

        $hooks = new HookRunner($loadedHooks, $this->cwd, $this->session->store());
        $this->hooks = $hooks;
        $this->session->setHooks($hooks);

        $session = $this->session;
        $hooks->initialize(
            getModel: static fn () => $session->model(),
            isIdle: static fn (): bool => !$session->isStreaming(),
            abort: static function () use ($session): void {
                $session->abort();
            },
            hasQueuedMessages: static fn (): bool => $session->queued() !== [],
            signal: static fn () => $session->signal(),
            ui: $this->ui,
            send: static function (HookMessage $message, bool $triggerTurn) use ($session): void {
                $session->sendHookMessage($message, $triggerTurn);
            },
            note: static function (string $customType, mixed $data) use ($session): void {
                $session->appendHookEntry($customType, $data);
            },
            getApiKey: static fn (Model $m) => $session->keyFor($m),
            setSessionName: static fn (string $name) => $session->setSessionName($name),
            getSessionName: static fn () => $session->getSessionName(),
        );

        $hooks->onError($this->sayHookError(...));
        $this->messageRenderers = $hooks->renderers();
        [$this->hookCommands, $clashes] = $hooks->commands();

        foreach ($clashes as $clash) {
            $hooks->emitError($clash);
        }

        // 6. Reload custom tools
        $toolApi = new CustomToolApi($this->cwd);
        [$diskTools, $toolProblems] = CustomToolLoader::load(
            $this->cwd,
            $this->builtInTools,
            $this->settings->customTools(),
            api: $toolApi,
            projectTrusted: $this->projectTrusted,
        );
        $allCustomTools = [...$diskTools, ...$loadedTools];
        $customTools = new CustomToolSet($allCustomTools, $toolApi);
        $this->customTools = $customTools;
        $customTools->withUi($this->ui);

        foreach ($loadedExtensions as $ext) {
            $customTools->adopt($ext);
        }

        $customTools->onChange(function (CustomToolSet $tools) use ($hooks): void {
            $this->session->agent->setTools(HookedTool::wrap(
                [...ToolSet::create($this->cwd, $this->builtInTools), ...$tools->agentTools()],
                $hooks,
            ));
            [$snippets, $guidelines] = $tools->promptContributions();
            $this->session->agent->setSystemPrompt(SystemPrompt::build($this->cwd, $this->builtInTools, contextFiles: $this->contextFiles, skills: $this->skills, toolSnippets: $snippets, toolGuidelines: $guidelines));
        });
        $customTools->withContext(fn () => $hooks->context());

        // 7. Update agent tools & system prompt
        $builtIn = ToolSet::create($this->cwd, $this->builtInTools);
        $allTools = [...$builtIn, ...$customTools->agentTools()];
        $wrapped = HookedTool::wrap($allTools, $hooks);
        $this->session->agent->setTools($wrapped);

        [$snippets, $guidelines] = $customTools->promptContributions();
        $systemPrompt = SystemPrompt::build(
            $this->cwd,
            $this->builtInTools,
            contextFiles: $this->contextFiles,
            skills: $this->skills,
            toolSnippets: $snippets,
            toolGuidelines: $guidelines,
        );
        $this->session->agent->setSystemPrompt($systemPrompt);

        // 8. Rebind autocomplete on editor and update banner
        $this->bindEditor();
        $this->banner?->setText($this->banner());

        // 9. Report warnings if any
        $allWarnings = [
            ...$contextWarnings,
            ...array_map(static fn ($w): string => "skill {$w->path}: {$w->message}", $skillWarnings),
            ...array_map(static fn ($p): string => "hook {$p->toText()}", $hookProblems),
            ...array_map(static fn ($p): string => "extension {$p->toText()}", $extensionProblems),
            ...array_map(static fn ($p): string => "tool {$p->toText()}", $toolProblems),
        ];

        foreach ($allWarnings as $w) {
            $this->sayWarning($w);
        }

        $this->say($this->palette->fg('accent', 'Reloaded extensions, skills, commands, tools, and context files.'));
        $this->tui->requestRender();
    }
    private static function modelSearchText(Model $model): string
    {
        return "{$model->provider} {$model->provider}/{$model->id} {$model->provider} {$model->id} {$model->name}";
    }

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

        // The models there is a key for, which is upstream's `getAvailable()` and what the word
        // "available" means everywhere else in pig now — narrowed by `--models` when that was
        // given. A picker that offers a model whose every turn will fail is a picker that teaches
        // people not to read it, and one that offers models ctrl+p cannot reach is the same thing
        // one step milder.
        $models = $this->modelsWithAKey();
        $current = $this->session->model();
        $items = [];

        if ($models === []) {
            $this->sayError('No model has a key here. /login, or set a provider key in the environment.');

            return;
        }

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
                self::modelSearchText($model),
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
        // Over the models there is a key for, as upstream's model selector resolves: `/model
        // gemini` with no Google key should say there is no such model here rather than switch to
        // one and fail on the next turn. And over the scope when `--models` set one.
        $choice = ModelResolver::parse($pattern, $this->modelsWithAKey());

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
        // `setModel()` refuses a model there is no key for. The lists above are filtered, so this
        // is the case they cannot cover: a `models.json` provider whose key variable is empty, or
        // a sign-out in another window between drawing the picker and choosing from it.
        try {
            $this->session->setModel($model, $thinking);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

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

        $tree = $store->tree();

        if (count($store->branch()) < 2) {
            $this->say('Nothing to go back to yet.');

            return;
        }

        // `tree()`, not `branch()`. The branch is the path being talked on; a conversation that went
        // back has another one beside it, still in the file with its parents intact, and listing
        // only the current path made it unreachable — `goTo()` needs an id and nothing showed one.
        $picker = new TreeList($tree, $store->leaf(), 12, $this->palette);
        $picker->setSelectHandler(function (string $id): void {
            $this->closePicker();

            // In a fiber: going back may ask the model to summarise what is being left,
            // and that suspends. Same reason `send()` spawns.
            Async::spawn(fn () => $this->goBackTo($id));
        });
        $picker->setCancelHandler($this->closePicker(...));
        $picker->setLabelHandler(function (string $id, ?string $current): void {
            // Named from inside the tree, which is where anybody realises a point is worth a name.
            // The overlay closes first: the name is asked for with the editor, and two things
            // holding the focus is the bug `$overlay` exists to prevent.
            $this->closePicker();
            Async::spawn(function () use ($id, $current): void {
                // The current name goes in as the placeholder rather than as a prefill: upstream
                // seeds its own input with it, and `HookUi::input()` — which three classes
                // implement — takes a placeholder and no prefill. Adding one for this would change
                // an interface for a convenience, so the name is shown and retyped instead.
                $name = trim((string) $this->ui->input(
                    $current === null ? 'Name this point' : "Name this point (now: {$current})",
                    $current ?? 'before the refactor',
                ));

                $this->session->store()?->appendLabel($id, $name === '' ? null : $name);
                $this->say($name === '' ? 'Name cleared.' : 'Named "' . $name . '".');
            });
        });

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text(
            $this->palette->fg('muted', 'Go back to — enter to pick, ctrl+o to filter, l to name, type to search, esc to cancel'),
            1,
            0,
        ));
        $this->overlay->addChild($picker);

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    private ?\Pig\CodingAgent\Web\HttpServer $webServer = null;

    /** Show or set the friendly name of the session. */
    private function handleNameCommand(string $name): void
    {
        if ($name === '') {
            $current = $this->session->getSessionName();
            if ($current !== null && $current !== '') {
                $this->say("Session name: {$current}");
            } else {
                $this->sayWarning('Usage: /name <name>');
            }

            return;
        }

        $this->session->setSessionName($name);
        $this->say("Session name set: {$name}");
        $this->tui->requestRender();
    }

    private function handleWebCommand(string $args): void
    {
        $rawArgs = trim($args);
        $parts = $rawArgs === '' ? [] : (preg_split('/\s+/', $rawArgs) ?: []);
        $action = strtolower($parts[0] ?? '');

        // 1. /web stop: stop foreground server & background daemon
        if ($action === 'stop') {
            $stoppedForeground = false;
            if ($this->webServer !== null && $this->webServer->isRunning()) {
                $server = $this->webServer;
                $this->webServer = null;
                Async::spawn(static fn () => $server->stop());
                $stoppedForeground = true;
            }

            [$daemonCode, $daemonOut] = Process::run([...$this->resolvePigBinary(), 'web', 'stop']);
            $stoppedDaemon = $daemonCode === 0 && str_contains($daemonOut, 'stopped');

            if ($stoppedForeground || $stoppedDaemon) {
                $this->say('Web UI server stopped.');
            } else {
                $this->say('Web UI is not running.');
            }

            return;
        }

        // 2. /web status: report foreground and background daemon status
        if ($action === 'status') {
            if ($this->webServer !== null && $this->webServer->isRunning()) {
                $url = "http://{$this->webServer->host}:{$this->webServer->port}";
                $this->say('Web UI foreground server: ' . $this->palette->fg('accent', 'running') . " at {$url}");
            }

            [, $daemonOut] = Process::run([...$this->resolvePigBinary(), 'web', 'status']);
            $statusLine = trim($daemonOut);
            if ($statusLine !== '') {
                $this->say($statusLine);
            }

            return;
        }

        // 3. /web restart [port]: restart daemon (and stop foreground if running)
        if ($action === 'restart') {
            if ($this->webServer !== null && $this->webServer->isRunning()) {
                $server = $this->webServer;
                $this->webServer = null;
                Async::spawn(static fn () => $server->stop());
            }

            $rest = array_slice($parts, 1);
            $daemonArgs = ['web', 'restart'];
            if (isset($rest[0])) {
                if (ctype_digit($rest[0])) {
                    $daemonArgs[] = '--port=' . $rest[0];
                } else {
                    $daemonArgs = array_merge($daemonArgs, $rest);
                }
            }

            [$exitCode, $stdout, $stderr] = Process::run([...$this->resolvePigBinary(), ...$daemonArgs]);
            if ($exitCode === 0) {
                foreach (explode("\n", trim($stdout)) as $line) {
                    if (trim($line) !== '') {
                        $this->say($line);
                    }
                }
            } else {
                $this->sayError('Failed to restart Web UI: ' . trim($stderr ?: $stdout));
            }

            return;
        }

        // 4. Default: /web or /web [port]
        if ($this->webServer !== null && $this->webServer->isRunning()) {
            $url = "http://{$this->webServer->host}:{$this->webServer->port}";
            $this->say("Web UI is already running at {$url}");

            return;
        }

        // If user explicitly asks for `-d` or `--daemon`, start in background
        if ($action === 'start' || in_array('-d', $parts, true) || in_array('--daemon', $parts, true)) {
            $rest = $action === 'start' ? array_slice($parts, 1) : $parts;
            $daemonArgs = ['web', 'start', '-d'];
            if (isset($rest[0])) {
                if (ctype_digit($rest[0])) {
                    $daemonArgs[] = '--port=' . $rest[0];
                } else {
                    $daemonArgs = array_merge($daemonArgs, $rest);
                }
            }
            [$startCode, $startOut, $startErr] = Process::run([...$this->resolvePigBinary(), ...$daemonArgs]);
            if ($startCode === 0) {
                foreach (explode("\n", trim($startOut)) as $line) {
                    if (trim($line) !== '') {
                        $this->say($line);
                    }
                }
            } else {
                $this->sayError('Failed to start Web UI daemon: ' . trim($startErr ?: $startOut));
            }

            return;
        }

        // Otherwise start in-process attached to this session (matching original /web [port])
        $port = is_numeric($rawArgs) ? (int) $rawArgs : 8088;
        $bound = false;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidatePort = $port + $attempt;
            try {
                $this->webServer = new \Pig\CodingAgent\Web\HttpServer(
                    $this->session->cwd(),
                    $candidatePort,
                    auth: $this->auth,
                );
                $this->webServer->start();
                $bound = true;
                $port = $candidatePort;
                break;
            } catch (Throwable) {
                continue;
            }
        }

        if (!$bound || $this->webServer === null) {
            $this->sayError('Failed to bind Web UI server (ports in use).');

            return;
        }

        $url = "http://127.0.0.1:{$port}";
        $this->say('Web UI started at ' . $this->palette->fg('accent', $url));
        $this->say($this->palette->fg('dim', 'Runs while this session is open. To keep it running in background: pig web start -d'));
    }

    /** @return list<string> */
    private function resolvePigBinary(): array
    {
        $localBin = __DIR__ . '/../../../../bin/pig';
        if (is_file($localBin)) {
            return [PHP_BINARY, (string) realpath($localBin)];
        }

        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($script !== '' && is_file($script) && basename($script) === 'pig') {
            return [PHP_BINARY, (string) realpath($script)];
        }

        return ['pig'];
    }

    private function handleDiffCommand(string $args): void
    {
        $cwd = $this->session->cwd();
        $cmd = ['git', 'diff'];
        if ($args !== '') {
            $tokens = preg_split('/\s+/', $args, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $cmd = array_merge($cmd, $tokens);
        }

        $res = Process::run($cmd, cwd: $cwd);
        [$code, $stdout, $stderr] = $res;
        if ($code !== 0) {
            $this->sayError('git diff failed: ' . ($stderr ?: 'Not a git repository or git error.'));

            return;
        }

        $diff = trim($stdout);
        if ($diff === '') {
            $this->say('No git changes in working tree.');

            return;
        }

        $this->chat->addChild(new DiffView($diff, $this->palette));
        $this->tui->requestRender();
    }

    private function handleCommitCommand(string $args): void
    {
        $cwd = $this->session->cwd();
        [$statusCode, $statusOut] = Process::run(['git', 'status', '--porcelain'], cwd: $cwd);
        if ($statusCode !== 0) {
            $this->sayError('Not a git repository.');

            return;
        }

        if (trim($statusOut) === '') {
            $this->say('Nothing to commit (working tree clean).');

            return;
        }

        $msg = trim($args);

        if ($msg !== '') {
            $this->executeGitCommit($cwd, $msg);

            return;
        }

        Async::spawn(function () use ($cwd): void {
            $this->generateAndCommit($cwd);
        });
    }

    private function generateAndCommit(string $cwd): void
    {
        [, $diff] = Process::run(['git', 'diff', 'HEAD'], cwd: $cwd);
        if (trim($diff) === '') {
            [, $diff] = Process::run(['git', 'diff'], cwd: $cwd);
        }
        if (trim($diff) === '') {
            [, $diff] = Process::run(['git', 'status', '-s'], cwd: $cwd);
        }

        if (strlen($diff) > 20000) {
            $diff = substr($diff, 0, 20000) . "\n... [diff truncated]";
        }

        $prompt = "Based on the following git changes, write a concise, precise git commit message following Conventional Commits format (e.g. 'feat(core): add feature' or 'fix(auth): fix token bug').\n"
            . "Output ONLY the commit message itself on 1-2 lines, with no quotes, no markdown fences, no extra explanations:\n\n"
            . $diff;

        $this->say('Reviewing changes to generate commit message…');

        try {
            $model = $this->session->model();
            if ($model === null) {
                $this->sayError('No model selected to generate commit message.');

                return;
            }

            $stream = \Pig\Ai\Stream::simple(
                $model,
                new \Pig\Ai\Context([new \Pig\Ai\UserMessage($prompt)], systemPrompt: 'You are an expert software developer writing clean Conventional Commits git messages.'),
                new \Pig\Ai\SimpleStreamOptions(temperature: 0.2, apiKey: $this->session->keyFor($model)),
            );

            $assistantMsg = $stream->result()->await();
            $generated = '';
            foreach ($assistantMsg->content as $c) {
                if ($c instanceof \Pig\Ai\TextContent) {
                    $generated .= $c->text;
                }
            }
            $generated = trim((string) preg_replace('/^```[a-z]*\n|```$/i', '', trim($generated)));

            if ($generated === '') {
                $this->sayError('Failed to generate commit message.');

                return;
            }

            $confirmed = $this->ui->confirm('Commit with message?', $generated);
            if ($confirmed) {
                $this->executeGitCommit($cwd, $generated);
            } else {
                $this->say('Commit cancelled.');
            }
        } catch (Throwable $e) {
            $this->sayError('Commit generation failed: ' . $e->getMessage());
        }
    }

    private function executeGitCommit(string $cwd, string $message): void
    {
        [$addCode, , $addErr] = Process::run(['git', 'add', '-A'], cwd: $cwd);
        if ($addCode !== 0) {
            $this->sayError('git add failed: ' . ($addErr ?: 'Unknown error'));

            return;
        }

        [$commitCode, $commitOut, $commitErr] = Process::run(['git', 'commit', '-m', $message], cwd: $cwd);
        if ($commitCode !== 0) {
            $this->sayError('git commit failed: ' . ($commitErr ?: $commitOut));

            return;
        }

        $this->say($this->palette->fg('accent', '✓ Committed: ') . $this->palette->fg('dim', trim($commitOut)));
        $this->footer->invalidate();
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

        if (!$jump->moved && !$jump->aborted) {
            // Already there. Not a cancellation and not a failure, so it says so and stops: the
            // fourth answer `TreeJump` had to be able to give.
            $this->say('Already at that point.');

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

        // Back in the prompt, to be asked differently — which is what going back to something you
        // said is for. Whatever was half-typed is replaced, because this is a deliberate choice
        // from a list and the text that was there is the text of the message being taken back.
        if ($jump->editorText !== null) {
            $this->editor->setText($jump->editorText);
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

        $this->showLoader('Summarising the branch... (esc to stop)');

        try {
            return $this->session->goTo($entryId, summarise: true, signal: $controller->signal);
        } finally {
            $this->compaction = null;
            $this->hideLoader();
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
            // Upstream's `oauth-selector.ts` wording: a sign-in that is there is "configured", one
            // that is not is "not configured", and the kind is "subscription" only for a provider
            // whose sign-in is backed by one (all three of pig's are; upstream's Radius is not).
            $kind = $provider->isSubscription() ? 'subscription' : 'account';
            $items[] = new SelectItem(
                (string) (count($providers) - 1),
                $provider->available() ? $provider->label() : $this->palette->fg('dim', $provider->label()),
                $signedIn ? "{$kind} configured" : ($provider->available() ? 'not configured' : 'not ported yet'),
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
                    // domain prompt and a cancellation for Anthropic's paste box. The signal is
                    // the flow's own, for a box that races a browser callback and has to close
                    // when the callback wins.
                    fn (string $message, string $placeholder, bool $allowEmpty, ?AbortSignal $closing = null): ?string
                        => $this->ui->input($message, $placeholder, $closing),
                    function (string $note): void {
                        $this->say($note);
                    },
                    $controller->signal,
                    // A choice between named ways in — Anthropic's browser-or-copy-code. The
                    // labels are what the list shows; the id is what comes back.
                    function (string $title, array $options): ?string {
                        $labels = array_map(static fn (array $option): string => $option[1], $options);
                        $picked = $this->ui->select($title, $labels);

                        foreach ($options as [$id, $label]) {
                            if ($label === $picked) {
                                return $id;
                            }
                        }

                        return null;
                    },
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

    /**
     * Periodically check whether the Command key is held down on local macOS to display the shortcut HUD,
     * matching the interaction in Blink Shell / iPadOS.
     */
    private function watchCommandKey(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !class_exists('FFI')) {
            return;
        }

        // Only watch for physical terminals, never for unit tests running on FakeTerminal
        if (!$this->tui->terminal instanceof \Pig\Tui\ProcessTerminal) {
            return;
        }

        if (getenv('SSH_CONNECTION') !== false || getenv('SSH_CLIENT') !== false || getenv('SSH_TTY') !== false) {
            return;
        }

        static $ffi = null;
        static $available = true;

        if ($available && $ffi === null) {
            try {
                $ffi = \FFI::cdef("
                    typedef uint32_t CGEventSourceStateID;
                    typedef uint64_t CGEventFlags;
                    CGEventFlags CGEventSourceFlagsState(CGEventSourceStateID stateID);
                ", "/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics");
            } catch (\Throwable) {
                $available = false;
            }
        }

        if ($ffi === null) {
            return;
        }

        $tick = function () use (&$tick, $ffi): void {
            if ($this->commandHudTimer === null) {
                return;
            }

            try {
                $flags = (int) $ffi->CGEventSourceFlagsState(1); // kCGEventSourceStateCombinedSessionState

                if (self::isSoloCommand($flags)) {
                    $this->commandPressedMs += 80;
                    // 850ms threshold matching iPadOS/Blink standards to eliminate hesitation false-positives
                    if ($this->commandPressedMs >= 850 && !$this->commandHudOpen && $this->overlay->children() === []) {
                        $this->showCommandHud();
                    }
                } else {
                    $this->commandPressedMs = 0;
                    if ($this->commandHudOpen) {
                        $this->closeCommandHud();
                    }
                }
            } catch (\Throwable) {
            }

            $this->commandHudTimer = Loop::get()->delay(0.08, $tick);
        };

        $this->commandHudTimer = Loop::get()->delay(0.08, $tick);
    }

    /**
     * Determine whether macOS CGEventFlags indicates a pure, isolated Command key press
     * with no other active modifiers (Shift, Control, Option/Alt).
     */
    public static function isSoloCommand(int $flags): bool
    {
        // macOS CoreGraphics modifier bitmasks:
        // kCGEventFlagMaskCommand   = 0x00100000 (1 << 20)
        // kCGEventFlagMaskShift     = 0x00020000 (1 << 17)
        // kCGEventFlagMaskControl   = 0x00040000 (1 << 18)
        // kCGEventFlagMaskAlternate = 0x00080000 (1 << 19, Option/Alt)
        $hasCommand = ($flags & 0x00100000) !== 0;
        $hasOtherModifiers = ($flags & (0x00020000 | 0x00040000 | 0x00080000)) !== 0;

        return $hasCommand && !$hasOtherModifiers;
    }

    private function showCommandHud(): void
    {
        if ($this->commandHudOpen || $this->overlay->children() !== []) {
            return;
        }

        $this->commandHudOpen = true;
        $this->overlay->addChild(new CommandHudComponent($this->palette));
        $this->tui->requestRender();
    }

    private function closeCommandHud(): void
    {
        if (!$this->commandHudOpen) {
            return;
        }

        $this->commandHudOpen = false;
        $this->overlay->clear();
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    /** Replace this conversation with a saved one, and redraw it. */
    private function resume(SessionInfo $info): void
    {
        $was = $this->session->model()?->id;

        // Asking the hooks, opening the file and restoring the model are `switchTo()`'s, so
        // that a host switching session over RPC gets the same three and not two of them.
        try {
            $switch = $this->session->switchTo($info->path);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        if (!$switch->switched) {
            $this->say('A hook stopped that.');

            return;
        }

        $previous = $switch->previous;

        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();

        // Said after the transcript, so it is the last thing on screen rather than the
        // first thing buried above a conversation.
        $this->say('Resumed ' . $switch->messages . ' messages from ' . $info->when());

        $now = $this->session->model()?->id;

        if ($now !== null && $now !== $was) {
            // Worth a line: the model changing under someone without being told is how a
            // surprising answer becomes a puzzle.
            $this->say($this->palette->fg('muted', "This conversation was on {$now} — switched back to it."));
        }

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

    /**
     * There is a newer pig, drawn where the changelog is drawn.
     *
     * Upstream's `showNewVersionNotification()`: a bordered block naming the version and the
     * command, and nothing more — pi does not update itself and neither does pig, which is the
     * developer's call and `Cli\UpdateCheck`'s docblock has the reasoning.
     *
     * **Public, because the caller arrives late.** The check is spawned so it cannot slow the
     * start, so the answer lands while the person is already typing and has to be able to draw
     * itself then. Everything else the chat says goes through `say()`, `sayError()` or
     * `sayWarning()`; this is a block rather than a line, like the changelog it sits next to.
     *
     * **"you have" is `$this->version`, which is the same string the banner drew.** It was
     * `Version::current()` for a batch, which reads the manifest a second time — so on any screen
     * where the two could differ they *did*: the banner said one number and the notice said
     * another, three lines apart, which is a screen contradicting itself about the one fact this
     * block exists to compare.
     */
    public function sayNewVersion(string $version): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Rule($this->palette->of('warning')));
        $this->chat->addChild(new Text(
            $this->palette->fg('warning', Style::bold('Update Available')) . "\n\n"
            . $this->palette->fg('muted', "New version {$version} is available. Run ")
            . $this->palette->fg('accent', UpdateCheck::COMMAND) . "\n"
            . $this->palette->fg('muted', 'Changelog: https://pigagent.dev/changelog'),
            1,
            0,
        ));
        $this->chat->addChild(new Rule($this->palette->of('warning')));
        $this->tui->requestRender();
    }

    /**
     * Upstream's `showPackageUpdateNotification()`: a bordered block naming packages with available updates.
     *
     * @param list<string> $packages
     */
    public function sayPackageUpdates(array $packages): void
    {
        if ($packages === []) {
            return;
        }

        $packageLines = implode("\n", array_map(static fn (string $pkg): string => "- {$pkg}", $packages));

        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Rule($this->palette->of('warning')));
        $this->chat->addChild(new Text(
            $this->palette->fg('warning', Style::bold('Package Updates Available')) . "\n\n"
            . $this->palette->fg('muted', 'Package updates are available. Run ')
            . $this->palette->fg('accent', 'pig update --extensions') . "\n\n"
            . $this->palette->fg('muted', "Packages:\n")
            . $packageLines,
            1,
            0,
        ));
        $this->chat->addChild(new Rule($this->palette->of('warning')));
        $this->tui->requestRender();
    }

    private function handleThemeCommand(string $arg = ''): void
    {
        $names = Palette::names($this->cwd);

        if ($arg !== '') {
            if (in_array($arg, $names, true)) {
                $this->useTheme($arg);
                return;
            }

            $this->sayError("No theme called '{$arg}'. Available themes: " . implode(', ', $names) . '.');
            return;
        }

        // Without arguments: cycle through available themes in order
        $currentIndex = array_search($this->theme, $names, true);
        $nextIndex = ($currentIndex !== false && $currentIndex + 1 < count($names)) ? $currentIndex + 1 : 0;
        $this->useTheme($names[$nextIndex]);
    }

    private function showThemeSelector(): void
    {
        $names = Palette::names($this->cwd);
        $items = [];
        $selectedIndex = 0;

        foreach ($names as $index => $name) {
            $isCurrent = $name === $this->theme;
            if ($isCurrent) {
                $selectedIndex = $index;
            }
            $desc = match ($name) {
                'dark' => 'Default dark theme (violet accent, balanced contrast)',
                'light' => 'Clean light theme (violet accent for bright environments)',
                'labra' => 'Cyberpunk dark olive & hot pink theme (from omarchy-labra)',
                default => 'Custom theme',
            };
            $items[] = new SelectItem(
                $name,
                $name . ($isCurrent ? ' ·' : ''),
                $desc,
                $name,
            );
        }

        $picker = new SelectList($items, 8, $this->palette->selectListTheme());
        $picker->setSelectedIndex($selectedIndex);
        $picker->setSelectHandler(function (SelectItem $item): void {
            $this->closePicker();
            $this->useTheme($item->value);
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new Text($this->palette->fg('muted', 'Pick a theme — enter to switch, esc to cancel'), 1, 0));
        $this->overlay->addChild($picker);
        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    private function switchTheme(): void
    {
        $this->handleThemeCommand('');
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
                values: Palette::names($this->cwd),
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
        $this->footer->invalidate();
    }

    /** Split out of `switchTheme()` so `/settings` can name a theme rather than toggle. */
    private function useTheme(string $wanted): void
    {
        $this->theme = $wanted;
        $this->palette = Palette::named($wanted, cwd: $this->cwd);
        $this->settings->setTheme($wanted);

        // Update banner text and colors
        if ($this->banner !== null) {
            $this->banner->setText($this->banner());
        }

        // Replay chat history so existing messages and tool blocks update to new theme
        $this->chat->clear();
        $this->replay();

        // Update footer and editor with new palette
        $this->footer->setPalette($this->palette);
        $this->paintBorder();

        $available = implode(', ', Palette::names($this->cwd));
        $this->say("Theme: {$wanted} — already-drawn output keeps its colours (Available themes: {$available})");

        // **Not forced**, which it was: nothing has overwritten this screen, so there is a previous
        // frame to diff against and the lines whose colours changed are the only ones to rewrite.
        // Forcing empties what the screen is believed to hold, and the renderer reads that as
        // "first frame ever" — every line written with no clear, from wherever the cursor is, which
        // is the bottom of the frame already there. The trap in CLAUDE.md about the forced render,
        // third caller. `force` is for after something else owned the screen: coming back from
        // `$VISUAL`, or from a suspend.
        $this->tui->requestRender();
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
        $this->showLoader('Working... (esc to interrupt)');
    }

    /**
     * A loader in the rule above the prompt, where upstream puts every status indicator.
     *
     * Upstream's `showStatusIndicator()` with `embedWorkingStatus`: working, retry, compaction
     * and branch summary all go through here, so there is one loader at a time and one place
     * that takes it down. It used to be a line of its own in `$status`, with a blank above it,
     * which grew the frame by two rows at the start of every turn and shrank it at the end; the
     * border is already there. `$status` stays for anything that is not a loader.
     */
    private function showLoader(string $message): Loader
    {
        $this->working?->stop();
        $this->status->clear();

        $this->working = new Loader(
            $this->tui,
            $this->palette->of('accent'),
            $this->palette->of('muted'),
            $message,
        );

        $this->editor->setWorkingStatus($this->working, fn (string $text): string => $this->borderColour()($text));
        $this->tui->requestRender();

        return $this->working;
    }

    private function hideLoader(): void
    {
        $this->working?->stop();
        $this->working = null;
        $this->editor->setWorkingStatus(null);
        $this->status->clear();
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

            if ($event->message->stopReason === StopReason::Error) {
                $hasContent = false;
                foreach ($event->message->content as $c) {
                    if ($c instanceof TextContent && trim($c->text) !== '') {
                        $hasContent = true;
                        break;
                    }
                }
                if (!$hasContent) {
                    $this->sayError($said);
                }

                if (BugReport::worthReporting($event->message, $this->session->model()?->contextWindow)) {
                    $this->suggestBugReport();
                }
            }
        } else {
            // The message is whole, so every call in it is whole — which is the moment an
            // edit can be shown before it happens, and the last moment before a `tool_call`
            // hook may stop everything to ask about one. Upstream's own place for this.
            foreach ($this->tools as $tool) {
                $tool->setArgsComplete();
            }
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
        $this->hideLoader();
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
     *
     * Upstream's wording (`Retrying (1/3) in 30s... (esc to cancel)`), and not the error with
     * the countdown after it, which this used to say: the reason is already a red line in the
     * transcript by the time the retry starts, and in the prompt's border — where this is drawn
     * now — an 80-column terminal cut the error off before the one word that matters, which is
     * the key that stops it.
     */
    private function onRetryStart(RetryStartEvent $event): void
    {
        $seconds = rtrim(rtrim(number_format($event->delaySeconds, 1), '0'), '.');

        $this->showLoader("Retrying ({$event->attempt}/{$event->maxAttempts}) in {$seconds}s... (esc to stop)");
    }

    private function onRetryEnd(RetryEndEvent $event): void
    {
        $this->hideLoader();

        if ($event->succeeded) {
            // Nothing said: the answer it retried for is already on screen above this, and
            // "it worked" about something that never visibly failed is noise.
            return;
        }

        $this->sayError($event->error ?? 'Giving up after ' . $event->attempts . ' attempts.');
    }

    private function onOverflow(): void
    {
        // Enter does nothing for the length of it, which is upstream's one use of this flag and
        // the only answer that does not lose the message. Without it the submit handler clears
        // the editor and spawns a turn that parks on the compaction — and when the compaction's
        // own carry-on starts a run, that turn wakes up to `Agent is already working` and what
        // was typed is gone, with a red line where it went. The text stays where it is and the
        // next Enter sends it.
        $this->editor->disableSubmit(true);

        $this->showLoader('Context is full — summarising, then trying again. (esc to cancel)');
    }

    private function onOverflowHandled(AutoCompactionEndEvent $event): void
    {
        $this->editor->disableSubmit(false);
        $this->hideLoader();

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
            cwd: $this->cwd,
        );
        $tool->setExpanded($this->expanded);
        $this->chat->addChild($tool);
        $this->tools[$id] = $tool;

        return $tool;
    }

    // ---- the two lines that are not the conversation ------------------------------------------

    /**
     * What is waiting to be sent, above the editor — upstream's `updatePendingMessagesDisplay()`.
     *
     * Each line says *which* queue it is in, because the two mean different things to the
     * person watching: `Steering:` arrives after the tool that is running, `Follow-up:` after
     * the turn. And the way back out is named under them, since a queued line that cannot be
     * taken back is a line nobody dares to queue.
     */
    private function showQueue(): void
    {
        $this->pending->clear();
        ['steering' => $steering, 'followUp' => $followUp] = $this->session->queuedByKind();

        if ($steering === [] && $followUp === []) {
            return;
        }

        $this->pending->addChild(new Spacer(1));

        foreach ($steering as $message) {
            $this->pending->addChild(new TruncatedText($this->palette->fg('dim', "Steering: {$message}"), 1, 0));
        }

        foreach ($followUp as $message) {
            $this->pending->addChild(new TruncatedText($this->palette->fg('dim', "Follow-up: {$message}"), 1, 0));
        }

        $key = $this->keybindings->display('app.message.dequeue');
        $this->pending->addChild(new TruncatedText($this->palette->fg('dim', "↳ {$key} to edit all queued messages"), 1, 0));
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
