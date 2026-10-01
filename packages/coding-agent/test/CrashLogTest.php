<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\CrashLog;
use RuntimeException;

final class CrashLogTest extends TestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/pig-crash-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        $this->path = $dir . '/crashes.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->path)));
    }

    public function testARecordCarriesWhatABugReportNeedsAndTheFileKeepsFive(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $record = CrashLog::record('fatal_error', new RuntimeException("boom {$i}"), '/s/f.jsonl', '/work', $this->path);
            $this->assertNotNull($record);
        }

        $records = CrashLog::read($this->path);

        $this->assertCount(5, $records);
        $this->assertSame('boom 3', $records[0]['message'], 'the oldest two fell off');
        $this->assertSame('boom 7', $records[4]['message']);
        $this->assertSame('fatal_error', $records[4]['kind']);
        $this->assertSame('/s/f.jsonl', $records[4]['sessionFile']);
        $this->assertSame('/work', $records[4]['cwd']);
        $this->assertStringContainsString('RuntimeException: boom 7', $records[4]['stack']);
        $this->assertStringContainsString('in ' . __FILE__ . ':', $records[4]['stack'], 'the throw site, which the trace alone leaves out');
    }

    public function testAMessagelessThrowableIsNamedByItsClass(): void
    {
        CrashLog::record('loop_error', new \LogicException(), null, '/work', $this->path);

        $this->assertSame('LogicException', CrashLog::read($this->path)[0]['message']);
    }

    public function testAFileThatIsNotACrashLogReadsAsEmptyRatherThanThrowing(): void
    {
        file_put_contents($this->path, 'not json');
        $this->assertSame([], CrashLog::read($this->path));

        file_put_contents($this->path, '[{"timestamp":"x","message":"ok"},{"junk":1},"str"]');
        $this->assertCount(1, CrashLog::read($this->path));
    }

    public function testRecordingNeverThrowsEvenWhereItCannotWrite(): void
    {
        // A crash log that throws is a second crash on top of the first.
        $this->assertNull(CrashLog::record('fatal_error', new RuntimeException('x'), null, '/', '/dev/null/nope/crashes.json'));
    }

    public function testTheNewestUnnotifiedCrashIsHandedOverOnceAndOldOnesAreNot(): void
    {
        CrashLog::record('fatal_error', new RuntimeException('first'), null, '/', $this->path);
        CrashLog::record('fatal_error', new RuntimeException('second'), null, '/', $this->path);

        $crash = CrashLog::takeUnnotified($this->path);
        $this->assertSame('second', $crash['message'] ?? null);

        // All of them are marked, so the next start says nothing.
        $this->assertNull(CrashLog::takeUnnotified($this->path));
        $this->assertTrue(CrashLog::read($this->path)[0]['notified']);

        // And one from eight days ago is too old to greet somebody with.
        CrashLog::record('fatal_error', new RuntimeException('stale'), null, '/', $this->path);
        $this->assertNull(CrashLog::takeUnnotified($this->path, time() + 8 * 24 * 3600));
    }

    public function testClearRemovesTheFileAndIsHarmlessWithoutOne(): void
    {
        CrashLog::record('fatal_error', new RuntimeException('x'), null, '/', $this->path);
        CrashLog::clear($this->path);
        $this->assertFileDoesNotExist($this->path);

        CrashLog::clear($this->path);
        $this->assertSame([], CrashLog::read($this->path));
    }

    public function testTheNoticeNamesTheCrashAndTheWayToReportIt(): void
    {
        $notice = CrashLog::notice(['timestamp' => '2026-10-01T08:00:00Z', 'message' => 'Grapheme split failed']);

        $this->assertStringContainsString('pig crashed on 2026-10-01', $notice);
        $this->assertStringContainsString('(Grapheme split failed)', $notice);
        $this->assertStringContainsString('Run /bug', $notice);
    }
}
