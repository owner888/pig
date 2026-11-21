<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Pig\Async\AbortSignal;

/**
 * Something a request is sent through — `HttpClient`, or a decorator around one.
 *
 * Upstream hands a `fetch` function around, which anything can wrap; this is pig's seam for the
 * same thing. Whatever only sends takes a transport, so an extension can count, limit or log every
 * request it makes in one place by wrapping its client, instead of at each call.
 */
interface HttpTransport
{
    /** Send the request and return once status and headers are in. */
    public function send(Request $request, ?AbortSignal $signal = null): Response;
}
