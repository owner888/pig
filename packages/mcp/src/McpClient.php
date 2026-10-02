<?php

declare(strict_types=1);

namespace Pig\Mcp;

use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Mcp\Protocol\JsonRpc;
use Pig\Mcp\Protocol\McpAbortError;
use Pig\Mcp\Protocol\McpConnectionClosedError;
use Pig\Mcp\Protocol\McpError;
use Pig\Mcp\Protocol\McpTimeoutError;
use Pig\Mcp\Protocol\Protocol;
use Pig\Mcp\Transports\Transport;
use RuntimeException;
use Throwable;

/**
 * An MCP client over any `Transport` — upstream's `client.ts`.
 *
 * `connect()` runs `initialize` and `notifications/initialized`; after that `request()` is a
 * JSON-RPC call that suspends the fiber until the reply, and the list methods page through
 * `nextCursor`. Requests the *server* makes (`ping`, `roots/list`) are answered from a table of
 * handlers, and notifications fan out to listeners by method.
 *
 * Three things the timing depends on, all upstream's: a request has a timeout that **progress
 * notifications reset**, so a long tool call that keeps reporting stays alive; an abort or a
 * timeout sends `notifications/cancelled` to the server (never for `initialize`, which the spec
 * forbids cancelling); and when the transport closes, every request in flight fails with
 * "connection closed" rather than hanging on a reply that is not coming.
 */
final class McpClient
{
    private const float DEFAULT_REQUEST_TIMEOUT = 30.0;

    private const int MAX_LIST_PAGES = 1000;

    /** @var 'idle'|'connecting'|'connected'|'closed' */
    private string $state = 'idle';

    private ?Transport $transport = null;

    private int $nextRequestId = 1;

    /** @var array<string, mixed>|null */
    private ?array $serverInfo = null;

    /** @var array<string, mixed>|null */
    private ?array $serverCapabilities = null;

    private ?string $instructions = null;

    private ?string $protocolVersion = null;

    /** @var array<int|string, array{deferred: Deferred, timer: ?string, timeout: float, cancellable: bool, onProgress: ?Closure, progressToken: int|string|null, abortListener: ?string, signal: ?AbortSignal}> */
    private array $pending = [];

    /** @var array<int|string, int|string> progress token => request id */
    private array $progressRequests = [];

    /** @var array<int|string, \Pig\Async\AbortController> requests the server made that are still being served */
    private array $incoming = [];

    /** @var array<string, Closure(mixed, AbortSignal): mixed> */
    private array $requestHandlers = [];

    /** @var array<string, array<int, Closure(mixed): void>> */
    private array $notificationListeners = [];

    /** @var array<int, Closure(Throwable): void> */
    private array $errorListeners = [];

    /** @var array<int, Closure(): void> */
    private array $closeListeners = [];

    /** @var list<Closure> what detaches this client from its transport */
    private array $disposers = [];

    private int $nextListener = 0;

