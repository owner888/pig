<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

/**
 * Highlights code for one owner, such as a tool row, and reuses the last result while the code,
 * the language and the theme stay the same — upstream's `CodeHighlighter` in `theme.ts`. Each
 * owner keeps its own, so a cached result lives as long as the code it shows instead of in a
 * process-wide cache.
 *
 * Upstream also compares the number of registered grammars, which grows when its deferred
 * grammars load; pig's grammars are a fixed table, so there is nothing to compare.
 */
final class CodeHighlighter
{
    private ?string $code = null;

    private ?string $language = null;

    private ?Theme $theme = null;

    /** @var list<string> */
    private array $lines = [];

    /**
     * `Themes::highlightCode()`, or its last answer when nothing it depends on has changed.
     * Renderers cannot tell a theme change from any other redraw, so this checks the theme itself.
     *
     * @return list<string>
     */
    public function highlight(string $code, ?string $language): array
    {
        $theme = Themes::theme();

        if ($this->code === $code && $this->language === $language && $this->theme === $theme) {
            return $this->lines;
        }

        $this->lines = Themes::highlightCode($code, $language);
        $this->code = $code;
        $this->language = $language;
        $this->theme = $theme;

        return $this->lines;
    }
}
