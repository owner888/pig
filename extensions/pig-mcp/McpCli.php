<?php

declare(strict_types=1);

namespace PigMcp;

use Closure;
use Pig\Async\Async;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Version;
use Pig\Tui\Style;

/**
 * `pig mcp`: add, remove and check MCP servers outside a session — upstream's `extensions/mcp/cli.js`.
 * Agents run it through `bash` to configure servers and verify an `mcp.json` they wrote; running
 * sessions pick the change up on their next start.
 *
 * `login`/`logout` arrive with OAuth. Writes nothing to a stream itself: the two closures do, so a
 * test reads what it would have printed.
 */
final class McpCli
{
    private const string HELP = <<<'TEXT'
        Usage:
          pig mcp add <server> [options] -- <command> [args...]
          pig mcp add <server> [options] --url <url>
          pig mcp remove <server> [-l]
          pig mcp list [--json]
          pig mcp login <server> [--timeout <seconds>]
          pig mcp logout <server>

        Configure and check MCP servers and sign in to OAuth servers without starting a session.
        Reads ~/.pig/agent/mcp.json and, in trusted projects, .pig/mcp.json.

        Commands:
          add <server>            Add or replace a server in mcp.json
          remove <server>         Remove a server from mcp.json
          list                    Show state, tools, and errors (exits 1 on failure)
          login <server>          Sign in through the browser
          logout <server>         Delete the stored OAuth credentials

        Options for add and remove:
          -l, --local             Use .pig/mcp.json in the current project instead of the global file

        Options for add:
          --url <url>             Streamable HTTP server URL (instead of a command)
          --env <KEY=VALUE>       Environment variable for a stdio server (repeatable)
          --cwd <dir>             Working directory for a stdio server
          --header <KEY=VALUE>    HTTP header (repeatable)
          --bearer-token-env-var <NAME>
                                  Send "Authorization: Bearer ${NAME}"
          --oauth-client-id <id>  Pre-registered OAuth client id
          --oauth-client-secret <secret>
                                  OAuth client secret (may be ${NAME} or !command)
          --oauth-callback-port <port>
                                  Fixed OAuth callback port
          --oauth-auth-server-metadata-url <url>
                                  Authorization server metadata document, for a server
                                  that advertises the wrong one or none
          --exposure <mode>       direct, deferred, or hidden (codemode is read as deferred)

        Other options:
          --json                  Print the list as JSON
          --timeout <seconds>     How long login waits for the browser (default: 300)
        TEXT;

    private const int DEFAULT_LOGIN_TIMEOUT = 300;

    private const string HELP_HINT = 'Use "pig mcp --help" for usage.';

    /** `-l`/`--local` match `pig install`. */
    private const array ALIASES = ['-l' => '--local'];

