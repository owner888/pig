<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Middlewares;

use Closure;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Middleware;
use Pig\CodingAgent\Web\WebLogin;

/**
 * Nothing of `pig web` without logging in (`WebLogin`) but the login page and what it needs. A
 * page asked for without a session is sent to `/login`; anything else — an API call, a WebSocket
 * handshake — is a 401, as smart-book's `AuthMiddleware` answers.
 */
final class LoginMiddleware implements Middleware
{
    /** What the login page needs before there is a session. */
    public const array PUBLIC = [
        '/login',
        '/api/login',
        '/api/login/state',
        '/api/login/setup',
        '/favicon.ico',
        '/favicon.svg',
        '/theme.css',
        '/assets/css/style.css',
        '/assets/js/login.js',
    ];

    public function __construct(private readonly WebLogin $login)
    {
    }

    #[\Override]
    public function handle(array $req, Connection $conn, Closure $next): void
    {
        if (in_array($req['path'], self::PUBLIC, true) || $this->login->isLoggedIn($req['headers'])) {
            $next();

            return;
        }

        $page = $req['method'] === 'GET'
            && ($req['headers']['upgrade'] ?? '') === ''
            && !str_starts_with($req['path'], '/api/')
            && !str_starts_with($req['path'], '/assets/');

        if ($page) {
            $conn->sendResponse(302, ['Location' => '/login', 'Cache-Control' => 'no-store'], '');

            return;
        }

        $conn->sendResponse(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'ok' => false,
            'error' => 'Not logged in.',
            'login' => '/login',
        ]));
    }
}
