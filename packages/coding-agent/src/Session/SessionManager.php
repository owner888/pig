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
        $id = bin2hex(random_bytes(8));
        $now = Timestamp::nowMs();

        return new self(
            $path ?? self::directory($cwd) . '/' . date('Y-m-d-His', intdiv($now, 1000)) . '-' . $id . '.jsonl',
            $id,
            $cwd,
            $now,
        );
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
            (int) ($header['timestamp'] ?? 0),
        );

        $session->started = true;
        $previous = null;

        foreach (array_slice($lines, 1) as $line) {
            $entry = json_decode($line, true);
            $message = is_array($entry) ? SessionCodec::decode($entry) : null;

            if ($message === null) {
                continue;
            }

            // A file written before the tree has no ids. Read linearly, each entry is
            // the child of the one before it, which is the same conversation the old
            // format described — so an old session opens as a tree with no branches.
            $id = (string) ($entry['entryId'] ?? self::newId($session->entries));
            $parent = array_key_exists('parent', $entry) ? $entry['parent'] : $previous;

            $session->entries[$id] = [
                'message' => $message,
                'parent' => is_string($parent) ? $parent : null,
            ];

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
        $id = $this->leaf;

        while ($id !== null && isset($this->entries[$id])) {
            array_unshift($path, $this->entries[$id]['message']);
            $id = $this->entries[$id]['parent'];
        }

        return self::resolve($path);
    }

    /**
     * Every point on this branch that could be gone back to, oldest first.
     *
     * The ids as well as the messages, because going back means naming one.
     *
     * @return list<array{id: string, message: mixed, branches: int}>
     */
    public function branch(): array
    {
        $path = [];
        $id = $this->leaf;

        while ($id !== null && isset($this->entries[$id])) {
            array_unshift($path, [
                'id' => $id,
                'message' => $this->entries[$id]['message'],
                'branches' => $this->childCount($this->entries[$id]['parent']),
            ]);
            $id = $this->entries[$id]['parent'];
        }

        return $path;
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
     * @param list<mixed> $path
     * @return list<mixed>
     */
    private static function resolve(array $path): array
    {
        $messages = [];

        foreach ($path as $message) {
            if ($message instanceof CompactionSummary && $message->replaced > 0) {
                array_splice($messages, 0, $message->replaced, [$message]);

                continue;
            }

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * Add a message, and write it.
     *
     * Kept in memory as well as on disk because a resumed session hands these straight
     * to the agent, and re-reading the file to answer that would be the same list twice.
     */
    public function append(mixed $message): void
    {
        $entry = SessionCodec::encode($message);

        if ($entry === null) {
            return;
        }

        $id = self::newId($this->entries);
        $entry['entryId'] = $id;
        $entry['parent'] = $this->leaf;

        $this->entries[$id] = ['message' => $message, 'parent' => $this->leaf];
        $this->leaf = $id;

        if (!$this->started && !$this->worthKeeping($message)) {
            return;
        }

        $this->write($entry);
    }

    /**
     * @param array<string, mixed> $taken
     */
    private static function newId(array $taken): string
    {
        do {
            $id = bin2hex(random_bytes(6));
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
                'cwd' => $this->cwd,
                'timestamp' => $this->createdAt,
            ]);

            // Everything held back so far, in the order it was appended, with the ids
            // and parents it was given — so the tree that is in memory is the tree the
            // file describes.
            foreach (array_slice($this->entries, 0, -1, true) as $id => $earlier) {
                $encoded = SessionCodec::encode($earlier['message']);

                if ($encoded !== null) {
                    $lines .= self::line([...$encoded, 'entryId' => $id, 'parent' => $earlier['parent']]);
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
    public static function directory(string $cwd): string
    {
        // The path is the name, with the separators flattened — so the directory says
        // which project it belongs to at a glance, which a hash never would.
        $slug = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $cwd), '-');

        return Config::home() . '/sessions/' . ($slug === '' ? 'root' : $slug);
    }

    /**
     * The sessions for $cwd, newest first.
     *
     * @return list<SessionInfo>
     */
    public static function listFor(string $cwd): array
    {
        $paths = glob(self::directory($cwd) . '/*.jsonl') ?: [];
        rsort($paths);

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

        foreach (array_slice($lines, 1) as $line) {
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                continue;
            }

            $messages++;

            if ($opening === '' && ($entry['role'] ?? null) === 'user') {
                $opening = self::opening($entry);
            }
        }

        return new SessionInfo(
            $path,
            (string) ($header['id'] ?? ''),
            (string) ($header['cwd'] ?? ''),
            (int) ($header['timestamp'] ?? 0),
            $messages,
            $opening,
        );
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
