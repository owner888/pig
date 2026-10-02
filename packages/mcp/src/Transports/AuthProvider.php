<?php

declare(strict_types=1);

namespace Pig\Mcp\Transports;

use Pig\Ai\Http\Response;

/**
 * Where the HTTP transport gets its bearer token, and whom it tells when the server says no.
 *
 * `token()` is asked before every request; `onUnauthorized()` is given a `401` (or a `403` that
 * asks for more scope) once, after which the request is retried with whatever `token()` answers
 * then. The OAuth half of `Pig\Mcp` implements this; a static header needs no provider at all.
 */
interface AuthProvider
{
    public function token(): ?string;

    public function onUnauthorized(Response $response, string $serverUrl, ?string $token): void;
}
