<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use RuntimeException;

/**
 * What a tool's `*Operations` throws when a file could not be had, and why — so the tool can say it
 * in its own words, with the path as the model wrote it, as it did before the operations existed.
 *
 * pig's own. Upstream's operations throw whatever their file system throws, and its tools mostly
 * pass that on (`ENOENT: … access '/tmp/x/…'`). An implementation that cannot tell the reasons
 * apart throws `FAILED`, or anything else, which the tool passes on as it is. Not an `AgentError`
 * (that class is final): the tool turns it into one, in its own words.
 */
final class FileAccessError extends RuntimeException
{
    /** Nothing is there. */
    public const string NOT_FOUND = 'not_found';

    /** Something is there and may not be read or written as asked. */
    public const string NOT_ALLOWED = 'not_allowed';

    /** A directory where a file was meant. */
    public const string IS_DIRECTORY = 'is_directory';

    /** Anything else: the operation itself failed. */
    public const string FAILED = 'failed';

    /**
     * @param string|null $reason why, in the file system's words without its paths — `No space left
     *        on device` — which the tool puts after its own sentence, so a full disk is told from a
     *        read-only one; null when there is nothing to add
     */
    public function __construct(public readonly string $kind, string $message, public readonly ?string $reason = null)
    {
        parent::__construct($reason === null ? $message : "{$message}: {$reason}");
    }

    /** `$sentence`, the tool's own words, with the reason after it when there is one. */
    public function explain(string $sentence): string
    {
        return $this->reason === null ? $sentence : "{$sentence}: {$this->reason}";
    }

    /**
     * Run `$operation` and keep the warning PHP raises when it fails, rather than printing it: its
     * text is the reason. `file_put_contents(/abs/path): Failed to open stream: Permission denied`
     * comes back as `Failed to open stream: Permission denied` — the path is the tool's to name.
     *
     * @template T
     * @param Closure(): T $operation
     * @return array{0: T, 1: string|null} what it returned, and the last warning's reason
     */
    public static function capture(Closure $operation): array
    {
        $reason = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$reason): bool {
            $reason = preg_match('/^\w+\(.*?\): (.+)$/s', $errstr, $match) === 1 ? $match[1] : $errstr;

            return true;
        });

        try {
            return [$operation(), $reason];
        } finally {
            restore_error_handler();
        }
    }
}
