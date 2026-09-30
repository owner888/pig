<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Test\AssertsThrows;

/** The conversation on disk, and reading it back. */
final class SessionManagerTest extends TestCase
{
    use AssertsThrows;

    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-sessions-' . bin2hex(random_bytes(4));
        putenv('PIG_HOME=' . $this->home);

        // `listFor()` reads pi's directory too, and on a real machine that is a real
        // directory with real conversations in it. Pointed somewhere empty so a test
        // cannot pass or fail on what the person running it happens to have.
        putenv('PI_HOME=' . $this->home . '-pi');
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('PIG_HOME');
        putenv('PI_HOME');
        self::remove($this->home);
        self::remove($this->home . '-pi');
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

    private function answer(string $text = 'here you go', StopReason $stop = StopReason::Stop): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(10, 20, 30, 40, 100, new Cost(total: 0.25)),
            $stop,
        );
    }

    // ---- when a file appears ----------------------------------------------------------

    public function testNothingIsWrittenUntilSomethingHasBeenAnswered(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('are you there'));

        // Someone who starts pig, reads the banner and quits leaves nothing behind —
        // which is what makes the sessions directory worth opening at all.
        $this->assertFileDoesNotExist($session->path);
    }

    public function testTheQuestionIsWrittenInFrontOfTheAnswerThatTriggeredIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('are you there'));
        $session->append($this->answer());

        $lines = file($session->path, FILE_IGNORE_NEW_LINES);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('"type":"session"', $lines[0]);
        $this->assertStringContainsString('are you there', $lines[1]);
        $this->assertStringContainsString('here you go', $lines[2]);
    }

    public function testEachLaterMessageIsAppendedRatherThanRewritten(): void
    {
        // Appended, so a session survives whatever ends the process — a crash, a closed
        // laptop, Ctrl+C twice.
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());
        $before = filesize($session->path);

        $session->append(new UserMessage('two'));

        $this->assertGreaterThan((int) $before, filesize($session->path));
    }

    // ---- what survives the trip ---------------------------------------------------------

    public function testEveryKindOfMessageComesBackAsItWent(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('a question'));
        $session->append(new AssistantMessage(
            [new ThinkingContent('let me look', 'sig'), new TextContent('found it'), new ToolCall('c1', 'read', ['path' => 'a.php'])],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(1, 2, 3, 4, 10, new Cost(0.1, 0.2, 0.3, 0.4, 1.0)),
            StopReason::ToolUse,
        ));
        $session->append(new ToolResultMessage('c1', 'read', [new TextContent('<?php')], false, ['diff' => "-1 a\n+1 b"]));
        $session->append(new BashExecution('ls', "a\nb", 0));

        $back = SessionManager::open($session->path)->messages();

        $this->assertCount(4, $back);

        $assistant = $back[1];
        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertSame('let me look', $assistant->content[0]->thinking);
        $this->assertSame('sig', $assistant->content[0]->thinkingSignature);
        $this->assertSame('read', $assistant->content[2]->name);
        $this->assertSame(['path' => 'a.php'], $assistant->content[2]->arguments);
        $this->assertSame(1, $assistant->usage->input);
        $this->assertSame(1.0, $assistant->usage->cost->total);
        $this->assertSame(StopReason::ToolUse, $assistant->stopReason);

        // The one thing anything reads out of a tool's details is the edit diff.
        $this->assertSame("-1 a\n+1 b", $back[2]->details['diff']);

        // A call with no arguments is written as `{}` and not `[]`. The file is pi's, and pi
        // sends this history straight back to a provider — `input: []` is refused by Anthropic,
        // so an empty PHP array reaching the file is a conversation pi cannot carry on.
        $session->append(new AssistantMessage(
            [new ToolCall('c2', 'now', [])],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(),
            StopReason::ToolUse,
        ));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($session->path))));
        $written = (string) end($lines);

        $this->assertStringContainsString('"arguments":{}', $written);
        $this->assertSame([], SessionManager::open($session->path)->messages()[4]->content[0]->arguments);

        $bash = $back[3];
        $this->assertInstanceOf(BashExecution::class, $bash);
        $this->assertSame('ls', $bash->command);
        $this->assertSame(0, $bash->exitCode);
    }

    public function testAnImageSurvivesToo(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage([new TextContent('look'), new ImageContent('BASE64', 'image/png')]));
        $session->append($this->answer());

        $back = SessionManager::open($session->path)->messages()[0];

        $this->assertInstanceOf(ImageContent::class, $back->content[1]);
        $this->assertSame('image/png', $back->content[1]->mimeType);
    }

    public function testUnicodeIsWrittenAsItselfAndComesBackWhole(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('你好 — 中文注释'));
        $session->append($this->answer('好的'));

        // Readable in the file as well as after decoding: a session log is something
        // someone greps.
        $this->assertStringContainsString('你好 — 中文注释', (string) file_get_contents($session->path));
        $this->assertSame('你好 — 中文注释', SessionManager::open($session->path)->messages()[0]->content[0]->text);
    }

    public function testAnErrorTurnKeepsItsMessage(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('go'));
        $session->append(new AssistantMessage(
            [],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(),
            StopReason::Error,
            'overloaded_error',
        ));

        $back = SessionManager::open($session->path)->messages()[1];

        $this->assertSame(StopReason::Error, $back->stopReason);
        $this->assertSame('overloaded_error', $back->errorMessage);
    }

    // ---- compaction ----------------------------------------------------------------------

    public function testACompactedConversationComesBackCompacted(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('the first thing'));
        $session->append($this->answer());
        $session->append(new UserMessage('the second thing'));
        $session->append($this->answer('and again'));

        // Where the kept part starts, by entry id — pi's way of saying it, and what the
        // file records. `entryAt()` is the join: index 3 of the conversation is the
        // fourth message, which is the one this summary keeps.
        $session->append(new CompactionSummary(
            'we talked about things',
            ['a.php'],
            ['b.php'],
            1_000,
            $session->entryAt(3),
        ));

        $back = SessionManager::open($session->path)->messages();

        // Three messages became one, and the fourth is still there. Replaying the log
        // without knowing where the kept part starts would hand a resumed session back the
        // whole conversation that had just been compacted away.
        $this->assertCount(2, $back);
        $this->assertInstanceOf(CompactionSummary::class, $back[0]);
        $this->assertSame('we talked about things', $back[0]->summary);
        $this->assertSame(['a.php'], $back[0]->readFiles);
        $this->assertSame(['b.php'], $back[0]->modifiedFiles);
        $this->assertSame(1_000, $back[0]->tokensBefore);
        $this->assertSame('and again', $back[1]->content[0]->text);

        // Counted back from the file rather than stored in it: two facts about one thing
        // written down twice is two facts that can disagree.
        $this->assertSame(3, $back[0]->replaced);
    }

    public function testTheMessagesThatWereSummarisedAreStillInTheFile(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('the first thing'));
        $session->append($this->answer());
        $session->append(new CompactionSummary('we talked about things', [], [], 0, null));

        // Nothing is ever rewritten: a session log is a record of what happened, and what
        // happened is that these were said and then summarised.
        $this->assertStringContainsString('the first thing', (string) file_get_contents($session->path));
    }

    public function testASummaryThatKeepsNothingReplacesTheWholeConversation(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());
        $session->append(new CompactionSummary('all of it', [], [], 0, null));

        // A null `firstKeptEntryId` is "the kept part starts nowhere", which is a summary
        // standing in for everything before it.
        $back = SessionManager::open($session->path)->messages();
        $this->assertCount(1, $back);
        $this->assertSame('all of it', $back[0]->summary);
    }

    public function testTwoCompactionsInARowLeaveOneSummary(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());
        $session->append(new CompactionSummary('first summary', [], [], 0, null));
        $session->append(new UserMessage('two'));
        $session->append($this->answer('again'));
        $session->append(new CompactionSummary('second summary', [], [], 0, null));

        $back = SessionManager::open($session->path)->messages();

        $this->assertCount(1, $back);
        $this->assertSame('second summary', $back[0]->summary);
    }

    // ---- going back ------------------------------------------------------------------------

    public function testEveryPointOnTheConversationCanBeNamed(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $session->append(new UserMessage('two'));

        $branch = $session->branch();

        $this->assertCount(3, $branch);
        $this->assertNotSame($branch[0]['id'], $branch[1]['id']);
        $this->assertSame('one', $branch[0]['message']->content[0]->text);
    }

    public function testGoingBackAndSayingSomethingElseForksRatherThanOverwrites(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first answer'));
        $after = $session->branch()[1]['id'];
        $session->append(new UserMessage('down the wrong road'));
        $session->append($this->answer('wrong'));

        $session->goTo($after);
        $session->append(new UserMessage('the other way'));

        $messages = $session->messages();

        // The conversation is now the second road, and the first is not in it.
        $this->assertCount(3, $messages);
        $this->assertSame('the other way', $messages[2]->content[0]->text);
    }

    public function testTheAbandonedBranchIsStillThereToGoBackTo(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $fork = $session->branch()[1]['id'];
        $session->append(new UserMessage('down the wrong road'));
        $wrong = $session->leaf();

        $session->goTo($fork);
        $session->append(new UserMessage('the other way'));

        $session->goTo($wrong);

        // Nothing is deleted and nothing is rewritten, which is the whole point.
        $this->assertSame('down the wrong road', $session->messages()[2]->content[0]->text);
    }

    public function testAForkIsVisibleAsOne(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $fork = $session->branch()[1]['id'];
        $session->append(new UserMessage('road A'));

        $session->goTo($fork);
        $session->append(new UserMessage('road B'));

        $branch = $session->branch();

        // Two entries call the fork point their parent, so a picker can say so.
        $this->assertSame(2, $branch[2]['branches']);
        $this->assertSame(1, $branch[1]['branches']);
    }

    public function testBothBranchesSurviveBeingWrittenAndReadBack(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $fork = $session->branch()[1]['id'];
        $session->append(new UserMessage('road A'));
        $session->goTo($fork);
        $session->append(new UserMessage('road B'));

        $back = SessionManager::open($session->path);

        // The end of the file is the end of the branch that was being talked on.
        $this->assertSame('road B', $back->messages()[2]->content[0]->text);

        $back->goTo($back->branch()[1]['id']);
        $this->assertCount(2, $back->messages());
    }

    public function testGoingNowhereIsRefusedRatherThanSilentlyEmptying(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());

        $this->assertThrows(
            AgentError::class,
            static fn () => $session->goTo('not-an-entry'),
            'No such point',
        );
    }

    public function testABranchSummaryComesBackAsItWent(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());
        $session->append(new BranchSummary(
            'what happened over there',
            ['read.php'],
            ['written.php'],
            'abc123',
            fromHook: true,
        ));

        $reopened = SessionManager::open($session->path)->messages();
        $summary = $reopened[2];

        $this->assertInstanceOf(BranchSummary::class, $summary);
        $this->assertSame('what happened over there', $summary->summary);
        $this->assertSame(['read.php'], $summary->readFiles);
        $this->assertSame(['written.php'], $summary->modifiedFiles);
        $this->assertSame('abc123', $summary->fromId);
        $this->assertTrue($summary->fromHook);
    }

    /**
     * It replaces nothing, unlike a compaction summary.
     *
     * The branch it describes was never on this one, so reading the log back must not
     * splice anything out on its account.
     */
    public function testABranchSummaryDoesNotReplaceWhatCameBeforeIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $session->append(new BranchSummary('from elsewhere'));

        $this->assertCount(3, SessionManager::open($session->path)->messages());
    }

    // ---- what a jump would leave behind ------------------------------------------------

    public function testGoingBackOnThisBranchLeavesEverythingAfterThePoint(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $target = $session->branch()[1]['id'];
        $session->append(new UserMessage('two'));
        $session->append($this->answer('second'));

        $left = $session->abandoning($target);

        $this->assertCount(2, $left);
        $this->assertSame('two', $left[0]->content[0]->text);
        $this->assertSame('second', $left[1]->content[0]->text);
    }

    public function testGoingNowhereLeavesTheWholeConversationBehind(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));

        $this->assertCount(2, $session->abandoning(null));
    }

    public function testStayingWhereYouAreLeavesNothingBehind(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));

        $this->assertSame([], $session->abandoning($session->leaf()));
    }

    /**
     * Jumping to another branch leaves everything past the point the two paths last
     * agreed — which is not "everything after the target", because the target is not on
     * the branch being left at all.
     */
    public function testJumpingToAnotherBranchLeavesOnlyWhatTheTwoDoNotShare(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('shared'));
        $session->append($this->answer('shared answer'));
        $fork = $session->leaf();

        $session->append(new UserMessage('first road'));
        $firstRoad = $session->leaf();

        $session->goTo($fork);
        $session->append(new UserMessage('second road'));
        $session->append($this->answer('second answer'));

        $left = $session->abandoning($firstRoad);

        // The two shared messages stay; only this branch's own two are being left.
        $this->assertCount(2, $left);
        $this->assertSame('second road', $left[0]->content[0]->text);
        $this->assertSame('second answer', $left[1]->content[0]->text);
    }

    /**
     * Entries, not the conversation they stand for: a summary here is the summary, and
     * whoever is looking at what is being abandoned wants what was written down.
     */
    public function testACompactionSummaryIsLeftBehindAsItself(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $target = $session->branch()[0]['id'];
        $session->append(new CompactionSummary('it was about one thing'));

        $left = $session->abandoning($target);

        $this->assertCount(2, $left);
        $this->assertInstanceOf(CompactionSummary::class, $left[1]);
    }

    public function testACompactionOnAnAbandonedBranchDoesNotAffectTheOtherOne(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $fork = $session->leaf();
        $session->append(new CompactionSummary('summarised', [], [], 0, null));

        $session->goTo($fork);

        // Resolved on the way out, every time: doing it once at load would be wrong the
        // moment a branch was taken from before the compaction.
        $this->assertCount(2, $session->messages());
    }

    // ---- finding one again ----------------------------------------------------------------

    public function testAJsonlFileThatIsNotASessionIsNotOfferedInTheList(): void
    {
        // The same guard as `open()`'s, in the path that builds `--resume`'s list — and with the
        // same hole: a file whose first line is valid JSON of the wrong type passes a weakened
        // check. What it costs here is milder than opening one (nothing is written), and it is
        // still a row in a list of conversations that is not a conversation.
        $directory = SessionManager::directory('/some/project');
        $session = SessionManager::create('/some/project', "{$directory}/2026-01-01T00-00-00-000Z_real.jsonl");
        $session->append(new UserMessage('a real conversation'));
        $session->append($this->answer());

        file_put_contents(
            "{$directory}/2026-01-02T00-00-00-000Z_notes.jsonl",
            json_encode(['type' => 'note', 'text' => 'not a conversation']) . "\n",
        );

        $listed = SessionManager::listFor('/some/project');

        $this->assertCount(1, $listed);
        $this->assertSame('a real conversation', $listed[0]->opening);
    }

    public function testSessionsAreListedNewestFirstAndLabelledByWhatWasAsked(): void
    {
        foreach (['the first thing', 'the second thing'] as $index => $said) {
            $directory = SessionManager::directory('/some/project');
            $session = SessionManager::create('/some/project', "{$directory}/2026-01-0{$index}T00-00-00-000Z_x{$index}.jsonl");
            $session->append(new UserMessage($said));
            $session->append($this->answer());
        }

        $listed = SessionManager::listFor('/some/project');

        $this->assertCount(2, $listed);
        // The first thing the person said is the label, because that is how anyone
        // remembers a conversation.
        $this->assertSame('the second thing', $listed[0]->opening);
        $this->assertSame(2, $listed[0]->messages);
    }

    /**
     * "Newest" is when it was last worked on, not when it was started.
     *
     * A conversation's file is named after the moment it began and is never renamed, so a name
     * sort answers "most recently *started*" — which for `--continue` is the wrong question the
     * moment anybody resumes something. Start A on Monday and B on Tuesday, spend Wednesday in A,
     * and a name sort hands back B: the one conversation you were demonstrably not working on.
     */
    public function testContinueOpensTheOneLastWorkedOnRatherThanTheOneStartedLast(): void
    {
        $directory = SessionManager::directory('/some/project');

        foreach (['A', 'B'] as $index => $which) {
            $session = SessionManager::create(
                '/some/project',
                "{$directory}/2026-01-0{$index}T00-00-00-000Z_x{$index}.jsonl",
            );
            $session->append(new UserMessage("conversation {$which}"));
            $session->append($this->answer());
        }

        // A was started first and resumed since; B has not been touched since it began.
        $a = "{$directory}/2026-01-00T00-00-00-000Z_x0.jsonl";
        $b = "{$directory}/2026-01-01T00-00-00-000Z_x1.jsonl";
        touch($b, 1_700_000_000);
        touch($a, 1_700_000_600);

        $this->assertSame('conversation A', SessionManager::latestFor('/some/project')?->opening);

        // And the picker's order is the same order, so the row under the cursor is the one
        // somebody would reach for first.
        $this->assertSame(
            ['conversation A', 'conversation B'],
            array_map(
                static fn (SessionInfo $info): string => $info->opening,
                SessionManager::listFor('/some/project'),
            ),
        );
    }

    /** Two files written in the same second still come back in a fixed order. */
    public function testSessionsWrittenInTheSameSecondDoNotShuffle(): void
    {
        $directory = SessionManager::directory('/some/project');

        foreach (['first', 'second'] as $index => $said) {
            $session = SessionManager::create(
                '/some/project',
                "{$directory}/2026-01-0{$index}T00-00-00-000Z_x{$index}.jsonl",
            );
            $session->append(new UserMessage($said));
            $session->append($this->answer());
            touch($session->path, 1_700_000_000);
        }

        $openings = array_map(
            static fn (SessionInfo $info): string => $info->opening,
            SessionManager::listFor('/some/project'),
        );

        $this->assertSame(['second', 'first'], $openings);
    }

    /**
     * A label survives a first message whose bytes are not UTF-8, and writing is why.
     *
     * `opening()` folds the whitespace with `preg_replace('/\s+/u', …)`, which answers **null** on
     * malformed UTF-8, and `(string) null` is `''` — so the label would be empty and the
     * conversation would sit in `--resume` with no name on it. It cannot happen here because this
     * only ever reads what `append()` wrote, and that goes through `JSON_INVALID_UTF8_SUBSTITUTE`.
     * The guard is one method away from the thing it protects, so this pins it: `@notes.txt` on a
     * latin-1 file puts those bytes straight into the first message, since `Cli\FileArguments`
     * reads with `file_get_contents` and wraps without cleaning.
     */
    public function testASessionThatOpensWithBytesThatAreNotUtf8StillHasALabel(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('read this ' . chr(0x80) . ' file'));
        $session->append($this->answer());

        $listed = SessionManager::listFor('/some/project');

        $this->assertNotSame('', $listed[0]->opening);
        $this->assertStringContainsString('read this', $listed[0]->opening);
        $this->assertStringContainsString('file', $listed[0]->opening);
    }

    public function testEverythingSaidIsCollectedSoASessionCanBeFoundByIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('why is the handshake failing'));
        $session->append($this->answer('because crypto returns zero'));
        $session->append(new UserMessage('try again'));

        $listed = SessionManager::listFor('/some/project')[0];

        // Not for showing — it is the transcript on one line. `--resume`'s search matches
        // against this, which is what makes a conversation findable by something from the
        // middle of it rather than only by how it opened.
        $this->assertSame('why is the handshake failing because crypto returns zero try again', $listed->text);
    }

    public function testThinkingAndToolResultsAreNotSearchable(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('have a look'));
        $session->append(new AssistantMessage(
            [new ThinkingContent('nobody ever read this', 'sig'), new TextContent('found it')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(1, 1, 0, 0, 2, new Cost(total: 0.0)),
            StopReason::Stop,
        ));
        $session->append(new ToolResultMessage('c1', 'read', [new TextContent('the whole of some file')], false));

        $text = SessionManager::listFor('/some/project')[0]->text;

        $this->assertSame('have a look found it', $text);
        // Thinking is the model talking to itself: a hit on it finds a conversation by
        // something nobody ever saw.
        $this->assertStringNotContainsString('nobody ever read this', $text);
        // And a tool result is usually a file, so searching them would match everything.
        $this->assertStringNotContainsString('the whole of some file', $text);
    }

    public function testEachProjectHasItsOwnDirectory(): void
    {
        $this->assertNotSame(
            SessionManager::directory('/project/one'),
            SessionManager::directory('/project/two'),
        );

        // Named after the path, so opening the directory says which project it is.
        $this->assertStringContainsString('project-one', SessionManager::directory('/project/one'));
    }

    public function testAnotherProjectsSessionsAreNotListedHere(): void
    {
        $session = SessionManager::create('/project/one');
        $session->append(new UserMessage('theirs'));
        $session->append($this->answer());

        $this->assertSame([], SessionManager::listFor('/project/two'));
        $this->assertNull(SessionManager::latestFor('/project/two'));
    }

    public function testAFileThatIsNotASessionIsRefusedRatherThanHalfRead(): void
    {
        $path = $this->home . '/not-a-session.jsonl';
        mkdir($this->home, 0o700, true);
        file_put_contents($path, "just some text\n");

        $this->assertThrows(
            AgentError::class,
            static fn () => SessionManager::open($path),
            'Not a pig session file',
        );
    }

    public function testAJsonFileThatIsNotASessionIsRefusedToo(): void
    {
        // **The case the test above cannot reach.** It writes `just some text`, which fails both
        // halves of `!is_array($header) || type !== 'session'` at once — so the guard survives
        // being weakened to `&&` and the suite stays green. What that weakening allows is the
        // thing this refusal exists to prevent: `.jsonl` is an ordinary format for datasets and
        // logs, and a mistyped `--resume ~/data/train.jsonl` would open one as a conversation and
        // then **append session entries to it**. There is no undo for that.
        $path = $this->home . '/train.jsonl';
        mkdir($this->home, 0o700, true);
        file_put_contents($path, json_encode(['type' => 'note', 'text' => 'not a conversation']) . "\n");

        $this->assertThrows(
            AgentError::class,
            static fn () => SessionManager::open($path),
            'Not a pig session file',
        );

        // Well-formed JSON of the right *shape* but the wrong type is the same refusal: a session
        // header is the only thing that makes a file one.
        file_put_contents($path, json_encode(['type' => 'message', 'cwd' => '/somewhere']) . "\n");

        $this->assertThrows(
            AgentError::class,
            static fn () => SessionManager::open($path),
            'Not a pig session file',
        );
    }

    public function testAMissingFileSaysSo(): void
    {
        $this->assertThrows(
            AgentError::class,
            fn () => SessionManager::open($this->home . '/nowhere.jsonl'),
            'Could not read',
        );
    }

    public function testALineWrittenBySomethingNewerIsWalkedPastRatherThanFatal(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());

        $leaf = $session->leaf();
        file_put_contents(
            $session->path,
            json_encode(['type' => 'something_else', 'id' => 'zzzzzzzz', 'parentId' => $leaf, 'timestamp' => '2030-01-01T00:00:00.000Z']) . "\n"
                . json_encode(['type' => 'message', 'id' => 'yyyyyyyy', 'parentId' => 'zzzzzzzz', 'timestamp' => '2030-01-01T00:00:01.000Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'after it']]]]) . "\n",
            FILE_APPEND,
        );

        // Kept in the tree, not skipped: the message after it names it as its parent, and
        // dropping it would have stranded everything downstream.
        $back = SessionManager::open($session->path)->messages();
        $this->assertCount(3, $back);
        $this->assertSame('after it', $back[2]->content[0]->text);
    }

    public function testTheTimeAgoReadsTheWayPeopleSayIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer());

        $info = SessionManager::listFor('/some/project')[0];
        $now = intdiv($info->timestamp, 1000);

        $this->assertSame('just now', $info->when($now));
        $this->assertSame('5m ago', $info->when($now + 300));
        $this->assertSame('3h ago', $info->when($now + 10_800));
        $this->assertSame('2d ago', $info->when($now + 172_800));
    }

    // ---- the whole tree -----------------------------------------------------------------------

    public function testTreeShowsABranchThatBranchOnlyHides(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');
        $branch = $session->branch();
        $back = $branch[count($branch) - 1]['id'];
        $this->converse($session, 'B');

        $session->goTo($back);
        $this->converse($session, 'C');

        // `branch()` is the path being talked on, so B is gone from it.
        $said = array_map(
            static fn (array $point): string => (string) ($point['message']->content[0]->text ?? ''),
            $session->branch(),
        );

        $this->assertNotContains('B', $said);

        // `tree()` has it, which is the point: `goTo()` promises an abandoned branch can be gone
        // back to, and until this existed nothing could name one.
        $ids = [];
        $walk = static function (array $nodes) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                $ids[(string) ($node['message']->content[0]->text ?? '')] = $node['id'];
                $walk($node['children']);
            }
        };
        $walk($session->tree());

        $this->assertArrayHasKey('B', $ids);
        $this->assertArrayHasKey('C', $ids);

        // And it is a real id: going back to it works.
        $session->goTo($ids['B']);
        $said = array_map(
            static fn (array $point): string => (string) ($point['message']->content[0]->text ?? ''),
            $session->branch(),
        );

        $this->assertContains('B', $said);
        $this->assertNotContains('C', $said);
    }

    public function testTheTreeIsTheForkItDescribes(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');
        $branch = $session->branch();
        $back = $branch[count($branch) - 1]['id'];
        $this->converse($session, 'B');
        $session->goTo($back);
        $this->converse($session, 'C');

        $tree = $session->tree();

        // One root, and the fork is where the conversation went back to.
        $this->assertCount(1, $tree);

        $node = $tree[0];

        while (count($node['children']) === 1) {
            $node = $node['children'][0];
        }

        $this->assertCount(2, $node['children'], 'two ways forward from the point that was returned to');
        $this->assertSame('B', $node['children'][0]['message']->content[0]->text ?? null, 'file order');
        $this->assertSame('C', $node['children'][1]['message']->content[0]->text ?? null);
    }

    public function testATreeWithNothingInItIsEmptyRatherThanAFailure(): void
    {
        $this->assertSame([], SessionManager::create($this->cwd())->tree());
    }

    public function testALabelOnAPointIsOnItsNode(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');
        $branch = $session->branch();
        $session->appendLabel($branch[0]['id'], 'the start');

        $this->assertSame('the start', $session->tree()[0]['label']);
    }

    public function testAnEntryThatIsItsOwnParentIsARootRatherThanAnEndlessTree(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');

        // A file written by something else can say anything, and an entry naming itself as its
        // parent is the one shape `tree()` cannot walk: it would be its own child for ever.
        file_put_contents(
            $session->path,
            json_encode([
                'type' => 'message',
                'id' => 'zzzzzzzz',
                'parentId' => 'zzzzzzzz',
                'timestamp' => '2030-01-01T00:00:00.000Z',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'from nowhere']]],
            ]) . "\n",
            FILE_APPEND,
        );

        $roots = SessionManager::open($session->path)->tree();

        $this->assertCount(2, $roots, 'the conversation, and the self-parented entry beside it');
        $this->assertSame('zzzzzzzz', $roots[1]['id']);
        $this->assertSame([], $roots[1]['children']);
    }

    public function testAnEntryWhoseParentIsMissingIsWalkedBackFromRatherThanThrough(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');

        file_put_contents(
            $session->path,
            json_encode([
                'type' => 'message',
                'id' => 'yyyyyyyy',
                'parentId' => 'nosuchid',
                'timestamp' => '2030-01-01T00:00:00.000Z',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'orphaned']]],
            ]) . "\n",
            FILE_APPEND,
        );

        // The walk back stops where the chain does: an entry whose parent is not in the file is
        // a root, so this conversation is that one message and nothing in front of it.
        $back = SessionManager::open($session->path)->messages();

        $this->assertCount(1, $back);
        $this->assertSame('orphaned', $back[0]->content[0]->text);
    }

    // ---- what a list of conversations says about each one -------------------------------------

    public function testTheLabelInTheListIsTheFirstThingSaidAndNotTheLast(): void
    {
        // What anybody remembers a conversation by is how it opened. Reading on past the first
        // question leaves every row in `--resume`'s list labelled by the last thing typed into
        // it, which for a long conversation is a detail nobody would recognise.
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('why does the build fail'));
        $session->append($this->answer());
        $session->append(new UserMessage('and now try the other one'));
        $session->append($this->answer('done'));

        $this->assertSame('why does the build fail', SessionManager::listFor('/some/project')[0]->opening);
    }

    public function testAConversationThatOpensWithAPictureIsLabelledByWhatWasSaidWithIt(): void
    {
        // A pasted screenshot arrives as the first block of the first message, so the walk for
        // the opening line has to step over a block that is not text rather than reading one.
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage([
            new ImageContent(base64_encode('not really a png'), 'image/png'),
            new TextContent('what is wrong with this screen'),
        ]));
        $session->append($this->answer());

        $this->assertSame('what is wrong with this screen', SessionManager::listFor('/some/project')[0]->opening);
    }

    public function testTheMessageCountCountsMessagesAndNotEveryLineInTheFile(): void
    {
        // A session file holds lines that are not messages — a label, the model that was being
        // used, a hook's private note — and `--resume` shows this number beside the opening
        // line. Counting the whole file says a two-message conversation is five messages long.
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one thing'));
        $session->append($this->answer());
        $session->appendModelChange('anthropic', 'claude-x');
        $session->appendCustomEntry('note', ['seen' => true]);
        $session->appendLabel($session->branch()[0]['id'], 'the start');

        // And a line that calls itself a message and carries none is not one either: there is
        // nothing to count the role of, so counting it would be counting a line for its `type`.
        file_put_contents(
            $session->path,
            json_encode(['type' => 'message', 'id' => 'xxxxxxxx', 'parentId' => null, 'timestamp' => '2030-01-01T00:00:00.000Z']) . "\n",
            FILE_APPEND,
        );

        $this->assertSame(2, SessionManager::listFor('/some/project')[0]->messages);
    }

    public function testADirectoryThatLooksLikeASessionFileIsNotListed(): void
    {
        $directory = SessionManager::directory('/some/project');
        $session = SessionManager::create('/some/project', "{$directory}/2026-01-01T00-00-00-000Z_real.jsonl");
        $this->converse($session, 'a real conversation');

        // The glob matches a name, not a file. Two guards make this true — the stat that builds
        // the sort keys skips it, and `lines()` refuses anything that is not a file — so no one
        // mutation of either fails this; what it pins is the rule rather than a line.
        mkdir("{$directory}/2026-01-02T00-00-00-000Z_not-a-file.jsonl");

        $listed = SessionManager::listFor('/some/project');

        $this->assertCount(1, $listed);
        $this->assertSame('a real conversation', $listed[0]->opening);
    }

    // ---- a name on a point --------------------------------------------------------------------

    public function testALabelOfNothingButSpacesClearsTheNameAndOneWithSpacesRoundItIsTrimmed(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');
        $point = $session->branch()[0]['id'];

        $session->appendLabel($point, '  before the refactor  ');
        $this->assertSame('before the refactor', $session->labelOf($point));

        // `/label` with nothing after it clears the name, and what arrives here from an editor
        // with a space still in it is the same act.
        $session->appendLabel($point, '   ');
        $this->assertNull($session->labelOf($point));
    }

    public function testAClearedNameIsGoneFromTheOneKeptInMemoryToo(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'A');
        $point = $session->branch()[0]['id'];

        $session->appendLabel($point, 'the start');
        $session->appendLabel($point, null);

        // Two records of one name — the file and the map this reads from — so clearing has to
        // reach both or the name comes off on disk and stays on screen.
        $this->assertNull($session->labelOf($point));
        $this->assertNull(SessionManager::open($session->path)->labelOf($point));
    }

    public function testSettingSessionNamePersistsAndReopens(): void
    {
        $session = SessionManager::create($this->cwd());
        $this->converse($session, 'Hello world');

        $this->assertNull($session->sessionName());
        $session->setSessionName('仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms');
        $this->assertSame('仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms', $session->sessionName());

        $reopened = SessionManager::open($session->path);
        $this->assertSame('仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms', $reopened->sessionName());

        // And in listFor/describe, session name is displayed instead of the first message
        $list = SessionManager::listFor($this->cwd());
        $this->assertNotEmpty($list);
        $this->assertSame('仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms', $list[0]->opening);
    }

    // ---- writing ------------------------------------------------------------------------------

    public function testASessionThatCannotBeWrittenSaysSoRatherThanCarryingOnSilently(): void
    {
        $directory = SessionManager::directory('/some/project');
        mkdir($directory, 0o700, true);

        // A directory standing where the file should be is the one way to make the write fail
        // on purpose: the parent exists, so nothing earlier refuses first.
        $path = "{$directory}/2026-01-01T00-00-00-000Z_taken.jsonl";
        mkdir($path);

        $session = SessionManager::create('/some/project', $path);
        $session->append(new UserMessage('a question'));

        // A failing `file_put_contents` warns as well as answering false, and a warning fails a
        // test under this project's phpunit.xml — so it is caught here rather than left to fail
        // the assertion about the throw.
        set_error_handler(static fn (): bool => true);

        try {
            $problem = $this->assertThrows(AgentError::class, fn () => $session->append($this->answer()));
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('Could not write the session', $problem->getMessage());
    }

    public function testACompactionDoesNotReplayTheMessagesThatCameAfterIt(): void
    {
        $session = SessionManager::create('/some/project');
        $session->append(new UserMessage('one'));
        $session->append($this->answer('first'));
        $session->append(new UserMessage('two'));
        $session->append($this->answer('second'));
        $session->append(new CompactionSummary('we talked', [], [], 0, $session->entryAt(3)));
        $session->append(new UserMessage('three'));
        $session->append($this->answer('third'));

        // What a compaction keeps ends at the compaction itself. Reading past it collects the
        // messages after it as well, and the walk then appends those a second time — a resumed
        // conversation whose last exchange the model is shown twice.
        $back = SessionManager::open($session->path)->messages();

        $this->assertCount(4, $back);
        $this->assertInstanceOf(CompactionSummary::class, $back[0]);
        $this->assertSame('second', $back[1]->content[0]->text);
        $this->assertSame('three', $back[2]->content[0]->text);
        $this->assertSame('third', $back[3]->content[0]->text);
        $this->assertSame(3, $back[0]->replaced);
    }

    public function testIsPersistedAndResumeCommand(): void
    {
        $session = SessionManager::create($this->cwd());

        // Newly created session without persisted assistant turn is not persisted
        $this->assertFalse($session->isPersisted());
        $this->assertNull($session->resumeCommand());

        $this->converse($session, 'first turn');

        // Persisted once written to disk
        $this->assertTrue($session->isPersisted());
        $this->assertSame("pig --session {$session->id}", $session->resumeCommand());
    }

    public function testFindSessionByExactIdPrefixAndPath(): void
    {
        $cwdA = $this->cwd() . '/project-a';
        $cwdB = $this->cwd() . '/project-b';

        $sessionA = SessionManager::create($cwdA);
        $this->converse($sessionA, 'hello in project A');

        $sessionB = SessionManager::create($cwdB);
        $this->converse($sessionB, 'hello in project B');

        // 1. Direct path
        $this->assertSame($sessionA->path, SessionManager::find($cwdA, $sessionA->path));

        // 2. Exact ID local match
        $this->assertSame($sessionA->path, SessionManager::find($cwdA, $sessionA->id));
        $this->assertSame($sessionA->path, SessionManager::findById($cwdA, $sessionA->id));

        // 3. Prefix match
        $prefixA = substr($sessionA->id, 0, 8);
        $this->assertSame($sessionA->path, SessionManager::find($cwdA, $prefixA));

        // 4. Global match for session in another project
        $this->assertSame($sessionB->path, SessionManager::find($cwdA, $sessionB->id));

        // 5. Unknown session ID returns null
        $this->assertNull(SessionManager::find($cwdA, 'non-existent-session-id'));
        $this->assertNull(SessionManager::find($cwdA, ''));
    }

    private function cwd(): string
    {
        return $this->home . '/project';
    }

    /** A question and an answer, which is what makes a session worth keeping. */
    private function converse(SessionManager $session, string $text): void
    {
        $session->append(new UserMessage([new TextContent($text)]));
        $session->append(new AssistantMessage(
            [new TextContent('re: ' . $text)],
            Api::AnthropicMessages,
            'anthropic',
            'm',
            new Usage(),
            StopReason::Stop,
        ));
    }
}
