<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Session\AgentSession;
use Pig\Tui\Process;
use Pig\Tui\Style;

/**
 * Web UI mode (`--mode web`), providing an interactive browser interface.
 *
 * Runs a micro non-blocking HTTP & SSE server on Loop's stream_select.
 */
final class WebMode
{
    private ?HttpServer $server = null;

    public function __construct(
        public readonly AgentSession $session,
        public readonly int $port = 8088,
        public readonly string $host = '127.0.0.1',
        public readonly ?Auth $auth = null,
    ) {
    }

    public function run(): int
    {
        $port = $this->port;
        $bound = false;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidatePort = $port + $attempt;
            try {
                $this->server = new HttpServer($this->session, $candidatePort, $this->host, $this->auth);
                $this->server->start();
                $bound = true;
                $port = $candidatePort;
                break;
            } catch (\Throwable) {
                continue;
            }
        }

        if (!$bound || $this->server === null) {
            fwrite(STDERR, Style::red("Error: Failed to bind Web UI to {$this->host}:{$port} (ports in use)\n"));

            return 1;
        }

        $url = "http://{$this->host}:{$port}";
        echo Style::green("✔ Web UI running at: ") . Style::bold($url) . "\n";
        echo Style::dim("Press Ctrl+C to stop the Web UI server.\n\n");

        $this->openBrowser($url);

        Loop::get()->run();

        return 0;
    }

    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function openBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            Process::run(['open', $url]);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            Process::run(['xdg-open', $url]);
        }
    }
}
