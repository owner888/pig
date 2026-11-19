<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

use Throwable;

/**
 * What one request has received so far, and how much more it may send. The connection writes it
 * as frames arrive; the request and its body read it.
 *
 * @internal
 */
final class Http2Stream
{
    public ?int $status = null;

    /** @var array<string, string> lowercased names, duplicates joined with ", " */
    public array $headers = [];

    /** @var list<string> DATA received and not yet handed on */
    public array $chunks = [];

    /** True once the server has sent END_STREAM. */
    public bool $ended = false;

    public ?Throwable $error = null;

    /** Bytes handed on and not yet given back to the server's send window. */
    public int $unacknowledged = 0;

    /** Bytes handed on in all. */
    public int $delivered = 0;

    /** The stream's window has been raised for a download (`Http2Connection::consumed()`). */
    public bool $grown = false;

    /** @param int $sendWindow how many bytes of body the server will take before a WINDOW_UPDATE */
    public function __construct(public readonly int $id, public int $sendWindow)
    {
    }
}
