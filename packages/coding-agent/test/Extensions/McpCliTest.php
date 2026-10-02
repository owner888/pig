<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\ProjectTrust;
use PigMcp\McpCli;

final class McpCliTest extends TestCase
{
    private string $home;

    private string $cwd;

    /** @var list<string> */
    private array $out = [];

    /** @var list<string> */
    private array $err = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $root = sys_get_temp_dir() . '/pig-mcp-cli-' . bin2hex(random_bytes(4));
        $this->home = $root . '/home';
        $this->cwd = $root . '/project';
        mkdir($this->home, 0o700, true);
        mkdir($this->cwd, 0o755, true);
        $this->out = [];
        $this->err = [];

        $repo = dirname(__DIR__, 4);

        foreach (['ServerEntry', 'McpConfig', 'ServerConnection', 'McpTools', 'McpSignInCancelledError', 'McpOauth', 'McpServerLog', 'McpCli'] as $class) {
            if (!class_exists("PigMcp\\{$class}", false)) {
                require $repo . "/extensions/pig-mcp/{$class}.php";
            }
        }
    }

    /** @var list<string> URLs the CLI "opened" */
    private array $opened = [];

    private function mcp(string ...$args): int
    {
        $cli = new McpCli(
            $this->cwd,
            $this->home,
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            },
            function (string $url): void {
                $this->opened[] = $url;
            },
            // No terminal to paste into: the prompt waits until the callback settles it.
            static function (\Pig\Async\AbortSignal $signal): ?string {
                $released = new \Pig\Async\Deferred();
                $signal->onAbort(static function () use ($released): void {
                    if (!$released->isComplete()) {
                        $released->complete(null);
                    }
                });

                return $released->future->await();
            },
        );

        return $cli->run($args);
    }

    private static function fixtureServer(): string
    {
        return dirname(__DIR__, 4) . '/packages/mcp/test/fixtures/stdio-server.php';
    }

    /** @return array<string, mixed> */
    private function config(?string $path = null): array
    {
        return json_decode((string) file_get_contents($path ?? $this->home . '/mcp.json'), true);
    }

    public function testHelpIsPrintedForNothingHelpAndTheFlags(): void
    {
        foreach ([[], ['help'], ['--help'], ['list', '-h']] as $args) {
            $this->out = [];
            $this->assertSame(0, $this->mcp(...$args));
            $this->assertStringContainsString('pig mcp add <server>', implode("\n", $this->out));
        }
    }

    public function testAddingAStdioServerWritesTheGlobalFileAndSaysHowToCheck(): void
    {
        $this->assertSame(0, $this->mcp('add', 'fs', '--env', 'A=1', '--env', 'B=two', '--cwd', '/tmp', '--exposure', 'direct', '--', 'npx', '-y', 'server-fs', '--root', '.'));

        $this->assertSame([
            'command' => 'npx',
            'args' => ['-y', 'server-fs', '--root', '.'],
            'env' => ['A' => '1', 'B' => 'two'],
            'cwd' => '/tmp',
            'exposure' => 'direct',
        ], $this->config()['mcpServers']['fs']);
        $this->assertSame("Added global MCP server \"fs\" in {$this->home}/mcp.json.", $this->out[0]);
        $this->assertSame('Check it with: pig mcp list', $this->out[1]);

        // Again replaces, and says so.
        $this->assertSame(0, $this->mcp('add', 'fs', '--', 'uvx', 'fs-mcp'));
        $this->assertSame(['command' => 'uvx', 'args' => ['fs-mcp']], $this->config()['mcpServers']['fs']);
        $this->assertStringStartsWith('Replaced global MCP server "fs"', $this->out[2]);
    }

    public function testTheCommandsOwnOptionsPassThroughAfterTheServerName(): void
    {
        // `--root` is the command's, not ours: after the second positional, everything is positional.
        $this->assertSame(0, $this->mcp('add', 'fs', 'npx', '--root', '.'));
        $this->assertSame(['command' => 'npx', 'args' => ['--root', '.']], $this->config()['mcpServers']['fs']);
    }

    public function testAddingAnHttpServerWithABearerTokenVariable(): void
    {
        $this->assertSame(0, $this->mcp('add', 'gh', '--url', 'https://api.githubcopilot.com/mcp/', '--bearer-token-env-var', 'GITHUB_TOKEN', '--header', 'X-A=b'));

        $this->assertSame([
            'url' => 'https://api.githubcopilot.com/mcp/',
            'headers' => ['X-A' => 'b', 'Authorization' => 'Bearer ${GITHUB_TOKEN}'],
        ], $this->config()['mcpServers']['gh']);
    }

    public function testLocalWritesTheProjectFileAndWarnsWhenTheProjectIsNotTrusted(): void
    {
        $this->assertSame(0, $this->mcp('add', 'fs', '-l', '--', 'npx', 'x'));

        $this->assertFileExists($this->cwd . '/.pig/mcp.json');
        $this->assertSame('npx', $this->config($this->cwd . '/.pig/mcp.json')['mcpServers']['fs']['command']);
        $this->assertStringContainsString('Added project MCP server "fs"', $this->out[0]);
        $this->assertStringContainsString('The project is not trusted', $this->out[1]);

        ProjectTrust::remember([$this->cwd => true], $this->home);
        $this->out = [];
        $this->assertSame(0, $this->mcp('add', 'fs', '--local', '--', 'npx', 'x'));
        $this->assertStringNotContainsString('not trusted', implode("\n", $this->out));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function refusals(): iterable
    {
        yield 'no name' => [['add'], 'Usage: pig mcp add'];
        yield 'neither command nor url' => [['add', 'fs'], 'Usage: pig mcp add'];
        yield 'both command and url' => [['add', 'fs', '--url', 'https://x/mcp', '--', 'npx'], 'Usage: pig mcp add'];
        yield 'unknown option' => [['add', 'fs', '--bogus', '--', 'npx'], 'Unknown option --bogus'];
        yield 'option with no value' => [['add', 'fs', '--url'], '--url needs a value'];
        yield 'env on an http server' => [['add', 'fs', '--url', 'https://x/mcp', '--env', 'A=1'], '--env only applies to stdio servers'];
        yield 'header on a stdio server' => [['add', 'fs', '--header', 'A=1', '--', 'npx'], '--header only applies to HTTP servers (--url)'];
        yield 'bad pair' => [['add', 'fs', '--env', 'NOEQUALS', '--', 'npx'], '--env expects KEY=VALUE, got "NOEQUALS"'];
        yield 'bad exposure' => [['add', 'fs', '--exposure', 'loud', '--', 'npx'], 'exposure must be one of'];
        yield 'bad name' => [['add', 'my server', '--', 'npx'], 'invalid server name'];
        yield 'unknown command' => [['frobnicate'], 'Unknown mcp command "frobnicate"'];
        yield 'remove without a name' => [['remove'], 'Usage: pig mcp remove'];
        yield 'list with an argument' => [['list', 'fs'], 'Usage: pig mcp list [--json]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalNamesWhatWasWrongAndWritesNothing(array $args, string $complaint): void
    {
        $this->assertSame(1, $this->mcp(...$args));
        $this->assertStringContainsString($complaint, implode("\n", $this->err));
        $this->assertFileDoesNotExist($this->home . '/mcp.json');
    }

    public function testRemovingNamesTheOtherScopeWhenTheServerIsThere(): void
    {
        $this->mcp('add', 'fs', '--', 'npx', 'x');
        $this->mcp('add', 'docs', '-l', '--url', 'https://x/mcp');

        $this->assertSame(1, $this->mcp('remove', 'docs'));
        $this->assertStringContainsString("No global MCP server named \"docs\" in {$this->home}/mcp.json. It is defined in {$this->cwd}/.pig/mcp.json; use --local.", end($this->err));

        $this->assertSame(1, $this->mcp('remove', 'fs', '-l'));
        $this->assertStringContainsString('omit --local', end($this->err));

        $this->assertSame(0, $this->mcp('remove', 'fs'));
        $this->assertSame("Removed global MCP server \"fs\" from {$this->home}/mcp.json.", end($this->out));
        $this->assertArrayNotHasKey('fs', $this->config()['mcpServers']);

        $this->assertSame(1, $this->mcp('remove', 'nope'));
        $this->assertStringContainsString('No global MCP server named "nope"', end($this->err));
    }

    public function testListConnectsToEachServerAndExitsOneIfAnyFailed(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer()], 'exposure' => 'direct', 'toolExposure' => ['echo' => 'hidden']],
            'off' => ['command' => 'nope', 'enabled' => false],
        ]]));

        $this->assertSame(0, $this->mcp('list'));
        $text = implode("\n", $this->out);
        $this->assertStringContainsString('fixture: connected, 1 tool (direct, global)', $text);
        $this->assertStringContainsString('  ' . PHP_BINARY . ' ' . self::fixtureServer(), $text);
        $this->assertStringContainsString('  tools: echo [hidden]', $text, 'a tool whose exposure differs from the server\'s says so');
        $this->assertStringContainsString('off: disabled (codemode, global)', $text);

        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'broken' => ['command' => PHP_BINARY, 'args' => [self::fixtureServer(), '--exit-on', 'initialize']],
            'bad' => 'not an object',
        ]]));
        $this->out = [];
        $this->assertSame(1, $this->mcp('list'), 'a server that failed is exit 1');
        $text = implode("\n", $this->out);
        $this->assertStringContainsString('broken: failed (codemode, global)', $text);
        $this->assertStringContainsString('  MCP connection closed', $text);
        $this->assertStringContainsString('config error: ', $text);
    }

    public function testLoginRunsTheBrowserFlowAndLogoutForgetsIt(): void
    {
        $server = new \Pig\Mcp\Test\FakeHttpMcpServer();
        $server->oauth = true;
        $server->requireToken = 'nothing-yet';
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => ['remote' => ['url' => $server->url()]]]));

        try {
            // The list says it needs a sign-in and how to get one.
            $this->assertSame(1, $this->mcp('list'));
            $text = implode("\n", $this->out);
            $this->assertStringContainsString('remote: needs sign-in (codemode, global)', $text);
            $this->assertStringContainsString('  sign in with: pig mcp login remote', $text);

            // Login: the browser is played from a second fiber once the URL is shown.
            $this->out = [];
            $played = false;
            $exit = Async::run(function () use ($server, &$played): int {
                $browser = Async::spawn(function () use ($server, &$played): void {
                    while ($this->opened === []) {
                        Async::delay(0.02);
                    }

                    parse_str((string) parse_url($this->opened[0], PHP_URL_QUERY), $query);
                    $code = $server->issueCode($query['code_challenge']);
                    (new \Pig\Ai\Http\HttpClient(timeout: 5.0))->send(new \Pig\Ai\Http\Request('GET', $query['redirect_uri'] . '?code=' . urlencode($code) . '&state=' . urlencode($query['state'])))->body->close();
                    $played = true;
                });

                $exit = $this->mcp('login', 'remote', '--timeout', '5');
                $browser->await();

                return $exit;
            });

            $this->assertTrue($played);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Sign in to MCP server "remote" in your browser:', $this->out[0]);
            $this->assertSame('Signed in to MCP server "remote" (1 tools).', end($this->out));
            $this->assertSame($server->requireToken, json_decode((string) file_get_contents($this->home . '/mcp-auth.json'), true)[$server->url()]['tokens']['access_token']);

            // Again: already signed in, nothing opened.
            $this->out = [];
            $this->opened = [];
            $this->assertSame(0, $this->mcp('login', 'remote'));
            $this->assertSame('Already signed in to MCP server "remote" (1 tools).', $this->out[0]);
            $this->assertSame([], $this->opened);

            $this->assertSame(0, $this->mcp('logout', 'remote'));
            $this->assertSame('Signed out of MCP server "remote".', end($this->out));
            $this->assertSame(0, $this->mcp('logout', 'remote'));
            $this->assertSame('No stored credentials for MCP server "remote".', end($this->out));
        } finally {
            $server->stop();
        }
    }

    public function testLoginRefusesWhatCannotBeSignedInto(): void
    {
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => [
            'stdio' => ['command' => 'x'],
            'bearer' => ['url' => 'https://x/mcp', 'headers' => ['authorization' => 'Bearer t']],
        ]]));

        $this->assertSame(1, $this->mcp('login', 'nope'));
        $this->assertStringContainsString('No MCP server named "nope". Configured: stdio, bearer.', end($this->err));
        $this->assertSame(1, $this->mcp('login', 'stdio'));
        $this->assertStringContainsString('does not use OAuth', end($this->err));
        $this->assertSame(1, $this->mcp('logout', 'bearer'));
        $this->assertStringContainsString('does not use OAuth', end($this->err));
        file_put_contents($this->home . '/mcp.json', json_encode(['mcpServers' => ['remote' => ['url' => 'https://x/mcp']]]));
        $this->assertSame(1, $this->mcp('login', 'remote', '--timeout', '0'));
        $this->assertStringContainsString('--timeout must be a positive number', end($this->err));
        $this->assertSame(1, $this->mcp('login'));
        $this->assertStringContainsString('Usage: pig mcp login <server>', end($this->err));
    }

    public function testAddingAnHttpServerSaysItMayNeedASignInAndTakesTheOauthOptions(): void
    {
        $this->assertSame(0, $this->mcp('add', 'gh', '--url', 'https://x/mcp', '--oauth-client-id', 'cid', '--oauth-client-secret', '${GH_SECRET}', '--oauth-callback-port', '8765'));
        $this->assertSame(['url' => 'https://x/mcp', 'oauth' => ['clientId' => 'cid', 'clientSecret' => '${GH_SECRET}', 'callbackPort' => 8765]], $this->config()['mcpServers']['gh']);
        $this->assertSame('Check it with: pig mcp list. If it requires sign-in: pig mcp login gh', $this->out[1]);

        $this->assertSame(1, $this->mcp('add', 'fs', '--oauth-client-id', 'x', '--', 'npx'));
        $this->assertStringContainsString('--oauth-client-id only applies to HTTP servers', end($this->err));
    }

    public function testListAsJsonAndTheUntrustedNote(): void
    {
        mkdir($this->cwd . '/.pig', 0o755, true);
        file_put_contents($this->cwd . '/.pig/mcp.json', json_encode(['mcpServers' => ['local' => ['command' => 'x']]]));

        $this->assertSame(0, $this->mcp('list', '--json'));
        $data = json_decode(implode("\n", $this->out), true);
        $this->assertSame([], $data['servers'], 'the untrusted project file is not read');
        $this->assertStringContainsString('is ignored because the project is not trusted', $data['note']);

        $this->out = [];
        $this->assertSame(0, $this->mcp('list'));
        $this->assertStringContainsString('No MCP servers configured.', $this->out[0]);
        $this->assertStringContainsString('not trusted', end($this->out));
    }
}
