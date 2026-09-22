<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Cost;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\Compaction;
use Pig\CodingAgent\Session\CompactionSummary;

/** Deciding what to throw away, without a provider in sight. */
final class CompactionTest extends TestCase
{
    // ---- how full it is ------------------------------------------------------------

    public function testTheProvidersOwnTotalIsPreferredToAddingTheFiguresUp(): void
    {
        // A tokeniser knows things an estimate does not, so its own number wins whenever
        // it gave one.
        $this->assertSame(999, Compaction::contextTokens(new Usage(10, 20, 30, 40, 999)));
    }

    public function testWithNoTotalThePartsAreAddedUp(): void
    {
        $this->assertSame(100, Compaction::contextTokens(new Usage(10, 20, 30, 40, 0)));
    }

    public function testCompactionHappensBeforeTheWallRatherThanAtIt(): void
    {
        // 200k window, 16k reserved: at 190k there is no longer room for an answer, even
        // though the window is not full.
        $this->assertTrue(Compaction::shouldCompact(190_000, 200_000));
        $this->assertFalse(Compaction::shouldCompact(150_000, 200_000));
    }

    public function testAModelWithNoStatedWindowIsNeverCompactedFor(): void
    {
        // Guessing a window and compacting against the guess throws away a conversation
        // for a number nobody supplied.
        $this->assertFalse(Compaction::shouldCompact(1_000_000, 0));
    }

    public function testTheUsageThatCountsIsTheLastTurnThatFinished(): void
    {
        $messages = [
            self::assistant('first', 120),
            self::assistant('interrupted', 999, StopReason::Aborted),
            self::assistant('failed', 888, StopReason::Error),
        ];

        // An aborted turn reports whatever it had got to, which is not what the next
        // request will carry.
        $this->assertSame(120, Compaction::lastUsage($messages)?->totalTokens);
    }

    public function testNothingAnsweredYetIsNull(): void
    {
        $this->assertNull(Compaction::lastUsage([new UserMessage('hello')]));
    }

    // ---- where to cut ---------------------------------------------------------------

    public function testAShortConversationHasNothingOldEnoughToDrop(): void
    {
        $messages = [new UserMessage('hi'), self::assistant('hello'), new UserMessage('bye')];

        $this->assertSame(0, Compaction::cutPoint($messages));
    }

    public function testTheCutKeepsRoughlyTheBudgetAndDropsWhatIsOlder(): void
    {
        // Ten messages of about 250 tokens each; keeping 500 should leave the last two.
        $messages = [];

        for ($i = 0; $i < 10; $i++) {
            $messages[] = new UserMessage(str_repeat('x', 1_000));
        }

        $cut = Compaction::cutPoint($messages, 500);

        $this->assertSame(8, $cut);
    }

    public function testTheCutNeverLandsOnAToolResultAtAnyBudget(): void
    {
        // A tool result separated from the call that produced it is a request every
        // provider rejects outright — so whatever the budget works out to, the kept half
        // never starts with one. Every budget rather than one: the arithmetic that picks
        // the cut is exactly the kind of thing that is right for the number you tested.
        $messages = [
            new UserMessage(str_repeat('a', 4_000)),
            self::assistant(str_repeat('b', 4_000)),
            self::calling('c1', 'read', ['path' => 'a.php']),
            new ToolResultMessage('c1', 'read', [new TextContent(str_repeat('c', 4_000))]),
            new ToolResultMessage('c2', 'read', [new TextContent(str_repeat('d', 4_000))]),
            new UserMessage('thanks'),
        ];

        for ($budget = 1; $budget <= 6_000; $budget += 37) {
            $cut = Compaction::cutPoint($messages, $budget);

            $this->assertFalse(
                $messages[$cut] instanceof ToolResultMessage,
                "budget {$budget} cut at {$cut}, which is a tool result",
            );
        }
    }

    public function testACallAndItsResultsAreKeptTogether(): void
    {
        // Cutting at the message that made the call keeps the results that follow it,
        // which is the whole reason the cut is allowed to land there.
        $messages = [
            new UserMessage(str_repeat('a', 40_000)),
            self::calling('c1', 'read', ['path' => str_repeat('p', 400)]),
            new ToolResultMessage('c1', 'read', [new TextContent(str_repeat('c', 2_000))]),
            new UserMessage('thanks'),
        ];

        $cut = Compaction::cutPoint($messages, 550);
        $kept = array_slice($messages, $cut);

        $this->assertSame(1, $cut);
        $this->assertInstanceOf(ToolResultMessage::class, $kept[1]);
    }

    public function testAConversationThatIsNothingButToolResultsIsLeftAlone(): void
    {
        $messages = [
            new ToolResultMessage('c1', 'read', [new TextContent(str_repeat('c', 200_000))]),
            new ToolResultMessage('c2', 'read', [new TextContent(str_repeat('d', 200_000))]),
        ];

        // There is nowhere legal to cut, so nothing is cut — a wrong cut is worse than
        // a full window, because it fails the request outright.
        $this->assertSame(0, Compaction::cutPoint($messages, 100));
    }

    // ---- sizes ----------------------------------------------------------------------

    public function testEveryKindOfMessageHasASize(): void
    {
        $this->assertSame(25, Compaction::estimateTokens(new UserMessage(str_repeat('x', 100))));
        $this->assertSame(25, Compaction::estimateTokens(self::assistant(str_repeat('x', 100))));
        $this->assertSame(
            25,
            Compaction::estimateTokens(new ToolResultMessage('c', 'read', [new TextContent(str_repeat('x', 100))])),
        );
        $this->assertGreaterThan(0, Compaction::estimateTokens(new BashExecution('ls', 'a b c', 0)));
        $this->assertGreaterThan(0, Compaction::estimateTokens(new CompactionSummary('what happened')));
    }

