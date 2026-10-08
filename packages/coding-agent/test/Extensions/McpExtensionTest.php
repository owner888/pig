<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Test\GlobalThemeFixture;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use PigMcp\McpConfig;
use PigMcp\McpTools;
use Pig\Codemode\ToolSearch;

final class McpExtensionTest extends TestCase
{
    use GlobalThemeFixture;

    private string $root;

    private string $home;

    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpGlobalTheme();
        Loop::reset();
        \Pig\Codemode\Registry::reset();
        $this->root = sys_get_temp_dir() . '/pig-mcp-ext-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->cwd = $this->root . '/project';
        mkdir($this->home, 0o700, true);
        mkdir($this->cwd, 0o755, true);
        putenv("PIG_HOME={$this->home}");

        $repo = dirname(__DIR__, 4);

        foreach (['ServerEntry', 'McpConfig', 'ServerConnection', 'McpTools', 'McpResources', 'McpSignInCancelledError', 'McpOauth', 'McpServerLog'] as $class) {
            if (!class_exists("PigMcp\\{$class}", false)) {
                require $repo . "/extensions/pig-mcp/{$class}.php";
            }
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
        putenv('PIG_HOME');
    }

    private static function fixtureServer(): string
    {
        return dirname(__DIR__, 4) . '/packages/mcp/test/fixtures/stdio-server.php';
    }

    // ---- config ---------------------------------------------------------------------------------

