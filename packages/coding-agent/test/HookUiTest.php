<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Hooks\Events\ToolCallEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
use Pig\CodingAgent\Hooks\NoUi;
use Pig\CodingAgent\Interactive\CustomEditor;
use Pig\CodingAgent\Interactive\FooterComponent;
use Pig\CodingAgent\Interactive\TerminalUi;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Container;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\Tui;
use Pig\Tui\TuiError;

/**
 * Asking the person something from inside a turn.
 *
 * The interesting property is that it blocks: a handler calls `confirm()` and gets a
 * `bool` back as if nothing had happened, while underneath the fiber suspended and the
 * loop kept serving the terminal. Every test here drives that through a fake terminal —
 * spawn something that asks, type the answer, check what it was told.
 */
final class HookUiTest extends TestCase
{
    private FakeTerminal $terminal;

    private Tui $tui;

    private Container $chat;

    private Container $status;

    private CustomEditor $editor;

    private TerminalUi $ui;

    private Palette $palette;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->palette = Palette::dark(true);
        $this->terminal = new FakeTerminal(80, 24);
        $this->tui = new Tui($this->terminal);
        $this->chat = new Container();
        $this->status = new Container();
        $this->editor = new CustomEditor(new Editor($this->palette->editorTheme()));

        $this->tui->addChild($this->chat);
        $this->tui->addChild($this->status);
        $this->tui->addChild($this->editor);
        $this->tui->setFocus($this->editor);

        $this->ui = new TerminalUi(
            $this->tui,
            $this->chat,
            $this->status,
            $this->editor,
            // A footer needs a session, and nothing here draws one: the keyed status is
            // what the UI writes into it, and `FooterTest` covers how that renders.
            new FooterComponent(
                new AgentSession(new Agent(new AgentOptions()), sys_get_temp_dir()),
                $this->palette,
                sys_get_temp_dir(),
            ),
            fn (): Palette => $this->palette,
        );

