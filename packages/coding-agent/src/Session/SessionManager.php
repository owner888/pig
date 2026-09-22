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
    private const int VERSION = 1;

    /** How many to offer in a list before it stops being a list. */
    private const int LISTED = 30;

    /** @var list<mixed> everything appended so far, in order */
    private array $messages = [];

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

    /** Read one back. Its messages are in `messages()`. */
    public static function open(string $path): self
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
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

        foreach (array_slice($lines, 1) as $line) {
            $entry = json_decode($line, true);
            $message = is_array($entry) ? SessionCodec::decode($entry) : null;

            if ($message !== null) {
                $session->messages[] = $message;
            }
        }

        return $session;
    }

    /** @return list<mixed> */
    public function messages(): array
    {
        return $this->messages;
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

        $this->messages[] = $message;

        if (!$this->started && !$this->worthKeeping($message)) {
            return;
        }

        $this->write($entry);
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

            foreach (array_slice($this->messages, 0, -1) as $earlier) {
                $encoded = SessionCodec::encode($earlier);

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
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false || $lines === []) {
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
