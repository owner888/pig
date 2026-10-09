<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Codemode\Registry;
use PigCodemode\CodemodeDescription;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\StartedSession;
use Pig\Test\WithoutProviderKeys;

/**
 * The codemode extension with the MCP extension beside it, through `CodingAgent::session()` —
 * which is how `bin/pig` loads them and the only place the system prompt, the hooks and the
 * agent's tools all meet.
 */
final class CodemodeExtensionTest extends TestCase
{
    use WithoutProviderKeys;

    private string $root;

    private string $home;

    private string $cwd;

    private string|false $realHome = false;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        Registry::reset();
        $this->realHome = getenv('HOME');
        $this->root = sys_get_temp_dir() . '/pig-codemode-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home';
        $this->cwd = $this->root . '/project';
        mkdir($this->home, 0o700, true);
        mkdir($this->cwd . '/.pig', 0o755, true);
        putenv('PIG_HOME=' . $this->home);
        putenv('PI_HOME=' . $this->root . '/pi');
        putenv('HOME=' . $this->root . '/nobody');
        $this->forgetProviderKeys();
        putenv('ANTHROPIC_API_KEY=test-key');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreProviderKeys();
        putenv('PIG_HOME');
        putenv('PI_HOME');
        // Unsetting `HOME` rather than restoring it blinded every later test that looks under
        // `~` — `fd is not installed` in `SearchToolsTest`, fourteen skips elsewhere.
        putenv($this->realHome === false ? 'HOME' : 'HOME=' . $this->realHome);
    }

    private static function fixtureServer(): string
    {
        return dirname(__DIR__, 4) . '/packages/mcp/test/fixtures/stdio-server.php';
    }

    /** Start a session with both extensions and let `session_start` connect the servers. */
    private function start(array $mcp = [], array $extra = []): StartedSession
    {
        if ($mcp !== []) {
            file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => $mcp]));
        }

        $settings = Settings::load($this->cwd, $this->home);
        $repo = dirname(__DIR__, 4);
        $arguments = [
            $this->cwd,
            $settings,
            Auth::inMemory($settings),
            'extensionPaths' => [$repo . '/extensions/pig-codemode/index.php', $repo . '/extensions/pig-mcp/index.php'],
            ...$extra,
        ];
        $started = Async::run(static fn (): StartedSession => CodingAgent::session(...$arguments));

        $session = $started->session;
        $started->hooks->initialize(
            static fn () => $started->model,
            note: static fn (string $customType, mixed $data) => $session->appendHookEntry($customType, $data),
        );

        Async::run(static function () use ($started): void {
            $started->hooks->emit(new SessionStartEvent());
            $started->hooks->emitBeforeAgentStart('hi');
        });

        return $started;
    }

    /** @return list<string> */
    private static function toolNames(StartedSession $started): array
    {
        $names = array_map(static fn ($t) => $t->definition()->name, $started->session->agent->tools());
        sort($names);

        return $names;
    }

    private function stop(StartedSession $started): void
    {
        Async::run(static fn () => $started->hooks->emit(new SessionShutdownEvent()));
    }

    public function testWithNoCodemodeServerAndNoSettingTheToolIsNotThere(): void
    {
        $started = $this->start(['fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'direct']]);

        $this->assertSame(['bash', 'edit', 'mcp__fixture__echo', 'read', 'write'], self::toolNames($started));
        $this->assertStringNotContainsString('- codemode:', $started->session->systemPrompt());
        $this->stop($started);
    }

    public function testACodemodeServerActivatesTheToolAndTheSystemPromptSaysSo(): void
    {
        $started = $this->start(['fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()]]]);   // default exposure

        // The MCP tool is not on the model; codemode is, and it is the way to the MCP tool.
        $this->assertSame(['bash', 'codemode', 'edit', 'read', 'write'], self::toolNames($started));

        $prompt = $started->session->systemPrompt();
        $this->assertStringContainsString('- codemode: Run PHP that calls other tools', $prompt, "upstream's promptSnippet, in the Available tools list");
        $this->assertStringContainsString('- Use codemode to batch independent tool calls (parallel_settled), chain them, or filter large output', $prompt, "and its guideline");

        $codemode = $started->customTools->find('codemode');
        $this->assertStringContainsString('## fixture', $codemode->description, 'the server is a namespace in the catalog');
        $this->assertStringContainsString('$tools->mcp__fixture__echo(array{text?: string} $input): array{', $codemode->description);

        // A script calls it and the result is the whole CallToolResult, which the script filters.
        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => '$r = $tools->mcp__fixture__echo(["text" => "hi"]); return strtoupper($r["content"][0]["text"]);'], null, $started->hooks->context(), null));
        $this->assertMatchesRegularExpression("/^Script completed\nWall time [\d.]+ seconds\nOutput:\n$/", $result->content[0]->text);
        $this->assertSame('ECHO: HI', $result->content[1]->text);
        $this->assertSame('mcp__fixture__echo', $result->details['calls'][0]['name']);
        $this->assertSame('ok', $result->details['calls'][0]['status']);

        // Shutting down takes it away again.
        $this->stop($started);
        $this->assertSame(['bash', 'edit', 'read', 'write'], self::toolNames($started));
    }

    public function testTheSettingTurnsItOnWithoutAnyServer(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();

        $this->assertContains('codemode', self::toolNames($started));
        $codemode = $started->customTools->find('codemode');
        $this->assertStringNotContainsString('Nested tools:', $codemode->description, 'nothing codemode-only to list; the agent\'s tools the model has already');

        // Upstream 1.0's lean description: the intro, one line per global, and the path of the
        // reference the model reads when it needs a detail — not the whole reference, every turn.
        $this->assertLessThan(1500, strlen($codemode->description), 'the description is read on every request');
        $this->assertStringContainsString(CodemodeDescription::DOCS_PATH, $codemode->description);
        $this->assertFileExists(CodemodeDescription::DOCS_PATH);
        $this->assertStringContainsString('## Store values', (string) file_get_contents(CodemodeDescription::DOCS_PATH));

        // The agent's own tools are callable from a script even though they are not listed.
        file_put_contents($this->cwd . '/note.txt', "hello from a file\n");
        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => 'return trim($tools->read(["path" => "note.txt"]));'], null, $started->hooks->context(), null));
        $this->assertSame('hello from a file', $result->content[count($result->content) - 1]->text);
        $this->stop($started);
    }

    public function testANestedCallGoesThroughTheToolCallHookLikeAnyOther(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        mkdir($this->home . '/hooks', 0o755, true);
        file_put_contents($this->home . '/hooks/guard.php', <<<'PHP'
<?php
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
return function ($pi): void {
    $pi->on('tool_call', function ($event) {
        file_put_contents(getenv('PIG_HOME') . '/seen-call-id', $event->toolCallId);

        return $event->toolName === 'bash' ? new ToolCallEventResult(block: true, reason: 'No shells from scripts.') : null;
    });
};
PHP);
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('call-7', ['code' => 'return parallel_settled([fn () => $tools->bash(["command" => "id"]), fn () => "fine"]);'], null, $started->hooks->context(), null));

        $value = json_decode($result->content[count($result->content) - 1]->text, true);
        $this->assertFalse($value[0]['ok']);
        $this->assertStringContainsString('No shells from scripts.', $value[0]['error'], 'the guard that stops bash stops bash from a script');
        $this->assertSame('fine', $value[1]['value']);
        $this->assertSame('error', $result->details['calls'][0]['status']);
        // The nested call's id is `<codemode call id>/<n>`, upstream's shape, and it is the id the
        // hook was handed — not a synthetic one the row alone knew.
        $this->assertSame('call-7/1', $result->details['calls'][0]['id']);
        $this->assertSame('call-7/1', file_get_contents($this->home . '/seen-call-id'));
        $this->stop($started);
    }

    public function testTextItemsAreNumberedAndWhatEchoWroteFollowsInAConsoleBlock(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => 'text("first"); echo "printed one\n"; text("second"); echo "printed two"; return "returned";'], null, $started->hooks->context(), null));

        // Upstream's `formatOutput()` then `joinAdjacentText()`: one text block, each item headed.
        $this->assertCount(2, $result->content, 'the header and one joined text block');
        $this->assertSame(
            "==> text 1/3 <==\nfirst\n==> text 2/3 <==\nsecond\n==> text 3/3 <==\nreturned\n<console_output>\nprinted one\nprinted two\n</console_output>",
            $result->content[1]->text,
        );

        // One item gets no header, and a script that only returns reads as it did before.
        $alone = Async::run(static fn () => ($codemode->execute)('2', ['code' => 'return "just this";'], null, $started->hooks->context(), null));
        $this->assertSame('just this', $alone->content[1]->text);
        $this->stop($started);
    }

    public function testOnModeTellsEachToolHowAScriptCallsItAndOnlyModeHidesThemFromTheModel(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $read = null;

        foreach ($started->session->agent->tools() as $tool) {
            if ($tool->definition()->name === 'read') {
                $read = $tool->definition();
            }
        }

        $this->assertNotNull($read);
        $this->assertStringEndsWith("\n\nCodemode: `\$tools->read([...])` resolves to a string.", $read->description, 'upstream\'s `on` mode: the declared tool says how a script calls it');
        $codemode = $started->customTools->find('codemode');
        $this->assertStringNotContainsString('### `read`', $codemode->description, 'in `on` mode the agent\'s own tools are not listed again');
        $this->stop($started);

        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true, 'mode' => 'only']]));
        $started = $this->start();
        $this->assertSame(['codemode'], self::toolNames($started), 'upstream\'s `only` mode: the model is offered scripts alone');
        $declared = null;

        foreach ($started->session->agent->tools() as $tool) {
            $declared = $tool->definition()->description;
        }

        $this->assertStringContainsString('### `read`', (string) $declared, 'and codemode lists what it hid');
        $this->assertStringContainsString('### `bash`', (string) $declared);

        // The hidden tools are still callable: the extension kept the list the loadout handed it.
        file_put_contents($this->cwd . '/note.txt', "hidden but reachable\n");
        $codemode = $started->customTools->find('codemode');
        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => 'return trim($tools->read(["path" => "note.txt"]));'], null, $started->hooks->context(), null));
        $this->assertSame('hidden but reachable', $result->content[count($result->content) - 1]->text);
        $this->stop($started);
    }

    public function testTheCodemodeToolAsksForGrammarSamplingSoAModelCanWriteRawSource(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $this->assertSame('grammar', $codemode->constrainedSampling['type']);
        $this->assertStringContainsString('@options', $codemode->constrainedSampling['variants']['openai_lark']);
        $this->assertSame(\Pig\Codemode\Source::GRAMMAR, $codemode->constrainedSampling['variants']['openai_lark']);
        $this->stop($started);
    }

    public function testDescribeNamespaceFindsAServerByAnyOfItsNamesAndSearchToolsFiltersByThem(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $space = ['name' => 'mcp__dev-radius', 'description' => 'the radius server', 'instructions' => 'be gentle'];
        Registry::register('mcp__dev-radius__search', 'search radius', ['type' => 'object', 'properties' => []], static fn (): string => 'found', $space, 'codemode');
        Registry::register('mcp__dev-radius__fetch', 'fetch a radius page', ['type' => 'object', 'properties' => []], static fn (): string => 'page', $space, 'codemode');
        Registry::register('mcp__other__search', 'search elsewhere', ['type' => 'object', 'properties' => []], static fn (): string => 'x', ['name' => 'mcp__other', 'description' => null], 'codemode');
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => <<<'PHP'
            return [
                describe_namespace('dev-radius'),
                describe_namespace('mcp__dev_radius')['tools'],
                describe_namespace('dev_radius')['name'],
                describe_namespace('nowhere'),
                array_column(search_tools('search', ['namespace' => 'dev_radius']), 'name'),
                array_column(search_tools('search', ['namespace' => 'mcp__other']), 'name'),
            ];
            PHP], null, $started->hooks->context(), null));

        $value = json_decode($result->content[count($result->content) - 1]->text, true);
        $this->assertSame(['name' => 'mcp__dev-radius', 'description' => 'the radius server', 'instructions' => 'be gentle', 'tools' => ['mcp__dev_radius__search', 'mcp__dev_radius__fetch']], $value[0]);
        $this->assertSame(['mcp__dev_radius__search', 'mcp__dev_radius__fetch'], $value[1]);
        $this->assertSame('mcp__dev-radius', $value[2]);
        $this->assertNull($value[3]);
        $this->assertSame(['mcp__dev_radius__search'], $value[4], 'the namespace filter takes the suffix in identifier form');
        $this->assertSame(['mcp__other__search'], $value[5]);
        $this->stop($started);
    }

    public function testTheModelsNamespaceAnswersTheCatalogueAndRefusesABadCallBeforeAnyProviderIsReached(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => <<<'PHP'
            $chat = $models->getModelOfType('chat', 'anthropic', 'claude-sonnet-4-5');
            $classifiers = $models->getModelsOfType('classifier');
            $tries = [];
            foreach ([
                fn () => $models->getModelOfType('oops', 'a', 'b'),
                fn () => $models->getModelOfType('chat', 'anthropic/claude-sonnet-4-5'),
                fn () => $models->classify(null, []),
                fn () => $models->classify($chat, ['state' => [], 'questions' => []]),
                fn () => $models->classify($classifiers[0], ['state' => ['a' => 1], 'questions' => ['q' => ['type' => 'score', 'instructions' => 'rate', 'criteria' => 'bad']]]),
                fn () => $models->generateImages($models->getModelsOfType('image')[0], ['prompt' => 'a cat']),
                fn () => $models->Classify(),
            ] as $try) {
                try { $try(); $tries[] = 'no error'; } catch (Throwable $e) { $tries[] = $e->getMessage(); }
            }
            return ['id' => $chat['id'], 'keys' => array_key_exists('headers', $chat), 'cost' => isset($chat['cost']['input']), 'classifiers' => count($classifiers) > 0, 'available' => $models->getAvailableOfType('chat', 'anthropic') !== [], 'tries' => $tries];
            PHP], null, $started->hooks->context(), null));

        $value = json_decode($result->content[count($result->content) - 1]->text, true);
        $this->assertSame('claude-sonnet-4-5', $value['id']);
        $this->assertFalse($value['keys'], 'headers can carry credentials and never reach a script');
        $this->assertTrue($value['cost'], 'the price list goes out under upstream\'s name');
        $this->assertTrue($value['classifiers']);
        $this->assertTrue($value['available'], 'the test key for anthropic counts as available');
        $this->assertStringContainsString('Unknown model type "oops". Use "chat", "image", or "classifier".', $value['tries'][0]);
        $this->assertStringContainsString('expects three strings', $value['tries'][1]);
        $this->assertStringContainsString('expects a classifier model as its first argument, got null. $models->getModelOfType() returns null', $value['tries'][2]);
        $this->assertStringContainsString('"anthropic/claude-sonnet-4-5" is a chat model, not a classifier model.', $value['tries'][3]);
        $this->assertStringContainsString('context.questions.q is a "score" question, so criteria must list the levels', $value['tries'][4]);
        $this->assertStringContainsString('context.input must be a non-empty list of blocks, got null', $value['tries'][5]);
        $this->assertStringContainsString('$models->Classify() does not exist. Did you mean $models->classify()?', $value['tries'][6]);
        $this->assertSame([], $result->details['calls'], 'nothing reached a provider, so no call row');
        $this->stop($started);
    }

    public function testAToolResultWithAPictureInItReachesTheScriptAsABlockImageCanShow(): void
    {
        // Upstream's `models.generateImages()` answers image blocks that `image()` shows as they
        // are. pig's pictures come back from tools — `read` on a PNG, `generate_image` — and a
        // script used to get the text half only: "Saved image to …", and then a second `read`
        // to show what it had just made. The pictures ride beside the text now, MCP-shaped.
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        // A 1×1 PNG is a valid PNG and is all `read` needs; nothing here reaches a provider.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        file_put_contents($this->cwd . '/dot.png', $png);
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => <<<'PHP'
            $r = $tools->read(['path' => 'dot.png']);
            image($r['images'][0]);
            return ['keys' => array_keys($r), 'mime' => $r['images'][0]['mimeType'], 'type' => $r['images'][0]['type']];
            PHP], null, $started->hooks->context(), null));

        $images = array_values(array_filter($result->content, static fn ($b): bool => $b instanceof \Pig\Ai\ImageContent));
        $this->assertCount(1, $images, 'the picture the script showed is on the result');
        $this->assertSame(base64_encode($png), $images[0]->data, 'byte for byte: no re-encoding on the way through the sandbox');

        // Upstream's `saveImages()`: the model has no other way to reach the bytes it is shown.
        $at = array_search($images[0], $result->content, true);
        $label = $result->content[$at - 1];
        $this->assertInstanceOf(\Pig\Ai\TextContent::class, $label);
        $this->assertMatchesRegularExpression('#^\[Image saved to (\S+\.png) \(image/png, \d+B\)\]$#', $label->text, 'the path line sits right in front of the picture');
        preg_match('#saved to (\S+\.png)#', $label->text, $m);
        $this->assertSame($png, file_get_contents($m[1]), 'the file holds the bytes the model was shown');
        unlink($m[1]);
        $value = json_decode($result->content[count($result->content) - 1]->text, true);
        $this->assertSame(['text', 'images'], $value['keys']);
        $this->assertSame('image/png', $value['mime']);
        $this->assertSame('image', $value['type']);
        $this->stop($started);
    }

    public function testAFailedScriptIsAnErrorThatStillShowsItsCalls(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        try {
            Async::run(static fn () => ($codemode->execute)('1', ['code' => '$x = $tools->read(["path" => "nope.txt"]); return $x;'], null, $started->hooks->context(), null));
            $this->fail('the script failed');
        } catch (\Pig\Agent\AgentError $error) {
            $this->assertStringContainsString("Script failed", $error->getMessage());
            $this->assertStringContainsString('Script error:', $error->getMessage());
            $this->assertStringContainsString('Tool calls made before the failure (they are not undone): read (error)', $error->getMessage());
            $this->assertSame('read', $error->details['calls'][0]['name']);
        }

        try {
            Async::run(static fn () => ($codemode->execute)('1', ['code' => '   '], null, $started->hooks->context(), null));
            $this->fail('refused');
        } catch (\Pig\Agent\AgentError $error) {
            $this->assertStringContainsString('Expected PHP source text', $error->getMessage());
        }

        $this->stop($started);
    }

    public function testTheStoreSurvivesBetweenScriptsThroughTheSessionFile(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');
        $ctx = $started->hooks->context();

        Async::run(static fn () => ($codemode->execute)('1', ['code' => 'store("count", 41); return null;'], null, $ctx, null));
        $result = Async::run(static fn () => ($codemode->execute)('2', ['code' => 'return load("count") + 1;'], null, $ctx, null));

        $this->assertSame('42', $result->content[count($result->content) - 1]->text);
        $entries = $started->store->customEntries('codemode-store');
        $this->assertCount(1, $entries, "upstream's entry type, so a conversation moved to pi keeps its store");
        $this->assertSame(['set' => ['count' => 41], 'delete' => []], $entries[0]->data);
        $this->stop($started);
    }

    public function testOutputPastTheBudgetIsCutInTheMiddleAndSaved(): void
    {
        file_put_contents($this->home . '/settings.json', json_encode(['codemode' => ['enabled' => true]]));
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => "// @options: {\"max_output_tokens\": 100}\nforeach (range(1, 300) as \$i) { text(\"line {\$i}\"); } return null;"], null, $started->hooks->context(), null));

        $text = $result->content[1]->text;
        $this->assertStringStartsWith('Warning: truncated output (original token count:', $text);
        $this->assertStringContainsString('line 1', $text);
        $this->assertStringContainsString('line 300', $text);
        $this->assertStringContainsString('chars truncated', $text);
        $this->assertFileExists($result->details['fullOutputPath']);
        $this->assertStringContainsString("line 150\n", (string) file_get_contents($result->details['fullOutputPath']));
        unlink($result->details['fullOutputPath']);
        $this->stop($started);
    }
}
