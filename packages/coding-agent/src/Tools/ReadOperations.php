<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Async\AbortSignal;

/**
 * Upstream's `ReadOperations` (`tools/read.ts`): where `read` gets a file from, so a host can read
 * somewhere other than this machine — over SSH, in a container. `LocalReadOperations` is this
 * machine's file system, the default.
 *
 * Synchronous where upstream's return promises: pig's tools run in a fiber, and an implementation
 * that waits on the network suspends that fiber rather than returning a promise.
 */
interface ReadOperations
{
    /** The file's bytes. */
    public function readFile(string $absolutePath): string;

    /**
     * Upstream's "Check if file is readable (throw if not)".
     *
     * @throws FileAccessError `NOT_FOUND` when there is no file, `NOT_ALLOWED` when it may not be read
     */
    public function access(string $absolutePath): void;

    /** The image's MIME type, or null for a file that is not an image. */
    public function detectImageMimeType(string $absolutePath): ?string;

    /**
     * The file's bytes in chunks, so only the lines that will be shown are kept in memory. Stops
     * when `$signal` aborts.
     *
     * @return iterable<int, string>
     */
    public function createReadStream(string $absolutePath, ?AbortSignal $signal = null): iterable;
}
