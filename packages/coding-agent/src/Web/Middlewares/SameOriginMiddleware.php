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
 */
final class SameOriginMiddleware implements Middleware
{
    #[\Override]
    public function handle(array $req, Connection $conn, Closure $next): void
    {
        $origin = $req['headers']['origin'] ?? null;

        if ($origin !== null && !self::sameHost($origin, $req['headers']['host'] ?? '')) {
            $conn->sendResponse(403, ['Content-Type' => 'application/json'], (string) json_encode([
                'ok' => false,
                'error' => 'Requests from another site are refused.',
            ]));

            return;
        }

        $next();
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
