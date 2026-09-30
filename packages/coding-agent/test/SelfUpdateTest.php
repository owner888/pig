<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\SelfUpdate;

final class SelfUpdateTest extends TestCase
{
    public function testHelpOutputsUsageAndReturnsZero(): void
    {
        $updater = new SelfUpdate();

        ob_start();
        $code = $updater->run(['--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage: pig update', $output);
        $this->assertStringContainsString('--self', $output);
        $this->assertStringContainsString('--models', $output);
        $this->assertStringContainsString(SelfUpdate::CHANGELOG_URL, $output);
    }

    public function testShortHelpOptionOutputsUsage(): void
    {
        $updater = new SelfUpdate();

        ob_start();
        $code = $updater->run(['-h']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage: pig update', $output);
    }

    public function testUpdatePigExecutesExpectedCommands(): void
    {
        $executed = [];
        $runner = static function (array $command, ?string $cwd = null) use (&$executed): int {
            $executed[] = [$command, $cwd];

            return 0;
        };

        $updater = new SelfUpdate($runner);

        ob_start();
        $code = $updater->run();
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertNotEmpty($executed);
        $this->assertStringContainsString('pig has been updated', $output);
        $this->assertStringContainsString(SelfUpdate::CHANGELOG_URL, $output);
    }

    public function testFailingCommandReturnsNonZeroStatus(): void
    {
        $runner = static function (array $command, ?string $cwd = null): int {
            return 127;
        };

        $updater = new SelfUpdate($runner);

        ob_start();
        $code = $updater->run();
        ob_end_clean();

        $this->assertSame(127, $code);
    }
}
