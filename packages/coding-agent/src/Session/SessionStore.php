<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentError;

/**
 * Where sessions are kept: the seam `SessionManager` reads and writes through, so the lines of a
 * conversation can live somewhere other than a file ([storage.md](../../../../docs/dev/design/storage.md)).
 *
 * **pig's own, not upstream's.** pi's `SessionManager` writes its JSONL file itself and has no such
 * interface; this is the deviation `docs/dev/upstream.md` records. What it stores is pi's: **the lines
 * of pi's session file**, header first, one JSON object each, in the order they were written. The
 * tree, compaction, the migrations, listings and the RPC wire are all above it, in `SessionManager`,
 * and read the same whatever is below.
 *
 * A session is named by a **location**, an opaque string the store hands out (`locationFor()`) and
 * takes back. For `JsonlSessionStore` it is the file's path, which is what every caller has always
 * held as `SessionManager::$path`, a web pool key and an RPC `sessionFile`; another store makes its
 * own, and nothing above may take it apart.
 *
 * A **sidecar** is a small document kept beside a session and deleted with it — `ToolProgress`'
 * running output today.
 */
interface SessionStore
{
    /** Where a new session of `$cwd` called `$name` (pi's `<timestamp>_<id>`) is kept. */
    public function locationFor(string $cwd, string $name): string;

    /**
     * Add `$lines` (each ending in a newline) after what the session holds, creating it when it
     * is not there yet. All of them or none: a reader never sees half of one append.
     *
     * @throws AgentError when they could not be kept
     */
    public function append(string $location, string $lines): void;

    /**
     * Start a session with `$lines`, refusing one that is already there — a fork never lands on
     * an existing session (upstream's `{ flag: "wx" }`).
     *
     * @throws AgentError when it exists or could not be written
     */
    public function create(string $location, string $lines): void;

    /**
     * Replace everything the session holds with `$lines` (without newlines) — a migrated file.
     * Best effort: a session this store may not change is left as it was, and the caller carries
     * on with what it has in memory.
     *
     * @param list<string> $lines
     */
    public function replace(string $location, array $lines): void;

    public function exists(string $location): bool;

    /**
     * The session's lines in order, header first, without their newlines and without empty ones;
     * null when there is nothing readable there. A generator, so a caller that needs only the top
     * stops reading there.
     *
     * @return iterable<int, string>|null
     */
    public function lines(string $location): ?iterable;

    /**
     * The same lines, last first — for what is near the end (the name a session was last given)
     * without reading from the top.
     *
     * @return iterable<int, string>
     */
    public function linesFromEnd(string $location): iterable;

    /** The first line (the header), or null when there is nothing readable there. */
    public function firstLine(string $location): ?string;

    /** When the session was last written to, in Unix milliseconds; null when it is not there. */
    public function modifiedAt(string $location): ?int;

    /** How many bytes the session takes; null when it is not there. */
    public function size(string $location): ?int;

    /**
     * The sessions to look through for `$cwd`, in no particular order, and whether each must be
     * checked for naming `$cwd` in its header (a store that keeps several projects together).
     *
     * @return array{0: list<string>, 1: bool}
     */
    public function candidates(string $cwd): array;

    /**
     * Every session, in no particular order.
     *
     * @return list<string>
     */
    public function every(): array;

    /**
     * `--session <arg>` resolved in `$cwd`'s own sessions: a location as typed, an exact id, then
     * an id prefix. Null when none matches here.
     */
    public function findLocal(string $cwd, string $arg): ?string;

    /** The same across every project: an exact id, then an id prefix. */
    public function findGlobal(string $arg): ?string;

    /**
     * The projects that have sessions, each as the `cwd` its first session names and how many
     * sessions it has. One project can appear more than once (pig's and pi's directories); the
     * caller adds them up.
     *
     * @return list<array{cwd: string, sessions: int}>
     */
    public function projects(): array;

    /** A sidecar's contents, or null when there is none. */
    public function readSidecar(string $location, string $name): ?string;

    /**
     * Keep `$contents` as the sidecar, readable by the user alone and replaced whole, so a crash
     * leaves the last complete copy.
     *
     * @throws AgentError when it could not be written
     */
    public function writeSidecar(string $location, string $name, string $contents): void;

    /** @throws AgentError when one is there and could not be removed */
    public function deleteSidecar(string $location, string $name): void;
}
