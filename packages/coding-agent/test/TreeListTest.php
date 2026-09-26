<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Interactive\TreeList;
use Pig\CodingAgent\Session\CustomEntry;
use Pig\CodingAgent\Session\Label;
use Pig\CodingAgent\Session\ModelChange;
use Pig\CodingAgent\Theme\Palette;

/**
 * The tree `/tree` draws, and the keys that move around it.
 *
 * Built from node arrays rather than from a `SessionManager`, so a shape that takes six turns of a
 * real conversation to produce is three lines here — and `SessionManagerTest` is where the walker
 * that produces them is checked.
 */
final class TreeListTest extends TestCase
{
    private const string UP = "\e[A";
    private const string DOWN = "\e[B";
    private const string LEFT = "\e[D";
    private const string RIGHT = "\e[C";
    private const string ENTER = "\r";
    private const string ESCAPE = "\e";
    private const string CTRL_O = "\x0f";

    /** The kitty sequence: `o` with shift and ctrl, whose bits are encoded one higher. */
    private const string SHIFT_CTRL_O = "\e[111;6u";
    private const string BACKSPACE = "\x7f";

    /** @param list<array<string, mixed>> $children */
    private static function node(string $id, mixed $message, array $children = [], ?string $label = null): array
    {
        return ['id' => $id, 'message' => $message, 'label' => $label, 'children' => $children];
    }

    private static function said(string $text): UserMessage
    {
        return new UserMessage([new TextContent($text)]);
    }

    /** @param list<mixed> $content */
    private static function answered(array $content, StopReason $reason = StopReason::Stop, ?string $error = null): AssistantMessage
    {
        return new AssistantMessage($content, Api::AnthropicMessages, 'anthropic', 'm', new Usage(), $reason, $error);
    }

    /** @param list<array<string, mixed>> $tree */
    private static function list(array $tree, ?string $leaf, int $maxVisible = 20): TreeList
    {
        return new TreeList($tree, $leaf, $maxVisible, Palette::dark(false));
    }

