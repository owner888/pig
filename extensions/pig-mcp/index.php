<?php

declare(strict_types=1);

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Version;
use PigMcp\McpConfig;
use PigMcp\McpManagerView;
use PigMcp\McpOauth;
use PigMcp\McpResources;
use PigMcp\McpServerLog;
use PigMcp\McpSignInCancelledError;
use PigMcp\McpTools;
use PigMcp\ServerConnection;
use PigMcp\ServerEntry;
use Pig\Codemode\ToolSearch;

// Class files beside the entry, guarded by class and not by `require_once`: the same class can
// live at two paths (a global copy and the repository's), and `require_once` dedups by path.
foreach (['ServerEntry', 'McpConfig', 'ServerConnection', 'McpTools', 'McpResources', 'McpManagerView', 'McpSignInCancelledError', 'McpOauth', 'McpServerLog'] as $class) {
    if (!class_exists("PigMcp\\{$class}", false)) {
        require __DIR__ . "/{$class}.php";
    }
}

/**
 * MCP servers — upstream's built-in `extensions/mcp`, the part that reads `mcp.json`, connects,
 * and turns each server's tools into `mcp__<server>__<tool>` tools the model can call.
 *
 * Connects when a session starts, in the background: the first prompt waits up to ten seconds
 * for the startup connections, and the tools of a server that takes longer arrive when it does.
 * Problems — config errors, servers that failed — are reported once after startup. `/mcp` prints
 * the state of every server; `/mcp reconnect <server>` reconnects one.
 *
 * Every call runs through pig's tool pipeline, so `tool_call` and `tool_result` hooks — the
 * permission gate among them — apply to MCP tools exactly as they apply to `bash`.
 *
 * A `deferred` tool is not declared to the model until `tool_search` loads it: that tool is
 * registered as soon as a connected server has a deferred tool, searches the ones not yet loaded
 * with BM25 over their names, descriptions and schemas, and registers the matches — which reach
 * the model from the next call. `codemode` and `codemode-deferred` are read as `deferred`, since
 * pig has no sandbox; the mapping is said once.
 *
 * `/mcp` with a terminal opens the manager: a list of servers that redraws as they connect, and
 * per server the tools, reconnect, exposure and enable/disable — the last two written back to the
 * `mcp.json` the server came from. Without a terminal it prints the status.
 *
 * An HTTP server with no `Authorization` header uses OAuth: tokens in `~/.pig/agent/mcp-auth.json`,
 * refreshed by the connection, and `/mcp login [server]` (or the manager's Sign in) runs the browser
 * flow when a server answers 401 with nothing to refresh. `/mcp logout [server]` forgets them.
 *
 * A server that offers resources brings the three resource tools Codex and opencode use —
 * `list_mcp_resources`, `list_mcp_resource_templates`, `read_mcp_resource` — registered with the
 * widest exposure among the servers that have resources: declared when one of them is `direct`,
 * behind `tool_search` otherwise, gone when none has any.
 *
 * What a server says with `notifications/message` goes to `~/.pig/agent/mcp.log`, rotated at 5MB.
 */
