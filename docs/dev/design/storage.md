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
   **Built (2026-11-09):**
   - `Session\SqlSessionStore`, one class for both dialects. Three tables (`sessions`,
     `session_lines`, `session_sidecars`, with a prefix), created on open.
   - A location is `sql:<timestamp>_<id>.jsonl`.
   - Each append is one transaction under a write lock: `BEGIN IMMEDIATE` on SQLite,
     `SELECT … FOR UPDATE` on MySQL. Measured with three processes appending 200 lines each to one
     session: 600 lines, positions 0–599, on both.
   - The `storage` setting is global only: a string, or `{dsn, user, password, prefix}`, with `$NAME`
     read from the environment. `bin/pig` opens the store before anything looks a session up. A store
     that cannot be opened stops pig with the reason; it never falls back to files.
   - The phase 1 gaps are closed with two more methods on the interface: `remove()` (pi's files to
     the trash, as `SessionList::deleteSessionFile()` did) and `canonical()` (the web page's path
     check).
   - Tests: `SqliteSessionStoreTest` runs the conformance suite on every run. `MysqlSessionStoreTest`
     is its own suite, `PIG_TEST_MYSQL_DSN=… vendor/bin/phpunit --testsuite mysql`, so the default
     run has no skipped MySQL tests. Both dialects were run against SQLite and MariaDB while
     building.

   **Importing** is `/import x.jsonl`, which writes into whatever store keeps sessions
   (`SessionStore::create()`): pi's files get a copy in the project's directory as before, and a
   database gets the lines. A bulk `pig sessions import` was weighed and left out. A server starts
   empty, the files stay where they are (switching `storage` back to `jsonl` shows them again), and
   `/export` then `/import` moves the one that is wanted. It is a loop over `create()` once somebody
   needs it.
   **Design (2026-11-09, to be decided before any code):**

   What exists:
   - The web shell (`pig web`) is one process. Each conversation is a `pig --mode rpc` child it
     spawns (`SessionPool`, keyed `cwd::session`).
   - Every child runs as the server's OS user, with the full tools: `bash`, `read`, `write`.
   - The login (`WebLogin`) is one password for one person: `web-login.json`, a cookie session.
   - Everything else is one person's too: `auth.json` / keychain credentials, `settings.json`,
     `models.json`, scheduled tasks, extensions.

   **The question everything else hangs on is isolation.** Owners on sessions, filtered lists and
   accounts are easy. But as long as every child runs as one OS user with `bash`, any user can
   `cat` another user's files and the server's database password, so the filtering is a
   courtesy, not a boundary. Three levels:

   - **(a) Trusted users** (a family, a small team that already shares the machine): accounts and
     owners keep each person's sessions apart in the UI. Nobody is defended against anybody else.
     Cheapest; honest only if the docs say so.
   - **(b) A sandbox per user, through phase 2's `operations`:** each user's tools run in their own
     container (or over SSH as their own OS user). The agent process stays shared, but the tools
     touch only that user's files. It needs a container runtime on the server and an image. The
     database password and the credentials still live in the shared process, which runs no user
     code, so they stay out of reach.
   - **(c) An OS user per user:** the web shell spawns each child with `sudo -u <user> pig --mode
     rpc` (or `su`). This is the classic Unix answer. The server needs root to set up, and each
     user needs a home with their own `~/.pig/agent`.

   Proposed: **build (a) now and leave room for (b)**. Owners, accounts and filtering are needed by
   all three. The sandbox is a separate step that plugs into `operations` without touching what (a)
   built. The docs say plainly that until (b), users of one server can read each other's files.

   What (a) adds, in order:
   1. **Accounts:** a `users` table in the store's database (name, `password_hash`, admin flag,
      created). `WebLogin` keeps its cookie sessions and gains a user on each. With JSONL storage
      there are no accounts, and the single password stays.
   2. **The owner of a session:** written when it is created (the column exists already). The
      web shell passes the logged-in user to its child (`--owner`, or an RPC header), and the child
      passes it to `SessionManager::create()`. `listFor()`, `listAll()`, `searchAll()`, `find*()`
      and `canonical()` see only the owner's sessions, an admin sees all. A session created
      before accounts has no owner (null) and is the admin's.
   3. **Working directories:** each user gets a workspace root (`/srv/pig/<user>`, say). The
      folder picker starts there, and a child's `cwd` must be inside it. This is the line (b)
      later enforces for real.
   4. **Scheduled tasks:** move from `scheduled-tasks.json` into the store with an owner. The one
      scheduler still runs them all, each run spawned as its owner's child.
   5. **Credentials and settings stay the server's** (one API key, one `settings.json`, one
      `models.json`), so every user's requests are billed to it. Per-user keys are a later
      choice.
   6. **The TUI and `--mode rpc` without the web shell** are the local user, the admin. Nothing
      changes for them.

   **Decided 2026-11-09:**
   - **Isolation:** (a) now, (b) later, as proposed. The docs say that users of one server can
     read each other's files until (b).
   - **Credentials:** one, the server's.
   - **Accounts:** an admin adds and removes them on a page in the web UI. The first admin is
     the existing password's owner: the setup code makes the first account, and it is an admin.
   - **Admin visibility:** first decided as "the admin sees and can open every user's
     conversations", then changed the same day: **nobody sees anybody else's conversations, the
     admin included** (privacy; the developer's call). The admin manages accounts and nothing
     more, and keeps the conversations from before accounts, which nobody owns.
   - **Every table has `created_at`, `updated_at` and `deleted_at`.** The database keeps the first
     two; the code never writes them. SQLite is made to behave as MySQL does. So the SQL goes
     through a small database interface of pig's own, with one implementation per database.

   **Built first (2026-11-09): the database layer.** `Database\Database` (interface),
   `PdoDatabase` (what both share), `MysqlDatabase`, `SqliteDatabase`, and `Databases::open()`,
   which picks one by DSN. What it borrows from omniphp's `DB` and what it does not:
   - Kept from omniphp: the connection set up per driver. SQLite gets WAL, `synchronous = FULL`,
     `busy_timeout` and `foreign_keys`. MySQL gets `utf8mb4` and associative rows. Identifiers are
     quoted per driver, and the table prefix lives in the database (`table('sessions')` →
     `pig_sessions`).
   - Also kept: **a lost connection is made again.** A statement outside a transaction runs once
     more. A transaction runs again from the start (MySQL's 2006 / 2013 / 2055 / 1927 and the
     messages without a code). `pig web` is long-running, and MySQL's `wait_timeout` would
     otherwise end it.
   - Added: a transaction ended by **a deadlock or lock wait** (1213 / 1205, SQLSTATE 40001) also
     runs again, up to three attempts in all. Two processes starting the same new session on
     MySQL deadlock on gap locks, and the server's own advice is to restart the transaction.
   - Not taken: the query builder, migrations as classes, models. The SQL stays written out in the
     store. The one schema change so far is moved by hand (below).
   - **The timestamps.**
     - MySQL: `DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)`, with
       `time_zone = '+00:00'` on each connection.
     - SQLite: a `strftime('%Y-%m-%d %H:%M:%f','now')` default, plus an `AFTER UPDATE` trigger
       that sets `updated_at` (by `rowid`) when another column changed and `updated_at` itself did
       not.
     - So on both, an update that changes nothing leaves `updated_at` alone, and an `updated_at`
       the code sets stays. omniphp's trigger updates on every row change by `id` and overwrites
       an explicit value.
     - UTC to the millisecond on both; `timestamp()` reads one back as Unix milliseconds, and
       `at()` writes one.
   - `exclusively($name, $work)` runs table changes one process at a time. On MySQL it uses
     `GET_LOCK()`, because DDL commits by itself. On SQLite it is one `BEGIN IMMEDIATE`, since DDL
     is transactional there. Two pigs starting on an empty database made the same index twice
     before this.
   - `renameTable()` on SQLite renames the table's indexes and trigger too. They follow the table
     but keep their names, which the new table would then ask for and, with `IF NOT EXISTS`,
     quietly go without.
   - `addColumn($table, $column, $type)` (2.1) gives a released table a new column, nullable, when
     it does not have it yet: `createTable()` alone makes a table only when there is none. On
     SQLite the `updated_at` trigger names every column, so it is made again with the new one.

   `SqlSessionStore` now runs on it:
   - `sessions.modified_at` is gone: `modifiedAt()` is `updated_at`.
   - `owner` is null until accounts (it was `''`).
   - `remove()` sets `deleted_at` on the session's rows in all three tables, and every read skips
     deleted rows. A session started again at that location clears what was left first. A deleted
     sidecar written again comes back.
   - `replace()` rewrites the lines outright: they are the file's contents, not records of their
     own.
   - Removed sessions stay in the tables until something purges them. The user management page is
     the natural place for that.
   - **1.9.9's tables are moved over on open:**
     1. Each table without `created_at` is renamed `…_v1`.
     2. The new tables are made.
     3. The rows are copied in one transaction, `modified_at` becoming `created_at` / `updated_at`.
     4. The old tables are dropped.

     A start that stopped half way picks up where it was.
   - Measured in the container with five and six processes appending 200 lines each to one new
     session: all lines there, positions unbroken, on SQLite and MariaDB. Before the deadlock
     retry MariaDB lost a writer.
   - Tests: `DatabaseConformance` (`SqliteDatabaseTest`, and `MysqlDatabaseTest` in the mysql
     suite, which also kills its own connection to check the reconnect). `SqlSessionStoreChecks`
     covers the soft delete and the 1.9.9 move, on both.

   **Step 1 built (2026-11-09): accounts.**
   - `Accounts\Accounts` keeps who may log in and their login sessions. `Web\WebLogin` keeps
     the rest: the setup code, slowing guesses, cookies, the local token.
     - `FileAccounts` is `web-login.json`, file for file as before: one password, its user
       unnamed and the admin.
     - `SqlAccounts` keeps accounts in the sessions' database, in two tables:
       - `users`: id, name, `password_hash`, admin.
       - `login_sessions`: `token_hash`, `user_id`, `expires_at`.
   - `WebLogin::configured($home)` picks one by `storage`. It uses the store already open (the
     server), or opens the database from the setting (`pig web password`, and the setup code that
     `start` / `status` print). With files nothing changes.
   - **With accounts:**
     - The page asks for a name as well. The setup code makes the first account, an admin.
     - `pig web password <name>` changes that account's password. When there is no account yet,
       it makes the first one, an admin, so a server can be set up without a browser.
     - A wrong name and a wrong password get the same answer, `wrong_login`. A missing name is
       checked against a dummy bcrypt hash, so the two take the same time.
     - A password change logs out that account's browsers only.
   - **Names** are unique among existing accounts. That is checked in a transaction rather than
     by a unique index, so a removed account's name can be used again.
   - **Removing** an account marks it deleted and ends its login sessions. The last admin cannot be
     removed. Login sessions that ran out are deleted as new ones come, as the file did.
   - **What a 1.9.9 server with a database sees:** the existing `web-login.json` password is not
     carried over. The first visit asks for the setup code and a name, as the decision said ("the
     setup code makes the first account").
   - `/api/login/state` also says `accounts` (whether to ask for a name) and `user` (`{name,
     admin}`). Steps 2 and 3 build on it.
   - Not in this step: filtering by account (step 2, below) and adding accounts in the page (step
     3). Until step 3, accounts other than the first are added only in code. The user docs wait
     for step 3.
   - Tests: `WebLoginAccountsTest` (SQLite), and `MysqlWebLoginAccountsTest` in the mysql suite.
     `WebLoginTest` runs the file case unchanged.

   **Step 2 built (2026-11-09): the owner of a session.**
   - **Written by the child.** The web shell starts each `pig --mode rpc` child with
     `PIG_SESSION_OWNER`, and `bin/pig` opens the store with `withOwner()`. A session the child
     starts gets that account id in `owner`; appending never changes it.
   - **Whose the child writes as.** For a new conversation, whoever opened it. For an existing one,
     its owner (`ownerOf()`). So what is forked or started from inside a conversation belongs to
     the conversation's owner. That matters for the ones from before accounts, which the admin
     opens and which stay nobody's.
   - **Read through a view.** `visibleTo($id, unowned:)` is a copy of the store whose
     `exists()`, `every()`, `candidates()`, `projects()`, `findLocal()` / `findGlobal()` and
     `canonical()` see only that account's sessions. With `unowned`, it also sees the sessions with
     no owner (from before accounts), which are the admin's.
   - **Nobody sees anybody else's, the admin included** (see the decision above).
     `HttpServer::asUser()` reads through `visibleTo($id, unowned: $admin)` for every list, open,
     delete and rename. It sees the account's own sessions, and the unowned ones for the admin.
   - **What is filtered:**
     - `/api/sessions`, `/api/sessions/all` (and its search), `/api/folders` (the counts): only
       one's own.
     - `/api/sessions/delete` and `/rename`: somebody else's is "Invalid session file", as one
       that is not there.
     - `start_session` on somebody else's: "no such conversation", without starting a child.
     - `/api/session-image` and `/api/running`. A new conversation not saved yet is listed only to
       the pages it is open on (and the admin).
   - **Not filtered yet:**
     - The scheduled tasks are still the server's, one list for everybody. That is step 5.
     - `/api/file`, the terminal and the tools reach the whole machine as the server's OS user.
       That is isolation (a), as decided, until (b).
   - Without accounts (session files) nothing changes.
   - Tests:
     - `SqlSessionStoreChecks` covers owners and the view, on SQLite and MySQL.
     - `WebModeTest::testWithAccountsEachSeesOnlyTheirOwnConversationsTheAdminToo` goes through
       the server, with two accounts:
       - each account's own list;
       - `delete`, `rename` and `start_session` refused on somebody else's, the admin's attempts
         included;
       - the admin keeping the conversations from before accounts.

   **Changed the same day: one login for both, no setup code** (the developer's call).
   - Logging in is a name and a password with session files too. `FileAccounts` is one named
     account, the admin, and a `web-login.json` from before accounts is the account `admin`'s.
   - `Accounts::named()` became `several()`: whether there can be more than one account, which
     only a database has. The accounts page, `AdminMiddleware` and the Docker runtime ask that.
   - **The setup code is gone.** While there is no account, the page makes the first, and so
     does `pig web password <name>` on the server. Whoever opens the page first makes it, which
     the developer accepted: `pig web` listens on this machine alone unless told otherwise. The
     old `web-setup-code` file is removed when a password is next set.
   - With that, "a 1.9.9 server with a database does not carry its password over" no longer holds
     for files. A database's accounts start empty, so its first visit makes the admin.

   **Step 3a built (2026-11-09): the accounts page.**
   - The ⋯ menu has **Accounts** for an admin. It can add an account (name, password, admin or
     not, where its tools run), set one's password, make one an admin or not, change where its
     tools run, and remove one. Nothing of anybody's conversations is on it.
   - The routes are `GET /api/accounts`, then `POST /api/accounts/{add,remove,update,password}`.
     Each change answers the list as it now is. Setting a password or removing an account also
     closes the pages that account has open, as logging out does.
   - `users.runtime` is `host` or `docker` (`User::RUNTIMES`). A new account made in the page is
     `docker` unless the admin says otherwise; the first account, made with the setup code, is
     `host`. The table is not released yet, so the column is in its first schema and needs no
     migration.
   - **The server's things are an admin's** (`Middlewares\AdminMiddleware`): `/api/accounts` and
     `/api/keys`, which are the keys everybody's requests are billed to. The WebSocket's SSH nodes
     (`node_request`) are checked where they are answered. Anybody else gets a 403, and the menu
     does not show those entries. A page with no model and no admin says to ask an admin rather
     than opening the keys.
   - Tests:
     - `WebLoginAccountsTest` covers admin, runtime and the last admin.
     - `WebModeTest::testTheAccountsAndTheServersKeysAreAnAdminsOnly` covers the 403s, adding,
       changing, setting a password and removing.

   **Step 3b, decided 2026-11-09 and built the same day: where a Docker account's tools run.**
   - **One long-lived container per account.**
     - It is started the first time it is needed (`docker run -d`), and the tools reach it with
       `docker exec`.
     - What is installed in it stays until the container is made again.
     - It runs as the uid that owns the account's directory, not root.
   - **One image for every Docker account**, a global setting (`sandbox.image`, with
     `sandbox.workspaces` for the directory below). The image carries `git`, `rg`, `fd` and the
     languages the server's people need.
   - **The account's files on the host:** `<workspaces>/<name>-<id>/`, mounted **at the same path**
     in the container (decided while building, instead of at `/workspace`). A path then means the
     same thing to the model, the session's `cwd`, the web page and the container, and nothing is
     translated. The directory outlives the container, and the admin can back it up on the host.
     The id is in the name so that a new account given a removed one's name does not get its
     files. A new conversation's directory is chosen inside it (step 4, folded in here).
   - **The agent stays on the host.** The `pig --mode rpc` child, the model calls, the stored
     sessions and the keys are all on the host, out of the container's reach.
     - Only the tools go in, as the phase 2 `*Operations` (`DockerOperations`, since step 6 `RemoteOperations`, for read, write,
       edit, ls, find, grep and bash).
     - The model is told the paths its commands run at, which are the host's as well.
   - **What else has to go with it, or the container is no boundary:**
     - **The web terminal:** a Docker account's is `docker exec -it` into its container, not a
       shell on the host.
     - **`/api/file`, `/api/upload`, `/api/dirs`:** only inside the account's directory.
     - **Project extensions and project MCP servers are not loaded** for a Docker account. They
       would run in the agent on the host. The admin's global extensions still load. (The
       developer's call.)
   - **As built:**
     - **The account's container.** `Sandbox\DockerSandbox::ensure()` makes the directory, then
       finds the container `pig-<id>`: started when it is stopped, made when there is none. It is
       made with these, and asked on the loop (`Process::runAsync()`):
       - `docker run -d --init --restart unless-stopped --pull never`;
       - `--user <the server's uid:gid>`;
       - `HOME=<directory>`, with the directory mounted at its own path.
     - **Settings and image.** `Settings::sandbox()` reads `sandbox: {image, workspaces, docker}`,
       which is global, as `storage` is. The image is `pig-sandbox` by default, from
       `docker/sandbox/Dockerfile`, built by the admin and never pulled by pig.
     - **The tools.** `Tools\DockerOperations` (since step 6 `RemoteOperations`) implements all seven operations with one
       `docker exec` each (`Sandbox\DockerContainer`):
       - the files through `cat`, `test`, `mkdir -p` and `find -print0`;
       - `rg` and `fd` run there as well. `Tools\RunsElsewhere` is pig's own interface for that:
         upstream's grep runs `rg` here, and its find swaps `fd` for `glob()`, so neither reaches
         files only another machine has. `grep` reads its context lines there too.
       - `bash` runs as `setsid -w bash -c` there, with the process group written down, so
         stopping a command signals that group from a second `docker exec`. Stopping the client
         here would leave the command running in the container.
       - Of the session's environment, only `PIG_*` goes in.
     - **The child process.** `PIG_SANDBOX_CONTAINER` / `PIG_SANDBOX_DOCKER` tell
       `bin/pig` to give every built-in tool these operations (`ToolSet::useOperations()`),
       `!command` included (`AgentSession::executeBash()`). `--no-approve` comes with them, so
       nothing of the project's own `.pig/` loads.
     - **The web server, for a Docker account:**
       - `start_session` keeps the `cwd` inside the directory and starts the container.
       - `/api/dirs` stays inside the directory.
       - `/api/file` reads through the container.
       - `/api/upload` writes into `<directory>/.pig-uploads/` through the container, so a link
         cannot aim the write at the host.
       - `/api/terminal/exec` runs in the container, and `/complete` answers nothing.
       - The page's terminal is `docker exec -it` into the container.
     - **Terminals are now per account, for everybody.** Until now every terminal was listed to
       every page and could be typed into from any of them. Now nobody else's can be listed,
       read or typed into.
     - **Tests:**
       - `DockerOperationsTest` uses a stand-in `docker` written at test time, which runs `exec`
         here. It covers the files, binary data past a pipe's size, errors, the image check,
         `bash` with only `PIG_*`, stopping a command stopping what it started, `grep` through it,
         `ToolSet::useOperations()`, the container made once, and a link out of the directory
         being outside it.
       - `WebModeTest::testADockerAccountWorksInItsOwnDirectoryAndContainer` covers the
         conversation's `cwd` and environment, the folder picker, the upload, the terminal
         command, and a host account untouched.
       - The `bash` tests need util-linux `setsid`, so they are skipped on macOS. They ran in
         the container this was built in.
   - Docker is the boundary for people who share a server and should not see each other's
     things. It shares the host's kernel, so it is not one against somebody set on breaking out.
     That would be gVisor or a VM, behind the same `*Operations`.

   **Step 3c, built 2026-11-09: folders of the host given to a Docker account.** Without them a
   Docker account has only its own empty directory, and code already on the server cannot be
   worked on. Uploading a whole folder through the page, or keeping one in step with the
   browser's machine as the Claude app does, was considered and left out (the developer's call):
   the admin gives folders that are on the server.
   - **Stored** in `users.options`, a JSON column (`{"folders": [{"path", "readonly"}]}`), added
     with `Database::addColumn()` to a 2.0 table. `User::$folders`; `Accounts::setFolders()`
     (`FileAccounts` has nothing to give: its one account is on the host).
   - **Given** by the admin: `POST /api/accounts/folders {name, folders}`, a Docker account only,
     each folder checked by `DockerSandbox::checkFolder()`: a whole path to an existing
     directory, kept as its real path, that neither holds nor is inside pig's home or the
     accounts' directories, and is none of the system's (`/etc`, `/usr`, …), which the
     container needs its own of. In the page: ⋯ → Accounts → Folders on a Docker account's row.
   - **Mounted** at the same path as the account's directory, `:ro` when read-only, so paths are
     still never translated. The container is labelled with a hash of its mounts
     (`dev.pigagent.mounts`); `ensure()` makes it again (`docker rm -f`, `run`) when the label
     differs. What was running in it stops, and what was installed outside `HOME` goes, as with
     a new image. A 2.0 container, without the label, is kept while the account has no folders.
   - **Reached** as the account's directory is: `DockerSandbox::contains()` and `roots()` take
     both, so `start_session`, `/api/dirs` (with no way up out of a root), `/api/file` and a
     scheduled task's folder accept them, and `/api/folders` lists them to start in. Uploads
     still go to the account's own directory. Files written in a folder are owned by the
     server's uid, as with the directory.
   - **Tests:** `DockerOperationsTest` (mounts, made again once, a 2.0 container kept, `contains()`,
     `checkFolder()`), `WebLoginAccountsTest` (kept, once each, a 2.0 table given the column),
     `DatabaseConformance` (`addColumn()`, the trigger with it), and `WebModeTest`'s Docker test
     (the API, the listing, the picker, a conversation in a given folder, the container made
     again).


   **Step 5 built (2026-11-09): each account's scheduled tasks.**
   - **Whose a task is.** `ScheduledTask::$owner` is the account that made the task. A change
     keeps it, and what the page sends cannot set it. A task from before accounts has none and is
     the admin's.
   - **Seeing only one's own.** `ScheduleManager`'s public methods take the `User` asking and see
     only that account's tasks: the list, the runs, the sidebar's marks, save, delete, toggle, run
     now and seen. The admin sees theirs and the unowned ones, as with conversations. Null is the
     server itself, which sees all.
   - **The `schedule` tool.** It runs in a conversation's pig, and reaches the server with the
     local token. `ScheduleClient` sends the account the conversation is for (`X-Pig-Owner`, from
     `PIG_SESSION_OWNER`), and `HttpServer::userOf()` believes that header only beside the local
     token, which only pig on the server's machine can read.
   - **A run is its owner's.** It starts its child through `SessionPool::open($cwd, $file, $owner,
     $environment)`, the environment from `HttpServer::environmentOf()`. A Docker account's run is
     in its container, started if need be, and the conversation a task gets is the owner's
     (`SqlSessionStore::withOwner()`). A Docker account's task works in a folder inside its own
     directory. A removed account's tasks are switched off (`disableAllOf()`), kept, and seen by
     nobody, and a run of one that is still somehow due says the account is gone.
   - **Where tasks are kept** is a `ScheduleStore`:
     - `FileScheduleStore` is `scheduled-tasks.json` as before, with sessions in files.
     - `SqlScheduleStore`, with sessions in a database, keeps them in `scheduled_tasks` (id,
       `owner`, the task as JSON in `data`) and `scheduled_runs` (id, `task_id`, `data`), with
       the three timestamps.
     - A save writes only the rows that changed, so `updated_at` is a row's last change. What is
       gone (a deleted task, a run past the last fifty) is deleted the soft way.
     - The first time, with no task in the table, `scheduled-tasks.json` is copied in and renamed
       `….json.imported`.
     - Which server runs the schedule is still the lock file in the home, so one machine for
       now; that is phase 5's.
   - Tests (`ScheduleManagerTest`):
     - each account sees and changes only its own, the admin the unowned too, and nobody can set
       the owner from the page;
     - a run's child is started as its owner, with its environment;
     - with a database the tasks are there, taken over from the file once, with the
       conversation the owner's and a delete soft.

   **Step 6, decided 2026-11-09 and built 2026-11-10: SSH accounts.** A third place an account's
   tools run, beside the host and a container: an OS user reached over SSH.
   - **Both uses, one runtime** (the developer's call): another OS user on this server
     (`127.0.0.1`), whose own permissions then keep the accounts apart without Docker, or another
     machine (a GPU box, a build machine). That user's permissions are the boundary; pig confines
     nothing further, so any absolute path there is the account's.
   - **Set on the account** (`User::$ssh` in `users.options`, `Accounts::setSsh()`): host, port,
     user, a private key's path on the server ('' for the server user's own `~/.ssh` and agent),
     and the workspace there, by its whole path. `POST /api/accounts/ssh` checks them
     (`SshSandbox::checkConfig()`); `POST /api/accounts/check` connects (and, for a Docker
     account, starts its container) and says what `ssh` said. In the page: ⋯ → Accounts →
     Connection, with "Save and test".
   - **How it connects** (`Sandbox\SshTarget`): `BatchMode=yes` (a key, never a prompt);
     `StrictHostKeyChecking=accept-new` against pig's own `known_hosts` beside `settings.json`;
     one shared connection per target (`ControlMaster=auto`, `ControlPersist=600`, sockets under
     `ssh/`), since every file the agent reads is an `ssh`. The remote command is one line for the
     login shell, each word single-quoted: `cd -- '<cwd>' && exec env 'K=V' '<argv>'…`. The
     `ssh` command is `sandbox.ssh`.
   - **What was generalised:**
     - `Sandbox\Shell` (`exec()`, `run()`, `environment()`) is what `DockerContainer` and
       `SshTarget` are; `Sandbox\Runtime` (`ensure()`, `shell()`, `workspace()`, `roots()`,
       `contains()`, `here()`, `dirs()`) is what `DockerSandbox` and `SshSandbox` are.
       `HttpServer::sandboxOf()` answers a `Runtime`.
     - `Tools\DockerOperations` became `Tools\RemoteOperations` over a `Shell`. Its run files
       are `${TMPDIR:-/tmp}/.pig-runs-<uid>`, since several accounts may be users of one machine,
       and the command is a word of what `Shell::exec()` runs (`ssh` sends one line; a word added
       after it would join that line unquoted). `PIG_SANDBOX_*` stays out of what goes in.
     - `bin/pig` reads `PIG_SANDBOX_CONTAINER` / `PIG_SANDBOX_SSH` (`Sandbox\Shells`).
   - **Paths are that machine's, and need not exist here.** So:
     - The child is started in a directory of pig's own (`Runtime::here()`:
       `ssh/here/<name>-<id>` for SSH; the folder itself for Docker), with `--cwd` the folder
       there; `HttpServer`'s spawn passes `--no-approve --cwd` whenever `PIG_SANDBOX_HERE` is set.
       `bin/pig` checks `--cwd` where the tools run, not with `is_dir()`.
     - `SessionCwd` (a resumed conversation's folder) and `ContextFiles` (`AGENTS.md`, one
       command for the walk up, then each read) ask where the tools run.
     - `ToolSet::run()` runs what pig runs in a project besides the tools — `git` for `/diff`
       and its untracked list, the doctor's branch, the PR description's diff — where the tools
       run. This also closed a hole for Docker accounts: those ran on the host, in the account's
       directory, where a `.git/config` of the account's (`core.fsmonitor`) would have run as the
       server's user.
     - `/api/dirs` lists there (`Runtime::dirs()`, `DirectoryListing::wanted()` / `of()`
       shared), `/api/folders` does not look for the workspace here, `/api/file` and the
       terminal's commands go through the shell, and the page's terminal is `ssh -t`, its pty
       started in `here()`.
     - A scheduled task's folder is looked for there when it is saved, and not here when it is
       saved or read back (`ScheduledTask::fromArray(..., here: false)`,
       `ScheduleManager::$elsewhere`); the `schedule` tool in such a child does the same.
   - **Nothing an unreachable machine can freeze:** `start_session`, the terminal, `/api/dirs`,
     `/api/file` and `check` reach the runtime on the loop (`Async::spawn`, `Process::runAsync`),
     so a connection waiting out `ConnectTimeout` holds only its own page. (Before, a Docker
     account's `start_session` ran `docker inspect` on the loop's own turn.)
   - **That side needs** a POSIX login shell, bash, coreutils, util-linux's `setsid` (stopping a
     command), and `rg` and `fd` for grep and find.
   - **Tests:** `SshOperationsTest` uses a stand-in `ssh` (`fakeSsh()`) that runs the line with
     bash here in an environment of its own, and refuses a host named `unreachable`: the command
     line and its quoting, options that must not reach `ssh`, the environment round trip, the
     tools, `bash` and stopping it, `AGENTS.md` there, `ToolSet::run()`, the sandbox's check,
     workspace, `here()` and listing, and what an account may be given.
     `RpcClientTest::testAnSshAccountsChildWorksInAFolderThere` runs a real `bin/pig` child over
     it. `WebModeTest::testAnSshAccountWorksAsAUserReachedOverSsh` covers the API, the check
     (refused, then made), the folder list, the picker, the conversation's child and
     environment, the terminal's command and a task's folder. `ScheduleManagerTest` covers a task
     in a folder there, across a restart.

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
