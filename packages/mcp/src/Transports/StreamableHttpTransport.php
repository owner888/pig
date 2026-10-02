<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\SseParser;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\McpConnectionClosedError;
use RuntimeException;
use Throwable;

/**
 * MCP over HTTP, the streamable kind — upstream's `transports/streamable-http.ts`.
 *
 * Every message is a `POST`. A notification is acknowledged with `202` and no body. A request is
 * answered either as `application/json` — one message, or a batch — or as `text/event-stream`,
 * where the reply arrives among other messages the server wants to send; the stream is read
 * until the reply is in, and if the server closed it first and had numbered its events, it is
 * resumed with `GET` and `Last-Event-ID`. After `notifications/initialized` a standing `GET`
 * stream is opened for everything the server says on its own, reconnected with backoff when it
 * drops, and given up when the server answers `405` — not every server has one.
 *
 * `Mcp-Session-Id` is kept from the first response that carries it and sent on every request
 * after; `MCP-Protocol-Version` likewise once `initialize` has picked one. `close()` sends
 * `DELETE` so the server can forget the session, and does not wait long for the answer.
 *
 * An `AuthProvider` adds the bearer token and is handed a `401` (or a `403` asking for more
 * scope) once per request, so a sign-in can refresh or re-authorize and the request is retried
 * with whatever it left behind. That is the hook the OAuth half plugs into.
 */
final class StreamableHttpTransport extends TransportEvents
{
    private const int MAX_ERROR_BODY_BYTES = 8 * 1024;

    private const int ERROR_MESSAGE_BODY_CHARS = 500;

    private const float DEFAULT_RECONNECT_INITIAL_DELAY = 1.0;

    private const float DEFAULT_RECONNECT_MAX_DELAY = 30.0;

    private const int DEFAULT_RECONNECT_MAX_RETRIES = 5;

    private readonly AbortController $controller;

    private bool $started = false;

    private bool $closed = false;

    private ?string $sessionId = null;

    private ?string $protocolVersion = null;

    private bool $getStreamStarted = false;