    /**
     * @param Closure(string): void $out
     * @param Closure(string): void $err
     * @param Closure(string): void|null $openUrl how the browser is opened; a test passes one that records
     * @param Closure(\Pig\Async\AbortSignal): ?string|null $askRedirectUrl how the pasted redirect URL is read; null means standard input when it is a terminal
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $agentDir,
        private readonly Closure $out,
        private readonly Closure $err,
        private readonly ?Closure $openUrl = null,
        private readonly ?Closure $askRedirectUrl = null,
        private readonly ?McpOauth $credentials = null,
    ) {
    }

    /** Run `pig mcp <args>` and answer the exit code. */
    public function run(array $args): int
    {
        $command = $args[0] ?? null;
        $rest = array_slice($args, 1);

        if ($command === null || $command === 'help' || in_array('--help', $args, true) || in_array('-h', $args, true)) {
            ($this->out)(self::HELP);

            return 0;
        }

        $projectConfig = $this->cwd . '/.pig/mcp.json';

        if ($command === 'add') {
            return $this->add($rest, $projectConfig);
        }

        if ($command === 'remove') {
            return $this->remove($rest, $projectConfig);
        }

        if ($command === 'list') {
            $parsed = $this->parse($rest, ['json' => 'flag']);

            if ($parsed === null) {
                return 1;
            }

            if ($parsed['positional'] !== []) {
                ($this->err)("Usage: pig mcp list [--json]\n" . self::HELP_HINT);

                return 1;
            }

            return $this->list(isset($parsed['values']['json']), $projectConfig);
        }

        if ($command === 'login' || $command === 'logout') {
            $parsed = $this->parse($rest, $command === 'login' ? ['timeout' => 'value'] : []);

            if ($parsed === null) {
                return 1;
            }

            $name = $parsed['positional'][0] ?? null;

            if ($name === null || count($parsed['positional']) > 1) {
                ($this->err)("Usage: pig mcp {$command} <server>\n" . self::HELP_HINT);

                return 1;
            }

            $trusted = ProjectTrust::decision($this->cwd, $this->agentDir) === true;
            $loaded = McpConfig::load($this->agentDir, $this->cwd, $trusted);
            $entry = null;

            foreach ($loaded->servers as $candidate) {
                if ($candidate->name === $name) {
                    $entry = $candidate;
                }
            }

            if ($entry === null) {
                $note = !$trusted && file_exists($projectConfig) ? " {$projectConfig} is ignored because the project is not trusted. Start pig in the project to trust it." : '';
                $configured = implode(', ', array_map(static fn (ServerEntry $e): string => $e->name, $loaded->servers)) ?: 'none';
                ($this->err)("No MCP server named \"{$name}\".{$note} Configured: {$configured}.");

                return 1;
            }

            $credentials = $this->credentials ?? new McpOauth(McpOauth::defaultPath($this->agentDir));
            $connection = new ServerConnection($entry, $this->cwd, Version::current(), credentials: $credentials);
            $url = $connection->oauthUrl();

            if ($url === null) {
                ($this->err)("MCP server \"{$name}\" does not use OAuth. Only HTTP servers without an Authorization header do.");

                return 1;
            }

            if ($command === 'logout') {
                $removed = $credentials->remove($name, $url);
                ($this->out)($removed ? "Signed out of MCP server \"{$name}\"." : "No stored credentials for MCP server \"{$name}\".");

                return 0;
            }

            $timeout = (float) ($parsed['values']['timeout'] ?? self::DEFAULT_LOGIN_TIMEOUT);

            if (!is_numeric($parsed['values']['timeout'] ?? self::DEFAULT_LOGIN_TIMEOUT) || $timeout <= 0) {
                ($this->err)('--timeout must be a positive number of seconds.');

                return 1;
            }

            return self::onTheLoop(function () use ($entry, $connection, $url, $timeout, $credentials): int {
                try {
                    return $this->login($entry, $connection, $url, $timeout, $credentials);
                } finally {
                    try {
                        $connection->close();
                    } catch (\Throwable) {
                        // Done with it either way.
                    }
                }
            });
        }

        ($this->err)("Unknown mcp command \"{$command}\".\n" . self::HELP_HINT);

        return 1;
    }

    // ---- login ----------------------------------------------------------------------------------

