<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use Pig\Async\Deferred;
use Pig\Async\Loop;
use RuntimeException;

/**
 * The loopback the browser comes back to — upstream's `OAuthCallbackServer`.
 *
 * Unlike `Pig\Ai\Utils\Oauth\CallbackServer`, whose port is registered with Google and so is a
 * constant, this one listens on **whatever port is free** (or a configured one), because the
 * redirect URI is registered with each MCP server's authorization server at sign-in, through
 * dynamic client registration. A callback is matched to the sign-in waiting for it by `state`.
 *
 * On the loop like everything else: `waitForCallback()` parks the fiber on a `Deferred` and the
 * request that arrives completes it.
 */
final class OauthCallbackServer
{
    private const int MAX_REQUEST = 16384;

    /** @var array<string, Deferred> by `state` */
    private array $pending = [];

    /** @var array<string, string> timer per `state` */
    private array $timers = [];

    /** @var array<string, resource> */
    private array $connections = [];

    /** @var array<string, string> */
    private array $partial = [];

    private int $next = 0;

    private ?string $watcher = null;

    /**
     * @param resource $socket
     */
    private function __construct(
        private mixed $socket,
        public readonly string $redirectUrl,
        private readonly string $path,
        private readonly float $timeout,
    ) {
        $this->watcher = Loop::get()->onReadable($socket, $this->accept(...));
    }

    /**
     * Listen on `$port`, or on a free one when it is 0.
     *
     * `$redirectHost` is what goes in the redirect URL; `$host` is what is bound. They differ for
     * `localhost`, which is served on 127.0.0.1 because browsers fall back to it when ::1 refuses.
     */
    public static function listen(
        string $host = '127.0.0.1',
        ?string $redirectHost = null,
        int $port = 0,
        string $path = '/callback',
        float $timeout = 300.0,
    ): self {
        $problem = null;

        set_error_handler(static function (int $number, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });

        try {
            $socket = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            $reason = $errstr !== '' ? $errstr : ($problem ?? 'unknown error');

            throw new RuntimeException("Could not listen on {$host}:{$port} for the sign-in to come back to: {$reason}");
        }

        stream_set_blocking($socket, false);
        $bound = (string) stream_socket_get_name($socket, false);
        $actualPort = (int) substr($bound, (int) strrpos($bound, ':') + 1);
        $redirectHost ??= $host;
        $hostInUrl = str_contains($redirectHost, ':') ? "[{$redirectHost}]" : $redirectHost;

        return new self($socket, "http://{$hostInUrl}:{$actualPort}{$path}", $path, $timeout);
    }

    /**
     * Wait for the browser to come back with `$state`. Answers the code; throws when the
     * authorization server refused, the callback was malformed, the wait timed out, or the
     * server was closed.
     *
     * @return array{code: string, state: string, iss?: string}
     */
    public function waitForCallback(string $state): array
    {
        if (isset($this->pending[$state])) {
            throw new RuntimeException('OAuth state is already pending');
        }

        $answer = new Deferred();
        $this->pending[$state] = $answer;
        $this->timers[$state] = Loop::get()->delay($this->timeout, function () use ($state, $answer): void {
            unset($this->pending[$state], $this->timers[$state]);

            if (!$answer->isComplete()) {
                $answer->error(new RuntimeException('OAuth callback timed out'));
            }
        });

        return $answer->future->await();
    }

