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
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\RegisteredTool;
use Pig\CodingAgent\Extensions\WrappedCustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\ExtensionGuardedTool;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Extensions\Results\ToolCallEventResult;
use Pig\CodingAgent\Tools\ToolSet;
use RuntimeException;

/**
 * Tools somebody wrote: declaring them, and handing them to the agent.
 *
 * An extension registers them, and `ExtensionFileLoadingTest` has the loading; what is worth
 * testing here is what is different — the declaration, and the session context that a custom
 * tool gets and a built-in one does not.
 */
final class CustomToolsTest extends TestCase
{
    private string $home = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-tools-home-' . bin2hex(random_bytes(4));
        $this->project = sys_get_temp_dir() . '/pig-tools-project-' . bin2hex(random_bytes(4));

        mkdir($this->home, 0o777, true);
        mkdir($this->project, 0o777, true);
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

    // ---- declaring one -----------------------------------------------------------------

    public function testATooNamelessToolIsRefusedAtTheDeclaration(): void
    {
        try {
            new ToolDefinition('', 'x', 'y', ['type' => 'object'], static fn () => null);
            $this->fail('expected the name to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('cannot be a tool name', $error->getMessage());
        }
    }

    public function testANameWithASpaceIsRefused(): void
    {
        try {
            new ToolDefinition('my tool', 'x', 'y', ['type' => 'object'], static fn () => null);
            $this->fail('expected the name to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('cannot be a tool name', $error->getMessage());
        }
    }

    /** It is the only thing the model chooses on. */
    public function testADescriptionIsRequired(): void
    {
        try {
            new ToolDefinition('wc', 'Count', '  ', ['type' => 'object'], static fn () => null);
            $this->fail('expected the description to be required');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('needs a description', $error->getMessage());
        }
    }

    public function testTheParametersHaveToBeASchemaObject(): void
    {
        try {
            new ToolDefinition('wc', 'Count', 'Counts', ['path' => 'string'], static fn () => null);
            $this->fail('expected the schema to be refused');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('JSON Schema object', $error->getMessage());
        }
    }

    // ---- the factory, as an extension's -----------------------------------------------

    public function testTheFactoryGetsTheWorkingDirectoryAndCanRunThings(): void
    {
        mkdir($this->home . '/extensions', 0o777, true);
        file_put_contents($this->home . '/extensions/probe.php', <<<'PHP'
            <?php

            use Pig\Agent\AgentToolResult;
            use Pig\Ai\TextContent;
            use Pig\CodingAgent\Extensions\ToolDefinition;

            return function ($pig): void {
                $run = $pig->exec(['echo', 'ready']);

                $pig->registerTool(new ToolDefinition(
                    name: 'probe',
                    label: 'Probe',
                    description: 'Reports what the factory saw.',
                    parameters: ['type' => 'object', 'properties' => []],
                    execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult(
                        [new TextContent($pig->cwd() . '|' . trim($run->stdout))],
                    ),
                ));
            };
            PHP);

        [$loaded, $problems] = ExtensionLoader::load($this->project, home: $this->home);

        $this->assertSame([], $problems);

        $result = ($loaded[0]->api->tools()[0]->execute)('1', [], null, new ExtensionContext('.'), null);

        $this->assertSame($this->project . '|ready', $result->content[0]->text);
    }

    // ---- as the agent sees them --------------------------------------------------------

    public function testAWrappedToolLooksLikeAnyOtherTool(): void
    {
        $wrapped = new WrappedCustomTool($this->tool('wc'), static fn () => new ExtensionContext('/work'));

        $this->assertSame('wc', $wrapped->definition()->name);
        $this->assertSame('Counts things.', $wrapped->definition()->description);
        $this->assertSame('Count', $wrapped->label());
        $this->assertSame(['type' => 'object', 'properties' => []], $wrapped->definition()->parameters);
    }

    /**
     * Upstream's `wrapToolDefinition()` copies `constrainedSampling` from the definition with the
     * rest. pig's `definition()` built a `Tool` from the name, description and schema only, so an
     * extension tool that asked for strict sampling, as the built-in four do, never got it on any
     * provider. And an MCP tool asks for nothing — upstream's `extensions/mcp/tools.ts` sets no
     * `constrainedSampling` — so it stays null.
     */
    public function testAWrappedToolKeepsTheConstrainedSamplingItAskedFor(): void
    {
        $strict = ['type' => 'json_schema', 'strict' => 'prefer'];
        $tool = new ToolDefinition(
            name: 'wc',
            label: 'Count',
            description: 'Counts things.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: static fn () => new AgentToolResult([new TextContent('ran')]),
            constrainedSampling: $strict,
        );

        $wrapped = new WrappedCustomTool($tool, static fn () => new ExtensionContext('/work'));

        $this->assertSame($strict, $wrapped->definition()->constrainedSampling);
        $this->assertNull((new WrappedCustomTool($this->tool('plain'), static fn () => new ExtensionContext('/work')))->definition()->constrainedSampling);
    }

    public function testTheToolIsGivenTheSessionContext(): void
    {
        $seen = null;
        $tool = new ToolDefinition(
            name: 'peek',
            label: 'Peek',
            description: 'Looks at the session.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: function ($id, $params, $onUpdate, $ctx) use (&$seen) {
                $seen = $ctx;

                return new AgentToolResult([new TextContent('ok')]);
            },
        );

        $wrapped = new WrappedCustomTool($tool, static fn () => new ExtensionContext('/work'));
        $wrapped->execute('1', []);

        $this->assertSame('/work', $seen?->cwd);
    }

    /** A closure, not a captured value: `/model` replaces what a tool would have been told. */
    public function testTheContextIsBuiltPerCall(): void
    {
        $cwd = '/first';
        $seen = [];
        $tool = new ToolDefinition(
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
            return new ExtensionContext($cwd);
        });

        $wrapped->execute('1', []);
        $cwd = '/second';
        $wrapped->execute('2', []);

        $this->assertSame(['/first', '/second'], $seen);
    }

