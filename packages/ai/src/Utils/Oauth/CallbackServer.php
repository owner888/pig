<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;

/**
 * The one request a browser makes back to this machine at the end of a Google sign-in.
 *
 * Google's flow has no device code and no paste: it redirects to
 * `http://localhost:8085/oauth2callback?code=…&state=…`, so something on this machine has to be
 * listening. Upstream reaches for Node's `http.createServer`; PHP has no HTTP server, so this is
 * one — and deliberately the narrowest one that answers the question. It serves a single path,
 * reads three query parameters, writes one page back, and stops.
 *
 * **The port is not negotiable.** `8085` is baked into the redirect URI registered with Google,
 * so a flow that picked a free port instead would be refused by Google rather than by the
 * socket. If something else already holds it, `listen()` says so by name — which is the whole
 * reason it is a separate step from `await()`: a port that cannot be taken should be reported
 * before anybody is sent to a browser.
 *
 * It is non-blocking and on the loop, like everything else here: `await()` parks the calling
 * fiber on a `Deferred` while the loop keeps serving the terminal, and the request that arrives
 * is what completes it. A blocking accept would stop the UI for as long as the person took to
 * sign in.
 */
final class CallbackServer
{
    /** Registered with Google as part of the redirect URI, so it is a constant and not a choice. */
    public const int PORT = 8085;

    public const string PATH = '/oauth2callback';

    /** Enough for a browser's GET and its headers; anything longer is not this request. */
    private const int MAX_REQUEST = 16384;

    /** @var resource|null */
    private mixed $socket = null;

    private ?string $watcher = null;

    private ?Deferred $answer = null;

    /** @var array<string, string> what each open connection has sent so far */
    private array $partial = [];

    /** @var array<string, resource> */
    private array $connections = [];

    /** A counter, because an object id can be reused the moment the object is collected. */
    private int $next = 0;

    public function __construct(
        private readonly int $port = self::PORT,
        private readonly string $path = self::PATH,
    ) {
    }

    /** What Google has to be told to come back to. */
    public function redirectUri(): string
    {
        return "http://localhost:{$this->port}{$this->path}";
    }

    /**
     * Take the port, or say who has it.
     *
     * `stream_socket_server()` both warns and returns false, so the warning is caught rather
     * than suppressed — it is the only place the reason appears, and `@` is not allowed here.
     */
    public function listen(): void
    {
        $problem = null;

        set_error_handler(static function (int $number, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });

        try {
            $socket = stream_socket_server("tcp://127.0.0.1:{$this->port}", $errno, $errstr);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            $reason = $errstr !== '' ? $errstr : ($problem ?? 'unknown error');

            throw new OauthError(
                "Could not listen on 127.0.0.1:{$this->port} for the sign-in to come back to: {$reason}. "
                . 'Google will only redirect to that exact port, so whatever is holding it has to stop first.',
            );
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    /**
     * Wait for the browser, and hand back what it brought.
     *
     * Null means the waiting was called off. An `error=` in the callback — the person pressed
     * *Cancel* on Google's consent screen — is a refusal rather than a cancellation and comes
     * back as a throw, because something was said and it was "no".
     *
     * @return array{code: string, state: string}|null
     */
    public function await(?AbortSignal $signal = null): ?array
    {
        if ($this->socket === null) {
            throw new OauthError('The callback server was never started.');
        }

        if ($signal?->aborted() === true) {
            return null;
        }

        $this->answer = new Deferred();
        $answer = $this->answer;

        $this->watcher = Loop::get()->onReadable($this->socket, $this->accept(...));

        $listener = $signal?->onAbort(static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(null);
            }
        });

        try {
            $result = $answer->future->await();
        } finally {
            if ($signal !== null && $listener !== null) {
                $signal->removeListener($listener);
            }
        }

        return is_array($result) ? $result : null;
    }

