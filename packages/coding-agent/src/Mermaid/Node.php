<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `Node`. `shape` is `rect`, `round` or `diamond`.
 *
 * `sections` are a class or ER box's compartments, formatted by the parser: a list of rows per
 * compartment (title, attributes, methods), drawn as they are between rules. `classes` are the
 * author's class names and `href` the node's link, carried out through `Span`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Node
{
    /**
     * @param list<list<string>>|null $sections
     * @param list<string>|null $classes
     */
    public function __construct(
        public string $label,
        public string $shape,
        public ?array $sections = null,
        public ?array $classes = null,
        public ?string $href = null,
    ) {
    }
}
