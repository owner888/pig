<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `ClassInfo`: the compartments of a class or ER box.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class ClassInfo
{
    /**
     * @param list<string> $attrs
     * @param list<string> $methods
     */
    public function __construct(public ?string $annotation = null, public array $attrs = [], public array $methods = [])
    {
    }
}
