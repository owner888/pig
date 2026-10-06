<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\Label;
use Pig\CodingAgent\Session\ModelChange;
use Pig\CodingAgent\Session\SessionCodec;

/**
 * The app message kinds only pig has, as they go over the wire.
 *
 * `SessionEntries` writes the *line*; this writes the *message* inside one, and for the three LLM
 * roles it delegates to `MessageJson`. What is left is pig's own — a compaction summary, a branch
 * summary, a hook message and a typed `!command` — and the one reader of them in that shape is `RpcEvents`, so a
 * host is the thing on the other side of every default here.
 *
 * Which is why the defaults are the subject: a host sends back what it was given, minus whatever
 * its own encoder dropped for being false. A mutation sweep put 43 mutations through this class and
 * every one of those defaults survived, along with the `default` arm that refuses a role this pig
 * does not know; `HookMessage` joined the same wire after Web was found dropping it.
 */
final class SessionCodecTest extends TestCase
{
    public function testARoleThisPigDoesNotKnowIsNothingRatherThanAnEmptyMessage(): void
    {
        // Null is what every caller checks for. An empty string is a value, so it would be added
        // to the conversation as a message that says nothing and cannot be drawn.
        $this->assertNull(SessionCodec::decode(['role' => 'somethingNewer', 'text' => 'hello']));
        $this->assertNull(SessionCodec::decode([]));
    }

    public function testSomethingThatIsNotAMessageIsNotEncodedAsOne(): void
    {
        // A label, the model that was being used, a hook's private note: each is a line of its
        // own with its own `type`, and `SessionEntries` writes those itself. Null is how this
        // says so — anything else and a label would be written as `message: <that>`.
        $this->assertNull(SessionCodec::encode(new Label('zzzzzzzz', 'the start')));
        $this->assertNull(SessionCodec::encode(new ModelChange('anthropic', 'claude-x')));
    }

    public function testAnLlmMessageStillGoesThroughTheSharedEncoder(): void
    {
        $encoded = SessionCodec::encode(new UserMessage('a question'));

        $this->assertIsArray($encoded);
        $this->assertSame('user', $encoded['role'] ?? null);

        $back = SessionCodec::decode($encoded);

        $this->assertInstanceOf(UserMessage::class, $back);
    }

    public function testAHooksMessageGoesOverTheWireWholeRatherThanBeingDropped(): void
    {
        $message = new HookMessage('build', [new TextContent('log'), new ImageContent('aGVsbG8=', 'image/png')],
            display: false, details: ['code' => 3], timestamp: 1234);
        $encoded = SessionCodec::encode($message);

        $this->assertIsArray($encoded);
        $this->assertSame('custom', $encoded['role']);
        $this->assertSame('build', $encoded['customType']);
        $this->assertFalse($encoded['display']);
        $this->assertSame(['code' => 3], $encoded['details']);
        $this->assertCount(2, $encoded['content']);
        $this->assertEquals($message, SessionCodec::decode($encoded));
    }

    public function testAHooksMessageWithNoDisplayFlagIsShown(): void
    {
        $message = SessionCodec::decode(['role' => 'custom', 'customType' => 'build', 'content' => []]);
        $this->assertInstanceOf(HookMessage::class, $message);
        $this->assertTrue($message->display);
    }

    public function testACompactionThatSaysNothingAboutWhoWroteItIsPigsOwn(): void
    {
        $mine = SessionCodec::decode(['role' => 'compactionSummary', 'summary' => 'we talked']);

        $this->assertInstanceOf(CompactionSummary::class, $mine);
        $this->assertFalse($mine->fromHook);

        $theirs = SessionCodec::decode([
            'role' => 'compactionSummary',
            'summary' => 'we talked',
            'fromHook' => true,
        ]);

        $this->assertInstanceOf(CompactionSummary::class, $theirs);
        $this->assertTrue($theirs->fromHook);
    }

    public function testASummaryThatNamesNoCutKeepsNothingRatherThanKeepingFromTheStart(): void
    {
        $decoded = SessionCodec::decode(['role' => 'compactionSummary', 'summary' => 'all of it']);

        // Null is "the kept part starts nowhere" — a summary standing in for everything before
        // it. An empty string is an entry id that matches no entry, which keeps nothing either
        // and says something different about why.
        $this->assertInstanceOf(CompactionSummary::class, $decoded);
        $this->assertNull($decoded->firstKeptEntryId);
    }

    public function testABranchSummaryThatNamesNoOriginCameFromNowhere(): void
    {
        $decoded = SessionCodec::decode(['role' => 'branchSummary', 'summary' => 'where I got to']);

        $this->assertInstanceOf(BranchSummary::class, $decoded);
        $this->assertNull($decoded->fromId);
        $this->assertFalse($decoded->fromHook);
    }

    public function testACommandThatSaysNothingAboutHowItEndedRanToTheEnd(): void
    {
        $decoded = SessionCodec::decode(['role' => 'bashExecution', 'command' => 'ls', 'output' => 'a']);

        // Both are drawn: `(cancelled)` and the truncation notice. Reading an absent field as
        // true tells somebody a command they watched finish was stopped, and points them at a
        // spill file that was never written.
        $this->assertInstanceOf(BashExecution::class, $decoded);
        $this->assertFalse($decoded->cancelled);
        $this->assertFalse($decoded->truncated);
        $this->assertNull($decoded->exitCode);
        $this->assertNull($decoded->spillPath);
    }

    public function testEveryOneOfThemCarriesTheMomentItNames(): void
    {
        $expected = [
            'compactionSummary' => CompactionSummary::class,
            'branchSummary' => BranchSummary::class,
            'bashExecution' => BashExecution::class,
        ];

        foreach ($expected as $role => $class) {
            $decoded = SessionCodec::decode(['role' => $role, 'timestamp' => 1_767_389_370_123]);

            $this->assertInstanceOf($class, $decoded, $role);
            $this->assertSame(1_767_389_370_123, $decoded->timestamp, $role);
        }
    }
}
