<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\ExtensionRunner;

/**
 * How one extension file loads: what counts as one, in what order, and what a broken one costs
 * the ones around it. `ExtensionLoaderTest` has the roots, the packages and the built-ins.
 */
final class ExtensionFileLoadingTest extends TestCase
{
    private string $home = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-ext-home-' . bin2hex(random_bytes(4));
        $this->project = sys_get_temp_dir() . '/pig-ext-project-' . bin2hex(random_bytes(4));

        mkdir($this->home . '/extensions', 0o777, true);
        mkdir($this->project . '/.pig/extensions', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->home, $this->project] as $root) {
            foreach (['/extensions', '/.pig/extensions', '/.pig', ''] as $suffix) {
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
        $this->write($this->home . '/extensions', 'a.php', <<<'PHP'
            <?php
            return function ($pig): void {
                $pig->on('tool_call', fn () => null);
            };
            PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertCount(1, $loaded[0]->api->handlers('tool_call'));
    }

    public function testBothRootsAreReadWithTheUserFirst(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php return function ($pig): void {};');
        $this->write($this->project . '/.pig/extensions', 'b.php', '<?php return function ($pig): void {};');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(2, $loaded);
        $this->assertSame('a.php', basename($loaded[0]->path));
        $this->assertSame('b.php', basename($loaded[1]->path));
    }

    public function testFilesInOneFolderLoadInNameOrder(): void
    {
        foreach (['z.php', 'm.php', 'a.php'] as $name) {
            $this->write($this->home . '/extensions', $name, '<?php return function ($pig): void {};');
        }

        [$loaded] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame(
            ['a.php', 'm.php', 'z.php'],
            array_map(static fn ($hook): string => basename($hook->path), $loaded),
        );
    }

    public function testOnlyPhpFilesAreLoaded(): void
    {
        $this->write($this->home . '/extensions', 'notes.md', 'not an extension');
        $this->write($this->home . '/extensions', 'a.php', '<?php return function ($pig): void {};');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertCount(1, $loaded);
        $this->assertSame([], $errors);
    }

    public function testNothingLoadedFromFoldersThatAreNotThere(): void
    {
        [$loaded, $errors] = ExtensionLoader::load('/nowhere-at-all', home: '/nowhere-either');

        $this->assertSame([], $loaded);
        $this->assertSame([], $errors);
    }

    public function testAFileThatReturnsSomethingElseIsAComplaint(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php return 42;');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must return a callable, got int', $errors[0]->message);
    }

    public function testAFileWithNoReturnIsAComplaint(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php $x = 1;');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must return a callable', $errors[0]->message);
    }

    /**
     * The whole reason `require` was an acceptable answer: PHP raises a syntax error in
     * an included file as a catchable ParseError, so a typo in an extension is a line on the
     * shell rather than the end of the session.
     */
    public function testASyntaxErrorIsAComplaintAndNotACrash(): void
    {
        $this->write($this->home . '/extensions', 'broken.php', '<?php return function ( {');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('ParseError', $errors[0]->message);
        $this->assertSame('load', $errors[0]->stage);
    }

    public function testAFactoryThatThrowsIsAComplaint(): void
    {
        $this->write($this->home . '/extensions', 'a.php', <<<'PHP'
            <?php
            return function ($pig): void {
                throw new RuntimeException('no API key');
            };
            PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertStringContainsString('no API key', $errors[0]->message);
    }

    public function testCallingAnUndefinedFunctionIsAComplaint(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php pig_no_such_function(); return fn () => null;');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('pig_no_such_function', $errors[0]->message);
    }

    /** Printing would land in the middle of the UI, which redraws over it. */
    public function testAFileThatPrintsIsAComplaint(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php echo "loading extensions\n"; return fn () => null;');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertStringContainsString('printed to standard output', $errors[0]->message);
        $this->assertStringContainsString('loading extensions', $errors[0]->message);
    }

    public function testSubscribingToAnEventThatDoesNotExistIsAComplaint(): void
    {
        $this->write($this->home . '/extensions', 'a.php', <<<'PHP'
            <?php
            return function ($pig): void {
                $pig->on('tool_calls', fn () => null);
            };
            PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertStringContainsString("No event called 'tool_calls'", $errors[0]->message);
    }

    /** The two upstream events pig does not fire say so, rather than "no such event". */
    public function testAnUnportedEventSaysWhatToUseInstead(): void
    {
        $this->write($this->home . '/extensions', 'a.php', <<<'PHP'
            <?php
            return function ($pig): void {
                $pig->on('session_branch', fn () => null);
            };
            PHP);

        [, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertStringContainsString('session_tree', $errors[0]->message);
    }

    public function testAConfiguredPathIsLoadedAfterTheFolders(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php return function ($pig): void {};');
        $extra = $this->write($this->project, 'extra.php', '<?php return function ($pig): void {};');

        [$loaded, $errors] = ExtensionLoader::load($this->project, [$extra], home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(2, $loaded);
        $this->assertSame('extra.php', basename($loaded[1]->path));
    }

    public function testAConfiguredRelativePathIsResolvedFromTheWorkingDirectory(): void
    {
        $this->write($this->project, 'extra.php', '<?php return function ($pig): void {};');

        [$loaded, $errors] = ExtensionLoader::load($this->project, ['extra.php'], home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
    }

    public function testAConfiguredPathCopiedOutOfAFileManagerStillResolves(): void
    {
        // The space in a path copied out of Finder is U+202F, not a space — which is the whole
        // reason `Paths::expand()` exists, and this loader had its own hand-rolled version of
        // that function which did not know. What it cost: an extension that is right there, reported
        // as "not a readable file", naming a path that looks identical to the one on disk.
        mkdir($this->project . '/my extensions', 0o777, true);
        $this->write($this->project . '/my extensions', 'extra.php', '<?php return function ($pig): void {};');

        [$loaded, $errors] = ExtensionLoader::load($this->project, ["my\u{202F}extensions/extra.php"], home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);

        unlink($this->project . '/my extensions/extra.php');
        rmdir($this->project . '/my extensions');
    }

    public function testAConfiguredPathThatIsNotThereIsAComplaint(): void
    {
        [$loaded, $errors] = ExtensionLoader::load($this->project, ['/no/such/extension.php'], home: $this->home);

        $this->assertSame([], $loaded);
        $this->assertSame('not a readable file', $errors[0]->message);
    }

    /**
     * One file reached twice is one extension. Not just a doubled handler: a file declaring a
     * function would be a fatal error on the second `require`.
     */
    public function testTheSameFileNamedTwiceIsLoadedOnce(): void
    {
        $path = $this->write($this->home . '/extensions', 'a.php', <<<'PHP'
            <?php
            return function ($pig): void {
                $pig->on('agent_start', fn () => null);
            };
            PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->project, [$path], home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertCount(1, $loaded[0]->api->handlers('agent_start'));
    }

    public function testEveryEventNameIsAcceptedBySubscribe(): void
    {
        $api = new ExtensionApi('.');

        foreach (ExtensionApi::EVENTS as $event) {
            $api->on($event, static fn () => null);
        }

        foreach (ExtensionApi::EVENTS as $event) {
            $this->assertCount(1, $api->handlers($event), $event);
        }
    }

    public function testABrokenExtensionDoesNotStopTheOnesAroundIt(): void
    {
        $this->write($this->home . '/extensions', 'a.php', '<?php return function ($pig): void {};');
        $this->write($this->home . '/extensions', 'b.php', '<?php return "nope";');
        $this->write($this->home . '/extensions', 'c.php', '<?php return function ($pig): void {};');

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertCount(2, $loaded);
        $this->assertCount(1, $errors);
        $this->assertSame('b.php', basename($errors[0]->path));
    }

    // ---- an extension with a class in it, which PHP cannot unload -------------------------------------

    public function testAnExtensionMayDeclareAClassAndAReloadRunsTheFactoryItFirstLoaded(): void
    {
        // Refused outright until this: every bundled extension kept its classes in sibling files
        // behind a hand-written `class_exists` guard, which made `/reload` run new closures against
        // old classes in silence. A named class is ordinary now, and the loader is what knows a
        // second `require` would fatal.
        $file = $this->write($this->home . '/extensions', 'with_class.php', <<<'PHP'
            <?php
            namespace ExtFileLoadingTestA;
            class Counter { public static int $built = 0; }
            function helper(): string { return 'v1'; }
            return static function ($pig): void {
                Counter::$built++;
                $pig->on('agent_end', static fn () => null);
            };
            PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertCount(1, $loaded);
        $this->assertSame([], $errors);
        $this->assertSame(1, \ExtFileLoadingTestA\Counter::$built);

        // The same file again, unchanged — `/reload`: no `require`, no fatal, no complaint, and the
        // factory ran against the fresh api.
        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertCount(1, $loaded);
        $this->assertSame([], $errors);
        $this->assertSame(2, \ExtFileLoadingTestA\Counter::$built);
        $this->assertCount(1, $loaded[0]->api->handlers('agent_end'));

        // Changed on disk: still loaded, from memory, and said so — the one thing a reload cannot do.
        file_put_contents($file, str_replace("'v1'", "'v2'", (string) file_get_contents($file)));

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertCount(1, $loaded);
        $this->assertCount(1, $errors);
        $this->assertSame('reload', $errors[0]->stage);
        $this->assertStringContainsString('declares ExtFileLoadingTestA\Counter, ExtFileLoadingTestA\helper, which PHP cannot unload', $errors[0]->message);
        $this->assertStringContainsString('restart pig to pick up the change', $errors[0]->message);
        $this->assertSame('v1', \ExtFileLoadingTestA\helper(), 'the running version is the one loaded first');
    }

    public function testASecondDifferentCopyOfAClassIsRefusedByNameAndAnIdenticalOneIsNot(): void
    {
        $body = <<<'PHP'
            <?php
            namespace ExtFileLoadingTestB;
            class Thing {}
            return static function ($pig): void {};
            PHP;
        $this->write($this->home . '/extensions', 'a_first.php', $body);
        $this->write($this->home . '/extensions', 'b_same_again.php', $body);
        $this->write($this->home . '/extensions', 'c_different.php', $body . "\n// not the same file\n");

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        // Loaded in name order. `c_different.php` declares a class `a_first.php` already put in
        // memory, and is not the same code: refused, naming where the class came from.
        // `b_same_again.php` is the same code at another path — an installed copy beside a
        // checkout — and is served by the first's factory. (`b_same_again.php` is the same code at
        $this->assertCount(2, $loaded);
        $this->assertCount(1, $errors);
        $this->assertSame('c_different.php', basename($errors[0]->path));
        $first = realpath($this->home . '/extensions/a_first.php') ?: ($this->home . '/extensions/a_first.php');
        $this->assertStringContainsString('declares ExtFileLoadingTestB\Thing from ' . $first, $errors[0]->message);
        $this->assertStringContainsString('two different copies of one extension cannot both be loaded', $errors[0]->message);
    }

    public function testAnonymousClassAndScopedClosuresAreAccepted(): void
    {
        $this->write($this->home . '/extensions', 'clean_scoped.php', <<<'PHP'
            <?php
            return static function ($pig): void {
                $closureHelper = static fn (): int => 42;
                $anonClass = new class {
                    public function answer(): int { return 42; }
                };
            };
            PHP);

        [$loaded, $errors] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertCount(1, $loaded);
        $this->assertCount(0, $errors);
    }

    // ---- what is left where hooks used to be ---------------------------------------------------

    public function testAFileLeftWhereHooksOrToolFilesLivedIsNamedWithWhereItGoes(): void
    {
        $this->assertSame([], ExtensionLoader::legacyFiles($this->project, home: $this->home), 'nothing to say with nothing there');

        mkdir($this->home . '/hooks');
        mkdir($this->project . '/.pig/hooks');
        mkdir($this->home . '/tools/wc', 0o777, true);
        $this->write($this->home . '/hooks', 'mine.php', '<?php return function ($pig): void {};');
        $this->write($this->project . '/.pig/hooks', 'theirs.php', '<?php return function ($pig): void {};');
        $this->write($this->home . '/hooks', 'notes.md', 'not a hook, not named');
        $this->write($this->home . '/tools/wc', 'index.php', '<?php return fn ($pig) => null;');

        $found = ExtensionLoader::legacyFiles($this->project, ['~/hooks/extra.php'], ['~/tools/x/index.php'], $this->home);

        $this->assertCount(6, $found);
        $this->assertStringContainsString($this->home . '/hooks/mine.php: hooks are extensions now — move it to ' . $this->home . '/extensions/', $found[0]);
        $this->assertStringContainsString($this->project . '/.pig/hooks/theirs.php: hooks are extensions now — move it to ' . $this->project . '/.pig/extensions/', $found[1]);
        $this->assertStringContainsString('the `hooks` setting', $found[2]);
        $this->assertStringContainsString($this->home . '/tools/wc/index.php: tool files are extensions now — move the folder to ' . $this->home . '/extensions/', $found[3]);
        $this->assertStringContainsString('the `customTools` setting', $found[4]);
        $this->assertStringContainsString('ExtensionApi', $found[5]);

        foreach (['/hooks/mine.php', '/hooks/notes.md', '/hooks', '/tools/wc/index.php', '/tools/wc', '/tools'] as $suffix) {
            is_dir($this->home . $suffix) ? rmdir($this->home . $suffix) : unlink($this->home . $suffix);
        }
        unlink($this->project . '/.pig/hooks/theirs.php');
        rmdir($this->project . '/.pig/hooks');
    }

    public function testHookContextStateContainerPassesDataAcrossCalls(): void
    {
        $runner = new ExtensionRunner([], '.');
        $ctx1 = $runner->context();

        $this->assertFalse($ctx1->has('service'));
        $this->assertNull($ctx1->get('service'));

        $ctx1->set('service', 'video_worker');

        $this->assertTrue($ctx1->has('service'));
        $this->assertSame('video_worker', $ctx1->get('service'));

        // Next context built from the same runner shares the state instance
        $ctx2 = $runner->context();
        $this->assertTrue($ctx2->has('service'));
        $this->assertSame('video_worker', $ctx2->get('service'));
    }
}
