<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Codemode\ToolSearch;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Extensions\RegisteredTool;
use Pig\CodingAgent\Extensions\ToolDefinition;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Tools\ToolLoadout;

/**
 * `pig-tool-search` — upstream's `tool-search` extension: `tool_search` registered inactive and
 * `model-only`, searching the registered `codemode` and `deferred` tools that are not active and
 * activating the matches. `McpExtensionTest` has it with the MCP extension.
 */
final class ToolSearchExtensionTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->root = sys_get_temp_dir() . '/pig-tool-search-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home', 0o700, true);
        mkdir($this->root . '/project', 0o755, true);
    }

    private static function tool(string $name, string $description, string $exposure = 'direct', ?array $namespace = null): ToolDefinition
    {
        return new ToolDefinition($name, $name, $description, ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], static fn () => new AgentToolResult([new TextContent('ran')]), exposure: $exposure, namespace: $namespace);
    }

    /**
     * A session with `pig-tool-search` and these tools beside it, as startup builds one.
     *
     * @param list<ToolDefinition> $tools
     * @return array{0: AgentSession, 1: CustomToolSet}
     */
    private function session(array $tools): array
    {
        [$loaded, $errors] = ExtensionLoader::load(
            $this->root . '/project',
            cliPaths: [dirname(__DIR__, 4) . '/extensions/pig-tool-search/index.php'],
            home: $this->root . '/home',
        );
        $this->assertSame([], $errors);
        $extension = $loaded[0];
        $set = new CustomToolSet([
            ...array_map(static fn (ToolDefinition $tool): RegisteredTool => new RegisteredTool($extension->path, $extension->resolved, $tool), $extension->api->tools()),
            ...array_map(static fn (ToolDefinition $tool): RegisteredTool => new RegisteredTool('<test>', '<test>', $tool), $tools),
        ]);
        $set->adopt($extension);
        $runner = new ExtensionRunner([new LoadedExtension($extension->path, $extension->resolved, $extension->name, $extension->api)], $this->root . '/project');
        $runner->initialize(static fn () => null);
        $agent = new Agent(new AgentOptions());
        $loadout = new ToolLoadout($agent, $this->root . '/project', ['read'], $set, $runner, [], []);
        $set->onChange(static fn () => $loadout->refresh());
        $loadout->apply();

        return [new AgentSession($agent, $this->root . '/project', extensionRunner: $runner, loadout: $loadout), $set];
    }

    private static function search(CustomToolSet $set, array $params): AgentToolResult
    {
        $search = $set->find(ToolSearch::TOOL_NAME);

        return Async::run(static fn () => ($search->execute)('1', $params, null, new ExtensionContext('.'), null));
    }

    public function testToolSearchIsRegisteredInactiveModelOnlyWithUpstreamsDescription(): void
    {
        [$session] = $this->session([]);
        $all = array_column($session->allTools(), null, 'name');

        $this->assertArrayHasKey('tool_search', $all);
        $this->assertFalse($all['tool_search']['active'], 'off until something names it, as upstream registers it');
        $this->assertSame('model-only', $all['tool_search']['exposure']);
        $this->assertSame("# Tool discovery\n\nSearches over deferred tool metadata with BM25 and exposes matching tools for the next model call.\n\nSome of the tools, such as tools of MCP servers, may not have been provided to you upfront, and you should use this tool (`tool_search`) to search for the required tools. For MCP tool discovery, always use `tool_search`.", $all['tool_search']['description']);
        $this->assertSame(['query'], $all['tool_search']['parameters']['required']);
        $this->assertSame(['read'], $session->activeTools());

        // Named, it is declared; and being `model-only`, no other tool can call it.
        $session->setActiveTools(['read', 'tool_search']);
        $this->assertSame(['read', 'tool_search'], $session->activeTools());

        try {
            Async::run(static fn () => $session->executeTool('tool_search', ['query' => 'x']));
            $this->fail('model-only is not callable');
        } catch (AgentError $error) {
            $this->assertSame("No callable tool called 'tool_search'.", $error->getMessage());
        }
    }

    public function testASearchActivatesTheMatchingDeferredAndCodemodeToolsThatAreNotActive(): void
    {
        [$session, $set] = $this->session([
            self::tool('docs_search', "Search the documentation.\nSecond line.", 'deferred', ['name' => 'docs', 'description' => 'Product docs.']),
            self::tool('gh_get_issue', 'Get a GitHub issue by number.', 'codemode'),
            self::tool('weather', 'The weather forecast.'),
            self::tool('secret_issue', 'Get an issue nobody may see.', 'hidden'),
        ]);
        $session->setActiveTools([...$session->activeTools(), 'tool_search']);
        $this->assertSame(['read', 'tool_search', 'weather'], $session->activeTools(), 'only a direct or model-only tool is activated by registering it');

        // A direct tool is not a candidate, even when it matches.
        $none = self::search($set, ['query' => 'weather forecast']);
        $this->assertSame('No matching tools found.', $none->content[0]->text);
        $this->assertSame(['loaded' => []], $none->details);

        $found = self::search($set, ['query' => 'issue']);
        $this->assertSame("Loaded 1 tool. They are available from your next call:\n- gh_get_issue: Get a GitHub issue by number.", $found->content[0]->text, 'a hidden tool is never found');
        $this->assertSame(['loaded' => ['gh_get_issue']], $found->details);
        $this->assertSame(['read', 'tool_search', 'gh_get_issue', 'weather'], $session->activeTools());

        // An active one is not found again; the namespace is searched too, and only the first line is shown.
        $this->assertSame('No matching tools found.', self::search($set, ['query' => 'issue'])->content[0]->text);
        $this->assertSame("Loaded 1 tool. They are available from your next call:\n- docs_search: Search the documentation.", self::search($set, ['query' => 'product'])->content[0]->text);
    }

    public function testToolSearchRefusesAnEmptyQueryAndABadLimit(): void
    {
        [, $set] = $this->session([self::tool('x_tool', 'A tool.', 'deferred')]);

        foreach ([['query' => '  '], ['query' => 'x', 'limit' => 0], ['query' => 'x', 'limit' => 1.5]] as $params) {
            try {
                self::search($set, $params);
                $this->fail('refused: ' . json_encode($params));
            } catch (AgentError $error) {
                $this->assertMatchesRegularExpression('/query must not be empty|limit must be a positive integer/', $error->getMessage());
            }
        }
    }
}