    /**
     * @param array<string, mixed>                          $capabilities what this client offers
     * @param list<array{uri: string, name?: string}>|Closure|null $roots  answers `roots/list`
     */
    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly ?string $title = null,
        private readonly array $capabilities = [],
        private readonly string $requestedProtocolVersion = Protocol::LATEST_VERSION,
        private readonly float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
        array|Closure|null $roots = null,
    ) {
        $this->requestHandlers['ping'] = static fn (): array => [];

        if ($roots !== null) {
            $this->requestHandlers['roots/list'] = static fn (): array => [
                'roots' => array_values($roots instanceof Closure ? $roots() : $roots),
            ];
            $this->rootsOffered = true;
        }
    }

    /** Whether `roots/list` is answered, so the capability is announced at `initialize`. */
    private bool $rootsOffered = false;

    public function connectionState(): string
    {
        return $this->state;
    }

    /** @return array<string, mixed>|null */
    public function serverInfo(): ?array
    {
        return $this->serverInfo;
    }

    /** @return array<string, mixed>|null */
    public function serverCapabilities(): ?array
    {
        return $this->serverCapabilities;
    }

    public function instructions(): ?string
    {
        return $this->instructions;
    }

    public function protocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    /**
     * Start the transport and run the handshake.
     *
     * @return array<string, mixed> the `initialize` result
     */
    public function connect(Transport $transport): array
    {
        if ($this->state !== 'idle') {
            throw new RuntimeException("Cannot connect MCP client in {$this->state} state");
        }

        $this->state = 'connecting';
        $this->transport = $transport;
        $this->disposers = [
            $transport->onMessage(fn (array $message) => $this->handleMessage($message)),
            // Transport errors are reported only. Pending requests fail when the transport closes.
            $transport->onError(fn (Throwable $error) => $this->emitError($error)),
            $transport->onClose(fn () => $this->handleTransportClose()),
        ];

        try {
            $transport->start();

            $capabilities = $this->capabilities;

            if ($this->rootsOffered && !isset($capabilities['roots'])) {
                $capabilities['roots'] = new \stdClass();
            }

            $result = self::validateInitializeResult($this->requestInternal('initialize', [
                'protocolVersion' => $this->requestedProtocolVersion,
                'capabilities' => $capabilities === [] ? new \stdClass() : $capabilities,
                'clientInfo' => [
                    'name' => $this->name,
                    'version' => $this->version,
                    ...($this->title === null ? [] : ['title' => $this->title]),
                ],
            ], [], true));

            if (!in_array($result['protocolVersion'], Protocol::SUPPORTED_VERSIONS, true)) {
                throw new RuntimeException("MCP server selected unsupported protocol version {$result['protocolVersion']}");
            }

            $this->protocolVersion = $result['protocolVersion'];
            $this->serverInfo = $result['serverInfo'];
            $this->serverCapabilities = $result['capabilities'];
            $this->instructions = $result['instructions'] ?? null;
            $transport->setProtocolVersion($result['protocolVersion']);

            $this->notifyInternal('notifications/initialized', null, true);
            $this->state = 'connected';

            return $result;
        } catch (Throwable $error) {
            try {
                $this->close();
            } catch (Throwable) {
                // The first error is the one worth reporting.
            }

            throw $error;
        }
    }

    /**
     * @param array<string, mixed>|null $params
     * @param array{signal?: ?AbortSignal, timeout?: ?float, onProgress?: ?Closure} $options
     */
    public function request(string $method, ?array $params = null, array $options = []): mixed
    {
        return $this->requestInternal($method, $params, $options, false);
    }

    /** @param array<string, mixed>|null $params */
    public function notify(string $method, ?array $params = null): void
    {
        $this->notifyInternal($method, $params, false);
    }

    /**
     * Answer a method the server may call. Returns what removes the handler again.
     *
     * @param Closure(mixed, AbortSignal): mixed $handler
     */
    public function setRequestHandler(string $method, Closure $handler): Closure
    {
        $this->requestHandlers[$method] = $handler;

        return function () use ($method, $handler): void {
            if (($this->requestHandlers[$method] ?? null) === $handler) {
                unset($this->requestHandlers[$method]);
            }
        };
    }

    /** @param Closure(mixed): void $listener */
    public function onNotification(string $method, Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->notificationListeners[$method][$id] = $listener;

        return function () use ($method, $id): void {
            unset($this->notificationListeners[$method][$id]);

            if (($this->notificationListeners[$method] ?? []) === []) {
                unset($this->notificationListeners[$method]);
            }
        };
    }

    /** @param Closure(Throwable): void $listener */
    public function onError(Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->errorListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->errorListeners[$id]);
        };
    }

    /**
     * Called once when the connection closes, whether the transport dropped or `close()` was called.
     *
     * @param Closure(): void $listener
     */
    public function onClose(Closure $listener): Closure
    {
        $id = $this->nextListener++;
        $this->closeListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->closeListeners[$id]);
        };
    }

    /** @param array{signal?: ?AbortSignal, timeout?: ?float} $options */
    public function ping(array $options = []): void
    {
        $this->request('ping', null, $options);
    }

    /**
     * Every tool, following `nextCursor` through all pages.
     *
     * @return list<array<string, mixed>>
     */
    public function listTools(array $options = []): array
    {
        return $this->listAll('tools/list', 'tools', static fn (array $tool): bool
            => is_string($tool['name'] ?? null) && JsonRpc::isObject($tool['inputSchema'] ?? null), $options);
    }

    /** @return list<array<string, mixed>> every resource, all pages */
    public function listResources(array $options = []): array
    {
        return array_map(self::toResource(...), $this->listAll('resources/list', 'resources', self::isResource(...), $options));
    }

    /** @return array{resources: list<array<string, mixed>>, nextCursor?: string} one page */
    public function listResourcesPage(?string $cursor = null, array $options = []): array
    {
        $page = $this->listPage('resources/list', 'resources', self::isResource(...), $cursor, $options);

        return ['resources' => array_map(self::toResource(...), $page['items']), ...self::pageCursor($page)];
    }

    /** @return list<array<string, mixed>> */
    public function listResourceTemplates(array $options = []): array
    {
        return array_map(
            self::toResourceTemplate(...),
            $this->listAll('resources/templates/list', 'resourceTemplates', self::isResourceTemplate(...), $options),
        );
    }

    /** @return array{resourceTemplates: list<array<string, mixed>>, nextCursor?: string} */
    public function listResourceTemplatesPage(?string $cursor = null, array $options = []): array
    {
        $page = $this->listPage('resources/templates/list', 'resourceTemplates', self::isResourceTemplate(...), $cursor, $options);

        return ['resourceTemplates' => array_map(self::toResourceTemplate(...), $page['items']), ...self::pageCursor($page)];
    }

    /** @return array{contents: list<array<string, mixed>>} */
    public function readResource(string $uri, array $options = []): array
    {
        return self::validateReadResourceResult($this->request('resources/read', ['uri' => $uri], $options));
    }

    /**
     * @param array<string, mixed>|null $arguments
     * @return array<string, mixed> the `tools/call` result, `content` always present
     */
    public function callTool(string $name, ?array $arguments = null, array $options = []): array
    {
        $params = ['name' => $name];

        if ($arguments !== null) {
            $params['arguments'] = $arguments === [] ? new \stdClass() : $arguments;
        }

        return self::validateCallToolResult($this->request('tools/call', $params, $options));
    }

    /** Detach, fail everything in flight, and close the transport. */
    public function close(): void
    {
        $transport = $this->transport;
        $this->transport = null;

        foreach (array_splice($this->disposers, 0) as $dispose) {
            $dispose();
        }

        $this->markClosed(new McpConnectionClosedError());
        $transport?->close();
    }

    // ---- requests ---------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $params
     * @param array{signal?: ?AbortSignal, timeout?: ?float, onProgress?: ?Closure} $options
     */
    private function requestInternal(string $method, ?array $params, array $options, bool $allowConnecting): mixed
    {
        $transport = $this->requireTransport($allowConnecting);
        $signal = $options['signal'] ?? null;

        if ($signal?->aborted() === true) {
            throw new McpAbortError();
        }

        $id = $this->nextRequestId++;
        $onProgress = $options['onProgress'] ?? null;
        $progressToken = $onProgress !== null ? $id : null;

        if ($progressToken !== null) {
            $meta = JsonRpc::isObject($params['_meta'] ?? null) ? $params['_meta'] : [];
            $params = [...($params ?? []), '_meta' => [...$meta, 'progressToken' => $progressToken]];
        }

        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if ($params !== null) {
            $message['params'] = $params === [] ? new \stdClass() : $params;
        }

        $deferred = new Deferred();
        $cancellable = $method !== 'initialize';   // The spec forbids cancelling `initialize`.
        $entry = [
            'deferred' => $deferred,
            'timer' => null,
            'timeout' => $options['timeout'] ?? $this->requestTimeout,
            'cancellable' => $cancellable,
            'onProgress' => $onProgress,
            'progressToken' => $progressToken,
            'abortListener' => null,
            'signal' => $signal,
        ];

        if ($signal !== null) {
            $entry['abortListener'] = $signal->onAbort(function () use ($id, $cancellable, $signal): void {
                $this->cancelPending($id, new McpAbortError(), $cancellable, $signal->reason());
            });
        }

        $this->pending[$id] = $entry;

        if ($progressToken !== null) {
            $this->progressRequests[$progressToken] = $id;
        }

        $this->armTimeout($id);

        try {
            $transport->send($message);
        } catch (Throwable $error) {
            $this->cancelPending($id, $error, false);
        }

        return $deferred->future->await();
    }

    /** @param array<string, mixed>|null $params */
    private function notifyInternal(string $method, ?array $params, bool $allowConnecting): void
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method];

        if ($params !== null) {
            $message['params'] = $params === [] ? new \stdClass() : $params;
        }

        $this->requireTransport($allowConnecting)->send($message);
    }

    private function requireTransport(bool $allowConnecting): Transport
    {
        if ($this->transport !== null && ($this->state === 'connected' || ($allowConnecting && $this->state === 'connecting'))) {
            return $this->transport;
        }

        throw new McpConnectionClosedError("MCP client is {$this->state}");
    }

    // ---- incoming -------------------------------------------------------------------------------

    /** @param array<string, mixed> $message */
    private function handleMessage(array $message): void
    {
        if (JsonRpc::isResponse($message)) {
            $this->handleResponse($message);

            return;
        }

        if (JsonRpc::isRequest($message)) {
            // Served in a fiber of its own: a handler may suspend (roots from disk, say), and
            // this runs inside the transport's read callback, which must not.
            Async::spawn(fn () => $this->handleRequest($message));

            return;
        }

        if (JsonRpc::isNotification($message)) {
            $this->handleNotification($message['method'], $message['params'] ?? null);

            return;
        }

        $this->emitError(new McpError(JsonRpc::INVALID_REQUEST, 'Received invalid JSON-RPC message'));
    }

    /** @param array<string, mixed> $message */
    private function handleResponse(array $message): void
    {
        $id = $message['id'];
        $entry = $this->pending[$id] ?? null;

        if ($entry === null) {
            $this->emitError(new RuntimeException('Received response for unknown MCP request ' . (string) $id));

            return;
        }

        $this->removePending($id);

        if (array_key_exists('error', $message)) {
            $error = $message['error'];
            $entry['deferred']->error(new McpError((int) $error['code'], (string) $error['message'], $error['data'] ?? null));

            return;
        }

        $entry['deferred']->complete($message['result']);
    }

    /** @param array<string, mixed> $message */
    private function handleRequest(array $message): void
    {
        $transport = $this->transport;

        if ($transport === null) {
            return;
        }

        $id = $message['id'];
        $handler = $this->requestHandlers[$message['method']] ?? null;

        if ($handler === null) {
            $this->sendQuietly($transport, [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => JsonRpc::METHOD_NOT_FOUND, 'message' => "Method not found: {$message['method']}"],
            ]);

            return;
        }

        $controller = new \Pig\Async\AbortController();
        $this->incoming[$id] = $controller;

        try {
            $result = $handler($message['params'] ?? null, $controller->signal);
            $this->sendQuietly($transport, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result ?? new \stdClass()]);
        } catch (Throwable $error) {
            $responseError = $error instanceof McpError
                ? ['code' => $error->rpcCode, 'message' => $error->getMessage(), 'data' => $error->data]
                : ['code' => JsonRpc::INTERNAL_ERROR, 'message' => $error->getMessage()];
            $this->sendQuietly($transport, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $responseError]);
        } finally {
            unset($this->incoming[$id]);
        }
    }

    /** A send whose failure is reported rather than thrown: nobody is awaiting a reply to a reply. */
    private function sendQuietly(Transport $transport, array $message): void
    {
        try {
            $transport->send($message);
        } catch (Throwable $error) {
            $this->emitError($error);
        }
    }

    private function handleNotification(string $method, mixed $params): void
    {
        if ($method === 'notifications/progress') {
            $this->handleProgress($params);
        } elseif ($method === 'notifications/cancelled') {
            $this->handleCancelled($params);
        }

        foreach ($this->notificationListeners[$method] ?? [] as $listener) {
            try {
                $listener($params);
            } catch (Throwable $error) {
                $this->emitError($error);
            }
        }
    }

    private function handleProgress(mixed $params): void
    {
        if (!JsonRpc::isObject($params) || !JsonRpc::isId($params['progressToken'] ?? null) || !is_numeric($params['progress'] ?? null)) {
            return;
        }

        $requestId = $this->progressRequests[$params['progressToken']] ?? null;
        $entry = $requestId === null ? null : ($this->pending[$requestId] ?? null);

        if ($requestId === null || $entry === null) {
            return;
        }

        // Progress is a sign of life, so the clock starts again.
        $this->armTimeout($requestId);

        if ($entry['onProgress'] !== null) {
            try {
                ($entry['onProgress'])($params);
            } catch (Throwable $error) {
                $this->emitError($error);
            }
        }
    }

    private function handleCancelled(mixed $params): void
    {
        if (JsonRpc::isObject($params) && JsonRpc::isId($params['requestId'] ?? null)) {
            ($this->incoming[$params['requestId']] ?? null)?->abort((string) ($params['reason'] ?? 'cancelled'));
        }
    }

    // ---- bookkeeping ----------------------------------------------------------------------------

    private function armTimeout(int|string $id): void
    {
        $entry = &$this->pending[$id];

        if ($entry['timer'] !== null) {
            Loop::get()->cancel($entry['timer']);
            $entry['timer'] = null;
        }

        $timeout = $entry['timeout'];

        if (!is_finite($timeout) || $timeout <= 0) {
            return;
        }

        $cancellable = $entry['cancellable'];
        $entry['timer'] = Loop::get()->delay($timeout, function () use ($id, $timeout, $cancellable): void {
            $this->cancelPending($id, new McpTimeoutError($timeout * 1000), $cancellable, 'Request timed out');
        });
    }

    private function cancelPending(int|string $id, Throwable $error, bool $notifyServer, ?string $reason = null): void
    {
        $entry = $this->pending[$id] ?? null;

        if ($entry === null) {
            return;
        }

        $this->removePending($id);
        $entry['deferred']->error($error);

        if ($notifyServer && $this->transport !== null) {
            $this->sendQuietly($this->transport, [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => $id, ...($reason !== null && $reason !== '' ? ['reason' => $reason] : [])],
            ]);
        }
    }

    private function removePending(int|string $id): void
    {
        $entry = $this->pending[$id] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->pending[$id]);

        if ($entry['timer'] !== null) {
            Loop::get()->cancel($entry['timer']);
        }

        if ($entry['progressToken'] !== null) {
            unset($this->progressRequests[$entry['progressToken']]);
        }

        if ($entry['abortListener'] !== null && $entry['signal'] !== null) {
            $entry['signal']->removeListener($entry['abortListener']);
        }
    }

    private function handleTransportClose(): void
    {
        $this->markClosed(new McpConnectionClosedError());
    }

    /** Idempotent: fails in-flight requests, aborts server requests being served, flips the state. */
    private function markClosed(Throwable $error): void
    {
        $wasClosed = $this->state === 'closed';
        $this->state = 'closed';

        foreach (array_keys($this->pending) as $id) {
            $entry = $this->pending[$id];
            $this->removePending($id);
            $entry['deferred']->error($error);
        }

        foreach ($this->incoming as $controller) {
            $controller->abort($error->getMessage());
        }

        $this->incoming = [];

        if ($wasClosed) {
            return;
        }

        foreach ($this->closeListeners as $listener) {
            try {
                $listener();
            } catch (Throwable $listenerError) {
                $this->emitError($listenerError);
            }
        }
    }

    private function emitError(mixed $error): void
    {
        $normalized = JsonRpc::toError($error);

        foreach ($this->errorListeners as $listener) {
            $listener($normalized);
        }
    }

    // ---- lists ----------------------------------------------------------------------------------

    /**
     * @param Closure(array<string, mixed>): bool $isItem
     * @return array{items: list<array<string, mixed>>, nextCursor?: string}
     */
    private function listPage(string $method, string $key, Closure $isItem, ?string $cursor, array $options): array
    {
        $value = $this->request($method, $cursor === null ? null : ['cursor' => $cursor], $options);

        return self::validateListPage($method, $key, $value, $isItem);
    }

    /**
     * @param Closure(array<string, mixed>): bool $isItem
     * @return list<array<string, mixed>>
     */
    private function listAll(string $method, string $key, Closure $isItem, array $options): array
    {
        $items = [];
        $cursors = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_LIST_PAGES; $page++) {
            $result = $this->listPage($method, $key, $isItem, $cursor, $options);
            $items = [...$items, ...$result['items']];

            if (!isset($result['nextCursor'])) {
                return $items;
            }

            if (isset($cursors[$result['nextCursor']])) {
                throw new RuntimeException("MCP {$method} returned duplicate cursor: {$result['nextCursor']}");
            }

            $cursors[$result['nextCursor']] = true;
            $cursor = $result['nextCursor'];
        }

        throw new RuntimeException('MCP ' . $method . ' exceeded ' . self::MAX_LIST_PAGES . ' pages');
    }

    // ---- validation -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function validateInitializeResult(mixed $value): array
    {
        if (!JsonRpc::isObject($value)
            || !is_string($value['protocolVersion'] ?? null)
            || !JsonRpc::isObject($value['capabilities'] ?? null)
            || !JsonRpc::isObject($value['serverInfo'] ?? null)
            || !is_string($value['serverInfo']['name'] ?? null)
            || !is_string($value['serverInfo']['version'] ?? null)
            || (array_key_exists('instructions', $value) && !is_string($value['instructions']))
        ) {
            throw new McpError(JsonRpc::INVALID_REQUEST, 'Invalid MCP initialize result');
        }

        return $value;
    }

    /**
     * @param Closure(array<string, mixed>): bool $isItem
     * @return array{items: list<array<string, mixed>>, nextCursor?: string}
     */
    private static function validateListPage(string $method, string $key, mixed $value, Closure $isItem): array
    {
        $items = JsonRpc::isObject($value) ? ($value[$key] ?? null) : null;

        if (!JsonRpc::isObject($value) || !is_array($items) || ($items !== [] && !array_is_list($items))) {
            throw new McpError(JsonRpc::INVALID_REQUEST, "Invalid MCP {$method} result");
        }

        foreach ($items as $item) {
            if (!JsonRpc::isObject($item) || !$isItem($item)) {
                throw new McpError(JsonRpc::INVALID_REQUEST, "Invalid entry in MCP {$method} result");
            }
        }

        if (array_key_exists('nextCursor', $value) && !is_string($value['nextCursor'])) {
            throw new McpError(JsonRpc::INVALID_REQUEST, "Invalid MCP {$method} cursor");
        }

        return ['items' => array_values($items), ...(isset($value['nextCursor']) ? ['nextCursor' => $value['nextCursor']] : [])];
    }

    /** @param array<string, mixed> $resource */
    private static function isResource(array $resource): bool
    {
        // `name` is required by the spec, but some servers omit it; the URI stands in.
        return is_string($resource['uri'] ?? null) && (!array_key_exists('name', $resource) || is_string($resource['name']));
    }

    /** @param array<string, mixed> $template */
    private static function isResourceTemplate(array $template): bool
    {
        return is_string($template['uriTemplate'] ?? null) && (!array_key_exists('name', $template) || is_string($template['name']));
    }

    /** @param array<string, mixed> $item */
    private static function toResource(array $item): array
    {
        return [...$item, 'name' => $item['name'] ?? $item['uri']];
    }

    /** @param array<string, mixed> $item */
    private static function toResourceTemplate(array $item): array
    {
        return [...$item, 'name' => $item['name'] ?? $item['uriTemplate']];
    }

    /** @param array{items: list<array<string, mixed>>, nextCursor?: string} $page */
    private static function pageCursor(array $page): array
    {
        return isset($page['nextCursor']) ? ['nextCursor' => $page['nextCursor']] : [];
    }

    /** @return array{contents: list<array<string, mixed>>} */
    private static function validateReadResourceResult(mixed $value): array
    {
        if (!JsonRpc::isObject($value) || !is_array($value['contents'] ?? null) || ($value['contents'] !== [] && !array_is_list($value['contents']))) {
            throw new McpError(JsonRpc::INVALID_REQUEST, 'Invalid MCP resources/read result');
        }

        foreach ($value['contents'] as $contents) {
            if (!JsonRpc::isObject($contents)
                || !is_string($contents['uri'] ?? null)
                || (!is_string($contents['text'] ?? null) && !is_string($contents['blob'] ?? null))
            ) {
                throw new McpError(JsonRpc::INVALID_REQUEST, 'Invalid contents in MCP resources/read result');
            }
        }

        return $value;
    }

    /**
     * `content` is required by the spec, but servers that only return `structuredContent` omit it
     * (the SDK defaults it too).
     *
     * @return array<string, mixed>
     */
    private static function validateCallToolResult(mixed $value): array
    {
        if (!JsonRpc::isObject($value) || (array_key_exists('content', $value) && !is_array($value['content']))) {
            throw new McpError(JsonRpc::INVALID_REQUEST, 'Invalid MCP tools/call result');
        }

        if (array_key_exists('structuredContent', $value) && !JsonRpc::isObject($value['structuredContent'])) {
            throw new McpError(JsonRpc::INVALID_REQUEST, 'Invalid MCP tools/call structured content');
        }

        return array_key_exists('content', $value) ? $value : [...$value, 'content' => []];
    }
}
