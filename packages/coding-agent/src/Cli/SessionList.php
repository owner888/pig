<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\ToolProgress;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Component;
use Pig\Tui\Components\Input;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\Keys;
use Pig\Tui\Width;

/**
 * The list of earlier conversations, with a search box over it.
 *
 * Upstream's `components/session-selector.ts` — `SessionSelectorHeader`, `SessionList` and the
 * `SessionSelectorComponent` around them, folded into one component, because pig's two callers
 * (`--resume` before the screen exists, `/resume` inside it) both want all three and nothing else.
 *
 * What it does, key by key, every key read through the keybinding registry:
 *
 * - **Typing searches** (`SessionSearch`: words, `"phrases"`, `re:<pattern>`); up, down, page up and
 *   page down move, Enter opens, Escape is `setCancelHandler()`'s and Ctrl+C `setQuitHandler()`'s —
 *   upstream's `onCancel` and `onExit`, two keys that mean "not this session" and "not pig".
 * - **`tui.input.tab`** switches between this folder's sessions and every project's, when the
 *   caller gave a way to list every project's (`$loadAll`); the other list is read the first time
 *   it is asked for.
 * - **`app.session.toggleSort`** cycles threaded → recent → fuzzy. Threaded draws forks under the
 *   session they were forked from (`├─`/`└─`), ordered by the newest activity in each subtree.
 * - **`app.session.toggleNamedFilter`** keeps only sessions that have a name.
 * - **`app.session.togglePath`** shows each file's path.
 * - **`app.session.rename`** asks for a new name in place of the list, when there is a rename
 *   handler; **`app.session.delete`** (and `deleteNoninvasive` from an empty search) asks first,
 *   refuses the session being talked in, and moves the file to the trash when it can.
 *
 * Rows are drawn here rather than by `SelectList`, as upstream draws its own: a fork tree needs a
 * prefix per row, and a named session, the current one and the one being deleted each have their
 * own colour.
 */
final class SessionList implements Component, Focusable, InputHandler
{
    private const string CURRENT = 'current';
    private const string ALL = 'all';

    /** Set by the renderer and handed to the search box, which holds the cursor. */
    public bool $focused = false;

    private Input $search;

    /** Upstream's `renameInput`, shown in place of the list while renaming. */
    private Input $renameInput;

    /** The session being renamed, or null while listing. */
    private ?string $renaming = null;

    /** @var list<SessionInfo> this folder's sessions, less what was deleted from here */
    private array $current;

    /** @var list<SessionInfo>|null every project's, once read */
    private ?array $everywhere = null;

    /** @var list<array{session: SessionInfo, prefix: string}> the rows on show, in order */
    private array $rows = [];

    private int $selected = 0;

    private string $scope = self::CURRENT;

    private string $sortMode = SessionSearch::THREADED;

    /** Upstream's `NameFilter`: `all`, or `named` for sessions that have a name. */
    private bool $namedOnly = false;

    /** Upstream's `showPath`, toggled by `app.session.togglePath`. */
    private bool $showPath = false;

    /** Upstream's `confirmingDeletePath`: the session `app.session.delete` asked about. */
    private ?string $confirmingDelete = null;

    /** The session being talked in, which cannot be deleted from under itself. */
    private ?string $currentPath = null;

    /** @var Closure(string): void|null */
    private ?Closure $onSelect = null;

    /** @var Closure(): void|null */
    private ?Closure $onCancel = null;

    /** @var Closure(): void|null */
    private ?Closure $onQuit = null;

    /** @var (Closure(string): ?string)|null deletes a session file; the error, or null when it went */
    private ?Closure $onDelete = null;

    /** @var (Closure(string, string): void)|null renames a session file; throws when it cannot */
    private ?Closure $onRename = null;

    /** The last thing a key here had to say, and whether it was an error. */
    private ?string $status = null;

    private bool $statusIsError = false;

