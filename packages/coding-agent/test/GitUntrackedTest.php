<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\GitUntracked;
use Pig\Tui\Process;

final class GitUntrackedTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pig-untracked-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        Process::run(['git', 'init', '-q'], cwd: $this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Process::run(['rm', '-rf', $this->dir]);
    }

    public function testTheFilesGitDiffCannotShowAreNamedAndTrackedOnesAreNot(): void
    {
        // `git diff` compares tracked files only, so a new file is not in it: `/diff` says "no
        // changes" while `git status` lists the file. The names, and only the names, go under the
        // diff; a modified tracked file is the diff's own business.
        file_put_contents($this->dir . '/kept.txt', "one\n");
        Process::run(['git', 'add', 'kept.txt'], cwd: $this->dir);
        Process::run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'one'], cwd: $this->dir);
        file_put_contents($this->dir . '/kept.txt', "two\n");
        file_put_contents($this->dir . '/test.txt', "new\n");
        mkdir($this->dir . '/sub');
        file_put_contents($this->dir . '/sub/also.txt', "new\n");

        $this->assertSame(['sub/also.txt', 'test.txt'], GitUntracked::files($this->dir));
        $this->assertSame('Untracked (not in the diff): sub/also.txt, test.txt', GitUntracked::note($this->dir));
    }

    public function testACleanTreeAndANonRepositoryBothSayNothing(): void
    {
        $this->assertSame('', GitUntracked::note($this->dir));

        $plain = $this->dir . '/plain';
        mkdir($plain);
        Process::run(['rm', '-rf', $this->dir . '/.git']);

        $this->assertSame([], GitUntracked::files($plain));
    }
}
