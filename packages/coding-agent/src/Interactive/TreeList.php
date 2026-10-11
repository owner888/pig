<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\CustomEntry;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\Label;
use Pig\CodingAgent\Session\ModelChange;
use Pig\CodingAgent\Session\ThinkingLevelChange;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Tools\Shell;
use Pig\Tui\Component;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\Width;

/**
 * The whole conversation as a tree, to pick a point out of.
 *
 * Upstream's `tree-selector.ts`. `/tree` used to be a `SelectList` over `SessionManager::branch()`,
 * which is **the path being talked on and nothing else** — so once a conversation went back and
 * carried on in another direction, the branch it left was in the file, with its parents intact,
 * and unreachable: `goTo()` needs an id and nothing showed one. This is what shows them.
 *
 * ```
 *   • user: port the tree selector
 *     • assistant: Here is what it does…
 *     ├─ user: actually, do the proxy first
 *     │  └─ assistant: Right — starting with CONNECT…
 *     └─ • user: no, keep going with the tree
 *           • assistant: Carrying on…
 * ```
 *
 * `•` marks the path being talked on, `├─`/`└─` a fork, and `│` carries a fork's gutter down past
 * the rows under it. The indent rules are upstream's and they are not "one level per generation":
 * a chain of single children stays at the same indent, so a long conversation reads as a list, and
 * only a fork costs a column. Otherwise a hundred turns would be a hundred columns deep.
 *
 * Five filters on Ctrl+O, backwards on Shift+Ctrl+O — `default` hides the bookkeeping entries,
 * `no-tools` also hides tool results, `user-only` keeps what the person said, `labeled-only` keeps
 * what they named, `all` hides nothing — and each has a direct key (`app.tree.filter.*`) that
 * toggles it against the default. Anything else typed is a search, matched against every token;
 * escape clears it before it cancels, so a search is not a trap.
 *
 * Every key is upstream's keybinding id, read through `Pig\Tui\Keybindings`: `app.tree.foldOrUp`
 * folds the branch under the cursor (or climbs to the start of its segment), `unfoldOrDown`
 * reverses it, `app.tree.editLabel` names a point, `app.tree.toggleLabelTimestamp` shows when each
 * name was given, and `app.message.copy` copies the selected entry's text.
 */
final class TreeList implements Component, InputHandler
{
    private const string DEFAULT = 'default';
    private const string NO_TOOLS = 'no-tools';
    private const string USER_ONLY = 'user-only';
    private const string LABELED_ONLY = 'labeled-only';
    private const string ALL = 'all';

    /** Cycled in this order, and wrapped. */
    private const array FILTERS = [self::DEFAULT, self::NO_TOOLS, self::USER_ONLY, self::LABELED_ONLY, self::ALL];

    /** Three columns per indent level, which is what leaves room for `├─ `. */
    private const int COLUMNS_PER_LEVEL = 3;

    /**
     * @var list<array{
     *     id: string, message: mixed, label: string|null,
     *     indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool
     * }>
     */
    private array $flat = [];

    /** @var list<int> indexes into $flat */
    private array $visible = [];

    /** @var array<string, string> tool call id to a rendered description of the call */
    private array $calls = [];

    /** @var array<string, true> the ids on the path being talked on */
    private array $active = [];

    /** @var array<string, string|null> child id to parent id, recorded while flattening */
    private array $parents = [];

    private int $selected = 0;

    private string $filter = self::DEFAULT;

    private string $search = '';

    private bool $manyRoots = false;

    /** @var Closure(string): void|null */
    private ?Closure $onSelect = null;

    /** @var Closure(): void|null */
    private ?Closure $onCancel = null;

    /** @var Closure(string, ?string): void|null */
    private ?Closure $onLabel = null;

    /** @var Closure(?string): void|null upstream's `onCopy`, given the selected entry's text or null */
    private ?Closure $onCopy = null;

    /** @var array<string, true> upstream's `foldedNodes` */
    private array $folded = [];

    /** @var array<string, string|null> visible id to its nearest visible ancestor — upstream's `visibleParentMap` */
    private array $visibleParent = [];

    /** @var array<string, list<string>> visible id ('' for the roots) to its visible children — `visibleChildrenMap` */
    private array $visibleChildren = [];

    /** Upstream's `showLabelTimestamps`. */
    private bool $showLabelTimestamps = false;

