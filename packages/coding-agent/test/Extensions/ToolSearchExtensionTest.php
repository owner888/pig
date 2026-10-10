<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Codemode\DeferredTools;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\HookContext;

/**
 * `pig-tool-search` on its own — upstream's `tool-search` extension: `tool_search` over whatever
 * `DeferredTools` lists, with no MCP extension beside it. `McpExtensionTest` has the two together.
 */
final class ToolSearchExtensionTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        DeferredTools::reset();
        $this->root = sys_get_temp_dir() . '/pig-tool-search-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home', 0o700, true);
        mkdir($this->root . '/project', 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        DeferredTools::reset();
    }

    private function load(): CustomToolSet
    {
        [$loaded, $errors] = ExtensionLoader::load(
            $this->root . '/project',
            cliPaths: [dirname(__DIR__, 4) . '/extensions/pig-tool-search/index.php'],
            home: $this->root . '/home',
        );
        $this->assertSame([], $errors);
        $set = new CustomToolSet([]);
        $set->adopt($loaded[0]);

        return $set;
    }

    private static function tool(string $name, string $description): CustomTool
    {
        return new CustomTool($name, $name, $description, ['type' => 'object', 'properties' => []], static fn () => new AgentToolResult([new TextContent('ran')]));
    }

    public function testToolSearchIsThereWhileSomethingIsListedAndLoadsTheMatches(): void
    {
        $set = $this->load();
        $this->assertSame([], $set->names(), 'nothing deferred, no tool_search');

        /** @var list<string> */
        $loaded = [];
        $list = static function (string $name, string $description, string $source, ?string $about) use (&$loaded): void {
            DeferredTools::register($name, $description, ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], ['name' => $source, 'description' => $about], static function () use (&$loaded, $name, $description): CustomTool {
                $loaded[] = $name;

                return self::tool($name, "{$description}\nSecond line.");
            });
        };
        $list('docs_search', 'Search the documentation.', 'docs', "Product docs.\nMore about them.");
        $list('gh_get_issue', 'Get a GitHub issue by number.', 'gh', null);

        $this->assertSame(['tool_search'], $set->names());
        $search = $set->find('tool_search');
        $this->assertStringContainsString("You have access to tools from the following sources:\n- docs: Product docs.\n- gh\n", $search->description);
        $this->assertSame(['query'], $search->parameters['required']);

        $ctx = new HookContext('.');
        $none = Async::run(static fn () => ($search->execute)('1', ['query' => 'weather forecast'], null, $ctx, null));
        $this->assertSame('No matching tools found.', $none->content[0]->text);
        $this->assertSame([], $loaded);

        $found = Async::run(static fn () => ($search->execute)('2', ['query' => 'issue'], null, $ctx, null));
        $this->assertSame("Loaded 1 tool. They are available from your next call:\n- gh_get_issue: Get a GitHub issue by number.", $found->content[0]->text);
        $this->assertSame(['loaded' => ['gh_get_issue']], $found->details);
        $this->assertSame(['gh_get_issue'], $loaded, 'loading is the listing extension\'s: its closure registers the tool');
        $this->assertTrue(DeferredTools::isLoaded('gh_get_issue'));

        // A loaded tool is not found again, and its source stays — after the ones still waiting.
        $again = Async::run(static fn () => ($set->find('tool_search')->execute)('3', ['query' => 'issue'], null, $ctx, null));
        $this->assertSame('No matching tools found.', $again->content[0]->text);
        $this->assertStringContainsString("sources:\n- docs: Product docs.\n- gh\n", $set->find('tool_search')->description);

        // Nothing listed any more, no tool_search.
        DeferredTools::remove(static fn (): bool => true);
        $this->assertSame([], $set->names());
    }

    public function testToolSearchRefusesAnEmptyQueryAndABadLimit(): void
    {
        $set = $this->load();
        DeferredTools::register('x_tool', 'A tool.', null, ['name' => 'x', 'description' => null], static fn (): CustomTool => self::tool('x_tool', 'A tool.'));
        $search = $set->find('tool_search');
        $ctx = new HookContext('.');

        foreach ([['query' => '  '], ['query' => 'x', 'limit' => 0], ['query' => 'x', 'limit' => 1.5]] as $params) {
            try {
                Async::run(static fn () => ($search->execute)('1', $params, null, $ctx, null));
                $this->fail('refused: ' . json_encode($params));
            } catch (AgentError $error) {
                $this->assertMatchesRegularExpression('/query must not be empty|limit must be a positive integer/', $error->getMessage());
            }
        }
    }
}
