<?php

declare(strict_types=1);

namespace PigMcp;

use Closure;
use Pig\Async\Async;
use Pig\CodingAgent\ConfigValue;
use Pig\CodingAgent\Tools\Paths;
use Pig\Mcp\McpClient;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\McpError;
use Pig\Mcp\Transports\McpAuthRequiredError;
use Pig\Mcp\Transports\McpHttpError;
use Pig\Mcp\Transports\McpSessionExpiredError;
use Pig\Mcp\Transports\StdioTransport;
use Pig\Mcp\Transports\StreamableHttpTransport;
use Pig\Mcp\Transports\Transport;
use RuntimeException;
use Throwable;

/**
 * One configured server and its connection — upstream's `McpServerConnection` in `runtime.ts`.
 *
 * Connects on first use and **reconnects lazily** when a call finds the connection gone: a server
 * that dropped shows as `disconnected` with its last words, and the next tool call opens it again.
 * An HTTP server that failed with a transient status (408, 429, 5xx) is retried twice on connect;
 * a stdio server is not, because a child that exited will exit again. A read-only request is
 * retried once after a transient error; a tool call is not, since the server may have run it.
 *
 * What a stdio server wrote to stderr is kept and shown with a failure, because it is the only
 * thing a server that would not start has to say.
 *
 * OAuth (`needs-auth`, the auth provider, sign-in) is the next step; a 401 here is a failure
 * that says to add a header.
 */
final class ServerConnection
{
    private const int STDERR_TAIL_CHARS = 2000;

    /** Delays between attempts to connect to an HTTP server that failed with a transient error. */
    private const array CONNECT_RETRY_DELAYS = [0.25, 1.0];

    /** @var 'connecting'|'connected'|'disconnected'|'failed'|'closed' */
    public string $state = 'connecting';

    public ?string $error = null;

    /** @var list<array<string, mixed>> the server's tools as it listed them */
    public array $tools = [];

    public bool $hasResources = false;

    /** Server instructions from `initialize`, describing its tools as a group. */
    public ?string $instructions = null;

    private ?McpClient $client = null;

    /** The connect in progress, so two callers share one attempt. */
    private ?\Pig\Async\Future $opening = null;

    private bool $closed = false;

    private ?string $stderrTail = null;

    /**
     * @param Closure(ServerEntry, string): Transport|null $createTransport for tests
     * @param Closure(self): void|null                    $onTools    the tool list changed
     * @param Closure(self): void|null                    $onChange   the state changed
     */
    public function __construct(
        public ServerEntry $entry,
        private readonly string $cwd,
        private readonly string $pigVersion,
        private readonly ?Closure $createTransport = null,
        private readonly ?Closure $onTools = null,
        private readonly ?Closure $onChange = null,
    ) {
    }

    public function name(): string
    {
        return $this->entry->name;
    }

    public function timeout(): float
    {
        return $this->entry->timeout();
    }

    /** The connected client, connecting first if need be. */
    public function client(): McpClient
    {
        if ($this->closed) {
            throw new RuntimeException("MCP server \"{$this->entry->name}\" is shut down");
        }

        if ($this->client?->connectionState() === 'connected') {
            return $this->client;
        }

        if ($this->opening === null) {
            $this->opening = Async::spawn(function (): McpClient {
                try {
                    return $this->open();
                } finally {
                    $this->opening = null;
                }
            });
        }

        return $this->opening->await();
    }

    /**
     * @param array<string, mixed>|null $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $name, ?array $arguments, array $options = []): array
    {
        return $this->withClient(fn (McpClient $client): array => $client->callTool($name, $arguments, $options));
    }

    /** @return array{contents: list<array<string, mixed>>} */
    public function readResource(string $uri, array $options = []): array
    {
        return $this->withClient(fn (McpClient $client): array => $client->readResource($uri, $options), readOnly: true);
    }

    /**
     * Run a request, reconnecting when needed. `$readOnly` requests are retried once after a
     * transient HTTP error; tool calls are not, since they may have run.
     *
     * @template T
     * @param Closure(McpClient): T $run
     * @return T
     */
    private function withClient(Closure $run, bool $readOnly = false): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $client = $this->client();