    /** Give the port back. Safe to call twice, which is what a `finally` needs. */
    public function close(): void
    {
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

        // A browser opens more than one connection — the callback, and often a favicon request
        // behind it — so each is read on its own and only the one asking for the callback path
        // answers the question.
        $this->connections[$id] = $connection;
        $this->partial[$id] = '';

        $reader = null;
        $reader = Loop::get()->onReadable($connection, function (mixed $peer) use ($id, &$reader): void {
            $chunk = fread($peer, 8192);

            if ($chunk === false || $chunk === '') {
                return;
            }

            $this->partial[$id] = ($this->partial[$id] ?? '') . $chunk;

            if (strlen($this->partial[$id]) > self::MAX_REQUEST) {
                Loop::get()->cancel((string) $reader);
                $this->drop($id);

                return;
            }

            // The head is all of it: this is a GET, so there is no body to wait for.
            if (!str_contains($this->partial[$id], "\r\n\r\n")) {
                return;
            }

            Loop::get()->cancel((string) $reader);
            $this->handle($id, $this->partial[$id]);
        });
    }

    private function handle(string $id, string $request): void
    {
        $target = self::targetOf($request);

        if ($target === null || self::pathOf($target) !== $this->path) {
            $this->reply($id, '404 Not Found', '<h1>Nothing here</h1>');

            return;
        }

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $error = is_string($query['error'] ?? null) ? $query['error'] : null;
        $code = is_string($query['code'] ?? null) ? $query['code'] : null;
        $state = is_string($query['state'] ?? null) ? $query['state'] : null;

        if ($error !== null) {
            $this->reply($id, '400 Bad Request', '<h1>Signing in failed</h1><p>You can close this window.</p>');
            $this->fail(new OauthError("Google refused the sign-in: {$error}"));

            return;
        }

        if ($code === null || $state === null) {
            $this->reply($id, '400 Bad Request', '<h1>Signing in failed</h1><p>The callback was missing something.</p>');
            $this->fail(new OauthError('The sign-in came back without a code and a state in it.'));

            return;
        }

        // Said before the terminal is looked at again, because the browser is where the person
        // is looking right now.
        $this->reply($id, '200 OK', '<h1>Signed in</h1><p>You can close this window and go back to the terminal.</p>');

        if ($this->answer !== null && !$this->answer->isComplete()) {
            $this->answer->complete(['code' => $code, 'state' => $state]);
        }
    }

    private function fail(OauthError $problem): void
    {
        if ($this->answer !== null && !$this->answer->isComplete()) {
            $this->answer->error($problem);
        }
    }

    private function reply(string $id, string $status, string $body): void
    {
        $connection = $this->connections[$id] ?? null;

        if (!is_resource($connection)) {
            return;
        }

        $page = '<!doctype html><meta charset="utf-8"><title>pig</title>'
            . '<body style="font:16px system-ui;padding:3em">' . $body . '</body>';

        $head = "HTTP/1.1 {$status}\r\n"
            . "content-type: text/html; charset=utf-8\r\n"
            . 'content-length: ' . strlen($page) . "\r\n"
            . "connection: close\r\n\r\n";

        // Looped, for the same reason `ProcessTerminal::write()` is: a short write is the
        // quietest failure there is. This page is small enough that one pass will do it, and
        // relying on that is how the next slightly larger page breaks.
        $out = $head . $page;

        while ($out !== '') {
            $written = fwrite($connection, $out);

            if ($written === false || $written === 0) {
                break;
            }

            $out = substr($out, $written);
        }

        $this->drop($id);
    }

    private function drop(string $id): void
    {
        $connection = $this->connections[$id] ?? null;
        unset($this->connections[$id], $this->partial[$id]);

        if (is_resource($connection)) {
            fclose($connection);
        }
    }

    /** `GET /oauth2callback?code=… HTTP/1.1` → the middle of it. */
    private static function targetOf(string $request): ?string
    {
        $line = strstr($request, "\r\n", true);

        if ($line === false) {
            return null;
        }

        $parts = explode(' ', $line);

        return count($parts) >= 2 && $parts[0] === 'GET' ? $parts[1] : null;
    }

    private static function pathOf(string $target): string
    {
        $path = parse_url($target, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
