<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Export;

use RuntimeException;

/**
 * The shares this machine put on pigagent.dev, in `shares.json` in the agent directory, so
 * `/unshare` can take one down before it expires: each one's id, its link, the token that deletes
 * it, when it expires and a title to tell it by.
 *
 * The link carries the share's key, and the token deletes it, so the file is kept `0600` like
 * `auth.json`. A share past its expiry is gone from the server already and is dropped from the file
 * the next time it is read.
 */
final class ShareRecords
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The shares not yet expired, newest first.
     *
     * @return list<array{id: string, url: string, deleteToken: string, expiresAt: int, title: string}>
     */
    public function active(?int $now = null): array
    {
        $now ??= time();

        return array_values(array_filter($this->read(), static fn (array $share): bool => $share['expiresAt'] > $now));
    }

    /** @param array{id: string, url: string, deleteToken: string, expiresAt: int, title: string} $share */
    public function add(array $share): void
    {
        $this->write([$share, ...$this->active()]);
    }

    public function remove(string $id): void
    {
        $this->write(array_values(array_filter($this->active(), static fn (array $share): bool => $share['id'] !== $id)));
    }

    /** @return list<array{id: string, url: string, deleteToken: string, expiresAt: int, title: string}> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($this->path), true);

        if (!is_array($json)) {
            throw new RuntimeException("{$this->path} is not JSON; fix or remove it.");
        }

        $shares = [];

        foreach ($json as $share) {
            if (is_array($share) && is_string($share['id'] ?? null) && is_string($share['url'] ?? null)
                && is_string($share['deleteToken'] ?? null) && is_int($share['expiresAt'] ?? null)) {
                $shares[] = [
                    'id' => $share['id'],
                    'url' => $share['url'],
                    'deleteToken' => $share['deleteToken'],
                    'expiresAt' => $share['expiresAt'],
                    'title' => is_string($share['title'] ?? null) ? $share['title'] : '',
                ];
            }
        }

        return $shares;
    }

    /** @param list<array{id: string, url: string, deleteToken: string, expiresAt: int, title: string}> $shares */
    private function write(array $shares): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        // 0600 before anything is in it, then swapped in whole: the file holds keys and tokens.
        $temporary = tempnam($directory, basename($this->path) . '.');

        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("Could not create a file in {$directory}.");
        }

        $json = json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || !chmod($temporary, 0o600) || file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $this->path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("Could not save {$this->path}.");
        }
    }
}