            try {
                return $run($client);
            } catch (McpHttpError $error) {
                if ($readOnly && $attempt === 1 && self::isTransient($error)) {
                    Async::delay(self::CONNECT_RETRY_DELAYS[0]);
                    continue;
                }

                if ($error instanceof McpSessionExpiredError && $attempt === 1) {
                    // The server no longer knows the session (restart, deploy), so it did not run
                    // the request. Retry once on a new session. The old client is detached but not
                    // closed: closing would fail its other in-flight calls, which instead get the
                    // same 404 and retry the same way.
                    if ($this->client === $client) {
                        $this->client = null;
                    }

                    continue;
                }

                throw $error;
            }
        }
    }

    /** Connect again, for example after the config changed. */
    public function reconnect(): void
    {
        if ($this->client !== null) {
            $this->dropClient($this->client);
        }

        $this->client();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->state = 'closed';
        $this->changed();

        $client = $this->client;
        $this->client = null;

        if ($client !== null) {
            try {
                $client->close();
            } catch (Throwable) {
                // Already gone is the state wanted.
            }
        }
    }

    // ---- connecting -----------------------------------------------------------------------------

    private function open(): McpClient
    {
        $this->state = 'connecting';
        $this->changed();

        $retries = $this->entry->isHttp() ? self::CONNECT_RETRY_DELAYS : [];

        for ($attempt = 0; ; $attempt++) {
            $this->stderrTail = null;

            try {
                return $this->connectOnce();
            } catch (Throwable $error) {
                $delay = $retries[$attempt] ?? null;

                if ($this->closed || $delay === null || !self::isTransient($error)) {
                    throw $this->connectFailed($error);
                }

                Async::delay($delay);

                if ($this->closed) {
                    throw $this->connectFailed($error);
                }
            }
        }
    }

    private function connectOnce(): McpClient
    {
        $client = new McpClient(
            'pig',
            $this->pigVersion,
            requestTimeout: $this->timeout(),
            roots: [['uri' => 'file://' . $this->cwd, 'name' => basename($this->cwd)]],
        );
        $transport = null;

        try {
            $transport = $this->createTransport !== null
                ? ($this->createTransport)($this->entry, $this->cwd)
                : self::defaultTransport($this->entry, $this->cwd);
            $client->connect($transport);

            $client->onNotification('notifications/tools/list_changed', function () use ($client): void {
                Async::spawn(fn () => $this->refreshTools($client));
            });
            $stdio = $transport instanceof StdioTransport ? $transport : null;
            $client->onClose(fn () => $this->handleClientClose($client, $stdio));

            // Servers without the tools capability (prompts or resources only) do not answer tools/list.
            $capabilities = $client->serverCapabilities() ?? [];
            $tools = isset($capabilities['tools']) ? $client->listTools() : [];

            if ($this->closed) {
                throw new RuntimeException('shut down while connecting');
            }

            if ($client->connectionState() !== 'connected') {
                throw new RuntimeException('connection closed during setup');
            }

            $this->client = $client;
            $this->tools = $tools;
            $this->hasResources = isset($capabilities['resources']);
            $instructions = trim((string) ($client->instructions() ?? ''));
            $this->instructions = $instructions !== '' ? $instructions : null;
            $this->state = 'connected';
            $this->error = null;

            if ($this->onTools !== null) {
                ($this->onTools)($this);
            }

            $this->changed();

            return $client;
        } catch (Throwable $error) {
            try {
                $client->close();
            } catch (Throwable) {
                // The error to report is the one that got us here.
            }

            if ($transport instanceof StdioTransport) {
                $tail = trim($transport->stderr());
                $this->stderrTail = $tail === '' ? null : substr($tail, -self::STDERR_TAIL_CHARS);
            }

            throw $error;
        }
    }

    private function connectFailed(Throwable $error): RuntimeException
    {
        $this->state = $this->closed ? 'closed' : 'failed';
        $message = $error->getMessage();

        if ($error instanceof McpAuthRequiredError) {
            $message .= ' — add an Authorization header to its mcp.json entry (OAuth sign-in is not ported yet)';
        }

        $this->error = $this->stderrTail !== null ? "{$message}\n{$this->stderrTail}" : $message;
        $this->changed();

        return new RuntimeException("MCP server \"{$this->entry->name}\" failed to connect: {$this->error}");
    }

    /** The transport dropped. The next call reconnects; until then the status shows why. */
    private function handleClientClose(McpClient $client, ?StdioTransport $stdio): void
    {
        if ($this->client !== $client || $this->closed) {
            return;
        }

        $this->client = null;
        $this->state = 'disconnected';
        $stderr = $stdio === null ? '' : trim($stdio->stderr());
        $this->error = $stderr !== '' ? "Connection closed\n" . substr($stderr, -self::STDERR_TAIL_CHARS) : 'Connection closed';
        $this->changed();
    }

    private function refreshTools(McpClient $client): void
    {
        try {
            $tools = $client->listTools();

            if ($this->client !== $client || $this->closed) {
                return;
            }

            $this->tools = $tools;

            if ($this->onTools !== null) {
                ($this->onTools)($this);
            }
        } catch (Throwable $error) {
            $this->error = 'Failed to refresh tools: ' . $error->getMessage();
        }

        $this->changed();
    }

    private function dropClient(McpClient $client): void
    {
        if ($this->client === $client) {
            $this->client = null;
        }

        try {
            $client->close();
        } catch (Throwable) {
            // Gone either way.
        }
    }

    private function changed(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this);
        }
    }

    /** Network failures and overloaded or restarting servers, which are worth another attempt. */
    private static function isTransient(Throwable $error): bool
    {
        if ($error instanceof McpHttpError) {
            return $error->status === 408 || $error->status === 429 || ($error->status >= 500 && $error->status !== 501);
        }

        return $error instanceof \Pig\Ai\Http\HttpError || $error instanceof \Pig\Async\SocketError;
    }

    /** The transport an entry describes, with `${VAR}` and `!command` resolved and `~` expanded. */
    public static function defaultTransport(ServerEntry $entry, string $cwd): Transport
    {
        $config = $entry->config;
        $name = $entry->name;

        if ($entry->isHttp()) {
            return new StreamableHttpTransport(
                (string) $config['url'],
                ConfigValue::resolveAll($config['headers'] ?? [], "MCP server \"{$name}\" header"),
                timeout: $entry->timeout(),
            );
        }

        $env = [];

        foreach ($config['env'] ?? [] as $key => $value) {
            $env[(string) $key] = ConfigValue::resolveOrThrow((string) $value, "MCP server \"{$name}\" env \"{$key}\"");
        }

        return new StdioTransport(
            Paths::expand((string) $config['command']),
            array_map(static fn (string $arg): string => Paths::expand($arg), $config['args'] ?? []),
            $env,
            Paths::resolve(Paths::expand((string) ($config['cwd'] ?? '.')), $cwd),
        );
    }
}
