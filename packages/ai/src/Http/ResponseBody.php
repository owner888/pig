<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Generator;
use IteratorAggregate;

/**
 * A response body, delivered as it arrives: iterate it for the chunks, or read it whole.
 *
 * HTTP/1.1 frames it on a socket of its own (`Http1Body`), HTTP/2 as one stream of a shared
 * connection (`Http2\Http2Body`); whoever has a response does not know which.
 *
 * @implements IteratorAggregate<int, string>
 */
abstract class ResponseBody implements IteratorAggregate
{
    /** @return Generator<int, string> */
    abstract public function getIterator(): Generator;

    /** Stop reading and hang up — used when a stream is abandoned part way. */
    abstract public function close(): void;

    /** Read the whole body into a string. For error responses and non-streaming calls. */
    public function all(): string
    {
        $body = '';

        foreach ($this as $chunk) {
            $body .= $chunk;
        }

        return $body;
    }
}
