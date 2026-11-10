<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `Group`: a subgraph, and the one it sits in.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Group
{
    public function __construct(public string $id, public string $label, public ?int $parent)
    {
    }
}
