<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentError;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Models;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Config;

/**
 * The conversation on disk, one JSON object per line.
 *
 * Appended to rather than rewritten, so a session survives whatever ends the process —
 * a crash, a closed laptop, Ctrl+C twice. Reading one back is reading the file top to
 * bottom; there is no state to reconstruct beyond the messages themselves.
 *
 * Ported from upstream's `session-manager.ts`, 1129 lines to this one's 1000-odd. What this
 * paragraph used to say was that the tree, labels, the model and thinking-level entries and the
 * format migrations were all unported and the log was a line — which was true when it was written
 * and had stopped being true of every item on the list. A docblock that inventories what is missing
 * is a docblock that goes stale silently; this one now says what is here.
 *
 * Upstream's `createBranchedSession()` — forking the path to one leaf into a **second session
 * file** — is `fork()` here, and it hands back a new instance rather than turning this one into
 * the fork, because `$path` and `$id` are readonly and `AgentSession::writeTo()` is how a session
 * changes files. Everything else in that file has a counterpart here.
 */
final class SessionManager
{
    /** Bumped when the format changes in a way an older pig could not read. */
    /**
     * pi's current format. v3 differs from v2 in one rename pig never wrote (`hookMessage` →
     * `custom` on a message's role) and in two entry kinds pig now reads and writes:
     * `context_edit` and `usage`. Writing 3 is what stops pi rewriting every pig file on open.
     */
    private const int VERSION = 3;

    /** How many to offer in a list before it stops being a list. */
    private const int LISTED = 30;

    /**
     * @var array<string, array{message: mixed, parent: string|null}> every entry, by id
     *
     * Every entry, not every entry on the current branch: going back to an earlier point
     * and taking the conversation somewhere else leaves the first attempt in the file,
     * and the whole point is that it can be gone back to.
     */
    private array $entries = [];

    /** The end of the branch being talked on. Null in an empty session. */
    private ?string $leaf = null;

    /** @var array<string, string> what each named point is called, by entry id */
    private array $labels = [];

    /** The user-defined or auto-summarized session display name. */
    private ?string $sessionName = null;


    /**
     * Whether the header has been written.
     *
     * Nothing is written until an assistant message arrives. Someone who starts pig,
     * reads the banner and quits leaves no file behind — which is why the sessions
     * directory is worth opening at all.
     */
    private bool $started = false;

    private function __construct(
        public readonly string $path,
        public readonly string $id,
        public readonly string $cwd,
        private readonly int $createdAt,
        /** The file this one was forked from — pi's `parentSession` on the header. Null otherwise. */
        public readonly ?string $parentSession = null,
    ) {
    }

    /** True on a copy made by `preview()`: appended to like any session, written nowhere. */
    private bool $preview = false;

    /**
     * A copy of this session to try entries against without writing any of them — upstream's
     * `SessionManager.inMemory(cwd, undefined, [header, ...branch])` as a boundary preview. The
     * entries are copied with it, so what the copy projects is what this session would project
     * with the same things appended.
     */
    public function preview(): self
    {
        $copy = clone $this;
        $copy->preview = true;

        return $copy;
    }

    /**
     * The same for a session that is not being written down (`--no-save`): the messages the
     * agent holds, appended to a session nothing writes. An id-addressed draft — a context edit,
     * a compaction's `firstKeptEntryId` — has nothing to name here, as nothing there has an id.
     *
     * @param list<mixed> $messages
     */
    public static function previewOf(string $cwd, array $messages): self
    {
        $copy = self::create($cwd);
        $copy->preview = true;

        foreach ($messages as $message) {
            $copy->append($message);
        }

        return $copy;
    }

    /** A session that will be written to $cwd's directory once it has something to say. */
    public static function create(string $cwd, ?string $path = null, ?string $parentSession = null): self
    {
        $id = self::uuid();
        $now = Timestamp::nowMs();

        // pi's name: the ISO timestamp with its colons and dots turned into dashes, an
        // underscore, then the session id. Same shape so one directory can hold both
        // tools' files and sort the way either of them expects.
        $stamp = str_replace([':', '.'], '-', SessionEntries::iso($now));

        return new self(
            $path ?? self::directory($cwd) . '/' . $stamp . '_' . $id . '.jsonl',
            $id,
            $cwd,
            $now,
            $parentSession,
        );
    }

    /** Whether this session has been written to disk. */
    public function isPersisted(): bool
    {
        return $this->started && is_file($this->path);
    }

    /**
     * The command to resume this session, matching upstream pi's formatResumeCommand.
     * Returns null if the session was never persisted to disk.
     */
    public function resumeCommand(): ?string
    {
        if (!$this->isPersisted()) {
            return null;
        }

        return "pig --session {$this->id}";
    }

    /** A v4 UUID, which is what pi's ids are made of. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    /**
     * A file's lines, or null when there is nothing readable there.
     *
     * The readability is checked rather than the warning suppressed: `@` hides every
     * other thing that could go wrong in the same call, and a session that will not open
     * is exactly when you want to be told why.
     *
     * @return list<string>|null
     */
    private static function lines(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? null : $lines;
    }

    /** Read one back. Its messages are in `messages()`. */
    public static function open(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AgentError("Could not read the session at {$path}");
        }

        $fh = fopen($path, 'rb');

        if ($fh === false) {
            throw new AgentError("Could not read the session at {$path}");
        }

        $firstLine = fgets($fh);

        if ($firstLine === false || trim($firstLine) === '') {
            fclose($fh);
            throw new AgentError("Not a pig session file: {$path}");
        }

        $header = json_decode(trim($firstLine), true);

        if (!is_array($header) || ($header['type'] ?? null) !== 'session') {
            fclose($fh);
            throw new AgentError("Not a pig session file: {$path}");
        }

        $version = (int) ($header['version'] ?? 1);

        // Rare migration branch for legacy v1/v2 files: read full lines array and rewrite
        if ($version < self::VERSION) {
            fclose($fh);
            $lines = self::lines($path) ?? throw new AgentError("Could not read the session at {$path}");
            $upgraded = self::upgrade($lines, $header);

            if ($upgraded !== null) {
                $lines = $upgraded;
                $header = json_decode($lines[0], true);
                self::rewrite($path, $lines);
            }

            return self::buildFromLines($path, $lines, $header);
        }

