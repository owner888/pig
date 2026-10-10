<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\FileLock;
use Pig\Test\AssertsThrows;
use RuntimeException;

/**
 * The lock a token renewal is taken under. Driven by a second `php` process holding the same
 * file, because a lock that only ever serialises one process is a lock nothing needed.
 */
final class FileLockTest extends TestCase
{
    use AssertsThrows;

    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pig-lock-' . bin2hex(random_bytes(4));
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Loop::reset();

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testAnotherProcessHoldingTheLockIsWaitedFor(): void
    {
        $lock = $this->dir . '/auth.json.lock';
        $held = self::holdFromAnotherProcess($lock, 0.6);

        $started = microtime(true);
        $ran = FileLock::hold($lock, static fn (): string => 'ran');
        $elapsed = microtime(true) - $started;

        $this->assertSame('ran', $ran);
        $this->assertGreaterThan(0.4, $elapsed, 'the other process had it first');
        proc_close($held);
    }

    public function testWaitingInAFiberLeavesTheLoopTurning(): void
    {
        $lock = $this->dir . '/auth.json.lock';
        $held = self::holdFromAnotherProcess($lock, 0.6);
        $ticks = 0;
        $timer = static function () use (&$ticks, &$timer): void {
            $ticks++;
            Loop::get()->delay(0.05, $timer);
        };
        Loop::get()->delay(0.05, $timer);

        Async::run(static fn (): mixed => FileLock::hold($lock, static fn (): null => null));

        // A blocking `flock()` would have stopped the loop for the whole wait: no ticks at all.
        $this->assertGreaterThan(3, $ticks);
        proc_close($held);
    }

    public function testGivingUpAfterTheWaitIsAnErrorThatNamesTheFile(): void
    {
        $lock = $this->dir . '/auth.json.lock';
        $held = self::holdFromAnotherProcess($lock, 2.0);

        $error = $this->assertThrows(RuntimeException::class, static fn (): mixed => FileLock::hold($lock, static fn (): null => null, 0.3));
        $this->assertStringContainsString($lock, $error->getMessage());
        proc_close($held);
    }

    public function testTheLockIsReleasedWhenTheWorkThrowsAndTheFileIsPrivate(): void
    {
        $lock = $this->dir . '/auth.json.lock';

        $this->assertThrows(RuntimeException::class, static fn (): mixed => FileLock::hold($lock, static fn (): null => throw new RuntimeException('inside')));
        $this->assertSame('ran', FileLock::hold($lock, static fn (): string => 'ran', 0.2), 'free again at once');
        $this->assertSame(0o600, fileperms($lock) & 0o777);
    }

    /**
     * A `php` that takes the lock and holds it for $seconds. Ready before this returns: it says
     * so on its standard output once `flock()` has succeeded.
     *
     * @return resource
     */
    private static function holdFromAnotherProcess(string $lock, float $seconds)
    {
        if (!is_dir(dirname($lock))) {
            mkdir(dirname($lock), 0o700, true);
        }

        $script = sprintf(
            '$h = fopen(%s, "c"); flock($h, LOCK_EX); echo "held\n"; usleep(%d); flock($h, LOCK_UN);',
            var_export($lock, true),
            (int) ($seconds * 1_000_000),
        );
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("held\n", fgets($pipes[1]));

        return $process;
    }
}
