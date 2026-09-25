<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\AgentError;
use Pig\Ai\AssistantMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Config;

/**
 * The conversation on disk, one JSON object per line.
 *
 * Appended to rather than rewritten, so a session survives whatever ends the process —
 * a crash, a closed laptop, Ctrl+C twice. Reading one back is reading the file top to
 * bottom; there is no state to reconstruct beyond the messages themselves.
 *
 * Ported from upstream's `session-manager.ts`, which is 1129 lines to this one's ~200.
 * The difference is the tree: upstream gives every entry a parent, which is what makes
 * `/branch` and `/tree` possible — going back to an earlier message and taking the
 * conversation somewhere else. That needs a UI to navigate it and a compaction system
 * that understands branches, neither of which is ported, so the log here is a line. The
 * file format is upstream's all the same, so adding the parent later is adding a field.
 *
 * Also not ported: labels, session migrations between format versions, and the separate
 * entry types for a model or thinking-level change.
 */
final class SessionManager
{
    /** Bumped when the format changes in a way an older pig could not read. */
    private const int VERSION = 2;

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
    ) {
    }

    /** A session that will be written to $cwd's directory once it has something to say. */
    public static function create(string $cwd, ?string $path = null): self
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
        );
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
        $lines = self::lines($path);

        if ($lines === null) {
            throw new AgentError("Could not read the session at {$path}");
        }

        $header = json_decode($lines[0] ?? '', true);

        if (!is_array($header) || ($header['type'] ?? null) !== 'session') {
            throw new AgentError("Not a pig session file: {$path}");
        }

        $session = new self(
            $path,
            (string) ($header['id'] ?? ''),
            (string) ($header['cwd'] ?? ''),
            SessionEntries::millis($header['timestamp'] ?? null),
        );

        $session->started = true;
        $previous = null;

        foreach (array_slice($lines, 1) as $line) {
            $raw = json_decode($line, true);

            if (!is_array($raw)) {
                continue;
            }

            // A line pig has nothing to do with — `thinking_level_change`, `model_change`,
            // `label`, all pi's — is kept in the tree as a node with nothing in it rather
            // than skipped. **Skipping it broke the chain**: the entries after it name it
            // as their parent, so walking back from the leaf stopped there and a pi
            // conversation came back as its first message and nothing else. It is walked
            // past on the way out instead, where it costs nothing.
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
            }

            $previous = $id;
        }

        // The end of the file is the end of the branch that was being talked on: a
        // branch is made by appending, so the newest entry is always on it.
        $session->leaf = $previous;

        return $session;
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
            && !$item instanceof Label;
    }

    /**
     * What this branch was being talked on: the model, and how hard it was thinking.
     *
     * Walked from the root down, so the last one said wins. The model falls back to whatever
     * answered last, exactly as pi's does — an assistant message carries the provider and the
     * model that produced it, so a conversation records what it was had with even if nobody
     * ever changed it on purpose.
     *
     * @return array{model: ?ModelChange, thinking: ?ThinkingLevelChange}
     */
    public function settings(): array
    {
        $model = null;
        $thinking = null;

        foreach ($this->pathTo($this->leaf) as $id) {
            $item = $this->entries[$id]['message'];

            if ($item instanceof ModelChange) {
                $model = $item;
            } elseif ($item instanceof ThinkingLevelChange) {
                $thinking = $item;
            } elseif ($item instanceof AssistantMessage && $item->model !== '') {
                $model = new ModelChange($item->provider, $item->model, $item->timestamp);
            }
        }

        return ['model' => $model, 'thinking' => $thinking];
    }

    /**
     * The entry ids from the root down to $id.
     *
     * @return list<string>
     */
    private function pathTo(?string $id): array
    {
        $path = [];

        while ($id !== null && isset($this->entries[$id])) {
            array_unshift($path, $id);
            $id = $this->entries[$id]['parent'];
        }

        return $path;
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
        $messages = [];

        foreach ($path as [$id, $item]) {
            if (!self::isSaid($item)) {
                continue;
            }

            if ($item instanceof CompactionSummary) {
                $kept = self::keptFrom($path, $item->firstKeptEntryId, $id);

                // Replaced, not seen: what came before minus what is being kept. Counting
                // everything before the compaction would say a summary that kept the last
                // four messages had replaced them too.
                $messages = [[$id, self::withCount($item, count($messages) - count($kept))], ...$kept];

                continue;
            }

            $messages[] = [$id, $item];
        }

        return $messages;
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

            if ($keeping && self::isSaid($item) && !$item instanceof CompactionSummary) {
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
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new AgentError("Could not create {$directory}");
        }

        $lines = '';

        if (!$this->started) {
            // Everything before the first assistant message was held back; it goes out
            // now, in front, so the file reads in the order it happened.
            $lines .= self::line([
                'type' => 'session',
                'version' => self::VERSION,
                'id' => $this->id,
                'timestamp' => SessionEntries::iso($this->createdAt),
                'cwd' => $this->cwd,
            ]);

            // Everything held back so far, in the order it was appended and with the ids
            // and parents it was given — so the tree in memory is the tree the file
            // describes. In order because that is the order it happened: notes and messages
            // go through one `append()` now, so there is nothing left to interleave.
            foreach (array_slice($this->entries, 0, -1, true) as $id => $earlier) {
                // Cast, because PHP turns an array key that looks like an integer into one:
                // an id is eight hex characters and about one in forty is all digits, so
                // `"12345678"` comes back out of this loop as `12345678`. See CLAUDE.md.
                $encoded = SessionEntries::encode($earlier['message'], (string) $id, $earlier['parent']);

                if ($encoded !== null) {
                    $lines .= self::line($encoded);
                }
            }

            $this->started = true;
        }

        $lines .= self::line($entry);

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
        // pi should not have to go and find the file by hand. Newest first across both,
        // which the names sort into by themselves: they begin with an ISO timestamp.
        $paths = [
            ...(glob(self::directory($cwd) . '/*.jsonl') ?: []),
            ...(glob(self::directory($cwd, Config::piHome()) . '/*.jsonl') ?: []),
        ];

        usort($paths, static fn (string $a, string $b): int => basename($b) <=> basename($a));

        $sessions = [];

        foreach (array_slice($paths, 0, self::LISTED) as $path) {
            $info = self::describe($path);

            if ($info !== null) {
                $sessions[] = $info;
            }
        }

        return $sessions;
    }

    public static function latestFor(string $cwd): ?SessionInfo
    {
        return self::listFor($cwd)[0] ?? null;
    }

    /**
     * Enough about a file to choose it from a list, without decoding every message.
     *
     * The first thing the person said is the label, because that is how anyone
     * remembers a conversation — not by its id or the time it started.
     */
    private static function describe(string $path): ?SessionInfo
    {
        $lines = self::lines($path);

        if ($lines === null || $lines === []) {
            return null;
        }

        $header = json_decode($lines[0], true);

        if (!is_array($header) || ($header['type'] ?? null) !== 'session') {
            return null;
        }

        $opening = '';
        $messages = 0;
        $said = [];

        foreach (array_slice($lines, 1) as $line) {
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                continue;
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
            if (in_array($entry['message']['role'] ?? null, ['user', 'assistant'], true)) {
                $text = self::said($entry['message']);

                if ($text !== '') {
                    $said[] = $text;
                }
            }
        }

        return new SessionInfo(
            $path,
            (string) ($header['id'] ?? ''),
            (string) ($header['cwd'] ?? ''),
            SessionEntries::millis($header['timestamp'] ?? null),
            $messages,
            $opening,
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