        // Started, because until it is the terminal does not route what is typed — and
        // what is typed is the whole subject here.
        $this->tui->start();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tui->stop();
    }

    private function type(string $data): void
    {
        $this->terminal->type($data);
    }

    private function screen(): string
    {
        return implode("\n", array_map(Ansi::strip(...), $this->tui->render(80)));
    }

    /** Turn the loop over until it has nothing left, so a spawned fiber can finish. */
    private function settle(): void
    {
        for ($tick = 0; $tick < 50 && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    // ---- select ------------------------------------------------------------------------

    public function testAChoiceComesBackAsTheStringThatWasOffered(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->select('Which one?', ['first', 'second']);
        });

        $this->settle();

        // The fiber is parked on the list, which is on screen with the keys.
        $this->assertStringContainsString('Which one?', $this->screen());
        $this->assertSame('not asked yet', $answer);

        $this->type("\x1b[B");
        $this->type("\r");
        $this->settle();

        $this->assertSame('second', $answer);
    }

    public function testEscapeAnswersWithNothing(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->select('Which one?', ['first', 'second']);
        });

        $this->settle();
        $this->type("\x1b");
        $this->settle();

        $this->assertNull($answer);
    }

    public function testTheDialogIsTakenDownAndTheKeysGoBackToThePrompt(): void
    {
        Async::spawn(function (): void {
            $this->ui->select('Which one?', ['first']);
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertStringNotContainsString('Which one?', $this->screen());

        // Typing reaches the editor again rather than a list nobody can see.
        $this->type('hello');
        $this->assertSame('hello', $this->editor->text());
    }

    public function testAnEmptyListIsNotWorthOpening(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->select('Which one?', []);
        });

        $this->settle();

        $this->assertNull($answer);
        $this->assertStringNotContainsString('Which one?', $this->screen());
    }

    // ---- confirm -----------------------------------------------------------------------

    /** The case the whole thing exists for: a guard that can ask instead of guessing. */
    public function testConfirmingRunsTheAnswerBack(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->confirm('Run this?', 'rm -rf build');
        });

        $this->settle();
        $screen = $this->screen();

        $this->assertStringContainsString('Run this?', $screen);
        $this->assertStringContainsString('rm -rf build', $screen);

        // No is first, so saying yes takes a keystroke — which is the right way round for
        // a question asked about something destructive.
        $this->type("\x1b[B");
        $this->type("\r");
        $this->settle();

        $this->assertTrue($answer);
    }

    public function testTheDefaultChoiceIsNo(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->confirm('Run this?', 'rm -rf build');
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertFalse($answer);
    }

    public function testEscapingAConfirmIsANo(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->confirm('Run this?', 'rm -rf build');
        });

        $this->settle();
        $this->type("\x1b");
        $this->settle();

        $this->assertFalse($answer);
    }

    public function testAConfirmWithNoMessageJustAsksTheTitle(): void
    {
        Async::spawn(function (): void {
            $this->ui->confirm('Carry on?', '');
        });

        $this->settle();

        $this->assertStringContainsString('Carry on?', $this->screen());
        $this->assertStringNotContainsString('—', $this->screen());
    }

    // ---- input -------------------------------------------------------------------------

    public function testTypedTextComesBack(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->input('Ticket number?');
        });

        $this->settle();

        $this->assertStringContainsString('Ticket number?', $this->screen());

        $this->type('PIG-7');
        $this->type("\r");
        $this->settle();

        $this->assertSame('PIG-7', $answer);
    }

    public function testAnEmptyLineIsAnAnswerAndNotACancellation(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->input('Anything to add?');
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertSame('', $answer);
    }

    /**
     * The reason `Input` grew a cancel handler.
     *
     * Without it there is no way out of a prompt opened in front of a suspended fiber,
     * and the session has to be killed.
     */
    public function testEscapingAnInputAnswersWithNothing(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->input('Ticket number?');
        });

        $this->settle();
        $this->type("\x1b");
        $this->settle();

        $this->assertNull($answer);
        $this->assertStringNotContainsString('Ticket number?', $this->screen());
    }

    public function testAPlaceholderIsShownWithTheTitle(): void
    {
        Async::spawn(function (): void {
            $this->ui->input('Ticket number?', 'PIG-123');
        });

        $this->settle();

        $this->assertStringContainsString('Ticket number? (PIG-123)', $this->screen());
    }

    // ---- editor ------------------------------------------------------------------------

    public function testTheMultiLineEditorComesBackWithWhatWasTyped(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->editor('What should it say?');
        });

        $this->settle();

        $this->assertStringContainsString('What should it say?', $this->screen());

        $this->type('a line');
        $this->type("\r");
        $this->settle();

        $this->assertSame('a line', $answer);
    }

    public function testItIsPrefilledAndTheTextCanBeAddedTo(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->editor('Edit this', 'the first part');
        });

        $this->settle();

        $this->assertStringContainsString('the first part', $this->screen());

        $this->type(' and the second');
        $this->type("\r");
        $this->settle();

        $this->assertSame('the first part and the second', $answer);
    }

    /** Enter finishes, so a second line needs the other key — and has to actually work. */
    public function testShiftEnterAddsALineRatherThanFinishing(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->editor('Edit this');
        });

        $this->settle();
        $this->type('first');
        $this->type("\x1b[13;2u");
        $this->type('second');
        $this->type("\r");
        $this->settle();

        $this->assertSame("first\nsecond", $answer);
    }

    public function testEscapingTheEditorAnswersWithNothing(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->editor('Edit this', 'something');
        });

        $this->settle();
        $this->type("\x1b");
        $this->settle();

        $this->assertNull($answer);
        $this->assertStringNotContainsString('Edit this', $this->screen());
    }

    /**
     * Ctrl+G inside the dialog is the same hand-off as Ctrl+G at the prompt.
     *
     * The real one stops the TUI and runs `$VISUAL`, which a test cannot do; what is
     * checked is the seam — the text goes out, what comes back is what is in the editor,
     * and the dialog is still open and still focused afterwards.
     */
    public function testCtrlGHandsTheTextOutAndPutsBackWhatComesIn(): void
    {
        $seen = null;
        $ui = $this->withExternalEditor(function (string $text) use (&$seen): ?string {
            $seen = $text;

            return 'what the editor saved';
        });

        $answer = null;

        Async::spawn(function () use ($ui, &$answer): void {
            $answer = $ui->editor('Edit this', 'before');
        });

        $this->settle();
        $this->type("\x07");
        $this->settle();

        $this->assertSame('before', $seen);
        $this->assertStringContainsString('what the editor saved', $this->screen());

        // Still the dialog's question, so Enter answers it rather than sending a prompt.
        $this->type("\r");
        $this->settle();

        $this->assertSame('what the editor saved', $answer);
    }

    public function testAnEditorThatCancelledLeavesTheTextAlone(): void
    {
        $ui = $this->withExternalEditor(static fn (): ?string => null);
        $answer = null;

        Async::spawn(function () use ($ui, &$answer): void {
            $answer = $ui->editor('Edit this', 'before');
        });

        $this->settle();
        $this->type("\x07");
        $this->type("\r");
        $this->settle();

        $this->assertSame('before', $answer);
    }

    public function testWithNoHandOffCtrlGDoesNothingRatherThanBreaking(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->editor('Edit this', 'before');
        });

        $this->settle();
        $this->type("\x07");
        $this->type("\r");
        $this->settle();

        $this->assertSame('before', $answer);
    }

    // ---- custom ------------------------------------------------------------------------

    public function testACustomDialogGetsTheScreenAndAnswersThroughDone(): void
    {
        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->custom(function (Tui $tui, Palette $palette, callable $done) {
                $list = new SelectList(
                    [new SelectItem('7', 'seven'), new SelectItem('8', 'eight')],
                    4,
                    $palette->selectListTheme(),
                );

                $list->setSelectHandler(static fn (SelectItem $item) => $done((int) $item->value));
                $list->setCancelHandler(static fn () => $done(null));

                return $list;
            });
        });

        $this->settle();

        $this->assertStringContainsString('seven', $this->screen());

        $this->type("\x1b[B");
        $this->type("\r");
        $this->settle();

        $this->assertSame(8, $answer);
    }

    public function testACustomDialogIsTakenDownWhenItIsDone(): void
    {
        Async::spawn(function (): void {
            $this->ui->custom(function (Tui $tui, Palette $palette, callable $done) {
                $list = new SelectList([new SelectItem('a', 'only')], 2, $palette->selectListTheme());
                $list->setSelectHandler(static fn () => $done('a'));

                return $list;
            });
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertStringNotContainsString('only', $this->screen());

        $this->type('back to the prompt');
        $this->assertSame('back to the prompt', $this->editor->text());
    }

    /** Two handlers firing on one key is a hook's mistake, not a reason to break the turn. */
    public function testCallingDoneTwiceResolvesOnce(): void
    {
        $answer = 'not asked yet';

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->custom(function (Tui $tui, Palette $palette, callable $done) {
                $list = new SelectList([new SelectItem('a', 'only')], 2, $palette->selectListTheme());
                $list->setSelectHandler(static function () use ($done): void {
                    $done('first');
                    $done('second');
                });

                return $list;
            });
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertSame('first', $answer);
    }

    public function testAFactoryThatReturnsSomethingElseIsRefused(): void
    {
        $thrown = null;

        Async::spawn(function () use (&$thrown): void {
            try {
                $this->ui->custom(static fn () => 'not a component');
            } catch (TuiError $error) {
                $thrown = $error;
            }
        });

        $this->settle();

        $this->assertStringContainsString('must return a component, got string', (string) $thrown?->getMessage());
    }

    /** And the refusal must not leave the UI thinking a dialog is still open. */
    public function testARefusedFactoryDoesNotWedgeTheNextQuestion(): void
    {
        Async::spawn(function (): void {
            try {
                $this->ui->custom(static fn () => 'not a component');
            } catch (TuiError) {
                // The point is what happens next.
            }
        });

        $this->settle();

        $answer = null;

        Async::spawn(function () use (&$answer): void {
            $answer = $this->ui->confirm('Still working?', '');
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertFalse($answer);
    }

    // ---- one at a time -----------------------------------------------------------------

    /**
     * A second dialog would take focus from the first, leaving the first fiber waiting on
     * a component nobody can reach — a session that has to be killed.
     */
    public function testASecondDialogIsRefusedRatherThanStacked(): void
    {
        $second = 'not asked yet';

        Async::spawn(function (): void {
            $this->ui->confirm('First?', '');
        });

        $this->settle();

        Async::spawn(function () use (&$second): void {
            $second = $this->ui->select('Second?', ['a', 'b']);
        });

        $this->settle();

        $this->assertNull($second);
        $this->assertStringContainsString('First?', $this->screen());
        $this->assertStringNotContainsString('Second?', $this->screen());
    }

    public function testASecondConfirmIsRefusedWithItsSafeAnswer(): void
    {
        $second = null;

        Async::spawn(function (): void {
            $this->ui->input('First?');
        });

        $this->settle();

        Async::spawn(function () use (&$second): void {
            $second = $this->ui->confirm('Second?', '');
        });

        $this->settle();

        $this->assertFalse($second);
    }

    public function testAnotherDialogCanBeOpenedOnceTheFirstIsAnswered(): void
    {
        $answers = [];

        Async::spawn(function () use (&$answers): void {
            $answers[] = $this->ui->confirm('First?', '');
            $answers[] = $this->ui->confirm('Second?', '');
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertStringContainsString('Second?', $this->screen());

        $this->type("\r");
        $this->settle();

        $this->assertSame([false, false], $answers);
    }

    // ---- the rest ----------------------------------------------------------------------

    public function testNotifyPutsALineInTheTranscript(): void
    {
        $this->ui->notify('the deploy finished');

        $this->assertStringContainsString('the deploy finished', $this->screen());
    }

    public function testAWarningAndAnErrorAreLabelled(): void
    {
        $this->ui->notify('slow', 'warning');
        $this->ui->notify('broken', 'error');
        $screen = $this->screen();

        $this->assertStringContainsString('Warning: slow', $screen);
        $this->assertStringContainsString('Error: broken', $screen);
    }

    public function testTheEditorCanBeReadAndWritten(): void
    {
        $this->ui->setEditorText('a prompt someone else wrote');

        $this->assertSame('a prompt someone else wrote', $this->ui->getEditorText());
        $this->assertSame('a prompt someone else wrote', $this->editor->text());
    }

    public function testThePaletteIsTheOneOnNow(): void
    {
        $this->assertSame($this->palette, $this->ui->palette());

        $this->palette = Palette::named('light', true);

        $this->assertSame($this->palette, $this->ui->palette());
    }

    // ---- no terminal -------------------------------------------------------------------

    public function testWithNoUiNothingIsChosenAndNothingIsConfirmed(): void
    {
        $ui = new NoUi();

        $this->assertNull($ui->select('Which one?', ['a']));
        $this->assertNull($ui->input('Anything?'));
        $this->assertSame('', $ui->getEditorText());
        $this->assertInstanceOf(Palette::class, $ui->palette());
    }

    /** A guard nobody can answer has not been answered yes. */
    public function testWithNoUiConfirmIsNo(): void
    {
        $this->assertFalse((new NoUi())->confirm('Run this?', 'rm -rf /'));
    }

    public function testNoUiIsAHookUi(): void
    {
        $this->assertInstanceOf(HookUi::class, new NoUi());
        $this->assertInstanceOf(HookUi::class, $this->ui);
    }

    // ---- the whole chain ---------------------------------------------------------------

    /**
     * The thing this was built for: a guard that asks.
     *
     * Tool call → `tool_call` hook → `confirm()` → the fiber parks on a dialog → a
     * keystroke → the answer → the tool runs or does not. Every layer is the real one; only
     * the terminal is fake.
     */
    public function testAGuardCanAskBeforeLettingAToolRun(): void
    {
        $tool = new RecordingTool();
        $wrapped = $this->guarded($tool);
        $result = null;

        Async::spawn(function () use ($wrapped, &$result): void {
            $result = $wrapped->execute('1', ['path' => 'build']);
        });

        $this->settle();

        // Parked mid-call: nothing has run and the question is on screen.
        $this->assertSame(0, $tool->calls);
        $this->assertStringContainsString('Let read run?', $this->screen());

        $this->type("\x1b[B");
        $this->type("\r");
        $this->settle();

        $this->assertSame(1, $tool->calls);
        $this->assertSame('read build', $result?->content[0]->text);
    }

    public function testSayingNoBlocksTheCallWithAReasonTheModelCanRead(): void
    {
        $tool = new RecordingTool();
        $wrapped = $this->guarded($tool);
        $error = null;

        Async::spawn(function () use ($wrapped, &$error): void {
            try {
                $wrapped->execute('1', ['path' => 'build']);
            } catch (AgentError $thrown) {
                $error = $thrown;
            }
        });

        $this->settle();
        $this->type("\r");
        $this->settle();

        $this->assertSame(0, $tool->calls);
        $this->assertSame('You said no.', $error?->getMessage());
    }

    /** Escape is a no, so walking away from the question does not wave the tool through. */
    public function testEscapingTheQuestionBlocksTheCall(): void
    {
        $tool = new RecordingTool();
        $wrapped = $this->guarded($tool);

        Async::spawn(function () use ($wrapped): void {
            try {
                $wrapped->execute('1', ['path' => 'build']);
            } catch (AgentError) {
                // The point is that it threw.
            }
        });

        $this->settle();
        $this->type("\x1b");
        $this->settle();

        $this->assertSame(0, $tool->calls);
    }

    /** A second UI over the same screen, with a stand-in for the `$VISUAL` hand-off. */
    private function withExternalEditor(\Closure $externalEditor): TerminalUi
    {
        return new TerminalUi(
            $this->tui,
            $this->chat,
            $this->status,
            $this->editor,
            new FooterComponent(
                new AgentSession(new Agent(new AgentOptions()), sys_get_temp_dir()),
                $this->palette,
                sys_get_temp_dir(),
            ),
            fn (): Palette => $this->palette,
            $externalEditor,
        );
    }

    /** A hook wrapped around $tool that asks the person before every call. */
    private function guarded(RecordingTool $tool): HookedTool
    {
        $api = new HookApi('.', 'ask.php');
        $api->on('tool_call', static function (ToolCallEvent $event, HookContext $ctx) {
            return $ctx->ui->confirm("Let {$event->toolName} run?", (string) ($event->input['path'] ?? ''))
                ? null
                : new ToolCallEventResult(block: true, reason: 'You said no.');
        });

        $runner = new HookRunner([new LoadedHook('ask.php', 'ask.php', $api)], '.');
        $runner->initialize(getModel: static fn () => null, ui: $this->ui);

        return new HookedTool($tool, $runner);
    }
}
