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
}
