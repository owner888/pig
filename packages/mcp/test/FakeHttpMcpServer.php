<?php

declare(strict_types=1);

namespace Pig\Mcp\Test;

use Closure;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A streamable-HTTP MCP server on a loopback port, enough to drive `StreamableHttpTransport`
 * through its shapes: JSON replies, SSE replies, a session id, the standing GET stream, 401
 * until a token arrives, 405 for no GET stream, and a stream cut off before the reply.
 *
 * One request per connection, read whole (`Content-Length`), answered, closed — except a GET
 * stream, which is held open and written to on demand. HTTP/1.1 with `Connection: close`, which
 * pig's `HttpClient` is happy with.
 */
final class FakeHttpMcpServer
{
    /** @var list<array{method: string, path: string, headers: array<string, string>, body: array<string, mixed>|null}> */
    public array $requests = [];

    /** How a request is answered: `'json'` or `'sse'`. */
    public string $replyAs = 'json';

    public ?string $sessionId = null;

    /** `null` means no GET stream (405); otherwise it is held open. */
    public ?bool $getStream = false;

    /** A bearer token the server insists on; null means no auth. */
    public ?string $requireToken = null;

    /**
     * Speak OAuth too: `.well-known` metadata naming this server as its own authorization server,
     * dynamic registration, and a token endpoint that redeems any code `issueCode()` handed out and
     * any refresh token it issued. With this on, `requireToken` is the token last issued.
     */
    public bool $oauth = false;

    /** Codes `issueCode()` handed out, by code => the PKCE challenge they were issued against. */
    public array $codes = [];

    /** @var list<string> refresh tokens issued, each good once (rotation) */
    public array $refreshTokens = [];

    /** @var list<array<string, string>> every token request's form, for assertions */
    public array $tokenRequests = [];

    /** @var array<string, mixed>|null the last registration body */
    public ?array $registration = null;

    /** Seconds the issued access token is said to live; null for no `expires_in`. */
    public ?int $expiresIn = 3600;

    /** The `WWW-Authenticate` sent with a 401. */
    public string $challenge = 'Bearer realm="mcp"';

    private int $issued = 0;

    /** Hand out an authorization code for the given PKCE challenge, as the browser flow would. */
    public function issueCode(string $challenge): string
    {
        $code = 'code-' . ++$this->issued;
        $this->codes[$code] = $challenge;

        return $code;
    }

    /** When set, the SSE reply to `tools/call` is cut off after this many events, before the answer. */
    public ?int $cutStreamAfter = null;

    /** @var array<string, Closure(array<string, mixed>): mixed> */
    public array $handlers = [];

    /** @var resource */
    private $socket;

    private string $watcher;

    /** @var list<resource> GET streams held open */
    private array $streams = [];

    /** @var list<string> */
    private array $watchers = [];

    private int $eventId = 0;