    /**
     * @param list<SessionInfo> $sessions newest first, as `SessionManager::listFor()` gives them
     * @param (Closure(): list<SessionInfo>)|null $loadAll every project's sessions, newest first —
     *        upstream's `allSessionsLoader`; without it Tab does nothing
     */
    public function __construct(
        array $sessions,
        private readonly int $visible = 10,
        private readonly ?Closure $loadAll = null,
    ) {
        $this->search = new Input();
        $this->renameInput = new Input();
        $this->current = $sessions;
        $this->refilter();

        // Enter is caught in `handleInput()` before the search ever sees it, so this fires for
        // one case only: a bracketed paste with a newline in it. Upstream wires the same handler.
        $this->search->setSubmitHandler(function (): void {
            $this->choose();
        });

        $this->renameInput->setSubmitHandler(function (string $value): void {
            $this->confirmRename($value);
        });
    }

    /** @param Closure(string): void|null $handler given the path of the session that was chosen */
    public function setSelectHandler(?Closure $handler): void
    {
        $this->onSelect = $handler;
    }

    /** @param Closure(): void|null $handler escape: this list is done, pig is not */
    public function setCancelHandler(?Closure $handler): void
    {
        $this->onCancel = $handler;
    }

    /** @param Closure(): void|null $handler ctrl+c: upstream's `onExit`, and it means the program */
    public function setQuitHandler(?Closure $handler): void
    {
        $this->onQuit = $handler;
    }

    /**
     * Upstream's `onDeleteSession`: asked for on `app.session.delete` once the person confirms. It
     * answers null when the file is gone, or why not — which is then said under the list. Without
     * one, the delete keys do nothing here.
     *
     * @param (Closure(string): ?string)|null $handler
     */
    public function setDeleteHandler(?Closure $handler, ?string $currentPath = null): void
    {
        $this->onDelete = $handler;
        $this->currentPath = $currentPath;
    }

    /**
     * Upstream's `renameSession` option: given the path and the new name, it writes the name. The
     * row then shows what the file holds. Without one, `app.session.rename` does nothing and is
     * not offered in the hints — upstream's `showRenameHint`, off for the `--resume` picker.
     *
     * @param (Closure(string, string): void)|null $handler
     */
    public function setRenameHandler(?Closure $handler): void
    {
        $this->onRename = $handler;
    }

    /**
     * Upstream's `deleteSessionFile()`: moves the file to the system trash/recycle bin using OS-native
     * commands first, so a slip of the key can be undone; `unlink()` when that is not available.
     *
     * @return string|null why the file is still there, or null when it is gone
     */
    public static function deleteSessionFile(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        // pig's own: a tool's output kept for `continueRun()` goes with its conversation.
        $running = ToolProgress::pathOf($path);

        if (is_file($running) && !unlink($running)) {
            return "could not remove {$running}";
        }

        if (self::moveToSystemTrash($path)) {
            return null;
        }

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $removed = unlink($path);
        } finally {
            restore_error_handler();
        }

        if ($removed) {
            return null;
        }

