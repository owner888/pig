<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `Span`: a run of adjacent cells sharing one semantic class — `border`, `text`,
 * `edge`, `edgeLabel`, `title` or `none`. The renderer never knows about colour; the caller maps
 * the class to its theme.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final readonly class Span
{
    public function __construct(public string $text, public string $cls)
    {
    }
}
