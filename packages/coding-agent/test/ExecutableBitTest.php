<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;

/**
 * The commands `composer.json` lists under `bin` are run as files, so git has to record them as
 * executable. 2.0.3 shipped `bin/pig` as 100644 after a one-line comment edit was written back by
 * a tool that recreates the file: a checkout updated with `git pull` then answered
 * `permission denied`. Nothing else in the suite notices a mode, so this does.
 */
final class ExecutableBitTest extends TestCase
{
    public function testEveryCommandInComposerJsonIsExecutable(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows has no executable bit');
        }

        $root = dirname(__DIR__, 3);
        $manifest = json_decode((string) file_get_contents("{$root}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $commands = $manifest['bin'] ?? [];
        $this->assertNotSame([], $commands, 'composer.json lists no commands under "bin"');

        foreach ($commands as $command) {
            $this->assertFileExists("{$root}/{$command}");
            $this->assertTrue(
                is_executable("{$root}/{$command}"),
                "{$command} is not executable. Run: chmod +x {$command} && git update-index --chmod=+x {$command}",
            );
        }
    }

    public function testGitRecordsEveryCommandAsExecutable(): void
    {
        $root = dirname(__DIR__, 3);
        $git = trim((string) shell_exec('command -v git'));
        if ($git === '' || !file_exists("{$root}/.git")) {
            $this->markTestSkipped('not a git checkout');
        }

        $manifest = json_decode((string) file_get_contents("{$root}/composer.json"), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['bin'] ?? [] as $command) {
            $output = [];
            $code = 0;
            exec(escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' ls-files -s -- ' . escapeshellarg($command), $output, $code);
            $this->assertSame(0, $code, "git ls-files failed for {$command}");
            $this->assertNotSame([], $output, "{$command} is not tracked by git");

            // The staged mode is what the next commit — and everyone's `git pull` — gets.
            $this->assertStringStartsWith(
                '100755 ',
                $output[0],
                "git records {$command} as not executable. Run: chmod +x {$command} && git update-index --chmod=+x {$command}",
            );
        }
    }
}
