<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use Closure;
use Pig\Async\Loop;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\Protocol;
use Pig\Mcp\Transports\InMemoryTransport;

/**
 * The other end of an in-memory pair: enough of an MCP server to drive the client through
 * every path. Upstream's `testing/` does the same job for its suite.
 *
 * Handlers are closures by method; `tools/list` pages when `$pageSize` is set; `answerAfter`
 * delays one method's reply so timeouts and cancellation can be seen; `progressEvery` sends
 * progress notifications for a slow `tools/call`.
 */
final class FakeMcpServer
{
    /** @var list<array<string, mixed>> every message the client sent, in order */
    public array $received = [];

    /** @var list<array<string, mixed>> */
    public array $tools = [];

    public ?int $pageSize = null;

    /** @var array<string, float> method => seconds to wait before answering */
    public array $answerAfter = [];

    /** How many progress notifications to send while a delayed `tools/call` is pending. */
    public int $progressEvery = 0;

    public string $protocolVersion = Protocol::LATEST_VERSION;

    /** @var array<string, mixed> */
    public array $capabilities = ['tools' => ['listChanged' => true]];

    public ?string $instructions = null;

    /** @var array<string, Closure(array<string, mixed>): mixed> */
    public array $handlers = [];

    private int $nextId = 1000;

    /** @var array<int, Closure(mixed): void> replies to requests this server made */
    private array $awaiting = [];

    public function __construct(public readonly InMemoryTransport $transport)
    {
        $transport->onMessage(fn (array $message) => $this->handle($message));
        $transport->start();
    }

    /** Send a notification to the client. */
    public function notify(string $method, mixed $params = null): void
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method];

        if ($params !== null) {
            $message['params'] = $params;
        }

        $this->transport->send($message);
    }

    /** Ask the client something; the reply goes to $onReply. */
    public function request(string $method, mixed $params, Closure $onReply): void
    {
        $id = $this->nextId++;
        $this->awaiting[$id] = $onReply;
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if ($params !== null) {
            $message['params'] = $params;
        }

        $this->transport->send($message);
    }

    /** Send raw bytes-as-message, for a malformed reply. */
    public function sendRaw(array $message): void
    {
        $this->transport->send($message);
    }

    /** @param array<string, mixed> $message */
    private function handle(array $message): void
    {
        $this->received[] = $message;

        if (JsonRpc::isResponse($message)) {
            $reply = $this->awaiting[$message['id']] ?? null;
            unset($this->awaiting[$message['id']]);
            $reply?->__invoke($message);

            return;
        }

        if (JsonRpc::isNotification($message)) {
            return;
        }

        $method = $message['method'];
        $id = $message['id'];
        $params = $message['params'] ?? [];
        $delay = $this->answerAfter[$method] ?? 0.0;

        $answer = function () use ($method, $id, $params): void {
            try {
                $result = $this->result($method, is_array($params) ? $params : []);
                $this->transport->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            } catch (\Pig\Mcp\Protocol\McpError $error) {
                $this->transport->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $error->rpcCode, 'message' => $error->getMessage()]]);
            }
        };

        if ($delay <= 0) {
            $answer();

            return;
        }

        if ($this->progressEvery > 0 && $method === 'tools/call' && isset($params['_meta']['progressToken'])) {
            $token = $params['_meta']['progressToken'];
            $step = $delay / ($this->progressEvery + 1);

            for ($i = 1; $i <= $this->progressEvery; $i++) {
                Loop::get()->delay($step * $i, fn () => $this->notify('notifications/progress', ['progressToken' => $token, 'progress' => $i, 'total' => $this->progressEvery]));
            }
        }

        Loop::get()->delay($delay, $answer);
    }

    /** @param array<string, mixed> $params */
    private function result(string $method, array $params): mixed
    {
        if (isset($this->handlers[$method])) {
            return ($this->handlers[$method])($params);
        }

        return match ($method) {
            'initialize' => [
                'protocolVersion' => $this->protocolVersion,
                'capabilities' => $this->capabilities === [] ? new \stdClass() : $this->capabilities,
                'serverInfo' => ['name' => 'fake', 'version' => '1.0'],
                ...($this->instructions === null ? [] : ['instructions' => $this->instructions]),
            ],
            'ping' => new \stdClass(),
            'tools/list' => $this->toolsPage(isset($params['cursor']) ? (int) $params['cursor'] : 0),
            'tools/call' => ['content' => [['type' => 'text', 'text' => 'called ' . ($params['name'] ?? '?') . ' with ' . json_encode($params['arguments'] ?? null)]]],
            default => throw new \Pig\Mcp\Protocol\McpError(JsonRpc::METHOD_NOT_FOUND, "Method not found: {$method}"),
        };
    }

    /** @return array<string, mixed> */
    private function toolsPage(int $from): array
    {
        if ($this->pageSize === null) {
            return ['tools' => $this->tools];
        }

        $page = array_slice($this->tools, $from, $this->pageSize);
        $next = $from + $this->pageSize;

        return ['tools' => $page, ...($next < count($this->tools) ? ['nextCursor' => (string) $next] : [])];
    }
}
