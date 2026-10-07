<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\Keybindings;
use Pig\Tui\Width;

/**
 * The transcript search box `TuiAltScreen` shows as an overlay — upstream's
 * `AltScreenSearchComponent` in `alt-screen-search.ts`: a framed input, the result count, and
 * previous/next buttons in the bottom rule that take a click.
 *
 * Upstream's `focused` is an accessor that forwards to the input; PHP 8.3 has none, so the
 * value is handed down when the box renders and when it takes a key.
 */
final class AltScreenSearchComponent implements Component, Focusable, InputHandler
{
    public bool $focused = false;

    private readonly Input $input;

    /** @var Closure(string): void */
    private readonly Closure $onQueryChange;

    /** @var Closure(string, bool): string */
    private readonly Closure $navigationButtonStyle;

    private int $resultCount = 0;

    private int $resultIndex = -1;

    private int $previousButtonStart = -1;

    private int $previousButtonEnd = -1;

    private int $nextButtonStart = -1;

    private int $nextButtonEnd = -1;

    /** @var -1|1|null */
    private ?int $hoveredNavigationDirection = null;

    /**
     * @param Closure(string): void $onQueryChange
     * @param (Closure(string, bool): string)|null $navigationButtonStyle
     */
    public function __construct(Closure $onQueryChange, ?Closure $navigationButtonStyle = null)
    {
        $this->input = new Input(
            prompt: ' ',
            placeholder: 'Find in transcript',
            placeholderStyle: static fn (string $text): string => "\x1b[2m{$text}\x1b[22m",
        );
        $this->onQueryChange = $onQueryChange;
        $this->navigationButtonStyle = $navigationButtonStyle ?? static fn (string $text, bool $hovered): string => $text;
    }

    public function setResult(int $index, int $count): void
    {
        $this->resultIndex = $index;
        $this->resultCount = $count;
    }

    /** @return -1|1|null */
    public function getNavigationDirectionAt(int $row, int $column): ?int
    {
        if ($row !== 2) {
            return null;
        }
        if ($column >= $this->previousButtonStart && $column < $this->previousButtonEnd) {
            return -1;
        }
        if ($column >= $this->nextButtonStart && $column < $this->nextButtonEnd) {
            return 1;
        }

        return null;
    }

    /** @param -1|1|null $direction */
    public function setHoveredNavigationDirection(?int $direction): bool
    {
        if ($direction === $this->hoveredNavigationDirection) {
            return false;
        }
        $this->hoveredNavigationDirection = $direction;

        return true;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $this->input->focused = $this->focused;
        $previous = $this->input->value();
        $this->input->handleInput($data);
        $query = $this->input->value();
        if ($query !== $previous) {
            ($this->onQueryChange)($query);
        }
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->input->invalidate();
    }

    #[\Override]
    public function render(int $width): array
    {
        $this->input->focused = $this->focused;
        $safeWidth = max(1, $width);
        $innerWidth = max(0, $safeWidth - 2);
        $formatKey = static fn (?string $key): string => $key === null
            ? 'Unbound'
            : implode('+', array_map(
                static fn (string $part): string => PHP_OS_FAMILY === 'Darwin' && strtolower($part) === 'alt' ? 'Option' : ucfirst($part),
                explode('+', $key),
            ));
        $keybindings = Keybindings::getKeybindings();
        $previousKey = $formatKey($keybindings->getKeys('tui.altScreen.searchPrevious')[0] ?? null);
        $nextKey = $formatKey($keybindings->getKeys('tui.altScreen.searchNext')[0] ?? null);
        $query = $this->input->value();
        $result = $query === '' ? '' : ($this->resultCount === 0 ? 'No matches' : ($this->resultIndex + 1) . '/' . $this->resultCount);
        $resultSpace = max(0, $innerWidth - 3);
        $visibleResult = Width::truncate($result, $resultSpace, '');
        $resultText = $visibleResult !== '' ? "\x1b[2m {$visibleResult} \x1b[22m" : '';
        $inputWidth = max(0, $innerWidth - Width::visible($resultText));
        $inputLine = Width::truncate($this->input->render(max(1, $inputWidth))[0] ?? '', $inputWidth, '');
        $inputPadding = str_repeat(' ', max(0, $inputWidth - Width::visible($inputLine)));
        $content = $inputLine . $inputPadding . $resultText;

        $previousButton = "↑ {$previousKey}";
        $nextButton = "↓ {$nextKey}";
        $separator = ' · ';
        $outerGapWidth = 1;
        $availableControlsWidth = max(0, $innerWidth - $outerGapWidth * 2 - 1);
        $controlsWidth = Width::visible($previousButton) + Width::visible($separator) + Width::visible($nextButton);
        if ($controlsWidth > $availableControlsWidth) {
            $previousButton = '↑';
            $nextButton = '↓';
            $separator = ' ';
            $controlsWidth = Width::visible($previousButton) + Width::visible($separator) + Width::visible($nextButton);
        }
        $showButtons = $controlsWidth <= $availableControlsWidth;
        $renderedButtons = $showButtons
            ? ($this->navigationButtonStyle)($previousButton, $this->hoveredNavigationDirection === -1)
                . $separator
                . ($this->navigationButtonStyle)($nextButton, $this->hoveredNavigationDirection === 1)
            : '';
        $outerGapsWidth = $showButtons ? $outerGapWidth * 2 : 0;
        $rightRuleWidth = $renderedButtons !== '' && $innerWidth > $controlsWidth + $outerGapsWidth ? 1 : 0;
        $leftRuleWidth = max(0, $innerWidth - ($showButtons ? $controlsWidth : 0) - $outerGapsWidth - $rightRuleWidth);
        $previousStart = 1 + $leftRuleWidth + $outerGapWidth;
        $this->previousButtonStart = $showButtons ? $previousStart : -1;
        $this->previousButtonEnd = $showButtons ? $previousStart + Width::visible($previousButton) : -1;
        $this->nextButtonStart = $showButtons ? $this->previousButtonEnd + Width::visible($separator) : -1;
        $this->nextButtonEnd = $showButtons ? $this->nextButtonStart + Width::visible($nextButton) : -1;

        if ($safeWidth === 1) {
            return ['┌', '│', '└'];
        }
        $gap = $renderedButtons !== '' ? ' ' : '';

        return [
            '┌' . str_repeat('─', $innerWidth) . '┐',
            '│' . $content . '│',
            '└' . str_repeat('─', $leftRuleWidth) . $gap . $renderedButtons . $gap . str_repeat('─', $rightRuleWidth) . '┘',
        ];
    }
}
