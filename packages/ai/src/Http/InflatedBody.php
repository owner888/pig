<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Generator;

/**
 * A gzip-compressed response body, given as it was before the server compressed it.
 *
 * Nothing here asks a server to compress: a request goes out with `accept-encoding: identity`
 * unless the caller sets the header itself, and only then can a response come back gzipped. A
 * caller that asks reads what it would have read without asking, chunk by chunk as the
 * compressed ones arrive, so a body that is streamed still is.
 */
final class InflatedBody extends ResponseBody
{
    public function __construct(private readonly ResponseBody $inner)
    {
    }

    /** @return Generator<int, string> */
    #[\Override]
    public function getIterator(): Generator
    {
        if (!function_exists('inflate_init')) {
            throw new HttpError('The response is gzip-compressed and this PHP has no zlib extension to undo it');
        }

        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new HttpError('Could not start decompressing the response body');
        }

        $any = false;

        foreach ($this->inner as $chunk) {
            $any = true;
            $plain = self::inflate($context, $chunk);

            if ($plain !== '') {
                yield $plain;
            }
        }

        // A body of no bytes is no gzip stream at all (a HEAD's, a 204's), and nothing was cut short.
        if ($any && inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new HttpError('Connection closed in the middle of a gzip-compressed body');
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->inner->close();
    }

    /** `inflate_add()`, its warning for bytes that are not gzip kept as the error's words. */
    private static function inflate(\InflateContext $context, string $chunk): string
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $plain = inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
        } finally {
            restore_error_handler();
        }

        if ($plain === false) {
            throw new HttpError('The response body is not the gzip it says it is: ' . ($warning ?? 'inflate_add() failed'));
        }

        return $plain;
    }
}
