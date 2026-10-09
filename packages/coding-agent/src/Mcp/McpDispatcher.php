<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mcp;

use Closure;
use Pig\Agent\AgentEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Async\Async;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Version;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\Protocol;
use Throwable;

/**
 * The MCP server's half that is the same over HTTP and over stdio: the JSON-RPC methods, the
 * one tool, and the queue the tool's calls wait in.
 *
 * A transport decodes bytes into one message and hands it here with a `$send` for everything
 * bound back to that requester. `initialize`, `ping`, `tools/list` and a `tools/call` that is
 * wrong on its face answer through it before `dispatch()` returns; a good `tools/call` after —
 * progress notifications while the turn runs, when the caller asked for them with a
 * `progressToken`, and the response when it ends. The transport decides what a "later" answer
 * looks like on its wire: an SSE stream over HTTP, just another line on stdio.
 *
 * **Asks are a queue, not a refusal.** The session takes one turn at a time, so a second `ask`
 * while one runs waits for the first to end and then takes its own turn — which is what a
 * caller handing pig two questions means, and what a tool result saying "busy, try later" would
 * have made the caller re-implement. The order is arrival order; nothing is dropped.
 */
final class McpDispatcher
{
    public const string TOOL = 'ask';

    private string $protocolVersion = Protocol::LATEST_VERSION;

    /** @var list<Closure(): void> turns waiting their go, oldest first */
    private array $queue = [];

    private bool $running = false;

    public function __construct(private readonly AgentSession $session)
    {
    }

    /**
     * One message, decoded.
     *
     * @param Closure(array<string, mixed>): void $send every message bound for this requester
     * @return bool whether a response will come through `$send` — false for a notification or a
     *              client response, which are taken and answered with nothing
     */
    public function dispatch(mixed $message, Closure $send): bool
    {
        if (!JsonRpc::isRequest($message)) {
            // A notification, or the client's answer to something — pig asks nothing, so there
            // is nothing to match it to. Both are taken and dropped.
            return false;
        }

        $id = $message['id'];
        $params = JsonRpc::isObject($message['params'] ?? null) ? $message['params'] : [];

        switch ($message['method']) {
            case 'initialize':
                $asked = $params['protocolVersion'] ?? null;
                $this->protocolVersion = is_string($asked) && in_array($asked, Protocol::SUPPORTED_VERSIONS, true)
                    ? $asked
                    : Protocol::LATEST_VERSION;

                $send(self::result($id, [
                    'protocolVersion' => $this->protocolVersion,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'pig', 'version' => Version::current()],
                    'instructions' => 'Ask pig, a coding agent working in ' . $this->session->cwd()
                        . '. Each call is a turn in one conversation, so a follow-up can refer to an earlier answer.',
                ]));

                return true;

            case 'ping':
                $send(self::result($id, new \stdClass()));

                return true;

            case 'tools/list':
                $send(self::result($id, ['tools' => [self::toolDefinition()]]));

                return true;

            case 'tools/call':
                if (($params['name'] ?? null) !== self::TOOL) {
                    $send(self::error($id, JsonRpc::INVALID_PARAMS, 'Unknown tool: ' . json_encode($params['name'] ?? null)));

                    return true;
                }

                $prompt = $params['arguments']['prompt'] ?? null;

                if (!is_string($prompt) || trim($prompt) === '') {
                    $send(self::error($id, JsonRpc::INVALID_PARAMS, '`prompt` must be a non-empty string'));

                    return true;
                }

                $token = $params['_meta']['progressToken'] ?? null;
                $this->enqueue(fn () => $this->ask($send, $id, $prompt, JsonRpc::isId($token) ? $token : null));

                return true;

            default:
                $send(self::error($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$message['method']}"));

                return true;
        }
    }

    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /** @param Closure(): void $turn */
    private function enqueue(Closure $turn): void
    {
        $this->queue[] = $turn;
        $this->drain();
    }

    /** The next turn, in a fiber of its own; the one after it when that ends, however it ends. */
    private function drain(): void
    {
        if ($this->running || $this->queue === []) {
            return;
        }

        $this->running = true;
        $turn = array_shift($this->queue);

        Async::spawn(function () use ($turn): void {
            try {
                $turn();
            } finally {
                $this->running = false;
                $this->drain();
            }
        });
    }

    /**
     * The turn: progress while it runs, the answer when it ends.
     *
     * @param Closure(array<string, mixed>): void $send
     */
    private function ask(Closure $send, string|int|float $id, string $prompt, string|int|float|null $token): void
    {
        $progress = 0;
        $unsubscribe = $this->session->subscribe(static function (AgentEvent $event) use ($send, $token, &$progress): void {
            if ($token === null) {
                return;
            }

            if ($event instanceof MessageUpdateEvent && $event->assistantMessageEvent instanceof TextDeltaEvent) {
                $progress += strlen($event->assistantMessageEvent->delta);
                $send([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => ['progressToken' => $token, 'progress' => $progress, 'message' => $event->assistantMessageEvent->delta],
                ]);
            }
        });

        try {
            $this->session->prompt($prompt, [], 'rpc');
            $result = $this->answer();
        } catch (Throwable $e) {
            $result = self::toolResult($e->getMessage(), isError: true);
        } finally {
            $unsubscribe();
        }

        $send(self::result($id, $result));
    }

    /**
     * The last answer as a tool result: its text blocks, or its error — `PrintMode::say()`'s
     * reading of the transcript, for a caller that is a program rather than a pipe.
     *
     * @return array{content: list<array{type: 'text', text: string}>, isError?: bool}
     */
    private function answer(): array
    {
        $messages = $this->session->messages();
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if (!$last instanceof AssistantMessage) {
            return self::toolResult('');
        }

        if ($last->stopReason->isFailure()) {
            return self::toolResult($last->errorMessage ?? "Request {$last->stopReason->value}", isError: true);
        }

        $text = [];

        foreach ($last->content as $block) {
            if ($block instanceof TextContent) {
                $text[] = $block->text;
            }
        }

        return self::toolResult(implode("\n", $text));
    }

    /** @return array<string, mixed> */
    public static function toolDefinition(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Ask pig, a coding agent with its own tools (read, write, edit, bash) in its working directory. '
                . 'Returns the final answer as text. Calls share one conversation, so a follow-up can build on an earlier answer; '
                . 'calls made while one is running wait their turn.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'What to ask or have done.'],
                ],
                'required' => ['prompt'],
            ],
        ];
    }

    /** @return array{content: list<array{type: 'text', text: string}>, isError?: bool} */
    private static function toolResult(string $text, bool $isError = false): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $text]]];

        if ($isError) {
            $result['isError'] = true;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function result(string|int|float $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public static function error(string|int|float|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    public static function encode(mixed $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