    /** @var array<string, int> target id to when its current name was given, in ms, from the `Label` entries */
    private array $labelTimestamps = [];

    /**
     * @param list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}> $tree
     * @param string|null $leaf the point the conversation is at, which is where the cursor starts
     * @param string|null $initialSelectedId upstream's: where the cursor starts instead, when given
     */
    public function __construct(
        array $tree,
        private readonly ?string $leaf,
        private readonly int $maxVisible,
        ?string $initialSelectedId = null,
        /** Upstream's `initialFilterMode`, from `treeFilterMode`; one this list does not know is the default. */
        ?string $initialFilter = null,
    ) {
        if ($initialFilter !== null && in_array($initialFilter, self::FILTERS, true)) {
            $this->filter = $initialFilter;
        }

        $this->manyRoots = count($tree) > 1;
        $this->flat = $this->flatten($tree);
        $this->buildActivePath();
        $this->applyFilter();

        // "Start with initialSelectedId if provided, otherwise current leaf".
        $targetId = $initialSelectedId ?? $leaf;

        foreach ($this->visible as $position => $index) {
            if ($this->flat[$index]['id'] === $targetId) {
                $this->selected = $position;

                break;
            }
        }
    }

    /** @param Closure(string): void|null $handler */
    public function setSelectHandler(?Closure $handler): void
    {
        $this->onSelect = $handler;
    }

    /** @param Closure(): void|null $handler */
    public function setCancelHandler(?Closure $handler): void
    {
        $this->onCancel = $handler;
    }

    /** @param Closure(string, ?string): void|null $handler asked for on `app.tree.editLabel`, with the current name */
    public function setLabelHandler(?Closure $handler): void
    {
        $this->onLabel = $handler;
    }

    /** @param Closure(?string): void|null $handler asked for on `app.message.copy`, with the entry's text */
    public function setCopyHandler(?Closure $handler): void
    {
        $this->onCopy = $handler;
    }

    /** What is typed, for whoever draws the search line. */
    public function search(): string
    {
        return $this->search;
    }

    /** `[no-tools]`, or the empty string in the default view; `[+label time]` while timestamps show. */
    public function filterLabel(): string
    {
        return ($this->filter === self::DEFAULT ? '' : " [{$this->filter}]")
            . ($this->showLabelTimestamps ? ' [+label time]' : '');
    }

    /** How many rows the filter admits, for a test and for a caller that wants to say so. */
    public function count(): int
    {
        return count($this->visible);
    }

    /** The id under the cursor, or null when the filter admits nothing. */
    public function current(): ?string
    {
        $index = $this->visible[$this->selected] ?? null;

        return $index === null ? null : $this->flat[$index]['id'];
    }

    // ---- flattening ----------------------------------------------------------------------------

    /**
     * Depth-first, with the branch holding the current leaf first at every fork.
     *
     * **The ordering is the useful part.** A conversation that went back has two ways forward from
     * that point, and the one being talked on is the one somebody is looking for; putting it second
     * because it was written second would bury it under an abandoned branch.
     *
     * Iterative rather than recursive, as upstream is, and for its reason: a long conversation is a
     * chain thousands of entries deep, and one PHP frame per entry is a stack overflow on a
     * conversation somebody actually had.
     *
     * @param list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}> $roots
     * @return list<array{id: string, message: mixed, label: string|null, indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool}>
     */
    private function flatten(array $roots): array
    {
        $holdsLeaf = [];
        $this->markBranchesHoldingLeaf($roots, $holdsLeaf);

        $flat = [];
        // Each item: node, indent, justForked, connector, last, gutters, virtual
        $stack = [];
        $ordered = $this->orderByLeaf($roots, $holdsLeaf);

        // Several roots is a file whose first entry has siblings, which a hook writing before the
        // first message can produce. They are drawn as children of a root that is not there, which
        // is why `virtual` exists: the connector is computed and then not drawn.
        for ($index = count($ordered) - 1; $index >= 0; $index--) {
            $this->parents[$ordered[$index]['id']] = null;
            $stack[] = [
                $ordered[$index],
                $this->manyRoots ? 1 : 0,
                $this->manyRoots,
                $this->manyRoots,
                $index === count($ordered) - 1,
                [],
                $this->manyRoots,
            ];
        }

        while ($stack !== []) {
            [$node, $indent, $justForked, $connector, $last, $gutters, $virtual] = array_pop($stack);

            $this->rememberToolCalls($node['message']);

            if ($node['message'] instanceof Label && $node['message']->label !== null) {
                $target = $node['message']->targetId;
                $this->labelTimestamps[$target] = max($this->labelTimestamps[$target] ?? 0, $node['message']->timestamp);
            }

            $flat[] = [
                'id' => $node['id'],
                'message' => $node['message'],
                'label' => $node['label'],
                'indent' => $indent,
                'connector' => $connector,
                'last' => $last,
                'gutters' => $gutters,
                'virtual' => $virtual,
            ];

            $children = $this->orderByLeaf($node['children'], $holdsLeaf);
            $forks = count($children) > 1;

            // Upstream's three rules, kept exactly: a fork costs a column; the first generation
            // after one costs another, which is what visually groups a branch; a single-child chain
            // costs nothing.
            $childIndent = match (true) {
                $forks => $indent + 1,
                $justForked && $indent > 0 => $indent + 1,
                default => $indent,
            };

            $displayIndent = $this->manyRoots ? max(0, $indent - 1) : $indent;
            $childGutters = $connector && !$virtual
                ? [...$gutters, max(0, $displayIndent - 1) => !$last]
                : $gutters;

            for ($index = count($children) - 1; $index >= 0; $index--) {
                $this->parents[$children[$index]['id']] = $node['id'];
                $stack[] = [
                    $children[$index],
                    $childIndent,
                    $forks,
                    $forks,
                    $index === count($children) - 1,
                    $childGutters,
                    false,
                ];
            }
        }

        return $flat;
    }

