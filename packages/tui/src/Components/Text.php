<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\TextWrap;
use Pig\Tui\Width;

/**
 * A block of wrapped text, padded out to the full width.
 *
 * Every line comes back padded even when nothing asked for a background, because the
 * renderer compares lines for equality: a line that is sometimes padded and sometimes not
 * differs from itself and gets redrawn for no reason.
 */
class Text implements Component
{
    /** @var list<string>|null */
    private ?array $cachedLines = null;

    private ?string $cachedText = null;

    private ?int $cachedWidth = null;

    /** @param Closure(string): string|null $background applied to each line, padding included */
    public function __construct(
        protected string $text = '',
        private readonly int $paddingX = 1,
        private readonly int $paddingY = 1,
        private ?Closure $background = null,
    ) {
    }

    public function setText(string $text): void
    {
        $this->text = $text;
        $this->invalidate();
    }

    public function text(): string
    {
        return $this->text;
    }

    /** @param Closure(string): string|null $background */
    public function setBackground(?Closure $background): void
    {
        $this->background = $background;
        $this->invalidate();
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->cachedLines = null;
        $this->cachedText = null;
        $this->cachedWidth = null;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->cachedLines !== null && $this->cachedText === $this->text && $this->cachedWidth === $width) {
            return $this->cachedLines;
        }

        return $this->cache($width, trim($this->text) === '' ? [] : $this->lines($width));
    }

    /** @return list<string> */
    private function lines(int $width): array
    {
        // At least one column of content: a padding wider than the terminal would
        // otherwise ask the wrapper for a negative width.
        $contentWidth = max(1, $width - $this->paddingX * 2);
        $margin = str_repeat(' ', $this->paddingX);
        $blank = $this->pad('', $width);

        $lines = array_fill(0, $this->paddingY, $blank);

        foreach (TextWrap::wrap(str_replace("\t", '   ', $this->text), $contentWidth) as $line) {
            $lines[] = $this->pad($margin . $line . $margin, $width);
        }

        for ($index = 0; $index < $this->paddingY; $index++) {
            $lines[] = $blank;
        }

        return $lines;
    }

    protected function pad(string $line, int $width): string
    {
        if ($this->background !== null) {
            return Width::background($line, $width, $this->background);
        }

        return $line . str_repeat(' ', max(0, $width - Width::visible($line)));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function cache(int $width, array $lines): array
    {
        $this->cachedText = $this->text;
        $this->cachedWidth = $width;
        $this->cachedLines = $lines;

        return $lines;
    }
}
