<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Ansi;
use Pig\Tui\Autocomplete\AutocompleteItem;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\SlashCommand;
use Pig\Tui\Components\Editor;
use Pig\Tui\Width;

final class EditorTest extends TestCase
{
    private Editor $editor;

    #[\Override]
    protected function setUp(): void
    {
        $this->editor = new Editor();
    }

    private function type(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->editor->handleInput($key);
        }
    }

    private function typeText(string $text): void
    {
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $this->editor->handleInput($char);
        }
    }

    /** The drawn rows between the two rules, with escape codes taken out. */
    private function rows(int $width = 40): array
    {
        $lines = array_map(Ansi::strip(...), $this->editor->render($width));

        return array_map(rtrim(...), array_slice($lines, 1, -1));
    }

    public function testAnEmptyEditorIsARuleACursorAndARule(): void
    {
        $lines = $this->editor->render(10);

        $this->assertCount(3, $lines);
        $this->assertSame(str_repeat('─', 10), Ansi::strip($lines[0]));
        $this->assertStringContainsString("\x1b[7m \x1b[27m", $lines[1]);
    }

    public function testTypingLandsInTheText(): void
    {
        $this->typeText('hello');

        $this->assertSame('hello', $this->editor->text());
        $this->assertSame(['hello'], $this->rows());
    }

    public function testEnterSubmitsAndClearsTheEditor(): void
    {
        $submitted = null;
        $this->editor->setSubmitHandler(static function (string $text) use (&$submitted): void {
            $submitted = $text;
        });

        $this->typeText('  hello  ');
        $this->type("\r");

        $this->assertSame('hello', $submitted);
        $this->assertSame('', $this->editor->text());
    }

    public function testSubmitCanBeTurnedOffWhileTheAgentIsBusy(): void
    {
        $submitted = 0;
        $this->editor->setSubmitHandler(static function () use (&$submitted): void {
            $submitted++;
        });
        $this->editor->disableSubmit = true;

        $this->typeText('hello');
        $this->type("\r");

        $this->assertSame(0, $submitted);
        $this->assertSame('hello', $this->editor->text());
    }

    public function testShiftEnterBreaksTheLineInsteadOfSubmitting(): void
    {
        $submitted = 0;
        $this->editor->setSubmitHandler(static function () use (&$submitted): void {
            $submitted++;
        });

        $this->typeText('one');
        // Every terminal spells this differently; all of them mean a new line.
        $this->type("\x1b[13;2u");
        $this->typeText('two');

        $this->assertSame("one\ntwo", $this->editor->text());
        $this->assertSame(0, $submitted);
    }

    public function testTheOtherSpellingsOfShiftEnterAlsoBreakTheLine(): void
    {
        foreach (["\n", "\x1b\r", "\x1b[13;2~", "\\\r"] as $key) {
            $editor = new Editor();
            $editor->handleInput('a');
            $editor->handleInput($key);
            $editor->handleInput('b');

            $this->assertSame("a\nb", $editor->text(), 'for ' . json_encode($key));
        }
    }

    public function testChangesAreReported(): void
    {
        $seen = [];
        $this->editor->setChangeHandler(static function (string $text) use (&$seen): void {
            $seen[] = $text;
        });

        $this->typeText('hi');

        $this->assertSame(['h', 'hi'], $seen);
    }

    public function testBackspaceAtTheStartOfALineJoinsItToThePrevious(): void
    {
        $this->editor->setText("one\ntwo");
        $this->type("\x01", "\x7f");

        $this->assertSame('onetwo', $this->editor->text());
        $this->assertSame(['line' => 0, 'col' => 3], $this->editor->cursor());
    }

    public function testBackspaceRemovesAWholeGrapheme(): void
    {
        $this->editor->setText("ab👨‍👩‍👧‍👦");
        $this->type("\x7f");

        $this->assertSame('ab', $this->editor->text());
    }

    public function testForwardDeleteAtTheEndOfALinePullsTheNextOneUp(): void
    {
        $this->editor->setText("one\ntwo");
        $this->type("\x1b[A", "\x05", "\x1b[3~");

        $this->assertSame('onetwo', $this->editor->text());
    }

    public function testCtrlWTakesOffOneSegmentOfAPath(): void
    {
        $this->typeText('cd src/pig');
        $this->type("\x17");

        $this->assertSame('cd src/', $this->editor->text());
    }

    public function testCtrlUAndCtrlKCutEitherSideOfTheCursor(): void
    {
        $this->typeText('hello world');
        $this->type("\x1b[1;5D", "\x15");
        $this->assertSame('world', $this->editor->text());

        $this->type("\x05", "\x1b[1;5D", "\x0b");
        $this->assertSame('', $this->editor->text());
    }

    public function testLeftAtTheStartOfALineWrapsToTheEndOfThePrevious(): void
    {
        $this->editor->setText("one\ntwo");
        $this->type("\x01", "\x1b[D");

        $this->assertSame(['line' => 0, 'col' => 3], $this->editor->cursor());
    }

    public function testUpAndDownMoveBetweenLines(): void
    {
        $this->editor->setText("first\nsecond");
        $this->type("\x01", "\x1b[A");

        $this->assertSame(0, $this->editor->cursor()['line']);

        $this->type("\x1b[B");
        $this->assertSame(1, $this->editor->cursor()['line']);
    }

    public function testUpMovesByDrawnRowsNotLogicalLines(): void
    {
        $this->editor->setText(str_repeat('a', 30));
        // Ten columns: thirty characters is three rows of one logical line.
        $this->editor->render(10);
        $this->type("\x1b[A");

        // Still on the only logical line, but a row higher.
        $this->assertSame(0, $this->editor->cursor()['line']);
        $this->assertSame(20, $this->editor->cursor()['col']);
    }

    public function testALongLineIsDrawnAsSeveralRows(): void
    {
        $this->editor->setText(str_repeat('a', 25));

        $this->assertSame(['aaaaaaaaaa', 'aaaaaaaaaa', 'aaaaa'], $this->rows(10));
    }

    public function testAWideCharacterIsNeverSplitAcrossRows(): void
    {
        $this->editor->setText(str_repeat('中', 4));

        // Five columns hold two CJK characters and could not hold half a third.
        $this->assertSame(['中中', '中中'], $this->rows(5));
    }

    public function testEveryDrawnRowIsExactlyTheWidth(): void
    {
        $this->editor->setText("short\n" . str_repeat('中', 12));

        foreach ($this->editor->render(11) as $line) {
            $this->assertSame(11, Width::visible($line));
        }
    }

    public function testTheCursorSitsOnTheCharacterItIsBefore(): void
    {
        $this->editor->setText('ab');
        $this->type("\x01");

        $this->assertStringContainsString("\x1b[7ma\x1b[27m", $this->editor->render(20)[1]);
    }

    public function testAFullRowPutsTheCursorOnItsLastCharacter(): void
    {
        // No cell is left to add one to, and adding one would wrap the row.
        $this->editor->setText('abcde');

        $this->assertStringContainsString("\x1b[7me\x1b[27m", $this->editor->render(5)[1]);
        $this->assertSame(5, Width::visible($this->editor->render(5)[1]));
    }

    public function testUpOnAnEmptyEditorBringsBackTheLastPrompt(): void
    {
        $this->editor->addToHistory('first thing');
        $this->editor->addToHistory('second thing');

        $this->type("\x1b[A");
        $this->assertSame('second thing', $this->editor->text());

        $this->type("\x1b[A");
        $this->assertSame('first thing', $this->editor->text());

        $this->type("\x1b[B");
        $this->assertSame('second thing', $this->editor->text());

        // Down past the newest entry returns to the empty line it started from.
        $this->type("\x1b[B");
        $this->assertSame('', $this->editor->text());
    }

    public function testHistoryIgnoresBlanksAndConsecutiveRepeats(): void
    {
        $this->editor->addToHistory('same');
        $this->editor->addToHistory('same');
        $this->editor->addToHistory('   ');

        $this->type("\x1b[A", "\x1b[A");

        $this->assertSame('same', $this->editor->text());
    }

    public function testTypingLeavesHistoryBrowsing(): void
    {
        $this->editor->addToHistory('older');
        $this->editor->addToHistory('newer');

        $this->type("\x1b[A");
        $this->typeText('!');
        // Up now moves the cursor inside the edited text, not back through history.
        $this->type("\x1b[A");

        $this->assertSame('newer!', $this->editor->text());
    }

    public function testASmallPasteGoesInAsText(): void
    {
        $this->type("\x1b[200~one\ntwo\x1b[201~");

        $this->assertSame("one\ntwo", $this->editor->text());
        $this->assertSame(['line' => 1, 'col' => 3], $this->editor->cursor());
    }

    public function testAPasteSplitAcrossChunksIsStillOnePaste(): void
    {
        $this->type("\x1b[200~one ", 'two ', "three\x1b[201~");

        $this->assertSame('one two three', $this->editor->text());
    }

    public function testAPasteIsMergedIntoTheLineTheCursorIsOn(): void
    {
        $this->typeText('a|b');
        $this->type("\x1b[D", "\x1b[D");
        $this->type("\x1b[200~X\nY\x1b[201~");

        $this->assertSame("aX\nY|b", $this->editor->text());
    }

    public function testABigPasteIsHeldAsideBehindAMarker(): void
    {
        $pasted = implode("\n", array_fill(0, 20, 'line'));
        $this->type("\x1b[200~{$pasted}\x1b[201~");

        // A wall of text would bury the prompt, so the prompt shows one line instead.
        $this->assertSame('[paste #1 +20 lines]', $this->editor->text());

        $submitted = null;
        $this->editor->setSubmitHandler(static function (string $text) use (&$submitted): void {
            $submitted = $text;
        });
        $this->type("\r");

        // What gets sent is the text, not the marker.
        $this->assertSame($pasted, $submitted);
    }

    public function testAPastedDollarSignSurvivesTheMarkerSubstitution(): void
    {
        $pasted = implode("\n", array_fill(0, 20, 'cost is $1 and \\0'));
        $this->type("\x1b[200~{$pasted}\x1b[201~");

        $submitted = null;
        $this->editor->setSubmitHandler(static function (string $text) use (&$submitted): void {
            $submitted = $text;
        });
        $this->type("\r");

        // Substituted through a callback: as a replacement string, `$1` would have been
        // read as a backreference and the paste would come back mangled.
        $this->assertSame($pasted, $submitted);
    }

    public function testAPastedPathAfterAWordGetsASpace(): void
    {
        $this->typeText('read');
        $this->type("\x1b[200~/etc/hosts\x1b[201~");

        $this->assertSame('read /etc/hosts', $this->editor->text());
    }

    public function testPastedTabsBecomeSpacesAndControlBytesAreDropped(): void
    {
        $this->type("\x1b[200~a\tb\x00c\x1b[201~");

        $this->assertSame('a    bc', $this->editor->text());
    }

    public function testSlashOpensTheCommandListAndTabTakesTheSelection(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([
            new SlashCommand('model', 'Choose a model'),
            new SlashCommand('quit'),
        ]));

        $this->typeText('/mo');
        $this->assertTrue($this->editor->isShowingSuggestions());

        $this->type("\t");

        $this->assertSame('/model ', $this->editor->text());
        $this->assertFalse($this->editor->isShowingSuggestions());
    }

    public function testTheCommandListIsDrawnUnderTheEditor(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([
            new SlashCommand('model'),
            new SlashCommand('mode'),
        ]));

        $this->typeText('/mo');
        $lines = array_map(Ansi::strip(...), $this->editor->render(40));

        $this->assertStringContainsString('model', $lines[3]);
        $this->assertStringContainsString('mode', $lines[4]);
    }

    public function testArrowKeysMoveThroughTheListInsteadOfTheText(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([
            new SlashCommand('model'),
            new SlashCommand('mode'),
        ]));

        $this->typeText('/mo');
        $this->type("\x1b[B", "\t");

        $this->assertSame('/mode ', $this->editor->text());
    }

    public function testEnterOnACommandCompletesItAndSubmits(): void
    {
        $submitted = null;
        $this->editor->setSubmitHandler(static function (string $text) use (&$submitted): void {
            $submitted = $text;
        });
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([new SlashCommand('quit')]));

        $this->typeText('/qu');
        $this->type("\r");

        $this->assertSame('/quit', $submitted);
    }

    public function testEscapeClosesTheListAndLeavesTheText(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([new SlashCommand('model')]));

        $this->typeText('/mo');
        $this->type("\x1b");

        $this->assertFalse($this->editor->isShowingSuggestions());
        $this->assertSame('/mo', $this->editor->text());
    }

    public function testTypingPastEveryMatchClosesTheList(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([new SlashCommand('model')]));

        $this->typeText('/mozz');

        $this->assertFalse($this->editor->isShowingSuggestions());
    }

    public function testBackspaceBringsTheListBackOnceThereAreMatchesAgain(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([new SlashCommand('model')]));

        $this->typeText('/mozz');
        $this->type("\x7f", "\x7f");

        // Deleting can turn "no matches" back into matches, so the list returns without
        // the user having to press Tab again.
        $this->assertTrue($this->editor->isShowingSuggestions());
    }

    public function testNoProviderMeansTabDoesNothing(): void
    {
        $this->typeText('/mo');
        $this->type("\t");

        $this->assertSame('/mo', $this->editor->text());
        $this->assertFalse($this->editor->isShowingSuggestions());
    }

    public function testACompletionIsAppliedThroughTheProvider(): void
    {
        $this->editor->setAutocompleteProvider(new CombinedAutocompleteProvider([
            new SlashCommand('model', null, static fn (string $typed): array => [new AutocompleteItem('opus')]),
        ]));

        $this->typeText('/model o');
        $this->type("\t");

        $this->assertSame('/model opus', $this->editor->text());
    }

    public function testCtrlCIsLeftToWhoeverIsHostingTheEditor(): void
    {
        $this->typeText('hello');
        $this->type("\x03");

        // At an empty prompt Ctrl+C means quit, and only the application knows that.
        $this->assertSame('hello', $this->editor->text());
    }

    // ---- where the terminal's cursor should sit -------------------------------------

    public function testTheCaretIsOneRowDownForTheBorder(): void
    {
        $editor = new Editor();

        $this->assertSame([1, 0], $editor->caret(40));
    }

    public function testTheCaretFollowsTypingAcrossTheLine(): void
    {
        $editor = new Editor();
        $editor->setText('hello');

        $this->assertSame([1, 5], $editor->caret(40));
    }

    public function testTheCaretIsMeasuredInColumnsNotCharacters(): void
    {
        // Two columns a character, which is the whole reason this cannot be a count of
        // characters: an input method draws its candidates at the terminal's cursor.
        $editor = new Editor();
        $editor->setText('你好');

        $this->assertSame([1, 4], $editor->caret(40));
    }

    public function testTheCaretFollowsAWrappedLineDown(): void
    {
        $editor = new Editor();
        $editor->setText(str_repeat('x', 25));

        $this->assertSame([2, 5], $editor->caret(20));
    }

    public function testTheCaretFollowsSeveralLinesDown(): void
    {
        $editor = new Editor();
        $editor->setText("one\ntwo\nthree");

        $this->assertSame([3, 5], $editor->caret(40));
    }
}