    /**
     * Mark every node with the leaf under it, by id.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<string, bool>        $holdsLeaf
     */
    private function markBranchesHoldingLeaf(array $nodes, array &$holdsLeaf): bool
    {
        $any = false;

        foreach ($nodes as $node) {
            $id = (string) $node['id'];
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $here = $this->markBranchesHoldingLeaf($children, $holdsLeaf) || $id === $this->leaf;
            $holdsLeaf[$id] = $here;
            $any = $any || $here;
        }

        return $any;
    }

    /**
     * The branch holding the leaf first, everything else in the order it was written.
     *
     * @param  list<array<string, mixed>> $nodes
     * @param  array<string, bool>        $holdsLeaf
     * @return list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}>
     */
    private function orderByLeaf(array $nodes, array $holdsLeaf): array
    {
        $first = [];
        $rest = [];

        foreach ($nodes as $node) {
            if ($holdsLeaf[(string) $node['id']] ?? false) {
                $first[] = $node;
            } else {
                $rest[] = $node;
            }
        }

        /** @var list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}> $ordered */
        $ordered = [...$first, ...$rest];

        return $ordered;
    }

    /**
     * The path from the leaf up to its root, which is what `•` marks.
     *
     * Walked through `$parents`, recorded while flattening, rather than read off the display
     * indents: a single-child chain stays at one indent on purpose, so the indents say nothing
     * about who a row's parent is.
     */
    private function buildActivePath(): void
    {
        $this->active = [];
        $id = $this->leaf;

        while ($id !== null && !isset($this->active[$id])) {
            $this->active[$id] = true;
            $id = $this->parents[$id] ?? null;
        }
    }

