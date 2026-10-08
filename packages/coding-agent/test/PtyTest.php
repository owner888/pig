<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\CodingAgent\Web\Pty\PtyManager;
use Pig\CodingAgent\Web\Pty\PtyProcess;

final class PtyTest extends TestCase
{
    protected function tearDown(): void
    {
        Loop::reset();
        parent::tearDown();
    }

    public function testPtyProcessCanSpawnAndReadOutput(): void
    {
        $output = '';
        $deferred = new Deferred();

        Async::run(function () use (&$output, $deferred): void {
            $pty = new PtyProcess(
                id: 'term-1',
                cwd: sys_get_temp_dir(),
                cols: 80,
                rows: 24,
                command: 'echo PTY_HELLO_WORLD && exit',
                onOutput: function (string $id, string $data) use (&$output): void {
                    $output .= $data;
                },
                onExit: function (string $id, ?int $code) use ($deferred): void {
                    if (!$deferred->isComplete()) {
                        $deferred->complete($code);
                    }
                },
            );

            $exitCode = $deferred->future->await();
            $this->assertStringContainsString('PTY_HELLO_WORLD', $output);
            $this->assertSame(0, $exitCode);
            $this->assertFalse($pty->isRunning());
        });
    }

    public function testPtyProcessAcceptsInteractiveInput(): void
    {
        $output = '';
        $deferred = new Deferred();

        Async::run(function () use (&$output, $deferred): void {
            $pty = new PtyProcess(
                id: 'term-interactive',
                cwd: sys_get_temp_dir(),
                cols: 80,
                rows: 24,
                onOutput: function (string $id, string $data) use (&$output, $deferred): void {
                    $output .= $data;
                    if (str_contains($output, 'PIG_PTY_REPLY_OK')) {
                        if (!$deferred->isComplete()) {
                            $deferred->complete(true);
                        }
                    }
                },
            );

            // Send an interactive command
            $pty->input("echo PIG_PTY_REPLY_OK\n");

            $ok = $deferred->future->await();
            $this->assertTrue($ok);
            $this->assertStringContainsString('PIG_PTY_REPLY_OK', $output);

            $pty->kill();
            $this->assertFalse($pty->isRunning());
        });
    }

    public function testPtyManagerCreatesAndKillsTerminals(): void
    {
        $outputs = [];

        Async::run(function () use (&$outputs): void {
            $mgr = new PtyManager(
                onOutput: function (string $id, string $data) use (&$outputs): void {
                    $outputs[$id] = ($outputs[$id] ?? '') . $data;
                },
            );

            $term = $mgr->create('t-1', sys_get_temp_dir(), 80, 24);
            $this->assertTrue($term->isRunning());
            $this->assertSame('t-1', $term->id);

            $list = $mgr->list();
            $this->assertCount(1, $list);
            $this->assertSame('t-1', $list[0]['id']);

            $term->resize(100, 30);
            $this->assertSame(100, $term->cols());
            $this->assertSame(30, $term->rows());

            $mgr->kill('t-1');
            $this->assertNull($mgr->get('t-1'));

            $mgr->dispose();
        });
    }

    public function testPtyManagerInputAndResizeGracefullyIgnoreUnknownOrExitedTerminals(): void
    {
        Async::run(function (): void {
            $mgr = new PtyManager();

            // Calling input or resize on a non-existent terminal id must not trigger Undefined array key warning
            $mgr->input('term-nonexistent', "data\n");
            $mgr->resize('term-nonexistent', 120, 40);

            $this->assertNull($mgr->get('term-nonexistent'));

            $mgr->dispose();
        });
    }

    public function testPtyManagerEnforcesCapacityLimit(): void
    {
        Async::run(function (): void {
            $mgr = new PtyManager();
            $created = [];

            for ($i = 0; $i < 16; $i++) {
                $created[] = $mgr->create("term-{$i}", sys_get_temp_dir(), 80, 24);
            }

            $this->assertCount(16, $mgr->list());

            // 17th should throw when all 16 are running
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('最多同时运行 16 个终端实例');

            try {
                $mgr->create('term-16', sys_get_temp_dir(), 80, 24);
            } finally {
                $mgr->dispose();
            }
        });
    }

    public function testPtyResizeBroadcastsToChildProcesses(): void
    {
        $output = '';
        $deferred = new Deferred();

        Async::run(function () use (&$output, $deferred): void {
            $pty = new PtyProcess(
                id: 'term-resize-broadcast',
                cwd: sys_get_temp_dir(),
                cols: 80,
                rows: 24,
                onOutput: function (string $id, string $data) use (&$output, $deferred): void {
                    $output .= $data;
                    if (str_contains($output, '40 120')) {
                        if (!$deferred->isComplete()) {
                            $deferred->complete(true);
                        }
                    }
                },
            );

            // Resize pty to 120x40 and ask a child what size its terminal is. (Not $COLUMNS: bash
            // keeps that to itself, so a child never had it — this case hung on it for good.)
            $pty->resize(120, 40);
            $pty->input("sh -c 'stty size'\n");

            $ok = $deferred->future->await();
            $this->assertTrue($ok);
            $this->assertSame(120, $pty->cols());
            $this->assertSame(40, $pty->rows());

            $pty->kill();
            $this->assertFalse($pty->isRunning());
        });
    }

    public function testPtyProcessClampsDimensionsAndHandlesLongInput(): void
    {
        $output = '';
        $deferred = new Deferred();

        Async::run(function () use (&$output, $deferred): void {
            // Pass extreme/invalid dimensions, should safely clamp to bounds
            $pty = new PtyProcess(
                id: 'term-clamp',
                cwd: sys_get_temp_dir(),
                cols: 2,
                rows: 1,
                onOutput: function (string $id, string $data) use (&$output, $deferred): void {
                    $output .= $data;
                    if (str_contains($output, 'LONG_INPUT_VERIFIED_OK')) {
                        if (!$deferred->isComplete()) {
                            $deferred->complete(true);
                        }
                    }
                },
            );

            // Should clamp min cols=10, min rows=4
            $this->assertSame(10, $pty->cols());
            $this->assertSame(4, $pty->rows());

            // Send a payload with multiple characters without truncation
            $largeString = str_repeat('X', 500);
            $pty->input("echo {$largeString} LONG_INPUT_VERIFIED_OK\n");

            $ok = $deferred->future->await();
            $this->assertTrue($ok);
            $this->assertStringContainsString($largeString, $output);

            $pty->kill();
            $this->assertFalse($pty->isRunning());
        });
    }
}