    private function login(ServerEntry $entry, ServerConnection $connection, string $url, float $timeout, McpOauth $credentials): int
    {
        $name = $entry->name;

        // Connecting first answers whether a sign-in is needed and records the server's challenge.
        try {
            $connection->client();
            ($this->out)("Already signed in to MCP server \"{$name}\" (" . count($connection->tools) . ' tools).');

            return 0;
        } catch (\Throwable) {
            if ($connection->state !== 'needs-auth') {
                ($this->err)("MCP server \"{$name}\" failed to connect: " . ($connection->error ?? 'unknown error'));

                return 1;
            }
        }

        $openUrl = $this->openUrl ?? static function (string $url): void {
            if (PHP_OS_FAMILY === 'Darwin') {
                \Pig\Tui\Process::run(['open', $url]);
            } elseif (PHP_OS_FAMILY === 'Linux') {
                \Pig\Tui\Process::run(['xdg-open', $url]);
            }
        };

        try {
            $credentials->signIn($name, $url, $connection->oauthSettings(), $connection->challenge, [
                'showAuthorizationUrl' => function (string $authorizationUrl) use ($name, $openUrl): void {
                    ($this->out)("Sign in to MCP server \"{$name}\" in your browser:\n{$authorizationUrl}");
                    $openUrl($authorizationUrl);
                },
                'promptForRedirectUrl' => fn (\Pig\Async\AbortSignal $signal): ?string => $this->waitForRedirectUrl($signal, $timeout),
            ], timeout: $timeout);
        } catch (McpSignInCancelledError) {
            ($this->err)("Sign-in to MCP server \"{$name}\" was cancelled or not completed within " . (int) round($timeout) . ' seconds.');

            return 1;
        } catch (\Throwable $error) {
            ($this->err)("Sign-in to MCP server \"{$name}\" failed: {$error->getMessage()}");

            return 1;
        }

        $connection->challenge = null;

        try {
            $connection->reconnect();
        } catch (\Throwable $error) {
            ($this->err)("Signed in, but {$error->getMessage()}");

            return 1;
        }

        ($this->out)("Signed in to MCP server \"{$name}\" (" . count($connection->tools) . ' tools).');

        return 0;
    }

