<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `Node`. `shape` is `rect`, `round` or `diamond`.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Node
{
    public function __construct(public string $label, public string $shape)
    {
    }
}
