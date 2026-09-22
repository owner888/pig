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
use Pig\CodingAgent\Interactive\InteractiveMode;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;
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
    ): void {
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

        $saved = match (true) {
            $resume !== null => SessionManager::open($resume),
            $store => SessionManager::create($this->cwd),
            default => null,
        };

        $this->session = new AgentSession($agent, $this->cwd, $saved);

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

        $this->type('/model gpt-5.2');
        $this->type(self::ENTER);

        $this->assertStringContainsString('Error: No model matches "gpt-5.2"', $this->screen());
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
}
