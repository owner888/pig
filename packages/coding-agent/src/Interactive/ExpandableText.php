<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;

/**
 * A `ThemedText` with a collapsed and an expanded form, switched by ctrl+o — upstream's `ExpandableText`
 * (a private class in `interactive-mode.ts`, whose `BuiltInHeader` subclass is the header). Here it is
 * the header and each loaded-resources section, as upstream's `showLoadedResources()` builds them.
 *
 * Upstream keeps the flag in a `state` object because TypeScript cannot touch `this` before `super()`;
 * PHP can hand the parent a closure over `$this`, so the flag is a plain property.
 */
final class ExpandableText extends ThemedText
{
    /**
     * @param Closure(): string $getCollapsedText
     * @param Closure(): string $getExpandedText
     */
    public function __construct(
        Closure $getCollapsedText,
        Closure $getExpandedText,
        private bool $expanded = false,
        int $paddingX = 0,
        int $paddingY = 0,
    ) {
        parent::__construct(
            fn (): string => $this->expanded ? $getExpandedText() : $getCollapsedText(),
            $paddingX,
            $paddingY,
        );
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
        $this->invalidate();
    }
}
