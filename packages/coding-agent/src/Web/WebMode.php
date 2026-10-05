<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\Tui\Process;
use Pig\Tui\Style;

/**
 * Web UI mode (`--mode web`): the shell process that serves the page and runs one
 * `pig --mode rpc` child per conversation. See `HttpServer` for the shape and CLAUDE.md
 * ("Three ways in") for why it is this shape.
 *
 * This mode holds **no** `AgentSession` of its own any more — it used to, and every tab in the
 * page was a `switchTo()` on it. The conversations live in the children; what this process
 * owns is the listening socket, the pool, and the signal handler that shuts the pool down
 * cleanly when `pig web stop` sends SIGTERM.
 */
final class WebMode
{
    private ?HttpServer $server = null;

    public function __construct(
        public readonly string $cwd,
        public readonly int $port = 8088,
        public readonly string $host = '127.0.0.1',
        public readonly ?Auth $auth = null,
        public readonly bool $openBrowser = false,
    ) {
    }

    public function run(): int
    {
        $port = $this->port;

        try {
            $this->server = new HttpServer($this->cwd, $port, $this->host, $this->auth);
            $this->server->start();
        } catch (\Throwable $e) {
            fwrite(STDERR, Style::red("Error: Failed to bind Web UI to {$this->host}:{$port} ({$e->getMessage()})\n"));

            return 1;
        }

        $url = "http://{$this->host}:{$port}";
        echo Style::green("✔ Web UI running at: ") . Style::bold($url) . "\n";
        echo Style::dim("Press Ctrl+C to stop the Web UI server.\n\n");

        // SIGTERM is what `pig web stop` sends, SIGINT what Ctrl+C does; both mean "stop, and
        // stop the children properly" — each child's `RpcMode` gets its stdin closed and runs
        // its own shutdown, so a `session_shutdown` hook fires in every conversation. Without
        // this the default disposition kills this process at once and orphans N children, each
        // holding a session file open.
        pcntl_async_signals(true);
        $onSignal = function (): void {
            Async::spawn(function (): void {
                $this->stop();
                Loop::get()->stop();
            });
        };
        pcntl_signal(SIGTERM, $onSignal);
        pcntl_signal(SIGINT, $onSignal);

        if ($this->openBrowser) {
            $this->openBrowserAt($url);
        }

        Loop::get()->run();

        return 0;
    }

    /** Suspends while the children wind down; call from inside a fiber. */
    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function openBrowserAt(string $url): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            Process::run(['open', $url]);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            Process::run(['xdg-open', $url]);
        }
    }
}
