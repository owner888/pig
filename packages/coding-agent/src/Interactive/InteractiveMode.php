<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentLoop;
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
use Pig\Agent\TurnStartEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\BuiltinProviders;
use Pig\Ai\Extension\ApiKeyAuth;
use Pig\Ai\Extension\EnvApiKeyAuth;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Models;
use Pig\Ai\Stream;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\Provider as ExtensionProvider;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\ModelRefresh;
use Pig\CodingAgent\ModelRefreshResult;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\BugReport;
use Pig\CodingAgent\CrashLog;
use Pig\CodingAgent\CustomModels;
use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\TrustChoice;
use Pig\CodingAgent\Doctor\Doctor;
use Pig\CodingAgent\Changelog;
use Pig\CodingAgent\Cli\UpdateCheck;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Cli\SessionList;
use Pig\CodingAgent\ModelChoice;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Export\JsonlExport;
use Pig\CodingAgent\Export\SessionShare;
use Pig\CodingAgent\Export\MarkdownExport;
use Pig\Tui\Keybindings as TuiKeybindings;
use Pig\Tui\Keys;
use Pig\Tui\Images\TerminalImage;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolApi;
use Pig\CodingAgent\CustomTools\CustomToolLoader;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\CustomTools\ToolProblem;
use Pig\CodingAgent\Extensions\ExtensionDiscovery;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\CodingAgent\McpServerRegistry;
use Pig\CodingAgent\VirtualModelRegistry;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookLoader;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\RegisteredCommand;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\ContextFiles;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\SkillWarning;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Packages\PackageError;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Tools\ToolInstaller;
use Pig\CodingAgent\Tools\ToolLoadout;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Tui\Env;
use Pig\CodingAgent\Session\CacheMiss;
use Pig\CodingAgent\Session\CacheStats;
use Pig\CodingAgent\Session\CacheWarmedEvent;
use Pig\CodingAgent\Session\CacheWarmer;
use Pig\CodingAgent\Session\UsageEntry;
use Pig\CodingAgent\Session\SessionCodec;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\ExtensionResources;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\CompactionEndEvent;
use Pig\CodingAgent\Session\CompactionStartEvent;
use Pig\CodingAgent\Session\ModelFallbackEvent;
use Pig\CodingAgent\Session\RetryEndEvent;
use Pig\CodingAgent\Session\RetryStartEvent;
use Pig\CodingAgent\Session\SummarizationRetryEvent;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\MissingSessionCwdError;
use Pig\CodingAgent\Session\SessionCwd;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\TreeJump;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\InteractiveThemeController;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\ThemeJson;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\ExternalTool;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\AutocompleteProvider;
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
use Pig\Tui\Components\SelectListLayout;
use Pig\Tui\Components\SettingItem;
use Pig\Tui\Components\SettingsList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\TruncatedText;
use Pig\Tui\Container;
use Pig\Tui\Process;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Style;
use Pig\Tui\Terminal;
use Pig\Tui\TUI;
use Pig\Tui\TuiMainScreen;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiStopOptions;
use Pig\Tui\TuiBase;
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

    /**
     * Upstream's `ui`: what components hold. It forwards to `$renderer`, so it stays right when
     * `switchTuiMode()` replaces the renderer.
     */
    private readonly TUI $tui;

    /** Upstream's `renderer`: the `TuiMainScreen` or `TuiAltScreen` drawing right now. */
    private TuiBase $renderer;

    /** Upstream's `mainScreenRenderState`: what the regular renderer had on screen when it was left. */
    private ?array $mainScreenRenderState = null;

    /** The one component tree both renderers mount — upstream's containers in `init()`. */
    private ?Container $documentContainer = null;

    /** @var list<Component> */
    private array $mountedComponents = [];

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
    private readonly Container $widgetsAbove;
    private readonly Container $widgetsBelow;
    private readonly Container $customHeader;
    private readonly Container $customFooter;
    private ?string $customWorkingMessage = null;

    /** @var array{frames?: list<string>, intervalMs?: int}|null upstream's `workingIndicatorOptions` */
    private ?array $workingIndicatorOptions = null;
    private ?string $hiddenThinkingLabel = null;

    /** The prompt as it stands: the default, or what an extension put in its place. */
    private CustomEditor $editor;

    private readonly CustomEditor $defaultEditor;

    /** Upstream's `editorContainer`: the dock slot the prompt sits in, so it can be swapped. */
    private readonly Container $editorSlot;

    /** @var (Closure(TUI, EditorTheme, Keybindings): CustomEditor)|null upstream's `editorComponentFactory` */
    private ?Closure $editorFactory = null;

    /** @var list<Closure(AutocompleteProvider): AutocompleteProvider> upstream's `autocompleteProviderWrappers` */
    private array $autocompleteWrappers = [];

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

    /**
     * Upstream's `outputPad`: the blank column either side of a message, a tool's output and a
     * command's, 1 or 0 — the `outputPad` setting. Read once at start and again from `/settings`.
     */
    private int $outputPad = 1;

    /** @var (Closure(string, array<string, mixed>): string)|null */
    private ?Closure $mermaidTransformer = null;

    /** The easter egg, while it is animating, so its frame timer can be stopped. */
    private ?ArminComponent $armin = null;

    /** The editor's text starts with `!`, so it is a command and not a prompt. */
    private bool $bashMode = false;

    private float $lastCtrlC = 0.0;

    private bool $running = false;

    /**
     * What the program exits with — 1 after `fatal()`, upstream's `handleFatalRuntimeError()`'s
     * `process.exit(1)`; `bin/pig` reads it once the loop has ended.
     */
    private int $exitCode = 0;

    /**
     * Set while `bin/pig`'s runtime factory builds the session for another directory: the old one
     * has been shut down and disposed by then, so a failure there leaves nothing to carry on with.
     */
    private bool $replacingRuntime = false;

    /** Upstream's `themeController`: applies the theme setting and keeps it in sync with the terminal. */
    private readonly InteractiveThemeController $themeController;

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

    /** Reports working, blocked, done, and error states to terminals that support OSC 7501. */
    private readonly ProgramStatusReporter $programStatus;

    /** @var array<string, RegisteredCommand> what the hooks added, by name */
    private array $hookCommands = [];

    /** What was chosen last time, and where the choices made here are remembered. */
    private Settings $settings;

    /** Upstream's `builtInHeader`: logo, version and key hints, with the full key list behind ctrl+o. */
    private ?ExpandableText $builtInHeader = null;

    /** Upstream's `loadedResourcesContainer`: one section per kind of loaded resource, under the header. */
    private readonly Container $loadedResourcesContainer;

    /**
     * What the last `/reload` found wrong with the skills, for upstream's `[Skill conflicts]` block.
     * Startup's go to standard error before the screen exists (see `CodingAgent`), so this starts empty.
     *
     * @var list<array{type: 'error'|'warning', message: string, path: string}>
     */
    private array $skillDiagnostics = [];

    /**
     * Likewise for hooks, extensions and custom tools, for upstream's `[Extension issues]` block.
     *
     * @var list<array{type: 'error'|'warning', message: string, path: string}>
     */
    private array $extensionDiagnostics = [];

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

    private ?ChatViewport $viewport = null;

    private string $tuiMode = 'fullscreen';

    /**
     * @param list<string>                $initialMessages said before the first keystroke, in order
     * @param list<\Pig\Ai\ImageContent> $initialImages   attachments for the first of them
     */
    public function __construct(
        private AgentSession $session,
        private string $cwd,
        private readonly string $version,
        // A theme setting that overrides the saved one for this run (`--theme`), or null to read
        // the settings. Upstream's controller takes the same thing as `initialThemeSetting`.
        ?string $themeSetting = null,
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
        private bool $projectTrusted = true,
        // Which key means which action — `keybindings.json`'s answer, or the defaults.
        private readonly Keybindings $keybindings = new Keybindings(),
        // Upstream's `InteractiveModeOptions.tuiMode` (`--tui-mode`): beats the setting for this
        // run. Null reads it.
        ?string $tuiMode = null,
        // Upstream's `verbose` (`--verbose`): the startup header and details whatever `quietStartup` says.
        private readonly bool $verbose = false,
        // Upstream's runtime factory (`bin/pig`'s `$createRuntime`): a session built for another
        // working directory, when one from another project is resumed. Null switches in place.
        private readonly ?Closure $createRuntime = null,
    ) {
        // Upstream's constructor does the same: the TUI reads `tui.*` keys from the registry.
        TuiKeybindings::setKeybindings($this->keybindings->tuiKeybindings());
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
        $this->outputPad = $this->settings->outputPad();
        $this->clipboard = $clipboard ?? new SystemClipboard();

        // Upstream's `options.tuiMode ?? settingsManager.getTuiMode()`: no environment variable.
        if ($tuiMode !== null) {
            $this->tuiMode = $tuiMode;
        } elseif ($terminal !== null && !$terminal instanceof ProcessTerminal) {
            $this->tuiMode = 'regular';
        } else {
            $this->tuiMode = $this->settings->tuiMode();
        }

        // Injected so a test can drive this without a terminal, the same way the editor
        // takes its clipboard: everything below here is arrangement, and arrangement is
        // exactly what is worth testing.
        Themes::setCodeBlockIndent($this->settings->codeBlockIndent());
        $this->renderer = $this->createRenderer($terminal ?? new ProcessTerminal(), $this->tuiMode, $this->settings->showHardwareCursor());
        $this->renderer->setClearOnShrink($this->settings->clearOnShrink());
        $this->tui = TuiRenderer::createInteractiveTuiReference(fn (): TuiBase => $this->renderer);
        // Upstream installs the theme JSON validator in its interactive mode, so a custom theme that
        // is missing tokens is rejected with the reason instead of drawing in half its colours.
        Themes::setThemeJsonValidator(ThemeJson::validateThemeJson(...));
        $this->themeController = new InteractiveThemeController(
            $this->tui,
            fn (): Settings => $this->settings,
            fn (string $message) => $this->sayError($message),
            fn () => $this->paintBorder(),
            $themeSetting,
        );
        $this->chat = new Container();
        $this->pending = new Container();
        $this->status = new Container();
        $this->overlay = new Container();
        $this->widgetsAbove = new Container();
        $this->widgetsBelow = new Container();
        $this->customHeader = new Container();
        $this->loadedResourcesContainer = new Container();
        $this->customFooter = new Container();
        $this->defaultEditor = new CustomEditor(new Editor(Themes::getEditorTheme()), $this->keybindings);
        $this->defaultEditor->setPaddingX($this->settings->editorPaddingX());
        $this->defaultEditor->setAutocompleteMaxVisible($this->settings->autocompleteMaxVisible());
        $this->editor = $this->defaultEditor;
        $this->editorSlot = new Container();
        $this->editorSlot->addChild($this->editor);
        // `$this->settings` rather than the argument: with none given it is the in-memory one
        // that `/settings` writes to, and the footer has to read what that screen changes.
        $this->footer = new FooterComponent($session, $cwd, $this->settings, $auth);

        $this->programStatus = new ProgramStatusReporter(
            fn (): Terminal => $this->renderer->terminal,
            fn (): ?string => $this->session->sessionName(),
        );

        $this->ui = new TerminalUi(
            $this->tui,
            $this->chat,
            // The overlay, not the status area: a dialog holds the focus, so it belongs
            // where only the thing that opened it clears.
            $this->overlay,
            fn (): CustomEditor => $this->editor,
            $this->footer,
            // The same `$VISUAL` hand-off Ctrl+G at the prompt uses, so the key means one
            // thing in both places and there is one piece of code to get right.
            fn (string $text): ?string => $this->externalEditor($text),
            $this->keybindings,
            $this->widgetsAbove,
            $this->widgetsBelow,
            $this->customHeader,
            $this->customFooter,
            // Extension dialogs share one slot: opening one replaces the status of a displaced one.
            onBlocked: fn (?BlockedStatus $blocked) => $this->programStatus->setBlocked('extension-dialog', $blocked),
            addAutocompleteProvider: fn (Closure $factory) => $this->addAutocompleteProvider($factory),
            setEditorComponent: fn (?Closure $factory) => $this->setCustomEditorComponent($factory),
            getEditorComponent: fn (): ?Closure => $this->editorFactory,
            onWorkingMessage: function (?string $message): void {
                $this->customWorkingMessage = $message;
                if ($this->working !== null) {
                    $this->working->setText($message ?? 'Working');
                    $this->tui->requestRender();
                }
            },
            onWorkingIndicator: function (?array $options): void {
                $this->workingIndicatorOptions = $options;
                $this->working?->setIndicator($options);
                $this->tui->requestRender();
            },
            onWorkingVisible: function (bool $visible): void {
                if ($visible) {
                    $this->showLoader($this->customWorkingMessage ?? 'Working', timer: true);
                } else {
                    $this->hideLoader();
                }
            },
            onHiddenThinkingLabel: function (?string $label): void {
                $this->hiddenThinkingLabel = $label;
                foreach ($this->chat->children() as $child) {
                    if ($child instanceof AssistantMessageComponent) {
                        $child->setHiddenThinkingLabel($label);
                    }
                }
                $this->tui->requestRender();
            },
            getToolsExpanded: fn (): bool => $this->expanded,
            setToolsExpanded: fn (bool $expanded) => $this->setToolsExpanded($expanded),
            setTheme: function (string|Theme $themeOrName): array {
                if ($themeOrName instanceof Theme) {
                    return $this->themeController->setThemeInstance($themeOrName);
                }
                $result = $this->themeController->setThemeName($themeOrName);
                if ($result['success']) {
                    if ($this->settings->theme() !== $themeOrName) {
                        $this->settings->setTheme($themeOrName);
                    }
                }

                return $result;
            },
        );

        $this->wireSession();
    }

    /**
     * The session's mode and shutdown request, and its hooks and custom tools onto this screen —
     * part of upstream's `rebindSession()`, run by the constructor and again by `rebind()`.
     */
    private function wireSession(): void
    {
        $session = $this->session;
        $hooks = $this->hooks;
        $customTools = $this->customTools;
        $cwd = $this->cwd;

        // What a handler's `$ctx->mode()` answers, and what `$ctx->shutdown()` does here: quit,
        // on the next tick rather than from inside whichever handler asked, because stopping
        // the TUI from inside a callback it is running is the thing `stop()` cannot survive.
        $session->setMode('tui');
        $session->onShutdownRequest(function (): void {
            Loop::get()->defer(fn () => $this->stop());
        });

        $hooks?->wire($session, $this->ui);

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

    /** The session's events onto this screen — the other part of `rebindSession()`. */
    private function listenToSession(): void
    {
        $this->session->onSessionNameChanged(function (): void {
            // The session name is part of working and done reports.
            $this->programStatus->report();
            $this->tui->requestRender();
        });
        $this->session->onSessionSwitched(fn () => $this->programStatus->reset());
        $this->session->subscribe($this->onEvent(...));
        $this->session->enableCacheWarming();
    }

    /** Wire everything up and draw the first frame. */
    public function start(): void
    {
        // Suppress raw console log outputs in TUI to prevent screen corruption; logs continue writing to file
        Logger::setConsoleOutput(false);

        $this->layout();
        $this->bindKeys();
        $this->bindEditor();
        $this->paintBorder();
        $this->reportLoopFailures();
        $this->watchCommandKey();
        $this->listenToSession();

        $this->replay();
        $this->sayIfStoppedMidRun();

        // After the replay, so an upgrade note sits under the conversation it is about rather
        // than above it. `bin/pig` is what decides there is one: it knows the version, holds the
        // settings the last-seen number is written to, and knows whether this is a resumed
        // session — where a release note nobody asked for is an interruption.
        if ($this->changelog !== null && $this->changelog !== '') {
            $this->settings->collapseChangelog() ? $this->sayChangelogCollapsed($this->changelog) : $this->sayChangelog($this->changelog);
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

        $this->maybeWarnAboutAnthropicSubscriptionAuth();

        $this->running = true;
        $this->hooks?->emit(new SessionStartEvent());
        $this->takeExtensionResources($this->session->discoverResources('startup'));
        $this->sayToolProblems($this->customTools?->notify('start') ?? []);
        $this->tui->start();
        $this->programStatus->report();

        // Upstream's `applyFromSettings()` once the terminal is up, since the colour query's replies
        // arrive as input. Everything above was drawn in the theme the controller started with (the
        // system theme renders grayscale until the terminal reports its colours); when they arrive,
        // the controller invalidates every component, and the banner and the chat's lines, being
        // `ThemedText`, rebuild in the new colours. `onChanged` repaints the editor's border, as
        // upstream's `updateEditorBorderColor()`.
        $this->themeController->applyFromSettings();

        // Upstream's `run()`: every provider with a fetched catalog asked for it in the background,
        // once the UI is up, unless `PIG_OFFLINE` says no network.
        if ($this->auth !== null && ModelRefresh::networkEnabled()) {
            ModelRefresh::inBackground($this->auth, done: fn () => $this->tui->requestRender());
        }

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
        // Upstream's `renderSessionItems()`: a miss is not written down, unlike a warming refresh,
        // so it is worked out again from the file and drawn after the message that paid for it.
        $misses = $this->settings->showCacheMissNotices()
            ? CacheStats::collect($this->session->store()?->everyMessage() ?? $this->session->messages())
            : null;

        foreach ($this->session->messages() as $message) {
            if ($message instanceof UserMessage) {
                $text = self::textOf($message);
                // Upstream's `populateHistory`: Up brings back what was asked in this session.
                $this->editor->addToHistory($text);
                $skillBlock = \Pig\CodingAgent\Prompt\SkillBlock::parse($text);
                if ($skillBlock !== null) {
                    $expandKey = $this->keybindings->display('app.tools.expand') ?: 'ctrl+o';
                    $this->chat->addChild(new SkillInvocationMessageComponent(
                        $skillBlock,
                        $this->expanded,
                        $expandKey,
                        $this->outputPad,
                    ));
                    if ($skillBlock->userMessage !== null) {
                        $this->chat->addChild(new UserMessageComponent(
                            $skillBlock->userMessage,
                            $this->markdownTransformers(),
                            $this->outputPad,
                        ));
                    }

                    continue;
                }

                $this->chat->addChild(new UserMessageComponent(
                    $text,
                    $this->markdownTransformers(),
                    $this->outputPad,
                ));

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
                $this->chat->addChild(new CompactionComponent($message, $this->expanded, $this->outputPad, $this->keybindings->keyText('app.tools.expand')));
                $this->addSummaryCostNotice($message);

                continue;
            }

            if ($message instanceof BranchSummary) {
                $this->chat->addChild(new BranchSummaryComponent($message, $this->expanded, $this->outputPad, $this->keybindings->keyText('app.tools.expand')));
                $this->addSummaryCostNotice($message);

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $this->chat->addChild(new AssistantMessageComponent(
                    $message,
                    $this->hideThinking,
                    $this->markdownTransformers(),
                    $this->hiddenThinkingLabel,
                    $this->outputPad,
                ));

                foreach ($message->content as $block) {
                    if ($block instanceof ToolCall) {
                        $tools[$block->id] = $this->addTool($block->id, $block->name, $block->arguments);
                    }
                }

                if ($misses !== null && isset($misses[$message]) && !in_array($message->stopReason, [StopReason::Aborted, StopReason::Error], true)) {
                    $this->addCacheMissNotice($misses[$message]);
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
        // and one still showing as running would never stop — except the calls a stopped run
        // left without a result, which `/continue` answers into the rows drawn here.
        $unanswered = AgentLoop::unansweredToolCalls($this->session->messages());
        $this->tools = $unanswered === null
            ? []
            : array_intersect_key($tools, array_flip(array_map(static fn (ToolCall $call): string => $call->id, $unanswered[1])));

        // Upstream's `registerEntryRenderer`: if a hook or extension registered a custom
        // renderer for `CustomEntry` entries stored in the session tree, render them into the chat.
        $entryRenderers = $this->hooks?->entryRenderers() ?? [];
        if ($entryRenderers !== []) {
            foreach ($this->session->store()?->customEntries() ?? [] as $entry) {
                if (isset($entryRenderers[$entry->customType])) {
                    $comp = ($entryRenderers[$entry->customType])(
                        $entry,
                        new \Pig\CodingAgent\CustomTools\RenderOptions($this->expanded, false),
                        Themes::theme(),
                    );
                    if ($comp instanceof \Pig\Tui\Component) {
                        $this->chat->addChild($comp);
                    }
                }
            }
        }
    }

    private function replayBash(BashExecution $execution): void
    {
        // Upstream's resume path: the component, the whole output, then `setComplete()`.
        $shown = $this->bashExecutionComponent($execution->command, excludeFromContext: $execution->excludeFromContext);
        $shown->appendOutput($execution->output);
        $shown->setComplete($execution->exitCode, $execution->cancelled, $execution->truncated, $execution->fullOutputPath);

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
    public function screen(): TuiBase
    {
        return $this->renderer;
    }

    /** @internal for tests */
    public function viewport(): ?ChatViewport
    {
        return $this->viewport;
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
        $this->terminalProgress(false);
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
        $this->hooks?->emit(new SessionShutdownEvent('quit'));

        // Nothing is drawn after this, so a tool that fails while letting go is reported
        // to stderr — the transcript is a moment away from being scrolled off.
        foreach ($this->customTools?->notify('shutdown') ?? [] as $problem) {
            fwrite(STDERR, "tool {$problem->toText()}\n");
        }

        $this->session->dispose();

        // Before the terminal is handed back: turning colour-scheme reports off is a write to it.
        $this->themeController->dispose();
        Themes::stopThemeWatcher();

        // Stopped here and not only in run()'s finally: whoever calls this wants the
        // terminal back — raw mode off, cursor shown — whether or not the loop is what
        // they are waiting on.
        // Upstream's way out of fullscreen: with `fullscreenExitOutput` at `transcript` the tree
        // moves to a regular renderer that draws the conversation into the normal screen; with
        // `resume-hint` the alternate screen is just left, and `bin/pig` prints the hint after
        // this either way.
        $this->stopInteractiveTui($this->settings->fullscreenExitOutput());
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

    public function setToolsExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->builtInHeader?->setExpanded($this->expanded);
        foreach ($this->loadedResourcesContainer->children() as $child) {
            if ($child instanceof ExpandableText) {
                $child->setExpanded($this->expanded);
            }
        }

        foreach ($this->chat->children() as $child) {
            if ($child instanceof ToolExecutionComponent
                || $child instanceof BashExecutionComponent
                || $child instanceof CompactionComponent
                || $child instanceof BranchSummaryComponent
                || $child instanceof HookMessageComponent
                || $child instanceof SkillInvocationMessageComponent
            ) {
                $child->setExpanded($this->expanded);
            }
        }

        $this->tui->requestRender();
    }

    private function layout(): void
    {
        // Upstream's `BuiltInHeader`: built on demand, so it follows theme changes. It reads the
        // key bindings, which change only on `/reload`, and that invalidates it.
        $headerContainer = new Container();

        // `quietStartup: true` silences the header too; upstream mounts an empty text in its place.
        if ($this->shouldShowStartupHeader()) {
            $this->builtInHeader = new ExpandableText(
                fn (): string => $this->builtInHeaderText(expanded: false),
                fn (): string => $this->builtInHeaderText(expanded: true),
                $this->expanded,
                1,
                0,
            );
            $headerContainer->addChild(new Spacer(1));
            $headerContainer->addChild($this->builtInHeader);
            $headerContainer->addChild(new Spacer(1));
        }

        // One component tree for both renderers, as upstream keeps it: regular mode mounts these
        // in order, fullscreen mounts the same ones and draws the `ChatViewport` laid over them.
        // Upstream's `headerContainer`, `loadedResourcesContainer` and `chatContainer`, with pig's
        // extension header (upstream swaps it in for the built-in one) above them.
        $document = new Container();
        $document->addChild($this->customHeader);
        $document->addChild($headerContainer);
        $document->addChild($this->loadedResourcesContainer);
        $document->addChild($this->chat);
        $this->documentContainer = $document;
        $this->showLoadedResources(force: false, showDiagnosticsWhenQuiet: true);

        // Upstream's dock slots: status, widgets above (with the spacer over the editor), editor,
        // widgets below, footer. pig's in-dock dialogs ride with the status — below what is
        // happening and directly above the editor, because it is the thing being answered — and
        // its extension footer with the footer.
        $statusSlot = new Container();
        $statusSlot->addChild($this->status);
        $statusSlot->addChild($this->overlay);
        $widgetsAboveSlot = new Container();
        $widgetsAboveSlot->addChild($this->widgetsAbove);
        $widgetsAboveSlot->addChild(new Spacer(1));
        $footerSlot = new Container();
        $footerSlot->addChild($this->footer);
        $footerSlot->addChild($this->customFooter);

        $this->viewport = ChatViewport::create(
            document: $document,
            pendingMessages: $this->pending,
            status: $statusSlot,
            editor: $this->editorSlot,
            footer: $footerSlot,
            widgetsAbove: $widgetsAboveSlot,
            widgetsBelow: $this->widgetsBelow,
            scrollbar: $this->settings->fullscreenScrollbar(),
            scrollbarTrackStyle: fn (string $text): string => Themes::theme()->fg('scrollbarTrack', $text),
            scrollbarThumbStyle: fn (string $text): string => Themes::theme()->fg('scrollbarThumb', $text),
        );

        $this->mountedComponents = [$document, $this->pending, $statusSlot, $widgetsAboveSlot, $this->editorSlot, $this->widgetsBelow, $footerSlot];
        $this->mountInteractiveTui($this->renderer, $this->mountedComponents);
        $this->tui->setFocus($this->editor);
    }

    /** Upstream's `mountInteractiveTui()`. */
    private function mountInteractiveTui(TuiBase $tui, array $components): void
    {
        foreach ($components as $component) {
            $tui->addChild($component);
        }
        if ($tui instanceof TuiAltScreen) {
            $tui->setLayoutRoot($this->viewport?->root ?? throw new \LogicException('Fullscreen layout is not initialized'));
        }
    }

    /** Upstream's `createInteractiveTui()` call, with this session's settings. */
    private function createRenderer(Terminal $terminal, string $mode, bool $showHardwareCursor): TuiBase
    {
        return TuiRenderer::createInteractiveTui(
            $terminal,
            $mode,
            $showHardwareCursor,
            $this->clipboard,
            $this->scrollToEndIndicator(...),
            self::openInBrowser(...),
            $this->settings->fullscreenCopyOnSelect(),
            $this->settings->fullscreenWheelScrollLines(),
        );
    }

    /**
     * Upstream's `switchTuiMode()`: move the same component tree onto a new renderer on the same
     * terminal. Refused while an overlay is shown, which upstream says in the status line.
     *
     * @param 'regular'|'fullscreen' $mode
     */
    private function switchTuiMode(string $mode, bool $startRenderer = true): bool
    {
        $previousUi = $this->renderer;
        if ($mode === $previousUi->mode) {
            return true;
        }
        if ($previousUi->hasOverlayEntries()) {
            return false;
        }

        $components = $previousUi->children();
        $focus = $previousUi->getFocusedComponent();
        $terminal = $previousUi->terminal;
        $showHardwareCursor = $previousUi->getShowHardwareCursor();
        $clearOnShrink = $previousUi->getClearOnShrink();
        $onDebug = $previousUi->onDebug;
        if ($previousUi instanceof TuiMainScreen) {
            $this->mainScreenRenderState = $previousUi->captureRenderState();
        }

        $previousUi->stop(new TuiStopOptions(preserveScreen: true));
        $previousUi->setFocus(null);
        $previousUi->clear();
        if ($previousUi instanceof TuiAltScreen) {
            $previousUi->setLayoutRoot(null);
        }

        $nextUi = $this->createRenderer($terminal, $mode, $showHardwareCursor);
        $nextUi->setClearOnShrink($clearOnShrink);
        $nextUi->onDebug = $onDebug;
        if ($nextUi instanceof TuiMainScreen && $this->mainScreenRenderState !== null) {
            $nextUi->restoreRenderState($this->mainScreenRenderState);
        }
        $this->renderer = $nextUi;
        $this->tuiMode = $mode;
        $this->mountInteractiveTui($nextUi, $components);
        $nextUi->invalidate();
        $nextUi->setFocus($focus);
        if (!$startRenderer) {
            return true;
        }
        $nextUi->start();
        $this->ui->rebindTerminalInputListeners();
        $this->themeController->rebindTui();
        // Stopping the old renderer stopped the terminal, which cleared the tab's progress.
        if ($this->session->isStreaming() || $this->session->isCompacting()) {
            $this->terminalProgress(true);
        }

        return true;
    }

    /** Upstream's `stopInteractiveTui()`. */
    private function stopInteractiveTui(string $fullscreenExitOutput): void
    {
        if ($this->renderer instanceof TuiAltScreen && $fullscreenExitOutput === 'transcript') {
            while ($this->renderer->hasOverlayEntries()) {
                $this->renderer->hideOverlay();
            }
            $this->switchTuiMode('regular', startRenderer: false);
            $this->renderer->renderNow();
        }
        $this->tui->stop(new TuiStopOptions(preserveScreen: $this->renderer instanceof TuiAltScreen));
    }

    private function scrollToEndIndicator(): string
    {
        $shortcut = $this->keybindings->display('tui.altScreen.bottom') ?: 'Ctrl+End';
        $label = " ↓ Jump to latest message" . ($shortcut !== '' ? " · {$shortcut}" : '') . ' ';

        return Themes::theme()->bg('selectedBg', Themes::theme()->fg('text', $label));
    }

    /**
     * The lines at the top, and the full list behind ctrl+o. Upstream's `BuiltInHeader` text as
     * its terminals without the logo get it: "Pig vX", then the key hints, each key dim and what it
     * does muted (upstream's `keyHint()`). What was loaded is drawn under it by
     * `showLoadedResources()`, one section each.
     *
     * One line of keys rather than a column of thirteen, which is what upstream settled
     * on: the list was taller than most of the conversations it sat above. The rest is
     * still there, one key away, for the session where someone needs it.
     */
    private function builtInHeaderText(bool $expanded): string
    {
        $theme = Themes::theme();
        $summaryStr = implode($theme->fg('muted', ' · '), array_map(
            static fn (array $hint): string => $theme->fg('dim', $hint[0]) . $theme->fg('muted', " {$hint[1]}"),
            self::SUMMARY,
        ));

        $lines = [
            PigLogo::wordmark() . ' ' . $theme->fg('dim', "v{$this->version}"),
            $summaryStr,
        ];

        $onboarding = Themes::theme()->fg('dim', 'Pig can explain its own features and look up its docs. Ask it how to use or extend Pig.');

        if (!$expanded) {
            return implode("\n", [
                ...$lines,
                Themes::theme()->fg('dim', 'Press ctrl+o to show full startup help' . ($this->shouldShowStartupDetails() ? ' and loaded resources' : '') . '.'),
                '',
                $onboarding,
            ]);
        }

        return implode("\n", [
            ...$lines,
            '',
            ($this->keysAndCommands())(),
            '',
            $onboarding,
        ]);
    }

    /**
     * Upstream's `showLoadedResources()`: what was loaded into this session, one `ExpandableText`
     * per section under the header, then a warning block per kind of resource that had problems.
     * Idempotent — it clears the container first — so `/reload` calls it again to rebuild.
     *
     * Collapsed, the sections are compact lists; ctrl+o expands them with the header.
     */
    private function showLoadedResources(bool $force = false, bool $showDiagnosticsWhenQuiet = false): void
    {
        // Resource rendering is idempotent; chat clears no longer clear this separate container.
        $this->loadedResourcesContainer->clear();

        $showListing = $force || $this->shouldShowStartupDetails();
        $showDiagnostics = $showListing || $showDiagnosticsWhenQuiet;

        $sectionHeader = static fn (string $name): string => Themes::theme()->fg('mdHeading', "[{$name}]");
        // Bodies are built on demand so the listing follows theme changes.
        $addLoadedSection = function (string $name, Closure $collapsedBody, ?Closure $expandedBody = null) use ($sectionHeader): void {
            $expandedBody ??= $collapsedBody;
            $section = new ExpandableText(
                static fn (): string => $sectionHeader($name) . "\n" . $collapsedBody(),
                static fn (): string => $sectionHeader($name) . "\n" . $expandedBody(),
                $this->expanded,
                0,
                0,
            );
            $this->loadedResourcesContainer->addChild($section);
            $this->loadedResourcesContainer->addChild(new Spacer(1));
        };

        if ($showListing) {
            $contextFiles = $this->contextFiles;
            if ($contextFiles !== []) {
                $this->loadedResourcesContainer->addChild(new Spacer(1));
                $contextList = fn (bool $compact): string => Themes::theme()->fg('muted', '  ' . implode(', ', array_unique(array_map(
                    fn (ContextFile $file): string => $compact ? basename($file->path) : $this->formatDisplayPath($file->path),
                    $contextFiles,
                ))));
                $addLoadedSection('Context', static fn (): string => $contextList(true), static fn (): string => $contextList(false));
            }

            $skillNames = array_map(static fn (Skill $skill): string => $skill->name, $this->skills);
            if ($skillNames !== []) {
                $addLoadedSection('Skills', static fn (): string => Themes::theme()->fg('muted', '  ' . implode(', ', $skillNames)));
            }

            $templateNames = array_map(static fn (FileCommand $command): string => "/{$command->name}", $this->fileCommands);
            if ($templateNames !== []) {
                sort($templateNames);
                $addLoadedSection('Prompts', static fn (): string => Themes::theme()->fg('dim', '  ' . implode(', ', $templateNames)));
            }

            $extensions = $this->discoveredExtensions();
            if ($extensions !== []) {
                $addLoadedSection('Extensions', static fn (): string => Themes::theme()->fg('muted', '  ' . implode(', ', $extensions)));
            }

            // pig's own: custom tools are loaded from their own folders, which upstream has no
            // equivalent of — its tools come from extensions. Only those: an extension's tools are
            // the extension's, named under [Extensions] (or not at all, for a built-in), as
            // upstream lists no tools.
            $toolNames = $this->toolsFromToolFolders();
            if ($toolNames !== []) {
                $addLoadedSection('Tools', static fn (): string => Themes::theme()->fg('muted', '  ' . implode(', ', $toolNames)));
            }
        }

        if ($showDiagnostics) {
            $skillDiagnostics = $this->skillDiagnostics;
            if ($skillDiagnostics !== []) {
                $warningLines = fn (): string => $this->formatDiagnostics($skillDiagnostics);
                $this->loadedResourcesContainer->addChild(
                    new ThemedText(static fn (): string => Themes::theme()->fg('warning', '[Skill conflicts]') . "\n" . $warningLines(), 0, 0),
                );
                $this->loadedResourcesContainer->addChild(new Spacer(1));
            }

            $extensionDiagnostics = $this->extensionDiagnostics;
            if ($extensionDiagnostics !== []) {
                $warningLines = fn (): string => $this->formatDiagnostics($extensionDiagnostics);
                $this->loadedResourcesContainer->addChild(
                    new ThemedText(static fn (): string => Themes::theme()->fg('warning', '[Extension issues]') . "\n" . $warningLines(), 0, 0),
                );
                $this->loadedResourcesContainer->addChild(new Spacer(1));
            }
        }
    }

    /**
     * Upstream's `shouldShowStartupHeader()`: hidden only by `quietStartup: true`, unless `--verbose`.
     */
    private function shouldShowStartupHeader(): bool
    {
        return $this->verbose || $this->settings->quietStartup() !== true;
    }

    /** Upstream's `shouldShowStartupDetails()`: the loaded resources, hidden by `quietStartup: true` or `header`. */
    private function shouldShowStartupDetails(): bool
    {
        return $this->verbose || $this->settings->quietStartup() === false;
    }

    /**
     * Upstream's `formatDiagnostics()`, for the diagnostics pig has: each with its path. pig's
     * loaders report no name collisions, so upstream's grouped `"name" collision:` form has nothing
     * to draw.
     *
     * @param list<array{type: 'error'|'warning', message: string, path: string}> $diagnostics
     */
    private function formatDiagnostics(array $diagnostics): string
    {
        $lines = [];
        foreach ($diagnostics as $d) {
            $color = $d['type'] === 'error' ? 'error' : 'warning';
            $lines[] = Themes::theme()->fg($color, '  ' . $this->formatDisplayPath($d['path']));
            $lines[] = Themes::theme()->fg($color, "    {$d['message']}");
        }

        return implode("\n", $lines);
    }

    /**
     * The custom tools that came from a tool folder rather than from an extension.
     *
     * @return list<string>
     */
    private function toolsFromToolFolders(): array
    {
        $fromExtensions = [];
        foreach ($this->extensions as $ext) {
            $fromExtensions[$ext->resolved] = true;
        }

        $names = [];
        foreach ($this->customTools?->loaded() ?? [] as $one) {
            if (!isset($fromExtensions[$one->resolvedPath])) {
                $names[] = $one->tool->name;
            }
        }

        return $names;
    }

    /**
     * Labels of loaded hooks and extensions from standard locations — not the built-ins, which
     * upstream marks `hidden` and leaves out of [Extensions] (`BuiltinExtensions`).
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

    /** The keys worth knowing before the first prompt, on one line: key, then what it does. */
    private const array SUMMARY = [
        ['escape', 'interrupt'],
        ['ctrl+c/ctrl+d', 'clear/exit'],
        ['/', 'commands'],
        ['!', 'bash'],
        ['ctrl+o', 'more'],
    ];

    /** @var array<string, string> */
    /**
     * The keys the application binds, by action where `keybindings.json` can move them and by
     * key where it cannot (shift+ctrl+d belongs to the terminal layer, `/` `@` `!` to
     * the prompt). `keys()` turns this into the table the banner and `/help` print, with the
     * key each action is *actually* on — a help screen that names ctrl+o for a key somebody
     * moved to ctrl+e is a help screen that lies.
     */
    private const array KEYS = [
        'app.interrupt' => 'interrupt the agent',
        'app.clear' => 'clear the prompt, twice to exit',
        'app.exit' => 'exit from an empty prompt',
        'app.suspend' => 'suspend',
        'app.clipboard.pasteImage' => 'paste, including an image from the clipboard',
        'app.editor.external' => 'edit the prompt in $VISUAL or $EDITOR',
        'app.message.copy' => 'copy the selection in fullscreen, otherwise the last answer',
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
        'tui.input.submit' => 'send',
        'tui.input.newLine' => 'a new line',
        'tui.editor.cursorUp' => 'the previous thing you said, from an empty prompt',
        'tui.input.tab' => 'complete a path, or take what is offered',
        'tui.editor.cursorLineStart' => 'start of the line',
        'tui.editor.cursorLineEnd' => 'end of the line',
        'tui.editor.cursorWordLeft' => 'back a word',
        'tui.editor.cursorWordRight' => 'on a word',
        'tui.editor.deleteWordBackward' => 'delete the word behind',
        'tui.editor.deleteWordForward' => 'delete the word ahead',
        'tui.editor.deleteToLineStart' => 'delete back to the start of the line',
        'tui.editor.deleteToLineEnd' => 'delete on to the end of the line',
        'tui.editor.yank' => 'put back what was last deleted',
        'tui.editor.yankPop' => 'straight after that, the deletion before it instead',
        'tui.editor.undo' => 'undo',
        'tui.editor.jumpForward' => 'jump to the next occurrence of the character typed next',
        'tui.editor.jumpBackward' => 'jump back to the character typed next',
        'tui.editor.historyPrevious' => 'the previous prompt, wherever the cursor is',
        'tui.editor.historyNext' => 'the next prompt, wherever the cursor is',
    ];

    /**
     * `EDITING_KEYS` with the keys each action is on — every key, joined by `/`, because the
     * second is often the one somebody's terminal can send. An action with no key is left out:
     * the dedicated history keys are unbound until a `keybindings.json` gives them one.
     *
     * @return array<string, string> key label => what it does
     */
    private function editingKeys(): array
    {
        $rows = [];

        foreach (self::EDITING_KEYS as $action => $does) {
            $keys = $this->keybindings->keysFor($action);

            if ($keys !== []) {
                $rows[implode('/', $keys)] = $does;
            }
        }

        return $rows;
    }

    // ---- keys ---------------------------------------------------------------------------

    private function bindKeys(): void
    {
        // The renderer has intercepted shift+ctrl+d since it was ported — the predicate, the field and the
        // setter were all there — and nothing ever called the setter, so the one key that works
        // whatever holds the focus did nothing. The same shape as ctrl+p: machinery wired at one
        // end. Not on the editor, because the point of it is that the editor may not be listening.
        $this->renderer->onDebug = $this->writeDebugLog(...);

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
        $this->editor->on('app.message.copy', fn () => $this->copyLastAnswer(flashConfirmation: true, preferSelection: true));
        $this->editor->on('app.message.followUp', $this->followUp(...));
        $this->editor->on('app.message.dequeue', $this->dequeue(...));
        $this->editor->on('app.editor.external', function (): void {
            // In a fiber of its own, the same reason `send()` is: this runs inside the
            // input callback, and it suspends — which would suspend the loop that called it.
            Async::spawn($this->editPromptExternally(...));
        });

        $this->editor->on('app.tools.expand', function (): void {
            $this->setToolsExpanded(!$this->expanded);
        });

        $this->editor->on('app.thinking.toggle', function (): void {
            $this->useHideThinking(!$this->hideThinking);
            $this->say($this->hideThinking ? 'Thinking hidden' : 'Thinking shown');
        });

        // Upstream's four session actions, unbound by default: `/new`, `/tree`, `/fork` and
        // `/resume` on a key for whoever gives them one in `keybindings.json`.
        // Each in a fiber of its own, as the slash commands run: starting a session emits hook
        // events that may suspend, and this is the input callback.
        $this->editor->on('app.session.new', fn () => Async::spawn($this->newSession(...)));
        $this->editor->on('app.session.tree', fn () => Async::spawn(fn () => $this->showTree()));
        $this->editor->on('app.session.fork', fn () => Async::spawn($this->showForkPoints(...)));
        $this->editor->on('app.session.resume', fn () => Async::spawn($this->showSessions(...)));

        // `tui.editor.pageUp`/`pageDown` move by 30% of the terminal, as upstream's do.
        $this->editor->setTerminalRows(fn (): int => $this->tui->terminal->rows());

        foreach ($this->hooks?->shortcuts() ?? [] as $spec) {
            $this->editor->registerShortcut(
                $spec['key'],
                function () use ($spec): void {
                    Async::spawn(function () use ($spec): void {
                        try {
                            ($spec['handler'])($this->hooks->context());
                        } catch (\Throwable $e) {
                            $this->sayError("Shortcut error ({$spec['key']}): {$e->getMessage()}");
                        }
                    });
                },
                $spec['description'],
            );
        }
    }

    /**
     * Show or hide thinking, everywhere it is already drawn as well as from here on.
     *
     * Split out of the ctrl+t handler so `/settings` sets the same thing the same way —
     * two places writing the setting and only one of them telling the components on
     * screen is how a toggle comes to half work.
     */
    /**
     * Upstream's `getMarkdownTransformers()`: the Mermaid diagrams first, then whatever the
     * extensions registered.
     *
     * @return list<Closure(string, array<string, mixed>): string>
     */
    private function markdownTransformers(): array
    {
        $this->mermaidTransformer ??= MermaidTransformer::create(
            fn (): string => $this->settings->mermaidRenderingMode(),
            // The text's room: the terminal less the padding either side of a message.
            fn (): int => max(1, $this->tui->terminal->columns() - 2 * $this->outputPad),
        );

        return [$this->mermaidTransformer, ...($this->hooks?->markdownTransformers() ?? [])];
    }

    /** Upstream's `onMermaidRenderingModeChange`: saved, and every message drawn again with it. */
    private function useMermaidRenderingMode(string $mode): void
    {
        $this->settings->setMermaidRenderingMode(in_array($mode, Settings::MERMAID_MODES, true) ? $mode : 'streaming');
        $this->chat->invalidate();
        $this->tui->requestRender();
    }

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
    /** Upstream's `onAutocompleteMaxVisibleChange`: saved, and on the editor that is up. */
    private function useAutocompleteMaxVisible(int $maxVisible): void
    {
        $this->settings->setAutocompleteMaxVisible($maxVisible);
        $this->defaultEditor->setAutocompleteMaxVisible($maxVisible);

        if ($this->editor !== $this->defaultEditor) {
            $this->editor->setAutocompleteMaxVisible($maxVisible);
        }
    }

    /** Upstream's `onImageWidthCellsChange`: saved, and on every picture already drawn. */
    private function useImageWidthCells(int $width): void
    {
        $this->settings->setImageWidthCells($width);

        foreach ($this->chat->children() as $child) {
            if ($child instanceof ToolExecutionComponent) {
                $child->setImageWidthCells($width);
            }
        }
    }

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
    /** When the last Escape on an empty prompt was pressed, for `doubleEscape()`. */
    private float $lastEscapeAt = 0.0;

    /**
     * Upstream's double-escape: Escape twice within half a second on an empty prompt opens `/tree`,
     * or `/fork`'s list, as `doubleEscapeAction` says — or nothing at all with `none`.
     */
    private function doubleEscape(): void
    {
        $action = $this->settings->doubleEscapeAction();

        if (trim($this->editor->text()) !== '' || $action === 'none') {
            return;
        }

        $now = microtime(true);

        if ($now - $this->lastEscapeAt >= 0.5) {
            $this->lastEscapeAt = $now;

            return;
        }

        $this->lastEscapeAt = 0.0;
        $action === 'tree' ? $this->showTree() : $this->showForkPoints();
    }

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
            $this->doubleEscape();

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
        $command = $this->resolveExternalEditorCommand();

        if ($command === null || trim($command) === '') {
            $this->sayWarning('No editor configured. Set $VISUAL or $EDITOR, or "externalEditor" in settings.json.');

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

    /**
     * Resolves the external editor command (aligned with upstream pi getExternalEditorCommand):
     * 1. `settings.externalEditor` from settings.json
     * 2. $VISUAL or $EDITOR environment variables
     * 3. Fallback: 'notepad' on Windows; 'nano' (or 'vim' / 'vi' if nano not found) on POSIX
     */
    private function resolveExternalEditorCommand(): ?string
    {
        $configured = $this->settings?->externalEditor();
        if ($configured !== null && trim($configured) !== '') {
            return trim($configured);
        }

        $env = getenv('VISUAL') ?: getenv('EDITOR');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return 'notepad';
        }

        foreach (['nano', 'vim', 'vi'] as $candidate) {
            if ($this->isExecutableOnPath($candidate)) {
                return $candidate;
            }
        }

        return 'nano';
    }

    private function isExecutableOnPath(string $binary): bool
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /** ctrl+p, and shift+ctrl+p the other way: the next model along, without opening the list. */
    private const string ANTHROPIC_SUBSCRIPTION_AUTH_WARNING = 'Anthropic subscription auth is active. Third-party harness usage draws from extra usage and is billed per token, not your Claude plan limits. Manage extra usage at https://claude.ai/settings/usage. Disable this warning in /settings.';

    private bool $anthropicSubscriptionWarningShown = false;

    /**
     * Upstream's `maybeWarnAboutAnthropicSubscriptionAuth()`: once a session, when the model is
     * Anthropic's and the way in is a Claude subscription — a sign-in, or an `sk-ant-oat` token —
     * say that the turns are billed as extra usage. Behind `warnings.anthropicExtraUsage`. A key
     * that cannot be looked up says nothing; this is a warning, not a check.
     */
    private function maybeWarnAboutAnthropicSubscriptionAuth(?Model $model = null): void
    {
        $model ??= $this->session->model();

        if (!$this->settings->warnAnthropicExtraUsage() || $this->anthropicSubscriptionWarningShown || $model?->provider !== 'anthropic') {
            return;
        }

        try {
            $subscription = $this->auth?->kind('anthropic') === 'oauth'
                || str_starts_with($this->session->keyFor($model) ?? '', 'sk-ant-oat');
        } catch (Throwable) {
            return;
        }

        if ($subscription) {
            $this->anthropicSubscriptionWarningShown = true;
            $this->sayWarning(self::ANTHROPIC_SUBSCRIPTION_AUTH_WARNING);
        }
    }

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

        $this->maybeWarnAboutAnthropicSubscriptionAuth($choice->model);

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

    /** Upstream's `createBaseAutocompleteProvider()`: the commands, the files, the git changes. */
    private function baseAutocompleteProvider(): AutocompleteProvider
    {
        return new CombinedAutocompleteProvider(
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
                        argumentHint: $command->argumentHint,
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
                // Upstream's skill commands, behind `enableSkillCommands`: the list only, since a
                // `/skill:name` typed by hand is expanded either way.
                ...($this->settings->enableSkillCommands() ? array_map(
                    static fn (Skill $skill): SlashCommand => new SlashCommand("skill:{$skill->name}", $skill->description),
                    $this->skills,
                ) : []),
            ],
            $this->cwd,
            // Only if it is already here. Reaching for the network to draw a completion
            // list is not something a keystroke should do.
            ExternalTool::has('fd') ? ExternalTool::fd() : null,
            $this->modifiedGitFiles(...),
        );
    }

    /**
     * Upstream's `setupAutocompleteProvider()`: the built-in provider, wrapped by every factory an
     * extension added, in the order they were added, and put on the prompt — whichever prompt.
     */
    private function setupAutocompleteProvider(): void
    {
        $provider = $this->baseAutocompleteProvider();

        foreach ($this->autocompleteWrappers as $wrap) {
            $provider = $wrap($provider);
        }

        $this->editor->setAutocompleteProvider($provider);
    }

    /** Upstream's `addAutocompleteProvider()` on the UI context. */
    private function addAutocompleteProvider(Closure $factory): void
    {
        $this->autocompleteWrappers[] = $factory;
        $this->setupAutocompleteProvider();
    }

    /**
     * Upstream's `setCustomEditorComponent()`: swap the prompt for one of the extension's own, or
     * back to the default with null. The text carries over; everything else bound to the prompt —
     * the keys, the handlers, the clipboard, the completions, the loader in its border — is bound
     * again, which is the same two methods startup runs and the one way the new prompt cannot be
     * missing a key the old one had.
     *
     * @param (Closure(TUI, EditorTheme, Keybindings): CustomEditor)|null $factory
     */
    private function setCustomEditorComponent(?Closure $factory): void
    {
        $this->editorFactory = $factory;
        $text = $this->editor->text();
        $editor = $factory === null ? $this->defaultEditor : $factory($this->tui, Themes::getEditorTheme(), $this->keybindings);

        if (!$editor instanceof CustomEditor) {
            throw new \InvalidArgumentException('An editor factory must return a CustomEditor, got ' . get_debug_type($editor));
        }

        $this->editor = $editor;
        // Upstream copies the appearance settings onto an extension's editor.
        $this->editor->setPaddingX($this->settings->editorPaddingX());
        $this->editor->setAutocompleteMaxVisible($this->settings->autocompleteMaxVisible());
        $this->editor->setText($text);
        $this->editorSlot->clear();
        $this->editorSlot->addChild($this->editor);
        $this->bindEditor();
        $this->bindKeys();
        $this->paintBorder();

        if ($this->working !== null) {
            $this->editor->setWorkingStatus($this->working, fn (string $t): string => $this->borderColour()($t));
        }

        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }

    // ---- what the person typed -------------------------------------------------------------

    private function bindEditor(): void
    {
        // Ctrl+V: an image on the clipboard is written to a temp file and its path
        // pasted, which is how a screenshot gets to the model.
        $this->editor->setClipboard($this->clipboard);

        $this->setupAutocompleteProvider();

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
            // means by typing "no, the other file" mid-run. Cmd/Ctrl+Enter is the follow-up, below.
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
     * Cmd/Ctrl+Enter (upstream's Alt+Enter): queue what was typed for **after** the turn —
     * upstream's `handleFollowUp()`.
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
     * `!` puts the result in the conversation, `!!` does not. Both are drawn by
     * `BashExecutionComponent`, as upstream draws them; `!!` in `dim`, which is how the screen
     * says the model will not see it.
     */
    private function runCommand(string $typed): void
    {
        $remember = !str_starts_with($typed, '!!');
        $command = trim(substr($typed, $remember ? 1 : 2));

        if ($command === '') {
            return;
        }

        $this->viewport?->transcript->scrollToEnd();

        if ($this->session->isBashRunning()) {
            $this->sayWarning('A command is already running. Press esc to stop it.');

            return;
        }

        $shown = $this->bashExecutionComponent($command, excludeFromContext: !$remember);
        $this->chat->addChild($shown);
        $this->tui->requestRender();

        Async::spawn(function () use ($command, $remember, $shown): void {
            try {
                // `executeBash()` hands over the output so far, whole; the component takes the
                // piece that is new, as upstream's `appendOutput()` takes a chunk off the pipe.
                $seen = 0;
                $execution = $this->session->executeBash(
                    $command,
                    !$remember,
                    function (string $output) use ($shown, &$seen): void {
                        $shown->appendOutput(substr($output, $seen));
                        $seen = strlen($output);
                        $this->tui->requestRender();
                    },
                );

                // Nothing said for `!!`: the block is drawn in `dim`, which is how upstream says
                // the output stayed out of the conversation.
                $shown->setComplete($execution->exitCode, $execution->cancelled, $execution->truncated, $execution->fullOutputPath);
            } catch (Throwable $error) {
                $shown->appendOutput($error->getMessage());
                $shown->setComplete(1, false);
            }

            $this->footer->invalidate();
            $this->tui->requestRender();
        });
    }

    /**
     * Upstream's `new BashExecutionComponent(command, ui, excludeFromContext, outputPad)`, with the
     * two key names it reads from the global keybindings handed in, and the current expansion.
     */
    private function bashExecutionComponent(string $command, bool $excludeFromContext): BashExecutionComponent
    {
        $shown = new BashExecutionComponent(
            $command,
            $this->tui,
            $excludeFromContext,
            $this->outputPad,
            cancelKey: $this->keybindings->keyText('app.interrupt'),
            expandKey: $this->keybindings->keyText('app.tools.expand'),
        );
        $shown->setExpanded($this->expanded);

        return $shown;
    }

    /** Green while the line is a command, otherwise the thinking level's colour. */
    private function borderColour(): Closure
    {
        return $this->bashMode
            ? Themes::theme()->getBashModeBorderColor()
            : Themes::theme()->getThinkingBorderColor($this->session->thinkingLevel());
    }

    private function paintBorder(): void
    {
        $this->editor->setTheme(new EditorTheme($this->borderColour(), Themes::getSelectListTheme()));
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
        $this->viewport?->transcript->scrollToEnd();

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
            // The threshold check before the turn is the session's (`AgentSession::prompt()`), as
            // upstream's is; its `compaction_start` / `compaction_end` draw the loader and the
            // summary here (`onAutoCompaction()`).
            $this->session->prompt($text, $images);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());
        }
    }

    /**
     * A conversation opened mid-run says so, and how to carry it on — nothing runs on its own
     * (`AgentSession::isStoppedMidRun()`). pig's own.
     */
    private function sayIfStoppedMidRun(): void
    {
        if ($this->session->isStoppedMidRun()) {
            $this->sayWarning('This conversation stopped in the middle of a run. /continue carries it on.');
        }
    }

    /** `/continue`: `AgentSession::continueRun()`, in a fiber of its own as `send()` is. pig's own. */
    private function continueStoppedRun(): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        $this->viewport?->transcript->scrollToEnd();

        Async::spawn(function (): void {
            try {
                if ($this->session->continueRun() === 'finished') {
                    $this->say('The last run had finished; there is nothing to carry on.');
                }
            } catch (Throwable $error) {
                $this->sayError($error->getMessage());
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

        $this->showLoader($this->compactionLabel('manual'), timer: true);
        $this->terminalProgress(true);

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
            $this->terminalProgress(false);
        }

        if ($failure !== null) {
            $this->sayError("Compaction failed: {$failure}");

            return;
        }

        // Upstream's `compaction_end` with `aborted` for the one somebody asked for: an error.
        if ($summary === null) {
            $this->sayError('Compaction cancelled');

            return;
        }

        // The transcript above is left where it is: it is what was said, and the summary
        // is a note about it, not a replacement for anyone's memory of reading it.
        $this->chat->addChild(new CompactionComponent($summary, $this->expanded, $this->outputPad, $this->keybindings->keyText('app.tools.expand')));
        $this->addSummaryCostNotice($summary);
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
        ['scoped-models', 'Enable/disable models for Ctrl+P cycling'],
        ['skills', 'What the model can reach for, and where it came from'],
        ['copy', 'Put the last answer on the clipboard'],
        ['export', 'Export session (HTML default, or specify path: .html/.jsonl; /export md|pr for Markdown or a PR description)'],
        ['import', 'Import and resume a session from a JSONL file'],
        ['share', 'Share session as a secret GitHub gist'],
        ['compact', 'Summarise the conversation so far and carry on from the summary'],
        ['resume', 'Pick up an earlier conversation'],
        ['continue', 'Carry on a run that stopped when pig did'],
        ['tree', 'Go back to an earlier point and take it somewhere else'],
        ['fork', 'Fork the conversation into a new session file from one of your messages'],
        ['clone', 'Copy this conversation into a new session file and carry on there'],
        ['label', 'Name this point, so /tree can find it again — /label with nothing clears it'],
        ['name', 'Show or set the session name'],
        ['diff', 'Show git working tree changes (/diff [--staged])'],
        ['commit', 'Review changes and commit with Conventional Commits message'],
        ['web', 'Browser UI server & daemon (/web [start|stop|status|restart] [port])'],
        ['login', 'Configure provider authentication'],
        ['logout', 'Remove provider authentication'],
        ['theme', 'Switch themes: a picker that previews, or /theme <name>'],
        ['settings', 'Change what is switchable, and see what it is set to'],
        ['changelog', 'What changed, release by release'],
        ['reload', 'Reload extensions, skills, commands, tools, and context files'],
        ['hooks', 'What hooks loaded, and what they added'],
        ['tools', 'What the model can call, built-in and loaded'],
        ['doctor', 'Inspect system health, PHP runtime, binaries, and auth tokens'],
        ['bug', 'Report a bug to the pig developers (/bug [what went wrong])'],
        ['trust', 'Decide whether this project\'s .pig/ hooks, tools and extensions may load'],
        ['quit', 'Quit pig'],
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
        // what the autocomplete offers: `exit` is an alias for `quit`, the name pig listed before
        // it took upstream's, and
        // `arminsayshi` is an easter egg — upstream leaves it out of its own command list too,
        // and something you have to already know about is the whole idea.
        // `debug` is a third: it is for reporting a fault rather than for using pig, and
        // shift+ctrl+d is the route worth advertising — it works whatever holds the focus, which
        // is the whole point of a key that captures the screen.
        if ($name === 'exit' || $name === 'hotkeys' || $name === 'arminsayshi' || $name === 'debug') {
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
            'help', 'hotkeys' => $this->sayHotkeys(),
            'new' => $this->newSession(),
            'session' => $this->say($this->sessionSummary()),
            'compact' => $this->startCompaction(trim(substr($text, strlen($name) + 1))),
            'model' => $this->showModels(trim(substr($text, strlen($name) + 1))),
            'thinking' => $this->handleThinkingCommand(trim(substr($text, strlen($name) + 1))),
            'skills' => $this->say($this->skillList()),
            'copy' => $this->copyLastAnswer(),
            'scoped-models' => $this->showScopedModels(),
            'export' => $this->exportSession(trim(substr($text, strlen($name) + 1))),
            'import' => $this->importSession(trim(substr($text, strlen($name) + 1))),
            'share' => $this->shareSession(),
            'resume' => $this->showSessions(),
            'continue' => $this->continueStoppedRun(),
            'tree' => $this->showTree(),
            'fork' => $this->showForkPoints(),
            'clone' => $this->cloneSession(),
            'label' => $this->label(trim(substr($text, strlen($name) + 1))),
            'name' => $this->handleNameCommand(trim(substr($text, strlen($name) + 1))),
            'diff' => $this->handleDiffCommand(trim(substr($text, strlen($name) + 1))),
            'commit' => $this->handleCommitCommand(trim(substr($text, strlen($name) + 1))),
            'web' => $this->handleWebCommand(trim(substr($text, strlen($name) + 1))),
            'login' => $this->handleLoginCommand(trim(substr($text, strlen($name) + 1))),
            'logout' => $this->showLogoutSelector(),
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

    /**
     * What hooks loaded, where from, and what each one is listening for.
     *
     * @return Closure(): string built from a snapshot, so a theme change recolours it without a
     *                           `/reload` since changing what it lists
     */
    private function hookList(): Closure
    {
        if ($this->hooks === null || $this->hooks->isEmpty()) {
            return static fn (): string => 'No hooks loaded. A .php file in ~/.pig/agent/hooks or .pig/hooks that returns a callable is one.';
        }

        $paths = $this->hooks->paths();
        $commands = array_map(static fn (RegisteredCommand $command): string => $command->description, $this->hookCommands);

        return static function () use ($paths, $commands): string {
            $lines = [];

            foreach ($paths as $path) {
                $lines[] = Themes::theme()->fg('muted', $path);
            }

            if ($commands !== []) {
                $lines[] = '';

                foreach ($commands as $name => $description) {
                    $lines[] = Themes::theme()->fg('dim', Width::pad('/' . $name, 12))
                        . Themes::theme()->fg('muted', $description);
                }
            }

            return implode("\n", $lines);
        };
    }

    /**
     * Every tool the model can call this session, and where each came from.
     *
     * Read off the agent rather than off the two loaders, so what is listed is what the
     * model was actually given — a tool that failed to load is missing here, which is the
     * answer someone typing this is after.
     */
    private function toolList(): Closure
    {
        $custom = [];

        foreach ($this->customTools?->loaded() ?? [] as $one) {
            $custom[$one->tool->name] = $one->path;
        }

        /** @var array<string, string> $tools name => what is shown after it */
        $tools = [];

        foreach ($this->session->state()->tools as $tool) {
            $name = $tool->definition()->name;
            $tools[$name] = $tool->label() . ' · ' . ($custom[$name] ?? 'built-in');
        }

        return static function () use ($tools): string {
            $lines = [];

            foreach ($tools as $name => $detail) {
                $lines[] = Themes::theme()->fg('dim', Width::pad($name, 14))
                    . Themes::theme()->fg('muted', $detail);
            }

            return $lines === [] ? 'This session has no tools at all.' : implode("\n", $lines);
        };
    }

    /** @return Closure(): string the report taken now, drawn in whichever theme is on */
    private function doctorReport(): Closure
    {
        $report = Doctor::inspect($this->session, $this->auth);

        return static fn (): string => Doctor::renderTui($report);
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

        $picker = new SelectList($items, count($items), Themes::getSelectListTheme());
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
        $cwd = $this->cwd;
        $this->overlay->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('muted', "Trust {$cwd}? — this session: {$now}, {$was}"), 1, 0));
        $this->overlay->addChild($picker);
        $this->overlay->addChild(new Spacer(1));

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
        // Upstream's wording.
        $this->say('If this looks like a pig bug, /bug sends a report to the developers.');
    }

    /**
     * `/bug [hint]` — upstream's `reportBug()` (`modes/interactive/bug-report.ts`).
     *
     * What went wrong (the hint, editable), whether to include the transcript, a summary by the
     * current model instead when it is not, then Upload Report / Export as Zip / Cancel. Upload
     * goes to the developer's server (`BugReport::upload()`); when it fails, the zip is offered in
     * its place. Either way the session records that a report was made (`pig.bug-report`) and the
     * crash log is cleared, as upstream's `recordInSession()` does.
     *
     * Spawned because the dialogs park the fiber they are asked on, and this runs inside the loop's
     * own input callback. Escape on any question cancels the whole report.
     */
    private function reportBug(string $hint): void
    {
        Async::spawn(function () use ($hint): void {
            $model = $this->session->model();
            $modelName = $model?->name ?? 'the current model';

            $hint = $this->ui->editor("Report a bug\n\n" . BugReport::DISCLAIMER . "\n\nWhat went wrong? (optional)", $hint);

            if ($hint === null) {
                $this->say('Bug report cancelled');

                return;
            }

            $transcript = $this->ui->select("Include the session transcript?\n\n" . BugReport::TRANSCRIPT_NOTE, ['Yes, include the transcript', 'No']);

            if ($transcript === null) {
                $this->say('Bug report cancelled');

                return;
            }

            $includeSession = $transcript !== 'No';
            $includeSummary = false;

            if (!$includeSession) {
                $provider = $model?->provider ?? 'your provider';
                $choice = $this->ui->select(
                    "Attach a summary written by {$modelName} instead?\n\n"
                    . "The transcript is sent to {$provider} with your credentials and tokens. Only the generated summary is attached; the transcript stays on your machine.",
                    ['Yes, generate a summary', 'No'],
                );

                if ($choice === null) {
                    $this->say('Bug report cancelled');

                    return;
                }

                $includeSummary = $choice !== 'No';
            }

            $description = trim($hint);
            $host = (string) parse_url(BugReport::SERVER_ENDPOINT, PHP_URL_HOST);
            $delivery = $this->ui->select(
                "Bug report\n\nDescription: " . ($description !== '' ? $description : 'none')
                . "\nTranscript: " . ($includeSession ? 'included' : 'not included')
                . "\nSummary: " . ($includeSummary ? "written by {$modelName}" : 'none')
                . "\n\nUpload sends the report to {$host}. Export writes a zip archive to the current directory instead.",
                ['Upload Report', 'Export as Zip', 'Cancel'],
            );

            if ($delivery === null || $delivery === 'Cancel') {
                $this->say('Bug report cancelled');

                return;
            }

            if ($delivery === 'Upload Report' && !\Pig\CodingAgent\ModelRefresh::networkEnabled()) {
                $this->sayError('Uploading bug reports requires online mode. Use Export as Zip instead.');

                return;
            }

            $summary = null;

            if ($includeSummary) {
                $controller = new AbortController();
                $this->compaction = $controller;
                $this->showLoader("Writing summary with {$modelName}...");

                try {
                    $summary = $this->session->summarizeForBugReport($description === '' ? null : $description, $controller->signal);
                } catch (Throwable $error) {
                    if ($controller->signal->aborted()) {
                        $this->say('Bug report cancelled');
                    } else {
                        $this->sayError('Failed to write bug report summary: ' . $error->getMessage());
                    }

                    return;
                } finally {
                    $this->compaction = null;
                    $this->hideLoader();
                }

                if ($controller->signal->aborted()) {
                    $this->say('Bug report cancelled');

                    return;
                }
            }

            try {
                $report = BugReport::build($this->session, $this->auth, $description, $includeSession, $summary);
                $sessionJsonl = $includeSession
                    ? $this->session->store()?->serializeBranch(fn (?string $parent, string $timestamp): array => SessionShare::trailingEntries($this->session, $parent, $timestamp))
                    : null;
            } catch (Throwable $error) {
                $this->sayError('Failed to build bug report: ' . $error->getMessage());

                return;
            }

            $id = \Pig\Ai\Utils\Uuid::v7();

            if ($delivery === 'Upload Report') {
                $controller = new AbortController();
                $this->compaction = $controller;
                $this->showLoader('Uploading bug report...');

                try {
                    $url = BugReport::upload($description, $report, signal: $controller->signal);
                    $failure = null;
                } catch (Throwable $error) {
                    $url = null;
                    $failure = $error->getMessage();
                } finally {
                    $this->compaction = null;
                    $this->hideLoader();
                }

                if ($controller->signal->aborted()) {
                    $this->say('Bug report cancelled');

                    return;
                }

                if ($url !== null) {
                    $this->recordBugReport($id, $description, $includeSession, $summary !== null, 'upload', null);
                    $this->say("Bug report uploaded: {$url}");

                    return;
                }

                $fallback = $this->ui->select("Upload failed\n\n{$failure}\n\nExport the report as a zip archive instead?", ['Export as Zip', 'Cancel']);

                if ($fallback !== 'Export as Zip') {
                    $this->say('Bug report cancelled');

                    return;
                }
            }

            $archive = rtrim($this->cwd, '/') . '/' . BugReport::archiveFileName($id);

            try {
                BugReport::writeArchive($archive, BugReport::archiveFiles($report, $sessionJsonl, $summary));
            } catch (Throwable $error) {
                $this->sayError('Failed to write bug report: ' . $error->getMessage());

                return;
            }

            $this->recordBugReport($id, $description, $includeSession, $summary !== null, 'zip', $archive);
            $this->say("Bug report exported to: {$archive}\nReport ID: {$id}");
        });
    }

    /** Upstream's `recordInSession()`: a `pig.bug-report` entry, and the crash log handed over. */
    private function recordBugReport(string $id, string $hint, bool $sessionIncluded, bool $summaryIncluded, string $delivery, ?string $path): void
    {
        $this->session->store()?->appendCustomEntry(BugReport::CUSTOM_ENTRY_TYPE, [
            'id' => $id,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'hint' => $hint === '' ? null : $hint,
            'sessionIncluded' => $sessionIncluded,
            'summaryIncluded' => $summaryIncluded,
            'delivery' => $delivery,
            ...($path === null ? [] : ['path' => $path]),
        ]);

        \Pig\CodingAgent\CrashLog::clear();
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

        // 3. Upstream's `handleExportCommand()`: a path ending in `.jsonl` is the branch as a
        // session file (`exportToJsonl()`), anything else — or nothing — single-file HTML.
        $target = self::pathArgument($path);

        try {
            $written = $target !== null && str_ends_with($target, '.jsonl')
                ? JsonlExport::write($store, $target, $this->cwd)
                : HtmlExport::write($store, $target ?? HtmlExport::defaultPath($store, $this->cwd));
        } catch (Throwable $error) {
            $this->sayError('Failed to export session: ' . $error->getMessage());

            return;
        }

        $this->say('Session exported to: ' . $written);
    }

    /**
     * Upstream's `getPathCommandArgument()`: the first word after the command, or the text inside
     * a leading pair of quotes so a path can hold a space. Null when there is none, or when the
     * quote is never closed.
     */
    private static function pathArgument(string $args): ?string
    {
        $args = ltrim($args);

        if ($args === '') {
            return null;
        }

        if ($args[0] === '"' || $args[0] === "'") {
            $closing = strpos($args, $args[0], 1);

            return $closing === false ? null : substr($args, 1, $closing - 1);
        }

        return preg_split('/\s/', $args, 2)[0];
    }

    /**
     * `/import <path.jsonl>` — upstream's `handleImportCommand()` and `importFromJsonl()`.
     *
     * Asked first, because the conversation on screen is replaced. The file is copied into this
     * project's session directory — under its own name, or `name-1.jsonl`, `name-2.jsonl`… when
     * that is taken, and not copied at all when it already lives there — and the copy is resumed,
     * so the original is never written to. The hooks hear it as a `resume`, as upstream's do.
     *
     * The copy carries on in the working directory its header names, as upstream's
     * `importFromJsonl()` does (`resume()`, `AgentSession::switchTo()`), and when that directory
     * has gone the same "continue in current cwd" question is asked, a no being `Import cancelled`.
     */
    private function importSession(string $args): void
    {
        $input = self::pathArgument($args);

        if ($input === null || $input === '') {
            $this->sayError('Usage: /import <path.jsonl>');

            return;
        }

        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        Async::spawn(function () use ($input): void {
            if (!$this->ui->confirm('Import session', "Replace current session with {$input}?")) {
                $this->say('Import cancelled');

                return;
            }

            $source = JsonlExport::resolve($input, $this->cwd);

            if (!is_file($source)) {
                $this->sayError("Failed to import session: File not found: {$source}");

                return;
            }

            $directory = SessionManager::directory($this->cwd);
            $destination = $directory . '/' . basename($source);
            $alreadyStored = (realpath($destination) ?: $destination) === (realpath($source) ?: $source);

            if (!$alreadyStored) {
                $name = pathinfo($destination, PATHINFO_FILENAME);
                $extension = pathinfo($destination, PATHINFO_EXTENSION);
                $suffix = 1;

                while (file_exists($destination)) {
                    $destination = "{$directory}/{$name}-" . $suffix++ . ($extension === '' ? '' : ".{$extension}");
                }

                if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
                    $this->sayError("Failed to import session: could not create {$directory}");

                    return;
                }

                if (!copy($source, $destination)) {
                    $this->sayError("Failed to import session: could not copy it to {$destination}");

                    return;
                }
            }

            $info = SessionManager::info($destination);

            if ($info === null) {
                $this->sayError("Failed to import session: {$source} is not a session file");

                return;
            }

            if ($this->resume($info, 'Import cancelled')) {
                $this->say("Session imported from: {$input}");
            }
        });
    }

    /**
     * `/share`: the session as a secret GitHub gist, and a link to view it — upstream's
     * `shareSession()` on its gist road (see `SessionShare` for the Radius one pig has not got).
     *
     * `gh` has to be installed and signed in; the HTML export goes into a temporary directory,
     * `gh gist create --public=false` uploads it, and the answer is the viewer URL
     * (`PIG_SHARE_VIEWER_URL`, `#<gist id>`) and the gist's own. Escape stops the upload, under the
     * same spinner `/compact` uses.
     */
    private function shareSession(): void
    {
        $store = $this->session->store();

        if ($store === null) {
            $this->sayError('This session is not being saved, so there is nothing to share.');

            return;
        }

        $gh = SessionList::onPath('gh');

        if ($gh === null) {
            $this->sayError('GitHub CLI (gh) is not installed. Install it from https://cli.github.com/');

            return;
        }

        Async::spawn(function () use ($store, $gh): void {
            [$authCode] = Process::runAsync([$gh, 'auth', 'status'], 30.0);

            if ($authCode !== 0) {
                $this->sayError("GitHub CLI is not logged in. Run 'gh auth login' first.");

                return;
            }

            $directory = sys_get_temp_dir() . '/pig-share-' . bin2hex(random_bytes(6));

            if (!mkdir($directory, 0o700)) {
                $this->sayError("Failed to export session: could not create {$directory}");

                return;
            }

            $html = "{$directory}/session.html";

            try {
                try {
                    HtmlExport::write($store, $html);
                } catch (Throwable $error) {
                    $this->sayError('Failed to export session: ' . $error->getMessage());

                    return;
                }

                $controller = new AbortController();
                $this->compaction = $controller;
                $this->showLoader('Creating gist...');

                try {
                    [$code, $stdout, $stderr] = Process::runAsync(
                        [$gh, 'gist', 'create', '--public=false', $html],
                        300.0,
                        signal: $controller->signal,
                    );
                } finally {
                    $this->compaction = null;
                    $this->hideLoader();
                }

                if ($controller->signal->aborted()) {
                    $this->say('Share cancelled');

                    return;
                }

                if ($code !== 0) {
                    $this->sayError('Failed to create gist: ' . (trim($stderr) !== '' ? trim($stderr) : 'Unknown error'));

                    return;
                }

                $gistUrl = trim($stdout);
                $parts = explode('/', $gistUrl);
                $gistId = end($parts);

                if ($gistId === false || $gistId === '') {
                    $this->sayError('Failed to parse gist ID from gh output');

                    return;
                }

                $viewer = SessionShare::viewerUrl($gistId);
                $this->say("Share URL: {$viewer}\nGist: {$gistUrl}");
            } finally {
                // Upstream's `rmSync(tempDir, { force: true })`: the file may never have been written.
                if (is_file($html)) {
                    unlink($html);
                }

                rmdir($directory);
                $this->tui->requestRender();
            }
        });
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

            $this->sayThemed(static fn (): string => Themes::theme()->fg('accent', '✓ PR Description generated and copied to clipboard!'));
            $this->sayThemed(static fn (): string => Themes::theme()->fg('dim', "Saved to: {$savePath}\n\n") . $prMarkdown);
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
     * that **pig already has every other end of it**: `TuiBase::frame()` is the dump `checkWidth()`
     * writes when a line is too wide, and every width bug in docs/dev/traps/tui.md was found by reading
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
            $this->renderer->frame(),
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

    /**
     * Upstream's `handleCopyCommand()`. `/copy` calls it bare; ctrl+x calls it as upstream's
     * `app.message.copy` does — `preferSelection`, so in fullscreen with `fullscreenCopyOnSelect`
     * off the key copies what the mouse selected (a selection that copied itself on release has
     * nothing left to copy), and `flashConfirmation`, so the fullscreen flash says so rather than
     * a line in the transcript.
     */
    private function copyLastAnswer(bool $flashConfirmation = false, bool $preferSelection = false): void
    {
        if (
            $preferSelection
            && $this->renderer instanceof TuiAltScreen
            && !$this->renderer->getCopyOnSelect()
            && $this->renderer->hasActiveSelection()
        ) {
            $this->renderer->copyActiveSelectionToClipboard();

            return;
        }

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

        if ($flashConfirmation && $this->renderer instanceof TuiAltScreen) {
            $this->renderer->flash('Copied!');

            return;
        }

        $this->say('Copied the last answer — ' . number_format(mb_strlen($text)) . ' characters');
    }

    /**
     * The skills loaded, and which root each came from.
     *
     * The source is shown because the same name can live in several places and the one that
     * won is not obvious — a user skill shadowed by a project one looks like the user one
     * simply not working.
     */
    private function skillList(): Closure
    {
        if ($this->skills === []) {
            return static fn (): string => 'No skills found. A skill is a folder with a SKILL.md in '
                . '~/.pig/agent/skills, .pig/skills, ~/.agents/skills or .agents/skills, or one the '
                . '`skills` setting names.';
        }

        // `/reload` replaces the list rather than changing these, so holding the array is the snapshot.
        $skills = $this->skills;

        return static function () use ($skills): string {
            $lines = [];

            foreach ($skills as $skill) {
                $lines[] = Themes::theme()->fg('dim', Width::pad($skill->name, 24))
                    . Themes::theme()->fg('muted', $skill->description);
                $lines[] = Themes::theme()->fg('dim', str_repeat(' ', 24) . $skill->source . ' · ' . $skill->path);
            }

            return implode("\n", $lines);
        };
    }

    /**
     * The three tables and what is loaded, as one block.
     *
     * One implementation because there are two readers — `/help` and the banner under ctrl+o —
     * and they were the same ten lines twice. They could not have disagreed about the tables;
     * they could and would have disagreed about the column, which is the thing that moved when
     * the editing keys arrived with labels wider than the old hardcoded twelve.
     */
    private function keysAndCommands(): Closure
    {
        $keys = $this->keys();
        $shortcuts = [];
        foreach ($this->editor->shortcuts() as $s) {
            $shortcuts[$s['name']] = $s['description'] !== '' ? $s['description'] : 'extension shortcut';
        }

        $editing = $this->editingKeys();
        $labels = [
            ...array_keys($keys),
            ...array_keys($editing),
            ...array_keys($shortcuts),
            ...array_map(static fn (array $row): string => '/' . $row[0], self::COMMANDS),
        ];

        // Measured from the labels, not a constant: `shift+enter` is eleven columns and the old
        // `12` left it one space from its description while `esc` had nine. The same rule as
        // `--list-models`' columns, and measured with `Width` for the same reason.
        $column = max(array_map(Width::visible(...), $labels)) + 2;

        $tables = [$keys, $editing];
        if ($shortcuts !== []) {
            $tables[] = $shortcuts;
        }

        // Everything above is a snapshot of the bindings; the colours are applied when it is drawn.
        return static function () use ($tables, $column): string {
            $rows = [];

            foreach ($tables as $table) {
                foreach ($table as $key => $does) {
                    $rows[] = Themes::theme()->fg('dim', Width::pad($key, $column)) . Themes::theme()->fg('muted', $does);
                }

                $rows[] = '';
            }

            foreach (self::COMMANDS as [$name, $does]) {
                $rows[] = Themes::theme()->fg('dim', Width::pad('/' . $name, $column)) . Themes::theme()->fg('muted', $does);
            }

            return implode("\n", $rows);
        };
    }

    /**
     * `/hotkeys` (and `/help`, which is the same block here) in upstream's frame: a blank row, a
     * rule, the bold accent title, a blank row, the list padded one row above and below, a rule.
     *
     * The rows are pig's column-aligned text rather than upstream's markdown tables, because
     * `Pig\Tui\Components\Markdown` draws no tables; the frame around them is upstream's to the
     * line, so the block reads as the same thing in both.
     */
    private function sayHotkeys(): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('border', $text)));
        $this->chat->addChild(new ThemedText(static fn (): string => Style::bold(Themes::theme()->fg('accent', 'Keyboard Shortcuts')), 1, 0));
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText($this->keysAndCommands(), 1, 1));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('border', $text)));
        $this->tui->requestRender();
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
        // Upstream's line, accent and with a blank row under it (`ThemedText(..., 1, 1)`) — the
        // one note in the transcript that pads below, because it is the first thing on an
        // otherwise empty screen.
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('accent', '✓ New session started'), 1, 1));
        $this->tui->requestRender();

        // The `session_start` hook (reason `new`) fired inside `startNew()`, where every mode gets it. What
        // is left here is the custom tools, which this mode holds and the session does not.
        $this->sayToolProblems($this->customTools?->notify('switch', $previous) ?? []);
    }

    /** @return Closure(): string the stats taken now, as upstream's `/session` snapshots them */
    private function sessionSummary(): Closure
    {
        $stats = $this->session->stats();
        $summary = sprintf(
            '%d messages · %d tool calls · %s tokens · $%s',
            $stats->totalMessages,
            $stats->toolCalls,
            number_format($stats->totalTokens()),
            number_format($stats->cost, 4),
        );

        // Upstream's "Cache Warming" block, as one more line: the mode, then what the warmer is
        // doing and why, then what a miss and a refresh cost when it has the numbers.
        $status = $this->session->cacheWarmingStatus();
        $warming = 'Cache warming: ' . $this->settings->cacheWarmingMode() . ' · '
            . ($status !== null ? CacheWarmer::formatStatus($status) : 'Inactive (cache warming unavailable)');

        if ($status?->decision?->economicsAvailable === true) {
            $warming .= sprintf(' · miss penalty $%.3f · refresh $%.3f', $status->decision->missCost, $status->decision->warmCost);
        }

        $lines = [$summary, $warming];

        // Upstream's per-model cost lines under the total: what each physical model the session
        // reached cost — a virtual model's requests under the models it routed them to. "A single
        // entry repeats the total, unless it names a model other than the selected one."
        $breakdown = \Pig\CodingAgent\UsageCostBreakdown::of($this->session->store()?->everyMessage() ?? $this->session->messages());
        $selected = $this->session->model();
        $selectedKey = $selected === null ? null : "{$selected->provider}/{$selected->id}";

        if ($stats->cost > 0 && (count($breakdown) > 1 || ($breakdown[0]['key'] ?? null) !== $selectedKey)) {
            foreach ($breakdown as $entry) {
                $lines[] = sprintf('  %s: $%.3f (%s tokens)', $entry['key'], $entry['cost'], FooterComponent::tokens($entry['tokens']));
            }
        }

        // Upstream's "Cache Re-billed" line, under the cost: what was in the previous request's
        // prompt and billed again rather than read from the cache.
        $waste = CacheStats::waste($this->session->store()?->everyMessage() ?? $this->session->messages());

        if ($waste['missedTokens'] > 0) {
            $detail = number_format($waste['missedTokens']) . ' tokens, ' . ($waste['missCount'] === 1 ? '1 miss' : "{$waste['missCount']} misses");
            $lines[] = $waste['missedCost'] >= 0.0001
                ? sprintf('Cache re-billed: $%.3f (%s)', $waste['missedCost'], $detail)
                : "Cache re-billed: {$detail}";
        }

        return static fn (): string => implode("\n", array_map(static fn (string $line): string => Themes::theme()->fg('muted', $line), $lines));
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

        // The same component `--resume` draws before the screen exists, search box and all —
        // upstream's `showSessionSelector()`: Tab for every project's sessions, sorting, the named
        // filter, paths, delete, and rename (which the `--resume` one does not offer).
        $picker = new SessionList($sessions, 8, static fn (): array => SessionManager::listAll(PHP_INT_MAX)[0]);

        $picker->setSelectHandler(function (string $path): void {
            $info = SessionManager::info($path);

            if ($info === null) {
                $this->closePicker();
                $this->sayError("Could not read the session at {$path}");

                return;
            }

            $this->closePicker();
            // In a fiber of its own: a session from another project may ask about its directory
            // or its trust, and a dialog cannot be waited for from inside the key handler.
            Async::spawn(fn () => $this->resume($info));
        });

        // Escape and ctrl+c both close it. They mean different things where the list is the
        // whole screen — `SessionList` keeps them apart for `bin/pig`, which quits on one of
        // them — but inside a running session there is nothing to quit to: the conversation is
        // still behind the overlay either way.
        $picker->setCancelHandler($this->closePicker(...));
        $picker->setQuitHandler($this->closePicker(...));
        // Upstream's `app.session.delete`: confirmed in the list, refused for the session being
        // talked in, and the file goes to the trash when there is one.
        $picker->setDeleteHandler(SessionList::deleteSessionFile(...), $this->session->store()?->path);
        // Upstream's `renameSession`: `SessionManager.open(path).appendSessionInfo(name)`. The
        // session being talked in is named through the session instead, so the footer and the
        // next `/name` see it too and the file is not written by two managers at once.
        $picker->setRenameHandler(function (string $path, string $name): void {
            $current = $this->session->store()?->path;

            if ($current !== null && (realpath($current) ?: $current) === (realpath($path) ?: $path)) {
                $this->session->setSessionName($name);
                $this->footer->invalidate();

                return;
            }

            SessionManager::open($path)->setSessionName($name);
        });

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild($picker);
        $this->overlay->addChild(new Spacer(1));

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
            'xhigh' => 'Extra-high reasoning (~32k tokens)',
            'max' => 'Maximum reasoning',
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
     * Argument completions for `/theme`: every theme `Themes::getAvailableThemes()` lists.
     *
     * @return list<AutocompleteItem>
     */
    private function themeCompletions(string $typed): array
    {
        $wanted = mb_strtolower(trim($typed));
        $items = [];

        foreach (Themes::getAvailableThemes() as $name) {
            if ($wanted !== '' && !str_starts_with(mb_strtolower($name), $wanted)) {
                continue;
            }

            $items[] = new AutocompleteItem($name, $name, self::themeDescription($name));
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
        }, function (string $value): void {
            // Upstream's `selectThinkingLevel(level, true)`.
            $this->overlay->clear();
            $this->tui->setFocus($this->editor);
            $this->useThinkingLevel($value, persistAsDefault: true);
            $this->say("Default thinking level: {$value}");
            $this->tui->requestRender();
        });

        $this->overlay->clear();
        $this->overlay->addChild($submenu);
        $this->tui->setFocus($submenu);
        $this->tui->requestRender();
    }

    /**
     * What `resources_discover` added, merged into the lists this screen keeps of its own: the
     * `/skills` listing and the names the autocomplete offers. The session already holds them.
     */
    private function takeExtensionResources(?ExtensionResources $resources): void
    {
        if ($resources === null) {
            return;
        }

        $byName = [];

        foreach ([...$this->skills, ...$resources->skills] as $skill) {
            $byName[$skill->name] = $skill;
        }

        $this->skills = array_values($byName);
        $this->fileCommands = [
            ...array_values(array_filter($this->fileCommands, static fn (FileCommand $c): bool => !str_starts_with($c->source, '(extension'))),
            ...$resources->commands,
        ];
    }

    /**
     * Hot-reload keybindings, extensions, skills, commands, tools, themes, and context files.
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

        // 2–3. Reload skills, prompt templates and theme files — `CodingAgent::resources()`, the same
        // loader and the same trust gate `CodingAgent::session()` uses: `/reload` must not be the
        // door an untrusted project's `.pig/` comes in by. Packages are resolved again without
        // installing anything, as a reload is not the moment to clone.
        try {
            $packages = (new PackageManager($this->cwd, $this->settings))->resolve(static fn (): string => 'skip');
        } catch (PackageError $error) {
            $this->sayWarning("packages: {$error->getMessage()}");
            $packages = null;
        }

        $resources = CodingAgent::resources($this->cwd, $this->settings, $this->projectTrusted, $packages);
        $skillWarnings = $resources['skillWarnings'];
        $this->skills = $resources['skills'];
        $this->fileCommands = $resources['fileCommands'];
        $this->session->setFileCommands($this->fileCommands);

        foreach ($resources['promptWarnings'] as $problem) {
            $this->sayWarning($problem);
        }

        // Upstream's `reload()`: what the extensions being replaced put on the prompt goes with them —
        // the wrappers around the completions and the editor itself — and they add it again on
        // `session_start` if they still want it.
        $this->autocompleteWrappers = [];
        $this->setCustomEditorComponent(null);

        // 4. Reload hooks, and extensions with theirs. The runner being replaced hears
        // `session_shutdown` first and the new one `session_start`, as upstream's do across a
        // reload — an MCP server connected by the old one is otherwise never closed, and never
        // connected by the new one.
        $this->hooks?->emit(new SessionShutdownEvent('reload'));
        [$loadedHooks, $hookProblems] = HookLoader::load($this->cwd, $this->settings->hooks(), projectTrusted: $this->projectTrusted);
        $loadedTools = [];

        // The loader scans the three extension roots itself. The third argument is for paths
        // typed on the command line (`--extension`), which this mode is not given — and for a
        // while it was handed `ExtensionDiscovery::discover()`'s *display labels* instead, so every
        // reload complained that `copy.php` and `pig-antigravity` were not readable files under
        // the project directory. A label for a banner is not a path.
        // What the previous load put into the process-wide registries goes first, or an
        // extension that was removed keeps its provider, its routes and its flags; the ones
        // still there register again a line later.
        ProviderRegistry::forget();
        ExtensionApi::forgetHttpRoutes();
        ExtensionApi::forgetWebPanels();
        ExtensionApi::forgetFlags();
        McpServerRegistry::reset();
        VirtualModelRegistry::reset();

        [$loadedExtensions, $extensionProblems] = ExtensionLoader::load(
            $this->cwd,
            $this->settings->extensions(),
            auth: $this->auth,
            projectTrusted: $this->projectTrusted,
            disabled: $this->settings->disabledExtensions(),
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
        $hooks->wire($session, $this->ui);

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
        $previousTools = $this->customTools;
        $this->customTools = $customTools;
        $customTools->withUi($this->ui);

        foreach ($loadedExtensions as $ext) {
            $customTools->adopt($ext);
        }

        // `--tools` and `--exclude-tools` survive a reload. The new set used to be built
        // without the filter, so `/reload` put back every tool the command line had left out.
        $filter = $previousTools?->filter();

        if ($filter !== null) {
            $customTools->keep($filter);
        }

        $customTools->withContext(fn () => $hooks->context());

        // 7. Update agent tools & system prompt — through the session's loadout, the one place
        // that builds them, so an extension's narrowed active set survives too.
        $loadout = $this->session->loadout();

        if ($loadout === null) {
            $loadout = new ToolLoadout($this->session->agent, $this->cwd, $this->builtInTools, $customTools, $hooks, $this->contextFiles, $this->skills);
            $this->session->useLoadout($loadout);
            $loadout->apply();
        } else {
            $loadout->reloaded($customTools, $this->contextFiles, $this->skills);
        }

        $customTools->onChange(static fn () => $loadout->refresh());
        $hooks->emit(new SessionStartEvent('reload'));
        $this->takeExtensionResources($this->session->discoverResources('reload'));

        // Upstream's `this.keybindings.reload()`: the same manager reads `keybindings.json` again,
        // so the editor and the TUI's global registry — which both hold it — answer to the new keys
        // at once. What the file has that cannot be read is said, as it is at startup.
        foreach ($this->keybindings->reload() as $problem) {
            $this->sayWarning($problem);
        }

        // Upstream's `themeController.applyFromSettings()` after a reload: the theme is loaded again
        // from its file, so an edited custom theme shows without a restart.
        $this->themeController->applyFromSettings();

        // 8. Rebind autocomplete on editor, and redraw the header and what was loaded — upstream's
        // `showLoadedResources()` after a reload, which draws the problems as its warning blocks.
        $this->bindEditor();
        $this->builtInHeader?->setExpanded($this->expanded);
        $this->skillDiagnostics = array_map(
            static fn (SkillWarning $w): array => ['type' => 'warning', 'message' => $w->message, 'path' => $w->path],
            $skillWarnings,
        );
        $this->extensionDiagnostics = [
            ...array_map(static fn (HookError $p): array => ['type' => 'error', 'message' => "{$p->event}: {$p->error}", 'path' => $p->hookPath], $hookProblems),
            ...array_map(static fn (ExtensionError $p): array => ['type' => 'error', 'message' => "{$p->stage}: {$p->message}", 'path' => $p->path], $extensionProblems),
            ...array_map(static fn (ToolProblem $p): array => ['type' => 'error', 'message' => $p->error, 'path' => $p->path], $toolProblems),
        ];
        $this->showLoadedResources(force: false, showDiagnosticsWhenQuiet: true);

        // 9. Context files have no block upstream; their problems are said as warnings.
        foreach ($contextWarnings as $w) {
            $this->sayWarning($w);
        }

        $this->sayThemed(static fn (): string => Themes::theme()->fg('accent', 'Reloaded keybindings, extensions, skills, commands, tools, themes, and context files.'));
        $this->tui->requestRender();
    }
    private static function modelSearchText(Model $model): string
    {
        return ScopedModelsSelector::searchText($model);
    }

    private function showModels(string $pattern = ''): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        // Upstream's `modelRuntime.refresh()` on opening `/model`: `models.json` read again, so an
        // endpoint added to it is there without a restart.
        $custom = \Pig\CodingAgent\CustomModels::discover();
        $custom->install($this->auth);

        foreach ($custom->problems as $problem) {
            $this->sayWarning("models.json: {$problem}");
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

        if ($models === []) {
            $this->sayError('No model has a key here. /login, or set a provider key in the environment.');

            return;
        }

        // Upstream's model selector refreshes every catalog while it is open
        // (`ModelSelectorComponent.refreshModels()`), saying so on a line under the list: the
        // cached models first, the refreshed ones when the refresh comes back.
        $status = ['message' => 'Refreshing model catalogs…', 'kind' => 'muted'];
        $picker = $this->modelPicker($models);
        // The list sits in a slot of its own, so the refreshed one can take its place.
        $slot = new Container();
        $slot->addChild($picker);
        $statusLine = new ThemedText(static function () use (&$status): string {
            return Themes::theme()->fg($status['kind'], '  ' . $status['message']);
        }, 1, 0);

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new ThemedText(fn (): string => Themes::theme()->fg('muted', 'Pick a model — enter to switch, ' . $this->keybindings->label('app.models.save') . ' to set as default, esc to cancel'), 1, 0));
        $this->overlay->addChild($slot);

        if ($this->auth !== null) {
            $this->overlay->addChild($statusLine);
        }

        $this->overlay->addChild(new Spacer(1));

        $this->tui->setFocus($picker);
        $this->tui->requestRender();

        $this->refreshModelCatalogs(
            fn (): bool => in_array($slot, $this->overlay->children(), true),
            function (?ModelRefreshResult $result, bool $timedOut, ?Throwable $error) use (&$status, &$picker, $slot, $statusLine): void {
                if ($error !== null || $result === null) {
                    $status = ['message' => $timedOut ? 'Model refresh timed out; showing cached models.' : 'Could not refresh model catalogs: ' . $error?->getMessage(), 'kind' => 'error'];
                } elseif ($result->aborted && $timedOut) {
                    $status = ['message' => 'Model refresh timed out; showing cached models.', 'kind' => 'error'];
                } elseif (count($result->errors) === 1) {
                    $status = ['message' => 'Could not refresh ' . array_key_first($result->errors) . '; showing cached models.', 'kind' => 'error'];
                } elseif (count($result->errors) > 1) {
                    $status = ['message' => 'Could not refresh ' . count($result->errors) . ' model catalogs (' . implode(', ', array_keys($result->errors)) . '); showing cached models.', 'kind' => 'error'];
                } else {
                    $status = ['message' => 'Model catalogs refreshed.', 'kind' => 'success'];
                }

                // Upstream's `loadModelsFromSnapshot()`: the list again, over what the refresh left,
                // with what was typed and the model under the cursor kept.
                $models = $this->modelsWithAKey();

                if ($models !== []) {
                    $picker = $this->modelPicker($models, $picker);
                    $slot->clear();
                    $slot->addChild($picker);
                    $this->tui->setFocus($picker);
                }

                $statusLine->invalidate();
                $this->tui->requestRender();
            },
        );
    }

    /**
     * The `/model` list over `$models`; with `$previous`, the filter typed into it and the model
     * under its cursor carried over.
     *
     * @param list<Model> $models
     */
    private function modelPicker(array $models, ?SelectList $previous = null): SelectList
    {
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
                self::modelSearchText($model),
            );
        }

        $picker = new SelectList($items, 8, Themes::getSelectListTheme());
        $picker->setSelectHandler(function (SelectItem $item) use ($models): void {
            $this->closePicker();
            $this->useModel($models[(int) $item->value], persistAsDefault: false);
        });
        $picker->setSaveDefaultHandler(function (SelectItem $item) use ($models): void {
            $this->closePicker();
            $this->useModel($models[(int) $item->value], persistAsDefault: true);
        });
        $picker->setCancelHandler($this->closePicker(...));

        if ($previous !== null) {
            $selected = $previous->selectedItem();
            $picker->setFilter($previous->filter());

            if ($selected !== null) {
                // The same item by what it names, not by where it was: the list may have grown.
                $label = $selected->label;

                foreach ($items as $position => $item) {
                    if ($item->label === $label) {
                        $picker->setSelectedIndex($position);

                        break;
                    }
                }
            }
        }

        return $picker;
    }

    /**
     * Upstream's `refreshModelCatalogs()` as its selectors run it: every catalog refreshed in a
     * fiber of its own, with the 15-second budget (`ModelRefresh::TIMEOUT_MS`), and `$done` told
     * the result, whether the budget ran out, or what was thrown — unless `$stillOpen` says the
     * selector it was for has closed by then, in which case the refresh is called off and nobody
     * is told (upstream's `dispose()` aborting it). Offline (`PIG_OFFLINE`) it reads the stored
     * catalogs only.
     *
     * @param Closure(): bool $stillOpen
     * @param Closure(?ModelRefreshResult, bool, ?Throwable): void $done
     */
    private function refreshModelCatalogs(Closure $stillOpen, Closure $done): void
    {
        $auth = $this->auth;

        // No credentials, no catalogs to ask for: only a test builds the mode without them.
        if ($auth === null) {
            return;
        }

        Async::spawn(static function () use ($auth, $stillOpen, $done): void {
            $controller = new AbortController();
            $timedOut = false;
            $timer = Loop::get()->delay(ModelRefresh::TIMEOUT_MS / 1000, static function () use ($controller, &$timedOut): void {
                $timedOut = true;
                $controller->abort('The operation was aborted due to timeout');
            });
            // Looked at while the refresh runs, so a selector closed part-way calls it off.
            $watch = null;
            $check = static function () use (&$check, &$watch, $controller, $stillOpen): void {
                if (!$stillOpen()) {
                    $controller->abort('Cancelled');

                    return;
                }

                $watch = Loop::get()->delay(0.25, $check);
            };
            $watch = Loop::get()->delay(0.25, $check);
            $result = null;
            $error = null;

            try {
                $result = ModelRefresh::refresh($auth, signal: $controller->signal, allowNetwork: ModelRefresh::networkEnabled());
            } catch (Throwable $thrown) {
                $error = $thrown;
            } finally {
                Loop::get()->cancel($timer);

                if ($watch !== null) {
                    Loop::get()->cancel($watch);
                }
            }

            if ($stillOpen()) {
                $done($result, $timedOut, $error);
            }
        });
    }

    /**
     * `/scoped-models`: which models Ctrl+P cycles through — upstream's `showModelsSelector()`.
     *
     * Over every model there is a key for, not the scope (which is what is being edited). What is
     * enabled to begin with is the session's scope when it has one — `--models`, or an earlier
     * visit here — and otherwise the `enabledModels` setting resolved against those models, with
     * any pattern that matched nothing kept as an id so saving does not drop it.
     *
     * Every change narrows the session at once (`AgentSession::setModelScope()`); an empty scope,
     * or one that enables every model, is no scope. `app.models.save` writes `enabledModels`, and
     * clears it when everything is enabled — upstream's `onPersist`.
     *
     * While the selector is open every provider's catalog is refreshed, as upstream's
     * `refreshModelCatalogs()` does: "Refreshing model catalogs…" above the footer, then the
     * refreshed models (re-resolving `enabledModels` against them unless something was already
     * changed) and the outcome in upstream's words. `models.json` is read again on opening, as
     * `/model` does.
     */
    private function showScopedModels(): void
    {
        $custom = \Pig\CodingAgent\CustomModels::discover();
        $custom->install($this->auth);

        foreach ($custom->problems as $problem) {
            $this->sayWarning("models.json: {$problem}");
        }

        $available = $this->auth?->availableModels() ?? Models::all();

        if ($available === []) {
            $this->sayError('No model has a key here. /login, or set a provider key in the environment.');

            return;
        }

        $availableIds = array_map(ScopedModelsSelector::id(...), $available);
        $sessionScope = $this->session->modelScope();
        $patterns = $this->settings->enabledModels() ?? [];

        // Upstream's `configuredEnabledIds()`: the setting resolved against the models there are,
        // with a pattern that matched nothing kept as an id so saving does not drop it.
        $configured = static function (array $available) use ($patterns): ?array {
            if ($patterns === []) {
                return null;
            }

            $enabled = array_map(static fn (ModelChoice $choice): string => ScopedModelsSelector::id($choice->model), ModelResolver::scope($patterns, $available)[0]);

            foreach ($patterns as $pattern) {
                if (ModelResolver::scope([$pattern], $available)[0] === [] && !in_array($pattern, $enabled, true)) {
                    $enabled[] = $pattern;
                }
            }

            return $enabled;
        };

        $enabled = $sessionScope !== []
            ? array_map(static fn (ModelChoice $choice): string => ScopedModelsSelector::id($choice->model), $sessionScope)
            : $configured($available);
        $selectionChanged = false;

        $apply = function (?array $ids) use (&$available, &$availableIds): void {
            $anyAvailable = $ids !== null && array_intersect($ids, $availableIds) !== [];
            $allEnabled = $ids !== null && array_diff($availableIds, $ids) === [];

            $this->session->setModelScope($ids !== null && $anyAvailable && !$allEnabled
                ? ModelResolver::scope($ids, $available)[0]
                : []);
            $this->footer->invalidate();
            $this->tui->requestRender();
        };

        $selector = new ScopedModelsSelector(
            $available,
            $enabled,
            function (?array $ids) use ($apply, &$enabled, &$selectionChanged): void {
                $selectionChanged = true;
                $enabled = $ids;
                $apply($ids);
            },
            function (?array $ids) use (&$availableIds): void {
                $allEnabled = $ids !== null && count($ids) === count($availableIds) && array_diff($ids, $availableIds) === [];
                $this->settings->setEnabledModels($ids === null || $allEnabled ? null : $ids);
                $this->say('Model selection saved to settings');
            },
            $this->closePicker(...),
            refreshStatus: $this->auth === null ? null : 'Refreshing model catalogs…',
        );

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild($selector);
        $this->overlay->addChild(new Spacer(1));

        $this->tui->setFocus($selector);
        $this->tui->requestRender();

        $this->refreshModelCatalogs(
            fn (): bool => in_array($selector, $this->overlay->children(), true),
            function (?ModelRefreshResult $result, bool $timedOut, ?Throwable $error) use ($selector, $configured, $apply, $sessionScope, &$available, &$availableIds, &$enabled, &$selectionChanged): void {
                $available = $this->auth?->availableModels() ?? Models::all();
                $availableIds = array_map(ScopedModelsSelector::id(...), $available);

                if (!$selectionChanged && $sessionScope === []) {
                    $enabled = $configured($available);
                    $selector->updateModels($available, replaceEnabled: true, enabledIds: $enabled);
                } else {
                    $selector->updateModels($available);
                }

                if ($enabled !== null) {
                    $apply($enabled);
                }

                if ($error !== null || $result === null) {
                    $selector->setRefreshStatus($timedOut ? 'Model refresh timed out; showing cached models.' : 'Could not refresh model catalogs: ' . $error?->getMessage(), 'warning');
                } elseif ($result->aborted && $timedOut) {
                    $selector->setRefreshStatus('Model refresh timed out; showing cached models.', 'warning');
                } elseif ($result->errors !== []) {
                    $selector->setRefreshStatus('Could not refresh ' . implode(', ', array_keys($result->errors)) . '; showing cached models.', 'warning');
                } else {
                    $selector->setRefreshStatus('Model catalogs refreshed.', 'success');
                }

                $this->tui->requestRender();
            },
        );
    }

    /**
     * `/model sonnet:high` — resolve it and switch, or say why not.
     *
     * Upstream's `findExactModelMatch()`: the cached models first; only when nothing there matches
     * and no scope narrows the list are the catalogs refreshed — "Refreshing model catalogs…",
     * a warning when that fails ("…; searching cached models."), and the pattern resolved again
     * over what the refresh left.
     */
    private function pickModel(string $pattern): void
    {
        // Over the models there is a key for, as upstream's model selector resolves: `/model
        // gemini` with no Google key should say there is no such model here rather than switch to
        // one and fail on the next turn. And over the scope when `--models` set one.
        $choice = ModelResolver::parse($pattern, $this->modelsWithAKey());

        if ($choice !== null || $this->session->modelScope() !== [] || $this->auth === null) {
            $this->chooseModel($pattern, $choice);

            return;
        }

        $this->say('Refreshing model catalogs…');
        $this->refreshModelCatalogs(static fn (): bool => true, function (?ModelRefreshResult $result, bool $timedOut, ?Throwable $error) use ($pattern): void {
            if ($error !== null || $result === null) {
                $this->sayWarning($timedOut ? 'Model refresh timed out; searching cached models.' : 'Could not refresh model catalogs: ' . $error?->getMessage());
            } elseif ($result->aborted && $timedOut) {
                $this->sayWarning('Model refresh timed out; searching cached models.');
            } elseif ($result->errors !== []) {
                $this->sayWarning('Could not refresh ' . implode(', ', array_keys($result->errors)) . '; searching cached models.');
            }

            $this->chooseModel($pattern, ModelResolver::parse($pattern, $this->modelsWithAKey()));
            $this->tui->requestRender();
        });
    }

    /** What `pickModel()` found, switched to — or why not. */
    private function chooseModel(string $pattern, ?ModelChoice $choice): void
    {
        if ($choice === null) {
            $this->sayError("No model matches \"{$pattern}\". Try /model on its own for the list.");

            return;
        }

        if ($choice->warning !== null) {
            $this->sayWarning($choice->warning);
        }

        // A level only when the pattern named one: `/model sonnet` leaves it to the model's own
        // `modelThinkingLevels` entry and the default, as upstream's `setModel()` does.
        $this->useModel($choice->model, $choice->explicitThinking ? $choice->thinking : null);
    }

    private function useModel(Model $model, ?ThinkingLevel $thinking = null, bool $persistAsDefault = false): void
    {
        // `setModel()` refuses a model there is no key for. The lists above are filtered, so this
        // is the case they cannot cover: a `models.json` provider whose key variable is empty, or
        // a sign-out in another window between drawing the picker and choosing from it.
        try {
            $this->session->setModel($model, $thinking, persistAsDefault: $persistAsDefault);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        $this->footer->invalidate();
        $this->paintBorder();

        $this->maybeWarnAboutAnthropicSubscriptionAuth($model);

        $level = $this->session->thinkingLevel();

        // The level is said too, because switching models can change it under you — and
        // finding that out from a bill is worse than reading it here.
        $this->say($persistAsDefault
            ? ($level === ThinkingLevel::Off
                ? "Default model: {$model->provider}/{$model->id}"
                : "Default model: {$model->provider}/{$model->id} · thinking {$level->value}")
            : ($level === ThinkingLevel::Off
                ? "Model: {$model->id}"
                : "Model: {$model->id} · thinking {$level->value}"));
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
    /** @param string|null $initialSelectedId where the cursor starts; the current leaf when null */
    private function showTree(?string $initialSelectedId = null): void
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
        $picker = new TreeList($tree, $store->leaf(), 12, $initialSelectedId, $this->settings->treeFilterMode());
        $picker->setSelectHandler(function (string $id): void {
            $this->closePicker();

            // In a fiber: going back may ask the model to summarise what is being left,
            // and that suspends. Same reason `send()` spawns.
            Async::spawn(fn () => $this->goBackTo($id));
        });
        $picker->setCancelHandler($this->closePicker(...));
        // Upstream's `selector.onCopy`: `app.message.copy` copies the selected entry, not the last answer.
        $picker->setCopyHandler(function (?string $text): void {
            if ($text === null) {
                $this->sayError('Selected entry has no text to copy');

                return;
            }

            if (!$this->clipboard->write($text)) {
                $this->sayError('Could not copy. On Linux this needs wl-copy, xclip or xsel.');

                return;
            }

            $this->say('Copied selected message to clipboard');
        });
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
        $this->overlay->addChild(new ThemedText(
            fn (): string => Themes::theme()->fg('muted', sprintf(
                'Go back to — enter to pick, %s to filter, %s to name, %s to fold, type to search, esc to cancel',
                $this->keybindings->label('app.tree.filter.cycleForward'),
                $this->keybindings->label('app.tree.editLabel'),
                $this->keybindings->label('app.tree.foldOrUp'),
            )),
            1,
            0,
        ));
        $this->overlay->addChild($picker);
        $this->overlay->addChild(new Spacer(1));

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
                $this->sayThemed(static fn (): string => Themes::theme()->fg('dim', 'Web UI foreground server: ') . Themes::theme()->fg('accent', 'running') . Themes::theme()->fg('dim', " at {$url}"));
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
            self::openInBrowser($url);

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
        $this->sayThemed(static fn (): string => Themes::theme()->fg('dim', 'Web UI started at ') . Themes::theme()->fg('accent', $url));
        $code = $this->webServer->login()->setupCode();
        if ($code !== null) {
            $this->sayThemed(static fn (): string => Themes::theme()->fg('warning', 'First visit: set a password with the setup code ') . Themes::theme()->fg('accent', $code));
        }
        $this->sayThemed(static fn (): string => Themes::theme()->fg('dim', 'Runs while this session is open. To keep it running in background: pig web start -d'));
        self::openInBrowser($url);
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

        $this->chat->addChild(new ThemedText(static fn (): string => DiffView::render($diff), 1, 0));
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

        $committed = trim($commitOut);
        $this->sayThemed(static fn (): string => Themes::theme()->fg('accent', '✓ Committed: ') . Themes::theme()->fg('dim', $committed));
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

        $this->sayThemed(static fn (): string => $name === ''
            ? Themes::theme()->fg('dim', 'Name cleared.')
            : Themes::theme()->fg('dim', 'Named this point ') . Themes::theme()->fg('accent', $name) . Themes::theme()->fg('dim', '.'));
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

        // `branchSummary.skipPrompt`: no question, and no summary — upstream's default answer.
        $wants = $leaving !== []
            && $this->session->model() !== null
            && !$this->settings->branchSummarySkipPrompt()
            && $this->ui->confirm('Summarise the branch you are leaving?', self::summarise($leaving));

        try {
            $jump = $wants ? $this->summarisedJump($entryId) : $this->session->goTo($entryId);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        // Upstream's `if (result.cancelled) showStatus("Navigation cancelled")`, for a
        // `session_before_tree` hook that said no — after the aborted summary, which is cancelled too.
        if ($jump->cancelled && !$jump->aborted) {
            $this->say('Navigation cancelled');

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
            // Upstream: `showStatus("Branch summarization cancelled")` and the tree again, on the
            // entry that was picked.
            $this->say('Branch summarization cancelled');
            $this->showTree($entryId);

            return;
        }

        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();
        $this->say('Went back — anything you say now starts a new branch');

        if ($jump->summary !== null) {
            $this->chat->addChild(new BranchSummaryComponent($jump->summary, $this->expanded, $this->outputPad, $this->keybindings->keyText('app.tools.expand')));
            $this->addSummaryCostNotice($jump->summary);
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
     * `/fork` — pick one of your own messages, and the conversation up to before it becomes a
     * session file of its own, with that message back in the prompt to be asked differently.
     *
     * Upstream's `showUserMessageSelector()`, as a `SelectList` over every message somebody typed,
     * on any branch, newest last and the cursor on the newest — which is the one a fork is
     * nearly always made from.
     */
    private function showForkPoints(): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        $messages = $this->session->userMessagesForForking();

        if ($messages === []) {
            $this->say('No messages to fork from');

            return;
        }

        $items = [];

        foreach ($messages as $index => $message) {
            // `TreeList`'s one-line rule, because this is the same text in a list of the same kind:
            // sanitised, or one byte that is not UTF-8 ends the session, and a newline would be two rows.
            $items[] = new SelectItem((string) $index, TreeList::oneLine($message['text']));
        }

        $picker = new SelectList($items, 8, Themes::getSelectListTheme());
        $picker->setSelectedIndex(count($items) - 1);
        $picker->setSelectHandler(function (SelectItem $item) use ($messages): void {
            $this->closePicker();
            Async::spawn(fn () => $this->forkAt($messages[(int) $item->value]['entryId'], 'before'));
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('muted', 'Fork from — enter to choose, esc to cancel'), 1, 0));
        $this->overlay->addChild($picker);
        $this->overlay->addChild(new Spacer(1));

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /** `/clone` — this conversation as it stands, in a session file of its own. */
    private function cloneSession(): void
    {
        if ($this->session->isStreaming()) {
            $this->sayWarning('Still working. Press esc first.');

            return;
        }

        $leaf = $this->session->store()?->leaf();

        if ($leaf === null) {
            $this->say('Nothing to clone yet');

            return;
        }

        // In a fiber: `fork()` awaits the abort, which suspends. Same reason `send()` spawns.
        Async::spawn(fn () => $this->forkAt($leaf, 'at'));
    }

    /**
     * The fork itself, for both doors: the hook, the new file and the restored conversation are
     * `AgentSession::fork()`'s, and what is left here is the screen.
     *
     * @param 'before'|'at' $position
     */
    private function forkAt(string $entryId, string $position): void
    {
        try {
            $fork = $this->session->fork($entryId, $position);
        } catch (Throwable $error) {
            $this->sayError($error->getMessage());

            return;
        }

        if (!$fork->switched) {
            $this->say('A hook stopped that.');

            return;
        }

        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();
        $this->say($position === 'at' ? 'Cloned to new session' : 'Forked to new session');

        // The message that was forked before, back in the prompt — upstream's
        // `editor.setText(result.selectedText ?? "")`, which also clears it for a clone.
        $this->editor->setText($fork->editorText ?? '');

        $this->sayToolProblems($this->customTools?->notify('fork', $fork->previous) ?? []);
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

        $this->showLoader($this->branchSummaryLabel(), timer: true);

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
     * Upstream's `getLoginProviderOptions(authType)`: a row per way into each provider — its account
     * sign-in (the built-in `Provider` cases and the extensions' `OauthFlow`s) and its API key (the
     * built-in providers' and the extensions'), each with how it is configured now — sorted by name.
     *
     * @param 'oauth'|'api_key'|null $authType
     * @return list<AuthSelectorProvider>
     */
    private function loginProviderOptions(?string $authType = null): array
    {
        $options = [];

        if ($authType !== 'api_key') {
            foreach (Auth::signIns() as $flow) {
                $id = $flow instanceof Provider ? $flow->value : $flow->id();
                $name = $flow instanceof Provider ? BuiltinProviders::name($id) : $flow->label();
                $options[] = new AuthSelectorProvider($id, $name, 'oauth', $this->authStatus($id), $flow->isSubscription(), $flow, $flow->label());
            }
        }

        if ($authType !== 'oauth') {
            $extensions = [];

            foreach (ProviderRegistry::apiKeyProviders() as $provider) {
                \assert($provider->apiKeyAuth !== null);
                $extensions[$provider->id] = true;
                $options[] = new AuthSelectorProvider(
                    $provider->id,
                    $provider->name,
                    'api_key',
                    $this->authStatus($provider->id),
                    Auth::signIn($provider->id)?->isSubscription() ?? false,
                    $provider,
                    $provider->apiKeyAuth->name(),
                );
            }

            foreach (BuiltinProviders::apiKeyIds() as $id) {
                if (!isset($extensions[$id])) {
                    $extensions[$id] = true;
                    $options[] = new AuthSelectorProvider(
                        $id,
                        BuiltinProviders::name($id),
                        'api_key',
                        $this->authStatus($id),
                        Auth::signIn($id)?->isSubscription() ?? false,
                        $id,
                        BuiltinProviders::apiKeyAuth($id)?->name() ?? '',
                    );
                }
            }

            foreach (Models::providers() as $id) {
                if (!isset($extensions[$id]) && Auth::signIn($id) === null) {
                    $extensions[$id] = true;
                    $name = BuiltinProviders::has($id) ? BuiltinProviders::name($id) : ucwords(str_replace(['-', '_'], ' ', $id));
                    $auth = BuiltinProviders::apiKeyAuth($id) ?? new EnvApiKeyAuth("{$name} API key", Stream::envApiKeyNames($id));
                    $options[] = new AuthSelectorProvider(
                        $id,
                        $name,
                        'api_key',
                        $this->authStatus($id),
                        false,
                        $id,
                        $auth->name(),
                    );
                }
            }

            $options[] = new AuthSelectorProvider(
                '+custom',
                '+ Add custom provider (OpenAI compatible)...',
                'api_key',
                null,
                false,
                '+custom',
                'custom provider setup',
            );
        }

        usort($options, static function (AuthSelectorProvider $a, AuthSelectorProvider $b): int {
            if ($a->id === '+custom') {
                return -1;
            }
            if ($b->id === '+custom') {
                return 1;
            }

            return strcasecmp($a->name, $b->name);
        });

        return $options;
    }

    /**
     * Upstream's `getProviderAuthStatus()`: how a provider is configured now — a stored OAuth or API
     * key credential, or a key from where its api-key sign-in looks (the environment) — or null.
     *
     * @return array{type: 'oauth'|'api_key', source: string|null}|null
     */
    private function authStatus(string $id): ?array
    {
        $kind = $this->auth?->kind($id);

        if ($kind === 'oauth') {
            return ['type' => 'oauth', 'source' => 'OAuth'];
        }

        $apiKeyAuth = ProviderRegistry::apiKeyAuthFor($id) ?? BuiltinProviders::apiKeyAuth($id);
        $source = $apiKeyAuth?->check($this->auth?->apiKeyCredential($id));

        if ($source !== null) {
            return ['type' => 'api_key', 'source' => $source];
        }

        if ($kind === 'api_key') {
            return ['type' => 'api_key', 'source' => 'stored credential'];
        }

        // A key nothing above knows the source of: `models.json`, or ambient credentials.
        return $this->auth?->hasKeyFor($id) === true ? ['type' => 'api_key', 'source' => null] : null;
    }

    /** Upstream's `findLoginProviderOptions(providerRef)`: the rows whose id or name is it, ignoring case. */
    private function findLoginProviderOptions(string $providerRef): array
    {
        $wanted = strtolower(trim($providerRef));

        if ($wanted === '') {
            return [];
        }

        return array_values(array_filter(
            $this->loginProviderOptions(),
            static fn (AuthSelectorProvider $p): bool => strtolower($p->id) === $wanted || strtolower($p->name) === $wanted,
        ));
    }

    /**
     * Upstream's `handleLoginCommand()`: `/login` asks how; `/login <provider>` starts that provider's
     * sign-in when one row matches, asks how when its rows are one provider's two, and opens the
     * provider list searched for it otherwise.
     */
    private function handleLoginCommand(string $providerRef = ''): void
    {
        if ($this->auth === null) {
            $this->sayWarning('This session has nowhere to keep a sign-in.');

            return;
        }

        if ($providerRef === '') {
            $this->showLoginAuthTypeSelector();

            return;
        }

        $matches = $this->findLoginProviderOptions($providerRef);

        if (count($matches) === 1) {
            $this->startProviderLogin($matches[0]);

            return;
        }

        if (count($matches) > 1 && count(array_unique(array_map(static fn (AuthSelectorProvider $p): string => $p->id, $matches))) === 1) {
            $this->showLoginAuthTypeSelector($matches);

            return;
        }

        if ($matches === []) {
            $this->setupCustomProvider($providerRef);

            return;
        }

        $this->showLoginProviderSelector(null, $providerRef);
    }

    /**
     * Upstream's `startProviderLogin()`: an account's sign-in, an API key's, or — for a provider whose
     * key pig reads from the environment only — what to set. `$onBack` reopens where it was started
     * from when the sign-in is cancelled.
     */
    private function startProviderLogin(AuthSelectorProvider $option, ?Closure $onBack = null): void
    {
        if ($option->id === '+custom') {
            $this->setupCustomProvider();

            return;
        }

        if ($option->authType === 'oauth') {
            \assert($option->sign instanceof Provider || $option->sign instanceof OauthFlow);
            $this->signIn($option->sign, $onBack);

            return;
        }

        $apiKeyAuth = $option->sign instanceof ExtensionProvider
            ? $option->sign->apiKeyAuth
            : (BuiltinProviders::apiKeyAuth($option->id) ?? (BuiltinProviders::has($option->id) ? null : new EnvApiKeyAuth("{$option->name} API key", Stream::envApiKeyNames($option->id))));

        if ($apiKeyAuth !== null) {
            // If it is a custom provider that has NO models defined yet, launch the setup wizard to discover them
            $hasModels = !empty(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === $option->id));

            if (!BuiltinProviders::has($option->id) && !($option->sign instanceof ExtensionProvider) && !$hasModels) {
                $this->setupCustomProvider($option->id);

                return;
            }

            $this->signInWithApiKey($option->id, $option->name, $apiKeyAuth, $onBack);

            return;
        }

        // Upstream's `showAmbientAuthDialog()`.
        $env = Stream::envApiKeyNames($option->id);
        $this->say("{$option->name} is configured outside pig." . ($env === [] ? '' : ' Set ' . implode(' or ', $env) . '.'));
        $onBack?->__invoke();
    }

    /**
     * Interactive wizard for setting up custom OpenAI-compatible providers: prompts for base URL and
     * API key, automatically fetches models from $baseUrl/models, and saves to models.json & auth.json.
     */
    private function setupCustomProvider(string $providerRef = ''): void
    {
        $id = strtolower(trim($providerRef));

        Async::spawn(function () use ($id): void {
            $controller = new AbortController();
            $this->signingIn = $controller;

            try {
                $targetId = $id;

                if ($targetId === '' || $targetId === '+custom') {
                    $enteredId = $this->ui->input(
                        'Enter provider ID (e.g. my-gateway)',
                        'my-gateway',
                        $controller->signal,
                    );

                    if ($enteredId === null || trim($enteredId) === '') {
                        $this->say('Setup cancelled.');

                        return;
                    }

                    $targetId = strtolower(trim($enteredId));
                }

                $displayName = ucwords(str_replace(['-', '_'], ' ', $targetId));

                $existingBaseUrl = $this->findExistingCustomBaseUrl($targetId);
                $urlPrompt = $existingBaseUrl !== null
                    ? "Enter {$displayName} base URL"
                    : "Enter {$displayName} base URL (e.g. https://api.example.com/v1)";
                $urlPlaceholder = $existingBaseUrl ?? 'https://api.example.com/v1';

                // Step 1: Base URL
                $baseUrl = $this->ui->input($urlPrompt, $urlPlaceholder, $controller->signal);

                if ($baseUrl === null) {
                    $this->say('Setup cancelled.');

                    return;
                }

                $baseUrl = trim($baseUrl);
                if ($baseUrl === '' && $existingBaseUrl !== null) {
                    $baseUrl = $existingBaseUrl;
                }

                if ($baseUrl === '') {
                    $this->sayError('Base URL cannot be empty.');

                    return;
                }

                $baseUrl = rtrim($baseUrl, '/');

                // Step 2: API key
                $existingKey = $this->auth?->apiKey($targetId);
                $keyPrompt = $existingKey !== null && $existingKey !== ''
                    ? "Enter {$displayName} API key (leave blank to keep current key)"
                    : "Enter {$displayName} API key (leave blank if none)";
                $keyPlaceholder = $existingKey !== null && $existingKey !== ''
                    ? 'current key stored'
                    : 'sk-...';

                $apiKey = $this->ui->input(
                    $keyPrompt,
                    $keyPlaceholder,
                    $controller->signal,
                );

                if ($apiKey === null) {
                    $this->say('Setup cancelled.');

                    return;
                }

                $apiKey = trim($apiKey);
                if ($apiKey === '' && $existingKey !== null && $existingKey !== '') {
                    $apiKey = $existingKey;
                }

                // Step 3: Fetch models from $baseUrl/models
                $this->say("Discovering models from {$baseUrl}/models...");
                $modelList = $this->fetchOpenAiModels($baseUrl, $apiKey);

                if ($modelList === []) {
                    $fallbackId = $targetId . '-model';
                    $this->sayWarning("Could not discover models from /models. Created default model: {$fallbackId}");
                    $modelList = [[
                        'id' => $fallbackId,
                        'name' => $displayName,
                        'reasoning' => true,
                        'input' => ['text', 'image'],
                        'contextWindow' => 128_000,
                        'maxTokens' => 8192,
                    ]];
                } else {
                    $this->say('Found ' . count($modelList) . " models from endpoint.");
                }

                // Step 4: Save to ~/.pig/agent/models.json
                $this->saveCustomProviderToModelsJson($targetId, $displayName, $baseUrl, $modelList);

                // Step 5: Save API key to auth.json if provided
                if ($apiKey !== '') {
                    $this->auth?->setApiKey($targetId, $apiKey);
                }

                // Step 6: Reload custom models
                $custom = CustomModels::discover();
                $custom->install($this->auth);

                $count = count($modelList);
                $firstModel = $modelList[0]['id'];
                $this->say("✓ Added {$displayName} with {$count} model" . ($count === 1 ? '' : 's') . " to models.json.");

                // If the session has a narrowed modelScope, ensure the new model is included
                $scope = $this->session->modelScope();
                if ($scope !== []) {
                    $foundNewModel = Models::find($targetId, $firstModel);
                    if ($foundNewModel !== null) {
                        $scope[] = new ModelChoice($foundNewModel);
                        $this->session->setModelScope($scope);
                    }
                }

                // Automatically switch to the first model
                $this->pickModel("{$targetId}/{$firstModel}");
            } catch (Throwable $e) {
                $this->sayError("Failed to configure {$displayName}: {$e->getMessage()}");
            } finally {
                $this->signingIn = null;
            }
        });
    }

    private function findExistingCustomBaseUrl(string $id): ?string
    {
        $file = Config::home() . '/models.json';
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['providers'][$id]['baseUrl'])) {
            return null;
        }

        $url = $decoded['providers'][$id]['baseUrl'];

        return is_string($url) ? $url : null;
    }

    /** @return list<array{id: string, name: string, reasoning: bool, input: list<'text'|'image'>, contextWindow: int, maxTokens: int}> */
    private function fetchOpenAiModels(string $baseUrl, string $apiKey): array
    {
        $url = rtrim($baseUrl, '/') . '/models';
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'pig'];
        if ($apiKey !== '') {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        $body = '';

        try {
            $client = new HttpClient(10.0);
            $response = $client->follow(new Request('GET', $url, $headers));
            $body = $response->body->all();
        } catch (Throwable) {
            return [];
        }

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = is_array($decoded['data'] ?? null)
            ? $decoded['data']
            : (is_array($decoded['models'] ?? null) ? $decoded['models'] : (is_array($decoded) ? $decoded : []));

        $models = [];
        foreach ($items as $item) {
            $mid = null;
            if (is_string($item)) {
                $mid = trim($item);
            } elseif (is_array($item)) {
                $mid = trim((string) ($item['id'] ?? $item['name'] ?? ''));
            }
            if ($mid !== null && $mid !== '') {
                $models[$mid] = [
                    'id' => $mid,
                    'name' => $mid,
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'contextWindow' => 128_000,
                    'maxTokens' => 8192,
                ];
            }
        }

        return array_values($models);
    }

    /** @param list<array<string, mixed>> $models */
    private function saveCustomProviderToModelsJson(string $id, string $name, string $baseUrl, array $models): void
    {
        $dir = Config::home();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $file = $dir . '/models.json';
        $existing = [];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        if (!isset($existing['providers']) || !is_array($existing['providers'])) {
            $existing['providers'] = [];
        }

        $existing['providers'][$id] = [
            'name' => $name,
            'api' => 'openai-completions',
            'baseUrl' => $baseUrl,
            'models' => $models,
        ];

        file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * Upstream's `showLoginAuthTypeSelector()`: "Sign in with an account", "Sign in with an API key",
     * and — at the top level only — "Sign in with Radius" with its status. For one provider's rows it
     * is that provider's ways in, and with only one of them it starts that one.
     *
     * @param list<AuthSelectorProvider>|null $providerOptions
     */
    private function showLoginAuthTypeSelector(?array $providerOptions = null): void
    {
        $radius = null;

        if ($providerOptions === null) {
            foreach ($this->loginProviderOptions('oauth') as $option) {
                if ($option->id === Provider::Radius->value) {
                    $radius = $option;
                }
            }
        }

        $accountLabel = 'Sign in with an account';
        $apiKeyLabel = 'Sign in with an API key';
        $radiusLabel = $radius === null ? null : "Sign in with {$radius->name}" . OAuthSelectorComponent::statusLabel($radius);
        $types = $providerOptions === null
            ? ['oauth' => true, 'api_key' => true]
            : array_fill_keys(array_map(static fn (AuthSelectorProvider $p): string => $p->authType, $providerOptions), true);
        $labels = [];

        if (isset($types['oauth'])) {
            $labels[] = $accountLabel;
        }

        if (isset($types['api_key'])) {
            $labels[] = $apiKeyLabel;
        }

        if ($radiusLabel !== null) {
            $labels[] = $radiusLabel;
        }

        if ($labels === []) {
            $this->say('No login methods available.');

            return;
        }

        if ($providerOptions !== null && count($labels) === 1) {
            $this->startProviderLogin($providerOptions[0]);

            return;
        }

        $title = $providerOptions !== null
            ? "Select authentication method for {$providerOptions[0]->name}:"
            : 'Select authentication method:';
        $back = fn () => $this->showLoginAuthTypeSelector($providerOptions);

        $this->showListPicker($title, $labels, function (string $picked) use ($radius, $radiusLabel, $accountLabel, $providerOptions, $back): void {
            if ($radius !== null && $picked === $radiusLabel) {
                $this->startProviderLogin($radius, $back);

                return;
            }

            $authType = $picked === $accountLabel ? 'oauth' : 'api_key';

            if ($providerOptions !== null) {
                foreach ($providerOptions as $option) {
                    if ($option->authType === $authType) {
                        $this->startProviderLogin($option, $back);

                        return;
                    }
                }

                return;
            }

            $this->showLoginProviderSelector($authType);
        });
    }

    /**
     * Upstream's `showLoginProviderSelector()`: the providers for one kind of sign-in (or both), in the
     * searchable list; Escape goes back to the kinds when it came from them.
     *
     * @param 'oauth'|'api_key'|null $authType
     */
    private function showLoginProviderSelector(?string $authType = null, string $initialSearchInput = ''): void
    {
        $options = $this->loginProviderOptions($authType);

        if ($options === []) {
            $this->say(match ($authType) {
                'oauth' => 'No account providers available.',
                'api_key' => 'No API key providers available.',
                default => 'No login providers available.',
            });

            return;
        }

        $selector = new OAuthSelectorComponent(
            'login',
            $options,
            function (AuthSelectorProvider $option) use ($authType, $initialSearchInput): void {
                $this->closePicker();
                $this->startProviderLogin($option, fn () => $this->showLoginProviderSelector($authType, $initialSearchInput));
            },
            function () use ($authType): void {
                $this->closePicker();

                if ($authType !== null) {
                    $this->showLoginAuthTypeSelector();
                }
            },
            $initialSearchInput,
        );

        $this->showOverlay($selector);
    }

    /**
     * Upstream's `showOAuthSelector("logout")`: the credentials `/login` stored, and only those —
     * environment variables and `models.json` are not something it can take away.
     */
    private function showLogoutSelector(): void
    {
        if ($this->auth === null) {
            $this->sayWarning('This session has nowhere to keep a sign-in.');

            return;
        }

        $options = [];

        foreach ($this->auth->providers() as $id) {
            $kind = $this->auth->kind($id);

            if ($kind !== 'oauth' && $kind !== 'api_key') {
                continue;
            }

            $flow = Auth::signIn($id);
            $name = ProviderRegistry::get($id)?->name ?? (BuiltinProviders::has($id) ? BuiltinProviders::name($id) : ($flow instanceof OauthFlow ? $flow->label() : $id));
            $options[] = new AuthSelectorProvider($id, $name, $kind, ['type' => $kind, 'source' => 'stored credential'], $flow?->isSubscription() ?? false);
        }

        usort($options, static fn (AuthSelectorProvider $a, AuthSelectorProvider $b): int => strcasecmp($a->name, $b->name));

        if ($options === []) {
            $this->say('No stored credentials to remove. /logout only removes credentials saved by /login; environment variables and models.json config are unchanged.');

            return;
        }

        $selector = new OAuthSelectorComponent(
            'logout',
            $options,
            function (AuthSelectorProvider $option): void {
                $this->closePicker();

                try {
                    $this->auth?->remove($option->id);
                    $this->footer->invalidate();
                    $this->say($option->authType === 'oauth'
                        ? "Logged out of {$option->name}"
                        : "Removed stored API key for {$option->name}. Environment variables and models.json config are unchanged.");
                } catch (Throwable $problem) {
                    $this->sayError("Logout failed: {$problem->getMessage()}");
                }
            },
            $this->closePicker(...),
        );

        $this->showOverlay($selector);
    }

    /**
     * A plain list in the overlay with a title over it — upstream's `ExtensionSelectorComponent` as
     * `/login` uses it.
     *
     * @param list<string> $labels
     * @param Closure(string): void $onPick
     */
    private function showListPicker(string $title, array $labels, Closure $onPick): void
    {
        $picker = new SelectList(array_map(static fn (string $label): SelectItem => new SelectItem($label), $labels), 8, Themes::getSelectListTheme());
        $picker->setSelectHandler(function (SelectItem $item) use ($onPick): void {
            $this->closePicker();
            $onPick($item->value);
        });
        $picker->setCancelHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('accent', Style::bold($title)), 1, 0));
        $this->overlay->addChild($picker);
        $this->overlay->addChild(new Spacer(1));

        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    private function showOverlay(Component $component): void
    {
        $this->overlay->clear();
        $this->overlay->addChild($component);
        $this->tui->setFocus($component);
        $this->tui->requestRender();
    }

    /**
     * Show the URL, wait for what comes back, and keep the tokens.
     *
     * Spawned, because the paste box parks the fiber it is asked on and this is running
     * inside the loop's own input callback — suspending there suspends the loop that has to
     * deliver the keystrokes. Same reason `send()` and `startCompaction()` spawn.
     */
    private function signIn(Provider|OauthFlow $provider, ?Closure $onBack = null): void
    {
        if ($provider instanceof Provider && !$provider->available()) {
            $this->sayWarning("{$provider->label()} is not ported yet — pig cannot finish that sign-in.");

            return;
        }

        Async::spawn(function () use ($provider, $onBack): void {
            // Escape has to be able to end this: Copilot's flow polls GitHub for up to fifteen
            // minutes, and a fiber waiting that long with nothing able to stop it is a session
            // that has to be killed. `interrupt()` reaches this controller.
            $controller = new AbortController();
            $this->signingIn = $controller;
            $this->programStatus->setBlocked('login', new BlockedStatus('auth', "Log in to {$provider->label()}"));

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
                    function (string $title, array $options) use ($provider): ?string {
                        $labels = array_map(static fn (array $option): string => $option[1], $options);
                        // Upstream's Radius sign-in says what Radius is above its choice.
                        if ($provider === Provider::Radius) {
                            $this->say('Radius is a service crafted for Pi by the builders of Pi, Earendil Works');
                        }

                        $picked = $this->ui->select($title, $labels);

                        foreach ($options as [$id, $label]) {
                            if ($label === $picked) {
                                return $id;
                            }
                        }

                        return null;
                    },
                );

                if ($credentials === null) {
                    $onBack === null ? $this->say('Signing in was cancelled.') : $onBack();
                } else {
                    $id = $provider instanceof Provider ? $provider->value : $provider->id();
                    $name = $provider instanceof Provider ? BuiltinProviders::name($id) : $provider->label();
                    $this->completeProviderAuthentication($id, "Logged in to {$name}");
                }
            } catch (Throwable $problem) {
                $this->sayError($problem->getMessage());
            } finally {
                $this->signingIn = null;
                $this->programStatus->setBlocked('login', null);
            }

            $this->tui->requestRender();
        });
    }

    /**
     * An extension provider's api-key sign-in — upstream's `showApiKeyLoginDialog()`: the flow's
     * prompts in the same box OAuth's paste uses, the credential kept, and upstream's wording for
     * what happened — `Saved API key for <name>`, or `Failed to save API key for <name>: <error>`.
     * llama.cpp's guidance is upstream's `llamaCppPostLoginGuidance()`, said when no model is
     * selected yet.
     */
    private function signInWithApiKey(string $id, string $name, ApiKeyAuth $apiKeyAuth, ?Closure $onBack = null): void
    {
        Async::spawn(function () use ($id, $name, $apiKeyAuth, $onBack): void {
            $controller = new AbortController();
            $this->signingIn = $controller;
            $this->programStatus->setBlocked('login', new BlockedStatus('auth', "Log in to {$name}"));

            try {
                $credential = $this->auth?->loginApiKey(
                    $id,
                    $apiKeyAuth,
                    fn (string $message, string $placeholder, bool $allowEmpty, ?AbortSignal $closing = null): ?string
                        => $this->ui->input($message, $placeholder, $closing),
                    $controller->signal,
                );

                if ($credential === null) {
                    $onBack === null ? $this->say('Signing in was cancelled.') : $onBack();
                } else {
                    $this->completeProviderAuthentication($id, "Saved API key for {$name}");
                }
            } catch (Throwable $problem) {
                $this->sayError("Failed to save API key for {$name}: {$problem->getMessage()}");
            } finally {
                $this->signingIn = null;
                $this->programStatus->setBlocked('login', null);
            }

            $this->tui->requestRender();
        });
    }

    /**
     * Upstream's `completeProviderAuthentication()`: with no model chosen yet, the provider's default
     * one (`ModelResolver::DEFAULT_MODEL_PER_PROVIDER`, Radius's first when it has no `balanced`) or
     * why not; where the credential went; llama.cpp's guidance (upstream's
     * `llamaCppPostLoginGuidance()`); and the provider's catalog refreshed.
     */
    private function completeProviderAuthentication(string $providerId, string $actionLabel): void
    {
        $this->footer->invalidate();
        $selected = null;
        $selectionError = null;

        if ($this->session->model() === null) {
            $providerModels = array_values(array_filter(
                $this->auth?->availableModels() ?? [],
                static fn (Model $model): bool => $model->provider === $providerId,
            ));
            $defaultId = ModelResolver::DEFAULT_MODEL_PER_PROVIDER[$providerId] ?? null;

            if ($providerId === 'llama.cpp') {
                // Matches LLAMA_PROVIDER_ID in extensions/pig-llama; kept inline, as upstream keeps it.
                $selectionError = $providerModels === []
                    ? "{$actionLabel}. No llama.cpp models are loaded. Use /llama to load a model, then /model to select it."
                    : "{$actionLabel}. Use /model to select a loaded llama.cpp model, or /llama to manage models.";
            } elseif ($defaultId === null) {
                $selectionError = "{$actionLabel}, but no default model is configured for provider \"{$providerId}\". Use /model to select a model.";
            } elseif ($providerModels === []) {
                $selectionError = "{$actionLabel}, but no models are available for that provider. Use /model to select a model.";
            } else {
                foreach ($providerModels as $model) {
                    if ($model->id === $defaultId) {
                        $selected = $model;
                    }
                }

                // Radius catalogs vary by account; prefer balanced, then use catalog order.
                $selected ??= $providerId === 'radius' ? $providerModels[0] : null;

                if ($selected === null) {
                    $selectionError = "{$actionLabel}, but its default model \"{$defaultId}\" is not available. Use /model to select a model.";
                } else {
                    try {
                        $this->session->setModel($selected, persistAsDefault: true);
                        $this->paintBorder();
                    } catch (Throwable $error) {
                        $selected = null;
                        $selectionError = "{$actionLabel}, but selecting its default model failed: {$error->getMessage()}. Use /model to select a model.";
                    }
                }
            }
        }

        if ($selected !== null) {
            $this->say("{$actionLabel}. Selected {$selected->id}. Credentials saved to {$this->auth?->path()}");
            $this->maybeWarnAboutAnthropicSubscriptionAuth($selected);
        } else {
            $this->say("{$actionLabel}. Credentials saved to {$this->auth?->path()}");

            if ($selectionError !== null) {
                $this->sayError($selectionError);
            } else {
                $this->maybeWarnAboutAnthropicSubscriptionAuth();
            }
        }

        $this->refreshAfterSignIn($providerId, $actionLabel);
    }

    /**
     * Upstream's catalog refresh after a sign-in: this provider's alone, in the background, with
     * the 15-second budget, and a warning when it timed out or failed — the cached models stay.
     * Nothing for a provider whose catalog is not fetched.
     */
    private function refreshAfterSignIn(string $providerId, string $actionLabel): void
    {
        $provider = ProviderRegistry::get($providerId);

        if ($this->auth === null || $provider?->refreshModels === null) {
            return;
        }

        ModelRefresh::inBackground($this->auth, [$providerId], done: function (ModelRefreshResult $result) use ($actionLabel, $providerId): void {
            if ($result->aborted) {
                $this->sayWarning("{$actionLabel}, but its model catalog refresh timed out; using cached models.");
            } elseif (isset($result->errors[$providerId])) {
                $this->sayWarning("{$actionLabel}, but its model catalog could not be refreshed; using cached models.");
            }

            $this->tui->requestRender();
        });
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
     *
     * **`PIG_OFFLINE=1` means no browser**, the same switch that stops `ToolInstaller` fetching.
     * It is not only for a machine with no network: the test suite drives `/login` through a
     * `FakeTerminal`, and until this every run of it opened the developer's real browser on
     * Anthropic's authorize page — a sign-in nobody asked for, against an account that can be
     * suspended for exactly that. `InteractiveModeTest::setUp()` sets it; the URL is still said
     * on screen, which is what the tests read.
     */
    private static function openInBrowser(string $url): void
    {
        if (!ToolInstaller::enabled()) {
            return;
        }

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
        if (!$this->renderer->terminal instanceof \Pig\Tui\ProcessTerminal) {
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
        $this->overlay->addChild(new CommandHudComponent());
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

    /**
     * Replace this conversation with a saved one, and redraw it.
     *
     * @param string $cancelled what is said when the missing-directory question is answered no —
     *        upstream's `Resume cancelled`, or `/import`'s `Import cancelled`
     * @return bool whether it switched; false when it failed or a hook stopped it, both already said
     */
    private function resume(SessionInfo $info, string $cancelled = 'Resume cancelled'): bool
    {
        $was = $this->session->model()?->id;
        $inCurrentCwd = false;

        // Asking the hooks, opening the file and restoring the model are `switchTo()`'s, so
        // that a host switching session over RPC gets the same three and not two of them. A
        // session from another project carries on in its own directory, in a runtime built for
        // it (`relocate`); one whose directory has gone is asked about first — upstream's
        // `handleResumeSession()` and `promptForMissingSessionCwd()`.
        try {
            try {
                $switch = $this->session->switchTo($info->path, relocate: $this->relocator());
            } catch (MissingSessionCwdError $missing) {
                if (!$this->ui->confirm('Session cwd not found', SessionCwd::prompt($missing->issue))) {
                    $this->say($cancelled);

                    return false;
                }

                $switch = $this->session->switchTo($info->path, $missing->issue->fallbackCwd, $this->relocator());
                $inCurrentCwd = true;
            }
        } catch (Throwable $error) {
            // The old session is gone once the factory has been called: upstream's
            // `handleFatalRuntimeError("Failed to resume session", …)`.
            if ($this->replacingRuntime) {
                $this->replacingRuntime = false;
                $this->fatal('Failed to resume session', $error);

                return false;
            }

            $this->sayError($error->getMessage());

            return false;
        }

        if (!$switch->switched) {
            $this->say('A hook stopped that.');

            return false;
        }

        $previous = $switch->previous;

        if ($switch->replacement !== null) {
            $this->rebind($switch->replacement, $previous);
        }

        $this->chat->clear();
        $this->pending->clear();
        $this->replay();
        $this->footer->invalidate();

        // Said after the transcript, so it is the last thing on screen rather than the
        // first thing buried above a conversation.
        $this->say('Resumed ' . $switch->messages . ' messages from ' . $info->when() . ($inCurrentCwd ? ' in current cwd' : ''));

        $now = $this->session->model()?->id;

        if ($now !== null && $now !== $was) {
            // Worth a line: the model changing under someone without being told is how a
            // surprising answer becomes a puzzle.
            $this->sayThemed(static fn (): string => Themes::theme()->fg('muted', "This conversation was on {$now} — switched back to it."));
        }

        $this->sayIfStoppedMidRun();

        if ($switch->replacement === null) {
            $this->sayToolProblems($this->customTools?->notify('switch', $previous) ?? []);
        } else {
            $this->startRebound($switch->replacement, $previous);
        }

        return true;
    }

    /**
     * `switchTo()`'s `$relocate` for this screen: `bin/pig`'s runtime factory, asking about the new
     * directory's trust here rather than on a terminal of its own — upstream's
     * `createProjectTrustContext(cwd)` for the interactive runtime. Null when there is no factory.
     *
     * @return (Closure(string, SessionManager): \Pig\CodingAgent\StartedSession)|null
     */
    private function relocator(): ?Closure
    {
        $createRuntime = $this->createRuntime;

        if ($createRuntime === null) {
            return null;
        }

        return function (string $cwd, SessionManager $opened) use ($createRuntime): \Pig\CodingAgent\StartedSession {
            $askTrust = function (array $choices) use ($cwd): ?\Pig\CodingAgent\TrustChoice {
                $labels = array_map(static fn (\Pig\CodingAgent\TrustChoice $choice): string => $choice->label, $choices);
                $answer = $this->ui->select(ProjectTrust::prompt($cwd), $labels);

                foreach ($choices as $choice) {
                    if ($choice->label === $answer) {
                        return $choice;
                    }
                }

                return null;
            };

            $this->replacingRuntime = true;
            $started = $createRuntime($cwd, $opened, $askTrust);
            $this->replacingRuntime = false;

            return $started;
        };
    }

    /**
     * Upstream's `handleFatalRuntimeError()`: the error on screen, and the program ends with exit
     * code 1 — for a runtime that could not be built after the old one was already let go of. The
     * old session's hooks heard `session_shutdown` from `switchTo()` already, so `stop()` does not
     * tell them again.
     */
    private function fatal(string $prefix, Throwable $error): void
    {
        $this->sayError("{$prefix}: {$error->getMessage()}");
        $this->tui->requestRender();
        $this->exitCode = 1;
        $this->hooks = null;
        Loop::get()->defer(fn () => $this->stop());
    }

    /** What the program exits with once the loop has ended: 0, or 1 after a fatal error. */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Carry on with the session a runtime factory built for another working directory — upstream's
     * `rebindSession()` after `runtimeHost.switchSession()`. The old session is shut down and
     * disposed already; what this screen kept of it (its directory, settings, trust, hooks, tools,
     * context files, skills, prompt templates and what the old extensions put on the prompt) is
     * replaced, the new one wired to the screen, and its hooks told `session_start` (`resume`, with
     * the file left) as upstream's `bindExtensions()` tells them — in `startRebound()`, after the
     * caller has drawn the conversation.
     */
    private function rebind(\Pig\CodingAgent\StartedSession $started, ?string $previous): void
    {
        $this->sayToolProblems($this->customTools?->notify('shutdown') ?? []);

        $this->session = $started->session;
        $this->cwd = $started->session->cwd();
        $this->settings = $started->session->settings() ?? $this->settings;
        $this->projectTrusted = $started->session->isProjectTrusted();
        $this->hooks = $started->hooks;
        $this->customTools = $started->customTools;
        $this->extensions = $started->extensions;
        $this->contextFiles = $started->contextFiles;
        $this->skills = $started->skills;
        $this->fileCommands = $started->fileCommands;
        $this->footer->rebind($this->session, $this->cwd, $this->settings);

        // As `/reload` does: what the extensions being replaced put on the prompt goes with them.
        $this->autocompleteWrappers = [];
        $this->setCustomEditorComponent(null);

        $this->wireSession();
        $this->listenToSession();
        $this->bindEditor();
    }

    /**
     * The second half of `rebind()`, once the conversation is on screen: what the new runtime had
     * to say, and its hooks' `session_start`.
     */
    private function startRebound(\Pig\CodingAgent\StartedSession $started, ?string $previous): void
    {
        foreach ($started->warnings as $warning) {
            $this->sayWarning($warning);
        }

        if (!$this->projectTrusted && ProjectTrust::hasResources($this->cwd)) {
            $this->sayWarning(ProjectTrust::warning());
        }

        $this->hooks->emit(new SessionStartEvent('resume', $previous));
        $this->takeExtensionResources($this->session->discoverResources('startup'));
        $this->sayToolProblems($this->customTools->notify('switch', $previous));
        $this->paintBorder();
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
        $this->armin = new ArminComponent($this->tui);

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
    /**
     * Upstream's `collapseChangelog` arm: one line in the same rules — the newest version the notes
     * name, and where the rest is.
     */
    private function sayChangelogCollapsed(string $markdown): void
    {
        $latest = preg_match('/##\s+\[?(\d+\.\d+\.\d+)\]?/', $markdown, $match) === 1 ? $match[1] : $this->version;

        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('border', $text)));
        $this->chat->addChild(new ThemedText(static fn (): string => "Updated to v{$latest}. Use " . Style::bold('/changelog') . ' to view full changelog.', 1, 0));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('border', $text)));
        $this->tui->requestRender();
    }

    private function sayChangelog(string $markdown): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('border', $text)));
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('accent', Style::bold('What\'s New')), 1, 0));
        // Upstream's spacer between the title and the notes, on top of the markdown's own top
        // padding: two blank rows, as `/changelog` and the post-upgrade block both draw it.
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Markdown($markdown, 1, 1, Themes::getMarkdownTheme()));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('border', $text)));
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
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('warning', $text)));
        $this->chat->addChild(new ThemedText(
            static fn (): string => Themes::theme()->fg('warning', Style::bold('Update Available')) . "\n\n"
                . Themes::theme()->fg('muted', "New version {$version} is available. Run ")
                . Themes::theme()->fg('accent', UpdateCheck::COMMAND) . "\n"
                . Themes::theme()->fg('muted', 'Changelog: https://pigagent.dev/changelog'),
            1,
            0,
        ));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('warning', $text)));
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
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('warning', $text)));
        $this->chat->addChild(new ThemedText(
            static fn (): string => Themes::theme()->fg('warning', Style::bold('Package Updates Available')) . "\n\n"
                . Themes::theme()->fg('muted', 'Package updates are available. Run ')
                . Themes::theme()->fg('accent', 'pig update --extensions') . "\n\n"
                . Themes::theme()->fg('muted', "Packages:\n")
                . $packageLines,
            1,
            0,
        ));
        $this->chat->addChild(new Rule(static fn (string $text): string => Themes::theme()->fg('warning', $text)));
        $this->tui->requestRender();
    }

    /**
     * `/theme <name>` switches to a theme and saves it; `/theme` alone opens the picker.
     */
    private function handleThemeCommand(string $arg = ''): void
    {
        if ($arg === '') {
            $this->showThemeSelector();

            return;
        }

        $names = $this->availableThemes();
        if (!in_array($arg, $names, true)) {
            $this->sayError("No theme called '{$arg}'. Available themes: " . implode(', ', $names) . '.');

            return;
        }

        $this->useTheme($arg);
    }

    /**
     * Upstream's theme selector: moving through the list previews each theme (`preview()`), enter
     * keeps the one under the cursor, and escape puts back the theme that was on.
     */
    private function showThemeSelector(): void
    {
        $names = $this->availableThemes();
        $current = $this->themeController->getThemeSelection();
        $items = [];
        $selectedIndex = 0;

        foreach ($names as $index => $name) {
            $isCurrent = $name === $current;
            if ($isCurrent) {
                $selectedIndex = $index;
            }
            $items[] = new SelectItem($name, $name . ($isCurrent ? ' ·' : ''), self::themeDescription($name));
        }

        $picker = new SelectList($items, 8, Themes::getSelectListTheme(), SelectListLayout::compact());
        $picker->setSelectedIndex($selectedIndex);
        $picker->setSelectionChangeHandler(function (SelectItem $item): void {
            $this->themeController->preview($item->value);
        });
        $picker->setSelectHandler(function (SelectItem $item): void {
            $this->closePicker();
            $this->useTheme($item->value);
        });
        $picker->setCancelHandler(function () use ($current): void {
            $this->closePicker();
            $this->themeController->preview($current ?? Themes::SYSTEM_THEME_NAME);
        });

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('muted', 'Pick a theme — enter to switch, esc to cancel'), 1, 0));
        $this->overlay->addChild($picker);
        $this->overlay->addChild(new Spacer(1));
        $this->tui->setFocus($picker);
        $this->tui->requestRender();
    }

    /**
     * `Themes::getAvailableThemes()`, with the custom theme files it had to skip said in the transcript:
     * upstream's resource loader reports those, and pig has no loader for themes.
     *
     * @return list<string>
     */
    private function availableThemes(): array
    {
        $names = Themes::getAvailableThemes();
        foreach (Themes::getCustomThemeErrors() as $problem) {
            $this->sayWarning("Skipped theme {$problem}");
        }

        return $names;
    }

    private static function themeDescription(string $name): string
    {
        return match ($name) {
            Themes::SYSTEM_THEME_NAME => "Generated from the terminal's own colours",
            'dark' => 'Default dark theme',
            'light' => 'Default light theme',
            'labra' => 'Cyberpunk dark olive & hot pink theme (from omarchy-labra)',
            default => 'Custom theme',
        };
    }

    /**
     * `/settings` — everything that can be changed from inside a session, in one screen.
     *
     * **Only things that take effect.** Every row here is read again after it is changed, so
     * pressing Enter on it does something. `terminal.showImages` was nearly left off for
     * failing that — pig stored it and nothing read it — and the answer was to give it its
     * reader rather than a row that saves a value nobody looks at. The two queue modes needed
     * the same kind of work and got it: `Agent` had no setters, because the anchor commit's queue
     * split removed upstream's.
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
                $this->themeController->getThemeSelection() ?? Themes::SYSTEM_THEME_NAME,
                'The colours pig is drawn in. system follows the terminal.',
                values: $this->availableThemes(),
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

        // Upstream offers the width only on a terminal that draws pictures.
        if (TerminalImage::getCapabilities()->images !== null) {
            $rows[] = new SettingItem(
                'imageWidthCells',
                'Image width',
                (string) $this->settings->imageWidthCells(),
                'Preferred inline image width in terminal cells',
                values: ['60', '80', '120'],
            );
        }
        $rows[] = new SettingItem(
            'steeringMode',
            'Steering mode',
            $this->session->steeringMode()->value,
            "Enter while streaming queues steering messages. 'one-at-a-time': deliver one, wait for response. 'all': deliver all at once.",
            values: ['one-at-a-time', 'all'],
        );
        $rows[] = new SettingItem(
            'followUpMode',
            'Follow-up mode',
            $this->session->followUpMode()->value,
            $this->keybindings->display('app.message.followUp') . " queues follow-up messages until agent stops. 'one-at-a-time': deliver one, wait for response. 'all': deliver all at once.",
            values: ['one-at-a-time', 'all'],
        );
        // Upstream's row. Read by the session on every request, so the next one goes this way.
        $rows[] = new SettingItem(
            'transport',
            'Transport',
            $this->settings->transport(),
            'Preferred transport for providers that support multiple transports',
            values: Settings::TRANSPORTS,
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
        // Upstream's row, label and description. Global only, as upstream's setting is.
        $rows[] = new SettingItem(
            'cacheWarming',
            'Cache warming',
            $this->settings->cacheWarmingMode(),
            'off; streaming while the agent runs; idle also between runs while continuation stays profitable',
            values: Settings::CACHE_WARMING_MODES,
        );
        // Upstream's rows, with its labels and descriptions.
        // Upstream's row, label and description.
        $rows[] = new SettingItem(
            'mermaid',
            'Mermaid diagrams',
            $this->settings->mermaidRenderingMode(),
            'Render Mermaid code blocks as Unicode diagrams',
            values: Settings::MERMAID_MODES,
        );
        $rows[] = new SettingItem(
            'showCacheMissNotices',
            'Cache miss notices',
            $this->settings->showCacheMissNotices() ? 'true' : 'false',
            'Show transcript notices for cache costs and provider recovery diagnostics',
            values: ['true', 'false'],
        );
        $quiet = $this->settings->quietStartup();
        $rows[] = new SettingItem(
            'quietStartup',
            'Quiet startup',
            $quiet === true ? 'true' : ($quiet === 'header' ? 'header' : 'false'),
            'Disable verbose printing at startup (header: keep only the startup header)',
            values: ['true', 'header', 'false'],
        );
        $rows[] = new SettingItem(
            'doubleEscapeAction',
            'Double-escape action',
            $this->settings->doubleEscapeAction(),
            'Action when pressing Escape twice with empty editor',
            values: ['tree', 'fork', 'none'],
        );
        $rows[] = new SettingItem(
            'treeFilterMode',
            'Tree filter mode',
            $this->settings->treeFilterMode(),
            'Default filter when opening /tree',
            values: Settings::TREE_FILTER_MODES,
        );
        $rows[] = new SettingItem(
            'warnings',
            'Warnings',
            'configure',
            'Enable or disable individual warnings',
            submenu: fn (string $current, Closure $done): Component => $this->warningsSubmenu($done),
        );
        $rows[] = new SettingItem(
            'modelThinking',
            'Default thinking level per model',
            ModelThinkingSubmenu::summary($this->settings->allModelThinkingLevels()),
            'Override the default thinking level for specific models. ' . $this->keybindings->keyText('app.thinking.cycle') . ' cycles in-session.',
            submenu: fn (string $current, Closure $done): Component => new ModelThinkingSubmenu(
                $this->modelsForThinkingOverrides(),
                $this->settings->allModelThinkingLevels(),
                ($model = $this->session->model()) !== null ? "{$model->provider}/{$model->id}" : null,
                ($id = $this->settings->defaultModel()) !== null ? ($this->settings->defaultProvider() ?? '') . "/{$id}" : null,
                $this->settings->defaultThinkingLevel() ?? CodingAgent::DEFAULT_THINKING_LEVEL,
                self::THINKING_DESCRIPTIONS,
                function (Model $model, ?ThinkingLevel $level): void {
                    $level === null
                        ? $this->settings->removeModelThinkingLevel($model->provider, $model->id)
                        : $this->settings->setModelThinkingLevel($model->provider, $model->id, $level);
                    $this->tui->requestRender();
                },
                $done,
            ),
        );

        // Upstream's two padding rows, with its labels and values.
        $rows[] = new SettingItem(
            'editorPaddingX',
            'Editor padding',
            (string) $this->settings->editorPaddingX(),
            'Horizontal padding for input editor (0-3)',
            values: ['0', '1', '2', '3'],
        );
        $rows[] = new SettingItem(
            'outputPad',
            'Output padding',
            (string) $this->outputPad,
            'Horizontal padding for messages, tool output, and command output',
            values: ['0', '1'],
        );
        $rows[] = new SettingItem(
            'autocompleteMaxVisible',
            'Autocomplete max items',
            (string) $this->settings->autocompleteMaxVisible(),
            'Max visible items in autocomplete dropdown (3-20)',
            values: ['3', '5', '7', '10', '15', '20'],
        );
        $rows[] = new SettingItem(
            'showTerminalProgress',
            'Terminal progress',
            $this->settings->showTerminalProgress() ? 'true' : 'false',
            'Show OSC 9;4 progress indicators in the terminal tab bar',
            values: ['true', 'false'],
        );
        $rows[] = new SettingItem(
            'collapseChangelog',
            'Collapse changelog',
            $this->settings->collapseChangelog() ? 'true' : 'false',
            'Show condensed changelog after updates',
            values: ['true', 'false'],
        );
        // Upstream's row and key; pig sends no install ping, so the description says what the
        // setting still does (`ProviderAttribution`).
        $rows[] = new SettingItem(
            'installTelemetry',
            'Install telemetry',
            $this->settings->installTelemetry() ? 'true' : 'false',
            'Tell OpenRouter, NVIDIA and Cloudflare that pig is the client calling them',
            values: ['true', 'false'],
        );
        $rows[] = new SettingItem(
            'defaultProjectTrust',
            'Default project trust',
            self::PROJECT_TRUST_LABELS[$this->settings->defaultProjectTrust()],
            'Fallback behavior when no extension or saved trust decision decides project trust',
            values: array_values(self::PROJECT_TRUST_LABELS),
        );
        $rows[] = new SettingItem(
            'autoResizeImages',
            'Auto-resize images',
            $this->settings->imageAutoResize() ? 'true' : 'false',
            'Resize large images to 2000x2000 max for better model compatibility',
            values: ['true', 'false'],
        );
        $rows[] = new SettingItem(
            'blockImages',
            'Block images',
            $this->settings->blockImages() ? 'true' : 'false',
            'Prevent images from being sent to LLM providers',
            values: ['true', 'false'],
        );
        $rows[] = new SettingItem(
            'enableSkillCommands',
            'Skill commands',
            $this->settings->enableSkillCommands() ? 'true' : 'false',
            'Register skills as /skill:name commands',
            values: ['true', 'false'],
        );

        // Upstream's TUI rows. The mode switches renderers in place (`switchTuiMode()`).
        $rows[] = new SettingItem(
            'tuiMode',
            'TUI mode',
            $this->tuiMode,
            "Interface layout; regular mode uses the terminal's normal scrollback",
            values: ['regular', 'fullscreen'],
        );
        $rows[] = new SettingItem(
            'fullscreenExitOutput',
            'Fullscreen exit output',
            $this->settings->fullscreenExitOutput(),
            'Print the transcript or only a session resume hint when exiting fullscreen mode',
            values: ['transcript', 'resume-hint'],
        );
        $rows[] = new SettingItem(
            'fullscreenScrollbar',
            'Fullscreen scrollbar',
            $this->settings->fullscreenScrollbar(),
            'Scrollbar behavior in fullscreen mode; has no effect in regular mode',
            values: ['auto', 'always', 'hidden'],
        );
        $rows[] = new SettingItem(
            'fullscreenCopyOnSelect',
            'Fullscreen copy on select',
            $this->settings->fullscreenCopyOnSelect() ? 'true' : 'false',
            'Automatically copy selected text in fullscreen mode',
            values: ['true', 'false'],
        );
        $wheelLines = $this->settings->fullscreenWheelScrollLines();
        $wheelValues = array_values(array_unique(array_filter([1, 2, 3, 5, 10, $wheelLines], 'is_int')));
        sort($wheelValues);
        $rows[] = new SettingItem(
            'fullscreenWheelScrollLines',
            'Fullscreen wheel scrolling',
            (string) $wheelLines,
            "Lines per mouse-wheel event in fullscreen mode; 'auto' speeds up fast wheel spins where the terminal does not",
            values: ['auto', ...array_map('strval', $wheelValues)],
        );

        $list = new SettingsList($rows, 8, Themes::getSettingsListTheme());
        $list->setChangeHandler(function (string $id, string $value) use ($list): void {
            if ($id === 'tuiMode') {
                $this->useTuiMode($value, $list);

                return;
            }
            $this->applySetting($id, $value);
            $list->setValue($id, $value);
        });
        $list->setCloseHandler($this->closePicker(...));

        $this->overlay->clear();
        $this->overlay->addChild(new Spacer(1));
        $this->overlay->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('muted', 'Settings — enter to change, esc when done'), 1, 0));
        $this->overlay->addChild($list);
        $this->overlay->addChild(new Spacer(1));

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
    private function thinkingSubmenu(array $levels, string $current, Closure $done, ?Closure $saveDefault = null): Component
    {
        $descriptions = [
            'off' => 'No reasoning',
            'minimal' => 'Very brief reasoning (~1k tokens)',
            'low' => 'Light reasoning (~2k tokens)',
            'medium' => 'Moderate reasoning (~8k tokens)',
            'high' => 'Deep reasoning (~16k tokens)',
            'xhigh' => 'Extra-high reasoning (~32k tokens)',
            'max' => 'Maximum reasoning',
        ];

        $items = [];
        $at = 0;

        foreach ($levels as $index => $level) {
            $items[] = new SelectItem($level->value, $level->value, $descriptions[$level->value] ?? null);

            if ($level->value === $current) {
                $at = $index;
            }
        }

        $list = new SelectList($items, count($items), Themes::getSelectListTheme(), SelectListLayout::compact());
        $list->setSelectedIndex($at);
        $list->setSelectHandler(static function (SelectItem $item) use ($done): void {
            $done($item->value);
        });
        $list->setCancelHandler(static function () use ($done): void {
            $done(null);
        });

        // Upstream's thinking selector: `app.thinking.save` (ctrl+s) sets the level as the default.
        if ($saveDefault !== null) {
            $list->setSaveDefaultHandler(static function (SelectItem $item) use ($saveDefault): void {
                $saveDefault($item->value);
            }, 'app.thinking.save');
        }

        return new SettingsSubmenu(
            $list,
            Themes::theme()->fg('accent', 'Thinking'),
            Themes::theme()->fg('muted', $saveDefault === null
                ? '  Enter to select · Esc to go back'
                : '  Enter to select · ' . $this->keybindings->display('app.thinking.save') . ' to set as default · Esc to go back'),
        );
    }

    /** Upstream's `THINKING_DESCRIPTIONS`, for the per-model submenu. */
    private const array THINKING_DESCRIPTIONS = [
        'off' => 'No reasoning',
        'minimal' => 'Very brief reasoning (~1k tokens)',
        'low' => 'Light reasoning (~2k tokens)',
        'medium' => 'Moderate reasoning (~8k tokens)',
        'high' => 'Deep reasoning (~16k tokens)',
        'xhigh' => 'Extra-high reasoning (~32k tokens)',
        'max' => 'Maximum reasoning',
    ];

    /**
     * Upstream's `availableDefaultModels`: what a key reaches, with the model in use first even
     * when the registry has no row for it (one an extension or a test built), since it is the one
     * somebody opening this row most likely means.
     *
     * @return list<Model>
     */
    private function modelsForThinkingOverrides(): array
    {
        $models = $this->auth?->availableModels() ?? Models::all();
        $current = $this->session->model();

        foreach ($models as $model) {
            if ($current === null || ($model->provider === $current->provider && $model->id === $current->id)) {
                return $models;
            }
        }

        return [$current, ...$models];
    }

    /** Upstream's `WarningSettingsSubmenu`: one row per warning, applied as it changes. @param Closure(?string): void $done */
    private function warningsSubmenu(Closure $done): Component
    {
        $list = new SettingsList([
            new SettingItem(
                'anthropicExtraUsage',
                'Anthropic extra usage',
                $this->settings->warnAnthropicExtraUsage() ? 'true' : 'false',
                'Warn when Anthropic subscription auth may use paid extra usage',
                values: ['true', 'false'],
            ),
        ], 10, Themes::getSettingsListTheme());
        $list->setChangeHandler(function (string $id, string $value) use ($list): void {
            $this->settings->setWarnAnthropicExtraUsage($value === 'true');
            $list->setValue($id, $value);
        });
        $list->setCloseHandler(static function () use ($done): void {
            $done(null);
        });

        return $list;
    }

    /** Upstream's `DEFAULT_PROJECT_TRUST_LABELS`: the words `/settings` shows for each `defaultProjectTrust`. */
    private const array PROJECT_TRUST_LABELS = ['ask' => 'Ask', 'always' => 'Always trust', 'never' => 'Never trust'];

    /** One row of `/settings`, applied. Unknown ids are impossible: this list built them. */
    private function applySetting(string $id, string $value): void
    {
        match ($id) {
            'theme' => $this->useTheme($value),
            'thinking' => $this->useThinkingLevel($value, persistAsDefault: true),
            'hideThinking' => $this->useHideThinking($value === 'hidden'),
            'showImages' => $this->useShowImages($value === 'drawn'),
            // Takes effect on the next queued message, which is the only time it is read —
            // nothing about the ones already waiting changes, and nothing should.
            'steeringMode' => $this->session->setSteeringMode(QueueMode::tryFrom($value) ?? QueueMode::OneAtATime),
            'followUpMode' => $this->session->setFollowUpMode(QueueMode::tryFrom($value) ?? QueueMode::OneAtATime),
            'installTelemetry' => $this->settings->setInstallTelemetry($value === 'true'),
            'mermaid' => $this->useMermaidRenderingMode($value),
            'transport' => $this->settings->setTransport(in_array($value, Settings::TRANSPORTS, true) ? $value : 'auto'),
            'editorPaddingX' => $this->useEditorPaddingX((int) $value),
            'outputPad' => $this->useOutputPad($value === '0' ? 0 : 1),
            'autoCompact' => $this->settings->setCompactionEnabled($value === 'on'),
            'autoRetry' => $this->settings->setRetryEnabled($value === 'on'),
            'cacheWarming' => $this->session->setCacheWarmingMode($value),
            'showCacheMissNotices' => $this->settings->setShowCacheMissNotices($value === 'true'),
            'quietStartup' => $this->settings->setQuietStartup($value === 'header' ? 'header' : $value === 'true'),
            'doubleEscapeAction' => $this->settings->setDoubleEscapeAction($value),
            'treeFilterMode' => $this->settings->setTreeFilterMode($value),
            'collapseChangelog' => $this->settings->setCollapseChangelog($value === 'true'),
            'defaultProjectTrust' => $this->settings->setDefaultProjectTrust(array_search($value, self::PROJECT_TRUST_LABELS, true) ?: 'ask'),
            'autoResizeImages' => $this->settings->setImageAutoResize($value === 'true'),
            'blockImages' => $this->settings->setBlockImages($value === 'true'),
            'enableSkillCommands' => $this->useSkillCommands($value === 'true'),
            'imageWidthCells' => $this->useImageWidthCells((int) $value),
            'autocompleteMaxVisible' => $this->useAutocompleteMaxVisible((int) $value),
            'showTerminalProgress' => $this->settings->setShowTerminalProgress($value === 'true'),
            'fullscreenExitOutput' => $this->settings->setFullscreenExitOutput($value),
            'fullscreenScrollbar' => $this->useFullscreenScrollbar($value),
            'fullscreenCopyOnSelect' => $this->useFullscreenCopyOnSelect($value === 'true'),
            'fullscreenWheelScrollLines' => $this->useFullscreenWheelScrollLines($value === 'auto' ? 'auto' : (int) $value),
            default => null,
        };
    }

    /** Upstream's `onEnableSkillCommandsChange`: saved, and the completion list rebuilt to match. */
    private function useSkillCommands(bool $enabled): void
    {
        $this->settings->setEnableSkillCommands($enabled);
        $this->setupAutocompleteProvider();
    }

    /** Upstream's `onEditorPaddingXChange`: saved, and on the editor that is up. */
    private function useEditorPaddingX(int $padding): void
    {
        $this->settings->setEditorPaddingX($padding);
        $this->defaultEditor->setPaddingX($padding);
        if ($this->editor !== $this->defaultEditor) {
            $this->editor->setPaddingX($padding);
        }
        $this->tui->requestRender();
    }

    /**
     * Upstream's `onOutputPadChange`: saved, remembered for what is drawn next, and told to
     * everything already on screen that has a `setOutputPad()`.
     *
     * @param 0|1 $padding
     */
    private function useOutputPad(int $padding): void
    {
        $this->settings->setOutputPad($padding);
        $this->outputPad = $padding;

        foreach ([$this->chat, $this->pending] as $container) {
            foreach ($container->children() as $child) {
                if (method_exists($child, 'setOutputPad')) {
                    $child->setOutputPad($padding);
                }
            }
        }

        $this->tui->requestRender();
    }

    /** Upstream's `onTuiModeChange`. */
    private function useTuiMode(string $value, SettingsList $list): void
    {
        $mode = $value === 'regular' ? 'regular' : 'fullscreen';
        if (!$this->switchTuiMode($mode)) {
            $list->setValue('tuiMode', $this->tuiMode);
            $this->say('Close active overlays before changing TUI mode');

            return;
        }
        $this->settings->setTuiMode($mode);
        $list->setValue('tuiMode', $mode);
        $this->say("TUI mode: {$mode}");
    }

    /** Upstream's `applyFullscreenScrollbarSetting()`, after saving. */
    private function useFullscreenScrollbar(string $mode): void
    {
        $mode = in_array($mode, ['always', 'hidden'], true) ? $mode : 'auto';
        $this->settings->setFullscreenScrollbar($mode);
        $this->viewport?->transcript->setScrollbar($mode);
        $this->tui->requestRender();
    }

    private function useFullscreenCopyOnSelect(bool $enabled): void
    {
        $this->settings->setFullscreenCopyOnSelect($enabled);
        if ($this->renderer instanceof TuiAltScreen) {
            $this->renderer->setCopyOnSelect($enabled);
        }
    }

    /** @param int|'auto' $lines */
    private function useFullscreenWheelScrollLines(int|string $lines): void
    {
        $this->settings->setFullscreenWheelScrollLines($lines);
        if ($this->renderer instanceof TuiAltScreen) {
            $this->renderer->setWheelScrollLines($this->settings->fullscreenWheelScrollLines());
        }
    }

    private function useThinkingLevel(string $value, bool $persistAsDefault = false): void
    {
        $level = ThinkingLevel::tryFrom($value);

        if ($level === null) {
            return;
        }

        $this->session->setThinkingLevel($level, persistAsDefault: $persistAsDefault);
        $this->footer->invalidate();
        $this->paintBorder();
    }

    /**
     * Switch to a theme by name and save it — the picker, `/theme <name>`, the `/settings` row and an
     * extension's `setTheme()`. Upstream's `themeController.setThemeName()` with the settings write.
     * A theme that does not load is reported and not saved; the controller has fallen back to the
     * system theme, as upstream's does.
     */
    private function useTheme(string $wanted): bool
    {
        $result = $this->themeController->setThemeName($wanted);
        if (!$result['success']) {
            $this->sayError("Failed to load theme \"{$wanted}\": {$result['error']}\nFell back to the system theme.");

            return false;
        }
        $this->settings->setTheme($wanted);
        $this->say("Theme: {$wanted}");

        return true;
    }

    // ---- what the agent is doing ----------------------------------------------------------

    private function onEvent(AgentEvent $event): void
    {
        $this->footer->invalidate();
        $this->programStatus->handleEvent($event);

        match (true) {
            $event instanceof AgentStartEvent => $this->onStart(),
            $event instanceof TurnStartEvent => $this->terminalProgress(true),
            $event instanceof MessageStartEvent => $this->onMessageStart($event),
            $event instanceof MessageUpdateEvent => $this->onMessageUpdate($event),
            $event instanceof MessageEndEvent => $this->onMessageEnd($event),
            $event instanceof ToolExecutionStartEvent => $this->onToolStart($event),
            $event instanceof ToolExecutionUpdateEvent => $this->onToolUpdate($event),
            $event instanceof ToolExecutionEndEvent => $this->onToolEnd($event),
            $event instanceof AgentEndEvent => $this->onEnd(),

            // The session's own, from between one run and the next. See `AgentEvent`.
            $event instanceof ModelFallbackEvent => $this->onModelFallback($event),
            $event instanceof CacheWarmedEvent => $this->addCacheWarmingUsage($event->entry),
            $event instanceof RetryStartEvent => $this->onRetryStart($event),
            $event instanceof RetryEndEvent => $this->onRetryEnd($event),
            // Every compaction is announced; `/compact` draws its own loader in `compact()`, so only
            // the session's automatic ones — the threshold and overflow — are drawn from here.
            $event instanceof CompactionStartEvent && $event->reason !== 'manual' => $this->onAutoCompaction($event->reason),
            $event instanceof CompactionEndEvent && $event->reason !== 'manual' => $this->onAutoCompactionHandled($event),
            $event instanceof SummarizationRetryEvent => $this->onSummarizationRetry($event),

            default => null,
        };

        $this->tui->requestRender();
    }

    private function onStart(): void
    {
        $this->showLoader('Working', timer: true);
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
    /**
     * @param bool $timer the elapsed time after the message, `Working · 16s` — the developer's
     *        choice, which upstream's loader does not have (docs/dev/decisions.md)
     */
    private function showLoader(string $message, bool $timer = false): Loader
    {
        if ($this->customWorkingMessage !== null && $message === 'Working') {
            $message = $this->customWorkingMessage;
        }

        $this->working?->stop();
        $this->status->clear();
        $this->paintBorder();

        $this->working = new Loader(
            $this->tui,
            static fn (string $text): string => Themes::theme()->fg('accent', $text),
            static fn (string $text): string => Themes::theme()->fg('muted', $text),
            $message,
        );

        if ($timer) {
            $this->working->withTimer();
        }

        if ($this->workingIndicatorOptions !== null) {
            $this->working->setIndicator($this->workingIndicatorOptions);
        }

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
        $this->paintBorder();
    }

    private function onMessageStart(MessageStartEvent $event): void
    {
        if ($event->message instanceof UserMessage) {
            $text = self::textOf($event->message);
            $skillBlock = \Pig\CodingAgent\Prompt\SkillBlock::parse($text);
            if ($skillBlock !== null) {
                $expandKey = $this->keybindings->display('app.tools.expand') ?: 'ctrl+o';
                $this->chat->addChild(new SkillInvocationMessageComponent(
                    $skillBlock,
                    $this->expanded,
                    $expandKey,
                    $this->outputPad,
                ));
                if ($skillBlock->userMessage !== null) {
                    $this->chat->addChild(new UserMessageComponent(
                        $skillBlock->userMessage,
                        $this->markdownTransformers(),
                        $this->outputPad,
                    ));
                }
            } else {
                $this->chat->addChild(new UserMessageComponent(
                    $text,
                    $this->markdownTransformers(),
                    $this->outputPad,
                ));
            }
            $this->editor->setText('');
            $this->showQueue();

            return;
        }

        if ($event->message instanceof AssistantMessage) {
            $this->streaming = new AssistantMessageComponent(
                $event->message,
                $this->hideThinking,
                $this->markdownTransformers(),
                $this->hiddenThinkingLabel,
                $this->outputPad,
            );
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
                // The assistant component itself renders an error message for a failed turn. This
                // fallback is only for a malformed event stream that never opened a component;
                // otherwise the same provider failure is printed twice.
                if (!$hasContent && $this->streaming === null) {
                    $this->sayError($said);
                }

                if (BugReport::worthReporting($event->message)) {
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

            $this->maybeShowThinkingDropNotice($event->message);
            $this->maybeShowCacheMissNotice($event->message);
        }

        $this->streaming = null;
    }

    // ---- what cache traffic cost, behind `showCacheMissNotices` ------------------------------

    /** Upstream's `addCacheWarmingUsage()`. */
    private function addCacheWarmingUsage(UsageEntry $entry): void
    {
        if (!$this->settings->showCacheMissNotices()) {
            return;
        }

        $usage = CacheWarmer::formatUsage($entry);
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('dim', $usage), 1, 0));
    }

    /** Upstream's `addCompactionCostNotice()`: what a summary billed, from the usage on its entry. */
    private function addSummaryCostNotice(CompactionSummary|BranchSummary $summary): void
    {
        if (!$this->settings->showCacheMissNotices() || $summary->usage === null) {
            return;
        }

        $usage = $summary->usage;
        $tokens = $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite;
        $cost = $usage->cost->total >= 0.01 ? sprintf(' (~$%.2f)', $usage->cost->total) : '';
        $label = $summary instanceof CompactionSummary ? 'Compaction' : 'Branch summary';
        $line = "{$label}: " . FooterComponent::tokens($tokens) . " tokens billed{$cost}";
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('warning', $line), 1, 0));
    }

    private static function countDroppedThinkingBlocks(AssistantMessage $message): int
    {
        $count = 0;

        foreach ($message->diagnostics ?? [] as $diagnostic) {
            if ($diagnostic->type !== 'anthropic_input_transformations') {
                continue;
            }

            foreach ((array) ($diagnostic->details['transformations'] ?? []) as $transformation) {
                if (is_array($transformation) && ($transformation['type'] ?? null) === 'thinking_dropped') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** Upstream's `maybeShowThinkingDropNotice()`: said when Anthropic dropped more thinking than last turn. */
    private function maybeShowThinkingDropNotice(AssistantMessage $message): void
    {
        if (!$this->settings->showCacheMissNotices()) {
            return;
        }

        $dropped = self::countDroppedThinkingBlocks($message);

        if ($dropped === 0) {
            return;
        }

        // The file already holds this message, which upstream's does not at this moment; the
        // previous response is the last assistant message before it.
        $previousDropped = 0;
        $branch = $this->session->store()?->branch() ?? [];

        for ($i = count($branch) - 1; $i >= 0; $i--) {
            $entry = $branch[$i]['message'];

            if ($entry instanceof AssistantMessage && $entry !== $message) {
                $previousDropped = self::countDroppedThinkingBlocks($entry);

                break;
            }
        }

        if ($dropped <= $previousDropped) {
            return;
        }

        $noun = $dropped === 1 ? 'thinking block' : 'thinking blocks';
        $line = "Anthropic dropped {$dropped} {$noun} (details in session)";
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('warning', $line), 1, 0));
    }

    /** Upstream's `maybeShowCacheMissNotice()`. */
    private function maybeShowCacheMissNotice(AssistantMessage $message): void
    {
        if (!$this->settings->showCacheMissNotices()) {
            return;
        }

        $miss = CacheStats::detect($this->session->store()?->everyMessage() ?? $this->session->messages(), $message);

        if ($miss !== null) {
            $this->addCacheMissNotice($miss);
        }
    }

    /** Upstream's `addCacheMissNotice()`: only a miss of 20,000 tokens or ten cents is worth a line. */
    private function addCacheMissNotice(CacheMiss $miss): void
    {
        if ($miss->missedTokens < 20_000 && $miss->missedCost < 0.1) {
            return;
        }

        $cost = $miss->missedCost >= 0.01 ? sprintf(' (~$%.2f)', $miss->missedCost) : '';
        $label = match (true) {
            $miss->modelChanged => 'Cache miss after model switch',
            $miss->idleMs >= CacheStats::CACHE_TTL_MS => 'Cache miss after ' . (int) round($miss->idleMs / 60_000) . 'm idle',
            default => 'Cache miss',
        };
        $line = "{$label}: " . FooterComponent::tokens($miss->missedTokens) . " tokens re-billed{$cost}";
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('warning', $line), 1, 0));
    }

    private function onToolStart(ToolExecutionStartEvent $event): void
    {
        // Nested calls (from codemode scripts) are shown inside their parent's row.
        if ($event->parentToolCallId !== null) {
            return;
        }

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
        $this->terminalProgress(false);
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
     * Upstream's wording (`Retrying (1/3) in 30s... (escape to cancel)`), and not the error with
     * the countdown after it, which this used to say: the reason is already a red line in the
     * transcript by the time the retry starts, and in the prompt's border — where this is drawn
     * now — an 80-column terminal cut the error off before the one word that matters, which is
     * the key that stops it.
     */
    private function onRetryStart(RetryStartEvent $event): void
    {
        // Upstream's `RetryStatusIndicator`: `Retrying (n/max) in <s>s... (${keyText("app.interrupt")}
        // to cancel)` — `escape` with the default bindings — the seconds rounded up
        // (`Math.ceil(delayMs / 1000)`) and counted down once a second (`CountdownTimer`) until the
        // retry starts.
        $key = $this->keybindings->keyText('app.interrupt');
        $message = static fn (int $seconds): string => "Retrying ({$event->attempt}/{$event->maxAttempts}) in {$seconds}s... ({$key} to cancel)";
        $remaining = (int) ceil($event->delaySeconds);
        $loader = $this->showLoader($message($remaining));
        $this->stopRetryCountdown();

        $tick = function () use (&$tick, &$remaining, $loader, $message): void {
            $this->retryCountdown = null;
            $remaining--;

            if ($this->working !== $loader) {
                return;
            }

            $loader->setMessage($message(max(0, $remaining)));
            $this->tui->requestRender();

            if ($remaining > 0) {
                $this->retryCountdown = Loop::get()->delay(1.0, $tick);
            }
        };

        if ($remaining > 0) {
            $this->retryCountdown = Loop::get()->delay(1.0, $tick);
        }
    }

    /** The retry countdown's next tick, while one is counting. */
    private ?string $retryCountdown = null;

    private function stopRetryCountdown(): void
    {
        if ($this->retryCountdown !== null) {
            Loop::get()->cancel($this->retryCountdown);
            $this->retryCountdown = null;
        }
    }

    /**
     * A summary's request is being tried again — upstream's `summarization_retry_*` arms: the
     * error, and the retry countdown in place of the summary's own loader; then the summary's
     * loader back when the retry starts (`summarization_retry_attempt_start`). The end needs
     * nothing here: the summary's own caller takes its loader down.
     */
    private function onSummarizationRetry(SummarizationRetryEvent $event): void
    {
        if ($event->phase === SummarizationRetryEvent::SCHEDULED) {
            $this->sayError($event->error ?? 'Unknown error');
            $this->onRetryStart(new RetryStartEvent($event->attempt, $event->maxAttempts, $event->delaySeconds, $event->error ?? ''));

            return;
        }

        if ($event->phase === SummarizationRetryEvent::ATTEMPT_START) {
            $this->stopRetryCountdown();
            $this->showLoader(
                $event->source === 'branchSummary' ? $this->branchSummaryLabel() : $this->compactionLabel($event->reason ?? 'manual'),
                timer: true,
            );
        }
    }

    /**
     * The model hit its quota and the session moved on to a `fallbackModels` entry.
     *
     * A line in the transcript rather than a loader: by the time this arrives the switch has
     * happened and the turn is already going again. The provider's reason is a red line above
     * already, so this says only where the conversation went — in `cycleModel()`'s words, with the
     * provider in front because the whole point is usually the same id on a different key.
     */
    private function onModelFallback(ModelFallbackEvent $event): void
    {
        $this->footer->invalidate();
        $this->paintBorder();

        $level = $this->session->thinkingLevel();
        $this->say("Quota reached on {$event->from->provider}/{$event->from->id} — model: {$event->to->provider}/{$event->to->id}"
            . ($level === ThinkingLevel::Off ? '' : " · thinking {$level->value}"));
    }

    private function onRetryEnd(RetryEndEvent $event): void
    {
        $this->stopRetryCountdown();
        $this->hideLoader();

        if ($event->succeeded) {
            // Nothing said: the answer it retried for is already on screen above this, and
            // "it worked" about something that never visibly failed is noise.
            return;
        }

        $this->sayError($event->error ?? 'Giving up after ' . $event->attempts . ' attempts.');
    }

    /** Upstream's `terminal.setProgress()` calls, behind `terminal.showTerminalProgress`. */
    private function terminalProgress(bool $active): void
    {
        if ($this->settings->showTerminalProgress()) {
            $this->renderer->terminal->setProgress($active);
        }
    }

    /** @param 'threshold'|'overflow' $reason */
    private function onAutoCompaction(string $reason): void
    {
        $this->terminalProgress(true);
        // Enter does nothing for the length of it, which is upstream's one use of this flag and
        // the only answer that does not lose the message. Without it the submit handler clears
        // the editor and spawns a turn that parks on the compaction — and when the compaction's
        // own carry-on starts a run, that turn wakes up to `Agent is already working` and what
        // was typed is gone, with a red line where it went. The text stays where it is and the
        // next Enter sends it.
        $this->editor->disableSubmit(true);

        $this->showLoader($this->compactionLabel($reason), timer: true);
    }

    /**
     * Upstream's `CompactionStatusIndicator` label: `Compacting context...` for `/compact`,
     * `Auto-compacting...` for the threshold, with `Context overflow detected, ` in front for an
     * overflow — each followed by the interrupt key as `keyText()` names it.
     *
     * @param string $reason `manual`, `threshold` or `overflow`
     */
    private function compactionLabel(string $reason): string
    {
        $cancelHint = '(' . $this->keybindings->keyText('app.interrupt') . ' to cancel)';

        return $reason === 'manual'
            ? "Compacting context... {$cancelHint}"
            : ($reason === 'overflow' ? 'Context overflow detected, ' : '') . "Auto-compacting... {$cancelHint}";
    }

    /** Upstream's `BranchSummaryStatusIndicator` label. */
    private function branchSummaryLabel(): string
    {
        return 'Summarizing branch... (' . $this->keybindings->keyText('app.interrupt') . ' to cancel)';
    }

    private function onAutoCompactionHandled(CompactionEndEvent $event): void
    {
        $this->terminalProgress(false);
        $this->editor->disableSubmit(false);
        $this->hideLoader();

        if ($event->summary !== null) {
            $this->chat->addChild(new CompactionComponent($event->summary, $this->expanded, $this->outputPad, $this->keybindings->keyText('app.tools.expand')));
            $this->addSummaryCostNotice($event->summary);

            return;
        }

        if ($event->aborted) {
            $this->say('Auto-compaction cancelled');

            return;
        }

        $this->sayError($event->errorMessage ?? 'Could not summarise, so the turn could not be sent again.');
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
                $drawn = $renderer($message, new RenderOptions($this->expanded), Themes::theme());
            } catch (Throwable $problem) {
                $this->sayWarning("The renderer for '{$message->customType}' failed: {$problem->getMessage()}");
                $drawn = null;
            }

            if ($drawn instanceof Component) {
                // Upstream's `CustomMessageComponent` puts the blank line above a drawn one too.
                $this->chat->addChild(new Spacer(1));
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

        $this->chat->addChild(new HookMessageComponent($message, $this->expanded, $this->outputPad));
    }

    /** @param array<string, mixed> $arguments */
    private function toolRenderers(string $name, ?CustomTool $custom = null): ?array
    {
        $base = $custom === null ? null : static fn (): array => array_filter([
            'renderCall' => $custom->renderCall,
            'renderResult' => $custom->renderResult,
        ]);

        return $this->hooks?->resolveToolRenderers($name, $base);
    }

    private function addTool(string $id, string $name, array $arguments): ToolExecutionComponent
    {
        // A custom tool may draw its own call and result, so the declaration is looked up
        // rather than the name being enough. Null for every built-in, which is all of them
        // unless something was loaded.
        $custom = $this->customTools?->find($name);
        $tool = new ToolExecutionComponent(
            $name,
            $arguments,
            $custom,
            function (string $problem) use ($name): void {
                $this->sayWarning("tool {$name}: {$problem}");
            },
            showImages: $this->showImages,
            cwd: $this->cwd,
            toolRenderers: $this->toolRenderers($name, $custom),
            startedAt: microtime(true),
            outputPad: $this->outputPad,
            imageWidthCells: $this->settings->imageWidthCells(),
            expandKey: $this->keybindings->keyText('app.tools.expand'),
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
            $this->pending->addChild(new TruncatedText(Themes::theme()->fg('dim', "Steering: {$message}"), 1, 0));
        }

        foreach ($followUp as $message) {
            $this->pending->addChild(new TruncatedText(Themes::theme()->fg('dim', "Follow-up: {$message}"), 1, 0));
        }

        $key = $this->keybindings->display('app.message.dequeue');
        $this->pending->addChild(new TruncatedText(Themes::theme()->fg('dim', "↳ {$key} to edit all queued messages"), 1, 0));
    }

    /**
     * A note in the transcript — what a key did, or why something did not happen.
     *
     * A closure is a builder for the message, for the notes that colour parts of themselves: it is
     * called again on a theme change, so it builds from a snapshot (see `ThemedText`).
     *
     * @param string|Closure(): string $message
     */
    private function say(string|Closure $message): void
    {
        $this->sayThemed(static fn (): string => Themes::theme()->fg('dim', is_string($message) ? $message : $message()));
    }

    /**
     * A note in the transcript that picks its own colours: upstream's
     * `chatContainer.addChild(new ThemedText(...))`, which several of its commands write inline.
     *
     * A builder rather than a string, so the line follows a theme change; see `ThemedText` for what
     * the builder may read.
     *
     * @param Closure(): string $build
     */
    private function sayThemed(Closure $build): void
    {
        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new ThemedText($build, 1, 0));
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
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('error', "Error: {$message}"), $this->outputPad, 0));
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
        $this->chat->addChild(new ThemedText(static fn (): string => Themes::theme()->fg('warning', "Warning: {$message}"), 1, 0));
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
