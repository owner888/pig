# Design: storage and the execution environment behind interfaces

**Status: C decided 2026-11-09; phase 1 (the seam) built the same day.** The developer's answers are at the end, under
[Decisions](#decisions); the phases follow from them.

## What was asked

The developer's aim (October 2026): pig should be deployable on a server, with **several instances
and several users**, and its storage able to move to **MySQL**. pi's JSONL session file stays one
of the backends. For that, storage and the execution environment go behind interfaces, as
pi-durable has them. Porting pi-durable's task/checkpoint runtime was tied to this work, to be
done together with it if at all.

The RPC `requestId` dedupe that was to come first is done (0.6.70, [rpc.md](rpc.md)).

## What upstream has

Three different answers live in upstream's tree. Which one pig follows decides the rest of this
document.

**1. pi coding-agent (what pig ports): no storage interface.** `SessionManager` writes the JSONL
file itself (`appendFileSync`, `writeFileSync`). Its only other mode is `SessionManager.inMemory()`,
which persists nothing. There are two seams elsewhere:

- `SettingsStorage`, one method: `withLock(scope, fn(current) => next)`, with
  `FileSettingsStorage` as the file implementation.
- An `*Operations` interface on each built-in tool (`BashOperations`, `ReadOperations`,
  `WriteOperations`, `EditOperations`, `FindOperations`, `GrepOperations`, `LsOperations`), so a
  host can run the tools somewhere else (its SSH example). **pig dropped these**: `BashTool`'s
  docblock says a `spawnHook` that rewrites the command into `ssh host …` covers most uses.

**2. pi-durable (`packages/durable`, "Experimental. The API changes without notice"): a separate
harness, not a storage layer under coding-agent.**

- It has its own model: a `Session` that owns one commit line, plus conversations, entries,
  documents, tasks and submissions. A submission carries the `requestId` dedupe.
- `Storage` is the atomic persistence boundary under that model: `commit(batches)`, `mintId()`,
  `conversation()`, `scanEntries()`, `task()`, `submissionByRequest()`, `findDocument()` and so on.
- Its backends:
  - memory
  - JSONL — **its own records, not pi's session file**
  - IndexedDB
  - SQLite (Node and Cloudflare)
  - a store backend
- There is no MySQL backend.
- Size: about 34,000 lines of TypeScript (harness 11.4k, session 4.6k, storage 6.2k). It is built
  on `@earendil-works/chord` for document state.
- **Its user is `apps/p2`**, upstream's server app (Discord, sandboxes, session locks, and in the
  p3 documents users and accounts). So upstream's answer to "a server with users" is p2 on
  pi-durable, not the coding agent with a pluggable store.

**3. pi-env (`packages/env`): the execution environment for pi-durable.**

- `ExecutionEnv` is `FileSystem & Shell`: read, write, list, watch, exec.
- `LocalExecutionEnv` runs on this machine.
- `RemoteExecutionEnv` talks to a small Rust daemon that it deploys over SSH.
- About 2,600 lines of TypeScript, plus the daemon.

## What pig has today

**Sessions.** `Session\SessionManager` (2,569 lines) is pi's file, field for field
([session.md](session.md)). Its file I/O is in a few places:

- `appendLines()`: `file_put_contents(FILE_APPEND | LOCK_EX)`. Every entry goes through `write()`
  → `appendLines()`.
- Reading a session: `open()`, which also runs `upgrade()`. `upgrade()` rewrites a v1 file as
  upstream's `migrateV1ToV2()` does.
- New files: `create()`, `forkFrom()` and `fork()`.
- Directory scans:
  - `directory()`, `slug()`
  - `listFor()`, `listAll()`, `searchAll()`
  - `latestFor()`, `latestPathFor()`
  - `find()`, `locate()`, `findById()`
  - `glance()`, `info()`
  - The scans read `~/.pi/agent/sessions/` too.

Callers outside the class:

- `HttpServer` (6 calls, and one `glob('*.jsonl')` of its own at line ~2040)
- `InteractiveMode` (6)
- `AgentSession` (5)
- `CodingAgent` (3)
- `SessionPicker`, `SessionList`, `ScheduleManager`, `FoundSession`
- `HtmlExport`, `MarkdownExport`, `JsonlExport`, `BugReport`
- `Session\ToolProgress`: a `.running.json` sidecar next to the session file, used for resuming a
  run that stopped inside a tool call.

**Other state** in `~/.pig/agent`, each file read and written by its own class:

- `settings.json`, `models.json`, `models-store.json`, `mcp.json`, `trust.json`
- `scheduled-tasks.json` and its `.lock`
- `nodes.json` (and `nodes-secrets.json`)
- `web-login.json`, `web-local-token`
- `crashes.json`, `fff-frecency.json`
- directories: `artifacts/`, `bug-reports/`, `logs/`, `extensions/`, `skills/`, `prompts/`,
  `agents/`, `git/` (packages), `bin/`

**Execution.** The tools call PHP's file functions and `proc_open()` directly. The only seam is
`spawnHook` on the shell tools.

