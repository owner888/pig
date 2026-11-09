<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Session\SessionStore;

/**
 * A `SessionStore` that keeps everything in arrays: the second store the conformance suite runs
 * against, so the suite is shown to describe the interface and not `JsonlSessionStore`, and the
 * store `SessionManagerOverAStoreTest` runs `SessionManager` on to show nothing above the seam
 * reaches for a file.
 *
 * A location is `memory://<cwd>/<name>`, the name ending in the session id as pi's file names do.
 */
final class MemorySessionStore implements SessionStore
{
    /** @var array<string, list<string>> */
    private array $sessions = [];

    /** @var array<string, int> */
    private array $modified = [];

    /** @var array<string, string> by `<location>#<name>` */
    private array $sidecars = [];

    private int $clock = 1_700_000_000_000;

    #[\Override]
    public function locationFor(string $cwd, string $name): string
    {
        return 'memory://' . $cwd . '/' . $name;
    }

    #[\Override]
    public function append(string $location, string $lines): void
    {
        foreach (explode("\n", $lines) as $line) {
            if ($line !== '') {
                $this->sessions[$location][] = $line;
            }
        }

        $this->sessions[$location] ??= [];
        $this->modified[$location] = ++$this->clock;
    }

    #[\Override]
    public function create(string $location, string $lines): void
    {
        if (isset($this->sessions[$location])) {
            throw new AgentError("Could not write the forked session to {$location}");
        }

        $this->append($location, $lines);
    }

    #[\Override]
    public function replace(string $location, array $lines): void
    {
        if (isset($this->sessions[$location])) {
            $this->sessions[$location] = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
            $this->modified[$location] = ++$this->clock;
        }
    }

    #[\Override]
    public function exists(string $location): bool
    {
        return isset($this->sessions[$location]);
    }

    #[\Override]
    public function lines(string $location): ?iterable
    {
        return $this->sessions[$location] ?? null;
    }

    #[\Override]
    public function linesFromEnd(string $location): iterable
    {
        return array_reverse($this->sessions[$location] ?? []);
    }

    #[\Override]
    public function firstLine(string $location): ?string
    {
        return $this->sessions[$location][0] ?? null;
    }

    #[\Override]
    public function modifiedAt(string $location): ?int
    {
        return $this->modified[$location] ?? null;
    }

    #[\Override]
    public function size(string $location): ?int
    {
        return isset($this->sessions[$location]) ? strlen(implode("\n", $this->sessions[$location])) + 1 : null;
    }

    #[\Override]
    public function candidates(string $cwd): array
    {
        $prefix = 'memory://' . $cwd . '/';

        return [array_values(array_filter(
            array_keys($this->sessions),
            static fn (string $location): bool => str_starts_with($location, $prefix) && !str_contains(substr($location, strlen($prefix)), '/'),
        )), false];
    }

    #[\Override]
    public function every(): array
    {
        return array_keys($this->sessions);
    }

    #[\Override]
    public function findLocal(string $cwd, string $arg): ?string
    {
        if (isset($this->sessions[$arg])) {
            return $arg;
        }

        return self::byId($this->candidates($cwd)[0], $arg);
    }

    #[\Override]
    public function findGlobal(string $arg): ?string
    {
        return self::byId($this->every(), $arg);
    }

    /** @param list<string> $locations */
    private static function byId(array $locations, string $arg): ?string
    {
        foreach ([true, false] as $exact) {
            foreach ($locations as $location) {
                $id = substr($location, (int) strrpos($location, '_') + 1);

                if ($exact ? $id === $arg : str_starts_with($id, $arg)) {
                    return $location;
                }
            }
        }

        return null;
    }

    #[\Override]
    public function projects(): array
    {
        $counts = [];

        foreach ($this->sessions as $lines) {
            $header = json_decode($lines[0] ?? '', true);
            $cwd = is_array($header) && is_string($header['cwd'] ?? null) ? $header['cwd'] : null;

            if ($cwd !== null && $cwd !== '') {
                $counts[$cwd] = ($counts[$cwd] ?? 0) + 1;
            }
        }

        $projects = [];

        foreach ($counts as $cwd => $sessions) {
            $projects[] = ['cwd' => (string) $cwd, 'sessions' => $sessions];
        }

        return $projects;
    }

    #[\Override]
    public function readSidecar(string $location, string $name): ?string
    {
        return $this->sidecars[$location . '#' . $name] ?? null;
    }

    #[\Override]
    public function writeSidecar(string $location, string $name, string $contents): void
    {
        $this->sidecars[$location . '#' . $name] = $contents;
    }

    #[\Override]
    public function deleteSidecar(string $location, string $name): void
    {
        unset($this->sidecars[$location . '#' . $name]);
    }

    #[\Override]
    public function remove(string $location): ?string
    {
        unset($this->sessions[$location], $this->modified[$location]);

        foreach (array_keys($this->sidecars) as $key) {
            if (str_starts_with($key, $location . '#')) {
                unset($this->sidecars[$key]);
            }
        }

        return null;
    }

    #[\Override]
    public function canonical(string $location): ?string
    {
        return isset($this->sessions[$location]) ? $location : null;
    }
}
