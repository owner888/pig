<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Utils\Fuzzy;
use Pig\Tui\Caret;
use Pig\Tui\Component;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;

/**
 * The list of earlier conversations, with a search box over it.
 *
 * Upstream's `SessionList`, the inner half of `components/session-selector.ts`. The picker had
 * the list from the start and not this: thirty conversations under one project, each named by
 * whatever its first line happened to be, and the arrow keys as the only way through them.
 * What anybody actually remembers three days later is a phrase from the middle, which is what
 * `Fuzzy` over `SessionInfo::$text` matches.
 *
 * **The keys divide in two, and the split is upstream's.** Up, down, Enter, Escape and Ctrl+C
 * belong to the list; *everything else* is typing, and goes into the search. So there is no
 * key to switch between the two halves and no focus to lose — which is the only arrangement
 * that works, because a search box you have to tab into is a search box nobody finds.
 *
 * **Escape and Ctrl+C are two keys and not one**, which is the whole reason `setQuitHandler()`
 * exists next to `setCancelHandler()` — upstream's `onCancel` and `onExit`.
 *
 * One difference from upstream, in the rendering rather than the behaviour: the rows come from
 * `SelectList`, which pig already had, where upstream draws its own two-line rows. So a
 * session is one line here and fits ten on screen instead of five.
 */
final class SessionList implements Caret, Component, InputHandler
{
    private Input $search;

    private SelectList $list;

    /** @var list<SessionInfo> */
    private array $filtered;

    /** @var Closure(string): void|null */
    private ?Closure $onSelect = null;

    /** @var Closure(): void|null */
    private ?Closure $onCancel = null;

    /** @var Closure(): void|null */
    private ?Closure $onQuit = null;

    /** @param list<SessionInfo> $sessions newest first, as `SessionManager::listFor()` gives them */
    public function __construct(
        private readonly array $sessions,
        private readonly Palette $palette,
        private readonly int $visible = 10,
    ) {
        $this->search = new Input();
        $this->filtered = $sessions;
        $this->list = $this->rowsFor($sessions, 0);

        // Enter is caught in `handleInput()` before the search ever sees it, so this fires for
        // one case only: a bracketed paste with a newline in it. Upstream wires the same
        // handler, and leaving it out would make a pasted search string do nothing at all.
        $this->search->setSubmitHandler(function (): void {
            $this->list->handleInput("\r");
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

    /** What is typed in the search, which is the only state worth asking about from outside. */
    public function query(): string
    {
        return $this->search->value();
    }

    /**
     * The sessions currently matching, best first.
     *
     * @return list<SessionInfo>
     */
    public function matching(): array
    {
        return $this->filtered;
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->search->invalidate();
        $this->list->invalidate();
    }

    /**
     * The caret belongs to the search box, which is this component's first line.
     *
     * A wrapper that does not forward `caret()` silently does nothing — the interface is
     * checked on the focused component, so the cursor stays wherever the frame left it and an
     * input method draws over the bottom of the screen. `CustomEditor` shipped with exactly
     * this omission, which is why it is worth a sentence here.
     */
    #[\Override]
    public function caret(int $width): ?array
    {
        return $this->search->caret($width);
    }

    #[\Override]
    public function render(int $width): array
    {
        $lines = [...$this->search->render($width), ''];

        if ($this->filtered === []) {
            // Upstream's wording. "No sessions found" while the list behind it is plainly not
            // empty is the one thing that says the search is doing something.
            $lines[] = $this->palette->fg('muted', '  No sessions found');

            return $lines;
        }

        return [...$lines, ...$this->list->render($width)];
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        // Before the list, because `SelectList` answers ctrl+c with its cancel handler — it is
        // one key to a list inside a screen, where the screen deals with quitting. Here the list
        // *is* the screen, and cancelling is what escape already does.
        if (Keys::isCtrlC($data)) {
            if ($this->onQuit !== null) {
                ($this->onQuit)();
            }

            return;
        }

        $forList = Keys::isArrowUp($data)
            || Keys::isArrowDown($data)
            || Keys::isEnter($data)
            || Keys::isEscape($data);

        if ($forList) {
            // Escape reaches `onCancel` through the list's own handler rather than being
            // answered here, so there is one route out and not two.
            $this->list->handleInput($data);

            return;
        }

        $this->search->handleInput($data);
        $this->refilter();
    }

    /**
     * Match again, and keep the selection as near to where it was as the new list allows.
     *
     * The list component is rebuilt rather than told about the change, because `SelectList`
     * takes its items at construction — upstream's does too, which is why upstream's session
     * selector draws its own rows instead of using one. Thirty items and one object per
     * keystroke is not worth a setter that only this would use.
     */
    private function refilter(): void
    {
        $this->filtered = Fuzzy::filter(
            $this->sessions,
            $this->search->value(),
            static fn (SessionInfo $session): string => $session->text,
        );

        $this->list = $this->rowsFor($this->filtered, $this->list->selectedIndex());
    }

    /**
     * @param list<SessionInfo> $sessions
     */
    private function rowsFor(array $sessions, int $selected): SelectList
    {
        $list = new SelectList(SessionPicker::items($sessions), $this->visible, $this->palette->selectListTheme());

        // Clamped by `setSelectedIndex()` itself, which is what makes narrowing the search
        // from the bottom of a long list land somewhere sensible rather than back at the top.
        $list->setSelectedIndex($selected);

        // A row's value is its position in the list it was built from, so the lookup is
        // against the *filtered* sessions and has to be rebuilt with them.
        $list->setSelectHandler(function (SelectItem $item) use ($sessions): void {
            $session = $sessions[(int) $item->value] ?? null;

            if ($session !== null && $this->onSelect !== null) {
                ($this->onSelect)($session->path);
            }
        });

        $list->setCancelHandler(function (): void {
            if ($this->onCancel !== null) {
                ($this->onCancel)();
            }
        });

        return $list;
    }
}
