<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookLoader;

final class HookLoaderTest extends TestCase
{
    private string $home = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-hooks-home-' . bin2hex(random_bytes(4));
        $this->project = sys_get_temp_dir() . '/pig-hooks-project-' . bin2hex(random_bytes(4));

        mkdir($this->home . '/hooks', 0o777, true);
        mkdir($this->project . '/.pig/hooks', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->home, $this->project] as $root) {
            foreach (['/hooks', '/.pig/hooks', '/.pig', ''] as $suffix) {
                $directory = $root . $suffix;

                if (!is_dir($directory)) {
                    continue;
                }

                foreach (glob($directory . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }

                rmdir($directory);
            }
        }
    }

    private function write(string $directory, string $name, string $body): string
    {
        $path = $directory . '/' . $name;
        file_put_contents($path, $body);

        return $path;
    }

    public function testAFileThatReturnsAFactoryLoads(): void
    {
        $this->write($this->home . '/hooks', 'a.php', <<<'PHP'
            <?php
            return function ($pi): void {
                $pi->on('tool_call', fn () => null);
            };
            PHP);

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $hooks);
        $this->assertCount(1, $hooks[0]->api->handlers('tool_call'));
    }

    public function testBothRootsAreReadWithTheUserFirst(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php return function ($pi): void {};');
        $this->write($this->project . '/.pig/hooks', 'b.php', '<?php return function ($pi): void {};');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(2, $hooks);
        $this->assertSame('a.php', basename($hooks[0]->path));
        $this->assertSame('b.php', basename($hooks[1]->path));
    }

    public function testFilesInOneFolderLoadInNameOrder(): void
    {
        foreach (['z.php', 'm.php', 'a.php'] as $name) {
            $this->write($this->home . '/hooks', $name, '<?php return function ($pi): void {};');
        }

        [$hooks] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame(
            ['a.php', 'm.php', 'z.php'],
            array_map(static fn ($hook): string => basename($hook->path), $hooks),
        );
    }

    public function testOnlyPhpFilesAreLoaded(): void
    {
        $this->write($this->home . '/hooks', 'notes.md', 'not a hook');
        $this->write($this->home . '/hooks', 'a.php', '<?php return function ($pi): void {};');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertCount(1, $hooks);
        $this->assertSame([], $errors);
    }

    public function testNothingLoadedFromFoldersThatAreNotThere(): void
    {
        [$hooks, $errors] = HookLoader::load('/nowhere-at-all', home: '/nowhere-either');

        $this->assertSame([], $hooks);
        $this->assertSame([], $errors);
    }

    public function testAFileThatReturnsSomethingElseIsAComplaint(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php return 42;');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must return a callable, got int', $errors[0]->error);
    }

    public function testAFileWithNoReturnIsAComplaint(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php $x = 1;');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must return a callable', $errors[0]->error);
    }

    /**
     * The whole reason `require` was an acceptable answer: PHP raises a syntax error in
     * an included file as a catchable ParseError, so a typo in a hook is a line on the
     * shell rather than the end of the session.
     */
    public function testASyntaxErrorIsAComplaintAndNotACrash(): void
    {
        $this->write($this->home . '/hooks', 'broken.php', '<?php return function ( {');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('ParseError', $errors[0]->error);
        $this->assertSame('load', $errors[0]->event);
    }

    public function testAFactoryThatThrowsIsAComplaint(): void
    {
        $this->write($this->home . '/hooks', 'a.php', <<<'PHP'
            <?php
            return function ($pi): void {
                throw new RuntimeException('no API key');
            };
            PHP);

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertStringContainsString('no API key', $errors[0]->error);
    }

    public function testCallingAnUndefinedFunctionIsAComplaint(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php pig_no_such_function(); return fn () => null;');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('pig_no_such_function', $errors[0]->error);
    }

    /** Printing would land in the middle of the UI, which redraws over it. */
    public function testAFileThatPrintsIsAComplaint(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php echo "loading hooks\n"; return fn () => null;');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertStringContainsString('printed to standard output', $errors[0]->error);
        $this->assertStringContainsString('loading hooks', $errors[0]->error);
    }

    public function testSubscribingToAnEventThatDoesNotExistIsAComplaint(): void
    {
        $this->write($this->home . '/hooks', 'a.php', <<<'PHP'
            <?php
            return function ($pi): void {
                $pi->on('tool_calls', fn () => null);
            };
            PHP);

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertSame([], $hooks);
        $this->assertStringContainsString("No event called 'tool_calls'", $errors[0]->error);
    }

    /** The two upstream events pig does not fire say so, rather than "no such event". */
    public function testAnUnportedEventSaysWhatToUseInstead(): void
    {
        $this->write($this->home . '/hooks', 'a.php', <<<'PHP'
            <?php
            return function ($pi): void {
                $pi->on('session_branch', fn () => null);
            };
            PHP);

        [, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertStringContainsString('session_tree', $errors[0]->error);
    }

    public function testAConfiguredPathIsLoadedAfterTheFolders(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php return function ($pi): void {};');
        $extra = $this->write($this->project, 'extra.php', '<?php return function ($pi): void {};');

        [$hooks, $errors] = HookLoader::load($this->project, [$extra], $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(2, $hooks);
        $this->assertSame('extra.php', basename($hooks[1]->path));
    }

    public function testAConfiguredRelativePathIsResolvedFromTheWorkingDirectory(): void
    {
        $this->write($this->project, 'extra.php', '<?php return function ($pi): void {};');

        [$hooks, $errors] = HookLoader::load($this->project, ['extra.php'], $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $hooks);
    }

    public function testAConfiguredPathThatIsNotThereIsAComplaint(): void
    {
        [$hooks, $errors] = HookLoader::load($this->project, ['/no/such/hook.php'], $this->home);

        $this->assertSame([], $hooks);
        $this->assertSame('not a readable file', $errors[0]->error);
    }

    /**
     * One file reached twice is one hook. Not just a doubled handler: a file declaring a
     * function would be a fatal error on the second `require`.
     */
    public function testTheSameFileNamedTwiceIsLoadedOnce(): void
    {
        $path = $this->write($this->home . '/hooks', 'a.php', <<<'PHP'
            <?php
            return function ($pi): void {
                $pi->on('agent_start', fn () => null);
            };
            PHP);

        [$hooks, $errors] = HookLoader::load($this->project, [$path], $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $hooks);
        $this->assertCount(1, $hooks[0]->api->handlers('agent_start'));
    }

    public function testEveryEventNameIsAcceptedBySubscribe(): void
    {
        $api = new HookApi('.');

        foreach (HookApi::EVENTS as $event) {
            $api->on($event, static fn () => null);
        }

        foreach (HookApi::EVENTS as $event) {
            $this->assertCount(1, $api->handlers($event), $event);
        }
    }

    public function testABrokenHookDoesNotStopTheOnesAroundIt(): void
    {
        $this->write($this->home . '/hooks', 'a.php', '<?php return function ($pi): void {};');
        $this->write($this->home . '/hooks', 'b.php', '<?php return "nope";');
        $this->write($this->home . '/hooks', 'c.php', '<?php return function ($pi): void {};');

        [$hooks, $errors] = HookLoader::load($this->project, home: $this->home);

        $this->assertCount(2, $hooks);
        $this->assertCount(1, $errors);
        $this->assertSame('b.php', basename($errors[0]->hookPath));
    }
}
