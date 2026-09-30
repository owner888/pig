<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Timestamp;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionEntries;
use Pig\CodingAgent\Session\SessionInfoEntry;

/**
 * A line of the session file, which is pi's file.
 *
 * `SessionManagerTest` and `PiFormatTest` both go through this class, and between them they cover
 * every line pig itself writes — which is the gap this file exists for: **the fields pig writes
 * and pi does not.** A file written by pi, or by a pi old enough to predate a field, arrives here
 * with that field missing, and what a missing field *means* is a decision per field rather than
 * one rule. A mutation sweep put 71 mutations through this class and the default on every one of
 * those fields survived: `display`, `fromHook` on both summaries, and the moment a line names.
 */
final class SessionEntriesTest extends TestCase
{
    public function testAHookMessageThatSaysNothingAboutBeingShownIsShown(): void
    {
        $decoded = SessionEntries::decode([
            'type' => 'custom_message',
            'customType' => 'build',
            'content' => 'the build is broken',
        ]);

        // `display: false` is for a reminder injected before every turn, which is talking to the
        // model; the default has to be the other way round, or a hook that told somebody
        // something told nobody. pi omits the field when it is true.
        $this->assertInstanceOf(HookMessage::class, $decoded);
        $this->assertTrue($decoded->display);

        $hidden = SessionEntries::decode([
            'type' => 'custom_message',
            'customType' => 'build',
            'content' => 'and again',
            'display' => false,
        ]);

        $this->assertInstanceOf(HookMessage::class, $hidden);
        $this->assertFalse($hidden->display);
    }

    public function testACompactionThatSaysNothingAboutWhoWroteItIsPigsOwn(): void
    {
        $mine = SessionEntries::decode(['type' => 'compaction', 'summary' => 'we talked']);
        $theirs = SessionEntries::decode(['type' => 'compaction', 'summary' => 'we talked', 'fromHook' => true]);

        // pi's rule for its own field: "undefined/false if pi-generated". It decides whether
        // `Compaction::files()` carries the summary's file lists forward, so reading an absent
        // field as a hook's would drop every file a pi conversation had recorded reading.
        $this->assertInstanceOf(CompactionSummary::class, $mine);
        $this->assertFalse($mine->fromHook);
        $this->assertInstanceOf(CompactionSummary::class, $theirs);
        $this->assertTrue($theirs->fromHook);
    }

    public function testABranchSummaryThatSaysNothingAboutWhoWroteItIsPigsOwnToo(): void
    {
        $mine = SessionEntries::decode(['type' => 'branch_summary', 'summary' => 'where I got to']);
        $theirs = SessionEntries::decode([
            'type' => 'branch_summary',
            'summary' => 'where I got to',
            'fromHook' => true,
        ]);

        $this->assertInstanceOf(BranchSummary::class, $mine);
        $this->assertFalse($mine->fromHook);
        $this->assertInstanceOf(BranchSummary::class, $theirs);
        $this->assertTrue($theirs->fromHook);
    }

    public function testEveryLineComesBackWithTheMomentItNames(): void
    {
        $decoded = SessionEntries::decode([
            'type' => 'compaction',
            'summary' => 'we talked',
            'timestamp' => '2026-01-02T21:29:30.123Z',
        ]);

        // The timestamp is what orders the catch-up flush, so a line that lost its own is a note
        // that comes back in the wrong place in the conversation.
        $this->assertInstanceOf(CompactionSummary::class, $decoded);
        $this->assertSame(SessionEntries::millis('2026-01-02T21:29:30.123Z'), $decoded->timestamp);
    }

    public function testSessionInfoEntryEncodesAndDecodes(): void
    {
        $encoded = SessionEntries::encode(new SessionInfoEntry('Refactor auth module', 1_767_389_370_123), 'e1', 'root');
        $this->assertIsArray($encoded);
        $this->assertSame('session_info', $encoded['type']);
        $this->assertSame('Refactor auth module', $encoded['name']);
        $this->assertSame('e1', $encoded['id']);
        $this->assertSame('root', $encoded['parentId']);

        $decoded = SessionEntries::decode($encoded);
        $this->assertInstanceOf(SessionInfoEntry::class, $decoded);
        $this->assertSame('Refactor auth module', $decoded->name);
        $this->assertSame(1_767_389_370_123, $decoded->timestamp);
    }

    // ---- the moment itself ------------------------------------------------------------------

    public function testATimestampComesBackAsTheMomentItNamesRatherThanNow(): void
    {
        $millis = SessionEntries::millis('2026-01-02T21:29:30.123Z');

        $this->assertSame(1_767_389_370_123, $millis);
        // Round trip, because the two are the only readers of each other and the format is pi's.
        $this->assertSame('2026-01-02T21:29:30.123Z', SessionEntries::iso($millis));
    }

    public function testATimestampNothingCanReadIsNowAndNot1970(): void
    {
        $now = Timestamp::nowMs();

        foreach (['', 'the other day', null, 12_345] as $unreadable) {
            $millis = SessionEntries::millis($unreadable);

            // "Anything unreadable is now" is the only answer that is never wrong by years: a
            // conversation dated 1970 sorts below everything and reads as fifty years old.
            $this->assertGreaterThanOrEqual($now, $millis, var_export($unreadable, true));
            $this->assertLessThan($now + 60_000, $millis, var_export($unreadable, true));
        }
    }
}
