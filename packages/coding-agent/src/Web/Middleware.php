<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;

/**
 * Something every HTTP request to `pig web` passes through before it is answered — the WebSocket
 * handshakes included, so the page's conversations and terminals are behind it too.
 *
 * The shape of smart-book's (OmniPHP) `Middleware::handle(Context, $next)`, for this server: a
 * middleware either answers the request itself, on `$conn`, and stops, or calls `$next()` to let
 * the next one — and at the end `HttpServer`'s routes — have it.
 */
interface Middleware
{
    /**
     * @param array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string} $req
     * @param Closure(): void $next
     */
    public function handle(array $req, Connection $conn, Closure $next): void;
}