    private static function plain(TreeList $list, int $width = 120): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $list->render($width)));
    }

    /**
     * hello
     * └─ answer
     *    ├─ the road taken      (holds the leaf)
     *    └─ the road not taken
     *
     * @return list<array<string, mixed>>
     */
    private static function forked(): array
    {
        return [self::node('a', self::said('hello'), [
            self::node('b', self::answered([new TextContent('answer')]), [
                self::node('c', self::said('the road not taken'), [
                    self::node('d', self::answered([new TextContent('down that way')])),
                ]),
                self::node('e', self::said('the road taken'), [
                    self::node('f', self::answered([new TextContent('down this way')])),
                ]),
            ]),
        ])];
    }

    // ---- what it draws -------------------------------------------------------------------------

    public function testAChainOfSingleRepliesStaysAtOneIndent(): void
    {
        // The rule that makes a long conversation readable: a hundred turns would otherwise be a
        // hundred columns deep.
        $tree = [self::node('a', self::said('one'), [
            self::node('b', self::answered([new TextContent('two')]), [
                self::node('c', self::said('three')),
            ]),
        ])];

        $screen = self::plain(self::list($tree, 'c'));

        $this->assertStringContainsString('user: one', $screen);
        $this->assertStringNotContainsString('├', $screen, 'nothing forked, so nothing is drawn');
        $this->assertStringNotContainsString('└', $screen);
    }

    public function testAForkIsDrawnWithConnectors(): void
    {
        $screen = self::plain(self::list(self::forked(), 'f'));

        $this->assertStringContainsString('├─', $screen);
        $this->assertStringContainsString('└─', $screen);
        $this->assertStringContainsString('the road taken', $screen);
        $this->assertStringContainsString('the road not taken', $screen, 'the branch that was left is here');
    }

    public function testTheBranchBeingTalkedOnComesFirstAtEveryFork(): void
    {
        $screen = self::plain(self::list(self::forked(), 'f'));
        $rows = array_values(array_filter(explode("\n", $screen), static fn (string $l): bool => str_contains($l, 'road')));

        // Not written order: the branch somebody is on is the one they are looking for, and putting
        // it second would bury it under one they abandoned.
        $this->assertStringContainsString('the road taken', $rows[0]);
        $this->assertStringContainsString('the road not taken', $rows[1]);
    }

    public function testThePathBeingTalkedOnIsMarked(): void
    {
        $screen = self::plain(self::list(self::forked(), 'f'));

        foreach (explode("\n", $screen) as $line) {
            if (str_contains($line, 'the road taken') || str_contains($line, 'down this way')) {
                $this->assertStringContainsString('•', $line, $line);
            }

            if (str_contains($line, 'the road not taken') || str_contains($line, 'down that way')) {
                $this->assertStringNotContainsString('•', $line, $line);
            }
        }

        // And all the way up to the root, not just the leaf.
        $this->assertMatchesRegularExpression('/• user: hello/', $screen);
    }

    public function testAGutterCarriesAForkDownPastTheRowsUnderIt(): void
    {
        $screen = self::plain(self::list(self::forked(), 'f'));
        $under = '';

        foreach (explode("\n", $screen) as $line) {
            if (str_contains($line, 'down this way')) {
                $under = $line;
            }
        }

        // The row under the first arm of a fork keeps the fork's `│`, or the second arm looks like
        // it belongs to something else.
        $this->assertStringContainsString('│', $under, $under);
    }

    public function testTheCursorStartsOnThePointBeingTalkedOn(): void
    {
        $this->assertSame('f', self::list(self::forked(), 'f')->current());
        $this->assertSame('c', self::list(self::forked(), 'c')->current());
    }

    public function testALabelIsShownBesideTheRow(): void
    {
        $tree = [self::node('a', self::said('hello'), [], 'before the refactor')];

        $this->assertStringContainsString('[before the refactor] user: hello', self::plain(self::list($tree, 'a')));
    }

    public function testACountAndTheFilterAreOnTheLastLine(): void
    {
        $screen = self::plain(self::list(self::forked(), 'f'));
        $lines = explode("\n", $screen);

        // Four, because the cursor starts on the point being talked on rather than at the top.
        $this->assertStringContainsString('(4/6)', $lines[count($lines) - 1]);
    }

    // ---- what each kind of row says ------------------------------------------------------------

    public function testAnAnswerThatOnlyCalledAToolIsHiddenUnlessItIsWhereYouAre(): void
    {
        $tree = [self::node('a', self::said('read it'), [
            self::node('b', self::answered([new ToolCall('call-1', 'read', ['path' => 'a.txt'])]), [
                self::node('c', new ToolResultMessage('call-1', 'read', [new TextContent('contents')])),
            ]),
        ])];

        $screen = self::plain(self::list($tree, 'c'));

        // Nothing to show, so it is not shown — and the tool result says which call it answers,
        // which is the useful half of a row that would otherwise read `[read]`.
        $this->assertStringNotContainsString('(no text)', $screen);
        $this->assertStringContainsString('[read: {"path":"a.txt"}]', $screen);
    }

    public function testThinkingIsNotWhatARowSays(): void
    {
        // Thinking plus a tool call is what most tool-calling turns look like on a provider that
        // returns its reasoning — so counting it as text put a row of the model talking to itself
        // between every question and its answer. It is not row material for the reason it is not
        // search material either: nobody read it, and it is not what the point *is*.
        $tree = [self::node('a', self::said('read it'), [
            self::node('b', self::answered([
                new ThinkingContent('the file is probably under src, let me look'),
                new ToolCall('call-1', 'read', ['path' => 'a.txt']),
            ]), [
                self::node('c', new ToolResultMessage('call-1', 'read', [new TextContent('contents')])),
            ]),
        ])];

        $list = self::list($tree, 'c');
        $screen = self::plain($list);

        $this->assertStringNotContainsString('probably under src', $screen);
        $this->assertStringNotContainsString('assistant:', $screen);
    }

    public function testASearchDoesNotMatchThinkingEither(): void
    {
        $tree = [self::node('a', self::said('read it'), [
            self::node('b', self::answered([
                new ThinkingContent('the file is probably under src'),
                new ToolCall('call-1', 'read', ['path' => 'a.txt']),
            ]), [
                self::node('c', new ToolResultMessage('call-1', 'read', [new TextContent('contents')])),
            ]),
        ])];

        $list = self::list($tree, 'c');

        foreach (str_split('probably') as $character) {
            $list->handleInput($character);
        }

        $this->assertSame(0, $list->count());
    }

    public function testAPictureWithNothingSaidAboutItStillHasARow(): void
    {
        // pig's own, and the reason `textOf` is not simply upstream's text-blocks-only: a
        // screenshot pasted with no words is a point somebody goes back to, and `user: ` with
        // nothing after it is a row that cannot be told from a rendering fault.
        $tree = [self::node('a', new UserMessage([new ImageContent('x', 'image/png')]))];

        $this->assertStringContainsString('user: [image]', self::plain(self::list($tree, 'a')));
    }

    public function testAClearedNameSaysSoRatherThanShowingAnEmptyBracket(): void
    {
        // Nothing is deleted from a session file, so clearing a name writes a `Label` with no
        // label. Its row is only visible under the `all` filter, which is where bookkeeping lives.
        $tree = [self::node('a', self::said('hello'), [
            self::node('b', new Label('a')),
        ])];

        $list = self::list($tree, 'b');
        $list->handleInput(self::CTRL_O);
        $list->handleInput(self::CTRL_O);
        $list->handleInput(self::CTRL_O);
        $list->handleInput(self::CTRL_O);

        $this->assertStringContainsString('[label: (cleared)]', self::plain($list));
    }

    public function testATurnThatFailedIsShownEvenWithNoText(): void
    {
        // "The turn that broke" is exactly the point somebody goes back to.
        $tree = [self::node('a', self::said('go on'), [
            self::node('b', self::answered([], StopReason::Error, 'the provider said 500'), [
                self::node('c', self::said('again')),
            ]),
        ])];

        $screen = self::plain(self::list($tree, 'c'));

        $this->assertStringContainsString('the provider said 500', $screen);
    }

    public function testAStoppedTurnSaysSo(): void
    {
        $tree = [self::node('a', self::answered([], StopReason::Aborted))];

        $this->assertStringContainsString('(stopped)', self::plain(self::list($tree, 'a')));
    }

    // ---- filters -------------------------------------------------------------------------------

    public function testTheDefaultViewHidesTheBookkeeping(): void
    {
        $tree = [self::node('a', new ModelChange('anthropic', 'claude-sonnet-4-5'), [
            self::node('b', self::said('hello')),
        ])];

        $screen = self::plain(self::list($tree, 'b'));

        $this->assertStringNotContainsString('[model:', $screen);
        $this->assertStringContainsString('user: hello', $screen);
    }

    public function testCtrlOCyclesThroughTheFiveFilters(): void
    {
        $tree = [self::node('a', new ModelChange('anthropic', 'm'), [
            self::node('b', self::said('hello'), [
                self::node('c', new ToolResultMessage('x', 'read', [new TextContent('out')]), [
                    self::node('d', self::said('named'), [], 'a name'),
                ]),
            ]),
        ])];
        $list = self::list($tree, 'd');

        $this->assertSame('', $list->filterLabel());

        $list->handleInput(self::CTRL_O);
        $this->assertSame(' [no-tools]', $list->filterLabel());
        $this->assertStringNotContainsString('[read', self::plain($list));

        $list->handleInput(self::CTRL_O);
        $this->assertSame(' [user-only]', $list->filterLabel());
        $this->assertSame(2, $list->count(), 'two user messages and nothing else');

        $list->handleInput(self::CTRL_O);
        $this->assertSame(' [labeled-only]', $list->filterLabel());
        $this->assertSame(1, $list->count());

        $list->handleInput(self::CTRL_O);
        $this->assertSame(' [all]', $list->filterLabel());
        $this->assertStringContainsString('[model:', self::plain($list));

        $list->handleInput(self::CTRL_O);
        $this->assertSame('', $list->filterLabel(), 'and it wraps');
    }

    public function testShiftCtrlOCyclesBack(): void
    {
        $list = self::list(self::forked(), 'f');
        $list->handleInput(self::SHIFT_CTRL_O);

        $this->assertSame(' [all]', $list->filterLabel());
    }

    public function testTheCursorStaysOnTheSameRowAcrossAFilterChange(): void
    {
        $list = self::list(self::forked(), 'f');
        $list->handleInput(self::UP);
        $was = $list->current();
        $list->handleInput(self::CTRL_O);

        // A filter key that moves the selection means enter goes somewhere nobody chose.
        $this->assertSame($was, $list->current());
    }

    // ---- searching -----------------------------------------------------------------------------

    public function testTypingSearchesAndEveryTokenHasToMatch(): void
    {
        $list = self::list(self::forked(), 'f');

        foreach (str_split('road not') as $character) {
            $list->handleInput($character);
        }

        $this->assertSame('road not', $list->search());
        $this->assertSame(1, $list->count());
        $this->assertSame('c', $list->current());
    }

    public function testWhatIsTypedIsOnScreen(): void
    {
        // Without it the rows narrow and nothing says why: a typo cannot be seen, "nothing
        // matches" cannot be told from an empty filter, and backspace is blind. Upstream draws
        // the same line, and `search()` existed here for a caller that was never written.
        $list = self::list(self::forked(), 'f');

        $this->assertStringContainsString('Search:', self::plain($list));

        foreach (str_split('road') as $character) {
            $list->handleInput($character);
        }

        $this->assertStringContainsString('Search: road', self::plain($list));
    }

    public function testTheSearchLineIsStillThereWhenNothingMatches(): void
    {
        // Where it matters most: the only way to see what was typed wrong.
        $list = self::list(self::forked(), 'f');

        foreach (str_split('zzz') as $character) {
            $list->handleInput($character);
        }

        $this->assertStringContainsString('Search: zzz', self::plain($list));
    }

    public function testBackspaceTakesTheSearchApartAgain(): void
    {
        $list = self::list(self::forked(), 'f');

        foreach (str_split('taken') as $character) {
            $list->handleInput($character);
        }

        $list->handleInput(self::BACKSPACE);

        $this->assertSame('take', $list->search());
    }

    public function testASearchThatMatchesNothingSaysSoRatherThanDrawingNothing(): void
    {
        $list = self::list(self::forked(), 'f');

        foreach (str_split('zzz') as $character) {
            $list->handleInput($character);
        }

        $this->assertSame(0, $list->count());
        $this->assertStringContainsString('Nothing matches', self::plain($list));
        $this->assertNull($list->current());
    }

    public function testEscapeClearsASearchBeforeItCancels(): void
    {
        $cancelled = false;
        $list = self::list(self::forked(), 'f');
        $list->setCancelHandler(static function () use (&$cancelled): void {
            $cancelled = true;
        });

        $list->handleInput('t');
        $list->handleInput(self::ESCAPE);

        $this->assertSame('', $list->search());
        $this->assertFalse($cancelled, 'the way out of "nothing matches" is not also the way out');

        $list->handleInput(self::ESCAPE);

        $this->assertTrue($cancelled);
    }

    public function testAnArrowIsNotTypedIntoTheSearchBox(): void
    {
        $list = self::list(self::forked(), 'f');
        $list->handleInput("\e[Z");

        // Every key this class does not name arrives as bytes; a control sequence must not end up
        // in the search one bracket at a time.
        $this->assertSame('', $list->search());
    }

    // ---- moving --------------------------------------------------------------------------------

    public function testUpAndDownWrapAtBothEnds(): void
    {
        // With the leaf at the root no fork is reordered, so the rows are a b c d e f.
        $list = self::list(self::forked(), 'a');
        $list->handleInput(self::UP);

        $this->assertSame('f', $list->current(), 'up from the first row is the last');

        $list->handleInput(self::DOWN);

        $this->assertSame('a', $list->current());
    }

    public function testLeftAndRightArePagesRatherThanWraps(): void
    {
        $list = self::list(self::forked(), 'a', maxVisible: 2);
        $list->handleInput(self::RIGHT);

        $this->assertSame('c', $list->current(), 'two rows on, not the next one');

        $list->handleInput(self::LEFT);
        $list->handleInput(self::LEFT);

        $this->assertSame('a', $list->current(), 'and clamped at the top rather than wrapping');
    }

    public function testEnterHandsBackTheIdUnderTheCursor(): void
    {
        $picked = null;
        $list = self::list(self::forked(), 'f');
        $list->setSelectHandler(static function (string $id) use (&$picked): void {
            $picked = $id;
        });

        $list->handleInput(self::ENTER);

        $this->assertSame('f', $picked);
    }

    public function testEnterOnAnEmptyListAsksForNothing(): void
    {
        $picked = null;
        $list = self::list(self::forked(), 'f');
        $list->setSelectHandler(static function (string $id) use (&$picked): void {
            $picked = $id;
        });

        foreach (str_split('zzz') as $character) {
            $list->handleInput($character);
        }

        $list->handleInput(self::ENTER);

        $this->assertNull($picked);
    }

    public function testLAsksForANameAndSaysWhatTheNameIsNow(): void
    {
        $asked = [];
        $tree = [self::node('a', self::said('hello'), [], 'before the refactor')];
        $list = self::list($tree, 'a');
        $list->setLabelHandler(static function (string $id, ?string $current) use (&$asked): void {
            $asked = [$id, $current];
        });

        $list->handleInput('l');

        $this->assertSame(['a', 'before the refactor'], $asked);
    }

    public function testLIsALetterWhileSomethingIsBeingSearchedFor(): void
    {
        $asked = false;
        $list = self::list(self::forked(), 'f');
        $list->setLabelHandler(static function () use (&$asked): void {
            $asked = true;
        });

        $list->handleInput('r');
        $list->handleInput('l');

        $this->assertFalse($asked, 'or "label" could never be typed');
        $this->assertSame('rl', $list->search());
    }

    // ---- shapes a file can hold ----------------------------------------------------------------

    public function testSeveralRootsAreDrawnAsBranchesOfSomethingThatIsNotThere(): void
    {
        // A file whose first entry has siblings, which a hook writing before the first message can
        // produce.
        $tree = [
            self::node('a', self::said('one')),
            self::node('b', self::said('two')),
        ];

        $screen = self::plain(self::list($tree, 'b'));

        $this->assertStringContainsString('user: one', $screen);
        $this->assertStringContainsString('user: two', $screen);
    }

    public function testAnEmptyTreeDrawsTheEmptyMessageRatherThanFailing(): void
    {
        $list = self::list([], null);

        $this->assertSame(0, $list->count());
        $this->assertStringContainsString('Nothing matches', self::plain($list));
    }

    public function testACustomEntryIsNamedByItsTypeInTheAllView(): void
    {
        $tree = [self::node('a', new CustomEntry('deploy-note', ['at' => 'now']))];
        $list = self::list($tree, 'a');

        $this->assertSame(0, $list->count(), 'bookkeeping, so the default view hides it');

        $list->handleInput(self::CTRL_O);
        $list->handleInput(self::CTRL_O);
        $list->handleInput(self::CTRL_O);
        $list->handleInput(self::CTRL_O);

        $this->assertStringContainsString('[deploy-note]', self::plain($list));
    }
}
