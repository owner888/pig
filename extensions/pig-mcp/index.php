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
use PigMcp\McpTools;
use PigMcp\ServerConnection;
use PigMcp\ServerEntry;

// Class files beside the entry, guarded by class and not by `require_once`: the same class can
// live at two paths (a global copy and the repository's), and `require_once` dedups by path.
foreach (['ServerEntry', 'McpConfig', 'ServerConnection', 'McpTools'] as $class) {
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
 * Not here yet, each its own step: OAuth sign-in (`/mcp login`), `tool_search` and the
 * `deferred` exposure (every tool is `direct` for now, and the mapping says so once), resources,
 * the `/mcp` manager screen, `pig mcp add/remove/list`, the server log.
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
    $saidAboutExposure = false;

    /** @var array<string, string> pig tool name => `<server>\0<tool>` it was assigned to */
    $toolOwners = [];
    /** @var array<string, list<string>> server => the pig tool names it currently offers */
    $serverTools = [];

    $describeState = static function (ServerEntry $entry, ?ServerConnection $connection, bool $withError = true): string {
        if (!$entry->isEnabled()) {
            return 'disabled';
        }

        if ($connection === null) {
            return 'starting';
        }

        return match ($connection->state) {
            'failed' => $withError ? 'failed: ' . strtok((string) ($connection->error ?? 'unknown error'), "\n") : 'failed',
            'connected' => sprintf('connected · %d tool%s', count($connection->tools), count($connection->tools) === 1 ? '' : 's'),
            'connecting' => 'connecting…',
            default => $connection->state,
        };
    };

    /** Register a server's tools, replacing what it offered before; dropped tools go away. */
    $registerTools = static function (ServerConnection $connection) use ($pi, &$toolOwners, &$serverTools, &$saidAboutExposure): void {
        $server = $connection->name();
        $current = [];

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
            $pi->registerTool(McpTools::define($server, $tool, $name, $connection));
        }

        // Tools the server dropped are taken away; when it offers them again they come back above.
        $gone = array_diff($serverTools[$server] ?? [], $current);

        if ($gone !== []) {
            $pi->removeTools(static fn ($tool): bool => in_array($tool->name, $gone, true));
        }

        $serverTools[$server] = $current;
    };

    $hideTools = static function (string $server) use ($pi, &$serverTools): void {
        $names = $serverTools[$server] ?? [];

        if ($names !== []) {
            $pi->removeTools(static fn ($tool): bool => in_array($tool->name, $names, true));
        }

        $serverTools[$server] = [];
    };

    /** One message for everything that needs the user after startup. */
    $reportProblems = static function (HookContext $ctx, array $only = []) use (&$entries, &$connections, &$configErrors, $describeState): void {
        $lines = $only === [] ? array_map(static fn (string $e): string => "config: {$e}", $configErrors) : [];

        foreach ($only === [] ? $entries : $only as $entry) {
            $connection = $connections[$entry->name] ?? null;

            if ($connection !== null && $connection->state === 'failed') {
                $lines[] = "{$entry->name}: " . $describeState($entry, $connection);
            }
        }

        if ($lines === []) {
            return;
        }

        $ctx->ui->notify("MCP servers need attention:\n" . implode("\n", array_map(static fn (string $l): string => "  {$l}", $lines)) . "\nRun /mcp to see.", 'warning');
    };

    $createConnection = static function (ServerEntry $entry) use (&$connections, $cwd, $registerTools): ServerConnection {
        $connection = new ServerConnection(
            $entry,
            $cwd,
            Version::current(),
            onTools: $registerTools,
        );
        $connections[$entry->name] = $connection;

        return $connection;
    };

    $formatStatus = static function () use (&$entries, &$connections, &$configErrors, $describeState): string {
        if ($entries === [] && $configErrors === []) {
            return 'No MCP servers configured. Add them to ' . Config::home() . '/mcp.json or .pig/mcp.json.';
        }

        $lines = [];

        foreach ($entries as $entry) {
            $connection = $connections[$entry->name] ?? null;
            $exposure = McpConfig::here($entry->exposure());
            $state = !$entry->isEnabled()
                ? 'disabled'
                : ($connection?->state === 'disconnected' ? 'disconnected, reconnects on next call' : ($connection?->state ?? 'starting'));
            $tools = $connection?->state === 'connected' ? ', ' . count($connection->tools) . ' tools' : '';
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
        &$saidAboutExposure,
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

        if (!$saidAboutExposure) {
            foreach ($enabled as $entry) {
                if (str_starts_with($entry->exposure(), 'codemode')) {
                    $saidAboutExposure = true;
                    $ctx->ui->notify(
                        "MCP: \"{$entry->name}\" asks for codemode exposure, which pig does not have (it is a JavaScript sandbox); "
                        . 'its tools are declared to the model directly.',
                        'info',
                    );
                    break;
                }
            }
        }

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

    $pi->on('session_shutdown', static function (SessionShutdownEvent $event, HookContext $ctx) use (&$connections, &$entries, &$generation): void {
        $generation++;
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

    $pi->registerCommand(
        'mcp',
        static function (string $args, HookContext $ctx) use (&$entries, &$connections, $formatStatus, $describeState, $hideTools): void {
            $parts = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $action = $parts[0] ?? null;
            $name = $parts[1] ?? null;

            if ($action === null) {
                $ctx->ui->notify($formatStatus(), 'info');

                return;
            }

            if ($action !== 'reconnect' || count($parts) > 2) {
                $ctx->ui->notify('Usage: /mcp, /mcp reconnect <server>', 'warning');

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
        'Show MCP servers, or reconnect one: /mcp, /mcp reconnect <server>',
    );
};
