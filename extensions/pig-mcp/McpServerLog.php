<?php

declare(strict_types=1);

namespace PigMcp;

/**
 * Log messages MCP servers send with `notifications/message`, appended to `mcp.log` in the agent
 * directory — upstream's `extensions/mcp/log.js`. Several pigs may write to the same file, so
 * every message is one append. The file is rotated to `mcp.log.1` once it grows past
 * `MAX_LOG_BYTES`.
 *
 * Write errors are ignored: logging must not break tools. That is the one place in the extension
 * that swallows, and it is this method's whole job.
 */
final class McpServerLog
{
    public const int MAX_LOG_BYTES = 5 * 1024 * 1024;

    private ?int $size = null;

    public function __construct(public readonly string $path)
    {
    }

    /** Format one `notifications/message` from `$server` as a log line; continuation lines are indented. */
    public static function format(string $server, mixed $params, ?float $now = null): string
    {
        $message = is_array($params) && !array_is_list($params) ? $params : ['data' => $params];
        $level = is_string($message['level'] ?? null) ? $message['level'] : 'info';
        $logger = is_string($message['logger'] ?? null) && $message['logger'] !== '' ? " {$message['logger']}:" : '';
        $data = $message['data'] ?? null;
        $text = is_string($data) ? $data : (json_encode($data, JSON_UNESCAPED_SLASHES) ?: (string) var_export($data, true));
        $text = (string) preg_replace('/\r?\n/', "\n    ", $text);
        $time = $now ?? microtime(true);
        $stamp = gmdate('Y-m-d\TH:i:s', (int) $time) . sprintf('.%03dZ', (int) (($time - floor($time)) * 1000));

        return "{$stamp} [{$server}] {$level}{$logger} {$text}\n";
    }

    public function write(string $server, mixed $params): void
    {
        $line = self::format($server, $params);

        set_error_handler(static fn (): bool => true);

        try {
            if ($this->size === null) {
                $directory = dirname($this->path);

                if (!is_dir($directory)) {
                    mkdir($directory, 0o700, true);
                }

                $this->size = $this->currentSize();
            }

            if ($this->size > self::MAX_LOG_BYTES) {
                // Another process may have rotated it already; check before renaming.
                if ($this->currentSize() > self::MAX_LOG_BYTES) {
                    rename($this->path, $this->path . '.1');
                }

                $this->size = $this->currentSize();
            }

            if (file_put_contents($this->path, $line, FILE_APPEND) !== false) {
                $this->size += strlen($line);
            }
        } catch (\Throwable) {
            // The log is best effort.
        } finally {
            restore_error_handler();
        }
    }

    private function currentSize(): int
    {
        clearstatcache(true, $this->path);
        $size = is_file($this->path) ? filesize($this->path) : false;

        return $size === false ? 0 : $size;
    }
}