    private function rememberToolCalls(mixed $message): void
    {
        if (!$message instanceof AssistantMessage) {
            return;
        }

        foreach ($message->content as $block) {
            if ($block instanceof ToolCall) {
                $arguments = json_encode($block->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $arguments = $arguments === false ? '{}' : $arguments;
                $this->calls[$block->id] = sprintf(
                    '[%s: %s%s]',
                    $block->name,
                    substr($arguments, 0, 40),
                    strlen($arguments) > 40 ? '…' : '',
                );
            }
        }
    }

    // ---- filtering -----------------------------------------------------------------------------

    private function applyFilter(): void
    {
        $wasOn = $this->current();
        $tokens = array_values(array_filter(preg_split('/\s+/', mb_strtolower($this->search)) ?: []));
        $this->visible = [];

        foreach ($this->flat as $index => $row) {
            if (!$this->passes($row, $tokens)) {
                continue;
            }

            $this->visible[] = $index;
        }

        // Upstream: the descendants of a folded node go, whatever the filter said about them.
        // `$flat` is depth-first, so a parent is always decided before its children.
        if ($this->folded !== []) {
            $skip = [];

            foreach ($this->flat as $row) {
                $parent = $this->parents[$row['id']] ?? null;

                if ($parent !== null && (isset($this->folded[$parent]) || isset($skip[$parent]))) {
                    $skip[$row['id']] = true;
                }
            }

            $this->visible = array_values(array_filter($this->visible, fn (int $index): bool => !isset($skip[$this->flat[$index]['id']])));
        }

        $this->buildVisibleMaps();

        // The cursor stays on the row it was on, when the new filter still admits it. Anything else
        // is a filter key that silently moves the selection, and then enter goes somewhere nobody
        // chose.
        foreach ($this->visible as $position => $index) {
            if ($this->flat[$index]['id'] === $wasOn) {
                $this->selected = $position;

                return;
            }
        }

        $this->selected = max(0, min($this->selected, count($this->visible) - 1));
    }

    /**
     * Upstream's `visibleParentMap` and `visibleChildrenMap`, recomputed from the rows the filter
     * kept: a row's visible parent is its nearest ancestor that is shown.
     */
    private function buildVisibleMaps(): void
    {
        $shown = [];

        foreach ($this->visible as $index) {
            $shown[$this->flat[$index]['id']] = true;
        }

        $this->visibleParent = [];
        $this->visibleChildren = [];

        foreach ($this->visible as $index) {
            $id = $this->flat[$index]['id'];
            $parent = $this->parents[$id] ?? null;
            $seen = [];

            while ($parent !== null && !isset($shown[$parent]) && !isset($seen[$parent])) {
                $seen[$parent] = true;
                $parent = $this->parents[$parent] ?? null;
            }

            $this->visibleParent[$id] = $parent;
            $this->visibleChildren[$parent ?? ''][] = $id;
        }
    }

    /**
     * Upstream's `isFoldable()`: a node with visible children that is a root or the start of a
     * segment (its visible parent has more than one visible child).
     */
    private function isFoldable(string $id): bool
    {
        if (($this->visibleChildren[$id] ?? []) === []) {
            return false;
        }

        $parent = $this->visibleParent[$id] ?? null;

        return $parent === null || count($this->visibleChildren[$parent] ?? []) > 1;
    }

    /**
     * Upstream's `findBranchSegmentStart()`: up walks the visible parents to the nearest segment
     * start above the cursor; down follows first children to the next fork's first child, or the
     * branch's end.
     */
    private function findBranchSegmentStart(string $direction): int
    {
        $selectedId = $this->current();

        if ($selectedId === null) {
            return $this->selected;
        }

        $positions = [];

        foreach ($this->visible as $position => $index) {
            $positions[$this->flat[$index]['id']] = $position;
        }

        $currentId = $selectedId;

        if ($direction === 'down') {
            while (true) {
                $children = $this->visibleChildren[$currentId] ?? [];

                if ($children === []) {
                    return $positions[$currentId];
                }

                if (count($children) > 1) {
                    return $positions[$children[0]];
                }

                $currentId = $children[0];
            }
        }

        while (true) {
            $parent = $this->visibleParent[$currentId] ?? null;

            if ($parent === null) {
                return $positions[$currentId];
            }

            if (count($this->visibleChildren[$parent] ?? []) > 1 && $positions[$currentId] < $this->selected) {
                return $positions[$currentId];
            }

            $currentId = $parent;
        }
    }

    /**
     * @param array{id: string, message: mixed, label: string|null, indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool} $row
     * @param list<string> $tokens
     */
    private function passes(array $row, array $tokens): bool
    {
        $message = $row['message'];
        $isLeaf = $row['id'] === $this->leaf;

        // An answer that is only a tool call has nothing to show, so it is dropped — unless it is
        // where the conversation is, because a cursor with nowhere to sit is worse, or unless it
        // failed, because "the turn that broke" is exactly the point somebody goes back to.
        if ($message instanceof AssistantMessage && !$isLeaf) {
            $hasText = self::textOf($message->content) !== '';
            $failed = $message->stopReason !== StopReason::Stop && $message->stopReason !== StopReason::ToolUse;

            if (!$hasText && !$failed) {
                return false;
            }
        }

        $bookkeeping = $message instanceof Label
            || $message instanceof CustomEntry
            || $message instanceof ModelChange
            || $message instanceof ThinkingLevelChange;

        $passes = match ($this->filter) {
            self::USER_ONLY => $message instanceof UserMessage,
            self::NO_TOOLS => !$bookkeeping && !$message instanceof ToolResultMessage,
            self::LABELED_ONLY => $row['label'] !== null,
            self::ALL => true,
            default => !$bookkeeping,
        };

        if (!$passes) {
            return false;
        }

        if ($tokens === []) {
            return true;
        }

        $haystack = mb_strtolower($this->searchableText($row));

        foreach ($tokens as $token) {
            if (!str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{id: string, message: mixed, label: string|null, indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool} $row
     */
    private function searchableText(array $row): string
    {
        $message = $row['message'];
        $parts = [$row['label'] ?? ''];

        $parts[] = match (true) {
            $message instanceof UserMessage => 'user ' . self::textOf($message->content),
            $message instanceof AssistantMessage => 'assistant ' . self::textOf($message->content)
                . ' ' . ($message->errorMessage ?? ''),
            $message instanceof ToolResultMessage => 'toolresult ' . $message->toolName
                . ' ' . ($this->calls[$message->toolCallId] ?? ''),
            $message instanceof BashExecution => 'bash ' . $message->command,
            $message instanceof HookMessage => 'hook ' . self::textOf($message->content),
            $message instanceof CompactionSummary => 'compaction ' . $message->summary,
            $message instanceof BranchSummary => 'branch summary ' . $message->summary,
            $message instanceof CustomEntry => 'custom ' . $message->customType,
            $message instanceof ModelChange => 'model ' . $message->provider . ' ' . $message->modelId,
            $message instanceof ThinkingLevelChange => 'thinking ' . $message->level,
            $message instanceof Label => 'label ' . $message->label,
            $message instanceof SystemMessage => 'system',
            default => '',
        };

        return implode(' ', $parts);
    }

    // ---- drawing -------------------------------------------------------------------------------

    #[\Override]
    public function invalidate(): void
    {
        // Nothing is cached: at most maxVisible rows are drawn.
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->visible === []) {
            return [
                $this->searchLine($width),
                Themes::theme()->fg('muted', '  Nothing matches'),
                Themes::theme()->fg('muted', '  (0/0)' . $this->filterLabel()),
            ];
        }

        $total = count($this->visible);
        $start = max(0, min($this->selected - intdiv($this->maxVisible, 2), $total - $this->maxVisible));
        $end = min($start + $this->maxVisible, $total);
        $lines = [$this->searchLine($width)];

        for ($position = $start; $position < $end; $position++) {
            $lines[] = $this->row($this->flat[$this->visible[$position]], $position === $this->selected, $width);
        }

        $lines[] = Themes::theme()->fg(
            'muted',
            sprintf('  (%d/%d)%s', $this->selected + 1, $total, $this->filterLabel()),
        );

        return $lines;
    }

    /**
     * What is typed, above the rows.
     *
     * Drawn here rather than by whoever built the list — upstream gives it a component of its own,
     * wired to the list's `getSearchQuery()`, and here the accessor existed while the line never
     * did. The list owns the query, so the list draws it, and then there is no second end to
     * forget. **Always, even empty**, as upstream has it: a label saying `Search:` is what tells
     * anybody that typing does something, and when nothing matches it is the only way to see what
     * was typed wrong.
     */
    private function searchLine(int $width): string
    {
        $line = '  ' . Themes::theme()->fg('muted', 'Search:')
            . ($this->search === '' ? '' : ' ' . Themes::theme()->fg('accent', $this->search));

        // Cut like every other line here: a query longer than the terminal is wide would wrap and
        // push a row off the bottom of the list on each keystroke.
        return Width::truncate($line, $width, '');
    }

    /**
     * @param array{id: string, message: mixed, label: string|null, indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool} $row
     */
    private function row(array $row, bool $isSelected, int $width): string
    {
        $cursor = $isSelected ? Themes::theme()->fg('accent', '› ') : '  ';
        $marker = isset($this->active[$row['id']]) ? Themes::theme()->fg('accent', '• ') : '';
        $label = $row['label'] === null ? '' : Themes::theme()->fg('warning', "[{$row['label']}] ");
        $stamp = $this->showLabelTimestamps && $row['label'] !== null && isset($this->labelTimestamps[$row['id']])
            ? Themes::theme()->fg('muted', self::formatLabelTimestamp($this->labelTimestamps[$row['id']]) . ' ')
            : '';
        // Upstream draws the fold in the connector; a row without one (a root) gets `⊞ ` in front.
        $foldMarker = isset($this->folded[$row['id']]) && !($row['connector'] && !$row['virtual'])
            ? Themes::theme()->fg('accent', '⊞ ')
            : '';
        $text = $this->describe($row['message']);
        $line = $cursor . Themes::theme()->fg('dim', $this->prefix($row)) . $foldMarker . $marker . $label . $stamp . $text;

        return Width::truncate($isSelected ? Themes::theme()->bg('selectedBg', $line) : $line, $width, '');
    }

    /**
     * The tree art in front of a row: gutters at their own levels, then this row's connector.
     *
     * Built a column at a time because a gutter belongs to an *ancestor's* fork and has to appear at
     * that ancestor's level, which is not the same as this row's — three nested forks put three
     * `│` at three different columns, and drawing them by repeating a string puts them all at one.
     *
     * @param array{id: string, message: mixed, label: string|null, indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool} $row
     */
    private function prefix(array $row): string
    {
        $displayIndent = $this->manyRoots ? max(0, $row['indent'] - 1) : $row['indent'];
        $connector = $row['connector'] && !$row['virtual'];
        $connectorLevel = $connector ? $displayIndent - 1 : -1;
        $prefix = '';

        for ($column = 0; $column < $displayIndent * self::COLUMNS_PER_LEVEL; $column++) {
            $level = intdiv($column, self::COLUMNS_PER_LEVEL);
            $within = $column % self::COLUMNS_PER_LEVEL;

            if (array_key_exists($level, $row['gutters'])) {
                $prefix .= $within === 0 && $row['gutters'][$level] ? '│' : ' ';

                continue;
            }

            if ($level === $connectorLevel) {
                $prefix .= match ($within) {
                    0 => $row['last'] ? '└' : '├',
                    // Upstream's fold indicator: folded, foldable, or a plain connector.
                    1 => isset($this->folded[$row['id']]) ? '⊞' : ($this->isFoldable($row['id']) ? '⊟' : '─'),
                    default => ' ',
                };

                continue;
            }

            $prefix .= ' ';
        }

        return $prefix;
    }

    /** One row's text: who said it, and enough of what they said to recognise it. */
    private function describe(mixed $message): string
    {
        return match (true) {
            $message instanceof UserMessage => Themes::theme()->fg('accent', 'user: ')
                . self::oneLine(self::textOf($message->content)),
            $message instanceof AssistantMessage => Themes::theme()->fg('success', 'assistant: ')
                . $this->assistant($message),
            $message instanceof ToolResultMessage => Themes::theme()->fg(
                'muted',
                $this->calls[$message->toolCallId] ?? "[{$message->toolName}]",
            ),
            $message instanceof BashExecution => Themes::theme()->fg(
                'dim',
                '[bash]: ' . self::oneLine($message->command),
            ),
            $message instanceof HookMessage => Themes::theme()->fg('customMessageLabel', '[hook]: ')
                . self::oneLine(self::textOf($message->content)),
            $message instanceof CompactionSummary => Themes::theme()->fg(
                'borderAccent',
                sprintf('[compaction: %dk tokens]', (int) round($message->tokensBefore / 1000)),
            ),
            $message instanceof BranchSummary => Themes::theme()->fg('warning', '[branch summary]: ')
                . self::oneLine($message->summary),
            $message instanceof ModelChange => Themes::theme()->fg(
                'dim',
                "[model: {$message->provider}/{$message->modelId}]",
            ),
            $message instanceof ThinkingLevelChange => Themes::theme()->fg('dim', "[thinking: {$message->level}]"),
            // A cleared name is a `Label` entry with no label — nothing is deleted from a session
            // file — and `[label: ]` is a row that reads like a rendering fault. Upstream's word.
            $message instanceof Label => Themes::theme()->fg(
                'dim',
                '[label: ' . ($message->label ?? '(cleared)') . ']',
            ),
            $message instanceof CustomEntry => Themes::theme()->fg('dim', "[{$message->customType}]"),
            // Upstream's tree has no arm of its own for one: any other role is `[<role>]`, dim.
            $message instanceof SystemMessage => Themes::theme()->fg('dim', '[system]'),
            default => Themes::theme()->fg('dim', '[' . get_debug_type($message) . ']'),
        };
    }

    private function assistant(AssistantMessage $message): string
    {
        $text = self::oneLine(self::textOf($message->content));

        if ($text !== '') {
            return $text;
        }

        if ($message->stopReason === StopReason::Aborted) {
            return Themes::theme()->fg('muted', '(stopped)');
        }

        if ($message->errorMessage !== null) {
            return Themes::theme()->fg('error', self::oneLine(mb_substr($message->errorMessage, 0, 80)));
        }

        return Themes::theme()->fg('muted', '(no text)');
    }

    /**
     * What a message says, for a row and for the search.
     *
     * **Thinking is not part of it**, which is upstream's rule and pig's own: the tree is a list of
     * points somebody might go back to, and the model talking to itself is neither what a point
     * *is* nor something anybody read. It counted once, and thinking plus a tool call is what most
     * tool-calling turns look like on a provider that returns its reasoning — so the default tree
     * grew a row of chain-of-thought between every question and its answer, and a search matched
     * text nobody had seen. `SessionInfo::$text` leaves thinking out of the session search for the
     * same reason.
     *
     * `[image]` is pig's, and the one addition: a screenshot pasted with nothing said about it is a
     * point worth going back to, where upstream draws `user: ` and nothing after it.
     *
     * @param list<mixed> $content
     */
    private static function textOf(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = $block->text;
            } elseif ($block instanceof ImageContent) {
                $parts[] = '[image]';
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * A message's text as one row of a list.
     *
     * `Shell::sanitize()` first, and it is not belt-and-braces: this pattern has no `/u`, so it
     * neither fails on malformed UTF-8 nor removes it, and what it hands to `Width::truncate()`
     * goes into `Graphemes::split()`, which throws. `pig @notes.txt` on a latin-1 file and then
     * `/tree` was a dead session. Escapes go too, or a coloured line from a model would paint over
     * the selected row's background.
     */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\n\t]+/', ' ', Shell::sanitize($text)));
    }

    // ---- keys ----------------------------------------------------------------------------------

    /** Upstream's `TreeList.handleInput()`, every key by its keybinding id and in upstream's order. */
    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = Keybindings::getKeybindings();
        $total = count($this->visible);

        if ($kb->matches($data, 'tui.select.up')) {
            $this->selected = $this->selected === 0 ? max(0, $total - 1) : $this->selected - 1;
        } elseif ($kb->matches($data, 'tui.select.down')) {
            $this->selected = $total === 0 || $this->selected === $total - 1 ? 0 : $this->selected + 1;
        } elseif ($kb->matches($data, 'app.tree.foldOrUp')) {
            $id = $this->current();

            if ($id !== null && $this->isFoldable($id) && !isset($this->folded[$id])) {
                $this->folded[$id] = true;
                $this->applyFilter();
            } else {
                $this->selected = $this->findBranchSegmentStart('up');
            }
        } elseif ($kb->matches($data, 'app.tree.unfoldOrDown')) {
            $id = $this->current();

            if ($id !== null && isset($this->folded[$id])) {
                unset($this->folded[$id]);
                $this->applyFilter();
            } else {
                $this->selected = $this->findBranchSegmentStart('down');
            }
        } elseif ($kb->matches($data, 'tui.editor.cursorLeft') || $kb->matches($data, 'tui.select.pageUp')) {
            $this->selected = max(0, $this->selected - $this->maxVisible);
        } elseif ($kb->matches($data, 'tui.editor.cursorRight') || $kb->matches($data, 'tui.select.pageDown')) {
            $this->selected = max(0, min($total - 1, $this->selected + $this->maxVisible));
        } elseif ($kb->matches($data, 'tui.select.confirm')) {
            $id = $this->current();

            if ($id !== null && $this->onSelect !== null) {
                ($this->onSelect)($id);
            }
        } elseif ($kb->matches($data, 'app.message.copy')) {
            $index = $this->visible[$this->selected] ?? null;

            if ($this->onCopy !== null) {
                ($this->onCopy)($index === null ? null : self::copyText($this->flat[$index]['message']));
            }
        } elseif ($kb->matches($data, 'tui.select.cancel')) {
            // A search is cleared first and cancels second, so a typed search is never a trap: the
            // way out of "nothing matches" is the key already under your finger.
            if ($this->search !== '') {
                $this->search = '';
                $this->folded = [];
                $this->applyFilter();
            } else {
                ($this->onCancel ?? static fn () => null)();
            }
        } elseif ($kb->matches($data, 'app.tree.filter.default')) {
            $this->setFilter(self::DEFAULT);
        } elseif ($kb->matches($data, 'app.tree.filter.noTools')) {
            $this->toggleFilter(self::NO_TOOLS);
        } elseif ($kb->matches($data, 'app.tree.filter.userOnly')) {
            $this->toggleFilter(self::USER_ONLY);
        } elseif ($kb->matches($data, 'app.tree.filter.labeledOnly')) {
            $this->toggleFilter(self::LABELED_ONLY);
        } elseif ($kb->matches($data, 'app.tree.filter.all')) {
            $this->toggleFilter(self::ALL);
        } elseif ($kb->matches($data, 'app.tree.filter.cycleBackward')) {
            $this->cycleFilter(-1);
        } elseif ($kb->matches($data, 'app.tree.filter.cycleForward')) {
            $this->cycleFilter(1);
        } elseif ($kb->matches($data, 'tui.editor.deleteCharBackward')) {
            if ($this->search !== '') {
                $this->search = mb_substr($this->search, 0, -1);
                $this->folded = [];
                $this->applyFilter();
            }
        } elseif ($kb->matches($data, 'app.tree.editLabel')) {
            $id = $this->current();
            $index = $this->visible[$this->selected] ?? null;

            if ($id !== null && $index !== null && $this->onLabel !== null) {
                ($this->onLabel)($id, $this->flat[$index]['label']);
            }
        } elseif ($kb->matches($data, 'app.tree.toggleLabelTimestamp')) {
            $this->showLabelTimestamps = !$this->showLabelTimestamps;
        } elseif ($data !== '' && !self::hasControlCharacters($data)) {
            $this->search .= $data;
            $this->folded = [];
            $this->applyFilter();
        }
    }

    private function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->folded = [];
        $this->applyFilter();
    }

