<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Logger;

final class LoggerTest extends TestCase
{
    private string $tempDir;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Logger::reset();
        $this->tempDir = sys_get_temp_dir() . '/pig-log-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Logger::reset();
        $files = glob("{$this->tempDir}/*") ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testDefaultLevelIsInfo(): void
    {
        Logger::init(enabled: true, logDir: $this->tempDir);
        $this->assertSame(Logger::LEVEL_INFO, Logger::getLevel());
        $this->assertTrue(Logger::isLevelEnabled('INFO'));
        $this->assertTrue(Logger::isLevelEnabled('WARNING'));
        $this->assertTrue(Logger::isLevelEnabled('ERROR'));
        $this->assertFalse(Logger::isLevelEnabled('DEBUG'));
        $this->assertFalse(Logger::isLevelEnabled('VERBOSE'));
    }

    public function testLevelFilteringRespectsMinLevel(): void
    {
        $captured = [];
        Logger::init(enabled: true, logDir: $this->tempDir, minLevel: 'WARNING');
        Logger::setConsoleOutput(false);
        Logger::addHandler('test', function (string $level, string $msg) use (&$captured): void {
            $captured[] = "{$level}: {$msg}";
        });

        Logger::debug('debug message');
        Logger::info('info message');
        Logger::warning('warning message');
        Logger::error('error message');

        $this->assertSame([
            'WARNING: warning message',
            'ERROR: error message',
        ], $captured);
    }

    public function testLogPersistsToFileWithFormattedPrefixAndContext(): void
    {
        Logger::init(enabled: true, showTimestamp: false, logDir: $this->tempDir, minLevel: 'DEBUG');
        Logger::setConsoleOutput(false); // suppress console in test

        Logger::info('Session started', ['session' => 'test-123']);
        Logger::debug('Tool executed', 'detailed string trace');

        $logFile = $this->tempDir . '/pig-' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('[INFO   ] Session started {"session":"test-123"}', $content);
        $this->assertStringContainsString('[DEBUG  ] Tool executed detailed string trace', $content);
    }

    public function testTimersMeasureElapsedDuration(): void
    {
        $captured = [];
        Logger::init(enabled: true, logDir: $this->tempDir, minLevel: 'DEBUG');
        Logger::setConsoleOutput(false);
        Logger::addHandler('test', function (string $level, string $msg) use (&$captured): void {
            $captured[] = $msg;
        });

        Logger::time('perf');
        usleep(5000); // 5ms
        Logger::timeEnd('perf');

        $this->assertCount(2, $captured);
        $this->assertSame("Timer 'perf' started", $captured[0]);
        $this->assertMatchesRegularExpression("/Timer 'perf': \d+\.\d+ ms/", $captured[1]);
    }

    public function testDumpOutputsFormattedStructureUnderDebug(): void
    {
        $captured = [];
        Logger::init(enabled: true, logDir: $this->tempDir, minLevel: 'DEBUG');
        Logger::setConsoleOutput(false);
        Logger::addHandler('test', function (string $level, string $msg) use (&$captured): void {
            $captured[] = $msg;
        });

        Logger::dump('user', ['name' => 'pig', 'role' => 'admin']);

        $this->assertCount(1, $captured);
        $this->assertStringContainsString("user: Array", $captured[0]);
        $this->assertStringContainsString("[name] => pig", $captured[0]);
    }

    public function testRotationCleansUpExpiredLogFiles(): void
    {
        Logger::init(enabled: true, logDir: $this->tempDir);
        Logger::setConsoleOutput(false);

        $oldFile = "{$this->tempDir}/pig-2020-01-01.log";
        $newFile = "{$this->tempDir}/pig-" . date('Y-m-d') . '.log';

        file_put_contents($oldFile, "old log\n");
        file_put_contents($newFile, "new log\n");

        // Manually set mtime on oldFile to 10 days ago
        touch($oldFile, time() - (10 * 86400));

        Logger::rotateLogs($this->tempDir);

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }
}