**Process model.** `pig web` is pi-web's shell over one `pig --mode rpc` child per
`cwd::sessionFile` ([web.md](web.md)), all on one machine.

- One process writes a session file at a time. The pool guarantees that, and `LOCK_EX` covers an
  append.
- The scheduled tasks belong to the one `pig web` that holds `scheduled-tasks.lock`.

## Three ways to do it

### A. Port pi-durable and run the server on it, as upstream's p2 does

- **For:**
  - It is upstream's own server design.
  - Its `Storage` interface was made for exactly this.
  - It brings submissions with `requestId`, tasks, checkpoints, and a model request that dies
    mid-turn being retried on reopen.
- **Against:**
  - About 34k lines, plus chord, which pig would have to port as well.
  - It is a **second conversation model beside pi's session file**. The TUI, RPC mode, the web
    shell, compaction, export and `/resume` are all built on `SessionManager` and `AgentSession`.
    So the server would be a second agent rather than pig with another store. This is the reason
    [web.md](web.md) gives for declining it for scheduled tasks: "a second store beside pi's
    session file for one feature".
  - Its JSONL is not pi's.
  - It has no MySQL backend; the SQLite schema is the nearest starting point.
  - It is experimental, so tracking it costs a port every time its API moves.

### B. A storage interface of pig's own under `SessionManager`

**The records are pi's session lines.** The interface stores and returns them. Everything above
it stays as it is: the tree, compaction, migrations, export and the RPC wire. A sketch:

```php
interface SessionStore
{
    public function create(SessionHeader $header, array $lines): SessionRef;   // a new session, its first lines
    public function append(SessionRef $session, array $lines): void;          // what appendLines() does now
    public function read(SessionRef $session): iterable;                      // every line, in order
    public function replace(SessionRef $session, array $lines): void;         // upgrade() of a v1 file
    public function list(SessionQuery $query, int $limit, ?string $cursor): SessionPage;
    public function glance(SessionRef $session): ?SessionInfo;                // the list row
    public function sidecar(SessionRef $session, string $name): Sidecar;      // ToolProgress' .running.json
}
```

- `JsonlSessionStore` is today's code moved, with no change in behaviour. It keeps reading
  `~/.pi/agent/sessions/` beside pig's own directory.
- `MysqlSessionStore` (through PDO) would have two tables:
  - `sessions`: id, owner, cwd, header JSON, name, created, updated
  - `session_lines`: session id, seq, the line as JSON
- Export to a `.jsonl` file stays exact, because the lines are pi's.

Assessment:

- **For:**
  - The change is confined to one seam, and upstream's code above it still reads the same.
  - pi's format stays the canonical one.
  - Each step can ship by itself.
- **Against:**
  - It is a deviation: upstream's coding-agent has no such interface, so it goes into
    [upstream.md](../upstream.md).
  - It brings no crash-resume beyond what `ToolProgress` already does, and no task graph.
  - Several instances still need a writer lease (see below), which upstream solves inside
    pi-durable's `Session`.

### C. B for storage, plus upstream's tool `*Operations` back for execution (recommended)

- Storage as in B.
- Execution: restore upstream's seven `*Operations` interfaces exactly as upstream has them, with
  today's code as the local implementation. This is a port toward upstream, not away from it, and
  it closes the deviation `BashTool`'s docblock records.
