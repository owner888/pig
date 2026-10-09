<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mcp;

use Pig\Async\Loop;
use Pig\CodingAgent\Session\AgentSession;
use Pig\Mcp\Protocol\JsonRpc;

/**
 * pig as an MCP server over stdio: one JSON-RPC message per line in, one per line out —
 * the server side of `Pig\Mcp\Transports\StdioTransport`.
 *
 * The transport a host spawns rather than connects to: Claude Desktop and Claude Code both
 * start a stdio server as a child and own its lifetime, so there is no port, no session id,
 * and no keep-alive — a `tools/call` that takes a minute is a minute with nothing on stdout,
 * which a pipe does not mind. Standard output is the protocol, so every word for a person
 * goes to standard error, as in `RpcMode`; and the host closing stdin is the host saying it
 * is done, which stops the loop and lets `McpMode` shut the session down.
 *
 * The lines are read the way `RpcMode` reads its commands: a non-blocking watcher, a buffer,
 * a frame per newline. A line that is not JSON gets a parse error with a null id, as the
 * spec has it, and does not end the conversation.
 */
final class McpStdio
{
    private const int CHUNK = 65536;

    private readonly McpDispatcher $dispatcher;

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    private string $buffer = '';

    private ?string $watcher = null;

    /**
     * @param resource|null $in  standard input unless a test hands in a pipe
     * @param resource|null $out standard output, likewise
     */
    public function __construct(AgentSession $session, $in = null, $out = null)
    {
        $this->dispatcher = new McpDispatcher($session);
        $this->in = $in ?? STDIN;
        $this->out = $out ?? STDOUT;
    }

    public function start(): void
    {
        stream_set_blocking($this->in, false);
        $this->watcher = Loop::get()->onReadable($this->in, $this->onReadable(...));
    }

    public function stop(): void
    {
        if ($this->watcher !== null) {
            Loop::get()->cancel($this->watcher);
            $this->watcher = null;
        }
    }

    private function onReadable(): void
    {
        $chunk = fread($this->in, self::CHUNK);

        if ($chunk === false || ($chunk === '' && feof($this->in))) {
            $this->stop();
            Loop::get()->stop();

            return;
        }

        $this->buffer .= $chunk;

        while (($break = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $break);
            $this->buffer = substr($this->buffer, $break + 1);

            if (trim($line) !== '') {
                $this->line($line);
            }
        }
    }

    private function line(string $line): void
    {
        $message = json_decode($line, true);

        if (!JsonRpc::isObject($message)) {
            $this->send(McpDispatcher::error(null, JsonRpc::PARSE_ERROR, 'Expected one JSON-RPC message per line'));

            return;
        }

        $this->dispatcher->dispatch($message, $this->send(...));
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        fwrite($this->out, McpDispatcher::encode($message) . "\n");
    }
}