    public function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->watcher = Loop::get()->onReadable($socket, fn ($listening) => $this->accept($listening));
    }

    public function url(): string
    {
        return 'http://' . stream_socket_get_name($this->socket, false) . '/mcp';
    }

    public function stop(): void
    {
        $loop = Loop::get();
        $loop->cancel($this->watcher);

        foreach ($this->watchers as $watcher) {
            $loop->cancel($watcher);
        }

        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->watchers = $this->streams = [];
    }

    /** Push a notification down every open GET stream. */
    public function pushNotification(string $method, mixed $params = null): void
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method];

        if ($params !== null) {
            $message['params'] = $params;
        }

        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fwrite($stream, $this->sseEvent($message));
            }
        }
    }

    /** Drop every open GET stream, as a server restart would. */
    public function dropStreams(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->streams = [];
    }

    public function openStreams(): int
    {
        return count(array_filter($this->streams, 'is_resource'));
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_values(array_filter(array_map(static fn (array $r): ?string => $r['body']['method'] ?? null, $this->requests)));
    }

    // ---- serving ----------------------------------------------------------------------------------

    private function accept(mixed $listening): void
    {
        $connection = stream_socket_accept($listening, 0);

        if ($connection === false) {
            return;
        }

        stream_set_blocking($connection, false);
        $buffer = '';
        $watcher = null;

        $watcher = Loop::get()->onReadable($connection, function ($peer) use (&$buffer, &$watcher): void {
            $data = fread($peer, 65536);

            if (is_string($data)) {
                $buffer .= $data;
            }

            if (!str_contains($buffer, "\r\n\r\n")) {
                if (feof($peer)) {
                    Loop::get()->cancel($watcher);
                    fclose($peer);
                }

                return;
            }

            [$head, $body] = explode("\r\n\r\n", $buffer, 2);
            $headers = self::headers($head);
            $length = (int) ($headers['content-length'] ?? 0);

            if (strlen($body) < $length) {
                return;
            }

            Loop::get()->cancel($watcher);
            $this->handle($peer, $head, $headers, $body);
        });

        $this->watchers[] = $watcher;
    }

    /** @param array<string, string> $headers */
    private function handle(mixed $connection, string $head, array $headers, string $body): void
    {
        [$method, $path] = explode(' ', explode("\r\n", $head)[0]);
        $decoded = $body === '' ? null : json_decode($body, true);
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => is_array($decoded) ? $decoded : null];

        if ($this->oauth && $this->oauthEndpoint($connection, $method, $path, $body)) {
            return;
        }

        if ($this->requireToken !== null && ($headers['authorization'] ?? '') !== "Bearer {$this->requireToken}") {
            $this->respond($connection, 401, ['WWW-Authenticate' => $this->challenge], 'who are you');

            return;
        }

        if ($method === 'DELETE') {
            $this->respond($connection, 200, [], '');

            return;
        }

        if ($method === 'GET') {
            if ($this->getStream === null) {
                $this->respond($connection, 405, ['Allow' => 'POST'], '');

                return;
            }

            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n" . $this->sessionHeader() . "\r\n");
            $this->streams[] = $connection;

            return;
        }

        if (!is_array($decoded)) {
            $this->respond($connection, 400, [], 'bad json');

            return;
        }

        if (!array_key_exists('id', $decoded)) {
            // A notification: 202 and no body.
            $this->respond($connection, 202, [], '');

            return;
        }

        $reply = $this->reply($decoded);

        if ($this->replyAs === 'json') {
            $this->respond($connection, 200, ['Content-Type' => 'application/json'], (string) json_encode($reply));

            return;
        }

        $events = '';

        if ($this->cutStreamAfter !== null && ($decoded['method'] ?? '') === 'tools/call') {
            for ($i = 0; $i < $this->cutStreamAfter; $i++) {
                $events .= $this->sseEvent(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => "chunk {$i}"]]);
            }

            // Cut: the stream ends with no reply in it. A resumption GET will carry the answer.
            $this->pendingAnswer = $reply;
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n" . $this->sessionHeader() . "\r\n" . $events);
            fclose($connection);

            return;
        }

        $events .= $this->sseEvent($reply);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n" . $this->sessionHeader() . "\r\n" . $events);
        fclose($connection);
    }

    /** @var array<string, mixed>|null the reply a cut stream owes, delivered on the next GET */
    private ?array $pendingAnswer = null;

    private function origin(): string
    {
        return 'http://' . stream_socket_get_name($this->socket, false);
    }

    /** The OAuth endpoints; true when `$path` was one of them. */
    private function oauthEndpoint(mixed $connection, string $method, string $path, string $body): bool
    {
        $pathOnly = (string) parse_url($path, PHP_URL_PATH);
        $json = static fn (array $data): string => (string) json_encode($data);

        if ($pathOnly === '/.well-known/oauth-protected-resource/mcp' || $pathOnly === '/.well-known/oauth-protected-resource') {
            $this->respond($connection, 200, ['Content-Type' => 'application/json'], $json([
                'resource' => $this->url(),
                'authorization_servers' => [$this->origin()],
                'scopes_supported' => ['mcp:tools'],
            ]));

            return true;
        }

        if ($pathOnly === '/.well-known/oauth-authorization-server') {
            $this->respond($connection, 200, ['Content-Type' => 'application/json'], $json([
                'issuer' => $this->origin(),
                'authorization_endpoint' => $this->origin() . '/authorize',
                'token_endpoint' => $this->origin() . '/token',
                'registration_endpoint' => $this->origin() . '/register',
                'response_types_supported' => ['code'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
            ]));

            return true;
        }

        if ($pathOnly === '/register' && $method === 'POST') {
            $this->registration = json_decode($body, true);
            $this->respond($connection, 201, ['Content-Type' => 'application/json'], $json([
                'client_id' => 'client-' . ++$this->issued,
                'redirect_uris' => $this->registration['redirect_uris'] ?? [],
            ]));

            return true;
        }

        if ($pathOnly === '/token' && $method === 'POST') {
            parse_str($body, $form);
            $this->tokenRequests[] = $form;
            $grant = $form['grant_type'] ?? '';

            if ($grant === 'authorization_code') {
                $challenge = $this->codes[$form['code'] ?? ''] ?? null;
                $expected = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)), '+/', '-_'), '=');

                if ($challenge === null || $challenge !== $expected) {
                    $this->respond($connection, 400, ['Content-Type' => 'application/json'], $json(['error' => 'invalid_grant', 'error_description' => 'bad code or verifier']));

                    return true;
                }

                unset($this->codes[$form['code']]);
            } elseif ($grant === 'refresh_token') {
                $index = array_search($form['refresh_token'] ?? '', $this->refreshTokens, true);

                if ($index === false) {
                    $this->respond($connection, 400, ['Content-Type' => 'application/json'], $json(['error' => 'invalid_grant', 'error_description' => 'unknown refresh token']));

                    return true;
                }

                unset($this->refreshTokens[$index]);
            } else {
                $this->respond($connection, 400, ['Content-Type' => 'application/json'], $json(['error' => 'unsupported_grant_type']));

                return true;
            }

            $access = 'access-' . ++$this->issued;
            $refresh = 'refresh-' . ++$this->issued;
            $this->refreshTokens[] = $refresh;
            $this->requireToken = $access;
            $this->respond($connection, 200, ['Content-Type' => 'application/json'], $json(array_filter([
                'access_token' => $access,
                'token_type' => 'Bearer',
                'refresh_token' => $refresh,
                'expires_in' => $this->expiresIn,
                'scope' => 'mcp:tools',
            ], static fn ($v) => $v !== null)));

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $request */
    private function reply(array $request): array
    {
        $method = $request['method'];
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if (isset($this->handlers[$method])) {
            return ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ($this->handlers[$method])($params)];
        }

        $result = match ($method) {
            'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'fake-http', 'version' => '1.0']],
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => [['name' => 'echo', 'inputSchema' => ['type' => 'object']]]],
            'tools/call' => ['content' => [['type' => 'text', 'text' => 'echo: ' . ($params['arguments']['text'] ?? '')]]],
            default => null,
        };

        if ($result === null) {
            return ['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => "Method not found: {$method}"]];
        }

        return ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result];
    }

    /** @param array<string, string> $extra */
    private function respond(mixed $connection, int $status, array $extra, string $body): void
    {
        $reason = match ($status) { 200 => 'OK', 202 => 'Accepted', 400 => 'Bad Request', 401 => 'Unauthorized', 405 => 'Method Not Allowed', default => 'Whatever' };
        $head = "HTTP/1.1 {$status} {$reason}\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n" . $this->sessionHeader();

        foreach ($extra as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        fwrite($connection, $head . "\r\n" . $body);
        fclose($connection);
    }

    private function sessionHeader(): string
    {
        return $this->sessionId === null ? '' : "Mcp-Session-Id: {$this->sessionId}\r\n";
    }

    /** @param array<string, mixed> $message */
    private function sseEvent(array $message): string
    {
        $this->eventId++;

        return "id: {$this->eventId}\nevent: message\ndata: " . json_encode($message) . "\n\n";
    }

    /** The GET stream after a cut: delivers the owed answer first, if there is one. */
    public function deliverPendingOnStreams(): void
    {
        if ($this->pendingAnswer === null) {
            return;
        }

        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fwrite($stream, $this->sseEvent($this->pendingAnswer));
            }
        }

        $this->pendingAnswer = null;
    }

    /** @return array<string, string> lowercased */
    private static function headers(string $head): array
    {
        $headers = [];

        foreach (array_slice(explode("\r\n", $head), 1) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }
}