return static function (ExtensionApi $pi): void {
    $startupWait = 10.0;

    /** @var list<ServerEntry> */
    $entries = [];
    /** @var array<string, ServerConnection> by server name */
    $connections = [];
    /** @var list<string> */
    $configErrors = [];
    /** @var \Pig\Async\Future|null the startup connections, for the first prompt to wait on */
    $pending = null;
    $waitedForStartup = false;
    $generation = 0;
    $cwd = $pi->cwd();
    $credentials = new McpOauth(McpOauth::defaultPath(Config::home()));
    $log = new McpServerLog(Config::home() . '/mcp.log');
    $openUrl = static function (string $url): void {
        if (PHP_OS_FAMILY === 'Darwin') {
            \Pig\Tui\Process::run(['open', $url]);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            \Pig\Tui\Process::run(['xdg-open', $url]);
        }
    };

    /** @var array<string, string> pig tool name => `<server>\0<tool>` it was assigned to */
    $toolOwners = [];
    /** @var array<string, list<string>> server => the pig tool names it currently offers */
    $serverTools = [];
    /** @var array<string, array{server: string, tool: array<string, mixed>, connection: ServerConnection}> deferred tools not yet loaded, by pig name */
    $deferred = [];
    /** @var array<string, array{server: string, connection: ServerConnection}> deferred tools `tool_search` has loaded, by pig name — they stay loaded across a reconnect */
    $loaded = [];
    $toolSearchRegistered = false;
    /** @var 'direct'|'deferred'|'hidden'|null the exposure the resource tools were last registered with */
    $resourceToolsExposure = null;
    /** @var array<string, array{server: string, tool: array<string, mixed>, connection: ServerConnection}> resource tools waiting for tool_search, under their own names */
    $deferredResourceTools = [];

    // Declared before it is defined: the closure below refers to itself by reference, and a
    // `use (&$x)` of a variable that does not exist yet captures null.
    $syncToolSearch = null;

    /** Register `tool_search` the first time a deferred tool exists, and keep its description current. */
    $syncToolSearch = static function () use ($pi, &$deferred, &$loaded, &$toolSearchRegistered, &$syncToolSearch): void {
        $names = array_merge(array_keys($deferred), array_keys($loaded));

        if ($names === []) {
            if ($toolSearchRegistered) {
                $pi->removeTools(static fn ($tool): bool => $tool->name === ToolSearch::TOOL_NAME);
                $toolSearchRegistered = false;
            }

            return;
        }

        // The sources are every server with a deferred tool, loaded or not — upstream lists by
        // exposure, and a server whose tools are all loaded is still where they came from.
        $sources = [];

        foreach ([...$deferred, ...$loaded] as $one) {
            $sources[$one['server']] ??= ['name' => $one['server'], 'description' => $one['connection']?->instructions];
        }

        $toolSearchRegistered = true;
        $pi->registerTool(new \Pig\CodingAgent\CustomTools\CustomTool(
            name: ToolSearch::TOOL_NAME,
            label: ToolSearch::TOOL_NAME,
            description: ToolSearch::description(array_values($sources)),
            parameters: ToolSearch::parameters(),
            execute: static function (string $id, array $params) use ($pi, &$deferred, &$loaded, &$syncToolSearch): \Pig\Agent\AgentToolResult {
                $query = trim((string) ($params['query'] ?? ''));

                if ($query === '') {
                    throw new \Pig\Agent\AgentError('query must not be empty');
                }

                $max = $params['limit'] ?? ToolSearch::DEFAULT_LIMIT;

                if (!is_numeric($max) || (float) $max !== floor((float) $max) || (int) $max <= 0) {
                    throw new \Pig\Agent\AgentError('limit must be a positive integer');
                }

                $documents = [];

                foreach ($deferred as $name => $one) {
                    $documents[] = ToolSearch::document($name, $one['tool'], $one['server'], $one['connection']?->instructions);
                }

                $matches = ToolSearch::rank($query, $documents, (int) $max);
                $lines = [];

                foreach ($matches as $match) {
                    $one = $deferred[$match['name']];
                    unset($deferred[$match['name']]);
                    $loaded[$match['name']] = ['server' => $one['server'], 'connection' => $one['connection']];
                    $defined = $one['define']();
                    $pi->registerTool($defined);
                    $lines[] = "- {$match['name']}: " . trim((string) strtok(trim($defined->description), "\r\n"));
                }

                $syncToolSearch();

                $text = $lines === []
                    ? 'No matching tools found.'
                    : sprintf("Loaded %d tool%s. They are available from your next call:\n%s", count($lines), count($lines) === 1 ? '' : 's', implode("\n", $lines));

                return new \Pig\Agent\AgentToolResult([new \Pig\Ai\TextContent($text)], ['loaded' => array_map(static fn (array $m): string => $m['name'], $matches)]);
            },
        ));
    };

    $describeState = static function (ServerEntry $entry, ?ServerConnection $connection, bool $withError = true): string {
        if (!$entry->isEnabled()) {
            return 'disabled';
        }

        if ($connection === null) {
            return 'starting';
        }

        return match ($connection->state) {
            'needs-auth' => 'needs sign-in',
            'failed' => $withError ? 'failed: ' . strtok((string) ($connection->error ?? 'unknown error'), "\n") : 'failed',
            'connected' => sprintf('connected · %d tool%s', count($connection->tools), count($connection->tools) === 1 ? '' : 's')
                . ($connection->hasResources ? ' · resources' : ''),
            'connecting' => 'connecting…',
            default => $connection->state,
        };
    };

    /** Register a server's tools, replacing what it offered before; dropped tools go away. */
    /** Enabled, connected servers with resources whose exposure is not `hidden` — what the resource tools reach. */
    $resourceServers = static function () use (&$entries, &$connections): array {
        $out = [];

        foreach ($entries as $entry) {
            $connection = $connections[$entry->name] ?? null;

            if ($connection !== null && $connection->state === 'connected' && $connection->hasResources && $entry->isEnabled() && McpConfig::here($entry->exposure()) !== 'hidden') {
                $out[] = $connection;
            }
        }

        return $out;
    };

    $syncResourceTools = null;

    /**
     * Register the resource tools with the widest exposure of the servers they reach: `direct` when
     * one of them is direct, `deferred` otherwise; gone when no server has resources.
     */
    $syncResourceTools = static function () use ($pi, &$entries, &$connections, &$resourceToolsExposure, &$deferred, &$loaded, $resourceServers, &$syncToolSearch): void {
        $exposures = [];

        foreach ($resourceServers() as $connection) {
            $exposures[McpConfig::here($connection->entry->exposure())] = true;
        }

        $next = isset($exposures['direct']) ? 'direct' : (isset($exposures['deferred']) ? 'deferred' : 'hidden');

        if ($next === $resourceToolsExposure || ($resourceToolsExposure === null && $next === 'hidden')) {
            return;
        }

        $resourceToolsExposure = $next;
        $tools = McpResources::define($resourceServers);

        // Whatever they were before comes off; what they are now goes on by the exposure's door.
        $pi->removeTools(static fn ($tool): bool => in_array($tool->name, McpResources::NAMES, true));

        foreach (McpResources::NAMES as $name) {
            unset($deferred[$name], $loaded[$name]);
        }

        foreach ($tools as $tool) {
            if ($next === 'direct') {
                $pi->registerTool($tool);
            } elseif ($next === 'deferred') {
                $deferred[$tool->name] = [
                    'server' => 'mcp resources',
                    'tool' => ['name' => $tool->name, 'description' => $tool->description, 'inputSchema' => $tool->parameters],
                    'connection' => null,
                    'define' => static fn () => $tool,
                ];
            }
        }
    };

    // Whether a resource link in a result should name `read_mcp_resource`: only while the resource tools are on the model.
    $resourcesReadable = static function () use (&$resourceToolsExposure, &$loaded): bool {
        return $resourceToolsExposure === 'direct' || isset($loaded[McpResources::READ]);
    };

    $registerTools = static function (ServerConnection $connection) use ($pi, &$toolOwners, &$serverTools, &$deferred, &$loaded, &$syncToolSearch, &$syncResourceTools, $resourcesReadable): void {
        $server = $connection->name();
        $current = [];
        /** @var array<string, true> the names that went to the codemode registry this pass */
        $codemodeTools = [];
        /** @var list<string> the names actually declared this pass — a deferred one is owned and not declared */
        $declared = [];

        // Whatever this server deferred last time is re-read from its new list below.
        foreach ($deferred as $name => $one) {
            if ($one['server'] === $server) {
                unset($deferred[$name]);
            }
        }

        foreach ($connection->tools as $tool) {
            $toolName = (string) $tool['name'];
            $owner = "{$server}\0{$toolName}";
            $exposure = McpConfig::here(McpConfig::toolExposure($connection->entry->config, $toolName));

            if ($exposure === 'hidden') {
                continue;
            }

            $name = McpTools::name($server, $toolName, static function (string $candidate) use (&$toolOwners, $owner, $current): bool {
                $existing = $toolOwners[$candidate] ?? null;

                return ($existing !== null && $existing !== $owner) || in_array($candidate, $current, true);
            });
            $toolOwners[$name] = $owner;
            $current[] = $name;

            // A deferred tool waits for `tool_search` — unless it was loaded before this server
            // reconnected, in which case the model already knows it and it stays declared.
            $define = static fn (): \Pig\CodingAgent\CustomTools\CustomTool => McpTools::define($server, $tool, $name, $connection, $resourcesReadable);

            // A codemode tool is never on the model: scripts reach it through the registry the
            // codemode extension reads, and registering it there is what activates codemode.
            if ($exposure === 'codemode' || $exposure === 'codemode-deferred') {
                $defined = $define();
                \Pig\Codemode\Registry::register(
                    $name,
                    $defined->description,
                    $defined->parameters,
                    static fn (array $args, \Pig\Async\AbortSignal $signal, HookContext $ctx): mixed => McpTools::scriptValue($connection->callTool($toolName, $args, ['signal' => $signal, 'timeout' => $connection->timeout()])),
                    ['name' => $server, 'description' => $connection->instructions],
                    $exposure,
                    McpTools::callToolResultSchema($tool),
                );
                $codemodeTools[$name] = true;
                continue;
            }

            if ($exposure === 'deferred' && !isset($loaded[$name])) {
                $deferred[$name] = ['server' => $server, 'tool' => $tool, 'connection' => $connection, 'define' => $define];
                continue;
            }

            $declared[] = $name;
            $pi->registerTool($define());
        }

        // Tools the server dropped are taken away, and so is one that was declared last time and
        // is deferred now (the exposure changed under it); when it is offered again it comes back above.
        $gone = array_diff($serverTools[$server] ?? [], $declared);

        if ($gone !== []) {
            $pi->removeTools(static fn ($tool): bool => in_array($tool->name, $gone, true));

            foreach ($gone as $name) {
                unset($loaded[$name]);
            }
        }

        // And the ones that left the codemode registry: not codemode any more, or dropped.
        \Pig\Codemode\Registry::remove(static fn (string $n): bool => in_array($n, $serverTools[$server] ?? [], true) && !isset($codemodeTools[$n]));

        $serverTools[$server] = $current;
        $syncResourceTools();
        $syncToolSearch();
    };

    $hideTools = static function (string $server) use ($pi, &$serverTools, &$deferred, &$loaded, &$syncToolSearch, &$syncResourceTools): void {
        $names = $serverTools[$server] ?? [];

        if ($names !== []) {
            $pi->removeTools(static fn ($tool): bool => in_array($tool->name, $names, true));
            \Pig\Codemode\Registry::remove(static fn (string $n): bool => in_array($n, $names, true));
        }

        foreach ($names as $name) {
            unset($deferred[$name], $loaded[$name]);
        }

        $serverTools[$server] = [];
        $syncResourceTools();
        $syncToolSearch();
    };

    /** One message for everything that needs the user after startup. */
    $reportProblems = static function (HookContext $ctx, array $only = []) use (&$entries, &$connections, &$configErrors, $describeState): void {
        $lines = $only === [] ? array_map(static fn (string $e): string => "config: {$e}", $configErrors) : [];

        foreach ($only === [] ? $entries : $only as $entry) {
            $connection = $connections[$entry->name] ?? null;

            if ($connection !== null && $connection->state === 'failed') {
                $lines[] = "{$entry->name}: " . $describeState($entry, $connection);
            } elseif ($connection !== null && $connection->state === 'needs-auth') {
                $lines[] = "{$entry->name}: needs sign-in, run /mcp login {$entry->name}";
            }
        }

        if ($lines === []) {
            return;
        }

        $ctx->ui->notify("MCP servers need attention:\n" . implode("\n", array_map(static fn (string $l): string => "  {$l}", $lines)) . "\nRun /mcp to see.", 'warning');
    };

    /** @var array<int, \Closure(): void> who to tell when a server's state changes — the manager's menus */
    $watchers = [];
    $emitChange = static function () use (&$watchers): void {
        foreach ($watchers as $watcher) {
            $watcher();
        }
    };
    /** @return \Closure(): void that stops the watching */
    $subscribe = static function (\Closure $watcher) use (&$watchers): Closure {
        static $next = 0;
        $id = $next++;
        $watchers[$id] = $watcher;

        return static function () use (&$watchers, $id): void {
            unset($watchers[$id]);
        };
    };

    $createConnection = static function (ServerEntry $entry) use (&$connections, $cwd, $registerTools, $emitChange, $credentials, $log): ServerConnection {
        $connection = new ServerConnection(
            $entry,
            $cwd,
            Version::current(),
            onTools: $registerTools,
            onChange: static fn () => $emitChange(),
            credentials: $credentials,
            log: $log,
        );
        $connections[$entry->name] = $connection;

        return $connection;
    };

    /** Write a change to the file the server came from, and to the entry in hand. */
    $saveConfig = static function (ServerEntry $entry, array $patch): ?string {
        try {
            McpConfig::update($entry->source, $entry->name, $patch);
        } catch (\Throwable $error) {
            return "Could not update {$entry->source}: {$error->getMessage()}";
        }

        foreach ($patch as $key => $value) {
            $entry->config[$key] = $value;
        }

        return null;
    };

    $setEnabled = static function (ServerEntry $entry, bool $enabled) use ($saveConfig, &$connections, $hideTools, $createConnection, $emitChange): ?string {
        $failed = $saveConfig($entry, ['enabled' => $enabled]);

        if ($failed !== null) {
            return $failed;
        }

        if (!$enabled) {
            $connection = $connections[$entry->name] ?? null;
            unset($connections[$entry->name]);
            $hideTools($entry->name);
            $emitChange();

            try {
                $connection?->close();
            } catch (\Throwable) {
                // Disabled is disabled, however the connection went.
            }

            return null;
        }

        $connection = $createConnection($entry);

        try {
            $connection->client();
        } catch (\Throwable) {
            // The failure is the connection's state.
        }

        return null;
    };

    $setExposure = static function (ServerEntry $entry, string $exposure) use ($saveConfig, &$connections, $registerTools, $emitChange): ?string {
        $failed = $saveConfig($entry, ['exposure' => $exposure]);

        if ($failed !== null) {
            return $failed;
        }

        $connection = $connections[$entry->name] ?? null;

        if ($connection?->state === 'connected') {
            $registerTools($connection);
        }

        $emitChange();

        return null;
    };

    $formatStatus = static function () use (&$entries, &$connections, &$configErrors, &$deferred, $describeState): string {
        if ($entries === [] && $configErrors === []) {
            return 'No MCP servers configured. Add them to ' . Config::home() . '/mcp.json or .pig/mcp.json.';
        }

        $lines = [];

        foreach ($entries as $entry) {
            $connection = $connections[$entry->name] ?? null;
            $exposure = McpConfig::here($entry->exposure());

            if ($connection?->state === 'needs-auth') {
                $lines[] = "{$entry->name}: needs sign-in, run /mcp login {$entry->name} ({$exposure})";
                continue;
            }

            $state = !$entry->isEnabled()
                ? 'disabled'
                : ($connection?->state === 'disconnected' ? 'disconnected, reconnects on next call' : ($connection?->state ?? 'starting'));
            $tools = $connection?->state === 'connected' ? ', ' . count($connection->tools) . ' tools' : '';

            if ($connection?->state === 'connected' && $exposure === 'deferred') {
                $hidden = count(array_filter($deferred, static fn (array $one): bool => $one['server'] === $entry->name));
                $tools .= " ({$hidden} waiting for tool_search)";
            }
            $error = $connection !== null && $connection->error !== null && $connection->state !== 'connected'
                ? "\n    " . str_replace("\n", "\n    ", $connection->error)
                : '';
            $lines[] = "{$entry->name}: {$state}{$tools} ({$exposure}){$error}";
        }

        foreach ($configErrors as $error) {
            $lines[] = "config error: {$error}";
        }

        return implode("\n", $lines);
    };

    $pi->on('session_start', static function (SessionStartEvent $event, HookContext $ctx) use (
        $pi,
        &$entries,
        &$connections,
        &$configErrors,
        &$pending,
        &$waitedForStartup,
        &$generation,
        $cwd,
        $createConnection,
        $reportProblems,
    ): void {
        $trusted = ProjectTrust::hasResources($cwd) ? (ProjectTrust::decision($cwd) ?? false) : true;
        $loaded = McpConfig::load(Config::home(), $cwd, $trusted);
        $entries = $loaded->servers;
        $configErrors = $loaded->errors;
        $waitedForStartup = false;
        $current = ++$generation;

        $enabled = array_values(array_filter($entries, static fn (ServerEntry $e): bool => $e->isEnabled()));

        if ($enabled === []) {
            $reportProblems($ctx);

            return;
        }

        // In the background, so the first frame is drawn before anything connects.
        $pending = Async::spawn(static function () use ($enabled, $createConnection, &$generation, $current, $ctx, $reportProblems): void {
            $started = array_map($createConnection, $enabled);
            $waits = [];

            foreach ($started as $connection) {
                $waits[] = Async::spawn(static function () use ($connection): void {
                    try {
                        $connection->client();
                    } catch (\Throwable) {
                        // The failure is the connection's state, reported below.
                    }
                });
            }

            foreach ($waits as $wait) {
                $wait->await();
            }

            if ($current !== $generation) {
                return;
            }

            $reportProblems($ctx);
        });
    });

    // The first prompt waits for startup connections so their tools are available to it, but
    // not indefinitely: a slow or hanging server must not hold up the prompt.
    $pi->on('before_agent_start', static function ($event, HookContext $ctx) use (&$pending, &$waitedForStartup, $startupWait): ?BeforeAgentStartEventResult {
        if ($pending === null || $waitedForStartup) {
            return null;
        }

        $waitedForStartup = true;
        $deadline = microtime(true) + $startupWait;

        while (!$pending->isComplete() && microtime(true) < $deadline) {
            Async::delay(0.05);
        }

        if (!$pending->isComplete()) {
            $ctx->ui->notify('MCP servers are still connecting; their tools become available once connected.', 'info');
        }

        return null;
    });

    $pi->on('session_shutdown', static function (SessionShutdownEvent $event, HookContext $ctx) use (&$connections, &$entries, &$generation, &$serverTools, $hideTools): void {
        $generation++;

        // Every server's tools come off the model and out of the codemode registry first.
        foreach (array_keys($serverTools) as $server) {
            $hideTools($server);
        }

        $closing = $connections;
        $connections = [];
        $entries = [];

        foreach ($closing as $connection) {
            try {
                $connection->close();
            } catch (\Throwable) {
                // Shutting down; nothing to say it to.
            }
        }
    });

    // ---- signing in -----------------------------------------------------------------------------

    /**
     * Sign in to a server and reconnect. Answers a message when it did not work (null when it did),
     * so a menu can show it beside the server.
     *
     * @param array{showAuthorizationUrl: \Closure, promptForRedirectUrl: \Closure} $prompt
     */
    $signIn = static function (ServerEntry $entry, array $prompt) use (&$connections, $credentials, $emitChange): ?string {
        $connection = $connections[$entry->name] ?? null;
        $url = $connection?->oauthUrl();

        if ($connection === null || $url === null) {
            return "MCP server \"{$entry->name}\" does not use OAuth. Only HTTP servers without an Authorization header do.";
        }

        try {
            $credentials->signIn($url, $connection->oauthSettings(), $connection->challenge, $prompt);
        } catch (McpSignInCancelledError) {
            return 'Sign-in cancelled.';
        } catch (\Throwable $error) {
            return "Sign-in to MCP server \"{$entry->name}\" failed: {$error->getMessage()}";
        }

        $connection->challenge = null;

        try {
            $connection->reconnect();
        } catch (\Throwable $error) {
            return "Signed in, but {$error->getMessage()}";
        }

        $emitChange();

        return null;
    };

    $signOut = static function (ServerEntry $entry) use (&$connections, $credentials, $hideTools, $createConnection, $emitChange): ?string {
        $connection = $connections[$entry->name] ?? null;
        $url = $connection?->oauthUrl();

        if ($connection === null || $url === null) {
            return "MCP server \"{$entry->name}\" does not use OAuth.";
        }

        $removed = $credentials->remove($url);
        // Without its tokens the server is where it was before anybody signed in: reconnecting
        // shows `needs-auth` and takes its tools off the model.
        $hideTools($entry->name);

        try {
            $connection->close();
        } catch (\Throwable) {
            // Gone is what was wanted.
        }

        $fresh = $createConnection($entry);

        try {
            $fresh->client();
        } catch (\Throwable) {
            // The state says what happened.
        }

        $emitChange();

        return $removed ? null : "No stored credentials for MCP server \"{$entry->name}\".";
    };

    /** The sign-in with the plain dialogs — `/mcp login` outside the manager. */
    $loginCommand = static function (ServerEntry $entry, HookContext $ctx) use ($signIn, $openUrl, &$connections): void {
        $name = $entry->name;

        if (!$ctx->hasUi) {
            $ctx->ui->notify("Signing in to MCP server \"{$name}\" requires interactive mode. Run: pig mcp login {$name}", 'error');

            return;
        }

        $failure = $signIn($entry, [
            'showAuthorizationUrl' => static function (string $url) use ($ctx, $name, $openUrl): void {
                $ctx->ui->notify("Sign in to MCP server \"{$name}\" in your browser:\n{$url}", 'info');
                $openUrl($url);
            },
            'promptForRedirectUrl' => static fn (\Pig\Async\AbortSignal $signal): ?string => $ctx->ui->input(
                "Waiting for sign-in to \"{$name}\". If the browser cannot reach this machine, paste the URL it was redirected to.",
                'http://127.0.0.1:.../callback?code=...',
                $signal,
            ),
        ]);

        if ($failure !== null) {
            $ctx->ui->notify($failure, $failure === 'Sign-in cancelled.' ? 'info' : 'error');

            return;
        }

        $ctx->ui->notify("Signed in to MCP server \"{$name}\" (" . count($connections[$name]?->tools ?? []) . ' tools).', 'info');
    };

    // ---- the manager (`/mcp` in the terminal) ------------------------------------------------

    $exposureDescriptions = [
        'codemode' => 'called from codemode scripts, listed in the codemode description',
        'codemode-deferred' => 'called from codemode scripts, not listed; scripts find them with search_tools()',
        'deferred' => 'not declared until tool_search loads them, then called directly; no codemode needed',
        'direct' => 'declared to the model like built-in tools',
    ];

    $findEntry = static function (string $name) use (&$entries): ?ServerEntry {
        foreach ($entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    };

    /** Servers that need the user first. */
    $attentionRank = static function (ServerEntry $entry, ?ServerConnection $connection): int {
        if (!$entry->isEnabled()) {
            return 5;
        }

        return match ($connection?->state) {
            'needs-auth' => 0,
            'failed' => 1,
            'disconnected' => 2,
            'connected' => 4,
            default => 3,
        };
    };

    /** @var array<string, string> a message about the last action on a server, shown in its menu */
    $messages = [];

    $serversMenu = static function () use (&$entries, &$connections, &$configErrors, $describeState, $attentionRank): array {
        $sorted = $entries;
        usort($sorted, static fn (ServerEntry $a, ServerEntry $b): int =>
            $attentionRank($a, $connections[$a->name] ?? null) <=> $attentionRank($b, $connections[$b->name] ?? null)
            ?: strcmp($a->name, $b->name));

        $items = [];

        foreach ($sorted as $entry) {
            $items[] = new \Pig\Tui\Components\SelectItem(
                $entry->name,
                $entry->name,
                $describeState($entry, $connections[$entry->name] ?? null) . ' · ' . McpConfig::here($entry->exposure()) . ' · ' . $entry->scope,
            );
        }

        $notices = array_map(static fn (string $e): string => "config: {$e}", $configErrors);

        return [
            'title' => 'MCP servers',
            'error' => $notices === [] ? null : implode("\n", $notices),
            'items' => $items,
            'empty' => 'No MCP servers configured. Add them to ' . Config::home() . '/mcp.json or .pig/mcp.json.',
            'confirmLabel' => 'manage',
            'cancelLabel' => 'close',
        ];
    };

    $serverMenu = static function (string $name) use ($findEntry, &$connections, &$messages, $describeState): array {
        $entry = $findEntry($name);

        if ($entry === null) {
            return ['title' => $name, 'items' => [], 'empty' => 'This server is no longer configured.', 'confirmLabel' => '', 'cancelLabel' => 'back'];
        }

        $connection = $connections[$name] ?? null;
        $saved = "saved to the {$entry->scope} mcp.json";
        $items = [];
        $item = static fn (string $value, string $label, ?string $description = null) => new \Pig\Tui\Components\SelectItem($value, $label, $description);

        if (!$entry->isEnabled()) {
            $items[] = $item('enable', 'Enable', $saved);
        } else {
            $state = $connection?->state;

            if ($state === 'needs-auth') {
                $items[] = $item('signin', 'Sign in', 'opens the browser');
            }

            if ($state === 'connected' && $connection !== null) {
                $items[] = $item('tools', 'Tools', count($connection->tools) . ' offered');
            }

            if (in_array($state, ['failed', 'disconnected', 'connected', 'needs-auth'], true)) {
                $items[] = $item('reconnect', 'Reconnect');
            }

            if ($state === 'connected' && $connection?->oauthUrl() !== null) {
                $items[] = $item('signout', 'Sign out', 'deletes the stored credentials');
            }

            $items[] = $item('exposure', 'Exposure', McpConfig::here($entry->exposure()));
            $items[] = $item('disable', 'Disable', $saved);
        }

        $details = [$entry->describeTransport(), "{$entry->scope}: {$entry->source}", 'State: ' . $describeState($entry, $connection, false)];
        $error = array_filter([$messages[$name] ?? null, $connection?->state === 'connected' ? null : $connection?->error], static fn (?string $l): bool => $l !== null);

        return [
            'title' => "MCP server {$name}",
            'details' => implode("\n", $details),
            'error' => $error === [] ? null : implode("\n", $error),
            'items' => $items,
            'selected' => $items[0]?->value,
            'confirmLabel' => 'select',
            'cancelLabel' => 'back',
        ];
    };

    $showTools = static function (McpManagerView $ui, ServerEntry $entry) use (&$connections, $exposureDescriptions): void {
        $exposure = McpConfig::here($entry->exposure());
        $overridden = ($entry->config['toolExposure'] ?? []) !== [];
        $ui->menu(static function () use ($entry, &$connections, $exposure, $overridden, $exposureDescriptions): array {
            $items = [];

            foreach ($connections[$entry->name]?->tools ?? [] as $tool) {
                $toolExposure = McpConfig::here(McpConfig::toolExposure($entry->config, (string) $tool['name']));
                $description = trim((string) strtok((string) ($tool['description'] ?? ''), "\n"));
                $items[] = new \Pig\Tui\Components\SelectItem((string) $tool['name'], (string) $tool['name'], $toolExposure === $exposure ? ($description === '' ? null : $description) : "[{$toolExposure}] {$description}");
            }

            return [
                'title' => "Tools of {$entry->name}",
                'details' => "Exposure {$exposure}: " . ($exposure === 'hidden' ? 'unreachable' : $exposureDescriptions[$exposure]) . ($overridden ? "\nSome tools override it with toolExposure." : ''),
                'items' => $items,
                'empty' => 'The server offers no tools.',
                'confirmLabel' => 'back',
                'cancelLabel' => 'back',
            ];
        });
    };

    $chooseExposure = static function (McpManagerView $ui, ServerEntry $entry) use ($exposureDescriptions, $setExposure): ?string {
        $current = $entry->exposure();
        $choice = $ui->menu(static function () use ($entry, $current, $exposureDescriptions): array {
            $items = [];

            foreach ($exposureDescriptions as $exposure => $description) {
                $items[] = new \Pig\Tui\Components\SelectItem($exposure, ($exposure === $current ? '✓ ' : '  ') . $exposure, $description);
            }

            return [
                'title' => "Exposure of {$entry->name}",
                'details' => "Saved to {$entry->source}.",
                'items' => $items,
                'selected' => $current,
                'confirmLabel' => 'save',
                'cancelLabel' => 'back',
            ];
        });

        if ($choice === null || $choice === $current) {
            return null;
        }

        return $setExposure($entry, $choice);
    };

    $runAction = static function (McpManagerView $ui, ServerEntry $entry, string $action) use (&$connections, &$messages, $showTools, $chooseExposure, $setEnabled, $emitChange, $signIn, $signOut, $openUrl): void {
        $name = $entry->name;
        $message = null;

        switch ($action) {
            case 'signin':
                $title = "Sign in to {$name}";
                $authorizationUrl = '';
                $ui->status($title, 'Contacting the authorization server…');
                $message = $signIn($entry, [
                    'showAuthorizationUrl' => static function (string $url) use (&$authorizationUrl, $openUrl): void {
                        $authorizationUrl = $url;
                        $openUrl($url);
                    },
                    'promptForRedirectUrl' => static function (\Pig\Async\AbortSignal $signal) use ($ui, $title, &$authorizationUrl): ?string {
                        $value = $ui->redirectUrl($title, $authorizationUrl, $signal);
                        $ui->status($title, 'Connecting…');

                        return $value;
                    },
                ]);
                break;
            case 'signout':
                $message = $signOut($entry);
                break;
            case 'reconnect':
                $ui->status("MCP server {$name}", 'Reconnecting…');

                try {
                    $connections[$name]?->reconnect();
                } catch (\Throwable) {
                    // A failure shows as the connection's state and error.
                }

                break;
            case 'tools':
                $showTools($ui, $entry);
                break;
            case 'exposure':
                $message = $chooseExposure($ui, $entry);
                break;
            case 'enable':
            case 'disable':
                $ui->status("MCP server {$name}", $action === 'enable' ? 'Connecting…' : 'Disconnecting…');
                $message = $setEnabled($entry, $action === 'enable');
                break;
        }

        if ($message === null) {
            unset($messages[$name]);
        } else {
            $messages[$name] = $message;
        }

        $emitChange();
    };

    $manage = static function (McpManagerView $ui) use ($serversMenu, $serverMenu, $findEntry, $runAction, $subscribe): void {
        while (true) {
            $name = $ui->menu($serversMenu, $subscribe);

            if ($name === null) {
                return;
            }

            while (true) {
                $action = $ui->menu(static fn (): array => $serverMenu($name), $subscribe);
                $entry = $findEntry($name);

                if ($action === null || $entry === null) {
                    break;
                }

                $runAction($ui, $entry, $action);
            }
        }
    };

    $pi->registerCommand(
        'mcp',
        static function (string $args, HookContext $ctx) use (&$entries, &$connections, $formatStatus, $describeState, $manage, $loginCommand, $signOut): void {
            $parts = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $action = $parts[0] ?? null;
            $name = $parts[1] ?? null;

            if ($action === null) {
                if (!$ctx->hasUi) {
                    $ctx->ui->notify($formatStatus(), 'info');

                    return;
                }

                $ctx->ui->custom(static function (\Pig\Tui\Tui $tui, \Pig\CodingAgent\Theme\Palette $palette, \Closure $done) use ($manage, $ctx): McpManagerView {
                    $view = new McpManagerView($tui, $palette);

                    Async::spawn(static function () use ($manage, $view, $done, $ctx): void {
                        try {
                            $manage($view);
                        } catch (\Throwable $error) {
                            $ctx->ui->notify($error->getMessage(), 'error');
                        }

                        $done();
                    });

                    return $view;
                });

                return;
            }

            if (!in_array($action, ['reconnect', 'login', 'logout'], true) || count($parts) > 2) {
                $ctx->ui->notify('Usage: /mcp, /mcp login [server], /mcp logout [server], /mcp reconnect [server]', 'warning');

                return;
            }

            $usesOauth = static fn (ServerEntry $e): bool => ($connections[$e->name] ?? null)?->oauthUrl() !== null;
            $eligible = $action === 'reconnect'
                ? static fn (ServerEntry $e): bool => isset($connections[$e->name])
                : $usesOauth;

            if ($action !== 'reconnect') {
                $candidates = array_values(array_filter($entries, static fn (ServerEntry $e): bool => $eligible($e) && ($name === null || $e->name === $name)));

                if ($name !== null && $candidates === []) {
                    $known = in_array($name, array_map(static fn (ServerEntry $e): string => $e->name, $entries), true);
                    $ctx->ui->notify($known
                        ? 'No enabled MCP server uses OAuth. Only HTTP servers without an Authorization header do.'
                        : "No MCP server named \"{$name}\".", 'error');

                    return;
                }

                if ($candidates === []) {
                    $ctx->ui->notify('No enabled MCP server uses OAuth. Only HTTP servers without an Authorization header do.', 'info');

                    return;
                }

                if ($name === null && count($candidates) > 1) {
                    // The one waiting for a sign-in is the one meant, when there is exactly one.
                    $waiting = array_values(array_filter($candidates, static fn (ServerEntry $e): bool => ($connections[$e->name] ?? null)?->state === 'needs-auth'));

                    if (count($waiting) === 1) {
                        $candidates = $waiting;
                    } else {
                        $picked = $ctx->ui->select('MCP server', array_map(static fn (ServerEntry $e): string => $e->name, $candidates));
                        $candidates = array_values(array_filter($candidates, static fn (ServerEntry $e): bool => $e->name === $picked));
                    }
                }

                if ($candidates === []) {
                    return;
                }

                if ($action === 'login') {
                    $loginCommand($candidates[0], $ctx);
                } else {
                    $failure = $signOut($candidates[0]);
                    $ctx->ui->notify($failure ?? "Signed out of MCP server \"{$candidates[0]->name}\".", $failure === null ? 'info' : 'warning');
                }

                return;
            }

            $candidates = array_values(array_filter($entries, static fn (ServerEntry $e): bool => isset($connections[$e->name]) && ($name === null || $e->name === $name)));

            if ($name !== null && $candidates === []) {
                $ctx->ui->notify(isset($connections[$name]) || in_array($name, array_map(static fn (ServerEntry $e): string => $e->name, $entries), true)
                    ? "MCP server \"{$name}\" is disabled."
                    : "No MCP server named \"{$name}\".", 'error');

                return;
            }

            if ($name === null) {
                if (count($candidates) !== 1) {
                    $picked = $ctx->ui->select('MCP server', array_map(static fn (ServerEntry $e): string => $e->name, $candidates));
                    $candidates = array_values(array_filter($candidates, static fn (ServerEntry $e): bool => $e->name === $picked));
                }

                if ($candidates === []) {
                    return;
                }
            }

            $entry = $candidates[0];
            $connection = $connections[$entry->name];

            try {
                $connection->reconnect();
                $ctx->ui->notify("Reconnected to MCP server \"{$entry->name}\" (" . $describeState($entry, $connection) . ').', 'info');
            } catch (\Throwable $error) {
                $ctx->ui->notify($error->getMessage(), 'error');
            }
        },
        'Manage MCP servers: sign in, reconnect, enable or disable, and change exposure — /mcp, /mcp login [server], /mcp logout [server], /mcp reconnect [server]',
    );
};
