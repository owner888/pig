<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\TrustPrompt;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\TrustChoice;
use Pig\Tui\Ansi;
use Pig\Tui\Test\FakeTerminal;

final class TrustPromptTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /** @param list<string> $keys queued before the prompt opens — there is no "after" */
    private function ask(array $keys): array
    {
        $terminal = new FakeTerminal(100, 24);

        foreach ($keys as $key) {
            $terminal->queue($key);
        }

        $cwd = '/Users/dev/work/acme';
        $chosen = TrustPrompt::ask($cwd, ProjectTrust::choices($cwd), Palette::dark(true), $terminal);

        return [$chosen, Ansi::strip($terminal->output())];
    }

    public function testEnterOnTheFirstRowIsTrust(): void
    {
        [$chosen, $screen] = $this->ask(["\r"]);

        $this->assertInstanceOf(TrustChoice::class, $chosen);
        $this->assertSame('Trust', $chosen->label);
        $this->assertTrue($chosen->trusted);
        $this->assertStringContainsString('Trust project folder?', $screen);
        $this->assertStringContainsString('/Users/dev/work/acme', $screen);
    }

    public function testDownToTheParentFolderNamesIt(): void
    {
        [$chosen] = $this->ask(["\e[B", "\r"]);

        $this->assertSame('Trust parent folder (/Users/dev/work)', $chosen->label);
        $this->assertSame(['/Users/dev/work' => true, '/Users/dev/work/acme' => null], $chosen->updates);
    }

    public function testEscapeAnswersNothingWhichTheCallerReadsAsNo(): void
    {
        [$chosen] = $this->ask(["\e"]);

        $this->assertNull($chosen);
    }
}