    /**
     * The pasted redirect URL from a terminal; otherwise only the browser callback can finish the
     * sign-in. Answers null — cancelling — when the time runs out or the callback arrived first.
     */
    private function waitForRedirectUrl(\Pig\Async\AbortSignal $signal, float $timeout): ?string
    {
        if ($this->askRedirectUrl !== null) {
            return ($this->askRedirectUrl)($signal);
        }

        $answer = new \Pig\Async\Deferred();
        $timer = \Pig\Async\Loop::get()->delay($timeout, static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(null);
            }
        });
        $listener = $signal->onAbort(static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(null);
            }
        });
        $watcher = null;

        if (defined('STDIN') && function_exists('posix_isatty') && posix_isatty(STDIN)) {
            fwrite(STDERR, "If the browser cannot reach this machine, paste the URL it was redirected to: ");
            stream_set_blocking(STDIN, false);
            $watcher = \Pig\Async\Loop::get()->onReadable(STDIN, static function () use ($answer): void {
                $line = fgets(STDIN);

                if ($line !== false && !$answer->isComplete()) {
                    $answer->complete(trim($line));
                }
            });
        }

        try {
            return $answer->future->await();
        } finally {
            \Pig\Async\Loop::get()->cancel($timer);
            $signal->removeListener($listener);

            if ($watcher !== null) {
                \Pig\Async\Loop::get()->cancel($watcher);
            }
        }
    }

    // ---- add / remove ---------------------------------------------------------------------------

    private function add(array $args, string $projectConfig): int
    {
        $usage = "Usage: pig mcp add <server> [options] (--url <url> | -- <command> [args...])\n" . self::HELP_HINT;
        $parsed = $this->parse($args, [
            'local' => 'flag', 'url' => 'value', 'env' => 'list', 'cwd' => 'value', 'header' => 'list',
            'bearer-token-env-var' => 'value', 'oauth-client-id' => 'value', 'oauth-client-secret' => 'value',
            'oauth-callback-port' => 'value', 'oauth-auth-server-metadata-url' => 'value', 'exposure' => 'value',
        ], 2);

        if ($parsed === null) {
            return 1;
        }

        ['positional' => $positional, 'values' => $values, 'lists' => $lists] = $parsed;
        $name = $positional[0] ?? null;
        $command = array_slice($positional, 1);
        $url = $values['url'] ?? null;

        if ($name === null || ($url === null) === ($command === [])) {
            ($this->err)($usage);

            return 1;
        }

        $httpOnly = ['header', 'bearer-token-env-var', 'oauth-client-id', 'oauth-client-secret', 'oauth-callback-port', 'oauth-auth-server-metadata-url'];
        $stdioOnly = ['env', 'cwd'];

        foreach ($url === null ? $httpOnly : $stdioOnly as $option) {
            if (isset($values[$option]) || isset($lists[$option])) {
                ($this->err)("--{$option} only applies to " . ($url === null ? 'HTTP servers (--url)' : 'stdio servers') . '.');

                return 1;
            }
        }

        if ($url !== null) {
            $headers = $this->pairs('header', $lists['header'] ?? null);

            if ($headers === null) {
                return 1;
            }

            if (isset($values['bearer-token-env-var'])) {
                $headers['Authorization'] = 'Bearer ${' . $values['bearer-token-env-var'] . '}';
            }

            $oauth = [
                ...(isset($values['oauth-client-id']) ? ['clientId' => $values['oauth-client-id']] : []),
                ...(isset($values['oauth-client-secret']) ? ['clientSecret' => $values['oauth-client-secret']] : []),
                ...(isset($values['oauth-callback-port']) ? ['callbackPort' => (int) $values['oauth-callback-port']] : []),
                ...(isset($values['oauth-auth-server-metadata-url']) ? ['authServerMetadataUrl' => $values['oauth-auth-server-metadata-url']] : []),
            ];
            $config = ['url' => $url, ...($headers !== [] ? ['headers' => $headers] : []), ...($oauth !== [] ? ['oauth' => $oauth] : [])];
        } else {
            $env = $this->pairs('env', $lists['env'] ?? null);

            if ($env === null) {
                return 1;
            }

            $config = [
                'command' => $command[0],
                ...(count($command) > 1 ? ['args' => array_slice($command, 1)] : []),
                ...($env !== [] ? ['env' => $env] : []),
                ...(isset($values['cwd']) ? ['cwd' => $values['cwd']] : []),
            ];
        }

        if (isset($values['exposure'])) {
            $config['exposure'] = $values['exposure'];
        }

        $validated = McpConfig::validate($name, $config);

        if (is_string($validated)) {
            ($this->err)($validated);

            return 1;
        }

        $project = isset($values['local']);
        $path = $project ? $projectConfig : $this->agentDir . '/mcp.json';
        $scope = $project ? 'project' : 'global';

        try {
            $replaced = McpConfig::add($path, $name, $validated);
        } catch (\Throwable $error) {
            ($this->err)("Could not update {$path}: {$error->getMessage()}");

            return 1;
        }

        ($this->out)(($replaced ? 'Replaced' : 'Added') . " {$scope} MCP server \"{$name}\" in {$path}.");

        if ($project && ProjectTrust::decision($this->cwd, $this->agentDir) !== true) {
            ($this->out)("The project is not trusted, so {$path} is ignored until you start pig in the project and trust it.");
        }

        // HTTP servers without an Authorization header may use OAuth.
        $mayNeedSignIn = isset($validated['url'])
            && array_filter(array_keys($validated['headers'] ?? []), static fn (string $h): bool => strtolower($h) === 'authorization') === [];
        ($this->out)('Check it with: pig mcp list' . ($mayNeedSignIn ? ". If it requires sign-in: pig mcp login {$name}" : ''));

        return 0;
    }

    private function remove(array $args, string $projectConfig): int
    {
        $parsed = $this->parse($args, ['local' => 'flag']);

        if ($parsed === null) {
            return 1;
        }

        $name = $parsed['positional'][0] ?? null;

        if ($name === null || count($parsed['positional']) > 1) {
            ($this->err)("Usage: pig mcp remove <server> [-l]\n" . self::HELP_HINT);

            return 1;
        }

        $project = isset($parsed['values']['local']);
        $path = $project ? $projectConfig : $this->agentDir . '/mcp.json';
        $scope = $project ? 'project' : 'global';

        try {
            $removed = McpConfig::remove($path, $name);
        } catch (\Throwable $error) {
            ($this->err)("Could not update {$path}: {$error->getMessage()}");

            return 1;
        }

        if ($removed) {
            ($this->out)("Removed {$scope} MCP server \"{$name}\" from {$path}.");

            return 0;
        }

        $other = null;

        foreach (McpConfig::load($this->agentDir, $this->cwd, true)->servers as $entry) {
            if ($entry->name === $name && $entry->scope !== $scope) {
                $other = $entry;
            }
        }

        ($this->err)("No {$scope} MCP server named \"{$name}\" in {$path}."
            . ($other !== null ? " It is defined in {$other->source}" . ($other->scope === 'project' ? '; use --local' : '; omit --local') . '.' : ''));

        return 1;
    }

    // ---- list -----------------------------------------------------------------------------------

    private function list(bool $json, string $projectConfig): int
    {
        $trusted = ProjectTrust::decision($this->cwd, $this->agentDir) === true;
        $loaded = McpConfig::load($this->agentDir, $this->cwd, $trusted);
        $note = !$trusted && file_exists($projectConfig)
            ? "{$projectConfig} is ignored because the project is not trusted. Start pig in the project to trust it."
            : null;

        $reports = self::onTheLoop(function () use ($loaded): array {
            $reports = [];

            foreach ($loaded->servers as $entry) {
                $report = [
                    'name' => $entry->name,
                    'scope' => $entry->scope,
                    'source' => $entry->source,
                    'enabled' => $entry->isEnabled(),
                    'exposure' => $entry->exposure(),
                    'transport' => $entry->describeTransport(),
                    'state' => 'disabled',
                    'tools' => [],
                ];

                if (!$entry->isEnabled()) {
                    $reports[] = $report;
                    continue;
                }

                $connection = new ServerConnection($entry, $this->cwd, Version::current(), credentials: $this->credentials ?? new McpOauth(McpOauth::defaultPath($this->agentDir)), log: new McpServerLog($this->agentDir . '/mcp.log'));

                try {
                    $connection->client();
                } catch (\Throwable) {
                    // The connection records the state and error.
                }

                $report['state'] = $connection->state;
                $report['tools'] = array_map(static fn (array $tool): string => (string) $tool['name'], $connection->tools);
                $overrides = [];

                foreach ($connection->tools as $tool) {
                    $exposure = McpConfig::toolExposure($entry->config, (string) $tool['name']);

                    if ($exposure !== $report['exposure']) {
                        $overrides[(string) $tool['name']] = $exposure;
                    }
                }

                if ($overrides !== []) {
                    $report['toolExposure'] = $overrides;
                }

                if ($connection->hasResources) {
                    try {
                        $report['resources'] = count($connection->allResources());
                        $report['resourceTemplates'] = count($connection->allResourceTemplates());
                    } catch (\Throwable) {
                        $report['resources'] = 0;
                        $report['resourceTemplates'] = 0;
                    }
                }

                if ($connection->state !== 'connected' && $connection->error !== null) {
                    $report['error'] = $connection->error;
                }

                try {
                    $connection->close();
                } catch (\Throwable) {
                    // Listing is done with it either way.
                }

                $reports[] = $report;
            }

            return $reports;
        });

        $failed = $loaded->errors !== [] || array_filter($reports, static fn (array $r): bool => $r['enabled'] && $r['state'] !== 'connected') !== [];

        if ($json) {
            ($this->out)(json_encode(['servers' => $reports, 'errors' => $loaded->errors, ...($note !== null ? ['note' => $note] : [])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? 1 : 0;
        }

        if ($reports === [] && $loaded->errors === []) {
            ($this->out)("No MCP servers configured. Add them to {$this->agentDir}/mcp.json or .pig/mcp.json.");
        }

        foreach ($reports as $report) {
            $count = count($report['tools']);
            $state = match ($report['state']) {
                'connected' => "connected, {$count} tool" . ($count === 1 ? '' : 's'),
                'needs-auth' => 'needs sign-in',
                default => $report['state'],
            };
            ($this->out)("{$report['name']}: {$state} ({$report['exposure']}, {$report['scope']})");
            ($this->out)("  {$report['transport']}");

            if ($report['state'] === 'needs-auth') {
                ($this->out)("  sign in with: pig mcp login {$report['name']}");
            }

            if ($report['tools'] !== []) {
                $tools = array_map(
                    static fn (string $tool): string => isset($report['toolExposure'][$tool]) ? "{$tool} [{$report['toolExposure'][$tool]}]" : $tool,
                    $report['tools'],
                );
                ($this->out)('  tools: ' . implode(', ', $tools));
            }

            if (isset($report['resources'])) {
                ($this->out)("  resources: {$report['resources']}, URI templates: {$report['resourceTemplates']}");
            }

            if (isset($report['error'])) {
                ($this->out)('  ' . str_replace("\n", "\n  ", $report['error']));
            }
        }

        foreach ($loaded->errors as $error) {
            ($this->out)("config error: {$error}");
        }

        if ($note !== null) {
            ($this->out)($note);
        }

        return $failed ? 1 : 0;
    }

    /**
     * Run `$work` on the loop: in a run of its own from a plain script, or right here when the
     * caller is already a coroutine (a test driving the CLI from inside `Async::run()`).
     */
    private static function onTheLoop(Closure $work): mixed
    {
        return \Fiber::getCurrent() === null ? Async::run($work) : $work();
    }

    // ---- arguments ------------------------------------------------------------------------------

    /**
     * Parse `--name value` options; null and a complaint for an unknown one. `--` ends the
     * options, as does reaching `$maxPositionals` positional arguments: the rest are positional,
     * so a command's own options (`add <server> <command> --flag`) are passed through.
     *
     * @param array<string, 'flag'|'value'|'list'> $known
     * @return array{positional: list<string>, values: array<string, string|true>, lists: array<string, list<string>>}|null
     */
    private function parse(array $args, array $known, int $maxPositionals = PHP_INT_MAX): ?array
    {
        $positional = [];
        $values = [];
        $lists = [];
        $count = count($args);

        for ($index = 0; $index < $count; $index++) {
            $arg = self::ALIASES[$args[$index]] ?? $args[$index];

            if ($arg === '--' || count($positional) >= $maxPositionals) {
                $positional = [...$positional, ...array_slice($args, $arg === '--' ? $index + 1 : $index)];
                break;
            }

            if (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
                continue;
            }

            $name = substr($arg, 2);
            $kind = $known[$name] ?? null;

            if ($kind === null) {
                ($this->err)("Unknown option {$arg}.\n" . self::HELP_HINT);

                return null;
            }

            if ($kind === 'flag') {
                $values[$name] = true;
                continue;
            }

            $value = $args[++$index] ?? null;

            if ($value === null) {
                ($this->err)("{$arg} needs a value.");

                return null;
            }

            if ($kind === 'list') {
                $lists[$name][] = $value;
            } else {
                $values[$name] = $value;
            }
        }

        return ['positional' => $positional, 'values' => $values, 'lists' => $lists];
    }

    /**
     * `KEY=VALUE` pairs of a repeatable option.
     *
     * @param list<string>|null $pairs
     * @return array<string, string>|null
     */
    private function pairs(string $option, ?array $pairs): ?array
    {
        $record = [];

        foreach ($pairs ?? [] as $pair) {
            $separator = strpos($pair, '=');

            if ($separator === false || $separator === 0) {
                ($this->err)("--{$option} expects KEY=VALUE, got \"{$pair}\".");

                return null;
            }

            $record[substr($pair, 0, $separator)] = substr($pair, $separator + 1);
        }

        return $record;
    }

    /** What `bin/pig mcp` calls: load the extension's classes, run, print, and answer the exit code. */
    public static function main(array $args, string $cwd): int
    {
        foreach (['ServerEntry', 'McpConfig', 'ServerConnection', 'McpTools', 'McpSignInCancelledError', 'McpOauth', 'McpServerLog'] as $class) {
            if (!class_exists("PigMcp\\{$class}", false)) {
                require __DIR__ . "/{$class}.php";
            }
        }

        $cli = new self(
            $cwd,
            Config::home(),
            static function (string $line): void {
                echo $line, "\n";
            },
            static function (string $line): void {
                fwrite(STDERR, Style::red($line) . "\n");
            },
        );

        return $cli->run($args);
    }
}
