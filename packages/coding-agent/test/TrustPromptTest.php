<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Cli\TrustPrompt;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\TrustChoice;
use Pig\Tui\Ansi;
use Pig\Tui\Test\FakeTerminal;

final class TrustPromptTest extends TestCase
{
    use GlobalThemeFixture;

    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();
        $this->cwd = sys_get_temp_dir() . '/pig-trustprompt-' . bin2hex(random_bytes(4));
        mkdir($this->cwd . '/extensions/acme', 0o755, true);
        file_put_contents($this->cwd . '/extensions/acme/index.php', '<?php');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        unlink($this->cwd . '/extensions/acme/index.php');
        rmdir($this->cwd . '/extensions/acme');
        rmdir($this->cwd . '/extensions');
        rmdir($this->cwd);
    }

    /** @param list<string> $keys queued before the prompt opens — there is no "after" */
    private function ask(array $keys): array
    {
        $terminal = new FakeTerminal(100, 24);

        foreach ($keys as $key) {
            $terminal->queue($key);
        }

        $cwd = $this->cwd;
        $chosen = TrustPrompt::ask($cwd, ProjectTrust::choices($cwd), $terminal);

        return [$chosen, Ansi::strip($terminal->output())];
    }

    public function testEnterOnTheFirstRowIsTrust(): void
    {
        [$chosen, $screen] = $this->ask(["\r"]);

        $this->assertInstanceOf(TrustChoice::class, $chosen);
        $this->assertSame('Trust', $chosen->label);
        $this->assertTrue($chosen->trusted);
        $this->assertStringContainsString('Trust project folder?', $screen);
        $this->assertStringContainsString($this->cwd, $screen);
        // Why it is asking — with no `.pig/` in sight, the prompt otherwise reads as a mistake.
        $this->assertStringContainsString('It has: extensions/acme/index.php', $screen);
        // And the parent folder's whole path, which a 30-column label cap used to cut in half.
        $this->assertStringContainsString('Trust parent folder (' . dirname(realpath($this->cwd)) . ')', $screen);
    }

    public function testDownToTheParentFolderNamesIt(): void
    {
        [$chosen] = $this->ask(["\e[B", "\r"]);

        $parent = dirname(realpath($this->cwd));
        $this->assertSame("Trust parent folder ({$parent})", $chosen->label);
        $this->assertSame([$parent => true, realpath($this->cwd) => null], $chosen->updates);
    }

    public function testEscapeAnswersNothingWhichTheCallerReadsAsNo(): void
    {
        [$chosen] = $this->ask(["\e"]);

        $this->assertNull($chosen);
    }
}
