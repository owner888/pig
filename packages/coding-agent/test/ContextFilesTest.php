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
        exec('chmod -R u+rw ' . escapeshellarg($this->root) . ' && rm -rf ' . escapeshellarg($this->root));
    }

    /** @return list<string> the loaded files' paths, relative to the root */
    private function loaded(string $cwd): array
    {
        return array_map(
            fn (object $file): string => substr($file->path, strlen($this->root) + 1),
            ContextFiles::load($cwd, $this->root . '/home'),
        );
    }

    public function testUpstreamsFiveNamesInOrderOneFilePerDirectory(): void
    {
        foreach (['CLAUDE.MD', 'CLAUDE.md', 'AGENTS.MD', 'AGENTS.md', 'AGENTS.override.md'] as $name) {
            file_put_contents($this->root . '/project/' . $name, $name);
            $first = $this->loaded($this->root . '/project');

            // On a case-insensitive filesystem `AGENTS.MD` *is* `AGENTS.md`; either spelling is
            // the second-best, and the override always wins.
            $this->assertCount(1, $first);
            $this->assertSame(strtolower('project/' . $name), strtolower($first[0]));
        }
    }

    public function testAnOverrideReplacesOnlyItsOwnDirectoryAndTheAgentDirectoryComesFirst(): void
    {
        mkdir($this->root . '/project/pkg', 0o755, true);
        file_put_contents($this->root . '/home/AGENTS.md', 'mine');
        file_put_contents($this->root . '/project/AGENTS.md', 'root');
        file_put_contents($this->root . '/project/pkg/AGENTS.md', 'pkg');
        file_put_contents($this->root . '/project/pkg/AGENTS.override.md', 'pkg override');

        $this->assertSame(['home/AGENTS.md', 'project/AGENTS.md', 'project/pkg/AGENTS.override.md'], $this->loaded($this->root . '/project/pkg'));
    }

    public function testANestedLinkedWorktreeDoesNotLoadTheMainCheckoutsCopyOfItsOwnFile(): void
    {
        // `git worktree add .worktrees/feat` laid out by hand: the main checkout's `.git` directory,
        // the worktree's `.git` file pointing into `.git/worktrees/feat`, and its `commondir`.
        $main = $this->root . '/project';
        $worktree = $main . '/.worktrees/feat';
        mkdir($main . '/.git/worktrees/feat', 0o755, true);
        mkdir($worktree, 0o755, true);
        file_put_contents($main . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($main . '/.git/worktrees/feat/HEAD', "ref: refs/heads/feat\n");
        file_put_contents($main . '/.git/worktrees/feat/commondir', "../..\n");
        file_put_contents($worktree . '/.git', 'gitdir: ' . realpath($main) . "/.git/worktrees/feat\n");
        file_put_contents($main . '/AGENTS.md', 'main');
        file_put_contents($worktree . '/AGENTS.md', 'feat');

        $this->assertSame(['project/.worktrees/feat/AGENTS.md'], $this->loaded($worktree));

        // An ordinary repository's own file and a subdirectory under it are untouched.
        mkdir($main . '/src', 0o755, true);
        $this->assertSame(['project/AGENTS.md'], $this->loaded($main . '/src'));
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