- A remote implementation (SSH, or pi-env's daemon) is a later choice that plugs into the same
  seam.
- pi-durable waits:
  - until upstream drops "experimental", or
  - until the developer wants its crash-resume or task graph badly enough to run a second model.

The reasoning: pig's value is that it *is* pi. Whatever pi does with a session, pig does to the
same file. A server that keeps pi's lines in MySQL stays that. A server on pi-durable is a
different program that happens to share the model layer.

## What several instances and users need beyond a store

These apply under B or C. They go in a later phase, but they shape the interface now.

- **One writer per session.**
  - Today one process per session file is implicit.
  - In a database, each session gets a lease row: owner instance and expiry, renewed while a turn
    runs.
  - An instance that does not hold the lease forwards the request to the instance that does, or
    takes over an expired lease.
  - The web pool already routes by session; it would route by lease.
- **Ids.** pi's entry ids (eight characters off a UUID) and session UUIDs need no central counter,
  so no change is needed.
- **Owner.** The `owner` column is filled from whoever is logged in (`web-login.json` today).
  Listing filters by it. Accounts and logins are a design of their own, above the store.
- **Scheduled tasks and the pool** are per machine (the lock file). With several instances they
  need a store too, a small table with the same lease idea.
- **What stays per instance:**
  - deployment, not user data: `extensions/`, `skills/`, `prompts/`, `bin/`, `git/` (packages),
    `logs/`
  - `settings.json` and `models.json`, unless they become per-user
  - for settings, upstream's `SettingsStorage::withLock()` is the seam to port if that day comes

## Phases, if C is chosen

1. **The seam, no behaviour change.**
   - Add `SessionStore` and `JsonlSessionStore`, moving the code out of `SessionManager`.
   - Every caller in the inventory above goes through it, including `HttpServer`'s own `glob()`,
     the exports, `BugReport` and `ToolProgress`.
   - Add a conformance test suite that every backend must pass, as pi-durable's
     `testing/` does for its storages. The existing tests stay green unchanged.
   **Built:** `Session\SessionStore` and `Session\JsonlSessionStore`. `SessionManager` has no file
   call left. It reads and writes through `sessionStore()` (`useSessionStore()` to choose
   another), and the same goes for:
   - `ToolProgress`' running output (a sidecar)
   - the web UI's folder list (`projects()`) and its rows' `modified`
   - `ScheduleManager`'s "is the conversation still there"
   - `Doctor`'s session size
   - `MarkdownExport`'s default name
   - `SessionList`'s removal of the sidecar

   The conformance suite is `test/Session/SessionStoreConformance.php`, run by
   `JsonlSessionStoreTest` and by `MemorySessionStoreTest` (a test store with no files, which
   keeps the suite honest about testing the interface). `SessionManagerOverAStoreTest` runs
   `SessionManager` on that store.

   Still about files on purpose:
   - `HtmlExport::fromFile()`, `/export x.jsonl` and `/import x.jsonl`: a file the person named. `/import`
     copies it into the sessions directory; with a SQL store it becomes phase 3's import.
   - `Migrations`: the layout of `~/.pig/agent` on disk.
   - `HttpServer::resolveSafeSessionPath()`: a path from the page is checked to be a `.jsonl`
     under the sessions directories.
   - `SessionList::deleteSessionFile()`: moves the file to the trash.

   The last two are what a SQL store needs next, as a location check and a `remove()`, in
   phase 3.
2. **Execution.** Port upstream's `*Operations` for the seven tools, local by default.

   **Built for the six file tools:** `ReadOperations`, `WriteOperations`, `EditOperations`,
   `LsOperations`, `FindOperations` and `GrepOperations`, each with a `Local*` implementation, given
   to a tool as its `operations` argument. The shape follows upstream, with two pig additions:
   - The methods are synchronous; an implementation that waits on the network suspends the
     tool's fiber.
   - `FileAccessError` (`NOT_FOUND`, `NOT_ALLOWED`, `IS_DIRECTORY`, `FAILED`) lets the tools keep
     their own error messages, with the path as the model wrote it.

   `find` given operations asks their `glob()` instead of running `fd`; `grep` asks only whether
   the path is a directory, and `rg` still runs here. Both are as upstream.
   `ToolOperationsTest` runs each tool on operations of its own, in a cwd that does not exist.

   **And for `bash` and `powershell`:** `Tools\Run` was split into its two halves.
   - The process half went to `LocalShellOperations` (with `LocalShellRun`), upstream's
     `createLocalShellOperations()`. It runs the process, a watcher per pipe, the poll for a shell
     whose background children keep the pipes open, and the kill on abort or timeout.
   - `Run` keeps the output half: the escape sequences held back across reads, the cleaning, the
     tail, the spill file and the live updates.

   `BashOperations::exec()` is upstream's, with the timeout in seconds. It throws `CommandStopped`
   for upstream's `"aborted"` / `"timeout:…"` rejections, and `$onData` is told which stream a
   chunk came from, so a sequence cut short is completed by its own stream.
3. **SQL backends.** `SqlSessionStore` on PDO with two dialects, MySQL (`pdo_mysql`) and SQLite
   (`pdo_sqlite`), behind a `storage` setting: `jsonl` (the default), a `mysql:` DSN or a `sqlite:`
   path. The conformance suite runs against JSONL and SQLite in CI, against MySQL where one is
   configured. An import command copies JSONL sessions in, pi's `~/.pi/agent/sessions/` included.
4. **Several users, one instance.** Owners on sessions, accounts and per-user login, and the
   scheduled tasks moved into the store with an owner.
5. **Several instances**, when a second one is actually wanted: session leases, and the pool and the
   scheduler routing by them.
6. **pi-durable**, reconsidered then.

## Decisions

Asked and answered 2026-11-09:

1. **C.** Storage behind pig's own `SessionStore`, execution through upstream's `*Operations`;
   pi-durable waits.
2. **MySQL through `pdo_mysql`, and SQLite (`pdo_sqlite`) as well**: SQLite is the SQL backend CI
   runs, without a database server. Both are PHP extensions needed only by whoever chooses that
   backend; the JSONL default needs neither, and no Composer package is added.
3. **Several users first, several instances later** (proposed, the developer to confirm):
   - (a) people logging in to one server, each with their own sessions, comes first, as phase 4;
   - (b) one person's sessions served by several instances is phase 5, built only when a second
     instance is actually wanted.

   The reasons:
   - A single server serving more than one person is the deployment the developer described.
   - High availability for one person is not something pig needs yet.
   - (a) needs nothing a second machine provides, and every piece of it is also needed by (b).

   The interface is shaped for both from phase 1, so neither needs a migration later: `owner` is
   a column from the first schema, and the store's write path takes a writer token that the
   JSONL and single-instance stores accept unchecked.
4. **pi's `~/.pi/agent/sessions/` is imported, not listed**, with a SQL store. The JSONL store
   keeps listing it as today: on a desktop, `/resume` showing pi's conversations stays.