        $session = new self(
            $path,
            (string) ($header['id'] ?? ''),
            (string) ($header['cwd'] ?? ''),
            SessionEntries::millis($header['timestamp'] ?? null),
            is_string($header['parentSession'] ?? null) ? $header['parentSession'] : null,
        );

        $session->started = true;
        $previous = null;

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $raw = json_decode($line, true);

            if (!is_array($raw)) {
                continue;
            }

            self::populateEntry($session, $raw, $previous);
        }

        fclose($fh);
        $session->leaf = $previous;

        return $session;
    }

    /** @param list<string> $lines */
    private static function buildFromLines(string $path, array $lines, array $header): self
    {
        $session = new self(
            $path,
            (string) ($header['id'] ?? ''),
            (string) ($header['cwd'] ?? ''),
            SessionEntries::millis($header['timestamp'] ?? null),
            is_string($header['parentSession'] ?? null) ? $header['parentSession'] : null,
        );

        $session->started = true;
        $previous = null;

        foreach (array_slice($lines, 1) as $line) {
            $raw = json_decode($line, true);

            if (!is_array($raw)) {
                continue;
            }

            self::populateEntry($session, $raw, $previous);
        }

        $session->leaf = $previous;

        return $session;
    }

    /**
     * Parse one entry from disk and wire it into the session tree and metadata.
     *
     * @param array<string, mixed> $raw
     */
    private static function populateEntry(self $session, array $raw, ?string &$previous): void
    {
        $id = (string) ($raw['id'] ?? self::newId($session->entries));
        $item = SessionEntries::decode($raw);

        $session->entries[$id] = [
            'message' => $item,
            'parent' => is_string($raw['parentId'] ?? null) ? $raw['parentId'] : null,
        ];

        // In file order, so the last thing said about a point is what it is called —
        // including "nothing", which is how a name is taken off again.
        if ($item instanceof Label) {
            if ($item->label === null) {
                unset($session->labels[$item->targetId]);
            } else {
                $session->labels[$item->targetId] = $item->label;
            }
        } elseif ($item instanceof SessionInfoEntry) {
            $session->sessionName = self::cleanSessionName($item->name);
        }

        $previous = $id;
    }

    /**
     * A session file pi wrote before it had a tree, brought up to the shape it has now.
     *
     * v1 had no `version`, no `id` and no `parentId`: **the order of the lines was the chain**, and
     * a compaction named its cut by `firstKeptEntryIndex` — an index into the file, the header
     * counted. pig read such a file as a set of entries with no parents, which makes every one of
     * them a root, so walking back from the leaf found exactly one message: *a conversation
     * somebody had with pi before the tree existed opened as its last line and nothing else.*
     *
     * Upstream's `migrateV1ToV2()`, and like upstream the file is **rewritten**. That is the one
     * decision here worth arguing, and the argument is `Migrations`': what happens is exactly what
     * pi itself does on its next start, so running it converges rather than diverges. Leaving the
     * file alone is worse than it looks — pig would append v2 entries to a v1 file, and pi's own
     * migration then re-ids every line from the top, flattening whatever branches pig made in
     * between.
     *
     * @param list<string>         $lines  the file, line by line
     * @param array<string, mixed> $header its first line, decoded
     * @return list<string>|null the upgraded lines, or null when there was nothing to do
     */
    private static function upgrade(array $lines, array $header): ?array
    {
        $version = (int) ($header['version'] ?? 1);

        if ($version >= self::VERSION) {
            return null;
        }

        if ($version === 2) {
            return self::upgradeFromV2($lines, $header);
        }

        $decoded = [$header];
        $taken = [];

        foreach (array_slice($lines, 1) as $line) {
            $raw = json_decode($line, true);

            // A line that is not JSON is dropped rather than carried, which is the one place this
            // differs from reading: an unreadable line has no id to give the next one a parent, so
            // keeping it would leave a hole in the chain this exists to build.
            if (!is_array($raw)) {
                continue;
            }

            $decoded[] = $raw;
        }

        $previous = null;

        foreach ($decoded as $at => $raw) {
            if ($at === 0) {
                continue;
            }

            $id = self::newId($taken);
            $taken[$id] = true;
            $decoded[$at]['id'] = $id;
            $decoded[$at]['parentId'] = $previous;
            $previous = $id;
        }

        foreach ($decoded as $at => $raw) {
            if (($raw['type'] ?? null) !== 'compaction' || !is_int($raw['firstKeptEntryIndex'] ?? null)) {
                continue;
            }

            $target = $decoded[$raw['firstKeptEntryIndex']] ?? null;
            unset($decoded[$at]['firstKeptEntryIndex']);

            if (is_array($target) && ($target['type'] ?? null) !== 'session') {
                $decoded[$at]['firstKeptEntryId'] = $target['id'];
            }
        }

        $decoded[0]['version'] = self::VERSION;

        return array_map(static fn (array $raw): string => (string) json_encode($raw), $decoded);
    }

    /**
     * pi's `migrateV2ToV3()`: the one rename, and the header. A v2 file already has the tree, so
     * ids and parents are left exactly as they are — this must not re-id anything.
     *
     * @param list<string>         $lines
     * @param array<string, mixed> $header
     * @return list<string>
     */
    private static function upgradeFromV2(array $lines, array $header): array
    {
        $header['version'] = self::VERSION;
        $out = [(string) json_encode($header)];

        foreach (array_slice($lines, 1) as $line) {
            $raw = json_decode($line, true);

            if (is_array($raw) && ($raw['type'] ?? null) === 'message' && (($raw['message']['role'] ?? null) === 'hookMessage')) {
                $raw['message']['role'] = 'custom';
                $line = (string) json_encode($raw);
            }

            $out[] = $line;
        }

        return $out;
    }

    /**
     * Put the upgraded lines back, and carry on regardless if that cannot be done.
     *
     * A read-only checkout or somebody else's file that is not ours to write is not a reason to
     * refuse the conversation: the entries in memory are already right, and the only cost is that
     * the next open upgrades it again. `Migrations`' rule — every step skipped at the first sign of
     * trouble, because half-migrating somebody else's data is worse than not starting.
     *
     * @param list<string> $lines
     */
    private static function rewrite(string $path, array $lines): void
    {
        if (!is_writable($path)) {
            return;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * The conversation on the branch being talked on.
     *
     * Walked from the leaf back to the root rather than read in file order, because the
     * file holds every branch and only one of them is this conversation.
     *
     * @return list<mixed>
     */
    public function messages(): array
    {
        $path = [];

        foreach ($this->pathTo($this->leaf) as $id) {
            $path[] = [$id, $this->entries[$id]['message']];
        }

        return array_map(static fn (array $one): mixed => $one[1], self::resolve($path));
    }

    /**
     * Every message in the file, on any branch and before any compaction.
     *
     * The answer to a different question from `messages()`, and the only one that is right about
     * money: a compaction replaces what it summarises, and a branch that was walked away from is
     * still in the file — but every assistant message here came back from a provider that charged
     * for it, and neither of those two things is a refund. `messages()` is what the conversation
     * *is*; this is what happened.
     *
     * File order, not branch order, because there is no branch to walk: every entry is included,
     * so the order is the order they arrived in. Upstream's `getEntries()`, which its footer sums
     * for the same reason.
     *
     * @return list<mixed>
     */
    public function everyMessage(): array
    {
        return array_values(array_map(
            static fn (array $entry): mixed => $entry['message'],
            $this->entries,
        ));
    }

    /**
     * Every message somebody typed, on any branch, in file order, with the id `fork()` takes.
     *
     * @return list<array{id: string, message: UserMessage}>
     */
    public function everyUserMessage(): array
    {
        $found = [];

        foreach ($this->entries as $id => $entry) {
            if ($entry['message'] instanceof UserMessage) {
                $found[] = ['id' => (string) $id, 'message' => $entry['message']];
            }
        }

        return $found;
    }

    /**
     * Which entry the message at $index of `messages()` came from.
     *
     * The join between the conversation and the file, and the reason it has to exist: a
     * compaction records where the kept part *starts*, by entry id, and what decides the cut
     * is an index into the resolved conversation. Those two are not the same numbering as
     * soon as there has been one compaction already, so the translation is done here, where
     * the resolution is, rather than guessed at by the caller.
     */
    public function entryAt(int $index): ?string
    {
        $path = [];

        foreach ($this->pathTo($this->leaf) as $id) {
            $path[] = [$id, $this->entries[$id]['message']];
        }

        return self::resolve($path)[$index][0] ?? null;
    }

    /**
     * One entry: what it holds, and what it came after.
     *
     * Needed because going back to something somebody *said* means going back to before it —
     * the leaf lands on its parent and the words go into the editor to be asked differently —
     * and that is two facts about one entry rather than a message on a path.
     *
     * A parent of the entry's own id is how a root is written in a pi file; it is handed back as
     * written, and the caller reads it as a root the way `tree()` does.
     *
     * @return array{message: mixed, parent: string|null}|null null for an id this file has not got
     */
    public function entry(string $id): ?array
    {
        return $this->entries[$id] ?? null;
    }

    /**
     * Every point on this branch that could be gone back to, oldest first.
     *
     * The ids as well as the messages, because going back means naming one.
     *
     * @return list<array{id: string, message: mixed, branches: int, label: string|null}>
     */
    public function branch(): array
    {
        $path = [];

        foreach ($this->pathTo($this->leaf) as $id) {
            $item = $this->entries[$id]['message'];

            if (!self::isSaid($item)) {
                continue;
            }

            $path[] = [
                'id' => $id,
                'message' => $this->entries[$id]['message'],
                'branches' => $this->childCount($this->entries[$id]['parent']),
                'label' => $this->labels[$id] ?? null,
            ];
        }

        return $path;
    }

    /**
     * The whole conversation as a tree, roots first, each node's children oldest first.
     *
     * **`branch()` walks the path being talked on; this walks everything.** The difference is the
     * point of `goTo()`: nothing is deleted when a conversation goes back, so the entries that were
     * left behind are still here with their parents intact — and until this existed, `branch()` was
     * the only way to see any of them, which meant an abandoned branch could not be named and so
     * could not be gone back to. The docblock below promised it could. See CLAUDE.md.
     *
     * Upstream's `getTree()`, including the two cases it guards: an entry whose parent is itself is
     * a root, and an entry whose parent is not in the file is an **orphan treated as a root** rather
     * than dropped. A file written by something newer can hold one, and losing part of somebody's
     * conversation to keep a tidy tree is the wrong way round.
     *
     * **Children come out in file order, and neither sorted by id nor by timestamp.** An id is eight
     * characters off a UUID, so sorting by it scrambles the order — the first version of this did,
     * and the test that caught it is the one asserting which arm of a fork is first. A timestamp
     * would not do either: it is the *message's*, and the entries of one turn share it to the
     * millisecond. `$this->entries` is an insertion-ordered map, which is the file's own order, so
     * walking it once and appending is the whole sort. Upstream sorts by timestamp and gets file
     * order by luck.
     *
     * @return list<array{
     *     id: string,
     *     message: mixed,
     *     label: string|null,
     *     children: list<array<string, mixed>>,
     * }>
     */
    public function tree(): array
    {
        $children = [];

        foreach ($this->entries as $id => $entry) {
            $parent = $entry['parent'];
            // Its own parent, or a parent this file does not have: a root either way.
            $key = $parent === null || $parent === (string) $id || !isset($this->entries[$parent])
                ? ''
                : $parent;
            $children[$key][] = (string) $id;
        }

        return $this->nodesUnder('', $children);
    }

    /**
     * @param array<string, list<string>> $children
     * @return list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}>
     */
    private function nodesUnder(string $parent, array $children): array
    {
        $nodes = [];

        foreach ($children[$parent] ?? [] as $id) {
            $nodes[] = [
                'id' => $id,
                'message' => $this->entries[$id]['message'],
                'label' => $this->labels[$id] ?? null,
                'children' => $this->nodesUnder($id, $children),
            ];
        }

        return $nodes;
    }

    /**
     * Go back to an earlier point; the next thing said starts a new branch.
     *
     * Nothing is deleted and nothing is rewritten. The entries after this one are still
     * in the file with their parents intact, so the branch that was abandoned can be
     * gone back to in exactly the same way.
     *
     * @throws AgentError when there is no such point in this session
     */
    public function goTo(?string $id): void
    {
        if ($id !== null && !isset($this->entries[$id])) {
            throw new AgentError('No such point in this conversation.');
        }

        $this->leaf = $id;
    }

    public function leaf(): ?string
    {
        return $this->leaf;
    }

    /**
     * The path from the root down to $leafId, as a session file of its own — upstream's
     * `createBranchedSession()`.
     *
     * `goTo()` moves inside one file and leaves every branch where it was; this is for when one
     * branch should *become* a conversation — resumable on its own, listed on its own, with the
     * other branches no longer in it. The new file's header names this one as `parentSession`,
     * which is pi's shape, so a fork made in either tool reads in the other.
     *
     * Three things from upstream, kept because each is a file pi could not read otherwise:
     *
     * - **Labels are taken off the path and written again at the end.** A label is a real entry
     *   with children of its own, so an entry after one on the path has it as a parent; the path
     *   is re-chained around them, and every label on a kept entry is written last, hanging off
     *   the new leaf, with the timestamp it had.
     * - **A compaction's `firstKeptEntryId` follows the re-chaining.** It can name a label that is
     *   gone, in which case it names the next kept entry instead.
     * - **Written now only when the path holds a conversation**, the same rule `append()` applies
     *   to a new session: a fork of nothing but a user message is held back until something
     *   answers it, so a changed mind leaves no file behind.
     *
     * @throws AgentError when there is no such point in this session
     */
    public function fork(string $leafId): self
    {
        $path = $this->pathTo($leafId);

        if ($path === []) {
            throw new AgentError('No such point in this conversation.');
        }

        $fork = self::create($this->cwd, parentSession: $this->path);

        $previous = null;
        $replacement = [];
        $pendingLabels = [];

        foreach ($path as $id) {
            $item = $this->entries[$id]['message'];

            if ($item instanceof Label) {
                $pendingLabels[] = $id;

                continue;
            }

            foreach ($pendingLabels as $labelId) {
                $replacement[$labelId] = $id;
            }

            $pendingLabels = [];

            if ($item instanceof CompactionSummary && $item->firstKeptEntryId !== null && $item->firstKeptEntryId !== $id) {
                $item = self::withFirstKept($item, $replacement[$item->firstKeptEntryId] ?? $item->firstKeptEntryId);
            }

            $fork->entries[$id] = ['message' => $item, 'parent' => $previous];
            $previous = $id;
        }

        $fork->leaf = $previous;
        $fork->sessionName = $this->sessionName;

        foreach ($this->labels as $targetId => $label) {
            if (!isset($fork->entries[$targetId])) {
                continue;
            }

            $labelId = self::newId($fork->entries);
            $fork->entries[$labelId] = [
                'message' => new Label((string) $targetId, $label, $this->labelTimestamp((string) $targetId)),
                'parent' => $fork->leaf,
            ];
            $fork->labels[$targetId] = $label;
            $fork->leaf = $labelId;
        }

        foreach ($fork->entries as $entry) {
            if ($this->worthKeeping($entry['message'])) {
                $fork->appendLines($fork->prologue($fork->entries));
                $fork->started = true;

                break;
            }
        }

        return $fork;
    }

    /** When $targetId was last given the name it has, read off the entries in file order. */
    private function labelTimestamp(string $targetId): ?int
    {
        $at = null;

        foreach ($this->entries as $entry) {
            $item = $entry['message'];

            if ($item instanceof Label && $item->targetId === $targetId && $item->label !== null) {
                $at = $item->timestamp;
            }
        }

        return $at;
    }

    private static function withFirstKept(CompactionSummary $summary, string $firstKeptEntryId): CompactionSummary
    {
        return new CompactionSummary(
            $summary->summary,
            $summary->readFiles,
            $summary->modifiedFiles,
            $summary->tokensBefore,
            $firstKeptEntryId,
            $summary->replaced,
            $summary->timestamp,
            $summary->fromHook,
            $summary->systemMessage,
        );
    }

    /**
     * What would be left behind by moving the leaf to $id, oldest first.
     *
     * The messages on the branch being talked on that the branch under $id does not
     * have. Computed by comparing the two paths rather than by taking everything after
     * $id, because $id may be on another branch entirely — in which case what is being
     * left is everything past the point the two paths last agreed.
     *
     * Unresolved: these are the entries as they were written, not the conversation they
     * stand for, so a compaction summary here is the summary and not the messages it
     * replaced. A caller looking at what is being abandoned wants the entries.
     *
     * @return list<mixed>
     */
    public function abandoning(?string $id): array
    {
        $target = [];

        foreach ($this->pathTo($id) as $entryId) {
            $target[$entryId] = true;
        }

        $left = [];

        foreach ($this->pathTo($this->leaf) as $entryId) {
            $item = $this->entries[$entryId]['message'];

            // Only what was said: a summary of an abandoned branch should not mention that
            // somebody switched model on it.
            if (!isset($target[$entryId]) && self::isSaid($item)) {
                $left[] = $item;
            }
        }

        return $left;
    }

    /**
     * Whether this entry is part of the conversation at all.
     *
     * Five things in a session file are not: a hook's private note, a model change, a
     * thinking-level change, a label, and a line pig cannot read. They are in the tree because the
     * entries after them hang off them, and they are walked past everywhere else.
     */
    private static function isSaid(mixed $item): bool
    {
        return $item !== null
            && !$item instanceof CustomEntry
            && !$item instanceof ModelChange
            && !$item instanceof ThinkingLevelChange
            && !$item instanceof Label
            && !$item instanceof SessionInfoEntry
            && !$item instanceof ContextEdit;
    }

    /**
     * What this branch was being talked on: the model, and how hard it was thinking.
     *
     * Walked from the root down, so the last one said wins. The model falls back to whatever
     * answered last, exactly as pi's does — an assistant message carries the provider and the
     * model that produced it, so a conversation records what it was had with even if nobody
     * ever changed it on purpose.
     *
     * Except under a virtual model (upstream's `getBranchSelection()`): its responses name the
     * physical models it routed to, so a virtual `ModelChange` holds over the responses after it
     * until the next `ModelChange`. One that is no longer registered does not hold, and the
     * selection falls back to the physical model that answered last. A response naming a virtual
     * model is a failed routing and never a selection.
     *
     * @return array{model: ?ModelChange, thinking: ?ThinkingLevelChange}
     */
    public function settings(): array
    {
        $model = null;
        $thinking = null;
        $cur = $this->leaf;

        // Traverse backward from leaf to root: the newest changes win, and we can stop
        // as soon as both settings have been resolved, skipping thousands of earlier entries.
        while ($cur !== null && isset($this->entries[$cur])) {
            $item = $this->entries[$cur]['message'];

            if ($model === null) {
                if ($item instanceof ModelChange) {
                    $model = $item;
                } elseif ($item instanceof AssistantMessage && $item->model !== '' && $item->api !== Api::Virtual) {
                    $change = $this->lastModelChangeBefore($cur);
                    $selected = $change === null ? null : Models::find($change->provider, $change->modelId);
                    $model = $change !== null && $selected !== null && Models::isVirtual($selected)
                        ? $change
                        : new ModelChange($item->provider, $item->model, $item->timestamp);
                }
            }

            if ($thinking === null && $item instanceof ThinkingLevelChange) {
                $thinking = $item;
            }

            if ($model !== null && $thinking !== null) {
                break;
            }

            $cur = $this->entries[$cur]['parent'];
        }

        return ['model' => $model, 'thinking' => $thinking];
    }

    /** The nearest `ModelChange` above entry $id, or null when nothing above it changed the model. */
    private function lastModelChangeBefore(string $id): ?ModelChange
    {
        $cur = $this->entries[$id]['parent'];

        while ($cur !== null && isset($this->entries[$cur])) {
            $item = $this->entries[$cur]['message'];

            if ($item instanceof ModelChange) {
                return $item;
            }

            $cur = $this->entries[$cur]['parent'];
        }

        return null;
    }

    /**
     * The entry ids from the root down to $id.
     *
     * @return list<string>
     */
    private function pathTo(?string $id): array
    {
        $path = [];

        // O(1) append in loop + single O(N) reverse at the end, replacing O(N^2) array_unshift.
        while ($id !== null && isset($this->entries[$id])) {
            $path[] = $id;
            $id = $this->entries[$id]['parent'];
        }

        return array_reverse($path);
    }

    /** How many entries call $parent their parent — two or more means a fork. */
    private function childCount(?string $parent): int
    {
        $count = 0;

        foreach ($this->entries as $entry) {
            if ($entry['parent'] === $parent) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A path of entries as the conversation it stands for.
     *
     * A compaction summary replaces the messages before it rather than following them,
     * and the file still holds those messages — so the replacement happens here, on the
     * way out, every time. Doing it once at load would be wrong the moment a branch was
     * taken from before the compaction.
     *
     * @param list<array{0: string, 1: mixed}> $path
     * @return list<array{0: ?string, 1: mixed}>
     */
    private static function resolve(array $path): array
    {
        // pi's `buildSessionProjection()`: the latest `context_edit` on this path for each target
        // decides what the model is shown of it. Collected first because an edit sits *after* the
        // message it edits, and branch-relative by construction — only edits on this path count.
        $edits = [];

        foreach ($path as [, $item]) {
            if ($item instanceof ContextEdit) {
                $edits[$item->targetId] = $item;
            }
        }

        $messages = [];

        foreach ($path as [$id, $item]) {
            if (!self::isSaid($item)) {
                continue;
            }

            if ($item instanceof CompactionSummary) {
                $kept = self::edited(self::keptFrom($path, $item->firstKeptEntryId, $id), $edits);

                // Replaced, not seen: what came before minus what is being kept. Counting
                // everything before the compaction would say a summary that kept the last
                // four messages had replaced them too. System messages are prompt state rather
                // than conversation, and not counted on either side.
                $said = count(array_filter($messages, static fn (array $one): bool => !$one[1] instanceof SystemMessage));

                // pi's `sessionEntryToContextMessages()` for a compaction: the prompt and tool state
                // it recorded, then the summary — both from this one entry.
                $messages = [
                    ...($item->systemMessage !== null ? [[$id, $item->systemMessage]] : []),
                    [$id, self::withCount($item, $said - count($kept))],
                    ...$kept,
                ];

                continue;
            }

            if ($edits !== [] && isset($edits[$id])) {
                $edit = $edits[$id];

                if ($edit->replacement === null) {
                    continue;
                }

                $replaced = self::withContent($item, $edit->replacement);

                if ($replaced !== null) {
                    $messages[] = [$id, $replaced];
                }
            } else {
                $messages[] = [$id, $item];
            }
        }

        return $messages;
    }

    /**
     * Apply the edits to a run of messages: an omitted target produces nothing, a replaced one
     * keeps its role and metadata with the new content.
     *
     * @param list<array{0: string, 1: mixed}> $messages
     * @param array<string, ContextEdit>       $edits
     * @return list<array{0: string, 1: mixed}>
     */
    private static function edited(array $messages, array $edits): array
    {
        if ($edits === []) {
            return $messages;
        }

        $out = [];

        foreach ($messages as [$id, $item]) {
            $edit = $edits[$id] ?? null;

            if ($edit === null) {
                $out[] = [$id, $item];
                continue;
            }

            if ($edit->replacement === null) {
                continue;
            }

            $replaced = self::withContent($item, $edit->replacement);

            if ($replaced !== null) {
                $out[] = [$id, $replaced];
            }
        }

        return $out;
    }

    /**
     * The same message with other content. A string becomes one text block — pi's rule for the
     * roles whose content has to be an array — and a message of a kind that has no content to
     * replace is left as it was rather than guessed at.
     *
     * @param string|list<\Pig\Ai\Content> $replacement
     */
    private static function withContent(mixed $message, string|array $replacement): mixed
    {
        $content = is_string($replacement) ? [new TextContent($replacement)] : $replacement;

        return match (true) {
            $message instanceof UserMessage => new UserMessage($content, $message->timestamp),
            $message instanceof AssistantMessage => new AssistantMessage(
                $content,
                $message->api,
                $message->provider,
                $message->model,
                $message->usage,
                $message->stopReason,
                $message->errorMessage,
                $message->timestamp,
                $message->rawStopReason,
                $message->responseId,
                $message->responseModel,
                $message->endTurn,
                $message->diagnostics,
                $message->providerThinkingLevel,
                $message->deferred,
                $message->thinkingLevel,
            ),
            $message instanceof ToolResultMessage => new ToolResultMessage(
                $message->toolCallId,
                $message->toolName,
                $content,
                $message->isError,
                $message->details,
                $message->timestamp,
                $message->usage,
            ),
            $message instanceof HookMessage => new HookMessage(
                $message->customType,
                $content,
                $message->display,
                $message->details,
                $message->timestamp,
            ),
            default => $message,
        };
    }

    /**
     * What a compaction keeps: everything from `firstKeptEntryId` up to the compaction.
     *
     * pi's model, and the reason pig's changed: a count of replaced messages says the same
     * thing from the other end and is not something pi can read. Null keeps nothing, which
     * is a summary that stands in for the whole conversation before it.
     *
     * @param list<array{0: string, 1: mixed}> $path
     * @return list<array{0: string, 1: mixed}>
     */
    private static function keptFrom(array $path, ?string $firstKept, string $until): array
    {
        if ($firstKept === null) {
            return [];
        }

        $kept = [];
        $keeping = false;

        foreach ($path as [$id, $item]) {
            if ($id === $until) {
                break;
            }

            $keeping = $keeping || $id === $firstKept;

            // pi's `buildContextEntries()` leaves the kept part's system messages out: the
            // compaction's own `systemMessage` already holds their replay.
            if ($keeping && self::isSaid($item) && !$item instanceof CompactionSummary && !$item instanceof SystemMessage) {
                $kept[] = [$id, $item];
            }
        }

        return $kept;
    }

    /**
     * The same summary with `replaced` filled in.
     *
     * Derived here rather than stored, because the file holds `firstKeptEntryId` and a file
     * that held both could disagree with itself. It is only ever a line on a screen —
     * "1,204 earlier messages summarised".
     *
     */
    private static function withCount(CompactionSummary $summary, int $replaced): CompactionSummary
    {
        return new CompactionSummary(
            $summary->summary,
            $summary->readFiles,
            $summary->modifiedFiles,
            $summary->tokensBefore,
            $summary->firstKeptEntryId,
            max(0, $replaced),
            $summary->timestamp,
            $summary->fromHook,
            $summary->systemMessage,
        );
    }

    /**
     * Add a message, and write it.
     *
     * Kept in memory as well as on disk because a resumed session hands these straight
     * to the agent, and re-reading the file to answer that would be the same list twice.
     */
    public function append(mixed $message): void
    {
        $id = self::newId($this->entries);
        $entry = SessionEntries::encode($message, $id, $this->leaf);

        if ($entry === null) {
            return;
        }

        $this->entries[$id] = ['message' => $message, 'parent' => $this->leaf];
        $this->leaf = $id;

        if (!$this->started && !$this->worthKeeping($message)) {
            return;
        }

        $this->write($entry);
    }

    /**
     * Edit what the model is shown of an earlier entry, without touching the entry — pi's
     * `appendContextEdit()`. Null omits it; a string or content list replaces its content.
     *
     * Refused for an id this file has not got, as a label is: it would be a line nobody reads
     * back, and the mistake is in the caller.
     */
    public function appendContextEdit(string $targetId, string|array|null $replacement): void
    {
        if (!isset($this->entries[$targetId])) {
            throw new AgentError("No entry {$targetId} to edit the context of.");
        }

        $this->append(new ContextEdit($targetId, $replacement));
    }

    /**
     * The id of the entry holding exactly this message object, or null.
     *
     * Identity and not equality: two turns can say the same words, and the one being asked
     * about is the one the agent's state holds — the very object `append()` was handed.
     */
    public function entryOf(mixed $message): ?string
    {
        foreach ($this->entries as $id => $entry) {
            if ($entry['message'] === $message) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * A hook's own note, which is not part of the conversation.
     *
     * Written like anything else and read back by `customEntries()`, but kept out of the
     * tree and out of `messages()`: it costs no context and the model never sees it. What
     * it is for is state a hook wants to find again after a restart.
     *
     * It does not make the session worth keeping on its own. A hook that notes something
     * before the first answer has not turned "someone opened pig and changed their mind"
     * into a conversation, and leaving a file behind for it would fill the sessions
     * directory with sessions nobody had.
     */
    /**
     * The name somebody put on this point, if there is one.
     *
     * Collected in **file order, not branch order**, which is upstream's rule and the right
     * one: a label names an entry by id, and that entry can be on any branch. Reading them
     * off the current branch would make a label vanish and come back as you moved around.
     */
    public function labelOf(string $entryId): ?string
    {
        return $this->labels[$entryId] ?? null;
    }

    /**
     * Name a point, or clear its name with null.
     *
     * The entry has to exist: a label on an id nothing has is a line nobody will ever read
     * back, and the mistake is in the caller.
     *
     * @throws AgentError when there is no such entry
     */
    public function appendLabel(string $entryId, ?string $label): void
    {
        if (!isset($this->entries[$entryId])) {
            throw new AgentError('No such point in this conversation.');
        }

        $label = $label === null || trim($label) === '' ? null : trim($label);

        $this->append(new Label($entryId, $label));

        if ($label === null) {
            unset($this->labels[$entryId]);

            return;
        }

        $this->labels[$entryId] = $label;
    }

    /**
     * Clean a raw session name, stripping any dynamic telemetry metrics suffixes like " • ⚡ ...".
     */
    public static function cleanSessionName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $cleaned = (string) preg_replace('/\s*•\s*⚡.*$/u', '', $name);
        $cleaned = trim($cleaned);
        if (str_starts_with($cleaned, '⚡')) {
            return null;
        }

        return $cleaned !== '' ? $cleaned : null;
    }

    /** The friendly display name of the session, or null if unset. */
    public function sessionName(): ?string
    {
        return $this->sessionName;
    }

    public function getSessionName(): ?string
    {
        return $this->sessionName;
    }

    /**
     * Set or clear the session display name.
     * Appends a SessionInfoEntry to the file and updates memory state.
     */
    public function setSessionName(string $name): void
    {
        $clean = self::cleanSessionName($name) ?? '';
        $this->sessionName = $clean !== '' ? $clean : null;
        $this->append(new SessionInfoEntry($clean));
    }

    /** Record that the model changed here, so resuming this conversation comes back to it. */
    public function appendModelChange(string $provider, string $modelId): void
    {
        $this->append(new ModelChange($provider, $modelId));
    }

    /** The same for the thinking level. */
    public function appendThinkingLevelChange(string $level): void
    {
        $this->append(new ThinkingLevelChange($level));
    }

    public function appendCustomEntry(string $customType, mixed $data = null): void
    {
        $entry = new CustomEntry($customType, $data);

        // In the tree, as pi's is: it has an id and a parent like every other line, and
        // `messages()` walks past it rather than the tree not knowing about it. pig kept
        // these outside the tree at first, which wrote a line pi could not place.
        $this->append($entry);
    }

    /**
     * Every note a hook left, oldest first, optionally just one kind.
     *
     * @return list<CustomEntry>
     */
    public function customEntries(?string $customType = null): array
    {
        $found = [];

        // Read off the branch being talked on, not off a list of its own: a note written on
        // a branch that was abandoned is not a note about this conversation, and a hook
        // reconstructing its state from it would rebuild something that was undone.
        foreach ($this->pathTo($this->leaf) as $id) {
            $entry = $this->entries[$id]['message'];

            if ($entry instanceof CustomEntry && ($customType === null || $entry->customType === $customType)) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $taken
     */
    private static function newId(array $taken): string
    {
        // Eight characters off the front of a UUID, as upstream's `generateId()` does.
        // Short enough to read in a file and long enough that the retry almost never runs.
        do {
            $id = substr(self::uuid(), 0, 8);
        } while (isset($taken[$id]));

        return $id;
    }

    /**
     * Whether there is now enough here to be worth a file.
     *
     * The first assistant message is the line: before it, nothing has been answered and
     * the session is someone who opened pig and changed their mind.
     */
    private function worthKeeping(mixed $message): bool
    {
        return $message instanceof AssistantMessage;
    }

    /** @param array<string, mixed> $entry */
    private function write(array $entry): void
    {
        if ($this->preview) {
            return;
        }

        $lines = '';

        if (!$this->started) {
            // Everything before the first assistant message was held back; it goes out
            // now, in front, so the file reads in the order it happened.
            // Everything held back so far, in the order it was appended and with the ids
            // and parents it was given — so the tree in memory is the tree the file
            // describes. In order because that is the order it happened: notes and messages
            // go through one `append()` now, so there is nothing left to interleave.
            $lines .= $this->prologue(array_slice($this->entries, 0, -1, true));
            $this->started = true;
        }

        $lines .= self::line($entry);

        $this->appendLines($lines);
    }

    /**
     * The header line, then $entries as they stand — what the file opens with.
     *
     * @param array<string, array{message: mixed, parent: string|null}> $entries
     */
    private function prologue(array $entries): string
    {
        $header = [
            'type' => 'session',
            'version' => self::VERSION,
            'id' => $this->id,
            'timestamp' => SessionEntries::iso($this->createdAt),
            'cwd' => $this->cwd,
        ];

        // Only on a fork, as pi writes it: a key that is absent and a key that is null read the
        // same, and pi's own header has no key when there is no parent.
        if ($this->parentSession !== null) {
            $header['parentSession'] = $this->parentSession;
        }

        $lines = self::line($header);

        foreach ($entries as $id => $entry) {
            // Cast, because PHP turns an array key that looks like an integer into one:
            // an id is eight hex characters and about one in forty is all digits, so
            // `"12345678"` comes back out of this loop as `12345678`. See CLAUDE.md.
            $encoded = SessionEntries::encode($entry['message'], (string) $id, $entry['parent']);

            if ($encoded !== null) {
                $lines .= self::line($encoded);
            }
        }

        return $lines;
    }

    private function appendLines(string $lines): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new AgentError("Could not create {$directory}");
        }

        if (file_put_contents($this->path, $lines, FILE_APPEND | LOCK_EX) === false) {
            throw new AgentError("Could not write the session to {$this->path}");
        }
    }

    /** @param array<string, mixed> $entry */
    private static function line(array $entry): string
    {
        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    // ---- finding one again --------------------------------------------------------------

    /** Where $cwd's sessions live: one directory per project, named after its path. */
    public static function directory(string $cwd, ?string $home = null): string
    {
        return ($home ?? Config::home()) . '/sessions/' . self::slug($cwd);
    }

    /**
     * A directory name for a working directory, exactly as pi builds one.
     *
     * The leading separator goes, every `/`, `\\` and `:` becomes a dash, and the whole
     * thing is wrapped in `--`. pig used to squash every run of unusual characters into
     * one dash and not wrap it, which named the same project two different things — so
     * pointing pig at pi's directory would have found nothing.
     */
    public static function slug(string $cwd): string
    {
        $flat = (string) preg_replace('#^[/\\\\]#', '', $cwd);
        $flat = (string) preg_replace('#[/\\\\:]#', '-', $flat);

        return '--' . $flat . '--';
    }

    /**
     * The sessions for $cwd, newest first.
     *
     * @return list<SessionInfo>
     */
    public static function listFor(string $cwd): array
    {
        // pi's directory as well as pig's. The file format is the same now, so a
        // conversation started in one opens in the other — and somebody who has been using
        // pi should not have to go and find the file by hand.
        $paths = [
            ...(glob(self::directory($cwd) . '/*.jsonl') ?: []),
            ...(glob(self::directory($cwd, Config::piHome()) . '/*.jsonl') ?: []),
        ];

        // **Newest is when it was last written to, not when it was started**, which is
        // upstream's `findMostRecentSession` and is the only reading of "newest" that answers
        // what `--continue` asks. A file is named after the moment the conversation began and
        // is never renamed, so the name says when it *started*: resume Monday's conversation on
        // Wednesday and a name sort still puts Tuesday's in front of it, so `--continue` opens
        // the one conversation somebody demonstrably was not working on.
        //
        // The name breaks a tie, so two files written in the same second have a fixed order
        // rather than whatever the directory happened to hand over. A path that has gone
        // between the glob and the stat is not a session to list.
        $times = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $times[$path] = filemtime($path);
            }
        }

        $paths = array_keys($times);

        usort(
            $paths,
            static fn (string $a, string $b): int
                => [$times[$b], basename($b)] <=> [$times[$a], basename($a)],
        );

        $sessions = [];

        foreach (array_slice($paths, 0, self::LISTED) as $path) {
            $info = self::describe($path);

            if ($info !== null) {
                $sessions[] = $info;
            }
        }

        return $sessions;
    }

    /**
     * Find the path to the newest session file for $cwd by mtime without parsing full file bodies.
     * Matches upstream pi's findMostRecentSession().
     */
    public static function latestPathFor(string $cwd): ?string
    {
        $paths = [
            ...(glob(self::directory($cwd) . '/*.jsonl') ?: []),
            ...(glob(self::directory($cwd, Config::piHome()) . '/*.jsonl') ?: []),
        ];

        $times = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $times[$path] = filemtime($path);
            }
        }

        $paths = array_keys($times);

        usort(
            $paths,
            static fn (string $a, string $b): int
                => [$times[$b], basename($b)] <=> [$times[$a], basename($a)],
        );

        foreach ($paths as $path) {
            $fh = fopen($path, 'r');
            if ($fh === false) {
                continue;
            }

            $firstLine = fgets($fh);
            fclose($fh);

            if ($firstLine === false) {
                continue;
            }

            $header = json_decode(trim($firstLine), true);

            if (is_array($header) && ($header['type'] ?? null) === 'session') {
                return $path;
            }
        }

        return null;
    }

    public static function latestFor(string $cwd): ?SessionInfo
    {
        $path = self::latestPathFor($cwd);

        return $path !== null ? self::describe($path) : null;
    }

    /**
     * Find a session file by its exact ID, prefix, or path.
     *
     * Matches upstream pi's resolveSessionPath():
     * 1. Direct path: if it contains a slash or ends in .jsonl, resolve as a file path.
     * 2. Local exact or prefix match: search the project's session directory in pig and pi.
     * 3. Global match: search all projects' session directories in pig and pi.
     */
    public static function find(string $cwd, string $sessionArg): ?string
    {
        $sessionArg = trim($sessionArg);

        if ($sessionArg === '') {
            return null;
        }

        // Direct path with directory separators: resolve relative to cwd or as absolute path
        if (str_contains($sessionArg, '/') || str_contains($sessionArg, '\\')) {
            if (is_file($sessionArg)) {
                return $sessionArg;
            }

            $local = $cwd . '/' . $sessionArg;

            if (is_file($local)) {
                return $local;
            }

            return null;
        }

        // Bare filename ending in .jsonl: check cwd first
        if (str_ends_with($sessionArg, '.jsonl')) {
            if (is_file($sessionArg)) {
                return $sessionArg;
            }

            $local = $cwd . '/' . $sessionArg;

            if (is_file($local)) {
                return $local;
            }
        }

        // Local project session directory (pig and pi)
        $localDirs = [
            self::directory($cwd),
            self::directory($cwd, Config::piHome()),
        ];

        foreach ($localDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            // 0. Direct filename match inside project session directory (e.g. "2026-10-04...jsonl")
            if (str_ends_with($sessionArg, '.jsonl') && is_file($dir . '/' . $sessionArg)) {
                return $dir . '/' . $sessionArg;
            }

            // 1. Exact ID match (files are named {timestamp}_{id}.jsonl)
            $exact = glob($dir . '/*_' . $sessionArg . '.jsonl') ?: [];

            if ($exact !== []) {
                return $exact[0];
            }

            // 2. Prefix match
            $prefix = glob($dir . '/*_' . $sessionArg . '*.jsonl') ?: [];

            if ($prefix !== []) {
                return $prefix[0];
            }
        }

        // Search through recent session entries in case of custom or migrated filenames
        foreach (self::listFor($cwd) as $session) {
            if ($session->id === $sessionArg || str_starts_with($session->id, $sessionArg)) {
                return $session->path;
            }
        }

        // Global search across all project session directories
        $globalRoots = [
            Config::home() . '/sessions',
            Config::piHome() . '/sessions',
        ];

        foreach ($globalRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $exact = glob($root . '/*/*_' . $sessionArg . '.jsonl') ?: [];

            if ($exact !== []) {
                return $exact[0];
            }

            $prefix = glob($root . '/*/*_' . $sessionArg . '*.jsonl') ?: [];

            if ($prefix !== []) {
                return $prefix[0];
            }
        }

        return null;
    }

    /** Find an exact session ID without loading transcript bodies. */
    public static function findById(string $cwd, string $id): ?string
    {
        return self::find($cwd, $id);
    }

    /**
     * Enough about a file to choose it from a list, without decoding every message.
     *
     * The first thing the person said is the label, because that is how anyone
     * remembers a conversation — not by its id or the time it started.
     */
    private static function describe(string $path): ?SessionInfo
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return null;
        }

        $firstLine = fgets($fh);
        if ($firstLine === false) {
            fclose($fh);
            return null;
        }

        $header = json_decode(trim($firstLine), true);

        if (!is_array($header) || ($header['type'] ?? null) !== 'session') {
            fclose($fh);
            return null;
        }

        $opening = '';
        $sessionName = null;
        $messages = 0;
        $said = [];
        $totalSaidLength = 0;

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) === 'session_info' && isset($entry['name']) && is_string($entry['name'])) {
                $clean = self::cleanSessionName($entry['name']);
                if ($clean !== null) {
                    $sessionName = $clean;
                }
            }

            // Read without decoding: this runs once per file for every session in a list,
            // and all it needs is a count, the first thing anybody said, and the words to
            // search. Decoding every message of thirty conversations to get at their text
            // would be the slow half of starting with `--resume`.
            if (($entry['type'] ?? null) !== 'message' || !is_array($entry['message'] ?? null)) {
                continue;
            }

            $messages++;

            if ($opening === '' && ($entry['message']['role'] ?? null) === 'user') {
                $opening = self::opening($entry['message']);
            }

            // Upstream's rule: the two roles somebody would remember a phrase from. A tool
            // result is usually a file, and a search that matched the contents of every file
            // ever read would match everything.
            if ($totalSaidLength < 65536 && in_array($entry['message']['role'] ?? null, ['user', 'assistant'], true)) {
                $text = self::said($entry['message']);

                if ($text !== '') {
                    $said[] = $text;
                    $totalSaidLength += strlen($text);
                }
            }
        }

        fclose($fh);

        return new SessionInfo(
            $path,
            (string) ($header['id'] ?? ''),
            (string) ($header['cwd'] ?? ''),
            SessionEntries::millis($header['timestamp'] ?? null),
            $messages,
            $sessionName ?? ($opening === '' ? 'a conversation' : $opening),
            implode(' ', $said),
        );
    }

    /**
     * The text blocks of one message, run together.
     *
     * Thinking is left out along with everything else that is not a text block: it is the
     * model talking to itself, and a search that hit it would find conversations by something
     * nobody ever read.
     *
     * @param array<string, mixed> $message
     */
    private static function said(array $message): string
    {
        $content = $message['content'] ?? null;

        // pi writes a list of blocks and accepts a bare string, so both arrive here.
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return implode(' ', $texts);
    }

    /** @param array<string, mixed> $entry */
    private static function opening(array $entry): string
    {
        $message = SessionCodec::decode($entry);

        if (!$message instanceof UserMessage) {
            return '';
        }

        foreach ($message->content as $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                return trim((string) preg_replace('/\s+/u', ' ', $block->text));
            }
        }

        return '';
    }
}
