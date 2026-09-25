<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
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
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Component;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
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
 * what they named, `all` hides nothing. Anything else typed is a search, matched against every
 * token; escape clears it before it cancels, so a search is not a trap.
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

    /**
     * @param list<array{id: string, message: mixed, label: string|null, children: list<array<string, mixed>>}> $tree
     * @param string|null $leaf the point the conversation is at, which is where the cursor starts
     */
    public function __construct(
        array $tree,
        private readonly ?string $leaf,
        private readonly int $maxVisible,
        private readonly Palette $palette,
    ) {
        $this->manyRoots = count($tree) > 1;
        $this->flat = $this->flatten($tree);
        $this->buildActivePath();
        $this->applyFilter();

        foreach ($this->visible as $position => $index) {
            if ($this->flat[$index]['id'] === $leaf) {
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

    /** @param Closure(string, ?string): void|null $handler asked for on `l`, with the current name */
    public function setLabelHandler(?Closure $handler): void
    {
        $this->onLabel = $handler;
    }

    /** What is typed, for whoever draws the search line. */
    public function search(): string
    {
        return $this->search;
    }

    /** `[no-tools]`, or the empty string in the default view. */
    public function filterLabel(): string
    {
        return $this->filter === self::DEFAULT ? '' : " [{$this->filter}]";
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
                $this->palette->fg('muted', '  Nothing matches'),
                $this->palette->fg('muted', '  (0/0)' . $this->filterLabel()),
            ];
        }

        $total = count($this->visible);
        $start = max(0, min($this->selected - intdiv($this->maxVisible, 2), $total - $this->maxVisible));
        $end = min($start + $this->maxVisible, $total);
        $lines = [];

        for ($position = $start; $position < $end; $position++) {
            $lines[] = $this->row($this->flat[$this->visible[$position]], $position === $this->selected, $width);
        }

        $lines[] = $this->palette->fg(
            'muted',
            sprintf('  (%d/%d)%s', $this->selected + 1, $total, $this->filterLabel()),
        );

        return $lines;
    }

    /**
     * @param array{id: string, message: mixed, label: string|null, indent: int, connector: bool, last: bool, gutters: array<int, bool>, virtual: bool} $row
     */
    private function row(array $row, bool $isSelected, int $width): string
    {
        $cursor = $isSelected ? $this->palette->fg('accent', '› ') : '  ';
        $marker = isset($this->active[$row['id']]) ? $this->palette->fg('accent', '• ') : '';
        $label = $row['label'] === null ? '' : $this->palette->fg('warning', "[{$row['label']}] ");
        $text = $this->describe($row['message']);
        $line = $cursor . $this->palette->fg('dim', $this->prefix($row)) . $marker . $label . $text;

        return Width::truncate($isSelected ? $this->palette->bg('selectedBg', $line) : $line, $width, '');
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
                    1 => '─',
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
            $message instanceof UserMessage => $this->palette->fg('accent', 'user: ')
                . self::oneLine(self::textOf($message->content)),
            $message instanceof AssistantMessage => $this->palette->fg('success', 'assistant: ')
                . $this->assistant($message),
            $message instanceof ToolResultMessage => $this->palette->fg(
                'muted',
                $this->calls[$message->toolCallId] ?? "[{$message->toolName}]",
            ),
            $message instanceof BashExecution => $this->palette->fg(
                'dim',
                '[bash]: ' . self::oneLine($message->command),
            ),
            $message instanceof HookMessage => $this->palette->fg('customMessageLabel', '[hook]: ')
                . self::oneLine(self::textOf($message->content)),
            $message instanceof CompactionSummary => $this->palette->fg(
                'borderAccent',
                sprintf('[compaction: %dk tokens]', (int) round($message->tokensBefore / 1000)),
            ),
            $message instanceof BranchSummary => $this->palette->fg('warning', '[branch summary]: ')
                . self::oneLine($message->summary),
            $message instanceof ModelChange => $this->palette->fg(
                'dim',
                "[model: {$message->provider}/{$message->modelId}]",
            ),
            $message instanceof ThinkingLevelChange => $this->palette->fg('dim', "[thinking: {$message->level}]"),
            $message instanceof Label => $this->palette->fg('dim', "[label: {$message->label}]"),
            $message instanceof CustomEntry => $this->palette->fg('dim', "[{$message->customType}]"),
            default => $this->palette->fg('dim', '[' . get_debug_type($message) . ']'),
        };
    }

    private function assistant(AssistantMessage $message): string
    {
        $text = self::oneLine(self::textOf($message->content));

        if ($text !== '') {
            return $text;
        }

        if ($message->stopReason === StopReason::Aborted) {
            return $this->palette->fg('muted', '(stopped)');
        }

        if ($message->errorMessage !== null) {
            return $this->palette->fg('error', self::oneLine(mb_substr($message->errorMessage, 0, 80)));
        }

        return $this->palette->fg('muted', '(no text)');
    }

    /** @param list<mixed> $content */
    private static function textOf(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = $block->text;
            } elseif ($block instanceof ThinkingContent) {
                $parts[] = $block->thinking;
            } elseif ($block instanceof ImageContent) {
                $parts[] = '[image]';
            }
        }

        return trim(implode(' ', $parts));
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $text));
    }

    // ---- keys ----------------------------------------------------------------------------------

    #[\Override]
    public function handleInput(string $data): void
    {
        $total = count($this->visible);

        if (Keys::isArrowUp($data)) {
            $this->selected = $this->selected === 0 ? max(0, $total - 1) : $this->selected - 1;

            return;
        }

        if (Keys::isArrowDown($data)) {
            $this->selected = $total === 0 || $this->selected === $total - 1 ? 0 : $this->selected + 1;

            return;
        }

        // Left and right are a page at a time, which is upstream's choice and the right one: there
        // is nothing to expand or collapse — every row is already shown.
        if (Keys::isArrowLeft($data)) {
            $this->selected = max(0, $this->selected - $this->maxVisible);

            return;
        }

        if (Keys::isArrowRight($data)) {
            $this->selected = max(0, min($total - 1, $this->selected + $this->maxVisible));

            return;
        }

        if (Keys::isEnter($data)) {
            $id = $this->current();

            if ($id !== null && $this->onSelect !== null) {
                ($this->onSelect)($id);
            }

            return;
        }

        if (Keys::isEscape($data)) {
            // A search is cleared first and cancels second, so a typed search is never a trap: the
            // way out of "nothing matches" is the key already under your finger.
            if ($this->search !== '') {
                $this->search = '';
                $this->applyFilter();

                return;
            }

            ($this->onCancel ?? static fn () => null)();

            return;
        }

        if (Keys::isCtrlC($data)) {
            ($this->onCancel ?? static fn () => null)();

            return;
        }

        if (Keys::isShiftCtrlO($data)) {
            $this->cycleFilter(-1);

            return;
        }

        if (Keys::isCtrlO($data)) {
            $this->cycleFilter(1);

            return;
        }

        if (Keys::isBackspace($data)) {
            if ($this->search !== '') {
                $this->search = mb_substr($this->search, 0, -1);
                $this->applyFilter();
            }

            return;
        }

        // `l` names the point under the cursor — but only when nothing is being searched for, or a
        // search for "label" could never be typed.
        if ($data === 'l' && $this->search === '') {
            $id = $this->current();
            $index = $this->visible[$this->selected] ?? null;

            if ($id !== null && $index !== null && $this->onLabel !== null) {
                ($this->onLabel)($id, $this->flat[$index]['label']);
            }

            return;
        }

        if ($data !== '' && !self::hasControlCharacters($data)) {
            $this->search .= $data;
            $this->applyFilter();
        }
    }

    private function cycleFilter(int $by): void
    {
        $at = (int) array_search($this->filter, self::FILTERS, true);
        $count = count(self::FILTERS);
        $this->filter = self::FILTERS[(($at + $by) % $count + $count) % $count];
        $this->applyFilter();
    }

    /**
     * Whether this keystroke is something rather than text.
     *
     * The escape hatch for every key this class does not name: an arrow it does not handle arrives
     * as `\e[…` and would otherwise be typed into the search box one bracket at a time.
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