    /** Upstream's direct filter keys: the mode against the default, toggled. */
    private function toggleFilter(string $filter): void
    {
        $this->setFilter($this->filter === $filter ? self::DEFAULT : $filter);
    }

    /**
     * Upstream's `getEntryCopyText()`: what a message says, in full — a bash entry's command, a
     * failed answer's error, a summary's summary — or null when there is nothing to copy.
     */
    private static function copyText(mixed $message): ?string
    {
        $text = match (true) {
            $message instanceof BashExecution => $message->command,
            $message instanceof AssistantMessage => self::fullText($message->content) ?: ($message->errorMessage ?? ''),
            $message instanceof UserMessage, $message instanceof HookMessage => self::fullText($message->content),
            $message instanceof ToolResultMessage => self::fullText($message->content),
            $message instanceof CompactionSummary, $message instanceof BranchSummary => $message->summary,
            default => '',
        };

        return trim($text) === '' ? null : $text;
    }

    /** @param list<mixed> $content the text blocks joined, as upstream's `extractFullContent()` */
    private static function fullText(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /** Upstream's `formatLabelTimestamp()`: `14:05` today, `3/9 14:05` this year, `25/3/9 14:05` before. */
    private static function formatLabelTimestamp(int $ms): string
    {
        $date = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $time = $date->format('H:i');

        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            return $time;
        }

        if ($date->format('Y') === $now->format('Y')) {
            return $date->format('n/j') . ' ' . $time;
        }

        return $date->format('y/n/j') . ' ' . $time;
    }

    private function cycleFilter(int $by): void
    {
        $at = (int) array_search($this->filter, self::FILTERS, true);
        $count = count(self::FILTERS);
        $this->filter = self::FILTERS[(($at + $by) % $count + $count) % $count];
        $this->folded = [];
        $this->applyFilter();
    }

    /**
     * Whether this keystroke is something rather than text.
     *
     * The escape hatch for every key this class does not name: an arrow it does not handle arrives
     * as `\e[…` and would otherwise be typed into the search box one bracket at a time.
     *
     * **Two ranges, where upstream has three, and the missing one is the point.** It also refuses
     * 0x80–0x9f, the C1 controls, which is right in a language that walks UTF-16 code units and
     * wrong in one that walks bytes: 0x80–0xbf is where every UTF-8 continuation byte lives, so
     * that range would make 一 (`e4 b8 80`), 、and 退 untypeable — the search box would silently
     * ignore them. The third shape from CLAUDE.md's index, on a comparison rather than on a length.
     */
    private static function hasControlCharacters(string $data): bool
    {
        foreach (str_split($data) as $character) {
            $code = ord($character);

            if ($code < 32 || $code === 0x7F) {
                return true;
            }
        }

        return false;
    }
}
