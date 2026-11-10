<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Middlewares;

use Closure;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Middleware;
use Pig\CodingAgent\Web\WebLogin;

/**
 * With accounts, what is the server's rather than anybody's own is the admin's
 * (docs/dev/design/storage.md, phase 4): the accounts themselves, the API keys everybody's
 * requests are billed to, and what the server's extensions put in the page — their panels (the
 * Antigravity sign-ins) and their own endpoints (`ExtensionApi::registerHttpRoute()`), which run
 * in the server, as its user, with its credentials. Anybody else gets a 403. With sessions in
 * files there is one account, the admin, and everything is let through. The SSH nodes and a
 * panel's actions are WebSocket requests, checked where `HttpServer` answers them.
 */
final class AdminMiddleware implements Middleware
{
    /** What only an admin may ask for, by path prefix. */
    public const array ADMIN = ['/api/accounts', '/api/keys', '/api/panels'];

    public function __construct(private readonly WebLogin $login)
    {
    }

    #[\Override]
    public function handle(array $req, Connection $conn, Closure $next): void
    {
        if (!self::isAdminPath($req['path']) || !$this->login->several() || ($this->login->user($req['headers'])?->admin ?? false)) {
            $next();

            return;
        }

        $conn->sendResponse(403, ['Content-Type' => 'application/json'], (string) json_encode([
            'ok' => false,
            'error' => 'Only an admin may do that.',
        ]));
    }

    public static function isAdminPath(string $path): bool
    {
        foreach (self::ADMIN as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return str_starts_with($path, '/api/') && \Pig\CodingAgent\Extensions\ExtensionApi::httpRouteFor($path) !== null;
    }
}