    public function testTheConfigIsReadFromBothFilesAndTheProjectWinsAName(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fs' => ['command' => 'npx', 'args' => ['-y', 'server-filesystem', '.']],
            'docs' => ['url' => 'https://example.com/mcp', 'headers' => ['Authorization' => 'Bearer ${DOCS}']],
        ]]));
        mkdir($this->cwd . '/.pig', 0o755, true);
        file_put_contents($this->cwd . '/.pig/mcp.json', json_encode(['mcpServers' => [
            'fs' => ['command' => 'uvx', 'args' => ['fs-mcp'], 'enabled' => false],
        ]]));

        $config = McpConfig::load($this->home, $this->cwd, projectTrusted: true);

        $this->assertSame([], $config->errors);
        $byName = array_combine(array_map(static fn ($e) => $e->name, $config->servers), $config->servers);
        $this->assertSame('project', $byName['fs']->scope, 'the project entry replaced the global one');
        $this->assertSame('uvx', $byName['fs']->config['command']);
        $this->assertFalse($byName['fs']->isEnabled());
        $this->assertSame('global', $byName['docs']->scope);
        $this->assertTrue($byName['docs']->isHttp());

        // Untrusted: the project file is not even opened.
        $gated = McpConfig::load($this->home, $this->cwd, projectTrusted: false);
        $this->assertSame('npx', array_combine(array_map(static fn ($e) => $e->name, $gated->servers), $gated->servers)['fs']->config['command']);
    }

    /** @return iterable<string, array{array<string, mixed>|mixed, string}> */
    public static function badEntries(): iterable
    {
        yield 'not an object' => ['npx', 'must be an object'];
        yield 'neither command nor url' => [['args' => []], 'needs either "command" (stdio) or "url"'];
        yield 'sse' => [['type' => 'sse', 'url' => 'https://x/sse'], 'legacy SSE transport is not supported'];
        yield 'bad url' => [['url' => 'ftp://x'], 'url must be an http or https URL'];
        yield 'bad exposure' => [['command' => 'x', 'exposure' => 'loud'], 'exposure must be one of'];
        yield 'bad toolExposure' => [['command' => 'x', 'toolExposure' => ['a' => 'loud']], 'toolExposure "a" must be one of'];
        yield 'bad args' => [['command' => 'x', 'args' => 'y'], 'args must be an array of strings'];
        yield 'bad env' => [['command' => 'x', 'env' => ['A' => 1]], 'env must map names to strings'];
        yield 'bad timeout' => [['command' => 'x', 'timeout' => 0], 'timeout must be a positive number'];
        yield 'bad headers' => [['url' => 'https://x/mcp', 'headers' => ['A' => 1]], 'headers must map names to strings'];
        yield 'bad callbackUrl' => [['url' => 'https://x/mcp', 'oauth' => ['callbackUrl' => 'https://evil/cb']], 'oauth.callbackUrl must be an http URI on localhost'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badEntries')]
    public function testABadEntryIsNamedAndTheOthersStillLoad(mixed $entry, string $complaint): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'bad' => $entry,
            'good' => ['command' => 'echo'],
        ]]));

        $config = McpConfig::load($this->home, $this->cwd, true);

        $this->assertCount(1, $config->errors);
        $this->assertStringContainsString($complaint, $config->errors[0]);
        $this->assertSame(['good'], array_map(static fn ($e) => $e->name, $config->servers));
    }

    public function testAProjectMcpFileIsOneOfTheThingsTrustIsAskedAbout(): void
    {
        mkdir($this->cwd . '/.pig', 0o755, true);
        file_put_contents($this->cwd . '/.pig/mcp.json', '{}');

        $this->assertSame(['.pig/mcp.json'], \Pig\CodingAgent\ProjectTrust::resources($this->cwd));
    }

    public function testABadServerNameIsRefused(): void
    {
        $this->assertStringContainsString('invalid server name', McpConfig::validate('my server', ['command' => 'x']));
        $this->assertIsArray(McpConfig::validate('my-server_2', ['command' => 'x']));
    }

    public function testToolExposureExactWinsThenFirstPatternThenTheServers(): void
    {
        $config = ['exposure' => 'deferred', 'toolExposure' => ['search_code' => 'direct', 'get_*' => 'codemode', 'delete_*' => 'hidden', '*' => 'direct']];

        $this->assertSame('direct', McpConfig::toolExposure($config, 'search_code'));
        $this->assertSame('codemode', McpConfig::toolExposure($config, 'get_issue'));
        $this->assertSame('hidden', McpConfig::toolExposure($config, 'delete_repo'));
        $this->assertSame('direct', McpConfig::toolExposure($config, 'anything'), 'the first pattern in the object that matches');
        $this->assertSame('deferred', McpConfig::toolExposure(['exposure' => 'deferred'], 'x'));
        $this->assertSame('codemode', McpConfig::toolExposure([], 'x'), "upstream's default, before pig's mapping");

        // All five of upstream's are pig's own now that codemode is ported; a sixth lands on the default.
        foreach (McpConfig::EXPOSURES as $exposure) {
            $this->assertSame($exposure, McpConfig::here($exposure));
        }

        $this->assertSame('codemode', McpConfig::here('something-newer'));
    }

    public function testEditingTheFileKeepsEverythingElseAndItsIndentation(): void
    {
        $path = $this->home . '/mcp.json';
        file_put_contents($path, "{\n\t\"autoEnableCodemode\": false,\n\t\"mcpServers\": {\n\t\t\"fs\": {\"command\": \"npx\"}\n\t}\n}\n");

        $this->assertFalse(McpConfig::add($path, 'docs', ['url' => 'https://x/mcp']));
        McpConfig::update($path, 'fs', ['enabled' => false, 'exposure' => 'direct']);
        $this->assertTrue(McpConfig::add($path, 'docs', ['url' => 'https://y/mcp']), 'replaced');

        $text = (string) file_get_contents($path);
        $parsed = json_decode($text, true);
        $this->assertFalse($parsed['autoEnableCodemode'], 'other content kept');
        $this->assertFalse($parsed['mcpServers']['fs']['enabled']);
        $this->assertSame('direct', $parsed['mcpServers']['fs']['exposure']);
        $this->assertSame('https://y/mcp', $parsed['mcpServers']['docs']['url']);
        $this->assertStringContainsString("\n\t\"mcpServers\"", $text, 'tabs stay tabs');

        $this->assertTrue(McpConfig::remove($path, 'docs'));
        $this->assertFalse(McpConfig::remove($path, 'docs'));
        $this->assertFalse(McpConfig::remove($this->home . '/nope.json', 'x'));

        // Enabling again and codemode exposure are the defaults, so the keys go rather than being written out.
        McpConfig::update($path, 'fs', ['enabled' => true, 'exposure' => 'codemode']);
        $this->assertSame(['command' => 'npx'], json_decode((string) file_get_contents($path), true)['mcpServers']['fs']);
    }

    // ---- tools ----------------------------------------------------------------------------------

    public function testToolNamesAreSanitizedShortenedAndKeptApart(): void
    {
        $this->assertSame('mcp__fs__read_file', McpTools::name('fs', 'read_file'));
        $this->assertSame('mcp__fs__a_b', McpTools::name('fs', 'a.b'));

        $long = McpTools::name('github', str_repeat('very_long_tool_name_', 5));
        $this->assertLessThanOrEqual(64, strlen($long));
        $this->assertMatchesRegularExpression('/_[0-9a-f]{8}$/', $long, 'a hash suffix tells it from its siblings');

        // `a.b` and `a_b` sanitise to one name; the second gets the hash.
        $taken = ['mcp__fs__a_b' => true];
        $second = McpTools::name('fs', 'a_b', static fn (string $n): bool => isset($taken[$n]));
        $this->assertNotSame('mcp__fs__a_b', $second);
        $this->assertStringStartsWith('mcp__fs__a_b_', $second);
    }

    public function testASchemaWithoutTypeOrPropertiesIsMadeAnObjectSchema(): void
    {
        $params = McpTools::parameters(['description' => 'no type']);
        $this->assertSame('object', $params['type']);
        $this->assertInstanceOf(\stdClass::class, $params['properties']);

        $params = McpTools::parameters(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);
        $this->assertSame(['a'], $params['required']);
    }

    public function testAResultsBlocksBecomeModelContentAndAnErrorResultRaises(): void
    {
        $result = McpTools::convert('fs', 'read', ['content' => [
            ['type' => 'text', 'text' => 'hello'],
            ['type' => 'image', 'data' => 'AAAA', 'mimeType' => 'image/png'],
            ['type' => 'resource_link', 'uri' => 'file:///x.txt', 'name' => 'x', 'mimeType' => 'text/plain', 'size' => 2048],
            ['type' => 'resource', 'resource' => ['uri' => 'file:///y.json', 'mimeType' => 'application/json', 'blob' => base64_encode('{"a":1}')]],
            ['type' => 'audio', 'data' => '', 'mimeType' => 'audio/wav'],
        ]]);

        $texts = array_map(static fn ($b) => $b instanceof TextContent ? $b->text : '[image]', $result->content);
        $this->assertSame('hello', $texts[0]);
        $this->assertSame('[image]', $texts[1]);
        $this->assertSame('[Resource file:///x.txt "x" (text/plain, 2 KB)]', $texts[2]);
        $this->assertSame('{"a":1}', $texts[3], 'a JSON blob is text');
        $this->assertSame('[audio audio/wav omitted]', $texts[4]);
        $this->assertSame(['server' => 'fs', 'tool' => 'read'], $result->details);

        try {
            McpTools::convert('fs', 'write', ['content' => [['type' => 'text', 'text' => 'disk full']], 'isError' => true]);
            $this->fail('an isError result is an error for the model');
        } catch (AgentError $error) {
            $this->assertSame('disk full', $error->getMessage());
        }

        try {
            McpTools::convert('fs', 'write', ['content' => [], 'isError' => true]);
            $this->fail('and one with nothing to say is still an error');
        } catch (AgentError $error) {
            $this->assertSame('MCP tool fs/write returned an error', $error->getMessage());
        }
    }

    public function testStructuredContentAloneBecomesJson(): void
    {
        $result = McpTools::convert('s', 't', ['content' => [], 'structuredContent' => ['answer' => 42]]);

        $this->assertStringContainsString('"answer": 42', $result->content[0]->text);
    }

    public function testLongTextIsCutInTheMiddleAndSavedWhole(): void
    {
        $text = implode("\n", array_map(static fn (int $n): string => "line {$n} " . str_repeat('x', 40), range(1, 1000)));
        $this->assertGreaterThan(McpTools::OUTPUT_MAX_BYTES, strlen($text));

        $result = McpTools::convert('s', 't', ['content' => [['type' => 'text', 'text' => $text], ['type' => 'image', 'data' => 'AA', 'mimeType' => 'image/png']]]);

        $this->assertCount(2, $result->content, 'one text block, and the image after it');
        $shown = $result->content[0]->text;
        $this->assertStringStartsWith('Warning: truncated output (original token count:', $shown);
        $this->assertStringContainsString('Total output lines: 1000', $shown);
        $this->assertStringContainsString('line 1 ', $shown, 'the start');
        $this->assertStringContainsString('line 1000 ', $shown, 'and the end');
        $this->assertMatchesRegularExpression('/…\d+ chars truncated…/', $shown);
        $this->assertInstanceOf(ImageContent::class, $result->content[1]);

        $path = $result->details['fullOutputPath'];
        $this->assertFileExists($path);
        $this->assertSame($text, file_get_contents($path));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4), 'only the user may read a result');
        unlink($path);
    }

    public function testTheMiddleCutLandsOnCharacterBoundaries(): void
    {
        // Four-byte characters, so any byte cut but a boundary one is a broken character.
        // 41 bytes of budget: 20 for the head (five whole characters), 21 for the tail, snapped
        // forward to a boundary — five more. Ninety go.
        [$cut, $removed] = McpTools::truncateMiddle(str_repeat('😀', 100), 41);

        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
        $this->assertSame(90, $removed);
        $this->assertSame('😀😀😀😀😀…90 chars truncated…😀😀😀😀😀', $cut);
    }

    // ---- the extension end to end ---------------------------------------------------------------

    public function testTheExtensionConnectsAtSessionStartAndTheToolsReachTheAgent(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'direct'],
            'broken' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--exit-on', 'initialize']],
        ]]));

        $repo = dirname(__DIR__, 4);
        [$loaded, $errors] = ExtensionLoader::load($this->cwd, cliPaths: [$repo . '/extensions/pig-mcp/index.php'], home: $this->home);
        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $ext = $loaded[0];

        $set = new CustomToolSet([]);
        $set->adopt($ext);
        $changes = 0;
        $set->onChange(static function () use (&$changes): void {
            $changes++;
        });

        $hooks = new HookRunner([new LoadedHook($ext->path, $ext->resolved, $ext->api)], $this->cwd);
        $ui = new NoticingUi();
        $hooks->initialize(static fn () => null, ui: $ui);

        Async::run(function () use ($hooks): void {
            $hooks->emit(new SessionStartEvent());
            // What the first prompt does: wait for the startup connections.
            $hooks->emitBeforeAgentStart('hi');
        });

        $this->assertSame(['mcp__fixture__echo'], $set->names(), 'the connected server\'s tool, under its MCP name');
        $this->assertGreaterThanOrEqual(1, $changes, 'and the agent was told to pick it up');

        $problems = implode("\n", $ui->notices);
        $this->assertStringContainsString('MCP servers need attention', $problems);
        $this->assertStringContainsString('broken: failed:', $problems);
        $this->assertStringNotContainsString('fixture:', $problems, 'the one that connected is not a problem');

        // The tool runs through the connection.
        $tool = $set->find('mcp__fixture__echo');
        $this->assertNotNull($tool);
        $result = Async::run(static fn () => ($tool->execute)('1', ['text' => 'round trip'], null, new \Pig\CodingAgent\Hooks\HookContext('.'), null));
        $this->assertSame('echo: round trip', $result->content[0]->text);

        // `/mcp` names both, with the failure's first line.
        $command = $ext->api->commands()['mcp'] ?? null;
        $this->assertNotNull($command);
        // No terminal: `/mcp` prints the status rather than opening the manager.
        Async::run(function () use ($command, $ui): void {
            ($command->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: false));
        });
        $status = end($ui->notices);
        $this->assertStringContainsString('fixture: connected, 1 tools (direct)', $status);
        // `broken` names no exposure, so it has upstream's default, which is codemode.
        $this->assertStringContainsString('broken: failed (codemode)', $status);
        $this->assertStringContainsString('MCP connection closed', $status);

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
        Loop::get()->tick();
        $this->assertTrue(Loop::get()->isIdle(), 'nothing left running after shutdown');
    }

    public function testACodemodeExposedToolIsNotOnTheModelButInTheCodemodeRegistry(): void
    {
        \Pig\Codemode\Registry::reset();
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()]],   // upstream's default exposure: codemode
        ]]));

        [$set, $hooks, $ui] = $this->start();

        // Nothing declared, nothing said: the tool is where codemode scripts find it.
        $this->assertSame([], $set->names());
        $this->assertStringNotContainsString('codemode', implode("\n", $ui->notices));
        $registered = \Pig\Codemode\Registry::all();
        $this->assertSame(['mcp__fixture__echo'], array_keys($registered));
        $this->assertSame('codemode', $registered['mcp__fixture__echo']['exposure']);
        $this->assertSame('fixture', $registered['mcp__fixture__echo']['namespace']['name']);

        // And the registry entry calls through to the server, answering the whole CallToolResult.
        $value = Async::run(static fn () => ($registered['mcp__fixture__echo']['execute'])(['text' => 'via script'], (new \Pig\Async\AbortController())->signal, new \Pig\CodingAgent\Hooks\HookContext('.')));
        $this->assertSame([['type' => 'text', 'text' => 'echo: via script']], $value['content']);

        // The status says so too.
        $this->assertStringContainsString('fixture: connected, 1 tools (codemode)', $this->mcpStatus());

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
        $this->assertSame([], \Pig\Codemode\Registry::all(), 'shutdown takes them out of the registry');
    }

    public function testADeferredToolIsDeclaredOnlyOnceToolSearchFindsIt(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'deferred'],
        ]]));

        [$set, $hooks, $ui] = $this->start();

        $this->assertSame(['tool_search'], $set->names(), 'the deferred tool is not declared yet');
        $search = $set->find('tool_search');
        $this->assertStringContainsString("You have access to tools from the following sources:\n- fixture", $search->description);

        // `/mcp` says how many are waiting.
        $command = $this->extension->api->commands()['mcp'];
        Async::run(fn () => ($command->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: false)));
        $this->assertStringContainsString('fixture: connected, 1 tools (1 waiting for tool_search) (deferred)', end($ui->notices));

        // A query that matches nothing loads nothing.
        $ctx = new \Pig\CodingAgent\Hooks\HookContext('.');
        $none = Async::run(static fn () => ($search->execute)('1', ['query' => 'weather forecast'], null, $ctx, null));
        $this->assertSame('No matching tools found.', $none->content[0]->text);
        $this->assertSame(['tool_search'], $set->names());

        // One that matches declares it, and says so for the next call.
        $found = Async::run(static fn () => ($search->execute)('2', ['query' => 'echo some text'], null, $ctx, null));
        $this->assertStringStartsWith("Loaded 1 tool. They are available from your next call:\n- mcp__fixture__echo:", $found->content[0]->text);
        $this->assertSame(['loaded' => ['mcp__fixture__echo']], $found->details);
        $this->assertSame(['mcp__fixture__echo', 'tool_search'], $set->names());

        // `tool_search` stays, as upstream's stays active, and still names the server its tools came from.
        $this->assertStringContainsString('- fixture', $set->find('tool_search')->description);

        // And the loaded tool works.
        $tool = $set->find('mcp__fixture__echo');
        $result = Async::run(static fn () => ($tool->execute)('3', ['text' => 'hi'], null, $ctx, null));
        $this->assertSame('echo: hi', $result->content[0]->text);

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    public function testToolSearchRefusesAnEmptyQueryAndABadLimit(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'deferred'],
        ]]));
        [$set, $hooks] = $this->start();
        $search = $set->find('tool_search');
        $ctx = new \Pig\CodingAgent\Hooks\HookContext('.');

        foreach ([['query' => '  '], ['query' => 'x', 'limit' => 0], ['query' => 'x', 'limit' => 1.5]] as $params) {
            try {
                Async::run(static fn () => ($search->execute)('1', $params, null, $ctx, null));
                $this->fail('refused: ' . json_encode($params));
            } catch (AgentError $error) {
                $this->assertMatchesRegularExpression('/query must not be empty|limit must be a positive integer/', $error->getMessage());
            }
        }

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    // ---- the manager ----------------------------------------------------------------------------

    public function testTheManagerListsServersAndDisablingOneWritesTheFileAndTakesItsToolsAway(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'direct'],
            'broken' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--exit-on', 'initialize']],
        ]]));

        [$set, $hooks] = $this->start();
        [$terminal, $tui, $ui] = $this->terminal();
        $this->assertSame(['mcp__fixture__echo'], $set->names());

        $command = $this->extension->api->commands()['mcp'];
        $ctx = new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true);
        $open = Async::spawn(static fn () => ($command->handler)('', $ctx));
        $this->turn();

        // The list: the failed server first, because it needs the user, and every row says its state.
        $screen = $this->screenOf($tui);
        $this->assertStringContainsString('MCP servers', $screen);
        $this->assertMatchesRegularExpression('/broken.*failed: MCP connection closed.*codemode.*global/', $screen);
        $this->assertMatchesRegularExpression('/fixture.*connected · 1 tool · direct · global/', $screen);
        $this->assertLessThan(strpos($screen, 'fixture'), strpos($screen, 'broken'), 'the one that needs attention first');

        // Down to `fixture`, Enter: its menu — the transport, the state, and the actions.
        $terminal->type("\e[B");
        $terminal->type("\r");
        $this->turn();
        $screen = $this->screenOf($tui);
        $this->assertStringContainsString('MCP server fixture', $screen);
        // The path is one line of text the view wraps at 100 columns; where the checkout lives
        // decides whether it fits, so it is compared with the wrap taken out.
        $this->assertStringContainsString(self::fixtureServer(), implode('', array_map(trim(...), explode("\n", $screen))));
        $this->assertStringContainsString('State: connected · 1 tool', $screen);
        $this->assertMatchesRegularExpression('/Tools\s+1 offered/', $screen);
        $this->assertMatchesRegularExpression('/Disable\s+saved to the global mcp.json/', $screen);

        // Tools: the one tool, with its name.
        $terminal->type("\r");
        $this->turn();
        $screen = $this->screenOf($tui);
        $this->assertStringContainsString('Tools of fixture', $screen);
        $this->assertStringContainsString('Exposure direct: declared to the model like built-in tools', $screen);
        $this->assertStringContainsString('echo', $screen);
        $terminal->type("\e");
        $this->turn();

        // Down to Disable (Tools, Reconnect, Exposure, Disable), Enter.
        $terminal->type("\e[B");
        $terminal->type("\e[B");
        $terminal->type("\e[B");
        $terminal->type("\r");
        $this->until(fn (): bool => str_contains($this->screenOf($tui), 'State: disabled'));

        $this->assertSame([], $set->names(), 'its tool is gone from the agent');
        $this->assertFalse(json_decode((string) file_get_contents($this->home . '/mcp.json'), true)['mcpServers']['fixture']['enabled'], 'and the file says so');
        $screen = $this->screenOf($tui);
        $this->assertStringContainsString('State: disabled', $screen);
        $this->assertMatchesRegularExpression('/Enable\s+saved to the global mcp.json/', $screen, 'the menu offers the way back');

        // Enable again: the connection comes back and so does the tool.
        $terminal->type("\r");
        // The tool is back the moment the connection is, which is *before* the menu is redrawn —
        // a key typed in that window lands on the status screen, which takes none. Wait for the menu.
        $this->until(fn (): bool => str_contains($this->screenOf($tui), 'State: connected'));
        $this->assertSame(['mcp__fixture__echo'], $set->names());
        $this->assertArrayNotHasKey('enabled', json_decode((string) file_get_contents($this->home . '/mcp.json'), true)['mcpServers']['fixture'], 'the default is not written out');

        // Esc back to the list, Esc closes the manager.
        $terminal->type("\e");
        $this->turn();
        $this->assertStringContainsString('MCP servers', $this->screenOf($tui));
        $terminal->type("\e");
        $this->turn();
        $this->assertTrue($open->isComplete(), 'the command returned');
        $this->assertStringNotContainsString('MCP servers', $this->screenOf($tui), 'and the overlay is empty');

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    public function testChangingTheExposureInTheManagerIsSavedAndReRegistersTheTools(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'direct'],
        ]]));

        [$set, $hooks] = $this->start();
        [$terminal, $tui, $ui] = $this->terminal();
        $this->assertSame(['mcp__fixture__echo'], $set->names());

        $command = $this->extension->api->commands()['mcp'];
        Async::spawn(static fn () => ($command->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true)));
        $this->turn();
        $terminal->type("\r");                 // fixture
        $this->turn();
        $terminal->type("\e[B");               // Tools → Reconnect
        $terminal->type("\e[B");               // → Exposure
        $terminal->type("\r");
        $this->turn();

        $screen = $this->screenOf($tui);
        $this->assertStringContainsString('Exposure of fixture', $screen);
        $this->assertStringContainsString('✓ direct', $screen);

        // Up from `direct` is `deferred`.
        $terminal->type("\e[A");
        $terminal->type("\r");
        $this->turn();

        $this->assertSame('deferred', json_decode((string) file_get_contents($this->home . '/mcp.json'), true)['mcpServers']['fixture']['exposure']);
        $this->assertSame(['tool_search'], $set->names(), 'the tool went behind tool_search; the one that was declared is gone');
        $this->assertStringContainsString('Exposure', $this->screenOf($tui));

        $terminal->type("\e");
        $terminal->type("\e");
        $this->turn();
        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    public function testTheListRedrawsWhileAServerIsStillConnecting(): void
    {
        // A server that sleeps before reading anything: the manager is opened before it is connected
        // and its row has to change from `connecting…` to `connected` without anybody pressing a key.
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'slow' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--slow-start'], 'exposure' => 'direct'],
        ]]));

        $repo = dirname(__DIR__, 4);
        [$loaded] = ExtensionLoader::load($this->cwd, cliPaths: [$repo . '/extensions/pig-mcp/index.php'], home: $this->home);
        $this->extension = $loaded[0];
        $set = new CustomToolSet([]);
        $set->adopt($this->extension);
        $hooks = new HookRunner([new LoadedHook($this->extension->path, $this->extension->resolved, $this->extension->api)], $this->cwd);
        [$terminal, $tui, $ui] = $this->terminal();
        $hooks->initialize(static fn () => null, ui: $ui);

        Async::spawn(fn () => $hooks->emit(new SessionStartEvent()));
        $this->turn(3);

        $command = $this->extension->api->commands()['mcp'];
        Async::spawn(static fn () => ($command->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true)));
        $this->turn(3);
        $this->assertMatchesRegularExpression('/slow.*(connecting…|starting)/', $this->screenOf($tui));

        $this->until(fn (): bool => str_contains($this->screenOf($tui), 'connected'));

        $this->assertMatchesRegularExpression('/slow.*connected · 1 tool/', $this->screenOf($tui), 'the row changed by itself');

        $terminal->type("\e");
        $this->turn();
        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    // ---- resources ------------------------------------------------------------------------------

    public function testAServerWithResourcesBringsTheThreeResourceToolsAndTheyWork(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'docs' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--resources'], 'exposure' => 'direct'],
            'plain' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'direct'],
        ]]));

        [$set, $hooks] = $this->start();

        $names = $set->names();
        sort($names);
        $this->assertSame(
            ['list_mcp_resource_templates', 'list_mcp_resources', 'mcp__docs__echo', 'mcp__plain__echo', 'read_mcp_resource'],
            $names,
            'the resource tools once, not per server, and declared because a server with resources is direct',
        );

        $ctx = new \Pig\CodingAgent\Hooks\HookContext('.');
        $call = static fn (string $tool, array $params) => Async::run(static fn () => ($set->find($tool)->execute)('1', $params, null, $ctx, null));
        $json = static fn (AgentToolResult $result): array => json_decode($result->content[0]->text, true);

        // Every page of every server with resources; icons, _meta and the MCP App resource left out.
        $all = $json($call('list_mcp_resources', []));
        $this->assertSame([
            ['server' => 'docs', 'uri' => 'file:///readme.md', 'name' => 'readme', 'mimeType' => 'text/markdown'],
            ['server' => 'docs', 'uri' => 'file:///notes.txt', 'name' => 'notes', 'mimeType' => 'text/plain', 'size' => 5],
        ], $all['resources']);
        $this->assertArrayNotHasKey('server', $all);
        $this->assertArrayNotHasKey('errors', $all);

        // One server: one page, and the cursor continues it.
        $first = $json($call('list_mcp_resources', ['server' => 'docs']));
        $this->assertSame('docs', $first['server']);
        $this->assertCount(1, $first['resources']);
        $this->assertSame('page2', $first['nextCursor']);
        $second = $json($call('list_mcp_resources', ['server' => 'docs', 'cursor' => 'page2']));
        $this->assertSame('notes', $second['resources'][0]['name']);
        $this->assertArrayNotHasKey('nextCursor', $second);

        $templates = $json($call('list_mcp_resource_templates', []));
        $this->assertSame([['server' => 'docs', 'uriTemplate' => 'file:///{path}', 'name' => 'any file']], $templates['resourceTemplates']);

        // Reading: text as text, several contents labelled, a binary one saved to a file, an empty one said so.
        $this->assertSame('# Hello', $call('read_mcp_resource', ['server' => 'docs', 'uri' => 'file:///readme.md'])->content[0]->text);
        $dir = $call('read_mcp_resource', ['server' => 'docs', 'uri' => 'file:///dir']);
        $texts = array_map(static fn ($b) => $b instanceof TextContent ? $b->text : '[image]', $dir->content);
        $this->assertSame(['file:///dir/a:', 'A', 'file:///dir/b.png:', '[image]'], $texts, 'a png resource reaches the model as an image');
        $this->assertSame('Resource file:///empty is empty.', $call('read_mcp_resource', ['server' => 'docs', 'uri' => 'file:///empty'])->content[0]->text);
        $this->assertSame(['server' => 'docs', 'tool' => 'read_mcp_resource'], $call('read_mcp_resource', ['server' => 'docs', 'uri' => 'file:///readme.md'])->details);

        // The refusals name what was wrong.
        foreach ([
            [['server' => 'plain'], 'MCP server "plain" has no resources. Servers with resources: docs'],
            [['cursor' => 'page2'], 'cursor can only be used when a server is specified'],
            [['server' => 7], 'server must be a string'],
        ] as [$params, $complaint]) {
            try {
                $call('list_mcp_resources', $params);
                $this->fail('refused: ' . json_encode($params));
            } catch (AgentError $error) {
                $this->assertSame($complaint, $error->getMessage());
            }
        }

        try {
            $call('read_mcp_resource', ['server' => 'docs']);
            $this->fail('uri required');
        } catch (AgentError $error) {
            $this->assertSame('uri must be provided', $error->getMessage());
        }

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    public function testTheResourceToolsTakeTheWidestExposureAndGoWhenNoServerHasResources(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'docs' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--resources'], 'exposure' => 'deferred'],
        ]]));

        [$set, $hooks] = $this->start();

        // A deferred server's resources: the tools wait behind tool_search, with the server's tool.
        $this->assertSame(['tool_search'], $set->names());
        $search = $set->find('tool_search');
        $this->assertStringContainsString('- mcp resources', $search->description, 'listed as a source of their own');
        $ctx = new \Pig\CodingAgent\Hooks\HookContext('.');
        $found = Async::run(static fn () => ($search->execute)('1', ['query' => 'list resources'], null, $ctx, null));
        $this->assertStringContainsString('list_mcp_resources', $found->content[0]->text);
        $this->assertContains('list_mcp_resources', $set->names(), 'loaded like any deferred tool');

        // Disabling the only server with resources takes them away, loaded or not.
        $command = $this->extension->api->commands()['mcp'];
        [$terminal, $tui, $ui] = $this->terminal();
        Async::spawn(static fn () => ($command->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true)));
        $this->turn();
        $terminal->type("\r");                               // docs
        $this->turn();
        $terminal->type("\e[B");                             // Tools → Reconnect
        $terminal->type("\e[B");                             // → Exposure
        $terminal->type("\e[B");                             // → Disable
        $terminal->type("\r");
        $this->until(fn (): bool => str_contains($this->screenOf($tui), 'State: disabled'));
        $this->assertSame([], $set->names(), 'no server with resources, no resource tools, no tool_search');

        $terminal->type("\e");
        $terminal->type("\e");
        $this->turn();
        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    public function testAResourceLinkNamesTheReaderOnlyWhileTheResourceToolsAreOnTheModel(): void
    {
        $withTools = McpTools::convert('docs', 't', ['content' => [['type' => 'resource_link', 'uri' => 'file:///x', 'name' => 'x']]], readableResources: true);
        $without = McpTools::convert('docs', 't', ['content' => [['type' => 'resource_link', 'uri' => 'file:///x', 'name' => 'x']]]);

        $this->assertSame('[Resource file:///x "x". Read it with read_mcp_resource (server "docs")]', $withTools->content[0]->text);
        $this->assertSame('[Resource file:///x "x"]', $without->content[0]->text);
    }

    // ---- the log --------------------------------------------------------------------------------

    public function testWhatAServerLogsGoesToMcpLogWithContinuationLinesIndented(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--log-on-call'], 'exposure' => 'direct'],
        ]]));

        [$set, $hooks] = $this->start();
        $tool = $set->find('mcp__fixture__echo');
        Async::run(static fn () => ($tool->execute)('1', ['text' => 'hi'], null, new \Pig\CodingAgent\Hooks\HookContext('.'), null));
        // The notification arrived before the reply, so the log is already written; a bare
        // `tick()` here would block in `select` on the server's pipes with nothing to read.

        $log = (string) file_get_contents($this->home . '/mcp.log');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z \[fixture\] warning echo: called with\n    \{"text":"hi"\}\n$/', $log);

        Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
    }

    public function testTheLogFormatsAnyShapeAndRotatesPastTheLimit(): void
    {
        $this->assertSame("1970-01-01T00:00:01.500Z [s] info plain\n", \PigMcp\McpServerLog::format('s', 'plain', 1.5));
        $this->assertSame("1970-01-01T00:00:00.000Z [s] info {\"a\":1}\n", \PigMcp\McpServerLog::format('s', ['data' => ['a' => 1]], 0.0), 'structured data is JSON');
        $this->assertSame("1970-01-01T00:00:00.000Z [s] info null\n", \PigMcp\McpServerLog::format('s', ['a' => 1], 0.0), "an object is the message record, as upstream reads it: no data is null");
        $this->assertSame("1970-01-01T00:00:00.000Z [s] error db: boom\n", \PigMcp\McpServerLog::format('s', ['level' => 'error', 'logger' => 'db', 'data' => 'boom'], 0.0));

        $path = $this->home . '/deep/mcp.log';
        $log = new \PigMcp\McpServerLog($path);
        $log->write('s', 'first');
        $this->assertFileExists($path, 'the directory is made');

        // Past the limit: the next write rotates first.
        file_put_contents($path, str_repeat('x', \PigMcp\McpServerLog::MAX_LOG_BYTES + 1));
        $log = new \PigMcp\McpServerLog($path);
        $log->write('s', 'after');
        $this->assertFileExists($path . '.1');
        $this->assertSame(\PigMcp\McpServerLog::MAX_LOG_BYTES + 1, filesize($path . '.1'));
        $this->assertStringEndsWith("[s] info after\n", (string) file_get_contents($path));

        // An unwritable path is not a failure.
        (new \PigMcp\McpServerLog('/dev/null/nope/mcp.log'))->write('s', 'lost');
        $this->addToAssertionCount(1);
    }

    // ---- OAuth ----------------------------------------------------------------------------------

    public function testAnHttpServerThatAnswers401IsNeedsAuthAndLoginSignsInThroughTheBrowser(): void
    {
        $server = new \Pig\Mcp\Test\FakeHttpMcpServer();
        $server->oauth = true;
        $server->requireToken = 'nothing-yet';
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'remote' => ['url' => $server->url(), 'exposure' => 'direct'],
        ]]));

        try {
            [$set, $hooks, $ui] = $this->start();

            // Not failed: waiting for a sign-in, and the report says how.
            $this->assertSame([], $set->names());
            $this->assertStringContainsString('remote: needs sign-in, run /mcp login remote', implode("\n", $ui->notices));
            $this->assertStringContainsString('remote: needs sign-in, run /mcp login remote (direct)', $this->mcpStatus());

            // `/mcp login`: the authorization URL is printed (and the browser opened, which a test
            // cannot see); the browser is played by hitting the callback with a code the fake
            // authorization server minted for the PKCE challenge in that URL.
            $command = $this->extension->api->commands()['mcp'];
            $ctx = new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true);
            $before = count($ui->notices);

            Async::run(function () use ($command, $ctx, $server, $ui, $before): void {
                $login = Async::spawn(static fn () => ($command->handler)('login remote', $ctx));
                $shown = null;

                // Wait for the authorization URL to be shown, then play the browser.
                while ($shown === null && !$login->isComplete()) {
                    Async::delay(0.02);

                    foreach (array_slice($ui->notices, $before) as $notice) {
                        if (preg_match('#in your browser:\n(\S+)#', $notice, $m) === 1) {
                            $shown = $m[1];
                        }
                    }
                }

                $this->assertNotNull($shown, 'the browser was sent somewhere');
                parse_str((string) parse_url($shown, PHP_URL_QUERY), $query);
                $code = $server->issueCode($query['code_challenge']);
                $redirect = $query['redirect_uri'] . '?code=' . urlencode($code) . '&state=' . urlencode($query['state']);
                $this->assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+/callback\?#', $redirect);
                (new \Pig\Ai\Http\HttpClient(timeout: 5.0))->send(new \Pig\Ai\Http\Request('GET', $redirect))->body->close();

                $login->await();
            });

            $this->assertStringContainsString('remote: connected, 1 tools (direct)', $this->mcpStatus());
            $this->assertSame(['mcp__remote__echo'], $set->names(), 'signed in, connected, and the tool is on the model');
            $this->assertStringContainsString('Signed in to MCP server "remote" (1 tools).', end($ui->notices));

            // The credentials file holds the tokens, keyed by URL, and is private.
            $auth = json_decode((string) file_get_contents($this->home . '/mcp-auth.json'), true);
            $this->assertSame($server->requireToken, $auth['mcp__remote|' . $server->url()]['tokens']['access_token'], 'stored under the server\'s own key, name and URL');
            $this->assertSame('0600', substr(sprintf('%o', fileperms($this->home . '/mcp-auth.json')), -4));

            // The pasted-URL prompt was asked and then abandoned when the callback won: the UI's
            // `input()` answers null here, which must not be read as a cancellation after the fact.
            $this->assertStringNotContainsString('cancelled', implode("\n", $ui->notices));

            // `/mcp logout` forgets them and the server goes back to waiting.
            Async::run(fn () => ($command->handler)('logout remote', $ctx));
            $this->assertStringContainsString('Signed out of MCP server "remote".', end($ui->notices));
            $this->assertArrayNotHasKey($server->url(), json_decode((string) file_get_contents($this->home . '/mcp-auth.json'), true));
            $this->assertStringContainsString('remote: needs sign-in', $this->mcpStatus());
            $this->assertSame([], $set->names(), 'and its tool is off the model');

            Async::run(fn () => $hooks->emit(new SessionShutdownEvent()));
        } finally {
            $server->stop();
        }
    }

    public function testAnExpiringTokenIsRefreshedBeforeItIsSentAndARejectedOneAfter(): void
    {
        $server = new \Pig\Mcp\Test\FakeHttpMcpServer();
        $server->oauth = true;
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'remote' => ['url' => $server->url(), 'exposure' => 'direct'],
        ]]));

        // Stored tokens from an earlier sign-in, about to expire. The fake server knows the refresh token.
        $server->refreshTokens[] = 'refresh-old';
        $server->requireToken = 'access-old';
        file_put_contents($this->home . '/mcp-auth.json', json_encode([$server->url() => [
            'serverUrl' => $server->url(),
            'clientInformation' => ['client_id' => 'client-1', 'redirect_uris' => ['http://127.0.0.1:1/callback']],
            'tokens' => ['access_token' => 'access-old', 'token_type' => 'Bearer', 'refresh_token' => 'refresh-old'],
            'tokensExpireAt' => (int) (microtime(true) * 1000) + 1_000,   // inside the 30s skew
        ]]));

        try {
            [$set] = $this->start();

            $this->assertSame(['mcp__remote__echo'], $set->names());
            $this->assertSame('refresh_token', $server->tokenRequests[0]['grant_type'], 'refreshed before the first request');
            $this->assertSame('refresh-old', $server->tokenRequests[0]['refresh_token']);
            $auth = json_decode((string) file_get_contents($this->home . '/mcp-auth.json'), true);
            // The seed above is the legacy shape, keyed by URL alone; the first server to load it
            // takes it over under its own key, so a second server at the same URL signs in by itself.
            $this->assertArrayNotHasKey($server->url(), $auth, 'the legacy entry was moved, not copied');
            $this->assertSame($server->requireToken, $auth['mcp__remote|' . $server->url()]['tokens']['access_token'], 'the new token is stored');
            $this->assertNotSame('refresh-old', $auth['mcp__remote|' . $server->url()]['tokens']['refresh_token'], 'and the rotated refresh token with it');

            // Now the server revokes the token out from under us: the next call gets a 401, the
            // provider refreshes, and the call is retried — the model sees one answer.
            $server->requireToken = 'revoked-elsewhere';
            $tool = $set->find('mcp__remote__echo');
            $result = Async::run(static fn () => ($tool->execute)('1', ['text' => 'still here'], null, new \Pig\CodingAgent\Hooks\HookContext('.'), null));
            $this->assertSame('echo: still here', $result->content[0]->text);
            $this->assertSame(2, count($server->tokenRequests), 'one more refresh');
        } finally {
            $server->stop();
        }
    }

    public function testAStepUpSignInAsksForTheGrantedScopeAsWellAsTheMissingOne(): void
    {
        // A server answering `insufficient_scope` may name only what it is missing. Asking for
        // just that gets a token that has lost what the old one had, the server asks again, and
        // the sign-ins never end. The new request carries both — and takes the browser route,
        // because a refresh keeps the granted scope and cannot widen it.
        $server = new \Pig\Mcp\Test\FakeHttpMcpServer();
        $server->oauth = true;
        $server->requireToken = 'access-old';
        $credentials = new \PigMcp\McpOauth($this->home . '/mcp-auth.json');
        file_put_contents($this->home . '/mcp-auth.json', json_encode(['mcp__remote|' . $server->url() => [
            'serverUrl' => $server->url(),
            'clientInformation' => ['client_id' => 'client-1', 'redirect_uris' => ['http://127.0.0.1:1/callback']],
            'tokens' => ['access_token' => 'access-old', 'token_type' => 'Bearer', 'refresh_token' => 'refresh-old', 'scope' => 'mcp:tools'],
        ]]));
        $server->refreshTokens[] = 'refresh-old';

        try {
            $asked = null;

            Async::run(function () use ($server, $credentials, &$asked): void {
                $credentials->signIn('remote', $server->url(), [], [
                    'resourceMetadataUrl' => null, 'scope' => 'mcp:files', 'error' => 'insufficient_scope', 'errorDescription' => null,
                ], [
                    'showAuthorizationUrl' => function (string $url) use ($server, &$asked): void {
                        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                        $asked = $query['scope'];
                        $redirect = $query['redirect_uri'] . '?code=' . urlencode($server->issueCode($query['code_challenge'])) . '&state=' . urlencode($query['state']);
                        Async::spawn(static fn () => (new \Pig\Ai\Http\HttpClient(timeout: 5.0))->send(new \Pig\Ai\Http\Request('GET', $redirect))->body->close());
                    },
                    'promptForRedirectUrl' => static function (\Pig\Async\AbortSignal $signal): ?string {
                        $parked = new \Pig\Async\Deferred();
                        $signal->onAbort(static fn () => $parked->isComplete() || $parked->complete(null));

                        return $parked->future->await();
                    },
                ], timeout: 5.0);
            });

            $this->assertSame('mcp:tools mcp:files', $asked, 'granted plus missing');
            $this->assertSame(['authorization_code'], array_column($server->tokenRequests, 'grant_type'), 'no refresh was tried: it cannot widen the scope');
            $auth = json_decode((string) file_get_contents($this->home . '/mcp-auth.json'), true);
            $this->assertSame($server->requireToken, $auth['mcp__remote|' . $server->url()]['tokens']['access_token']);
        } finally {
            $server->stop();
        }
    }

    public function testAServerWithItsOwnAuthorizationHeaderDoesNotUseOauth(): void
    {
        $server = new \Pig\Mcp\Test\FakeHttpMcpServer();
        $server->requireToken = 'static';
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'bearer' => ['url' => $server->url(), 'headers' => ['Authorization' => 'Bearer wrong']],
        ]]));

        try {
            [, , $ui] = $this->start();
            $status = $this->mcpStatus();
            $this->assertStringContainsString('bearer: failed', $status, 'a 401 with a header of its own is a wrong header, not a sign-in');
            $this->assertStringContainsString('check the Authorization header', $status);

            $command = $this->extension->api->commands()['mcp'];
            Async::run(fn () => ($command->handler)('login bearer', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: true)));
            $this->assertStringContainsString('No enabled MCP server uses OAuth', end($ui->notices));
        } finally {
            $server->stop();
        }
    }

    /** What `/mcp` prints with no terminal — the status, which is the extension's public face. */
    private function mcpStatus(): string
    {
        $command = $this->extension->api->commands()['mcp'];
        $ui = new NoticingUi();
        Async::run(fn () => ($command->handler)('', new \Pig\CodingAgent\Hooks\HookContext('.', ui: $ui, hasUi: false)));

        return (string) end($ui->notices);
    }

    // ---- the ranker -----------------------------------------------------------------------------

    public function testTokenizingSplitsCamelCaseDropsStopWordsAndSingularises(): void
    {
        // `GitHub` is two words to this tokenizer, as it is to upstream's: the split is at every
        // lower-to-upper boundary. A query saying `github` finds the `gh` server by its name instead.
        $this->assertSame(['list', 'git', 'hub', 'issue'], ToolSearch::tokenize('listGitHubIssues'));
        $this->assertSame(['search', 'code', 'repository'], ToolSearch::tokenize('Search the code in a repository'));
        $this->assertSame(['box', 'glass', 'query', 'ss'], ToolSearch::tokenize('boxes glasses queries ss'));
    }

    public function testBm25RanksTheDocumentThatSaysItMostAndKeepsOrderOnTies(): void
    {
        $documents = [
            ['name' => 'a', 'text' => 'read a file from disk'],
            ['name' => 'b', 'text' => 'list github issues and pull requests'],
            ['name' => 'c', 'text' => 'create a github issue'],
            ['name' => 'd', 'text' => 'nothing relevant here'],
        ];

        $ranked = ToolSearch::rank('github issue', $documents, 8);
        $this->assertSame(['c', 'b'], array_column($ranked, 'name'), 'the shorter document saying both terms wins');
        $this->assertSame(['c'], array_column(ToolSearch::rank('github issue', $documents, 1), 'name'));

        $this->assertSame([], ToolSearch::rank('the and of', $documents, 8), 'a query of stop words is no query');
        $this->assertSame(['a', 'b'], array_column(ToolSearch::rank('github', [['name' => 'a', 'text' => 'github'], ['name' => 'b', 'text' => 'github']], 8), 'name'));
    }

    public function testASearchDocumentCarriesTheSchemaAndTheServer(): void
    {
        $document = ToolSearch::document('mcp__gh__get_issue', [
            'name' => 'get_issue',
            'description' => 'Fetch one issue',
            'inputSchema' => ['type' => 'object', 'properties' => ['owner' => ['type' => 'string', 'description' => 'Repository owner'], 'number' => ['type' => 'integer']]],
        ], 'gh', 'GitHub tools');

        $this->assertSame('mcp__gh__get_issue', $document['name']);
        $this->assertSame('mcp__gh__get_issue mcp  gh  get issue Fetch one issue owner Repository owner number gh GitHub tools', $document['text']);
    }

    // ---- harness --------------------------------------------------------------------------------

    /**
     * A terminal UI over a fake terminal, started, so what is typed reaches the overlay.
     *
     * @return array{\Pig\Tui\Test\FakeTerminal, \Pig\Tui\TUI, \Pig\CodingAgent\Interactive\TerminalUi}
     */
    private function terminal(): array
    {
        $terminal = new \Pig\Tui\Test\FakeTerminal(100, 30);
        $tui = new \Pig\Tui\TuiMainScreen($terminal);
        $chat = new \Pig\Tui\Container();
        $overlay = new \Pig\Tui\Container();
        $editor = new \Pig\CodingAgent\Interactive\CustomEditor(new \Pig\Tui\Components\Editor(\Pig\CodingAgent\Theme\Themes::getEditorTheme()));
        $tui->addChild($chat);
        $tui->addChild($overlay);
        $tui->addChild($editor);
        $tui->setFocus($editor);

        $ui = new \Pig\CodingAgent\Interactive\TerminalUi(
            $tui,
            $chat,
            $overlay,
            $editor,
            new \Pig\CodingAgent\Interactive\FooterComponent(
                new \Pig\CodingAgent\Session\AgentSession(new \Pig\Agent\Agent(new \Pig\Agent\AgentOptions()), sys_get_temp_dir()),
                sys_get_temp_dir(),
            ),
        );
        $tui->start();

        return [$terminal, $tui, $ui];
    }

    private function screenOf(\Pig\Tui\TUI $tui): string
    {
        return implode("\n", array_map(\Pig\Tui\Ansi::strip(...), $tui->render(100)));
    }

    /** Turn the loop until `$done` says so, or five seconds — the subprocess steps take real time. */
    private function until(\Closure $done): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline && !$done()) {
            Loop::get()->delay(0.02, static fn () => null);
            Loop::get()->tick();
        }

        $this->assertTrue($done(), 'what was waited for did not happen within five seconds');
    }

    /** Turn the loop a few times so keys and spawned work land, without waiting for it to go idle. */
    private function turn(int $ticks = 6): void
    {
        for ($tick = 0; $tick < $ticks; $tick++) {
            Loop::get()->delay(0.0, static fn () => null);
            Loop::get()->tick();
        }
    }

    private \Pig\CodingAgent\Extensions\LoadedExtension $extension;

    /**
     * Load the extension from the repository, start a session on it and wait as the first prompt would.
     *
     * @return array{CustomToolSet, HookRunner, NoticingUi}
     */
    private function start(): array
    {
        $repo = dirname(__DIR__, 4);
        [$loaded, $errors] = ExtensionLoader::load($this->cwd, cliPaths: [$repo . '/extensions/pig-mcp/index.php'], home: $this->home);
        $this->assertSame([], $errors);
        $this->extension = $loaded[0];
        $set = new CustomToolSet([]);
        $set->adopt($this->extension);
        $hooks = new HookRunner([new LoadedHook($this->extension->path, $this->extension->resolved, $this->extension->api)], $this->cwd);
        $ui = new NoticingUi();
        $hooks->initialize(static fn () => null, ui: $ui);

        Async::run(function () use ($hooks): void {
            $hooks->emit(new SessionStartEvent());
            $hooks->emitBeforeAgentStart('hi');
        });

        return [$set, $hooks, $ui];
    }
}
