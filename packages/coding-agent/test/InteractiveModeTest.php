<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Changelog;
use Pig\CodingAgent\Cli\UpdateCheck;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Interactive\InteractiveMode;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Ai\Utils\Oauth\Credentials;
use Throwable;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Test\ProbeModels;
use Pig\Test\WithoutProviderKeys;
use Pig\Tui\Ansi;
use Pig\Tui\Width;
use Pig\Tui\Components\Text;
use Pig\Tui\Test\FakeClipboard;
use Pig\Tui\Test\FakeTerminal;
use RuntimeException;

/**
 * The terminal around the agent, driven by typing into a fake one.
 *
 * Nothing here runs the event loop to completion: the loop is what `run()` blocks on,
 * and a test that entered it would never come back. `start()` draws the first frame and
 * the keystrokes go in by hand, which is the same path a real key takes.
 */
final class InteractiveModeTest extends TestCase
{
    use ProbeModels;
    use WithoutProviderKeys;

    private const string ESC = "\e";

    private const string ENTER = "\r";

    private FakeTerminal $terminal;

    private AgentSession $session;

    private InteractiveMode $mode;

    /** @var list<string|AssistantMessage> a bare string is an answer of that text */
    private array $answers = [];

    /** Set while a test is holding the agent mid-run; the stream it is waiting on. */
    private ?AssistantMessageEventStream $held = null;

    private bool $holding = false;

    /** Model calls to let through before the next one is held. */
    private int $letThrough = 0;

    private string $cwd;

    private string $home;

    private FakeClipboard $clipboard;

