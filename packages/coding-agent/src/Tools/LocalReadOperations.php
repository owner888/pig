<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;
use Pig\Async\AbortSignal;
use Pig\Tui\Images\ImageType;

/** `ReadOperations` on this machine's file system — what `read` did itself before it had operations. */
final readonly class LocalReadOperations implements ReadOperations
{
    /** @param int $chunkBytes how much one read of the stream takes in (`ReadTool::CHUNK_BYTES`) */
    public function __construct(private int $chunkBytes = ReadTool::CHUNK_BYTES)
    {
    }

    #[\Override]
    public function readFile(string $absolutePath): string
    {
        [$bytes, $reason] = FileAccessError::capture(static fn (): string|false => file_get_contents($absolutePath));

        if ($bytes === false) {
            throw new FileAccessError(FileAccessError::FAILED, "Could not read {$absolutePath}", $reason);
        }

        return $bytes;
    }

    #[\Override]
    public function access(string $absolutePath): void
    {
        if (!is_file($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_FOUND, "No such file: {$absolutePath}");
        }

        if (!is_readable($absolutePath)) {
            throw new FileAccessError(FileAccessError::NOT_ALLOWED, "Not readable: {$absolutePath}");
        }
    }

    #[\Override]
    public function detectImageMimeType(string $absolutePath): ?string
    {
        return ImageType::ofFile($absolutePath);
    }

    /**
     * A regular file is read up to the size it had when opened, as `fs.readFile` reads it: text
     * appended meanwhile is not counted, and a file that keeps growing still ends. One that says
     * it is empty (`/proc`) is read to EOF.
     */
    #[\Override]
    public function createReadStream(string $absolutePath, ?AbortSignal $signal = null): iterable
    {
        // The warning caught rather than printed, as `SessionManager::appendLines()` does: its text
        // is the reason, and it belongs in the error rather than on the terminal.
        $reason = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$reason): bool {
            $reason = $errstr;

            return true;
        });

        try {
            $handle = fopen($absolutePath, 'rb');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            throw new AgentError("Could not read {$absolutePath}" . ($reason !== null ? ": {$reason}" : ''));
        }

        try {
            $stat = fstat($handle);
            $size = $stat !== false && ($stat['mode'] & 0o170000) === 0o100000 && $stat['size'] > 0 ? (int) $stat['size'] : null;
            $read = 0;

            // `$signal` is the tool's to look at: `ReadTool::scan()` checks it every few megabytes and
            // throws, which a stream that only stopped would turn into a short read.
            while ($size === null || $read < $size) {
                $data = fread($handle, $size === null ? $this->chunkBytes : min($this->chunkBytes, $size - $read));

                if ($data === false || $data === '') {
                    return;
                }

                $read += strlen($data);

                yield $data;
            }
        } finally {
            fclose($handle);
        }
    }
}
