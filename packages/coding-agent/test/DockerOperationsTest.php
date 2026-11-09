<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Sandbox\DockerContainer;
use Pig\CodingAgent\Sandbox\DockerSandbox;
use Pig\CodingAgent\Accounts\User;
use Pig\CodingAgent\Tools\CommandStopped;
use Pig\CodingAgent\Tools\DockerOperations;
use Pig\CodingAgent\Tools\FileAccessError;
use Pig\CodingAgent\Tools\GrepTool;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\CodingAgent\Tools\ToolSet;

/**
 * A Docker account's tools (docs/dev/design/storage.md, phase 4 step 3b), against a stand-in for
 * `docker`: a script, written here, that drops `docker exec … <container>` and runs the rest on this
 * machine, and writes down every command it was given. What is checked is what is asked of Docker
 * and what comes back of it — Docker itself is not needed.
 */
final class DockerOperationsTest extends TestCase
{
    private string $dir;

    private string $docker;

    #[\Override]
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('the stand-in for docker is a bash script');
        }

        $this->dir = sys_get_temp_dir() . '/pig-docker-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/work/src', 0o777, true);
        $this->docker = $this->dir . '/docker';
        file_put_contents($this->docker, <<<'SH'
            #!/bin/bash
            echo "$*" >> "$(dirname "$0")/docker.log"
            case "$1" in
              inspect) [ -f "$(dirname "$0")/docker.running" ] && echo true && exit 0; exit 1;;
              run|start) touch "$(dirname "$0")/docker.running"; echo id; exit 0;;
              exec) shift; cwd=
                while [ $# -gt 0 ]; do case "$1" in -i|-t) shift;; -w) cwd=$2; shift 2;; -e) export "$2"; shift 2;; *) break;; esac; done
                shift; [ -n "$cwd" ] && cd "$cwd"; exec "$@";;
            esac
            exit 99
            SH);
        chmod($this->docker, 0o755);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ToolSet::useOperations(null);
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function operations(): DockerOperations
    {
        return new DockerOperations(new DockerContainer('pig-test', $this->docker));
    }

    /** @return list<string> what docker was asked, one line each */
    private function asked(): array
    {
        return is_file($this->dir . '/docker.log') ? explode("\n", trim((string) file_get_contents($this->dir . '/docker.log'))) : [];
    }

    public function testFilesAreReadAndWrittenInTheContainer(): void
    {
        $ops = $this->operations();
        $file = "{$this->dir}/work/a/b/note.txt";

        $ops->mkdir(dirname($file));
        $ops->writeFile($file, "one\ntwo\n");
        $bytes = random_bytes(300_000);
        $ops->writeFile("{$this->dir}/work/blob", $bytes);

        $this->assertSame("one\ntwo\n", $ops->readFile($file));
        $this->assertSame($bytes, $ops->readFile("{$this->dir}/work/blob"), 'binary, and more than a pipe holds');
        $this->assertSame("one\ntwo\n", implode('', [...$ops->createReadStream($file)]));
        $this->assertTrue($ops->exists($file));
        $this->assertFalse($ops->exists("{$this->dir}/nothing"));
        $this->assertTrue($ops->stat("{$this->dir}/work/a")->isDirectory());
        $this->assertFalse($ops->stat($file)->isDirectory());
        $this->assertSame(['blob', 'src'], array_values(array_diff($ops->readdir("{$this->dir}/work"), ['a'])));
        $this->assertTrue($ops->isDirectory("{$this->dir}/work"));
        $ops->access($file);

        foreach ($this->asked() as $line) {
            $this->assertStringStartsWith('exec ', $line, 'nothing touched the files but docker exec');
        }
    }

    public function testWhatCannotBeHadSaysWhy(): void
    {
        $ops = $this->operations();

        try {
            $ops->readFile("{$this->dir}/nothing");
            $this->fail('no file');
        } catch (FileAccessError $error) {
            $this->assertSame(FileAccessError::FAILED, $error->kind);
            $this->assertStringContainsString('No such file or directory', $error->getMessage());
        }

        try {
            $ops->access("{$this->dir}/nothing");
            $this->fail('no file');
        } catch (FileAccessError $error) {
            $this->assertSame(FileAccessError::NOT_FOUND, $error->kind);
        }

        $this->expectException(FileAccessError::class);
        $ops->stat("{$this->dir}/nothing");
    }

    public function testAnImageIsKnownByItsFirstBytes(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32);
        file_put_contents("{$this->dir}/work/i.png", $png);

        $this->assertSame('image/png', $this->operations()->detectImageMimeType("{$this->dir}/work/i.png"));
        $this->assertNull($this->operations()->detectImageMimeType("{$this->dir}/work/src"));
    }

    public function testBashRunsThereWithPigsVariablesOnly(): void
    {
        self::needsSetsid();
        $out = '';

        // A closure, not `fn`: an arrow function would hand the callback a copy of `$out`.
        $exit = Async::run(function () use (&$out): ?int {
            return $this->operations()->exec(
                'echo "[$PIG_X][$SECRET]"; pwd; echo err >&2; exit 3',
                "{$this->dir}/work",
                static function (string $chunk) use (&$out): void {
                    $out .= $chunk;
                },
                env: ['PIG_X' => 'pig', 'SECRET' => 'a key'],
            );
        });

        $this->assertSame(3, $exit);
        $this->assertStringContainsString('[pig][]', $out, 'the host\'s variables stay out');
        $this->assertStringContainsString("{$this->dir}/work", $out);
        $this->assertStringContainsString('err', $out);
    }

    public function testStoppingACommandStopsWhatItStartedInThere(): void
    {
        self::needsSetsid();
        $marker = 'pig-docker-' . bin2hex(random_bytes(4));
        $abort = new AbortController();
        $started = microtime(true);

        try {
            Async::run(function () use ($abort, $marker): void {
                Loop::get()->delay(0.5, static fn () => $abort->abort());
                $this->operations()->exec("sleep 30 & exec -a {$marker} sleep 30", "{$this->dir}/work", static function (): void {
                }, $abort->signal);
            });
            $this->fail('stopped');
        } catch (CommandStopped $stopped) {
            $this->assertFalse($stopped->timedOut);
        }

        $this->assertLessThan(10, microtime(true) - $started);
        usleep(300_000);
        // `[p]ig-…`: the shell running pgrep has the marker in its own command line, the pattern does not match it.
        $this->assertSame('', trim((string) shell_exec('pgrep -f ' . escapeshellarg('[p]' . substr($marker, 1)))), 'nothing it started is left');
    }

    public function testGrepRunsRipgrepThereAndReadsTheContextThere(): void
    {
        if (trim((string) shell_exec('command -v rg')) === '') {
            self::markTestSkipped('needs rg');
        }

        file_put_contents("{$this->dir}/work/src/a.php", "one\nneedle here\nthree\n");
        $result = (new GrepTool("{$this->dir}/work", $this->operations()))->execute('1', ['pattern' => 'needle', 'context' => 1]);

        $this->assertStringContainsString("src/a.php:2: needle here\nsrc/a.php-3- three", $result->content[0]->text);
        $this->assertNotEmpty(array_filter($this->asked(), static fn (string $line): bool => str_contains($line, ' rg --no-config')), 'rg ran through docker exec');
        $this->assertNotEmpty(array_filter($this->asked(), static fn (string $line): bool => str_contains($line, ' cat -- ')), 'and the context was read there');
    }

    public function testToolSetGivesEveryToolTheContainersOperations(): void
    {
        ToolSet::useOperations($this->operations());
        file_put_contents("{$this->dir}/work/hello.txt", "hi\n");

        $read = ToolSet::one("{$this->dir}/work", 'read');
        $this->assertInstanceOf(ReadTool::class, $read);
        $this->assertStringContainsString('hi', $read->execute('1', ['path' => 'hello.txt'])->content[0]->text);
        $this->assertNotEmpty(array_filter($this->asked(), static fn (string $line): bool => str_contains($line, 'hello.txt')));
    }

    public function testAnAccountsContainerIsMadeOnceThenFound(): void
    {
        $sandbox = new DockerSandbox('pig-sandbox', "{$this->dir}/workspaces", $this->docker);
        $bob = new User('0123456789abcdef', 'bob', false, User::DOCKER);

        $container = Async::run(static fn (): DockerContainer => $sandbox->ensure($bob));
        Async::run(static fn (): DockerContainer => $sandbox->ensure($bob));

        $workspace = "{$this->dir}/workspaces/bob-01234567";
        $this->assertSame('pig-0123456789abcdef', $container->name);
        $this->assertDirectoryExists($workspace);
        $runs = array_values(array_filter($this->asked(), static fn (string $line): bool => str_starts_with($line, 'run ')));
        $this->assertCount(1, $runs, 'made once, found the second time');
        $this->assertStringContainsString("-v {$workspace}:{$workspace} -w {$workspace}", $runs[0], 'mounted at the same path');
        $this->assertStringContainsString('--pull never', $runs[0]);
        $this->assertStringContainsString('pig-sandbox sleep infinity', $runs[0]);

        mkdir("{$workspace}/app");
        symlink('/etc', "{$workspace}/etc");
        $this->assertTrue($sandbox->contains($bob, "{$workspace}/app"));
        $this->assertFalse($sandbox->contains($bob, "{$workspace}/etc"), 'a link out of it is out of it');
        $this->assertFalse($sandbox->contains($bob, $this->dir));
    }

    private static function needsSetsid(): void
    {
        if (trim((string) shell_exec('command -v setsid')) === '') {
            self::markTestSkipped('needs util-linux setsid, which the container has and macOS does not');
        }
    }
}