    /** Stop listening; anybody still waiting is told the server closed. */
    public function close(): void
    {
        foreach ($this->pending as $state => $answer) {
            if (!$answer->isComplete()) {
                $answer->error(new RuntimeException('OAuth callback server closed'));
            }
        }

        foreach ($this->timers as $timer) {
            Loop::get()->cancel($timer);
        }

        $this->pending = [];
        $this->timers = [];

        if ($this->watcher !== null) {
            Loop::get()->cancel($this->watcher);
            $this->watcher = null;
        }

        foreach ($this->connections as $id => $connection) {
            unset($this->connections[$id], $this->partial[$id]);

            if (is_resource($connection)) {
                fclose($connection);
            }
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    /** @param resource $listening */
    private function accept(mixed $listening): void
    {
        $connection = stream_socket_accept($listening, 0);

        if ($connection === false) {
            return;
        }

        stream_set_blocking($connection, false);
        $id = (string) ++$this->next;
        $this->connections[$id] = $connection;
        $this->partial[$id] = '';

        $reader = null;
        $reader = Loop::get()->onReadable($connection, function (mixed $peer) use ($id, &$reader): void {
            $chunk = fread($peer, 8192);

            if ($chunk === false || $chunk === '') {
                if (feof($peer)) {
                    Loop::get()->cancel((string) $reader);
                    $this->drop($id);
                }

                return;
            }

            $this->partial[$id] = ($this->partial[$id] ?? '') . $chunk;

            if (strlen($this->partial[$id]) > self::MAX_REQUEST) {
                Loop::get()->cancel((string) $reader);
                $this->drop($id);

                return;
            }

            if (!str_contains($this->partial[$id], "\r\n\r\n")) {
                return;
            }

            Loop::get()->cancel((string) $reader);
            $this->handle($id, $this->partial[$id]);
        });
    }

    private function handle(string $id, string $request): void
    {
        if (preg_match('/^[A-Z]+ (\S+) HTTP\//', $request, $match) !== 1) {
            $this->drop($id);

            return;
        }

        $target = $match[1];

        if ((string) parse_url($target, PHP_URL_PATH) !== $this->path) {
            $this->reply($id, 404, false, 'Not found');

            return;
        }

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $state = is_string($query['state'] ?? null) ? $query['state'] : null;
        $pending = $state !== null ? ($this->pending[$state] ?? null) : null;

        if ($state === null || $pending === null) {
            $this->reply($id, 400, false, 'Invalid or expired OAuth state');

            return;
        }

        unset($this->pending[$state]);

        if (isset($this->timers[$state])) {
            Loop::get()->cancel($this->timers[$state]);
            unset($this->timers[$state]);
        }

        $error = is_string($query['error'] ?? null) ? $query['error'] : null;

        if ($error !== null) {
            $description = is_string($query['error_description'] ?? null) ? $query['error_description'] : $error;
            $this->reply($id, 200, false, 'Authorization failed. You may close this window.', $description);
            $pending->error(new RuntimeException($description));

            return;
        }

        $code = is_string($query['code'] ?? null) ? $query['code'] : null;

        if ($code === null) {
            $this->reply($id, 400, false, 'Missing authorization code');
            $pending->error(new RuntimeException('OAuth callback did not include an authorization code'));

            return;
        }

        $this->reply($id, 200, true, 'Signed in to the MCP server. You may now close this page.');
        $result = ['code' => $code, 'state' => $state];

        if (is_string($query['iss'] ?? null)) {
            $result['iss'] = $query['iss'];
        }

        $pending->complete($result);
    }

    private function reply(string $id, int $status, bool $ok, string $message, ?string $details = null): void
    {
        $connection = $this->connections[$id] ?? null;

        if ($connection === null || !is_resource($connection)) {
            return;
        }

        $title = $ok ? 'Signed in' : 'Sign-in failed';
        $body = '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
            . '<body style="font-family:system-ui;margin:3em auto;max-width:36em;color:#222">'
            . '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p>'
            . ($details !== null ? '<pre style="white-space:pre-wrap;color:#a33">' . htmlspecialchars($details) . '</pre>' : '')
            . '</body>';
        $reason = match ($status) { 200 => 'OK', 400 => 'Bad Request', default => 'Not Found' };
        $head = "HTTP/1.1 {$status} {$reason}\r\nContent-Type: text/html; charset=utf-8\r\nCache-Control: no-store\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n";

        stream_set_blocking($connection, true);
        fwrite($connection, $head . $body);
        $this->drop($id);
    }

    private function drop(string $id): void
    {
        $connection = $this->connections[$id] ?? null;
        unset($this->connections[$id], $this->partial[$id]);

        if ($connection !== null && is_resource($connection)) {
            fclose($connection);
        }
    }
}
