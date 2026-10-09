<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mcp;

use Pig\Ai\Model;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\NoUi;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\HookMessage;
use Pig\Tui\Style;
use Throwable;

/**
 * `--mode mcp`: serve this conversation to another agent.
 *
 * The fifth way in, and the one where the person on the other end is a program: `McpServer`
 * listens, and every `ask` it takes is a turn in the one `AgentSession` built the way the
 * terminal builds it — same model, hooks, extensions and tools. What this class owns is the
 * wiring `PrintMode` and `RpcMode` each own for themselves: hooks and custom tools with
 * `NoUi`, since nobody is here to answer a `confirm()`, and the session lifecycle hooks
 * around the server's lifetime rather than around one prompt's.
 *
 * Standard output is for the one line saying where the server is; everything else goes to
 * standard error, as in every non-interactive mode.
 */
final class McpMode
{
    private ?McpServer $server = null;

    public function __construct(
        private readonly AgentSession $session,
        public readonly int $port = 8089,
        public readonly string $host = '127.0.0.1',
        private readonly ?HookRunner $hooks = null,
        private readonly ?CustomToolSet $customTools = null,
    ) {
    }

    public function run(): int
    {
        $this->start();

        try {
            $this->server = new McpServer($this->session, $this->port, $this->host);
            $this->server->start();
        } catch (Throwable $e) {
            fwrite(STDERR, Style::red("Error: {$e->getMessage()}\n"));
            $this->stop();

            return 1;
        }

        echo Style::green('✔ MCP server at: ') . Style::bold("http://{$this->host}:{$this->port}" . McpServer::PATH) . "\n";
        fwrite(STDERR, Style::dim("Press Ctrl+C to stop.\n"));

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
        $this->session->setMode('rpc');

        $this->hooks?->initialize(
            getModel: fn () => $this->session->model(),
            isIdle: fn (): bool => !$this->session->isStreaming(),
            abort: function (): void {
                $this->session->abort();
            },
            hasPendingMessages: fn (): bool => $this->session->queued() !== [],
            signal: fn () => $this->session->signal(),
            ui: new NoUi(),
            send: function (HookMessage $message, bool $triggerTurn): void {
                $this->session->sendHookMessage($message, $triggerTurn);
            },
            note: function (string $customType, mixed $data): void {
                $this->session->appendHookEntry($customType, $data);
            },
            getApiKey: fn (Model $m) => $this->session->keyFor($m),
            setSessionName: fn (string $name) => $this->session->setSessionName($name),
            getSessionName: fn () => $this->session->getSessionName(),
        );

        $this->hooks?->onError(static function (HookError $error): void {
            fwrite(STDERR, "hook {$error->hookPath} ({$error->event}): {$error->error}\n");
        });

        $this->customTools?->withUi(new NoUi());
        $this->customTools?->withContext(
            fn () => $this->hooks?->context() ?? new HookContext($this->session->cwd(), $this->session->store()),
        );

        $this->hooks?->emit(new SessionStartEvent());
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
        $this->hooks?->emit(new SessionShutdownEvent());
        $this->customTools?->notify('shutdown');
        $this->session->dispose();
    }
}