    public function testArgumentsAndProgressReachTheTool(): void
    {
        $updates = [];
        $tool = new ToolDefinition(
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

        $wrapped = new WrappedCustomTool($tool, static fn () => new ExtensionContext('.'));
        $result = $wrapped->execute('call-9', ['what' => 'it'], null, static function ($partial) use (&$updates): void {
            $updates[] = $partial->content[0]->text;
        });

        $this->assertSame('done it as call-9', $result->content[0]->text);
        $this->assertSame(['halfway'], $updates);
    }

    public function testASetWithNoContextStillRuns(): void
    {
        $set = new CustomToolSet([new RegisteredTool('a.php', 'a.php', $this->tool('wc'))]);

        $this->assertSame('wc ran', $set->agentTools()[0]->execute('1', [])->content[0]->text);
    }

    // ---- the session callbacks ---------------------------------------------------------

    public function testEveryToolHearsAboutTheSession(): void
    {
        $seen = [];
        $set = new CustomToolSet([
            new RegisteredTool('a.php', 'a.php', $this->tool('a', function ($event) use (&$seen): void {
                $seen[] = ['a', $event->reason, $event->previousSessionFile];
            })),
            new RegisteredTool('b.php', 'b.php', $this->tool('b', function ($event) use (&$seen): void {
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
        $set = new CustomToolSet([new RegisteredTool('a.php', 'a.php', $this->tool('wc'))]);

        $this->assertSame([], $set->notify('start'));
    }

    /** One tool failing to clean up is not a reason for `/new` to fail. */
    public function testACallbackThatThrowsIsReportedAndTheRestStillRun(): void
    {
        $reached = false;
        $set = new CustomToolSet([
            new RegisteredTool('bad.php', 'bad.php', $this->tool('a', static function (): void {
                throw new RuntimeException('could not save');
            })),
            new RegisteredTool('good.php', 'good.php', $this->tool('b', function () use (&$reached): void {
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
            new RegisteredTool('a/index.php', 'a/index.php', $this->tool('a')),
            new RegisteredTool('b/index.php', 'b/index.php', $this->tool('b')),
        ]);

        $this->assertFalse($set->isEmpty());
        $this->assertSame(['a', 'b'], $set->names());
        $this->assertSame(['a/index.php', 'b/index.php'], $set->paths());
        $this->assertTrue((new CustomToolSet())->isEmpty());
    }

    // ---- with the agent ----------------------------------------------------------------

    public function testACustomToolIsOfferedToTheModelBesideTheBuiltInOnes(): void
    {
        $set = new CustomToolSet([new RegisteredTool('a.php', 'a.php', $this->tool('wc'))]);
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
        $api = new ExtensionApi('.', 'guard.php');
        $api->on('tool_call', static fn ($event) => $event->toolName === 'wc'
            ? new ToolCallEventResult(block: true, reason: 'not that one')
            : null);

        $extensionRunner = new ExtensionRunner([new LoadedExtension('guard.php', 'guard.php', $api->name, $api)], '.');
        $set = new CustomToolSet([new RegisteredTool('a.php', 'a.php', $this->tool('wc'))]);

        $agent = CodingAgent::create(
            $this->model(),
            sys_get_temp_dir(),
            [],
            apiKey: 'test-key',
            extensionRunner: $extensionRunner,
            customTools: $set,
        );

        $tool = $agent->state->tools[0];

        $this->assertInstanceOf(ExtensionGuardedTool::class, $tool);

        try {
            $tool->execute('1', []);
            $this->fail('expected the hook to block it');
        } catch (\Pig\Agent\AgentError $error) {
            $this->assertSame('not that one', $error->getMessage());
        }
    }

    // ---- scaffolding -------------------------------------------------------------------

    private function tool(string $name, ?\Closure $onSession = null): ToolDefinition
    {
        return new ToolDefinition(
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