    public function testThinkingAndToolArgumentsCountToo(): void
    {
        // They are sent, so they cost — a turn of pure thinking is not a free turn.
        $thinking = new AssistantMessage(
            [new ThinkingContent(str_repeat('t', 4_000), null)],
            Api::AnthropicMessages,
            'anthropic',
            'test',
            new Usage(),
            StopReason::Stop,
        );

        $this->assertSame(1_000, Compaction::estimateTokens($thinking));
    }

    // ---- what the summariser reads -----------------------------------------------------

    public function testTheConversationIsFlattenedIntoLabelledLines(): void
    {
        $text = Compaction::serialize([
            new UserMessage('fix the parser'),
            self::assistant('looking'),
            self::calling('c1', 'read', ['path' => 'parser.php']),
            new ToolResultMessage('c1', 'read', [new TextContent('<?php')]),
            new BashExecution('phpunit', 'ok', 0),
            new CompactionSummary('what came before'),
        ]);

        $this->assertStringContainsString('[User]: fix the parser', $text);
        $this->assertStringContainsString('[Assistant]: looking', $text);
        $this->assertStringContainsString('[Assistant tool calls]: read(path="parser.php")', $text);
        $this->assertStringContainsString('[Tool result]: <?php', $text);
        $this->assertStringContainsString('[Ran]: phpunit', $text);
        $this->assertStringContainsString('[Earlier summary]: what came before', $text);
    }

    public function testTheRequestIsADocumentAboutAConversationRatherThanTheConversation(): void
    {
        $request = Compaction::request([new UserMessage('hello')]);

        // Tagged and flattened, because a model handed a conversation answers it.
        $this->assertStringContainsString('<conversation>', $request);
        $this->assertStringContainsString('</conversation>', $request);
        $this->assertStringContainsString('## Goal', $request);
        $this->assertStringNotContainsString('<previous-summary>', $request);
    }

    public function testASecondCompactionUpdatesTheFirstSummaryRatherThanStartingOver(): void
    {
        $request = Compaction::request([new UserMessage('hello')], 'what came before');

        $this->assertStringContainsString('<previous-summary>', $request);
        $this->assertStringContainsString('PRESERVE all existing information', $request);
    }

    public function testWhatThePersonAskedToFocusOnIsPassedOn(): void
    {
        $request = Compaction::request([new UserMessage('hello')], null, 'the parser bug');

        $this->assertStringContainsString('Additional focus: the parser bug', $request);
    }

    public function testTheNewestSummaryIsTheOneTheNextOneUpdates(): void
    {
        $messages = [new CompactionSummary('older'), new UserMessage('x'), new CompactionSummary('newer')];

        $this->assertSame('newer', Compaction::previousSummary($messages));
        $this->assertNull(Compaction::previousSummary([new UserMessage('x')]));
    }

    // ---- which files -------------------------------------------------------------------

    public function testFilesAreListedByWhatHappenedToThemRatherThanByToolName(): void
    {
        [$read, $modified] = Compaction::files([
            self::calling('c1', 'read', ['path' => 'a.php']),
            self::calling('c2', 'read', ['path' => 'b.php']),
            self::calling('c3', 'edit', ['path' => 'b.php']),
            self::calling('c4', 'write', ['path' => 'c.php']),
            self::calling('c5', 'bash', ['command' => 'ls']),
        ]);

        // b.php was edited, so it is not also "read": what matters next is whether the
        // copy on disk still matches what was seen.
        $this->assertSame(['a.php'], $read);
        $this->assertSame(['b.php', 'c.php'], $modified);
    }

    public function testAnEarlierSummarysFilesCarryForward(): void
    {
        [$read, $modified] = Compaction::files([
            new CompactionSummary('earlier', ['old.php'], ['edited.php']),
            self::calling('c1', 'read', ['path' => 'new.php']),
        ]);

        // Or a file read before the last compaction disappears from the record entirely.
        $this->assertSame(['new.php', 'old.php'], $read);
        $this->assertSame(['edited.php'], $modified);
    }

    public function testTheSameFileReadTwiceIsListedOnce(): void
    {
        [$read] = Compaction::files([
            self::calling('c1', 'read', ['path' => 'a.php']),
            self::calling('c2', 'read', ['path' => 'a.php']),
        ]);

        $this->assertSame(['a.php'], $read);
    }

    // ---- the summary itself --------------------------------------------------------------

    public function testTheFileListsAreTaggedOntoTheProseTheModelReads(): void
    {
        $summary = new CompactionSummary('we fixed the parser', ['a.php'], ['b.php']);
        $text = $summary->toText();

        $this->assertStringContainsString('we fixed the parser', $text);
        $this->assertStringContainsString("<read-files>\na.php\n</read-files>", $text);
        $this->assertStringContainsString("<modified-files>\nb.php\n</modified-files>", $text);
    }

    public function testNoFilesMeansNoEmptyTags(): void
    {
        $this->assertStringNotContainsString('<read-files>', (new CompactionSummary('nothing happened'))->toText());
    }

    // ---- scaffolding -----------------------------------------------------------------------

    private static function assistant(string $text, int $total = 0, StopReason $stop = StopReason::Stop): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent($text)],
            Api::AnthropicMessages,
            'anthropic',
            'test',
            new Usage(0, 0, 0, 0, $total, new Cost()),
            $stop,
        );
    }

    /** @param array<string, mixed> $arguments */
    private static function calling(string $id, string $name, array $arguments): AssistantMessage
    {
        return new AssistantMessage(
            [new ToolCall($id, $name, $arguments)],
            Api::AnthropicMessages,
            'anthropic',
            'test',
            new Usage(),
            StopReason::ToolUse,
        );
    }
}
