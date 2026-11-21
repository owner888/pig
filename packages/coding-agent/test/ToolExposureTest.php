<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Extensions\RegisteredTool;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Tools\ToolLoadout;

/**
 * Upstream's `ToolDefinition.exposure` in the session's loadout (`ToolLoadout`): which tools
 * registering activates, which are callable from other tools, and pig's `toolExposure` setting
 * over them.
 */
final class ToolExposureTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->root = sys_get_temp_dir() . '/pig-exposure-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home', 0o700, true);
        mkdir($this->root . '/project/.pig', 0o755, true);
    }

    private static function tool(string $name, string $exposure = 'direct', bool $defaultActive = true, ?array $namespace = null): ToolDefinition
    {
        return new ToolDefinition($name, $name, "The {$name} tool.", ['type' => 'object', 'properties' => []], static fn () => new AgentToolResult([new TextContent("{$name} ran")]), defaultActive: $defaultActive, exposure: $exposure, namespace: $namespace);
    }

    /**
     * A session over `read` and these tools, registered by one extension whose API is returned
     * so a test can register more.
     *
     * @param list<ToolDefinition> $tools
     * @return array{0: AgentSession, 1: ToolLoadout, 2: ExtensionApi}
     */
    private function session(array $tools, ?\Closure $named = null): array
    {
        $file = $this->root . '/tools.php';
        file_put_contents($file, "<?php\nreturn static function (\\Pig\\CodingAgent\\Extensions\\ExtensionApi \$pig): void {};\n");
        [$loaded] = ExtensionLoader::load($this->root . '/project', cliPaths: [$file], home: $this->root . '/home');
        $extension = $loaded[0];

        foreach ($tools as $tool) {
            $extension->api->registerTool($tool);
        }

        $set = new CustomToolSet(array_map(static fn (ToolDefinition $tool): RegisteredTool => new RegisteredTool($extension->path, $extension->resolved, $tool), $extension->api->tools()));
        $set->adopt($extension);
        $runner = new ExtensionRunner([], $this->root . '/project');
        $agent = new Agent(new AgentOptions());
        $loadout = new ToolLoadout($agent, $this->root . '/project', ['read'], $set, $runner, [], []);
        $loadout->useNamed($named);
        $set->onChange(static fn () => $loadout->refresh());
        $loadout->apply();

        return [new AgentSession($agent, $this->root . '/project', extensionRunner: $runner, loadout: $loadout), $loadout, $extension->api];
    }

    /** @return list<string> */
    private static function names(array $tools): array
    {
        return array_map(static fn ($tool): string => $tool->definition()->name, $tools);
    }

    public function testAnUnknownExposureIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The tool 'x' has exposure 'loud': it is one of direct, model-only, codemode, deferred, hidden.");
        self::tool('x', 'loud');
    }

    public function testOnlyADirectOrModelOnlyToolIsActivatedByRegisteringItAndAHiddenOneNeverIs(): void
    {
        [$session, $loadout] = $this->session([
            self::tool('plain'),
            self::tool('search', 'model-only'),
            self::tool('scripted', 'codemode'),
            self::tool('later', 'deferred'),
            self::tool('gone', 'hidden'),
            self::tool('off', defaultActive: false),
        ]);

        $this->assertSame(['read', 'plain', 'search'], $session->activeTools());

        // Naming activates any of them but a hidden one.
        $session->setActiveTools(['read', 'scripted', 'later', 'gone', 'off']);
        $this->assertSame(['read', 'scripted', 'later', 'off'], $session->activeTools());
        $this->assertSame(['read', 'scripted', 'later', 'off'], self::names($session->agent->tools()));

        // And every one is described, with its exposure.
        $this->assertSame(
            ['read' => 'direct', 'plain' => 'direct', 'search' => 'model-only', 'scripted' => 'codemode', 'later' => 'deferred', 'gone' => 'hidden', 'off' => 'direct'],
            array_column($loadout->describe(), 'exposure', 'name'),
        );
    }

    public function testThePromptListsOnlyTheToolsTheRequestDeclares(): void
    {
        // Upstream: "the tool list and rules must match the declarations the request carries" —
        // a tool `prepareLoadout` hides is active but in neither, its snippet and guideline too.
        $shown = new ToolDefinition('shown', 'shown', 'Shown.', ['type' => 'object', 'properties' => []], static fn () => new AgentToolResult([]), promptSnippet: 'The shown tool', promptGuidelines: ['Use shown first']);
        $kept = new ToolDefinition('kept', 'kept', 'Kept.', ['type' => 'object', 'properties' => []], static fn () => new AgentToolResult([]), promptSnippet: 'The kept tool', promptGuidelines: ['Use kept'], prepareLoadout: static fn (): array => ['hiddenDeclarations' => ['shown', 'read']]);
        [$session, $loadout] = $this->session([$shown, $kept]);

        $this->assertSame(['read', 'shown', 'kept'], $session->activeTools());
        $this->assertSame(['kept'], self::names($session->agent->tools()));
        $prompt = $loadout->systemPrompt();
        $this->assertStringContainsString("Available tools:\n- kept: The kept tool\n\nAdditional tools may be available.", $prompt);
        $this->assertStringNotContainsString('- shown:', $prompt);
        $this->assertStringNotContainsString('- read:', $prompt);
        $this->assertStringContainsString('Use kept', $prompt);
        $this->assertStringNotContainsString('Use shown first', $prompt);
    }

    public function testARunsNamedToolIsActivatedEvenWhenDeferredButNotWhenHidden(): void
    {
        [$session] = $this->session([self::tool('later', 'deferred'), self::tool('gone', 'hidden')], static fn (string $name): bool => in_array($name, ['later', 'gone'], true));

        $this->assertSame(['read', 'later'], $session->activeTools());
    }

    public function testAnMcpToolToolsKeepsWithoutMatchingItIsDeclaredOnlyWhenToolSearchCanLoadIt(): void
    {
        // Upstream's `_isActivatable()`: `--tools read,codemode` keeps MCP tools registered for
        // codemode and tool_search without naming them.
        $allowed = static fn (string $name): bool => in_array($name, ['read', 'codemode', 'named_direct'], true);
        [$session, $loadout] = $this->session([self::tool('mcp__gh__issues'), self::tool('mcp__gh__search', 'deferred'), self::tool('mcp__gh__named')]);
        $loadout->useAllowlist(static fn (string $name): bool => $allowed($name) || $name === 'mcp__gh__named');

        $session->setActiveTools(['read', 'mcp__gh__issues', 'mcp__gh__search', 'mcp__gh__named']);
        $this->assertSame(['read', 'mcp__gh__named'], $session->activeTools(), 'without tool_search, only the one --tools matched');

        // With tool_search registered, the deferred one can be loaded; a direct one never can.
        [$session, $loadout] = $this->session([self::tool('mcp__gh__issues'), self::tool('mcp__gh__search', 'deferred'), self::tool('tool_search', 'model-only', defaultActive: false)]);
        $loadout->useAllowlist(static fn (string $name): bool => in_array($name, ['read', 'tool_search'], true));
        $session->setActiveTools(['read', 'tool_search', 'mcp__gh__issues', 'mcp__gh__search']);
        $this->assertSame(['read', 'mcp__gh__search', 'tool_search'], $session->activeTools());

        // A tool that is not an MCP tool is the allowlist's to keep or drop, and not this rule's.
        [$session, $loadout] = $this->session([self::tool('zoo')]);
        $loadout->useAllowlist(static fn (string $name): bool => $name === 'read');
        $this->assertSame(['read', 'zoo'], $session->activeTools());
    }

    public function testCallableToolsAreTheActiveDirectOnesAndEveryCodemodeOrDeferredOne(): void
    {
        [$session, $loadout] = $this->session([
            self::tool('plain'),
            self::tool('idle', defaultActive: false),
            self::tool('search', 'model-only'),
            self::tool('scripted', 'codemode'),
            self::tool('later', 'deferred'),
            self::tool('gone', 'hidden'),
        ]);

        $this->assertSame(['read', 'plain', 'scripted', 'later'], self::names($loadout->callable()));

        // Upstream's `ctx.executeTool()` reaches them, active or not, and nothing else.
        $this->assertSame('later ran', Async::run(static fn () => $session->executeTool('later', []))->content[0]->text);
        $this->assertSame('scripted ran', Async::run(static fn () => $session->executeTool('scripted', []))->content[0]->text);

        foreach (['idle', 'search', 'gone'] as $name) {
            try {
                Async::run(static fn () => $session->executeTool($name, []));
                $this->fail("{$name} is not callable");
            } catch (AgentError $error) {
                $this->assertSame("No callable tool called '{$name}'.", $error->getMessage());
            }
        }
    }

    public function testTheToolExposureSettingIsOverTheDefinitionsTheNameFirstThenTheFirstPattern(): void
    {
        [$session, $loadout] = $this->session([self::tool('mcp_docs_search'), self::tool('mcp_docs_fetch'), self::tool('plain', 'deferred')]);

        $loadout->useToolExposure(['mcp_*' => 'deferred', 'mcp_docs_fetch' => 'direct', 'plain' => 'direct', '*' => 'hidden']);

        $this->assertSame('deferred', $loadout->exposure('mcp_docs_search'));
        $this->assertSame('direct', $loadout->exposure('mcp_docs_fetch'), 'the name wins over a pattern before it');
        $this->assertSame('hidden', $loadout->exposure('read'), 'a built-in too');
        $this->assertSame(['mcp_docs_fetch', 'plain'], $session->activeTools());
    }

    public function testAToolWhoseExposureBecomesDirectIsActivatedLikeANewOne(): void
    {
        [$session, , $api] = $this->session([self::tool('mcp_echo', 'hidden'), self::tool('kept')]);
        $this->assertSame(['read', 'kept'], $session->activeTools());
        $session->setActiveTools(['read']);

        // Upstream's `previousActivatedOnRegistration`: `hidden` to `direct` is a new tool to the
        // loadout; `kept`, active on registration all along, stays off as it was set.
        $api->registerTool(self::tool('mcp_echo'));
        $this->assertSame(['read', 'mcp_echo'], $session->activeTools());

        // An active tool stays active when its exposure changes, unless it becomes hidden.
        $api->registerTool(self::tool('mcp_echo', 'deferred'));
        $this->assertSame(['read', 'mcp_echo'], $session->activeTools());
        $api->registerTool(self::tool('mcp_echo', 'hidden'));
        $this->assertSame(['read'], $session->activeTools());
    }

    public function testTheNamespaceIsDescribed(): void
    {
        [, $loadout] = $this->session([self::tool('mcp__docs__search', 'deferred', namespace: ['name' => 'docs', 'description' => 'Docs.'])]);

        $described = array_column($loadout->describe(), null, 'name')['mcp__docs__search'];
        $this->assertSame(['name' => 'docs', 'description' => 'Docs.'], $described['namespace']);
        $this->assertArrayNotHasKey('namespace', array_column($loadout->describe(), null, 'name')['read']);
    }

    public function testTheSettingMergesTheProjectsKeysFirstAndNamesWhatItIgnored(): void
    {
        file_put_contents($this->root . '/home/settings.json', json_encode(['toolExposure' => ['*' => 'deferred', 'bash' => 'direct', 'web' => 'loud']]));
        file_put_contents($this->root . '/project/.pig/settings.json', json_encode(['toolExposure' => ['bash' => 'hidden', 'mcp_*' => 'codemode']]));
        $settings = Settings::load($this->root . '/project', $this->root . '/home');

        $this->assertSame(['bash' => 'hidden', 'mcp_*' => 'codemode', '*' => 'deferred'], $settings->toolExposure());
        $this->assertSame(
            [$this->root . '/home/settings.json: "toolExposure" gives "web" an exposure that is not one of direct, model-only, codemode, deferred, hidden, so that entry was ignored'],
            array_values(array_filter($settings->problems(), static fn (string $problem): bool => str_contains($problem, 'toolExposure'))),
        );

        file_put_contents($this->root . '/home/settings.json', json_encode(['toolExposure' => ['bash']]));
        $settings = Settings::load($this->root . '/project', $this->root . '/home');
        $this->assertSame(['bash' => 'hidden', 'mcp_*' => 'codemode'], $settings->toolExposure());
        $this->assertContains($this->root . '/home/settings.json: "toolExposure" must be an object of tool names or patterns to exposures, so it was ignored', $settings->problems());
    }
}
