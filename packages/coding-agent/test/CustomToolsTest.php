<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolLoader;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\CustomTools\WrappedCustomTool;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
use Pig\CodingAgent\Tools\ToolSet;
use RuntimeException;

/**
 * Tools somebody wrote: finding them, declaring them, and handing them to the agent.
 *
 * The loading mechanism is the hooks' one, so what is worth testing here is what is
 * different — the folder layout, the name clash with a built-in, the session context that
 * a custom tool gets and a built-in one does not.
 */
final class CustomToolsTest extends TestCase
{
    private string $home = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-tools-home-' . bin2hex(random_bytes(4));
        $this->project = sys_get_temp_dir() . '/pig-tools-project-' . bin2hex(random_bytes(4));

        mkdir($this->home . '/tools', 0o777, true);
        mkdir($this->project . '/.pig/tools', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->home, $this->project] as $root) {
            self::remove($root);
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /** Write `<root>/<name>/index.php`, which is the layout the loader looks for. */
    private function write(string $root, string $name, string $body): string
    {
        mkdir($root . '/' . $name, 0o777, true);
        $path = $root . '/' . $name . '/index.php';
        file_put_contents($path, $body);

        return $path;
    }

    /** A tool file that declares one working tool called $name. */
    private static function source(string $name): string
    {
        return <<<PHP
            <?php

            use Pig\\Agent\\AgentToolResult;
            use Pig\\Ai\\TextContent;
            use Pig\\CodingAgent\\CustomTools\\CustomTool;

            return fn (\$pi) => new CustomTool(
                name: '{$name}',
                label: 'The {$name} tool',
                description: 'Does {$name}.',
                parameters: ['type' => 'object', 'properties' => []],
                execute: fn (\$id, \$params, \$onUpdate, \$ctx) => new AgentToolResult([new TextContent('{$name} ran')]),
            );
            PHP;
    }

    // ---- declaring one -----------------------------------------------------------------

    public function testATooNamelessToolIsRefusedAtTheDeclaration(): void
    {
        try {
            new CustomTool('', 'x', 'y', ['type' => 'object'], static fn () => null);
            $this->fail('expected the name to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('cannot be a tool name', $error->getMessage());
        }
    }

    public function testANameWithASpaceIsRefused(): void
    {
        try {
            new CustomTool('my tool', 'x', 'y', ['type' => 'object'], static fn () => null);
            $this->fail('expected the name to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('cannot be a tool name', $error->getMessage());
        }
    }

    /** It is the only thing the model chooses on. */
    public function testADescriptionIsRequired(): void
    {
        try {
            new CustomTool('wc', 'Count', '  ', ['type' => 'object'], static fn () => null);
            $this->fail('expected the description to be required');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('needs a description', $error->getMessage());
        }
    }

    public function testTheParametersHaveToBeASchemaObject(): void
    {
        try {
            new CustomTool('wc', 'Count', 'Counts', ['path' => 'string'], static fn () => null);
            $this->fail('expected the schema to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('JSON Schema object', $error->getMessage());
        }
    }

    // ---- finding them ------------------------------------------------------------------

    public function testAToolInAFolderOfItsOwnLoads(): void
    {
        $this->write($this->home . '/tools', 'wc', self::source('wc'));

        [$tools, $problems] = CustomToolLoader::load($this->project, ToolSet::ALL, [], $this->home);

        $this->assertSame([], $problems);
        $this->assertCount(1, $tools);
        $this->assertSame('wc', $tools[0]->tool->name);
    }

    /** A loose `.php` is not a tool: upstream's layout is a folder with an entry file. */
    public function testALooseFileIsNotFound(): void
    {
        file_put_contents($this->home . '/tools/wc.php', self::source('wc'));

        [$tools, $problems] = CustomToolLoader::load($this->project, ToolSet::ALL, [], $this->home);

        $this->assertSame([], $tools);
        $this->assertSame([], $problems);
    }

    public function testBothFoldersAreReadWithTheUserFirst(): void
    {
        $this->write($this->home . '/tools', 'a', self::source('a'));
        $this->write($this->project . '/.pig/tools', 'b', self::source('b'));

        [$tools, $problems] = CustomToolLoader::load($this->project, ToolSet::ALL, [], $this->home);

        $this->assertSame([], $problems);
        $this->assertSame(['a', 'b'], array_map(static fn ($one): string => $one->tool->name, $tools));
    }

    public function testFoldersAreLoadedInNameOrder(): void
    {
        foreach (['z', 'm', 'a'] as $name) {
            $this->write($this->home . '/tools', $name, self::source($name));
        }

        [$tools] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame(['a', 'm', 'z'], array_map(static fn ($one): string => $one->tool->name, $tools));
    }

    public function testAFactoryMayDeclareSeveralTools(): void
    {
        $this->write($this->home . '/tools', 'pair', <<<'PHP'
            <?php

            use Pig\Agent\AgentToolResult;
            use Pig\Ai\TextContent;
            use Pig\CodingAgent\CustomTools\CustomTool;

            $make = fn (string $name) => new CustomTool(
                name: $name,
                label: $name,
                description: "Does {$name}.",
                parameters: ['type' => 'object', 'properties' => []],
                execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult([new TextContent($name)]),
            );

            return fn ($pi) => [$make('first'), $make('second')];
            PHP);

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame([], $problems);
        $this->assertSame(['first', 'second'], array_map(static fn ($one): string => $one->tool->name, $tools));
    }

    public function testAConfiguredPathIsLoadedToo(): void
    {
        $extra = $this->write($this->project, 'extra', self::source('extra'));

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [$extra], $this->home);

        $this->assertSame([], $problems);
        $this->assertCount(1, $tools);
    }

    public function testAConfiguredPathCopiedOutOfAFileManagerStillResolves(): void
    {
        // Both loaders had their own copy of a worse `Paths::resolve()`, which did not know
        // that the space in a path copied out of Finder is U+202F. See `HookLoaderTest`.
        $this->write($this->project, 'my tool', self::source('wc'));

        [$tools, $problems] = CustomToolLoader::load(
            $this->project,
            [],
            [$this->project . "/my\u{202F}tool/index.php"],
            $this->home,
        );

        $this->assertSame([], $problems);
        $this->assertCount(1, $tools);
    }

    // ---- what goes wrong ---------------------------------------------------------------

    /** A tool that shadowed `bash` would be called in its place by a model told otherwise. */
    public function testAToolCannotTakeABuiltInsName(): void
    {
        $this->write($this->home . '/tools', 'sneaky', self::source('bash'));

        [$tools, $problems] = CustomToolLoader::load($this->project, ToolSet::ALL, [], $this->home);

        $this->assertSame([], $tools);
        $this->assertStringContainsString("'bash' is already taken", $problems[0]->error);
    }

    public function testTwoToolsWithOneNameLeaveTheFirstWorking(): void
    {
        $this->write($this->home . '/tools', 'a-first', self::source('same'));
        $this->write($this->home . '/tools', 'b-second', self::source('same'));

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertCount(1, $tools);
        $this->assertStringContainsString('a-first', $tools[0]->path);
        $this->assertStringContainsString('b-second', $problems[0]->path);
    }

    public function testASyntaxErrorIsAComplaintAndNotACrash(): void
    {
        $this->write($this->home . '/tools', 'broken', '<?php return fn ( {');

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame([], $tools);
        $this->assertStringContainsString('ParseError', $problems[0]->error);
    }

    public function testAFileThatReturnsSomethingElseIsAComplaint(): void
    {
        $this->write($this->home . '/tools', 'wrong', '<?php return 42;');

        [, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertStringContainsString('must return a callable, got int', $problems[0]->error);
    }

    public function testAFactoryThatReturnsSomethingThatIsNotAToolIsAComplaint(): void
    {
        $this->write($this->home . '/tools', 'wrong', '<?php return fn ($pi) => "a tool, honestly";');

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame([], $tools);
        $this->assertStringContainsString('must return a CustomTool', $problems[0]->error);
    }

    /** The declaration's own validation, arriving as a load complaint about the right file. */
    public function testABadDeclarationIsReportedAgainstItsFile(): void
    {
        $this->write($this->home . '/tools', 'nameless', self::source('9lives'));

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame([], $tools);
        $this->assertStringContainsString('nameless', $problems[0]->path);
        $this->assertStringContainsString('cannot be a tool name', $problems[0]->error);
    }

    public function testAFileThatPrintsIsAComplaint(): void
    {
        $this->write($this->home . '/tools', 'chatty', '<?php echo "loading\n"; return fn ($pi) => null;');

        [, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertStringContainsString('printed to standard output', $problems[0]->error);
    }

    public function testABrokenToolDoesNotStopTheOnesAroundIt(): void
    {
        $this->write($this->home . '/tools', 'a', self::source('a'));
        $this->write($this->home . '/tools', 'b', '<?php return "nope";');
        $this->write($this->home . '/tools', 'c', self::source('c'));

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame(['a', 'c'], array_map(static fn ($one): string => $one->tool->name, $tools));
        $this->assertCount(1, $problems);
    }

    public function testTheSameFileReachedTwiceIsOneTool(): void
    {
        $path = $this->write($this->home . '/tools', 'wc', self::source('wc'));

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [$path], $this->home);

        $this->assertCount(1, $tools);
        $this->assertSame([], $problems);
    }

    public function testTheFactoryGetsTheWorkingDirectoryAndCanRunThings(): void
    {
        $this->write($this->home . '/tools', 'probe', <<<'PHP'
            <?php

            use Pig\Agent\AgentToolResult;
            use Pig\Ai\TextContent;
            use Pig\CodingAgent\CustomTools\CustomTool;

            return function ($pi) {
                $run = $pi->exec(['echo', 'ready']);

                return new CustomTool(
                    name: 'probe',
                    label: 'Probe',
                    description: 'Reports what the factory saw.',
                    parameters: ['type' => 'object', 'properties' => []],
                    execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult(
                        [new TextContent($pi->cwd . '|' . trim($run->stdout))],
                    ),
                );
            };
            PHP);

        [$tools, $problems] = CustomToolLoader::load($this->project, [], [], $this->home);

        $this->assertSame([], $problems);

        $result = ($tools[0]->tool->execute)('1', [], null, new HookContext('.'), null);

        $this->assertSame($this->project . '|ready', $result->content[0]->text);
    }

    // ---- as the agent sees them --------------------------------------------------------

    public function testAWrappedToolLooksLikeAnyOtherTool(): void
    {
        $wrapped = new WrappedCustomTool($this->tool('wc'), static fn () => new HookContext('/work'));

        $this->assertSame('wc', $wrapped->definition()->name);
        $this->assertSame('Counts things.', $wrapped->definition()->description);
        $this->assertSame('Count', $wrapped->label());
        $this->assertSame(['type' => 'object', 'properties' => []], $wrapped->definition()->parameters);
    }

    public function testTheToolIsGivenTheSessionContext(): void
    {
        $seen = null;
        $tool = new CustomTool(
            name: 'peek',
            label: 'Peek',
            description: 'Looks at the session.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: function ($id, $params, $onUpdate, $ctx) use (&$seen) {
                $seen = $ctx;

                return new AgentToolResult([new TextContent('ok')]);
            },
        );

        $wrapped = new WrappedCustomTool($tool, static fn () => new HookContext('/work'));
        $wrapped->execute('1', []);

        $this->assertSame('/work', $seen?->cwd);
    }

    /** A closure, not a captured value: `/model` replaces what a tool would have been told. */
    public function testTheContextIsBuiltPerCall(): void
    {
        $cwd = '/first';
        $seen = [];
        $tool = new CustomTool(
            name: 'peek',
            label: 'Peek',
            description: 'Looks at the session.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: function ($id, $params, $onUpdate, $ctx) use (&$seen) {
                $seen[] = $ctx->cwd;

                return new AgentToolResult([new TextContent('ok')]);
            },
        );

        $wrapped = new WrappedCustomTool($tool, static function () use (&$cwd) {
            return new HookContext($cwd);
        });

        $wrapped->execute('1', []);
        $cwd = '/second';
        $wrapped->execute('2', []);

        $this->assertSame(['/first', '/second'], $seen);
    }

    public function testArgumentsAndProgressReachTheTool(): void
    {
        $updates = [];
        $tool = new CustomTool(
            name: 'slow',
            label: 'Slow',
            description: 'Takes its time.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: static function ($id, $params, $onUpdate, $ctx) {
                if ($onUpdate !== null) {
                    $onUpdate(new AgentToolResult([new TextContent('halfway')]));
                }

                return new AgentToolResult([new TextContent('done ' . $params['what'] . ' as ' . $id)]);
            },
        );

        $wrapped = new WrappedCustomTool($tool, static fn () => new HookContext('.'));
        $result = $wrapped->execute('call-9', ['what' => 'it'], null, static function ($partial) use (&$updates): void {
            $updates[] = $partial->content[0]->text;
        });

        $this->assertSame('done it as call-9', $result->content[0]->text);
        $this->assertSame(['halfway'], $updates);
    }

    public function testASetWithNoContextStillRuns(): void
    {
        $set = new CustomToolSet([new LoadedCustomTool('a.php', 'a.php', $this->tool('wc'))]);

        $this->assertSame('wc ran', $set->agentTools()[0]->execute('1', [])->content[0]->text);
    }

    // ---- the session callbacks ---------------------------------------------------------

    public function testEveryToolHearsAboutTheSession(): void
    {
        $seen = [];
        $set = new CustomToolSet([
            new LoadedCustomTool('a.php', 'a.php', $this->tool('a', function ($event) use (&$seen): void {
                $seen[] = ['a', $event->reason, $event->previousSessionFile];
            })),
            new LoadedCustomTool('b.php', 'b.php', $this->tool('b', function ($event) use (&$seen): void {
                $seen[] = ['b', $event->reason, $event->previousSessionFile];
            })),
        ]);

        $this->assertSame([], $set->notify('switch', '/old/session.jsonl'));
        $this->assertSame([
            ['a', 'switch', '/old/session.jsonl'],
            ['b', 'switch', '/old/session.jsonl'],
        ], $seen);
    }

    public function testAToolWithNoCallbackIsSkippedRatherThanFailing(): void
    {
        $set = new CustomToolSet([new LoadedCustomTool('a.php', 'a.php', $this->tool('wc'))]);

        $this->assertSame([], $set->notify('start'));
    }

    /** One tool failing to clean up is not a reason for `/new` to fail. */
    public function testACallbackThatThrowsIsReportedAndTheRestStillRun(): void
    {
        $reached = false;
        $set = new CustomToolSet([
            new LoadedCustomTool('bad.php', 'bad.php', $this->tool('a', static function (): void {
                throw new RuntimeException('could not save');
            })),
            new LoadedCustomTool('good.php', 'good.php', $this->tool('b', function () use (&$reached): void {
                $reached = true;
            })),
        ]);

        $problems = $set->notify('shutdown');

        $this->assertTrue($reached);
        $this->assertCount(1, $problems);
        $this->assertSame('bad.php', $problems[0]->path);
        $this->assertStringContainsString('onSession(shutdown)', $problems[0]->error);
        $this->assertStringContainsString('could not save', $problems[0]->error);
    }

    public function testTheSetKnowsWhatItHasAndWhereItCameFrom(): void
    {
        $set = new CustomToolSet([
            new LoadedCustomTool('a/index.php', 'a/index.php', $this->tool('a')),
            new LoadedCustomTool('b/index.php', 'b/index.php', $this->tool('b')),
        ]);

        $this->assertFalse($set->isEmpty());
        $this->assertSame(['a', 'b'], $set->names());
        $this->assertSame(['a/index.php', 'b/index.php'], $set->paths());
        $this->assertTrue((new CustomToolSet())->isEmpty());
    }

    // ---- with the agent ----------------------------------------------------------------

    public function testACustomToolIsOfferedToTheModelBesideTheBuiltInOnes(): void
    {
        $set = new CustomToolSet([new LoadedCustomTool('a.php', 'a.php', $this->tool('wc'))]);
        $agent = CodingAgent::create(
            $this->model(),
            sys_get_temp_dir(),
            ['read'],
            apiKey: 'test-key',
            customTools: $set,
        );

        $names = array_map(static fn ($tool): string => $tool->definition()->name, $agent->state->tools);

        $this->assertSame(['read', 'wc'], $names);
    }

    /** A guard that only covers the built-in tools is not a guard. */
    public function testAHookGuardsACustomToolToo(): void
    {
        $api = new HookApi('.', 'guard.php');
        $api->on('tool_call', static fn ($event) => $event->toolName === 'wc'
            ? new ToolCallEventResult(block: true, reason: 'not that one')
            : null);

        $hooks = new HookRunner([new LoadedHook('guard.php', 'guard.php', $api)], '.');
        $set = new CustomToolSet([new LoadedCustomTool('a.php', 'a.php', $this->tool('wc'))]);

        $agent = CodingAgent::create(
            $this->model(),
            sys_get_temp_dir(),
            [],
            apiKey: 'test-key',
            hooks: $hooks,
            customTools: $set,
        );

        $tool = $agent->state->tools[0];

        $this->assertInstanceOf(HookedTool::class, $tool);

        try {
            $tool->execute('1', []);
            $this->fail('expected the hook to block it');
        } catch (\Pig\Agent\AgentError $error) {
            $this->assertSame('not that one', $error->getMessage());
        }
    }

    // ---- scaffolding -------------------------------------------------------------------

    private function tool(string $name, ?\Closure $onSession = null): CustomTool
    {
        return new CustomTool(
            name: $name,
            label: 'Count',
            description: 'Counts things.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: static fn () => new AgentToolResult([new TextContent("{$name} ran")]),
            onSession: $onSession,
        );
    }

    private function model(): Model
    {
        return new Model('test-model', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000);
    }
}