    /**
     * @param array<string, string> $headers sent on every request
     * @param array{initialDelay?: float, maxDelay?: float, maxRetries?: int} $reconnect
     */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly ?AuthProvider $authProvider = null,
        private readonly ?HttpClient $http = null,
        private readonly bool $openGetStream = true,
        private readonly int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES,
        private readonly array $reconnect = [],
        private readonly float $timeout = 60.0,
    ) {
        $this->controller = new AbortController();
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    #[\Override]
    public function start(): void
    {
        if ($this->started) {
            throw new RuntimeException('MCP Streamable HTTP transport already started');
        }

        if ($this->closed) {
            throw new McpConnectionClosedError();
        }

        $this->started = true;
    }

    #[\Override]
    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    #[\Override]
    public function send(array $message): void
    {
        if (!$this->started || $this->closed) {
            throw new McpConnectionClosedError();
        }

        $body = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            throw new RuntimeException('Could not encode MCP message: ' . json_last_error_msg());
        }

        $response = $this->authorizedFetch('POST', [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ], $body);

        $this->checkResponse($response);
        $this->captureSession($response);

        if (!JsonRpc::isRequest($message)) {
            // Notifications and responses are acknowledged with 202 and carry no reply; ignore any body.
            $response->body->close();

            // The server-to-client stream may only open once the session is initialized.
            if (($message['method'] ?? null) === 'notifications/initialized') {
                $this->startGetStream();
            }

            return;
        }

        if ($response->status === 202 || $response->status === 204) {
            throw new McpHttpError($response->status, "MCP server accepted request {$message['method']} without a response");
        }

        $type = self::contentType($response);

        if ($type === 'application/json') {
            $decoded = json_decode($response->body->all(), true);

            foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                $this->emitMessage(JsonRpc::parse($item));
            }

            return;
        }

        if ($type === 'text/event-stream') {
            // In a fiber of its own: the reply arrives on this stream, through `emitMessage()`,
            // and the client's `send()` has to return for the client to be waiting for it.
            Async::spawn(fn () => $this->consumeResponseStream($response, $message['id']));

            return;
        }

        $response->body->close();

        throw new McpHttpError($response->status, 'Unsupported MCP response content type: ' . ($type ?? 'missing'));
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->controller->abort('transport closed');

        if ($this->started && $this->sessionId !== null) {
            // Best effort, briefly: the session expires on the server either way.
            $controller = new AbortController();
            $timer = Loop::get()->delay(1.0, static fn () => $controller->abort('timeout'));

            try {
                $response = $this->client()->send(new Request('DELETE', $this->url, $this->requestHeaders()), $controller->signal);
                $response->body->close();
            } catch (Throwable) {
                // Resolving auth headers failed, or the server did not answer in time.
            } finally {
                Loop::get()->cancel($timer);
            }
        }

        $this->emitClose();
    }

    // ---- requests -------------------------------------------------------------------------------

    /**
     * Fetch with auth headers. A 401 (or a 403 asking for more scope) is handed to the auth
     * provider once, and the request is retried with whatever credentials it left behind.
     *
     * @param array<string, string> $extra
     */
    private function authorizedFetch(string $method, array $extra, ?string $body = null): Response
    {
        for ($attempt = 0; ; $attempt++) {
            [$headers, $token] = $this->requestHeadersWithToken($extra);

            try {
                $response = $this->client()->send(new Request($method, $this->url, $headers, $body), $this->controller->signal);
            } catch (HttpError $error) {
                if ($this->closed) {
                    throw new McpConnectionClosedError();
                }

                throw $error;
            }

            if ($attempt > 0 || $this->authProvider === null || !self::needsAuthorization($response)) {
                return $response;
            }

            try {
                $this->authProvider->onUnauthorized($response, $this->url, $token);
            } finally {
                $response->body->close();
            }
        }
    }

    /** @param array<string, string> $extra */
    private function requestHeaders(array $extra = []): array
    {
        return $this->requestHeadersWithToken($extra)[0];
    }

    /**
     * @param array<string, string> $extra
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function requestHeadersWithToken(array $extra): array
    {
        $headers = [...$this->headers, ...$extra];

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }

        $token = $this->authProvider?->token();

        if ($token !== null && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return [$headers, $token];
    }

    private function client(): HttpClient
    {
        return $this->http ?? new HttpClient($this->timeout);
    }

    private function captureSession(Response $response): void
    {
        $sessionId = $response->header('mcp-session-id');

        if ($sessionId !== null && $sessionId !== '') {
            $this->sessionId = $sessionId;
        }
    }

    private function checkResponse(Response $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        $body = substr($response->body->all(), 0, self::MAX_ERROR_BODY_BYTES);

        if ($response->status === 401) {
            throw new McpAuthRequiredError($response->header('www-authenticate'), $body);
        }

        if ($response->status === 404 && $this->sessionId !== null) {
            throw new McpSessionExpiredError($body);
        }

        throw new McpHttpError($response->status, self::describeHttpFailure($response->status, $body), $body);
    }

    private static function contentType(Response $response): ?string
    {
        $header = $response->header('content-type');

        if ($header === null) {
            return null;
        }

        return strtolower(trim(explode(';', $header, 2)[0]));
    }

    /** 401, or 403 with an `insufficient_scope` bearer challenge (step-up authorization). */
    private static function needsAuthorization(Response $response): bool
    {
        if ($response->status === 401) {
            return true;
        }

        if ($response->status !== 403) {
            return false;
        }

        return preg_match('/(?:^|[\s,])error="?insufficient_scope"?/i', $response->header('www-authenticate') ?? '') === 1;
    }

    /** Statuses worth retrying when a stream fails to (re)open. */
    private static function isTransientStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    private static function describeHttpFailure(int $status, string $body): string
    {
        $text = trim($body);
        $snippet = mb_strlen($text) > self::ERROR_MESSAGE_BODY_CHARS
            ? mb_substr($text, 0, self::ERROR_MESSAGE_BODY_CHARS - 3) . '...'
            : $text;

        return "MCP HTTP request failed with status {$status}" . ($snippet !== '' ? ": {$snippet}" : '');
    }

    // ---- streams --------------------------------------------------------------------------------

    /**
     * Read SSE off a response body until it ends, handing each JSON-RPC message on.
     *
     * @param array{lastEventId: ?string, retry: ?float, received: bool} $cursor
     * @param Closure(array<string, mixed>): void|null $onMessage
     */
    private function consumeSse(Response $response, array &$cursor, ?Closure $onMessage = null): void
    {
        $parser = new SseParser();

        foreach ($response->body as $chunk) {
            if (strlen($chunk) > $this->maxMessageBytes) {
                throw new RuntimeException("MCP SSE event exceeds {$this->maxMessageBytes} bytes");
            }

            foreach ($parser->feed($chunk) as $event) {
                $cursor['received'] = true;

                if ($event->id !== null) {
                    $cursor['lastEventId'] = $event->id;
                }

                if ($event->retry !== null) {
                    $cursor['retry'] = $event->retry / 1000;
                }

                // Events without data prime resumption; other event types are not JSON-RPC.
                if (trim($event->data) === '' || ($event->type !== '' && $event->type !== 'message')) {
                    continue;
                }

                try {
                    $message = JsonRpc::parse(json_decode($event->data, true, 512, JSON_THROW_ON_ERROR));
                } catch (Throwable $error) {
                    $this->emitError($error);
                    continue;
                }

                if ($onMessage !== null) {
                    $onMessage($message);
                }

                $this->emitMessage($message);
            }
        }
    }

    /**
     * Read the SSE stream answering one request. When the stream ends or breaks before the
     * response arrives and the server assigned event IDs, resume it with GET and `Last-Event-ID`,
     * as the server may close response streams at will. Otherwise only this request fails.
     */
    private function consumeResponseStream(Response $response, int|string $requestId): void
    {
        $cursor = ['lastEventId' => null, 'retry' => null, 'received' => false];
        $answered = false;
        $onMessage = static function (array $message) use (&$answered, $requestId): void {
            if (JsonRpc::isResponse($message) && $message['id'] === $requestId) {
                $answered = true;
            }
        };

        $stream = $response;
        $failure = null;

        for ($attempt = 0; ;) {
            if ($stream !== null) {
                try {
                    $this->consumeSse($stream, $cursor, $onMessage);
                    $failure = null;
                } catch (Throwable $error) {
                    $failure = $error;
                }
            }

            if ($answered || $this->closed) {
                return;
            }

            if ($failure !== null && !$this->isRetryable($failure)) {
                break;
            }

            if ($cursor['lastEventId'] === null || $attempt >= $this->maxRetries()) {
                break;
            }

            if ($cursor['received']) {
                $attempt = 0;
            }

            $cursor['received'] = false;

            if (!$this->sleep($this->reconnectDelay($attempt++, $cursor['retry']))) {
                return;
            }

            try {
                $stream = $this->openSseStream($cursor['lastEventId']);
            } catch (Throwable $error) {
                $failure = $error;

                if (!$this->isRetryable($error)) {
                    break;
                }

                $stream = null;
            }
        }

        if ($this->closed) {
            return;
        }

        $reason = $failure === null ? 'stream ended without a response' : $failure->getMessage();
        $this->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'error' => ['code' => JsonRpc::INTERNAL_ERROR, 'message' => "MCP response stream failed: {$reason}"],
        ]);
    }

    private function startGetStream(): void
    {
        if (!$this->openGetStream || $this->getStreamStarted || $this->closed) {
            return;
        }

        $this->getStreamStarted = true;
        Async::spawn(fn () => $this->runGetStream());
    }

    /** Keep the server-to-client stream open, reconnecting with backoff when it drops. */
    private function runGetStream(): void
    {
        $cursor = ['lastEventId' => null, 'retry' => null, 'received' => false];

        for ($attempt = 0; !$this->closed;) {
            try {
                $stream = $this->openSseStream($cursor['lastEventId']);

                // The server does not offer a GET stream.
                if ($stream === null) {
                    return;
                }

                $openedAt = microtime(true);
                $this->consumeSse($stream, $cursor);

                // A stream that stayed up for a while counts as healthy, even if it was idle.
                if ($cursor['received'] || microtime(true) - $openedAt > $this->maxDelay()) {
                    $attempt = 0;
                }
            } catch (Throwable $error) {
                if ($this->closed) {
                    return;
                }

                if (!$this->isRetryable($error)) {
                    $this->emitError($error);

                    return;
                }
            }

            $cursor['received'] = false;

            if ($attempt >= $this->maxRetries()) {
                $this->emitError(new RuntimeException('MCP server-to-client stream dropped and could not be reopened'));

                return;
            }

            if (!$this->sleep($this->reconnectDelay($attempt++, $cursor['retry']))) {
                return;
            }
        }
    }

    /** Open a GET SSE stream. Null when the server answers 405 (no GET stream). */
    private function openSseStream(?string $lastEventId): ?Response
    {
        $response = $this->authorizedFetch('GET', [
            'Accept' => 'text/event-stream',
            ...($lastEventId === null ? [] : ['Last-Event-ID' => $lastEventId]),
        ]);

        if ($response->status === 405) {
            $response->body->close();

            return null;
        }

        $this->checkResponse($response);
        $this->captureSession($response);

        $type = self::contentType($response);

        if ($type !== 'text/event-stream') {
            $response->body->close();

            throw new McpHttpError($response->status, 'Unsupported MCP GET response content type: ' . ($type ?? 'missing'));
        }

        return $response;
    }

    /**
     * Network failures and transient statuses are retried; auth, session and protocol errors are
     * not. pig's `HttpError` is the network layer's word for a connection that failed or dropped.
     */
    private function isRetryable(Throwable $error): bool
    {
        if ($error instanceof McpHttpError) {
            return self::isTransientStatus($error->status);
        }

        return $error instanceof HttpError || $error instanceof \Pig\Async\SocketError;
    }

    private function reconnectDelay(int $attempt, ?float $serverDelay): float
    {
        if ($serverDelay !== null) {
            return $serverDelay;
        }

        $initial = $this->reconnect['initialDelay'] ?? self::DEFAULT_RECONNECT_INITIAL_DELAY;

        return min($initial * (2 ** $attempt), $this->maxDelay());
    }

    private function maxDelay(): float
    {
        return $this->reconnect['maxDelay'] ?? self::DEFAULT_RECONNECT_MAX_DELAY;
    }

    private function maxRetries(): int
    {
        return $this->reconnect['maxRetries'] ?? self::DEFAULT_RECONNECT_MAX_RETRIES;
    }

    /** False when the transport closed while waiting. */
    private function sleep(float $seconds): bool
    {
        if ($this->controller->signal->aborted()) {
            return false;
        }

        $deferred = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($deferred): void {
            if (!$deferred->isComplete()) {
                $deferred->complete(true);
            }
        });
        $listener = $this->controller->signal->onAbort(static function () use ($deferred, $timer): void {
            Loop::get()->cancel($timer);

            if (!$deferred->isComplete()) {
                $deferred->complete(false);
            }
        });

        try {
            return (bool) $deferred->future->await();
        } finally {
            $this->controller->signal->removeListener($listener);
        }
    }
}