        return $error ?? 'could not remove the file';
    }

    /**
     * Moves a file to the system trash/recycle bin using OS-native commands.
     */
    public static function moveToSystemTrash(string $path): bool
    {
        $realPath = realpath($path) ?: $path;

        // 1. Dedicated `trash` CLI if available on PATH (macOS/Linux/Windows)
        $trash = self::onPath('trash');
        if ($trash !== null && self::runTrashCommand([$trash, ...(str_starts_with($path, '-') ? ['--'] : []), $path])) {
            if (!file_exists($path)) {
                return true;
            }
        }

        // 2. macOS native AppleScript (Finder delete moves directly to system .Trash)
        if (PHP_OS_FAMILY === 'Darwin' && self::onPath('osascript') !== null) {
            $script = sprintf('tell application "Finder" to delete POSIX file "%s"', addcslashes($realPath, "\"\\"));
            if (self::runTrashCommand(['osascript', '-e', $script]) && !file_exists($path)) {
                return true;
            }
        }

        // 3. Linux native `gio trash` or `trash-put`
        if (PHP_OS_FAMILY === 'Linux') {
            $gio = self::onPath('gio');
            if ($gio !== null && self::runTrashCommand([$gio, 'trash', $path]) && !file_exists($path)) {
                return true;
            }

            $trashPut = self::onPath('trash-put');
            if ($trashPut !== null && self::runTrashCommand([$trashPut, $path]) && !file_exists($path)) {
                return true;
            }
        }

        // 4. Windows native PowerShell RecycleBin
        if (PHP_OS_FAMILY === 'Windows') {
            $ps = self::onPath('powershell.exe') ?? self::onPath('pwsh.exe');
            if ($ps !== null) {
                $winPath = str_replace("'", "''", $path);
                $cmd = "Add-Type -AssemblyName Microsoft.VisualBasic; [Microsoft.VisualBasic.FileIO.FileSystem]::DeleteFile('{$winPath}', 'OnlyErrorDialogs', 'SendToRecycleBin')";
                if (self::runTrashCommand([$ps, '-NoProfile', '-NonInteractive', '-Command', $cmd]) && !file_exists($path)) {
                    return true;
                }
            }
        }

        return !file_exists($path);
    }

    private static function runTrashCommand(array $cmd): bool
    {
        // A trash command that is not there warns and answers false; false is the answer here.
        set_error_handler(static fn (): bool => true);
        try {
            $proc = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($proc)) {
            return false;
        }

        return proc_close($proc) === 0;
    }

    /** The executable called $name on `PATH`, or null — also how `/share` looks for `gh`. */
    public static function onPath(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("{$dir}/{$name}") && is_executable("{$dir}/{$name}")) {
                return "{$dir}/{$name}";
            }
        }

        return null;
    }

    /** What is typed in the search. */
    public function query(): string
    {
        return $this->search->value();
    }

    /**
     * The sessions on show, in the order they are drawn.
     *
     * @return list<SessionInfo>
     */
    public function matching(): array
    {
        return array_map(static fn (array $row): SessionInfo => $row['session'], $this->rows);
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->search->invalidate();
        $this->renameInput->invalidate();
    }

    #[\Override]
    public function render(int $width): array
    {
        $theme = Themes::theme();
        $kb = Keybindings::getKeybindings();
        $key = static fn (string $id): string => implode('/', $kb->getKeys($id)) ?: '(unbound)';
        $hint = static fn (string $id, string $what): string => $theme->fg('dim', $key($id)) . $theme->fg('muted', " {$what}");

        if ($this->renaming !== null) {
            // Upstream's rename panel, which replaces the list and its header while it is open.
            $this->renameInput->focused = $this->focused;

            return [
                $theme->bold('Rename Session'),
                '',
                ...$this->renameInput->render($width),
                '',
                $theme->fg('muted', Width::truncate($key('tui.select.confirm') . ' to save · ' . $key('tui.select.cancel') . ' to cancel', $width, '…')),
            ];
        }

        $this->search->focused = $this->focused;
        $lines = [...$this->header($width)];

        // Upstream's hint lines: the delete question while one is open, a status when there is
        // one, otherwise what the keys do.
        if ($this->confirmingDelete !== null) {
            $lines[] = $theme->fg('error', Width::truncate(
                'Delete session? ' . $key('tui.select.confirm') . ' confirm · ' . $key('tui.select.cancel') . ' cancel',
                $width,
                '…',
            ));
            $lines[] = '';
        } elseif ($this->status !== null) {
            $lines[] = $theme->fg($this->statusIsError ? 'error' : 'accent', Width::truncate($this->status, $width, '…'));
            $lines[] = '';
        } else {
            $sep = $theme->fg('muted', ' · ');
            $first = ($this->loadAll === null ? '' : $hint('tui.input.tab', 'scope') . $sep)
                . $theme->fg('muted', 're:<pattern> regex · "phrase" exact');
            $second = [
                $hint('app.session.toggleSort', 'sort'),
                $hint('app.session.toggleNamedFilter', 'named'),
                $hint('app.session.delete', 'delete'),
                $hint('app.session.togglePath', 'path ' . ($this->showPath ? '(on)' : '(off)')),
            ];

            if ($this->onRename !== null) {
                $second[] = $hint('app.session.rename', 'rename');
            }

            $lines[] = Width::truncate($first, $width, '…');
            $lines[] = Width::truncate(implode($sep, $second), $width, '…');
        }

        $lines[] = '';
        $lines = [...$lines, ...$this->search->render($width)];
        $lines[] = '';

        if ($this->rows === []) {
            $named = $key('app.session.toggleNamedFilter');
            $message = match (true) {
                $this->namedOnly && $this->scope === self::ALL => "  No named sessions found. Press {$named} to show all.",
                $this->namedOnly => "  No named sessions in current folder. Press {$named} to show all"
                    . ($this->loadAll === null ? '.' : ', or Tab to view all.'),
                $this->scope === self::ALL || $this->loadAll === null => '  No sessions found',
                default => '  No sessions in current folder. Press Tab to view all.',
            };
            $lines[] = $theme->fg('muted', Width::truncate($message, $width, '…'));

            return $lines;
        }

        $count = count($this->rows);
        $start = max(0, min($this->selected - intdiv($this->visible, 2), $count - $this->visible));
        $end = min($start + $this->visible, $count);

        for ($i = $start; $i < $end; $i++) {
            $lines[] = $this->row($this->rows[$i], $i === $this->selected, $width);
        }

        if ($start > 0 || $end < $count) {
            $lines[] = $theme->fg('muted', Width::truncate('  (' . ($this->selected + 1) . "/{$count})", $width, ''));
        }

        return $lines;
    }

    /** Upstream's `SessionSelectorHeader` title line: which list, and how it is filtered and sorted. */
    private function header(int $width): array
    {
        $theme = Themes::theme();
        $left = $theme->bold($this->scope === self::CURRENT ? 'Resume Session (Current Folder)' : 'Resume Session (All)');
        $sort = match ($this->sortMode) {
            SessionSearch::THREADED => 'Threaded',
            SessionSearch::RECENT => 'Recent',
            default => 'Fuzzy',
        };
        $scope = $this->scope === self::CURRENT
            ? $theme->fg('accent', '◉ Current Folder') . $theme->fg('muted', ' | ○ All')
            : $theme->fg('muted', '○ Current Folder | ') . $theme->fg('accent', '◉ All');
        $right = Width::truncate(
            ($this->loadAll === null ? '' : "{$scope}  ")
            . $theme->fg('muted', 'Name: ') . $theme->fg('accent', $this->namedOnly ? 'Named' : 'All')
            . '  ' . $theme->fg('muted', 'Sort: ') . $theme->fg('accent', $sort),
            $width,
            '',
        );
        $left = Width::truncate($left, max(0, $width - Width::visible($right) - 1), '');
        $spacing = max(0, $width - Width::visible($left) - Width::visible($right));

        return [$left . str_repeat(' ', $spacing) . $right];
    }

    /** @param array{session: SessionInfo, prefix: string} $row */
    private function row(array $row, bool $isSelected, int $width): string
    {
        $theme = Themes::theme();
        $session = $row['session'];
        $deleting = $session->path === $this->confirmingDelete;
        $display = $session->opening === '' ? '(nothing was said)' : $session->opening;
        $display = trim((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $display));

        $right = $session->messages . ' ' . self::age($session->modified > 0 ? $session->modified : $session->timestamp);

        if ($this->scope === self::ALL && $session->cwd !== '') {
            $right = self::shortenPath($session->cwd) . ' ' . $right;
        }

        if ($this->showPath) {
            $right = self::shortenPath($session->path) . ' ' . $right;
        }

        $cursor = $isSelected ? $theme->fg('accent', '› ') : '  ';
        $room = $width - 2 - Width::visible($row['prefix']) - Width::visible($right) - 2;
        $message = Width::truncate($display, max(10, $room), '…');

        $colour = match (true) {
            $deleting => 'error',
            $this->isCurrent($session->path) => 'accent',
            SessionSearch::hasName($session) => 'warning',
            default => null,
        };
        $styled = $colour === null ? $message : $theme->fg($colour, $message);

        if ($isSelected) {
            $styled = $theme->bold($styled);
        }

        $left = $cursor . $theme->fg('dim', $row['prefix']) . $styled;
        $spacing = max(1, $width - Width::visible($left) - Width::visible($right));
        $line = $left . str_repeat(' ', $spacing) . $theme->fg($deleting ? 'error' : 'dim', $right);

        if ($isSelected) {
            $line = $theme->bg('selectedBg', $line);
        }

        return Width::truncate($line, $width, '');
    }

    /** Upstream's `formatSessionDate()`: `now`, `5m`, `3h`, `2d`, `1w`, `4mo`, `1y`. */
    private static function age(int $millis, ?int $now = null): string
    {
        $minutes = intdiv(max(0, ($now ?? (int) (microtime(true) * 1000)) - $millis), 60000);

        return match (true) {
            $minutes < 1 => 'now',
            $minutes < 60 => "{$minutes}m",
            $minutes < 1440 => intdiv($minutes, 60) . 'h',
            $minutes < 10080 => intdiv($minutes, 1440) . 'd',
            $minutes < 43200 => intdiv($minutes, 10080) . 'w',
            $minutes < 525600 => intdiv($minutes, 43200) . 'mo',
            default => intdiv($minutes, 525600) . 'y',
        };
    }

    /** Upstream's `shortenPath()`: the home directory as `~`. */
    private static function shortenPath(string $path): string
    {
        $home = (string) getenv('HOME');

        return $home !== '' && str_starts_with($path, $home) ? '~' . substr($path, strlen($home)) : $path;
    }

    /**
     * Upstream's `SessionList.handleInput()` with the selector's rename mode in front of it: the
     * rename field takes every key while it is open, then the delete question, then the session
     * actions, then the list keys; everything else is typing, and goes into the search.
     *
     * Ctrl+C is the program's (`setQuitHandler()`), ahead of the list keys, because this list *is*
     * the screen — in front of `tui.select.cancel`, which would otherwise read it as Escape.
     */
    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = Keybindings::getKeybindings();

        if ($this->renaming !== null) {
            if ($kb->matches($data, 'tui.select.cancel')) {
                $this->renaming = null;
            } else {
                $this->renameInput->handleInput($data);
            }

            return;
        }

        if ($this->confirmingDelete !== null) {
            if ($kb->matches($data, 'tui.select.confirm')) {
                $path = $this->confirmingDelete;
                $this->confirmingDelete = null;
                $this->delete($path);
            } elseif ($kb->matches($data, 'tui.select.cancel')) {
                $this->confirmingDelete = null;
            }

            return;
        }

        if (Keys::isCtrlC($data)) {
            if ($this->onQuit !== null) {
                ($this->onQuit)();
            }

            return;
        }

        $this->status = null;

        if ($kb->matches($data, 'tui.input.tab')) {
            $this->toggleScope();

            return;
        }

        if ($kb->matches($data, 'app.session.toggleSort')) {
            $this->sortMode = match ($this->sortMode) {
                SessionSearch::THREADED => SessionSearch::RECENT,
                SessionSearch::RECENT => SessionSearch::RELEVANCE,
                default => SessionSearch::THREADED,
            };
            $this->refilter();

            return;
        }

        if ($kb->matches($data, 'app.session.toggleNamedFilter')) {
            $this->namedOnly = !$this->namedOnly;
            $this->refilter();

            return;
        }

        if ($kb->matches($data, 'app.session.togglePath')) {
            $this->showPath = !$this->showPath;

            return;
        }

        if ($kb->matches($data, 'app.session.delete')) {
            $this->startDeleteConfirmation();

            return;
        }

        if ($kb->matches($data, 'app.session.rename')) {
            $selected = $this->rows[$this->selected]['session'] ?? null;

            if ($selected !== null && $this->onRename !== null) {
                $this->renaming = $selected->path;
                $this->renameInput->setValue($selected->name ?? '');
            }

            return;
        }

        // Ctrl+Backspace deletes only from an empty search; with a query it is the search's.
        if ($kb->matches($data, 'app.session.deleteNoninvasive') && $this->search->value() === '') {
            $this->startDeleteConfirmation();

            return;
        }

        $last = max(0, count($this->rows) - 1);

        if ($kb->matches($data, 'tui.select.up')) {
            $this->selected = max(0, $this->selected - 1);
        } elseif ($kb->matches($data, 'tui.select.down')) {
            $this->selected = min($last, $this->selected + 1);
        } elseif ($kb->matches($data, 'tui.select.pageUp')) {
            $this->selected = max(0, $this->selected - $this->visible);
        } elseif ($kb->matches($data, 'tui.select.pageDown')) {
            $this->selected = min($last, $this->selected + $this->visible);
        } elseif ($kb->matches($data, 'tui.select.confirm')) {
            $this->choose();
        } elseif ($kb->matches($data, 'tui.select.cancel')) {
            if ($this->onCancel !== null) {
                ($this->onCancel)();
            }
        } else {
            $this->search->handleInput($data);
            $this->refilter();
        }
    }

    private function choose(): void
    {
        $session = $this->rows[$this->selected]['session'] ?? null;

        if ($session !== null && $this->onSelect !== null) {
            ($this->onSelect)($session->path);
        }
    }

    /** Upstream's `toggleScope()`: every project's list is read the first time it is shown. */
    private function toggleScope(): void
    {
        if ($this->loadAll === null) {
            return;
        }

        $this->scope = $this->scope === self::CURRENT ? self::ALL : self::CURRENT;

        if ($this->scope === self::ALL && $this->everywhere === null) {
            try {
                $this->everywhere = ($this->loadAll)();
            } catch (\Throwable $error) {
                $this->everywhere = null;
                $this->scope = self::CURRENT;
                $this->setStatus('Failed to load sessions: ' . $error->getMessage(), true);
            }
        }

        $this->selected = 0;
        $this->refilter();
    }

    /** Upstream's `startDeleteConfirmationForSelectedSession()`. */
    private function startDeleteConfirmation(): void
    {
        $selected = $this->rows[$this->selected]['session'] ?? null;

        if ($selected === null || $this->onDelete === null) {
            return;
        }

        if ($this->isCurrent($selected->path)) {
            $this->setStatus('Cannot delete the currently active session', true);

            return;
        }

        $this->confirmingDelete = $selected->path;
    }

    private function isCurrent(string $path): bool
    {
        return $this->currentPath !== null
            && (realpath($path) ?: $path) === (realpath($this->currentPath) ?: $this->currentPath);
    }

    private function delete(string $path): void
    {
        $error = $this->onDelete === null ? 'nothing here can delete a session' : ($this->onDelete)($path);

        if ($error !== null) {
            $this->setStatus("Failed to delete: {$error}", true);

            return;
        }

        $keep = static fn (SessionInfo $s): bool => $s->path !== $path;
        $this->current = array_values(array_filter($this->current, $keep));
        $this->everywhere = $this->everywhere === null ? null : array_values(array_filter($this->everywhere, $keep));
        $this->setStatus('Session deleted', false);
        $this->refilter();
    }

    /** Upstream's `confirmRename()`: an empty name is not saved, and the field stays open. */
    private function confirmRename(string $value): void
    {
        $name = trim($value);
        $path = $this->renaming;

        if ($name === '' || $path === null || $this->onRename === null) {
            return;
        }

        $this->renaming = null;

        try {
            ($this->onRename)($path, $name);
        } catch (\Throwable $error) {
            $this->setStatus('Failed to rename: ' . $error->getMessage(), true);

            return;
        }

        // What the file says now, which is the cleaned name rather than what was typed.
        $replace = static fn (SessionInfo $s): SessionInfo => $s->path === $path
            ? (SessionManager::info($path) ?? $s->withName($name))
            : $s;
        $this->current = array_map($replace, $this->current);
        $this->everywhere = $this->everywhere === null ? null : array_map($replace, $this->everywhere);
        $this->refilter();
    }

    private function setStatus(string $message, bool $isError): void
    {
        $this->status = $message;
        $this->statusIsError = $isError;
    }

    /**
     * Upstream's `filterSessions()`: the name filter, then the tree when threaded with nothing
     * typed, otherwise `SessionSearch`'s flat list. The selection stays where it was, clamped.
     */
    private function refilter(): void
    {
        $sessions = $this->scope === self::ALL ? ($this->everywhere ?? []) : $this->current;

        if ($this->namedOnly) {
            $sessions = array_values(array_filter($sessions, SessionSearch::hasName(...)));
        }

        $query = $this->search->value();

        $this->rows = $this->sortMode === SessionSearch::THREADED && trim($query) === ''
            ? self::threaded($sessions)
            : array_map(
                static fn (SessionInfo $session): array => ['session' => $session, 'prefix' => ''],
                SessionSearch::filterAndSort($sessions, $query, $this->sortMode),
            );

        $this->selected = min($this->selected, max(0, count($this->rows) - 1));
    }

    /**
     * Upstream's `buildSessionTree()` and `flattenSessionTree()`: each fork under the session it
     * was forked from when that one is in the list, siblings and roots ordered by the newest
     * activity anywhere under them, and the `│  ` / `├─ ` / `└─ ` prefix each row is drawn with.
     *
     * @param list<SessionInfo> $sessions
     * @return list<array{session: SessionInfo, prefix: string}>
     */
    private static function threaded(array $sessions): array
    {
        $canonical = static fn (string $path): string => realpath($path) ?: $path;
        $byPath = [];

        foreach ($sessions as $index => $session) {
            $byPath[$canonical($session->path)] = $index;
        }

        $children = array_fill(0, count($sessions), []);
        $roots = [];

        foreach ($sessions as $index => $session) {
            $parent = $session->parentSessionPath === null ? null : $canonical($session->parentSessionPath);

            if ($parent !== null && isset($byPath[$parent]) && $byPath[$parent] !== $index) {
                $children[$byPath[$parent]][] = $index;
            } else {
                $roots[] = $index;
            }
        }

        $latest = [];
        $activity = static function (int $index) use (&$activity, &$latest, $children, $sessions): int {
            $newest = $sessions[$index]->modified;

            foreach ($children[$index] as $child) {
                $newest = max($newest, $activity($child));
            }

            return $latest[$index] = $newest;
        };

        foreach ($roots as $root) {
            $activity($root);
        }

        // Stable, so equal activity keeps the order the list came in.
        $sort = static function (array $nodes) use (&$latest): array {
            usort($nodes, static fn (int $a, int $b): int => ($latest[$b] ?? 0) <=> ($latest[$a] ?? 0));

            return $nodes;
        };

        $rows = [];
        $walk = static function (int $index, int $depth, array $continues, bool $isLast) use (&$walk, &$rows, $children, $sessions, $sort): void {
            $prefix = $depth === 0
                ? ''
                : implode('', array_map(static fn (bool $more): string => $more ? '│  ' : '   ', $continues)) . ($isLast ? '└─ ' : '├─ ');
            $rows[] = ['session' => $sessions[$index], 'prefix' => $prefix];
            $kids = $sort($children[$index]);

            foreach ($kids as $i => $child) {
                $walk($child, $depth + 1, [...$continues, $depth > 0 ? !$isLast : false], $i === count($kids) - 1);
            }
        };

        $sorted = $sort($roots);

        foreach ($sorted as $i => $root) {
            $walk($root, 0, [], $i === count($sorted) - 1);
        }

        return $rows;
    }
}
