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
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\InteractiveMode;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Session\AgentSession;
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

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->held = null;
        $this->holding = false;
        $this->terminal = new FakeTerminal(80, 24);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->mode->stop();
    }

    private function startWithContext(): void
    {
        $this->start(context: [new ContextFile('/somewhere/AGENTS.md', 'be careful')]);
    }

    /**
     * @param list<string>      $answers one per model call
     * @param list<ContextFile> $context
     */
    private function start(array $answers = [], bool $reasoning = false, array $context = []): void
    {
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

        $this->session = new AgentSession($agent);
        $this->mode = new InteractiveMode(
            $this->session,
            Palette::dark(true),
            sys_get_temp_dir(),
            '0.0.0',
            'dark',
            $this->terminal,
            $context,
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

        $this->assertStringContainsString('No command called /nonsense', $this->screen());
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
