<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Middlewares;

use Closure;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Middleware;

/**
 * Only the page itself talks to `pig web`. A browser names the page a request came from in
 * `Origin` — on every cross-site fetch, form post and WebSocket handshake — and a request from
 * another site's page (one that knows the server is on 127.0.0.1:8088) is refused here, before it
 * can reach a terminal or an agent. A request with no `Origin` is no browser's cross-site one
 * (a navigation, `curl`), and goes on to the login.
 *
 * `Sec-Fetch-Site` decides first, when the browser sent one. It is the browser's own verdict, which
 * no page can set, and it is right where `Origin` can be wrong: an extension that rewrites
 * `Origin` — seen in the wild as `http://127.0.0.1` with the port gone, on a stylesheet that
 * carries no `Origin` at all without it — turned the login page's own `theme.css` into a 403.
 * `same-origin` and `none` (a navigation somebody typed) go on; `same-site` and `cross-site` are
 * refused, and `same-site` is the one that matters here, because another port on 127.0.0.1 is the
 * same site and not the same origin. Without the header — an older browser, `curl` — `Origin` is
 * compared with `Host` as before.
 */
final class SameOriginMiddleware implements Middleware
{
    #[\Override]
    public function handle(array $req, Connection $conn, Closure $next): void
    {
        if (!self::allowed($req['headers'], $req['method'] ?? 'GET')) {
            $conn->sendResponse(403, ['Content-Type' => 'application/json'], (string) json_encode([
                'ok' => false,
                'error' => 'Requests from another site are refused.',
            ]));

            return;
        }

        $next();
    }

    /**
     * Whether a request with these headers is the page's own.
     *
     * @param array<string, string> $headers lower-cased names
     */
    public static function allowed(array $headers, string $method = 'GET'): bool
    {
        $site = strtolower(trim($headers['sec-fetch-site'] ?? ''));
        if ($site !== '') {
            if ($site === 'same-origin' || $site === 'none') {
                return true;
            }

            // Top-level browser document navigation (e.g. clicking a link in Termux, Telegram, or
            // launching the browser via Android Intent) is safe for GET/HEAD requests under W3C Fetch
            // Metadata specification.
            $mode = strtolower(trim($headers['sec-fetch-mode'] ?? ''));
            if ($mode === 'navigate' && ($method === 'GET' || $method === 'HEAD')) {
                return true;
            }

            return false;
        }

        $origin = $headers['origin'] ?? null;

        return $origin === null || self::sameHost($origin, $headers['host'] ?? '');
    }

    /**
     * Whether `Origin` (`scheme://host[:port]`) names the host the request was sent to — and its
     * port, when `Host` has one. A `Host` without a port is a default one, which a TLS proxy in
     * front of the server leaves for an `https://` page.
     */
    public static function sameHost(string $origin, string $host): bool
    {
        $from = parse_url($origin);
        $to = parse_url('http://' . $host);
        if (!is_array($from) || !isset($from['host']) || !is_array($to) || !isset($to['host'])) {
            return false;
        }

        if (strtolower(trim($from['host'], '[]')) !== strtolower(trim($to['host'], '[]'))) {
            return false;
        }

        $port = $from['port'] ?? (($from['scheme'] ?? '') === 'https' ? 443 : 80);

        return !isset($to['port']) || $to['port'] === $port;
    }
}
