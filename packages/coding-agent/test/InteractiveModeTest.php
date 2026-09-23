<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Interactive\InteractiveMode;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\ToolSet;
use Pig\Tui\Ansi;
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
    private const string ESC = "\e";

    private const string ENTER = "\r";

    private FakeTerminal $terminal;

    private AgentSession $session;

    private InteractiveMode $mode;

    /** @var list<string> */
    private array $answers = [];

    /** Set while a test is holding the agent mid-run; the stream it is waiting on. */
    private ?AssistantMessageEventStream $held = null;

    private bool $holding = false;

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
        $this->terminal = new FakeTerminal(80, 24);
        $this->cwd = sys_get_temp_dir() . '/pig-interactive-' . bin2hex(random_bytes(4));
        $this->home = $this->cwd . '-home';
        mkdir($this->cwd, 0o755, true);
        putenv('PIG_HOME=' . $this->home);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->mode->stop();
        putenv('PIG_HOME');
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
     * @param list<string>      $answers one per model call
     * @param list<ContextFile> $context
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
        // loaded. Without it `/tools` would be answering about an agent that has none,
        // which is not the agent anyone runs.
        $agent->setTools([...ToolSet::create($this->cwd, ['read']), ...($customTools?->agentTools() ?? [])]);

        $saved = match (true) {
            $resume !== null => SessionManager::open($resume),
            $store => SessionManager::create($this->cwd),
            default => null,
        };

        $this->session = new AgentSession($agent, $this->cwd, $saved, null, $hooks);

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
        );

        $this->mode->start();
    }

    private function provider(Model $model, Context $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $text = array_shift($this->answers) ?? throw new RuntimeException('out of scripted answers');
        $stream = new AssistantMessageEventStream();

        // Held open: the two tests about typing mid-run need the agent to still be
        // working when the next key arrives, which an instant answer never is.
        if ($this->held === null && $this->holding) {
            $this->held = $stream;
            $this->answers[] = $text;

            return $stream;
        }

        Async::spawn(static function () use ($stream, $text): void {
            $stream->push(new StartEvent(self::message($text)));
            $stream->push(new DoneEvent(StopReason::Stop, self::message($text)));
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

    /** Run the loop until it has nothing left to do, so a spawned prompt can finish. */
    private function settle(): void
    {
        for ($tick = 0; $tick < 50 && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    // ---- the first frame ------------------------------------------------------------

    public function testTheBannerIsThreeLinesUntilAskedForMore(): void
    {
        $this->start();
        $screen = $this->screen();

        // A column of thirteen keys was taller than most of the conversations it sat
        // above, so the list moved behind ctrl+o.
        $this->assertStringContainsString('pig v0.0.0', $screen);
        $this->assertStringContainsString('escape interrupt · ctrl+c/ctrl+d clear/exit', $screen);
        $this->assertStringContainsString('Press ctrl+o', $screen);
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

    public function testWhatWasLoadedIsListedUnderTheFullList(): void
    {
        $this->startWithContext();

        $this->assertStringNotContainsString('[Context]', $this->screen());

        $this->type("\x0f");
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

    public function testAnUnknownCommandSaysSoRatherThanBeingSent(): void
    {
        $this->start();

        $this->type('/nonsense');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Error: No command called /nonsense', $this->screen());
        $this->assertSame([], $this->session->messages());
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
        $this->assertStringContainsString('where you are', $screen);
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
        // Two rows down: past "where you are" and the answer, onto "and again".
        $this->type("\e[B");
        $this->type("\e[B");
        $this->type(self::ENTER);
        $this->settle();

        // "Summarise the branch you are leaving?" — No is the default, so Enter is no.
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Went back', $this->screen());
        $this->assertStringNotContainsString('second answer', $this->screen());
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
        $this->assertStringContainsString('Exported to ' . $path, $this->screen());
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

    public function testAnUnknownCommandIsStillUnknownWhenThereAreFileCommands(): void
    {
        $this->start(fileCommands: [new FileCommand('review', 'd', 'x', '(user)')]);

        $this->type('/nonsense');
        $this->type(self::ENTER);
        $this->settle();

        $this->assertStringContainsString('Error: No command called /nonsense', $this->screen());
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

    public function testLoadedSkillsAreListedUnderTheFullList(): void
    {
        $this->start(skills: [new Skill('tidy', 'tidy up a file', '/s/tidy/SKILL.md', '/s/tidy', 'user')]);

        $this->type("\x0f");
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

    public function testWithNoSkillsTheCommandSaysWhereToPutOne(): void
    {
        $this->start();

        $this->type('/skills');
        $this->type(self::ENTER);

        $this->assertStringContainsString('~/.pig/skills', $this->screen());
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

    public function testPickingFromTheListSwitches(): void
    {
        $this->start();

        $this->type('/model');
        $this->type(self::ENTER);
        $this->type(self::ENTER);

        // The first row of the list, whichever it is — what matters is that choosing
        // one actually changes the model and closes the picker.
        $this->assertSame('claude-3-5-haiku-20241022', $this->session->model()?->id);
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
        $this->type("\x0f");

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
    private function holdTheAgent(): Closure
    {
        $this->holding = true;

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

    public function testSlashLoginListsTheProvidersAndSaysWhichOnesAreNotPorted(): void
    {
        $this->start(auth: Auth::inMemory());

        $this->type('/login');
        $this->type(self::ENTER);

        $screen = $this->screen();

        $this->assertStringContainsString('Anthropic (Claude Pro/Max)', $screen);
        $this->assertStringContainsString('GitHub Copilot', $screen);
        // Greyed and labelled, rather than quietly absent: a list that hides what somebody
        // came looking for teaches nothing.
        $this->assertStringContainsString('not ported yet', $screen);
    }

    public function testChoosingSomethingNotPortedSaysWhyRatherThanDoingNothing(): void
    {
        $this->start(auth: Auth::inMemory());

        $this->type('/login');
        $this->type(self::ENTER);
        $this->type(self::DOWN);
        $this->type(self::ENTER);
        $this->settle();

        // Upstream ignores the key here, which reads as the list being broken.
        $this->assertStringContainsString('is not ported yet', $this->screen());
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

}