    private Settings $settings;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->held = null;
        $this->holding = false;
        $this->letThrough = 0;
        $this->terminal = new FakeTerminal(80, 24);
        $this->cwd = sys_get_temp_dir() . '/pig-interactive-' . bin2hex(random_bytes(4));
        $this->home = $this->cwd . '-home';
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->home);

        // `/model` lists the models there is a key for when this mode is given an `Auth`, and what
        // the shell running the suite exports is not something an assertion should turn on.
        $this->forgetProviderKeys();

        // The scope cases need two models with known ids; which ones providers sell is not
        // what they are about — see `ProbeModels`.
        self::registerProbeModels();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->mode->stop();
        putenv('PIG_HOME');
        $this->restoreProviderKeys();
        Models::forgetRegistered();
        self::remove($this->home);
        self::remove($this->cwd);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function startWithContext(): void
    {
        $this->start(context: [new ContextFile('/somewhere/AGENTS.md', 'be careful')]);
    }

    /**
     * @param list<string|AssistantMessage> $answers one per model call
     * @param list<ContextFile>             $context
     * @param list<string>                  $tools which built-ins the agent is given
     */
    private function start(
        array $answers = [],
        bool $reasoning = false,
        array $context = [],
        bool $store = false,
        ?string $resume = null,
        array $skills = [],
        array $fileCommands = [],
        ?Settings $settings = null,
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
        array $initialMessages = [],
        array $initialImages = [],
        ?Auth $auth = null,
        ?string $changelog = null,
        array $tools = ['read'],
        array $modelScope = [],
        bool $projectTrusted = true,
        ?\Pig\CodingAgent\Keybindings $keybindings = null,
    ): void {
        $this->clipboard = new FakeClipboard();
        $this->settings = $settings ?? Settings::inMemory();
        $this->answers = $answers;
        $agent = new Agent(new AgentOptions(streamFn: $this->provider(...), apiKey: 'k'));
        $agent->setModel(new Model(
            'claude-test',
            'Test',
            Api::AnthropicMessages,
            'anthropic',
            'http://127.0.0.1:1',
            200_000,
            64_000,
            $reasoning,
        ));

        // The same shape `CodingAgent::create()` builds: one built-in plus whatever was
        // loaded, wrapped. Without the tools `/tools` would be answering about an agent that
        // has none; without the wrapping no `tool_call` hook fires at all, so a guard that
        // works in production could not be reached from here.
        $agent->setTools(HookedTool::wrap(
            [...ToolSet::create($this->cwd, $tools), ...($customTools?->agentTools() ?? [])],
            $hooks ?? new HookRunner(),
        ));

        $saved = match (true) {
            $resume !== null => SessionManager::open($resume),
            $store => SessionManager::create($this->cwd),
            default => null,
        };

        // The settings go to the session as well as to the mode, because that is what `bin/pig`
        // does — and the session reads them for the queue mode, auto-compaction and auto-retry.
        // Passing null here meant three settings that were live in production and inert in
        // every test. The file commands are the same shape and arrived the same way: the session
        // is what expands one now, so a list given only to the mode would leave the expansion
        // inert here too.
        $this->session = new AgentSession(
            $agent,
            $this->cwd,
            $saved,
            $this->settings,
            $hooks,
            $fileCommands,
            $modelScope,
        );

        if ($resume !== null && $saved !== null) {
            $this->session->restore($saved->messages());
        }
        $this->mode = new InteractiveMode(
            $this->session,
            Palette::dark(true),
            $this->cwd,
            '0.0.0',
            'dark',
            $this->terminal,
            $context,
            $skills,
            $this->clipboard,
            $fileCommands,
            $this->settings,
            $hooks,
            $customTools,
            $initialMessages,
            $initialImages,
            $auth,
            $changelog,
            projectTrusted: $projectTrusted,
            keybindings: $keybindings ?? new \Pig\CodingAgent\Keybindings(),
        );

        $this->mode->start();
    }

    // ---- crashes --------------------------------------------------------------------------------

    public function testTheLastCrashIsAnnouncedOnceAtStartup(): void
    {
        \Pig\CodingAgent\CrashLog::record('fatal_error', new RuntimeException('it fell over'), null, $this->cwd);

        $this->start();
        $this->assertStringContainsString('pig crashed on', $this->screenText());
        $this->assertStringContainsString('(it fell over). Run /bug', $this->screenText());

        $this->mode->stop();
        Loop::reset();
        $this->start();
        $this->assertStringNotContainsString('pig crashed on', $this->screenText());
    }

    public function testAThrowTheLoopCaughtIsWrittenDownForBug(): void
    {
        $this->start();
        $this->assertSame([], \Pig\CodingAgent\CrashLog::read());

        Loop::get()->defer(static function (): void {
            throw new RuntimeException('render died');
        });
        $this->settle();

        $records = \Pig\CodingAgent\CrashLog::read();
        $this->assertCount(1, $records);
        $this->assertSame('loop_error', $records[0]['kind']);
        $this->assertSame('render died', $records[0]['message']);
        $this->assertStringContainsString('render died', $this->screenText(), 'and it is still drawn');
    }

    // ---- /trust ---------------------------------------------------------------------------------

    public function testAnUntrustedProjectSaysSoOnScreenAndSlashTrustSavesADecision(): void
    {
        mkdir($this->cwd . '/.pig/hooks', 0o755, true);
        $this->start(projectTrusted: false);

        $this->assertStringContainsString('This project is not trusted', $this->screenText());

        $this->type('/trust');
        $this->type("\r");
        $this->assertStringContainsString('this session: not trusted, no saved decision', $this->screenText());

        // The first row is "Trust".
        $this->type("\r");

        $this->assertStringContainsString('Saved trust decision: trusted. Restart pig', $this->screenText());
        $this->assertTrue(\Pig\CodingAgent\ProjectTrust::decision($this->cwd, $this->home));
    }

    public function testATrustedProjectIsNotWarnedAboutAndEscapeLeavesTheFileAlone(): void
    {
        mkdir($this->cwd . '/.pig/hooks', 0o755, true);
        $this->start();

        $this->assertStringNotContainsString('This project is not trusted', $this->screenText());

        $this->type('/trust');
        $this->type("\r");
        $this->type("\e");

        $this->assertFileDoesNotExist($this->home . '/trust.json');
    }

    private function provider(Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $answer = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $message = $answer instanceof AssistantMessage ? $answer : self::message($answer);
        $stream = new AssistantMessageEventStream();

        // Held open: the two tests about typing mid-run need the agent to still be
        // working when the next key arrives, which an instant answer never is.
        if ($this->held === null && $this->holding) {
            if ($this->letThrough > 0) {
                $this->letThrough--;
            } else {
                $this->held = $stream;
                $this->answers[] = $answer;

                $options->signal?->onAbort(function () use ($stream, $message): void {
                    $aborted = new AssistantMessage(
                        $message->content,
                        $message->api,
                        $message->provider,
                        $message->model,
                        $message->usage,
                        StopReason::Aborted,
                        'Aborted',
                    );
                    $stream->push(new StartEvent($aborted));
                    $stream->push(new ErrorEvent(StopReason::Aborted, $aborted));
                    $stream->end($aborted);
                });

                return $stream;
            }
        }

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));

            // Each call announced as it closes, which is what every real provider does and
            // what gives the terminal a component to draw before the message ends. Without
            // it the first thing the UI hears about a call is that it has started running.
            foreach ($message->content as $index => $block) {
                if ($block instanceof ToolCall) {
                    $stream->push(new ToolCallEndEvent($index, $block, $message));
                }
            }

            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        });

        return $stream;
    }

    private static function message(string $text): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Stop,
        );
    }

    private function type(string $text): void
    {
        $this->terminal->type($text);
    }

    /** Everything on screen right now, without the escape codes. */
    private function screen(): string
    {
        return implode("\n", array_map(Ansi::strip(...), $this->mode->screen()->render(80)));
    }

    /** How many rows a picker says it has, from the `(3/57)` it draws under a long list. */
    private function pickerTotal(): int
    {
        return preg_match('/\(\d+\/(\d+)\)/', $this->screen(), $found) === 1 ? (int) $found[1] : 0;
    }

    /**
     * The row a picker has the selection on, which is the arrow's line.
     *
     * For a fuzzy-filtered list this is the assertion worth making: everything matches a little,
     * and what the filter is *for* is putting the right row under the cursor.
     */
    private function selectedRow(): string
    {
        foreach (explode("\n", $this->screen()) as $line) {
            if (str_contains($line, '→ ')) {
                return $line;
            }
        }

        return '';
    }

    /**
     * The screen as one run of words, for asserting on a sentence the renderer may have wrapped.
     *
     * **A line break inside the text is the renderer working, not a difference in the text.** The
     * export test asserted `Exported to <path>` against `screen()` and passed only where the
     * temporary directory was short: on macOS the path is
     * `/var/folders/mk/6fds…/T/pig-interactive-…` and the sentence wraps across three rows, so the
     * assertion failed on a machine where the feature was working perfectly. Reproduced in the
     * container by pointing `TMPDIR` at a path of that shape. Use this whenever the thing being
     * asserted is the *words*; use `screen()` when the layout is the point.
     *
     * **Rendered wide rather than de-wrapped**, because collapsing whitespace does not undo a wrap:
     * 80 columns split the path at `out.` / `html`, and joining those with a space gives
     * `out. html`, which is not the filename either. A width nothing wraps at is the only way to
     * read the text back as it was written.
     */
    private function screenText(int $width = 400): string
    {
        $lines = array_map(Ansi::strip(...), $this->mode->screen()->render($width));

        return trim((string) preg_replace('/ +/', ' ', implode(' ', $lines)));
    }

    /**
     * Turn the loop without waiting out whatever timer is pending.
     *
     * `settle()` polls with no timeout of its own, so with a retry's half-minute timer armed and
     * nothing to read it `usleep()`s for the whole of it. An expired timer of our own makes the
     * poll return at once, which is what lets a test look at a countdown rather than sit through it.
     */
    private static function turnTheLoop(int $ticks = 100): void
    {
        for ($tick = 0; $tick < $ticks; $tick++) {
            Loop::get()->delay(0.0, static fn () => null);
            Loop::get()->tick();
        }
    }

    /** A turn that comes back as a provider failure, which is what auto-retry is for. */
    private static function failed(string $error): AssistantMessage
    {
        return new AssistantMessage(
            [],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Error,
            $error,
        );
    }

    /** Run the loop until it has nothing left to do, so a spawned prompt can finish. */
    private function settle(): void
    {
        for ($tick = 0; $tick < 50 && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    // ---- the first frame ------------------------------------------------------------

    public function testTheDebugKeyWritesTheFrameAndTheConversation(): void
    {
        // `Tui` has intercepted shift+ctrl+d and offered `setDebugHandler()` since it was ported,
        // and nothing ever called the setter — so the key that works whatever holds the focus did
        // nothing, and the frame dump `checkWidth()` writes on a fault could only be got by
        // causing one. Both ends are here now.
        $this->start(answers: ['an answer of some length']);
        $this->type('say something');
        $this->type(self::ENTER);
        $this->settle();

        // Shift+ctrl has no control character; only a kitty terminal can report it.
        $this->type("\e[100;6u");
        $this->settle();

        $path = getenv('PIG_HOME') . '/pig-debug.log';
        $this->assertFileExists($path);

        $written = (string) file_get_contents($path);

        // The frame, with a width per line, which is the pair that makes a padding bug obvious.
        $this->assertStringContainsString('=== the frame ===', $written);
        $this->assertMatchesRegularExpression('/\[0\] \(w=\d+\)/', $written);

        // And what was said, as the session file's own shape rather than a second one.
        $this->assertStringContainsString('=== the conversation ===', $written);
        $this->assertStringContainsString('say something', $written);
        $this->assertStringContainsString('an answer of some length', $written);

        // A conversation holds whatever the model read.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testTheDebugCommandWritesTheSameThingWithoutBeingListed(): void
    {
        $this->start();
        $this->type('/debug');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertFileExists(getenv('PIG_HOME') . '/pig-debug.log');

        // Known and not offered, like `quit`: `COMMANDS` is what `/help` prints and what the
        // autocomplete lists, and this is for reporting a fault rather than for using pig.
        $this->type('/help');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringNotContainsString('/debug', $this->screen());
    }

    public function testTypingAfterSlashModelOffersModelsRatherThanFileNames(): void
    {
        // `SlashCommand::$argumentCompletions` is read by the provider and was supplied by nobody,
        // so the docblock's own example — "`/model <tab>` lists models rather than files" — was a
        // claim about the repository that the repository did not keep: past the first space Tab
        // means a path, so `/model s` offered whatever files happen to sit in the project.
        file_put_contents($this->cwd . '/sonnet-notes-for-the-picker.txt', 'x');

        $this->start();
        // No Tab: a command's own completions are the *automatic* list, offered as you type, and
        // Tab is then how you take one. Tab before the list exists means a path, and past a
        // command that answers for its argument it means neither — which is the fix.
        // The last keystroke on its own, because one chunk is one key: a `type()` of the whole
        // string arrives as a single seventeen-character key, and what opens the list is a typed
        // *letter or digit* inside a line that starts with a slash.
        $this->type('/model sonnet-4-');
        $this->type('5');
        $this->settle();

        $screen = $this->screen();

        // A real model id, which can only be on screen because a list of models was offered — the
        // footer says `claude-test`, so asserting on that would be an assertion that cannot fail.
        $this->assertStringContainsString('claude-sonnet-4-5', $screen);
        $this->assertStringNotContainsString('sonnet-notes-for-the-picker.txt', $screen);
    }

    public function testTabAfterACommandThatAnswersForItsArgumentIsNotAPath(): void
    {
        // The other door, and the one that made `shouldCompleteFiles()` a public method with no
        // caller: a space closes the automatic list, so the next Tab had nothing open and went
        // straight to the filesystem. `Editor::completeOnTab()` spelled the rule itself instead of
        // asking the provider, which is the only thing that knows `/model` answers for its own
        // argument.
        file_put_contents($this->cwd . '/notes-after-a-space.txt', 'x');

        $this->start();
        $this->type('/model');
        $this->type(' ');
        $this->type("\t");
        $this->settle();

        $screen = $this->screen();

        $this->assertStringNotContainsString('notes-after-a-space.txt', $screen);
        // A provider name, which is the description on each offered model and appears nowhere else
        // on this screen — the footer carries the model id alone.
        $this->assertStringContainsString('anthropic', $screen, 'the whole list, since nothing narrows it');

        // And once something *has* been typed after the space. Escape first, because a list that is
        // already open takes Tab as "accept this one" and never reaches the question — dismissing it
        // and pressing Tab is the one way back to asking, and the way a file name got in.
        $this->type('sonnet-4-');
        $this->type('5');
        $this->type(self::ESC);
        $this->type("\t");
        $this->settle();

        $narrowed = $this->screen();

        $this->assertStringNotContainsString('notes-after-a-space.txt', $narrowed);
        $this->assertStringContainsString('claude-sonnet-4-5', $narrowed);
    }

    public function testSwitchingThemeDoesNotWriteTheWholeFrameUnderItself(): void
    {
        // `requestRender(true)` empties what the screen is believed to hold, and the renderer
        // reads an empty `previousLines` as "first frame ever" — so it writes every line with no
        // clear, starting from wherever the cursor is, which is the bottom of the frame already
        // there. That is the trap in CLAUDE.md about the forced render, and `/theme` is the third
        // caller to make it: nothing overwrote this screen, so there is a previous frame to diff
        // against and a plain render rewrites only the lines whose colours changed.
        $this->start();
        $this->settle();

        $this->terminal->clearWrites();
        $this->type('/theme');
        $this->type(self::ENTER);
        $this->settle();

        $written = $this->terminal->output();

        $this->assertStringContainsString('Theme: light', Ansi::strip($written), 'it did switch');

        // The bytes, because `FakeTerminal` records writes and not a screen: a differential update
        // clears each line it rewrites (`\e[2K`), and the first-frame path — which is what an
        // emptied `previousLines` selects — emits none of those and no cursor move either. Counting
        // how often the banner is written cannot tell them apart, because `requestRender()`
        // coalesces: the plain render `say()` asked for and the forced one become a single draw.
        $this->assertStringContainsString("\x1b[2K", $written, 'a differential update, not a fresh frame');
    }

    public function testEveryKeyTheEditorAnswersToIsNamedSomewhere(): void
    {
        // `Editor` answers to all of these and pig named none of them: the application keys were
        // in `KEYS` and the *editing* keys — upstream's own `/hotkeys` table — were listed
        // nowhere, so ctrl+w, ctrl+u, ctrl+k, word movement and the prompt history all worked and
        // could only be found by guessing. `/help` says "the keys and commands", which is a claim.
        $this->start();
        $this->type('/help');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        foreach ([
            'ctrl+w',
            'ctrl+u',
            'ctrl+k',
            'ctrl+a',
            'ctrl+e',
            'alt+left',
            'shift+enter',
            'tab',
            'up',
        ] as $key) {
            $this->assertStringContainsString($key, $screen, "{$key} is not named anywhere");
        }
    }

    public function testTheBannerIsThreeLinesUntilAskedForMore(): void
    {
        $this->start();
        $screen = $this->screen();

        // A column of thirteen keys was taller than most of the conversations it sat
        // above, so the list moved behind ctrl+o.
        $this->assertStringContainsString('pig v0.0.0', $screen);
        $this->assertStringContainsString('escape interrupt · ctrl+c/ctrl+d clear/exit', $screen);
        $this->assertStringContainsString('Press ctrl+o to show full startup help and loaded resources.', $screen);
        $this->assertStringContainsString('Pig can explain its own features', $screen);
        $this->assertStringNotContainsString('suspend', $screen);
    }

    public function testCtrlOOpensTheFullListAndClosesItAgain(): void
    {
        $this->start();

        $this->type("\x0f");
        $this->assertStringContainsString('suspend', $this->screen());
        $this->assertStringNotContainsString('Press ctrl+o', $this->screen());

        $this->type("\x0f");
        $this->assertStringNotContainsString('suspend', $this->screen());
    }

    public function testWhatWasLoadedIsListedInTheBanner(): void
    {
        $this->startWithContext();

        $screen = $this->screen();
        $this->assertStringContainsString('[Context]', $screen);
        $this->assertStringContainsString('AGENTS.md', $screen);
    }

    public function testNoContextFilesMeansNoEmptyHeading(): void
    {
        $this->start();
        $this->type("\x0f");

        // A heading with nothing under it is worse than no heading.
        $this->assertStringNotContainsString('[Context]', $this->screen());
    }

    public function testTheFooterIsDrawnUnderEverything(): void
    {
        $this->start();

        // The model name is the last thing on the last line.
        $this->assertStringContainsString('claude-test', $this->screen());
    }

    // ---- saying something -------------------------------------------------------------

    public function testTypingAndPressingEnterSendsIt(): void
    {
        $this->start(['Here you go.']);

        $this->type('hello there');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('hello there', $screen);
        $this->assertStringContainsString('Here you go.', $screen);
    }

    public function testTheEditorIsEmptiedOnceTheMessageIsSent(): void
    {
        $this->start(['ok']);

        $this->type('a question');
        $this->type(self::ENTER);
        $this->settle();

        // The text appears once, in the transcript — not still sitting in the editor.
        $this->assertSame(1, substr_count($this->screen(), 'a question'));
    }

    public function testAnEmptyLineSendsNothing(): void
    {
        $this->start();

        $this->type(self::ENTER);
        $this->settle();

        $this->assertFalse($this->session->isStreaming());
    }

    // ---- commands ------------------------------------------------------------------------

    public function testALineThatNamesNoCommandIsSentAsTheTextItIs(): void
    {
        $this->start(['ok']);

        $this->type('/nonsense');
        $this->type(self::ENTER);
        $this->settle();

        // Upstream's rule, and the one pig follows: a command is a name something answers
        // to, matched exactly. Everything else is a message — including a typo, because
        // deciding that `/setings` *meant* `/settings` is a guess, and guessing is what read
        // a pasted picture's path as a command in the first place.
        $this->assertStringNotContainsString('No command called', $this->screen());
        $this->assertSame(2, count($this->session->messages()), 'sent, and answered');
    }

    public function testAPastedPathIsAMessageAndNotABadCommand(): void
    {
        $this->start(['ok']);

        // What pasting a picture leaves in the editor: `ClipboardFile` writes it to a temp
        // file and puts the path in, so the line begins with a slash.
        $this->type('/var/folders/mk/T/pig-clipboard-bf66.png what changed here?');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        // It used to be read as a command — "No command called /var/folders/…" — and the
        // question after it was swallowed along with the path.
        $this->assertStringNotContainsString('No command called', $screen);
        $this->assertStringContainsString('what changed here?', $screen, 'the whole line, not just the path');
        $this->assertStringContainsString('ok', $screen, 'and the model answered it');
    }

    public function testANearMissIsNotTreatedAsTheCommandItResembles(): void
    {
        $this->start(['ok']);

        $this->type('/setings');
        $this->type(self::ENTER);
        $this->settle();

        // The thing that must not happen is `/settings` opening. Matching is exact, so a
        // near miss is text, and it reaches the model as text.
        $this->assertStringNotContainsString('esc when done', $this->screen(), 'no settings screen');
        $this->assertStringContainsString('/setings', $this->screen());
    }

    public function testHelpListsTheKeysAndTheCommands(): void
    {
        $this->start();

        $this->type('/help');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('shift+tab', $screen);
        $this->assertStringContainsString('/session', $screen);
    }

    public function testHotkeysAliasListsTheKeysAndTheCommands(): void
    {
        $this->start();

        $this->type('/hotkeys');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('shift+tab', $screen);
        $this->assertStringContainsString('/session', $screen);
    }

    public function testThinkingCommandSetsLevelDirectlyOrOpensSubmenu(): void
    {
        $this->start(reasoning: true);

        // 1. Set level directly via argument
        $this->type('/thinking high');
        $this->type(self::ENTER);

        $this->assertSame(ThinkingLevel::High, $this->session->thinkingLevel());
        $this->assertStringContainsString('Thinking level: high', $this->screen());

        // 2. Open submenu via /thinking without arguments
        $this->type('/thinking');
        $this->type(self::ENTER);

        // Overlay is open showing Thinking submenu
        $screen = $this->screen();
        $this->assertStringContainsString('Thinking', $screen);
        $this->assertStringContainsString('Enter to select', $screen);

        // Escape closes submenu
        $this->type("\e");
        $this->assertStringNotContainsString('Enter to select', $this->screen());
    }

    public function testThinkingCommandReportsUnknownLevel(): void
    {
        $this->start(reasoning: true);

        $this->type('/thinking ultra');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Unknown thinking level "ultra"', $this->screen());
    }

    public function testThinkingCommandOnNonReasoningModelSaysNotSupported(): void
    {
        $this->start(reasoning: false);

        $this->type('/thinking');
        $this->type(self::ENTER);

        $this->assertStringContainsString('This model does not support thinking', $this->screen());
    }

    public function testReloadCommandRefreshesResourcesWhenIdle(): void
    {
        $this->start();

        $this->type('/reload');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Reloaded extensions, skills, commands, tools, and context files', $this->screen());
    }

    public function testReloadLoadsTheExtensionsThatAreThereAndComplainsAboutNoneOfThem(): void
    {
        // A global extension, as `~/.pig/agent/extensions/copy.php` is on a real machine.
        mkdir($this->home . '/extensions', 0o755, true);
        file_put_contents($this->home . '/extensions/greeter.php', <<<'PHP'
            <?php
            return function ($pi): void { $pi->registerCommand('greet', fn () => null, 'Say hello'); };
            PHP);

        $this->start();
        $this->type('/reload');
        $this->type(self::ENTER);

        $screen = $this->screenText();

        // The reload used to hand the loader the *banner labels* of the discovered extensions as if
        // they were command-line paths, so every one of them came back as
        // `<cwd>/greeter.php (load): not a readable file`.
        $this->assertStringNotContainsString('not a readable file', $screen);
        $this->assertStringContainsString('Reloaded extensions', $screen);

        // And the extension is live afterwards: its command is known.
        $this->type('/greet');
        $this->type(self::ENTER);
        $this->assertStringNotContainsString('No command called', $this->screenText());
    }

    public function testReloadCommandWarnsWhenStreaming(): void
    {
        $this->start(['done']);
        $this->holdTheAgent();

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertTrue($this->session->isStreaming());

        $this->type('/reload');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Wait for the current response to finish before reloading', $this->screen());

        // Exit cleanly
        $this->type('/quit');
        $this->type(self::ENTER);
        $this->settle();
    }

    public function testNewForgetsTheConversation(): void
    {
        $this->start(['answered']);

        $this->type('question');
        $this->type(self::ENTER);
        $this->settle();
        $this->assertNotSame([], $this->session->messages());

        $this->type('/new');
        $this->type(self::ENTER);

        $this->assertSame([], $this->session->messages());
        $this->assertStringNotContainsString('answered', $this->screen());
    }

    public function testSessionReportsWhatItHasCost(): void
    {
        $this->start();

        $this->type('/session');
        $this->type(self::ENTER);

        $this->assertStringContainsString('0 messages', $this->screen());
    }

    public function testExitStopsTheWholeThing(): void
    {
        $this->start();

        $this->type('/exit');
        $this->type(self::ENTER);

        $this->assertFalse($this->terminal->started);
    }

    // ---- the keys ---------------------------------------------------------------------------

    public function testCtrlCClearsTheEditorAndTwiceQuits(): void
    {
        $this->start();

        $this->type('half a thought');
        $this->type("\x03");

        $this->assertStringNotContainsString('half a thought', $this->screen());
        $this->assertTrue($this->terminal->started);

        $this->type("\x03");
        $this->assertFalse($this->terminal->started);
    }

    public function testCtrlDQuitsOnlyFromAnEmptyPrompt(): void
    {
        $this->start();

        $this->type('something');
        $this->type("\x04");
        $this->assertTrue($this->terminal->started);

        $this->type("\x03");
        $this->type("\x04");
        $this->assertFalse($this->terminal->started);
    }

    public function testShiftTabCyclesThinkingAndSaysSo(): void
    {
        $this->start(reasoning: true);

        $this->type("\e[Z");

        $this->assertSame(ThinkingLevel::Minimal, $this->session->thinkingLevel());
        $this->assertStringContainsString('Thinking: minimal', $this->screen());
    }

    public function testShiftTabOnAModelThatCannotThinkSaysThatInstead(): void
    {
        $this->start();

        $this->type("\e[Z");

        $this->assertStringContainsString('does not support thinking', $this->screen());
    }

    public function testCtrlTTogglesThinkingBlocksAndSaysWhich(): void
    {
        $this->start();

        $this->type("\x14");
        $this->assertStringContainsString('Thinking hidden', $this->screen());

        $this->type("\x14");
        $this->assertStringContainsString('Thinking shown', $this->screen());
    }



    public function testEscapeAtAnIdlePromptDoesNothing(): void
    {
        $this->start();

        $this->type('a draft');
        $this->type(self::ESC);

        // Nothing to interrupt, so the draft stays where it was typed.
        $this->assertStringContainsString('a draft', $this->screen());
    }

    // ---- sessions on disk ----------------------------------------------------------------

    public function testAConversationIsWrittenAsItHappens(): void
    {
        $this->start(['an answer'], store: true);

        $this->type('a question');
        $this->type(self::ENTER);
        $this->settle();

        $saved = SessionManager::open($this->session->store()->path)->messages();

        $this->assertCount(2, $saved);
        $this->assertInstanceOf(UserMessage::class, $saved[0]);
        $this->assertSame('an answer', $saved[1]->content[0]->text);
    }

    public function testASavedConversationIsDrawnAgainWhenItIsResumed(): void
    {
        $this->start(['the first answer'], store: true);
        $this->type('the first question');
        $this->type(self::ENTER);
        $this->settle();

        $path = $this->session->store()->path;
        $this->mode->stop();

        // A fresh pig in the same directory, resuming.
        $this->start(store: true, resume: $path);

        $screen = $this->screen();

        $this->assertStringContainsString('the first question', $screen);
        $this->assertStringContainsString('the first answer', $screen);
        $this->assertCount(2, $this->session->messages());
    }

    /**
     * What is said after resuming goes into the file that was resumed.
     *
     * It used to go into the file pig created at startup, which ended up holding its own
     * opening plus the continuation of a different conversation — while the resumed file
     * stopped growing at the moment it was resumed. Two files, neither of them what
     * happened.
     */
    public function testWhatIsSaidAfterResumingIsWrittenToTheFileThatWasResumed(): void
    {
        $this->start(['answer in the old one'], store: true);
        $this->type('the old question');
        $this->type(self::ENTER);
        $this->settle();

        $old = $this->session->store()->path;
        $this->mode->stop();

        // A second pig in the same directory, with its own file, which then resumes the
        // first one from the picker.
        $this->start(['answer after resuming'], store: true);
        $fresh = $this->session->store()->path;

        $this->assertNotSame($old, $fresh);

        $this->type('/resume');
        $this->type(self::ENTER);
        $this->type(self::ENTER);
        $this->settle();

        $this->type('said after resuming');
        $this->type(self::ENTER);
        $this->settle();

        $resumed = array_map(
            static fn (mixed $m): string => $m->content[0]->text ?? '',
            SessionManager::open($old)->messages(),
        );

        // The resumed file carries the whole thing: what was there, and what came after.
        $this->assertSame(
            ['the old question', 'answer in the old one', 'said after resuming', 'answer after resuming'],
            $resumed,
        );

        // And the file pig started with was never written at all — a session file appears
        // when something has been answered in it, and nothing ever was.
        $this->assertFileDoesNotExist($fresh);
    }

    /**
     * `/new` starts a new file, not just an empty screen.
     *
     * Keeping the old one appended the new conversation onto the last one as though they
     * were the same, and the new session never appeared in `/resume` at all.
     */
    public function testANewSessionGetsAFileOfItsOwn(): void
    {
        $this->start(['first answer', 'second answer'], store: true);

        $this->type('the first conversation');
        $this->type(self::ENTER);
        $this->settle();

        $first = $this->session->store()->path;

        $this->type('/new');
        $this->type(self::ENTER);
        $this->settle();

        $second = $this->session->store()->path;

        $this->assertNotSame($first, $second);

        $this->type('the second conversation');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertSame(
            ['the first conversation', 'first answer'],
            array_map(static fn (mixed $m): string => $m->content[0]->text ?? '', SessionManager::open($first)->messages()),
        );
        $this->assertSame(
            ['the second conversation', 'second answer'],
            array_map(static fn (mixed $m): string => $m->content[0]->text ?? '', SessionManager::open($second)->messages()),
        );

        // Two conversations, two sessions to pick from.
        $this->assertCount(2, SessionManager::listFor($this->cwd));
    }

    /** `--no-save` means no file, and `/new` does not quietly start one. */
    public function testANewSessionWritesNothingWhenNothingWasBeingWritten(): void
    {
        $this->start(['answered']);

        $this->type('/new');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertNull($this->session->store());
        $this->assertSame([], SessionManager::listFor($this->cwd));
    }

    public function testResumeOffersTheSessionsInThisDirectory(): void
    {
        $this->start(['answered'], store: true);
        $this->type('something memorable');
        $this->type(self::ENTER);
        $this->settle();
        $this->mode->stop();

        $this->start(store: true);
        $this->type('/resume');
        $this->type(self::ENTER);

        // Labelled by what was asked, which is how anyone remembers a conversation.
        $this->assertStringContainsString('something memorable', $this->screen());
        $this->assertStringContainsString('Pick a session', $this->screen());
    }

    public function testPickingOneReplacesTheConversation(): void
    {
        $this->start(['answered'], store: true);
        $this->type('something memorable');
        $this->type(self::ENTER);
        $this->settle();
        $this->mode->stop();

        $this->start(store: true);
        $this->type('/resume');
        $this->type(self::ENTER);
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('something memorable', $screen);
        $this->assertStringContainsString('Resumed 2 messages', $screen);
        $this->assertStringNotContainsString('Pick a session', $screen);
        $this->assertCount(2, $this->session->messages());
    }

    public function testTypingSearchesTheSessionsFromInsideASession(): void
    {
        // The same search the startup picker has. Without it the in-session list is eight rows
        // of openings and the arrow keys, which is the thing `SessionList` was written to fix —
        // and upstream shows the searchable list in both places, not one.
        $this->start(['first answer', 'second answer'], store: true);

        $this->type('the markdown lexer');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/new');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('the tls handshake');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/resume');
        $this->type(self::ENTER);

        // Newest first, so the highlighted row is the tls one; the query has to be what
        // decides, or this passes with no search at all.
        foreach (['l', 'e', 'x'] as $key) {
            $this->type($key);
        }

        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('the markdown lexer', $screen);
        $this->assertStringNotContainsString('the tls handshake', $screen);
    }

    public function testASearchThatMatchesNothingSaysSoRatherThanLookingEmpty(): void
    {
        $this->start(['answered'], store: true);
        $this->type('something memorable');
        $this->type(self::ENTER);
        $this->settle();
        $this->mode->stop();

        $this->start(store: true);
        $this->type('/resume');
        $this->type(self::ENTER);

        foreach (['z', 'z', 'z'] as $key) {
            $this->type($key);
        }

        $this->assertStringContainsString('No sessions found', $this->screen());
    }

    public function testEscapeClosesThePickerAndChangesNothing(): void
    {
        $this->start(['answered'], store: true);
        $this->type('something memorable');
        $this->type(self::ENTER);
        $this->settle();
        $this->mode->stop();

        $this->start(store: true);
        $this->type('/resume');
        $this->type(self::ENTER);
        $this->type(self::ESC);

        $this->assertStringNotContainsString('Pick a session', $this->screen());
        $this->assertSame([], $this->session->messages());
    }

    public function testWithNothingSavedResumeSaysSo(): void
    {
        $this->start(store: true);

        $this->type('/resume');
        $this->type(self::ENTER);

        $this->assertStringContainsString('No earlier sessions here yet', $this->screen());
    }

    // ---- running a command yourself -----------------------------------------------------

    public function testABangCommandRunsAndJoinsTheConversation(): void
    {
        $this->start();

        $this->type('!echo hello-from-bash');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('$ echo hello-from-bash', $screen);
        $this->assertStringContainsString('hello-from-bash', $screen);
        $this->assertCount(1, $this->session->messages());
        $this->assertInstanceOf(BashExecution::class, $this->session->messages()[0]);
    }

    public function testTwoBangsRunItAndKeepItOut(): void
    {
        $this->start();

        $this->type('!!echo quiet');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        // Shown the same way — what differs is whether the model sees it afterwards,
        // which is worth saying rather than leaving someone to remember.
        $this->assertStringContainsString('$ echo quiet', $screen);
        $this->assertStringContainsString('quiet', $screen);
        $this->assertStringContainsString('Not added to the conversation', $screen);
        $this->assertSame([], $this->session->messages());
    }

    public function testABareBangDoesNothing(): void
    {
        $this->start();

        $this->type('!');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertSame([], $this->session->messages());
        $this->assertStringNotContainsString('$', $this->screen());
    }

    public function testAFailingCommandIsMarkedAsOne(): void
    {
        $this->start();

        $this->type('!exit 3');
        $this->type(self::ENTER);
        $this->settle();

        // dark's toolErrorBg.
        $this->assertStringContainsString("\e[48;2;60;40;40m", implode('', $this->mode->screen()->render(80)));
        $this->assertSame(3, $this->session->messages()[0]->exitCode);
    }

    public function testTheExitCodeIsOnScreenAndNotOnlyInTheConversation(): void
    {
        $this->start();

        $this->type('!exit 3');
        $this->type(self::ENTER);
        $this->settle();

        // The colour says something went wrong and the number says what. 137 against 1 is the whole
        // point of the entry about signal numbers, and it is worth as much to a person as to a
        // model. The test above this one asserted the colour and then read the code off the
        // *message*, which is exactly where it was present.
        $this->assertStringContainsString('(exit 3)', $this->screen());
    }

    public function testACancelledCommandSaysItWasCancelledRatherThanJustFailing(): void
    {
        $this->start();

        $this->type('!sleep 5');
        $this->type(self::ENTER);

        // Turned rather than settled, and this is why the first version of this test could not
        // fail: `settle()` polls with no timeout, so with the command's pipes the only thing to
        // wait on it sat through the whole five seconds and escape arrived after the command had
        // already finished cleanly. `turnTheLoop()` arms a timer of its own, so the poll comes
        // back at once and the command is still running when the key goes in.
        self::turnTheLoop(5);
        $this->assertTrue($this->session->isBashRunning(), 'the command is still going');

        $this->type(self::ESC);

        for ($tick = 0; $tick < 400 && $this->session->isBashRunning(); $tick++) {
            Loop::get()->delay(0.005, static fn () => null);
            Loop::get()->tick();
        }

        self::turnTheLoop(5);

        // Escape stopping a command and a command failing are different things, and the block is
        // coloured the same for both.
        $this->assertStringContainsString('(cancelled)', $this->screen());
    }

    public function testTruncatedOutputSaysSoAndWhereTheRestIs(): void
    {
        $this->start();

        // Over `Truncate::MAX_LINES`, so `Run` spills the whole of it to a file and the tail is what
        // is kept. The model is told — `BashExecution::toText()` appends the sentence — and the
        // person watching it happen was told nothing, which is the notice's two readers with only
        // one of them served.
        $this->type('!seq 2500');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('Output truncated', $screen);
        $this->assertStringContainsString('/pig-bash-', $screen, 'and the file to read the rest in');
    }

    public function testACommandIsNotSentToTheModel(): void
    {
        // No scripted answers: if this reached the provider the test would blow up on
        // "out of scripted answers" rather than pass.
        $this->start();

        $this->type('!echo x');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertFalse($this->session->isStreaming());
    }

    // ---- going back ---------------------------------------------------------------------------

    public function testATranscriptDrawsAMessageWhoseBytesAreNotUtf8(): void
    {
        // The fifth transcript path. `pig @notes.txt` on a latin-1 file, or `pig $'ask \x80 this'`,
        // put bytes in here that no keystroke could — and `Graphemes::split()` throws on them from
        // inside `render()`, in the loop's own callback, which takes the session with it. The other
        // four paths had a guard; what the person said looked obviously safe.
        $this->start(['the answer'], initialMessages: ['why does ' . chr(0x80) . ' this fail']);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('why does', $screen);
        $this->assertStringContainsString('this fail', $screen);
    }

    public function testATreeRowSaysWhatWasSaidEvenWhenItsBytesAreNotUtf8(): void
    {
        // `bin/pig @notes.txt` on a latin-1 file is exactly this: the bytes reach the first
        // message without passing an editor, and `/tree` reads the conversation in memory rather
        // than the file, so the `JSON_INVALID_UTF8_SUBSTITUTE` that protects the file is not here.
        $this->start(['the answer'], store: true, initialMessages: ['why does ' . chr(0x80) . ' this fail']);
        $this->settle();

        $this->type('/tree');
        $this->type(self::ENTER);

        $screen = $this->screen();

        // `preg_replace('/\s+/u', …)` answers null on malformed UTF-8 and `(string) null` is '',
        // so the row fell through to `(nothing said)` — which is the line for a message that is a
        // pasted picture and nothing else, and the one row nobody would go back to.
        $this->assertStringNotContainsString('(nothing said)', $screen);
        $this->assertStringContainsString('why does', $screen);
    }

    public function testTreeOffersThePointsInThisConversation(): void
    {
        $this->start(['the answer'], store: true);

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/tree');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('Go back to', $screen);
        $this->assertStringContainsString('hello', $screen);
        // Who said it, which is what the tree shows instead of the old list's "N back": a row is
        // read for what is on it, and the position is the `•` down the side.
        $this->assertStringContainsString('user:', $screen);
        $this->assertStringContainsString('assistant:', $screen);
    }

    public function testTreeShowsABranchThatWasAbandonedAndCanGoBackToIt(): void
    {
        // The whole reason `/tree` is a tree. Going back and carrying on leaves the first direction
        // in the file with its parents intact, and listing only the current branch made it
        // unreachable: `goTo()` needs an id and nothing showed one.
        $this->start(['first answer', 'second answer', 'third answer'], store: true);

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('down the first road');
        $this->type(self::ENTER);
        $this->settle();

        // Back to the first exchange, then somewhere else.
        $this->type('/tree');
        $this->type(self::ENTER);
        $this->type("\e[A");
        $this->type("\e[A");
        $this->type(self::ENTER);
        $this->settle();

        // "Summarise the branch you are leaving?" — No is the default, so Enter is no. Without
        // answering it, everything typed next goes to that dialog: this test failed for exactly
        // that reason first, with the message quietly going nowhere.
        $this->type(self::ENTER);
        $this->settle();

        $this->type('down the second road');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/tree');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('down the second road', $screen, 'the branch being talked on');
        $this->assertStringContainsString('down the first road', $screen, 'and the one that was left');
        // The fork is drawn rather than implied.
        $this->assertMatchesRegularExpression('/[├└]/u', $screen);
    }

    public function testGoingBackRedrawsTheConversationWithoutWhatCameAfter(): void
    {
        $this->start(['first answer', 'second answer'], store: true);

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('and again');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/tree');
        $this->type(self::ENTER);
        // Two rows down from where the cursor starts, which is the point being talked on: the tree
        // opens on the leaf and wraps at the ends, so this lands earlier in the conversation.
        $this->type("\e[B");
        $this->type("\e[B");
        $this->type(self::ENTER);
        $this->settle();

        // "Summarise the branch you are leaving?" — Yes is first, as upstream has it; No is one down.
        $this->type("\e[B");
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Went back', $this->screen());
        $this->assertStringNotContainsString('second answer', $this->screen());
    }

    public function testGoingBackToSomethingYouSaidPutsItBackInThePrompt(): void
    {
        $this->start(['first answer'], store: true);

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        // Two rows, and the cursor opens on the leaf — the answer — so one press up is the
        // question. The list wraps, which is what makes one press enough.
        $this->type('/tree');
        $this->type(self::ENTER);
        $this->type("\e[A");
        $this->type(self::ENTER);
        $this->settle();

        // "Summarise the branch you are leaving?" — Yes is first; No is one down.
        $this->type("\e[B");
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        // The conversation is empty now, so "hello" on screen is the prompt and nothing else: the
        // question came back to be asked differently, which is what going back to it is for.
        $this->assertStringNotContainsString('first answer', $screen);
        $this->assertStringContainsString('hello', $screen);
        $this->assertSame([], $this->session->messages());
    }

    public function testWithNothingSaidYetThereIsNowhereToGoBackTo(): void
    {
        $this->start(store: true);

        $this->type('/tree');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Nothing to go back to yet', $this->screen());
    }

    public function testASessionThatIsNotSavedHasNowhereToGoBackTo(): void
    {
        $this->start(['the answer']);

        $this->type('/tree');
        $this->type(self::ENTER);

        $this->assertStringContainsString('not being saved', $this->screen());
    }

    // ---- exporting --------------------------------------------------------------------------

    public function testExportWritesTheConversationAsOneHtmlFile(): void
    {
        $this->start(['the answer'], store: true);

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $path = $this->cwd . '/out.html';
        $this->type('/export ' . $path);
        $this->type(self::ENTER);
        $this->settle();

        $this->assertFileExists($path);

        $html = (string) file_get_contents($path);

        $this->assertStringContainsString('hello', $html);
        $this->assertStringContainsString('the answer', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Exported to ' . $path, $this->screenText());
    }

    public function testASessionThatIsNotSavedHasNothingToExport(): void
    {
        $this->start(['the answer']);

        $this->type('/export');
        $this->type(self::ENTER);
        $this->settle();

        // The file is built from what is on disk, so a `--no-save` run has nothing to
        // build from — saying so beats writing an empty page.
        $this->assertStringContainsString('not being saved', $this->screen());
    }

    // ---- what is remembered for next time -------------------------------------------------------

    public function testSwitchingThemeIsRemembered(): void
    {
        $this->start();

        $this->type('/theme');
        $this->type(self::ENTER);

        // `/theme` that resets every run is a setting nobody uses twice.
        $this->assertSame('light', $this->settings->theme());
    }

    public function testHidingThinkingIsRemembered(): void
    {
        $this->start();

        $this->type("\x14");

        $this->assertTrue($this->settings->hideThinking());
    }

    public function testThinkingHiddenLastTimeStartsHidden(): void
    {
        $this->start(settings: Settings::inMemory(['hideThinkingBlock' => true]));

        $this->type("\x14");

        // Toggled from what was saved, not from the default: otherwise the first ctrl+t
        // of a session does nothing visible.
        $this->assertFalse($this->settings->hideThinking());
    }

    public function testSwitchingModelIsRemembered(): void
    {
        $this->start();

        $this->type('/model haiku:high');
        $this->type(self::ENTER);

        $this->assertSame('claude-haiku-4-5', $this->settings->defaultModel());
        $this->assertSame(ThinkingLevel::High, $this->settings->defaultThinkingLevel());
    }

    public function testCyclingTheThinkingLevelIsRemembered(): void
    {
        $this->start(reasoning: true);

        $this->type("\e[Z");

        $this->assertSame(ThinkingLevel::Minimal, $this->settings->defaultThinkingLevel());
    }

    // ---- commands kept as files ---------------------------------------------------------------

    public function testAFileCommandIsSentAsTheMessageItStandsFor(): void
    {
        $this->start(['done'], fileCommands: [
            new FileCommand('review', 'Review a file', 'Please review $1 closely.', '(user)'),
        ]);

        $this->type('/review src/Foo.php');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        // A command here is a stored prompt, not a program: it goes as if it were typed.
        $this->assertStringContainsString('Please review src/Foo.php closely.', $screen);
        $this->assertStringContainsString('done', $screen);
    }

    public function testAFileCommandCannotShadowABuiltInOne(): void
    {
        // No scripted answers: if `/help` reached the model this would blow up on
        // "out of scripted answers" rather than pass.
        $this->start(fileCommands: [new FileCommand('help', 'Not this one', 'send me instead', '(user)')]);

        $this->type('/help');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('ctrl+c', $this->screen());
        $this->assertStringNotContainsString('send me instead', $this->screen());
    }

    public function testAFileCommandsNameIsMatchedExactlyLikeAnyOther(): void
    {
        $this->start(['ok'], fileCommands: [new FileCommand('review', 'd', 'the stored prompt', '(user)')]);

        // One character off the name of a command that does exist, which is the case the
        // exact match is for: it is not `/review`, so it is not expanded into the prompt.
        $this->type('/reviews');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringNotContainsString('the stored prompt', $screen);
        $this->assertStringContainsString('/reviews', $screen);
    }

    // ---- copying ----------------------------------------------------------------------------

    public function testCopyPutsTheLastAnswerOnTheClipboard(): void
    {
        $this->start(['the whole answer']);

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/copy');
        $this->type(self::ENTER);

        $this->assertSame('the whole answer', $this->clipboard->written);
        $this->assertStringContainsString('Copied the last answer', $this->screen());
    }

    public function testCopyWithNothingAnsweredYetSaysSo(): void
    {
        $this->start();

        $this->type('/copy');
        $this->type(self::ENTER);

        $this->assertNull($this->clipboard->written);
        $this->assertStringContainsString('Error: No agent messages to copy yet', $this->screen());
    }

    public function testAMachineWithNoClipboardToolIsToldWhatToInstall(): void
    {
        $this->start(['the whole answer']);
        $this->clipboard->writable = false;

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/copy');
        $this->type(self::ENTER);

        // Not a broken machine, but worth naming the thing to install rather than
        // saying it did not work.
        $this->assertStringContainsString('wl-copy, xclip or xsel', $this->screen());
    }

    // ---- skills -----------------------------------------------------------------------------

    public function testLoadedSkillsAreListedInTheBanner(): void
    {
        $this->start(skills: [new Skill('tidy', 'tidy up a file', '/s/tidy/SKILL.md', '/s/tidy', 'user')]);

        $screen = $this->screen();

        $this->assertStringContainsString('[Skills]', $screen);
        $this->assertStringContainsString('tidy', $screen);
    }

    public function testNoSkillsMeansNoEmptyHeading(): void
    {
        $this->start();

        $this->type("\x0f");

        $this->assertStringNotContainsString('[Skills]', $this->screen());
    }

    public function testSkillsSaysWhereEachOneCameFrom(): void
    {
        $this->start(skills: [new Skill('tidy', 'tidy up a file', '/s/tidy/SKILL.md', '/s/tidy', 'claude-user')]);

        $this->type('/skills');
        $this->type(self::ENTER);

        $screen = $this->screen();

        // The same name can live in four places, and a shadowed skill looks exactly like
        // one that simply does not work.
        $this->assertStringContainsString('tidy', $screen);
        $this->assertStringContainsString('claude-user', $screen);
        $this->assertStringContainsString('/s/tidy/SKILL.md', $screen);
    }

    public function testTheDescriptionColumnLinesUpForASkillNamedInAnotherAlphabet(): void
    {
        $this->start(skills: [
            new Skill('tidy', 'tidy up a file', '/s/tidy/SKILL.md', '/s/tidy', 'user'),
            new Skill('代码审查', 'review the diff', '/s/review/SKILL.md', '/s/review', 'user'),
        ]);

        $this->type('/skills');
        $this->type(self::ENTER);

        $columns = [];

        foreach (explode("\n", $this->screen()) as $line) {
            $plain = Ansi::strip($line);

            foreach (['tidy up a file', 'review the diff'] as $description) {
                $at = mb_strpos($plain, $description, 0, 'UTF-8');

                if ($at !== false) {
                    $columns[$description] = Width::visible(mb_substr($plain, 0, $at, 'UTF-8'));
                }
            }
        }

        // `str_pad` counts bytes, so a name in an alphabet that spends more than one per character
        // is padded short and its description starts early — 代码审查 is 12 bytes and 8 columns, so
        // the column was four to the left of every other row's. `SelectList` and `SettingsList`
        // already measure their label columns in columns for this reason.
        $this->assertCount(2, $columns);
        $this->assertSame(
            $columns['tidy up a file'],
            $columns['review the diff'],
            'the two descriptions should start in the same column',
        );
    }

    public function testWithNoSkillsTheCommandSaysWhereToPutOne(): void
    {
        $this->start();

        $this->type('/skills');
        $this->type(self::ENTER);

        $this->assertStringContainsString('~/.pig/agent/skills', $this->screen());
    }

    // ---- models -----------------------------------------------------------------------------

    public function testModelOnItsOwnOffersTheList(): void
    {
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('Pick a model', $screen);
        $this->assertStringContainsString('claude-', $screen);
    }

    public function testTypingInTheModelListNarrowsItAndSwitchesToWhatIsLeft(): void
    {
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type('haiku');

        $this->assertStringContainsString('/haiku', $this->screen());

        // A fuzzy match over a line naming the provider, the id and the name is a loose net —
        // upstream's is too. What the assertion is about is the *ranking*: the best match is on
        // the top row, which is where typing puts the selection.
        $this->assertStringContainsString('claude-haiku-4-5', $this->selectedRow());

        $this->type(self::ENTER);

        $this->assertSame('claude-haiku-4-5', $this->session->model()?->id);
    }

    public function testTheModelListIsSearchedByProviderAndNameAsWellAsId(): void
    {
        // The rows are numbered `0`, `1`, `2`, so without a haystack of their own there is
        // nothing here anyone could type. Upstream ranks on provider, `provider/id`, id and name.
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type('anthropic/haiku');

        // Every token has to match, and `anthropic/…` is two tokens: a github-copilot row named
        // "Claude Haiku 4.5" cannot satisfy the first one.
        $this->assertStringContainsString('claude-haiku-4-5', $this->selectedRow());
        $this->assertStringNotContainsString('No matches', $this->screen());
    }

    public function testAProviderPrefixedQueryRanksThatProvidersOwnAboveAResellersCopy(): void
    {
        // The reason upstream's search line names the provider twice and the bare id last. Rank
        // the rows on how they read instead and `openai/gpt` puts groq's `openai/gpt-oss-120b`
        // first, because that row's *id* contains the provider the query names.
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type('openai/gpt');

        $row = $this->selectedRow();

        $this->assertStringContainsString('openai ·', $row);
        $this->assertStringNotContainsString('groq', $row);
    }

    public function testBackspaceInTheModelListWidensItAgain(): void
    {
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type('haiku');

        $this->assertStringContainsString('/haiku', $this->screen());
        $narrowed = $this->pickerTotal();

        foreach (range(1, 5) as $ignored) {
            $this->type("\x7f");
        }

        // The query line goes with the last character of the query, and the list is longer than
        // it was. The *selection* stays where it was rather than jumping back to the top, which
        // is upstream's behaviour and the point of clamping instead of resetting: you do not get
        // thrown to the start of a list you had scrolled into.
        $this->assertStringNotContainsString('/haiku', $this->screen());
        $this->assertGreaterThan($narrowed, $this->pickerTotal());
    }

    public function testEscapeLeavesTheModelListEvenAfterTypingInIt(): void
    {
        $this->start();
        $before = $this->session->model()?->id;

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type('haiku');
        $this->type(self::ESCAPE);

        $this->assertStringNotContainsString('Pick a model', $this->screen());
        $this->assertSame($before, $this->session->model()?->id);
    }

    public function testModelWithAPatternSwitchesWithoutOpeningTheList(): void
    {
        $this->start();

        $this->type('/model haiku');
        $this->type(self::ENTER);

        $this->assertSame('claude-haiku-4-5', $this->session->model()?->id);
        $this->assertStringNotContainsString('Pick a model', $this->screen());
        $this->assertStringContainsString('Model: claude-haiku-4-5', $this->screen());
    }

    public function testAThinkingLevelCanRideAlongWithTheModel(): void
    {
        $this->start();

        $this->type('/model sonnet:high');
        $this->type(self::ENTER);

        $this->assertSame(ThinkingLevel::High, $this->session->thinkingLevel());

        // Said out loud, because switching models can change the level under you and
        // finding that out from a bill is worse than reading it here.
        $this->assertStringContainsString('thinking high', $this->screen());
    }

    public function testAPatternThatMatchesNothingSaysSoInsteadOfPickingOne(): void
    {
        $this->start();
        $before = $this->session->model()?->id;

        $this->type('/model llama-9');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Error: No model matches "llama-9"', $this->screen());
        $this->assertSame($before, $this->session->model()?->id);
    }

    public function testTheListIsTheModelsThereIsAKeyFor(): void
    {
        // A key for openai and nothing else, because the registry starts with twenty-one anthropic
        // models: a picker that was not filtered would show eight of those and this case would
        // pass on a screen that never mentions the provider it is about.
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('openai', 'for-this-run');
        $this->start(auth: $auth);

        $this->type('/model');
        $this->type(self::ENTER);

        $screen = $this->screen();

        // Upstream's model selector lists `getAvailable()`. A picker that offers a model whose
        // every turn will fail is a picker that teaches people not to read it.
        $this->assertStringContainsString('openai', $screen);
        $this->assertStringNotContainsString('anthropic', $screen);
    }

    public function testAPatternForAProviderWithNoKeyMatchesNothing(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('anthropic', 'for-this-run');
        $this->start(auth: $auth);
        $before = $this->session->model()?->id;

        $this->type('/model gpt-5');
        $this->type(self::ENTER);

        // Rather than switching to it and failing on the next turn, from inside the turn.
        $this->assertStringContainsString('No model matches "gpt-5"', $this->screen());
        $this->assertSame($before, $this->session->model()?->id);
    }

    public function testCtrlPMovesToTheNextModelAndBackAgain(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('anthropic', 'for-this-run');
        $this->start(auth: $auth);

        // `CustomEditor` claimed both keys from the start and nothing was listening: upstream
        // binds them to cycling the model, so pressing them did nothing at all.
        $this->type("\x10");
        $first = $this->session->model()?->id;

        $this->type("\x10");
        $second = $this->session->model()?->id;

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('Model: ' . $second, $this->screen());

        $this->type("\e[112;6u");

        // Which is the point of having both: overshooting by one keystroke is the normal way to
        // use this, and the way back has to be a keystroke too.
        $this->assertSame($first, $this->session->model()?->id, 'shift+ctrl+p goes back');
    }

    public function testCtrlLOpensTheModelPicker(): void
    {
        $this->start();

        // `CustomEditor` has claimed ctrl+l since it was ported and nothing was listening, so the
        // key was taken off the text field and did nothing at all — the third time that exact
        // shape has turned up, after ctrl+p and `/debug`. Upstream binds it to its model selector.
        $this->type("\x0c");

        $this->assertStringContainsString('Pick a model', $this->screen());
    }

    public function testAMovedBindingMovesTheKeyTheHelpAndFreesTheOldOne(): void
    {
        $this->start(keybindings: new \Pig\CodingAgent\Keybindings(['app.model.select' => ['ctrl+e']]));

        // The old key is the text field's again — ctrl+l does nothing to the picker...
        $this->type("\x0c");
        $this->assertStringNotContainsString('Pick a model', $this->screen());

        // ...and the new one opens it.
        $this->type("\x05");
        $this->assertStringContainsString('Pick a model', $this->screen());
        $this->type("\e");

        // And the help names the key that works, not the one that used to.
        $this->type('/help');
        $this->type("\r");
        $text = $this->screenText();
        $this->assertMatchesRegularExpression('/ctrl\+e\s+choose a model from the list/', $text);
        $this->assertDoesNotMatchRegularExpression('/ctrl\+l\s+choose a model/', $text);
    }

    public function testCtrlPStaysInsideTheScopeAndCarriesItsThinkingLevel(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('anthropic', 'for-this-run');
        [$scope, $warnings] = ModelResolver::scope(
            ['zzp-plain', 'zzp-alpha:high'],
            $auth->availableModels(),
        );
        $this->assertSame([], $warnings);

        $this->start(auth: $auth, reasoning: true, modelScope: $scope);

        $this->type("\x10");

        // Two models in the scope and the current one is in neither — the test model is
        // `claude-test` — so the first press lands on the second entry, which is upstream's
        // `indexOf` quirk. What matters here is the level: `--models opus:high` says how hard
        // *that* model thinks, and a cycle that applied the model and forgot the level would be
        // the wired-at-one-end shape again.
        $this->assertSame('zzp-alpha', $this->session->model()?->id);
        $this->assertSame(ThinkingLevel::High, $this->session->thinkingLevel());
        $this->assertStringContainsString('thinking high', $this->screen());

        $this->type("\x10");

        $this->assertSame('zzp-plain', $this->session->model()?->id, 'and round the end of the scope');
    }

    public function testThePickerOffersTheScopeAndNothingElse(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('anthropic', 'for-this-run');
        [$scope] = ModelResolver::scope(['claude-haiku-4-5'], $auth->availableModels());

        $this->start(auth: $auth, modelScope: $scope);

        $this->type('/model');
        $this->type(self::ENTER);

        $screen = $this->screen();

        // A picker offering twenty models ctrl+p cannot reach is the same fault as a picker
        // offering a model whose every turn fails, one step milder — so the scope narrows every
        // list, not only the cycling.
        $this->assertStringContainsString('claude-haiku-4-5', $screen);
        $this->assertStringNotContainsString('claude-opus-4-1', $screen);
    }

    public function testWithOneModelInTheScopeCtrlPSaysSoRatherThanNothing(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('anthropic', 'for-this-run');
        [$scope] = ModelResolver::scope(['claude-haiku-4-5'], $auth->availableModels());

        $this->start(auth: $auth, modelScope: $scope);

        $this->type("\x10");

        $this->assertStringContainsString('Only one model in the scope --models set.', $this->screen());
    }

    public function testPickingFromTheListSwitches(): void
    {
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type(self::ENTER);

        // **The first row of the list, whichever it is** — which is what the assertion says
        // now. It used to name the row, and the first regeneration of the registry from
        // models.dev put a different model at the top, so a case whose own comment said the id
        // did not matter failed because the id had changed. What matters is that choosing one
        // actually changes the model and closes the picker.
        $this->assertNotSame('claude-test', $this->session->model()?->id);
        $this->assertNotNull($this->session->model());
        $this->assertStringNotContainsString('Pick a model', $this->screen());
    }

    // ---- compaction -------------------------------------------------------------------------

    public function testCompactSummarisesTheConversationAndSaysSoInTheTranscript(): void
    {
        $this->start(['a summary of it all']);

        foreach (range(1, 6) as $ignored) {
            $this->session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        $this->type('/compact');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('Compacted', $screen);
        $this->assertStringContainsString('earlier messages summarised', $screen);

        // Collapsed by default: the summary is long, and the transcript above it is what
        // the person was reading.
        $this->assertStringNotContainsString('a summary of it all', $screen);
    }

    public function testCtrlOOpensTheSummaryTheModelWillBeWorkingFrom(): void
    {
        $this->start(['a summary of it all']);

        foreach (range(1, 6) as $ignored) {
            $this->session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        $this->type('/compact');
        $this->type(self::ENTER);
        $this->settle();

        $this->type("\x0f");

        // The only way to tell a good summary from a bad one before it costs you
        // something.
        $this->assertStringContainsString('a summary of it all', $this->screen());
    }

    public function testANearlyFullContextIsCompactedBeforeTheTurnRatherThanAfterTheFailure(): void
    {
        $this->start(['a summary of it all', 'and the answer']);

        foreach (range(1, 6) as $ignored) {
            $this->session->agent->appendMessage(new UserMessage(str_repeat('x', 40_000)));
        }

        // What the provider said the last turn carried: 190k of a 200k window, which
        // leaves no room for an answer.
        $this->session->agent->appendMessage(new AssistantMessage(
            [new TextContent('so far so good')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(0, 0, 0, 0, 190_000),
            StopReason::Stop,
        ));

        $this->type('one more thing');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('Context is nearly full', $screen);
        $this->assertStringContainsString('Compacted', $screen);
        $this->assertStringContainsString('and the answer', $screen);
    }

    public function testCompactingAShortConversationSaysWhyInUpstreamsWords(): void
    {
        // No scripted answers: a conversation with nothing old enough to drop must not
        // reach the provider at all.
        $this->start();

        $this->type('/compact');
        $this->type(self::ENTER);
        $this->settle();

        // Upstream's wording, because it is the wording someone will search for.
        $this->assertStringContainsString(
            'Error: Compaction failed: Nothing to compact (session too small)',
            $this->screen(),
        );
    }

    public function testCompactingTwiceOverSaysItIsAlreadyDone(): void
    {
        $this->start();
        $this->session->agent->appendMessage(new CompactionSummary('already summarised'));

        $this->type('/compact');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Error: Compaction failed: Already compacted', $this->screen());
    }

    public function testCompactingWhileTheAgentWorksSaysToStopItFirst(): void
    {
        $this->start(['done']);

        $held = $this->holdTheAgent();

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/compact');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Warning: Still working', $this->screen());

        $held();
        $this->settle();
    }

    // ---- a prompt from the command line -------------------------------------------------

    public function testAMessageFromTheCommandLineIsSentWithoutAKeystroke(): void
    {
        $this->start(answers: ['the answer'], initialMessages: ['what does this do?']);
        $this->settle();

        // `bin/pig "what does this do?"` asks, and then leaves you in the terminal — which is
        // the difference from `-p`, and the reason it goes through the same path a typed
        // message does rather than a shortcut of its own.
        $screen = $this->screen();
        $this->assertStringContainsString('what does this do?', $screen);
        $this->assertStringContainsString('the answer', $screen);
    }

    public function testSeveralMessagesAreSentInTheOrderTheyWereGiven(): void
    {
        $this->start(answers: ['first answer', 'second answer'], initialMessages: ['one', 'two']);
        $this->settle();

        $messages = $this->session->messages();
        $this->assertCount(4, $messages);
        $this->assertSame('one', $messages[0]->content[0]->text);
        $this->assertSame('first answer', $messages[1]->content[0]->text);
        $this->assertSame('two', $messages[2]->content[0]->text);
    }

    public function testTheTerminalIsStillYoursAfterwards(): void
    {
        $this->start(answers: ['answered', 'and again'], initialMessages: ['from the shell']);
        $this->settle();

        // Two calls, because one chunk is one key: `'text' . ENTER` arrives as a
        // fourteen-character key that is not Enter, and lands in the editor as text.
        $this->type('typed by hand');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('typed by hand', $this->screen());
        $this->assertCount(4, $this->session->messages());
    }

    public function testAnImageRidesOnTheFirstMessageOnly(): void
    {
        $image = new ImageContent(base64_encode('bytes'), 'image/png');
        $this->start(
            answers: ['ok', 'ok again'],
            initialMessages: ['look', 'and again'],
            initialImages: [$image],
        );
        $this->settle();

        $messages = $this->session->messages();
        $this->assertCount(2, $messages[0]->content, 'the text and the image');
        $this->assertCount(1, $messages[2]->content, 'the text only');
    }

    public function testNoMessageFromTheCommandLineLeavesTheBannerAlone(): void
    {
        $this->start();
        $this->settle();

        $this->assertSame([], $this->session->messages());
        $this->assertStringContainsString('pig v0.0.0', $this->screen());
    }

    // ---- the queue ------------------------------------------------------------------------------

    public function testTypingWhileTheAgentWorksQueuesRatherThanRefuses(): void
    {
        $this->start([...array_fill(0, 2, 'done')]);

        $held = $this->holdTheAgent();

        $this->type('first');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('and another thing');
        $this->type(self::ENTER);

        $this->assertSame(['and another thing'], $this->session->queued());
        $this->assertStringContainsString('Queued: and another thing', $this->screen());

        $held();
        $this->settle();
    }

    public function testQuittingWhileTheAgentIsWorkingAbortsTheAgentAndStops(): void
    {
        $this->start(['done']);
        $this->holdTheAgent();

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertTrue($this->session->isStreaming());

        $this->type('/quit');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertFalse($this->session->isStreaming());
    }

    public function testAFileCommandQueuedMidTurnReachesTheModelAsItsPromptAndComesBackAsItsName(): void
    {
        $this->start(['first done', 'reviewed'], fileCommands: [
            new FileCommand('review', 'Review a file', 'Please review $1 closely.', '(user)'),
        ]);

        $held = $this->holdTheAgent();

        $this->type('first');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/review src/Foo.php');
        $this->type(self::ENTER);

        // Two halves of one decision. What is *waiting* is the line as typed, because that list
        // is what escape hands back to the editor — a forty-line stored prompt in front of
        // somebody who typed `/review foo.php` is not putting their text back.
        $this->assertSame(['/review src/Foo.php'], $this->session->queued());
        $this->assertStringContainsString('Queued: /review src/Foo.php', $this->screen());

        $held();
        $this->settle();

        // And what the *model* is handed is the prompt. Upstream queues the raw line at both
        // ends, so a file command typed during a turn reaches the model there as the eight
        // characters `/review `.
        $this->assertStringContainsString('Please review src/Foo.php closely.', $this->screen());
    }

    public function testInterruptingGivesTheQueuedTextBack(): void
    {
        $this->start(['done']);

        $held = $this->holdTheAgent();

        $this->type('first');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('second');
        $this->type(self::ENTER);
        $this->type(self::ESC);

        // Losing what someone typed because they pressed Escape is how people stop
        // trusting the queue at all, so it goes back into the editor.
        $this->assertSame([], $this->session->queued());
        $this->assertStringContainsString('second', $this->screen());

        $held();
        $this->settle();
    }

    // ---- hooks --------------------------------------------------------------------------

    public function testSlashHooksSaysSoWhenThereAreNone(): void
    {
        $this->start();
        $this->type('/hooks');
        $this->type(self::ENTER);

        $this->assertStringContainsString('No hooks loaded', $this->screen());
    }

    public function testSlashHooksListsWhatLoadedAndWhatItAdded(): void
    {
        $api = new HookApi($this->cwd, '/somewhere/deploy.php');
        $api->registerCommand('deploy', static fn () => null, 'Ship it');

        $this->start(hooks: $this->runner($api, '/somewhere/deploy.php'));
        $this->type('/hooks');
        $this->type(self::ENTER);
        $screen = $this->screen();

        $this->assertStringContainsString('/somewhere/deploy.php', $screen);
        $this->assertStringContainsString('/deploy', $screen);
        $this->assertStringContainsString('Ship it', $screen);
    }

    public function testACommandAHookRegisteredRunsWithItsArguments(): void
    {
        $seen = null;
        $api = new HookApi($this->cwd, 'deploy.php');
        $api->registerCommand('deploy', static function (string $arguments) use (&$seen): void {
            $seen = $arguments;
        });

        $this->start(hooks: $this->runner($api));
        $this->type('/deploy staging --now');
        $this->type(self::ENTER);
        // Settled, because the command runs inside `AgentSession::prompt()` in a fiber of its
        // own now — a handler that opens a dialog must not be run inside the input callback.
        $this->settle();

        $this->assertSame('staging --now', $seen);
    }

    public function testACommandThatFailsIsAWarningRatherThanTheEndOfTheSession(): void
    {
        $api = new HookApi($this->cwd, 'deploy.php');
        $api->registerCommand('deploy', static function (): void {
            throw new RuntimeException('no credentials');
        });

        $this->start(hooks: $this->runner($api));
        $this->type('/deploy');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Warning: hook deploy.php (/deploy): no credentials', $this->screen());
    }

    public function testAHookCannotShadowABuiltIn(): void
    {
        $called = false;
        $api = new HookApi($this->cwd, 'evil.php');
        $api->registerCommand('help', static function () use (&$called): void {
            $called = true;
        });

        $this->start(hooks: $this->runner($api));
        $this->type('/help');
        $this->type(self::ENTER);

        $this->assertFalse($called);
        $this->assertStringContainsString('ctrl+o', $this->screen());
    }

    public function testAHookCommandIsOfferedInTheBanner(): void
    {
        $api = new HookApi($this->cwd, 'deploy.php');
        $api->registerCommand('deploy', static fn () => null, 'Ship it');

        $this->start(hooks: $this->runner($api));
        $this->type("\x0f");

        $this->assertStringContainsString('deploy.php', $this->screen());
    }

    public function testAHookThatFailsMidRunIsAWarningInTheTranscript(): void
    {
        $api = new HookApi($this->cwd, 'watcher.php');
        $api->on('turn_end', static function (): void {
            throw new RuntimeException('counted wrong');
        });

        $this->start(['the answer'], hooks: $this->runner($api, 'watcher.php'));
        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('the answer', $screen);
        $this->assertStringContainsString('Warning: hook watcher.php (turn_end)', $screen);
    }

    public function testAHookCommandStillRunsWhileTheAgentIsWorking(): void
    {
        $seen = null;
        $api = new HookApi($this->cwd, 'deploy.php');
        $api->registerCommand('deploy', static function (string $arguments) use (&$seen): void {
            $seen = $arguments;
        });

        $this->start(['answered'], hooks: $this->runner($api));
        $release = $this->holdTheAgent();

        $this->type('hi');
        $this->type(self::ENTER);
        $this->settle();

        // A hook's command is code, not a message, so there is nothing for it to wait behind —
        // which is why the dispatch in `prompt()` sits in front of the "already working" throw.
        // Upstream's own comment says the same and its code throws there.
        $this->type('/deploy now');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertSame('now', $seen);

        $release();
        $this->settle();
    }

    private function runner(HookApi $api, string $path = 'deploy.php'): HookRunner
    {
        return new HookRunner([new LoadedHook($path, $path, $api)], $this->cwd);
    }

    // ---- naming a point ----------------------------------------------------------------

    public function testLabelNamesWhereTheConversationIs(): void
    {
        $this->start(answers: ['answered'], store: true);
        $this->type('hi');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/label before the refactor');
        $this->type(self::ENTER);
        $this->settle();

        // The last thing *said*, which is not the leaf: writing the label advanced it.
        $store = $this->session->store();
        self::assertNotNull($store);
        $points = $store->branch();
        $named = $points[count($points) - 1]['id'];

        $this->assertSame('before the refactor', $store->labelOf($named));
        $this->assertStringContainsString('before the refactor', $this->screen());
    }

    public function testNameCommandSetsAndShowsSessionName(): void
    {
        $this->start(answers: ['answered'], store: true);
        $this->type('/name');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Usage: /name <name>', $this->screen());

        $this->type('/name 仿写更新日志规则模板');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Session name set: 仿写更新日志规则模板', $this->screen());
        $this->assertSame('仿写更新日志规则模板', $this->session->getSessionName());

        $this->type('/name');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Session name: 仿写更新日志规则模板', $this->screen());
    }

    public function testTreeShowsTheNameBesideWhatWasSaid(): void
    {
        $this->start(answers: ['answered', 'again'], store: true);
        $this->type('the first thing');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/label before the refactor');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('the second thing');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/tree');
        $this->type(self::ENTER);
        $this->settle();

        // Both: the name is what you were thinking, the message is what was actually said,
        // and a list of "4 back · 7 back" is a list nobody can choose from.
        $screen = $this->screen();
        $this->assertStringContainsString('before the refactor', $screen);
        $this->assertStringContainsString('the first thing', $screen);
    }

    public function testLabelWithNothingClearsIt(): void
    {
        $this->start(answers: ['answered'], store: true);
        $this->type('hi');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/label a name');
        $this->type(self::ENTER);
        $this->settle();

        $this->type('/label');
        $this->type(self::ENTER);
        $this->settle();

        // Asserted on the point that was named, not on whatever the leaf is now — which
        // is what made the first version of this test pass without clearing anything.
        $store = $this->session->store();
        self::assertNotNull($store);
        $points = $store->branch();
        $named = $points[count($points) - 1]['id'];

        $this->assertNull($store->labelOf($named));
        $this->assertStringContainsString('Name cleared', $this->screen());
    }

    public function testLabelInAnUnsavedSessionSaysWhyNot(): void
    {
        $this->start(answers: ['answered']);

        $this->type('/label a name');
        $this->type(self::ENTER);
        $this->settle();

        // Not silence: a name that would not survive the session is a name nobody asked for.
        $this->assertStringContainsString('would not survive', $this->screen());
    }

    // ---- an edit shown before it is made ----------------------------------------------

    /** @param array<string, mixed> $arguments */
    private static function wants(string $tool, array $arguments): AssistantMessage
    {
        return new AssistantMessage(
            [new ToolCall('call-1', $tool, $arguments)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::ToolUse,
        );
    }

    /**
     * The window the preview exists for, and the wire that has to reach it.
     *
     * The component computing its own preview is one end; this is the other. A `tool_call`
     * hook parks the whole turn on a question, and until this was wired the answer was
     * given with nothing on screen but a path — approving a change to a file without being
     * shown the change.
     */
    public function testAnEditIsOnScreenBeforeAHookIsAskedWhetherToAllowIt(): void
    {
        file_put_contents($this->cwd . '/notes.txt', "one\ntwo\nthree\n");

        $api = new HookApi($this->cwd, 'guard.php');
        $api->on('tool_call', function ($event, $context): mixed {
            $context->ui->confirm('Let edit run?', 'notes.txt');

            return null;
        });

        $this->start(
            answers: [self::wants('edit', ['path' => 'notes.txt', 'oldText' => 'two', 'newText' => 'TWO']), 'done'],
            hooks: $this->runner($api, 'guard.php'),
            tools: ['edit'],
        );

        $this->type('fix line two');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();
        $this->assertStringContainsString('Let edit run?', $screen);
        $this->assertStringContainsString('-2 two', $screen);
        $this->assertStringContainsString('+2 TWO', $screen);

        // And it really has not happened yet.
        $this->assertSame("one\ntwo\nthree\n", file_get_contents($this->cwd . '/notes.txt'));

        $this->type("\e[B");
        $this->type(self::ENTER);
        $this->settle();

        $this->assertSame("one\nTWO\nthree\n", file_get_contents($this->cwd . '/notes.txt'));
    }

    /**
     * The other end of the wire, and the end that was missing.
     *
     * `HookRunnerTest` drives the runner with a signal handed to it directly; nothing checked
     * that the *terminal* hands it one. Taking `signal:` out of `initialize()` here broke no
     * test at all — the same "wired at one end only" shape as the ctrl+o handler that named
     * three classes, made again inside its own fix.
     */
    public function testEscapeStopsACommandAHooksGuardStarted(): void
    {
        $result = null;
        $api = new HookApi($this->cwd, 'guard.php');
        $api->on('tool_call', function ($event, $context) use (&$result, $api): mixed {
            // Escape arrives while this is parked on the command, which is the real order: the
            // loop can only deliver the key because the command is not blocking it.
            Async::spawn(function (): void {
                Async::delay(0.05);
                $this->type(self::ESC);
            });

            $result = $api->exec(['sh', '-c', 'echo guarding; sleep 5']);

            return null;
        });

        $this->start(
            answers: [self::wants('read', ['path' => 'notes.txt']), 'done'],
            hooks: $this->runner($api, 'guard.php'),
        );

        $started = microtime(true);
        $this->type('read it');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertNotNull($result, 'the guard never ran');
        $this->assertTrue($result->stopped());
        $this->assertStringContainsString('guarding', $result->stdout);
        $this->assertLessThan(3.0, microtime(true) - $started, 'escape did not reach the command');
    }

    public function testEnterDuringAnAutoCompactionKeepsWhatYouTypedRatherThanLosingIt(): void
    {
        // `Editor::$disableSubmit` is honoured, `CustomEditor::disableSubmit()` is public, and
        // nothing in pig ever called either — upstream's one use is exactly this window. Without
        // it the submit handler clears the editor and spawns a turn that parks on the
        // compaction; when the compaction's own carry-on starts a run, that turn wakes up to
        // `Agent is already working` and the message is gone, with a red line where it went.
        $this->start(
            answers: [
                'first',
                'second',
                self::failed('prompt is too long: 213462 tokens > 200000 maximum'),
                'the summary',
                'answered after summarising',
                'and one more',
            ],
            settings: Settings::inMemory(['compaction' => ['keepRecentTokens' => 1]]),
        );

        // Two answered turns first, because a compaction has to be worth making — and the fourth
        // model call, the summariser, is the one held.
        $release = $this->holdTheAgent(letThrough: 3);

        foreach (['one', 'two', 'three'] as $said) {
            $this->type($said);
            $this->type(self::ENTER);
            $this->settle();
        }

        $this->assertStringContainsString('summarising', strtolower($this->screen()));

        $this->type('a question I was half way through');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('a question I was half way through', $this->screen());
        $this->assertStringNotContainsString('already working', $this->screen());

        $release();
        $this->settle();

        // And Enter works again afterwards, which nothing else asserts: leaving the flag set is a
        // prompt that has quietly stopped sending, and it breaks no other test in this file.
        $this->type(' — and now the rest of it');
        $this->type(self::ENTER);
        $this->settle();

        // The *answer* and not the text: with submit still disabled the typed line is on screen
        // either way — in the editor rather than in the transcript — so asserting on it is an
        // assertion that cannot fail. What only happens if the turn ran is the reply.
        $this->assertStringContainsString('and one more', $this->screen());
    }

    public function testEscapeStopsTheRetryTheScreenSaysItCanStop(): void
    {
        // Two loaders on this screen name escape — the retry countdown and the summariser — and
        // `interrupt()`'s last branch called `$this->session->agent->abort()`, which reaches
        // neither: a sleeping retry and a running summariser both happen between runs, where the
        // agent has no controller to raise. So the label said "esc to stop" and the key did not.
        $this->start(
            answers: [self::failed('Anthropic returned 503: overloaded'), 'here you go'],
            settings: Settings::inMemory(['retry' => ['baseDelayMs' => 30_000]]),
        );

        $this->type('hi');
        $this->type(self::ENTER);
        self::turnTheLoop();

        $this->assertTrue($this->session->isRetrying());
        $this->assertStringContainsString('esc to stop', $this->screen());

        $this->type(self::ESC);
        self::turnTheLoop();

        $this->assertFalse($this->session->isRetrying());
        $this->assertStringContainsString('cancelled', $this->screen());
    }

    // ---- what a hook says -------------------------------------------------------------

    public function testAHooksMessageIsDrawnAsItsOwnThingNotAsSomethingYouSaid(): void
    {
        $api = new HookApi('.', 'test.php');
        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        Async::spawn(static function () use ($api): void {
            $api->sendMessage('build', 'the build is broken');
        });
        $this->settle();

        $screen = $this->screen();
        $this->assertStringContainsString('the build is broken', $screen);

        // Labelled with the hook's own type: it reaches the model as a user message, and
        // drawing it as one would have someone scroll back and find themselves saying
        // something they never typed.
        $this->assertStringContainsString('build', $screen);
    }

    public function testCtrlOOpensALongHookMessageTheWayItOpensEverythingElse(): void
    {
        // The component folding itself is one end; this is the other, and it is the end that
        // was missing — the ctrl+o handler named three classes and the fold has to be reached
        // through it or the component folds for nobody.
        $api = new HookApi('.', 'test.php');
        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        $long = implode("\n", array_map(static fn (int $i): string => "detail {$i}", range(1, 20)));

        Async::spawn(static function () use ($api, $long): void {
            $api->sendMessage('lint', $long);
        });
        $this->settle();

        $this->assertStringNotContainsString('detail 20', $this->screen(), 'folded to start with');

        $this->type("\x0f");

        $this->assertStringContainsString('detail 20', $this->screen(), 'ctrl+o did not reach it');
    }

    public function testAMessageMarkedNotToDisplayIsNotDrawn(): void
    {
        $api = new HookApi('.', 'test.php');
        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        Async::spawn(static function () use ($api): void {
            $api->sendMessage('reminder', 'only for the model', display: false);
        });
        $this->settle();

        // It is in the conversation and not on the screen — which is the whole point of the
        // flag: a reminder injected before every turn would otherwise fill the transcript.
        $this->assertStringNotContainsString('only for the model', $this->screen());
        $this->assertCount(1, $this->session->messages());
    }

    public function testAHookCanDrawItsOwnMessage(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->registerMessageRenderer(
            'build',
            static fn (): Text => new Text('drawn by the hook itself', 0, 0),
        );

        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        Async::spawn(static function () use ($api): void {
            $api->sendMessage('build', 'the plain version');
        });
        $this->settle();

        $screen = $this->screen();
        $this->assertStringContainsString('drawn by the hook itself', $screen);
        $this->assertStringNotContainsString('the plain version', $screen);
    }

    public function testARendererThatReturnsNullGetsTheDefaultWithoutComplaint(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->registerMessageRenderer('build', static fn (): mixed => null);

        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        Async::spawn(static function () use ($api): void {
            $api->sendMessage('build', 'the plain version');
        });
        $this->settle();

        // "Nothing to draw here" is a legitimate answer.
        $screen = $this->screen();
        $this->assertStringContainsString('the plain version', $screen);
        $this->assertStringNotContainsString('Warning:', $screen);
    }

    public function testARendererThatThrowsFallsBackAndSaysSo(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->registerMessageRenderer('build', static fn (): mixed => throw new RuntimeException('bad renderer'));

        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        Async::spawn(static function () use ($api): void {
            $api->sendMessage('build', 'the plain version');
        });
        $this->settle();

        // Both: the message still gets drawn, and the broken renderer is named. A picture
        // that quietly turns into plain text is a bug nobody reports.
        $screen = $this->screen();
        $this->assertStringContainsString('the plain version', $screen);
        $this->assertStringContainsString('bad renderer', $screen);
    }

    public function testARendererThatReturnsSomethingElseIsAComplaint(): void
    {
        $api = new HookApi('.', 'test.php');
        $api->registerMessageRenderer('build', static fn (): string => 'not a component');

        $hooks = new HookRunner([new LoadedHook('test.php', 'test.php', $api)], $this->cwd);
        $this->start(answers: ['ok'], hooks: $hooks);

        Async::spawn(static function () use ($api): void {
            $api->sendMessage('build', 'the plain version');
        });
        $this->settle();

        $screen = $this->screen();
        $this->assertStringContainsString('not a component', $screen);
        $this->assertStringContainsString('the plain version', $screen);
    }

    public function testAHooksMessageComesBackWhenTheSessionIsResumed(): void
    {
        $this->start(answers: ['answered'], store: true);
        $this->type('hi');
        $this->type(self::ENTER);
        $this->settle();

        $store = $this->session->store();
        self::assertNotNull($store);
        $store->append(new HookMessage('build', [new TextContent('remembered across a restart')]));

        $path = $store->path;
        $this->mode->stop();
        $this->start(answers: [], resume: $path);

        $this->assertStringContainsString('remembered across a restart', $this->screen());
    }

    // ---- custom tools -------------------------------------------------------------------

    public function testSlashToolsListsTheBuiltInsAndWhereTheyCameFrom(): void
    {
        $this->start();
        $this->type('/tools');
        $this->type(self::ENTER);

        $this->assertStringContainsString('built-in', $this->screen());
    }

    public function testSlashToolsNamesTheFileACustomToolCameFrom(): void
    {
        $this->start(customTools: $this->tools('wc'));
        $this->type('/tools');
        $this->type(self::ENTER);
        $screen = $this->screen();

        $this->assertStringContainsString('wc', $screen);
        $this->assertStringContainsString('wc/index.php', $screen);
    }

    public function testACustomToolIsNamedInTheBanner(): void
    {
        $this->start(customTools: $this->tools('wc'));

        $this->assertStringContainsString('[Tools]', $this->screen());
    }

    public function testAToolIsToldTheSessionStartedAndThenSwitched(): void
    {
        $seen = [];
        $this->start(customTools: $this->tools('wc', function ($event) use (&$seen): void {
            $seen[] = $event->reason;
        }));

        $this->type('/new');
        $this->type(self::ENTER);

        $this->assertSame(['start', 'switch'], $seen);
    }

    public function testAToolIsToldOnTheWayOut(): void
    {
        $seen = [];
        $this->start(customTools: $this->tools('wc', function ($event) use (&$seen): void {
            $seen[] = $event->reason;
        }));

        $this->mode->stop();

        $this->assertSame(['start', 'shutdown'], $seen);
    }

    public function testACallbackThatFailsIsAWarningRatherThanAStartupCrash(): void
    {
        // Only on start: the tear-down stops the mode, and a tool that also failed on the
        // way out would print to the test run's stderr for no extra assurance.
        $this->start(customTools: $this->tools('wc', static function ($event): void {
            if ($event->reason === 'start') {
                throw new RuntimeException('could not read its cache');
            }
        }));

        $this->assertStringContainsString(
            'Warning: tool wc/index.php: onSession(start)',
            $this->screen(),
        );
    }

    private function tools(string $name, ?Closure $onSession = null): CustomToolSet
    {
        $path = "{$name}/index.php";
        $tool = new CustomTool(
            name: $name,
            label: 'Count lines',
            description: 'Counts the lines in a file.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: static fn () => new \Pig\Agent\AgentToolResult([new TextContent('ran')]),
            onSession: $onSession,
        );

        return new CustomToolSet([new LoadedCustomTool($path, $path, $tool)]);
    }

    /**
     * Keep the agent mid-run until the returned closure is called.
     *
     * The scripted provider answers instantly, which is right for every other test and
     * useless for the two about what happens while it is still working.
     */
    private function holdTheAgent(int $letThrough = 0): Closure
    {
        $this->holding = true;
        $this->letThrough = $letThrough;

        return function (): void {
            $this->holding = false;
            $stream = $this->held;
            $this->held = null;

            if ($stream === null) {
                return;
            }

            $stream->push(new StartEvent(self::message('held')));
            $stream->push(new DoneEvent(StopReason::Stop, self::message('held')));
            $stream->end();
        };
    }

    // ---- signing in ------------------------------------------------------------------

    private const string DOWN = "\e[B";

    public function testSlashLoginListsEveryProvider(): void
    {
        $this->start(auth: Auth::inMemory());

        $this->type('/login');
        $this->type(self::ENTER);

        $screen = $this->screen();

        // Three flows, none greyed and none carrying a label. There were four until Gemini CLI
        // went the way upstream's did; the list is `Oauth\Provider`'s and shrank with it.
        $this->assertStringContainsString('Anthropic (Claude Pro/Max)', $screen);
        $this->assertStringContainsString('GitHub Copilot', $screen);
        $this->assertStringContainsString('Antigravity', $screen);
        $this->assertStringNotContainsString('Google Cloud Code Assist', $screen);
        $this->assertStringNotContainsString('not ported yet', $screen);
    }

    public function testChoosingAFlowWithNoClientCredentialsSaysWhatIsMissing(): void
    {
        $this->start(auth: Auth::inMemory());

        $this->type('/login');
        $this->type(self::ENTER);
        // Down to the last one, Antigravity, whose client id and secret pig does not ship and
        // which are not set here. Two steps rather than three since Gemini CLI left the list.
        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        // Named, and named before a browser is opened. Upstream ignores a missing client here,
        // which reads as the list being broken.
        $this->assertStringContainsString('ANTIGRAVITY_CLIENT_ID', $screen);
        $this->assertStringNotContainsString('Open this and approve it', $screen);
    }

    public function testSlashLogoutWithNothingSignedInSaysSo(): void
    {
        $this->start(auth: Auth::inMemory());

        $this->type('/logout');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Nothing is signed in', $this->screen());
    }

    public function testSlashLogoutForgetsTheSignIn(): void
    {
        $auth = Auth::inMemory();
        $auth->setCredentials(Provider::Anthropic, new Credentials('r', 'sk-ant-oat-x', 0));

        $this->start(auth: $auth);

        $this->type('/logout');
        $this->type(self::ENTER);
        $this->type(self::ENTER);
        $this->settle();

        $this->assertFalse($auth->has('anthropic'));
        $this->assertStringContainsString('Forgot the Anthropic', $this->screen());
    }

    public function testSlashLogoutOnlyOffersWhatIsActuallySignedIn(): void
    {
        $auth = Auth::inMemory();
        $auth->setCredentials(Provider::Anthropic, new Credentials('r', 'a', 0));

        $this->start(auth: $auth);

        $this->type('/logout');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('Anthropic (Claude Pro/Max)', $screen);
        // The other three have nothing to forget, so offering them would be three ways of
        // doing nothing.
        $this->assertStringNotContainsString('GitHub Copilot', $screen);
    }

    public function testWithNowhereToKeepASignInItSaysSoRatherThanOpeningAList(): void
    {
        $this->start();

        $this->type('/login');
        $this->type(self::ENTER);

        $this->assertStringContainsString('nowhere to keep a sign-in', $this->screen());
    }

    // ---- the easter egg --------------------------------------------------------------

    public function testTheEasterEggIsReachableButNotAdvertised(): void
    {
        $this->start();

        $this->type('/help');
        $this->type(self::ENTER);

        // Known and not listed, like `quit`: something you have to already know about is the
        // whole idea, and upstream leaves it out of its own command list too.
        $this->assertStringNotContainsString('arminsayshi', $this->screen());

        $this->type('/arminsayshi');
        $this->type(self::ENTER);
        $this->settle();

        $screen = $this->screen();

        $this->assertStringContainsString('ARMIN SAYS HI', $screen);
        $this->assertStringNotContainsString('No command called', $screen);
    }

    // ---- /changelog ------------------------------------------------------------------

    public function testSlashChangelogShowsPigsOwnFileAndNotTheInjectedNote(): void
    {
        $this->start();

        $this->type('/changelog');
        $this->type(self::ENTER);

        $screen = $this->screen();

        // **This used to assert `No changelog entries found.` and passed because pig had no
        // `CHANGELOG.md`.** The constructor's changelog is the upgrade note only; `/changelog`
        // calls `Changelog::parse()`, which reads the file at the root of the repository — so for
        // as long as that file was missing, the one test of this command was pinning the absence
        // of a file rather than the command. The empty answer has its case in `ChangelogTest`,
        // against a path that is not there, which is where a question about the file belongs.
        // Asserted against what the parser found rather than against a number typed here: the
        // version this build *is* comes from the git tag, so in a checkout it is not a release and
        // has nothing to do with which entries the file holds.
        $entries = Changelog::parse();

        $this->assertNotSame([], $entries, 'there is a CHANGELOG.md to show');
        $this->assertStringContainsString("What's New", $screen);
        $this->assertStringContainsString($entries[0]->version, $screen);
        $this->assertStringNotContainsString('No changelog entries found.', $screen);
    }

    public function testAnUpgradeNoteIsDrawnUnderTheConversationItIsAbout(): void
    {
        $this->start(changelog: "## 0.2.0\n\n- Something changed.");

        $screen = $this->screen();

        $this->assertStringContainsString("What's New", $screen);
        $this->assertStringContainsString('Something changed.', $screen);
    }

    public function testNothingIsDrawnWhenThereIsNoUpgradeNote(): void
    {
        $this->start();

        // The common case by far, and a title with nothing under it would be worse than
        // nothing at all.
        $this->assertStringNotContainsString("What's New", $this->screen());
    }

    // ---- there is a newer pig --------------------------------------------------------

    public function testTheUpdateNoticeNamesBothVersionsAndTheCommandToRun(): void
    {
        $this->start();
        $this->mode->sayNewVersion('9.9.9');

        $screen = $this->screen();

        // All three facts, because each of them is the reason one of the others is worth
        // reading: which version is out, which one this is, and the one line that closes the
        // gap. A block naming two of the three is a block that sends somebody to a search
        // engine.
        $this->assertStringContainsString('Update Available', $screen);
        $this->assertStringContainsString('9.9.9', $screen);
        $this->assertStringContainsString(UpdateCheck::COMMAND, $screen);
    }

    public function testTheVersionItSaysYouHaveIsTheOneTheBannerDrew(): void
    {
        $this->start();

        // One fact, one source. `sayNewVersion()` read `Version::current()` for a batch, which
        // is the manifest rather than the string this session was started as — so the banner
        // said one number and the notice said another three lines below it, about the very
        // comparison the block exists to make.
        $this->assertStringContainsString('pig v0.0.0', $this->screen());

        $this->mode->sayNewVersion('9.9.9');

        $this->assertStringContainsString('New version 9.9.9 is available. Run ' . UpdateCheck::COMMAND, $this->screen());
        $this->assertStringContainsString('https://pigagent.dev/changelog', $this->screen());
    }

    public function testNothingIsSaidAboutAVersionUntilSomethingSaysThereIsOne(): void
    {
        $this->start();

        // The answer arrives from a spawned check that is null nearly every time, so a start
        // that drew this block by itself would be a warning every morning.
        $this->assertStringNotContainsString('Update Available', $this->screen());
    }

    // ---- /settings -------------------------------------------------------------------

    private const string ESCAPE = "\e";

    private function openSettings(): void
    {
        $this->type('/settings');
        $this->type(self::ENTER);
    }

    public function testSlashSettingsShowsWhatEachOneIsSetTo(): void
    {
        $this->start();
        $this->openSettings();

        $screen = $this->screen();

        $this->assertStringContainsString('Theme', $screen);
        $this->assertStringContainsString('dark', $screen);
        $this->assertStringContainsString('Auto-compact', $screen);
        $this->assertStringContainsString('Auto-retry', $screen);
        $this->assertStringContainsString('esc when done', $screen);
    }

    public function testASettingChangedFromTheScreenIsAppliedAndRemembered(): void
    {
        $this->start();
        $this->openSettings();

        // Theme is the first row.
        $this->type(self::ENTER);

        $this->assertSame('light', $this->settings->theme());
    }

    public function testTurningOffAutoCompactIsRemembered(): void
    {
        $this->start();
        $this->openSettings();

        // Down past Thinking blocks, Pictures and Queued messages to Auto-compact. No thinking
        // row: the test model does not reason, which is the case the row is left out for.
        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::ENTER);

        $this->assertFalse($this->settings->compactionEnabled());
        $this->assertTrue($this->settings->retryEnabled(), 'and not the row below it');
    }

    public function testHidingThinkingFromTheScreenReachesTheTranscriptToo(): void
    {
        $this->start();
        $this->openSettings();

        $this->type(self::DOWN);
        $this->type(self::ENTER);

        // The reason ctrl+t and this row go through the same method: writing the setting
        // without telling the components already on screen is a toggle that half works.
        $this->assertTrue($this->settings->hideThinking());
    }

    public function testTurningPicturesOffIsRememberedAndReachesTheTranscript(): void
    {
        $this->start();
        $this->openSettings();

        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::ENTER);

        $this->assertFalse($this->settings->showImages());
    }

    public function testChangingHowQueuedMessagesAreHandedOverIsRemembered(): void
    {
        $this->start();
        $this->openSettings();

        // Theme, Thinking blocks, Pictures, then this one.
        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::DOWN);
        $this->type(self::ENTER);

        // Both halves, which is the whole point of the pair: the agent is told so this run
        // behaves, and the file is written so the next one does.
        $this->assertSame(QueueMode::All, $this->session->queueMode());
        $this->assertSame(QueueMode::All, $this->settings->queueMode());
    }

    public function testQueueModeChosenLastTimeIsWhatTheAgentStartsOn(): void
    {
        $this->start(settings: Settings::inMemory(['queueMode' => 'all']));

        // Read out of the settings and into `AgentOptions` at construction — a row that only
        // took effect for the session it was changed in would be a setting in name only.
        $this->assertSame(QueueMode::All, $this->session->queueMode());
    }

    public function testAModelThatCannotThinkIsNotOfferedAThinkingRow(): void
    {
        $this->start();

        $this->openSettings();
        $this->assertStringNotContainsString('Thinking  ', $this->screen(), 'the row, not the blocks one');

        $this->type(self::ESCAPE);
    }

    public function testAThinkingModelGetsARowThatOpensTheLevels(): void
    {
        $this->start(reasoning: true);
        $this->openSettings();

        $this->type(self::DOWN);
        $this->type(self::ENTER);

        $screen = $this->screen();

        // A submenu rather than a cycle: six levels, each of which needs saying.
        $this->assertStringContainsString('Moderate reasoning', $screen);
        $this->assertStringContainsString('Esc to go back', $screen);
    }

    public function testChoosingALevelSetsItOnTheSessionAndRemembersIt(): void
    {
        $this->start(reasoning: true);
        $this->openSettings();

        $this->type(self::DOWN);
        $this->type(self::ENTER);
        // `off` is selected, being the level the session starts on; one down is `minimal`.
        $this->type(self::DOWN);
        $this->type(self::ENTER);

        $this->assertSame(ThinkingLevel::Minimal, $this->session->thinkingLevel());
        $this->assertSame(ThinkingLevel::Minimal, $this->settings->defaultThinkingLevel());
        $this->assertStringContainsString('minimal', $this->screen(), 'and the row says so');
    }

    public function testATurnEndingDoesNotEraseAnOpenScreenFromUnderTheCursor(): void
    {
        $this->start(['done']);
        $held = $this->holdTheAgent();

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();

        // Opened while the agent is working, which `/settings` allows on purpose — the two
        // pickers that refuse are the ones that would change the conversation underneath it.
        $this->openSettings();
        $this->settle();
        $this->assertStringContainsString('esc when done', $this->screen());

        $held();
        $this->settle();

        // The bug: `onEnd()` cleared the container the picker was in, so the screen went
        // away **with the focus still on it** — and the next keystroke went to an invisible
        // list. Somebody typing what they thought was a message was changing settings, one
        // row at a time, with nothing on screen to say so.
        $this->assertStringContainsString('esc when done', $this->screen(), 'still on screen');
        $this->assertStringContainsString('held', $this->screen(), 'and the answer arrived too');
    }

    public function testAWorkingLoaderAndAnOpenScreenBothFitOnTheScreen(): void
    {
        $this->start(['done']);
        $held = $this->holdTheAgent();

        $this->type('hello');
        $this->type(self::ENTER);
        $this->settle();
        $this->openSettings();
        $this->settle();

        $screen = $this->screen();

        // Which is the honest answer: both are true at once, so both are drawn. Sharing one
        // container meant whichever wrote last was the only one you could see.
        $this->assertStringContainsString('Working...', $screen);
        $this->assertStringContainsString('Auto-retry', $screen);

        $held();
        $this->settle();
    }

    public function testEscapeClosesTheScreenAndGivesTheEditorBackTheKeys(): void
    {
        $this->start();
        $this->openSettings();

        $this->type(self::ESCAPE);

        $this->assertStringNotContainsString('esc when done', $this->screen());

        // Which is the part worth asserting: a screen that closes without moving the focus
        // leaves a terminal where typing does nothing.
        $this->type('hello');
        $this->assertStringContainsString('hello', $this->screen());
    }

    // ---- a throw out of a loop callback ---------------------------------------------------

    public function testAThrowFromInsideTheLoopIsDrawnRatherThanEndingTheSession(): void
    {
        // The other end of `Loop::setErrorHandler()`, and the reason it exists: five entries in
        // the traps begin "runs inside the loop's own input callback, so there is nothing above
        // it to catch", and each one is a small thing that took the whole session. `Loop`
        // rethrows unless an application says otherwise; `start()` is what says otherwise.
        $this->start();

        Loop::get()->defer(static fn () => throw new RuntimeException('a component could not draw'));

        self::turnTheLoop(5);

        $this->assertStringContainsString('Error: a component could not draw', $this->screen());

        // And the session is still usable, which is the whole point of not crashing.
        $this->type('h');
        $this->type('i');
        $this->assertStringContainsString('hi', $this->screen());
    }

    public function testTheSameFailureEveryTickSaysSoOnceAndNotEveryTime(): void
    {
        // A `render()` that throws throws again next tick, so reporting every one fills the
        // transcript with the same line and scrolls away the thing it is trying to say — the
        // same rule `ToolExecutionComponent`'s renderer fallback follows per call.
        $this->start();

        foreach (range(1, 4) as $ignored) {
            Loop::get()->defer(static fn () => throw new RuntimeException('the same thing twice'));
        }

        self::turnTheLoop(5);

        $this->assertSame(1, substr_count($this->screen(), 'the same thing twice'));
    }
}
