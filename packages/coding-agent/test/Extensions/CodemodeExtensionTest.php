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
        $this->assertStringNotContainsString('- codemode:', $started->session->agent->state->systemPrompt);
        $this->stop($started);
    }

    public function testACodemodeServerActivatesTheToolAndTheSystemPromptSaysSo(): void
    {
        $started = $this->start(['fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()]]]);   // default exposure

        // The MCP tool is not on the model; codemode is, and it is the way to the MCP tool.
        $this->assertSame(['bash', 'codemode', 'edit', 'read', 'write'], self::toolNames($started));

        $prompt = $started->session->agent->state->systemPrompt;
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
        return $event->toolName === 'bash' ? new ToolCallEventResult(block: true, reason: 'No shells from scripts.') : null;
    });
};
PHP);
        $started = $this->start();
        $codemode = $started->customTools->find('codemode');

        $result = Async::run(static fn () => ($codemode->execute)('1', ['code' => 'return parallel_settled([fn () => $tools->bash(["command" => "id"]), fn () => "fine"]);'], null, $started->hooks->context(), null));

        $value = json_decode($result->content[count($result->content) - 1]->text, true);
        $this->assertFalse($value[0]['ok']);
        $this->assertStringContainsString('No shells from scripts.', $value[0]['error'], 'the guard that stops bash stops bash from a script');
        $this->assertSame('fine', $value[1]['value']);
        $this->assertSame('error', $result->details['calls'][0]['status']);
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
