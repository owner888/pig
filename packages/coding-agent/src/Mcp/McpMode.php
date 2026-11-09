<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mcp;

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\Events\SessionShutdownEvent;
use Pig\CodingAgent\Extensions\Events\SessionStartEvent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Extensions\NoUi;
use Pig\CodingAgent\Session\AgentSession;
use Pig\Tui\Style;
use Throwable;

/**
 * `--mode mcp`: serve this conversation to another agent.
 *
 * The fifth way in, and the one where the person on the other end is a program: `McpServer`
 * listens (or `McpStdio` reads standard input, with `--mcp-stdio`), and every `ask` it takes
 * is a turn in the one `AgentSession` built the way the terminal builds it — same model,
 * hooks, extensions and tools, and the same conversation when `--session` or `-c` names one. What this class owns is the
 * wiring `PrintMode` and `RpcMode` each own for themselves: hooks and custom tools with
 * `NoUi`, since nobody is here to answer a `confirm()`, and the session lifecycle hooks
 * around the server's lifetime rather than around one prompt's.
 *
 * Standard output is for the one line saying where the server is — or, on stdio, for the
 * protocol and nothing else; everything for a person goes to standard error, as in every
 * non-interactive mode.
 */
final class McpMode
{
    private McpServer|McpStdio|null $server = null;

    /**
     * @param bool $stdio serve on standard input and output instead of listening — for a host
     *                    that spawns its servers, where the port and the host are not used
     */
    public function __construct(
        private readonly AgentSession $session,
        public readonly int $port = 8089,
        public readonly string $host = '127.0.0.1',
        private readonly ?ExtensionRunner $extensionRunner = null,
        private readonly ?CustomToolSet $customTools = null,
        public readonly bool $stdio = false,
    ) {
    }

    public function run(): int
    {
        $this->start();

        try {
            $this->server = $this->stdio ? new McpStdio($this->session) : new McpServer($this->session, $this->port, $this->host);
            $this->server->start();
        } catch (Throwable $e) {
            fwrite(STDERR, Style::red("Error: {$e->getMessage()}\n"));
            $this->stop();

            return 1;
        }

        if ($this->stdio) {
            // Standard output is the protocol here; the one line for a person goes to standard error.
            fwrite(STDERR, Style::dim("MCP server on stdio; closing stdin stops it.\n"));
        } else {
            echo Style::green('✔ MCP server at: ') . Style::bold("http://{$this->host}:{$this->port}" . McpServer::PATH) . "\n";
            fwrite(STDERR, Style::dim("Press Ctrl+C to stop.\n"));
        }

        pcntl_async_signals(true);
        $onSignal = function (): void {
            Async::spawn(function (): void {
                $this->stop();
                Loop::get()->stop();
            });
        };
        pcntl_signal(SIGTERM, $onSignal);
        pcntl_signal(SIGINT, $onSignal);

        Loop::get()->run();

        return 0;
    }

    private function start(): void
    {
        $this->session->setMode('mcp');

        $this->extensionRunner?->wire($this->session, new NoUi());

        $this->extensionRunner?->onError(static function (ExtensionError $error): void {
            fwrite(STDERR, "extension {$error->path} ({$error->stage}): {$error->message}\n");
        });

        $this->customTools?->withContext(
            fn () => $this->extensionRunner?->context() ?? new ExtensionContext($this->session->cwd(), $this->session->store()),
        );

        $this->extensionRunner?->emit(new SessionStartEvent());
        $this->session->discoverResources('startup');

        foreach ($this->customTools?->notify('start') ?? [] as $problem) {
            fwrite(STDERR, "tool {$problem->path}: {$problem->error}\n");
        }
    }

    /** Suspends while a turn in flight is aborted; call from inside a fiber. */
    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
        $this->extensionRunner?->emit(new SessionShutdownEvent());
        $this->customTools?->notify('shutdown');
        $this->session->dispose();
    }
}
