<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `Span`: a run of adjacent cells sharing one role and one set of author
 * classes. The role is what a cell is — `border`, `text`, `edge`, `edgeLabel`, `title` or `none` —
 * and the renderer never knows about colour; the caller maps the role to its theme (`Ansi`).
 *
 * `classes` are the author's class names of the node the cells belong to (`:::name`, `class A
 * name`), uninterpreted — pair them with `MermaidArt::$classDefs`. `href` is the node's link
 * (`click A "url"`, `link A "url"`). Both are null on cells that have none.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final readonly class Span
{
    /** @param list<string>|null $classes */
    public function __construct(
        public string $text,
        public string $role,
        public ?array $classes = null,
        public ?string $href = null,
    ) {
    }
}
