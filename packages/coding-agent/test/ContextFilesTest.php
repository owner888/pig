<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\ContextFiles;

/**
 * The `AGENTS.md` files that apply where the agent is working.
 *
 * The walk itself is covered through `SystemPromptTest`; what is here is the half that had no
 * answer — a file that is there and cannot be read.
 */
final class ContextFilesTest extends TestCase
{
    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-context-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home', 0o755, true);
        mkdir($this->root . '/project', 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['/home', '/project', ''] as $suffix) {
            $directory = $this->root . $suffix;

            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    chmod($file, 0o644);
                    unlink($file);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testAReadableFileIsLoadedAndSaysNothing(): void
    {
        file_put_contents($this->root . '/project/AGENTS.md', 'be careful');

        [$files, $warnings] = ContextFiles::loadWithWarnings($this->root . '/project', $this->root . '/home');

        $this->assertCount(1, $files);
        $this->assertSame('be careful', $files[0]->content);
        $this->assertSame([], $warnings);
    }

    public function testAFileThatCannotBeReadIsSaidRatherThanSkippedInSilence(): void
    {
        $path = $this->root . '/project/AGENTS.md';
        file_put_contents($path, 'be careful');
        chmod($path, 0o000);

        if (is_readable($path)) {
            // root reads whatever it likes, so there is nothing to reproduce here.
            $this->markTestSkipped('cannot make a file unreadable as this user');
        }

        [$files, $warnings] = ContextFiles::loadWithWarnings($this->root . '/project', $this->root . '/home');

        // The agent is now working without instructions the person wrote. Every other loader in
        // this repository hands such a problem back for `bin/pig` to print; this one swallowed it.
        $this->assertSame([], $files);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('AGENTS.md could not be read', $warnings[0]);
    }

    public function testAnUnreadableAgentsFileDoesNotHideTheClaudeOneBesideIt(): void
    {
        $agents = $this->root . '/project/AGENTS.md';
        file_put_contents($agents, 'the one that cannot be read');
        file_put_contents($this->root . '/project/CLAUDE.md', 'the one that can');
        chmod($agents, 0o000);

        if (is_readable($agents)) {
            $this->markTestSkipped('cannot make a file unreadable as this user');
        }

        [$files, $warnings] = ContextFiles::loadWithWarnings($this->root . '/project', $this->root . '/home');

        // Falling through is upstream's behaviour and the useful one: the second name exists
        // precisely so a project that has one and not the other still works.
        $this->assertCount(1, $files);
        $this->assertSame('the one that can', $files[0]->content);
        $this->assertCount(1, $warnings);
    }

    public function testLoadStillAnswersWithJustTheFiles(): void
    {
        file_put_contents($this->root . '/project/AGENTS.md', 'be careful');

        // `Prompt\SystemPrompt` calls this one, so its shape is fixed.
        $files = ContextFiles::load($this->root . '/project', $this->root . '/home');

        $this->assertCount(1, $files);
        $this->assertSame('be careful', $files[0]->content);
    }
}
