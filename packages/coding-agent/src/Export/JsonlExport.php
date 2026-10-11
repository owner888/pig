<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use Closure;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Session\SessionManager;

/**
 * The branch being talked on as a session file — upstream's `exportSessionToJsonl()`
 * (`core/session-export.ts`), behind `/export <path>.jsonl`.
 *
 * What is written is `SessionManager::serializeBranch()`: a fresh header and the root-to-leaf
 * entries, so the file is one conversation without the branches left behind, and `/import` (or
 * `pig --session`) opens it as one.
 */
final class JsonlExport
{
    /**
     * Write it, and answer the absolute path it went to.
     *
     * @param string|null $path where to; relative to $cwd. Upstream's default when none is given:
     *        `session-<ISO timestamp with : and . as ->.jsonl` in $cwd
     * @param (Closure(?string, string): list<array<string, mixed>>)|null $trailing see `serializeBranch()`
     * @throws AgentError when the directory or the file cannot be written
     */
    public static function write(SessionManager $session, ?string $path, string $cwd, ?Closure $trailing = null): string
    {
        $path ??= 'session-' . str_replace([':', '.'], '-', gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (microtime(true) * 1000) % 1000)) . '.jsonl';
        $target = self::resolve($path, $cwd);
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new AgentError("Could not create {$directory}");
        }

        if (file_put_contents($target, $session->serializeBranch($trailing)) === false) {
            throw new AgentError("Could not write {$target}");
        }

        return $target;
    }

    /** Upstream's `resolvePath()`: `~` is the home directory, and a relative path is under $cwd. */
    public static function resolve(string $path, string $cwd): string
    {
        $home = (string) getenv('HOME');

        if ($home !== '' && ($path === '~' || str_starts_with($path, '~/'))) {
            return $home . substr($path, 1);
        }

        return str_starts_with($path, '/') ? $path : rtrim($cwd, '/') . '/' . $path;
    }
}
