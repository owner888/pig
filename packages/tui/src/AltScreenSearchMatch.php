<?php

declare(strict_types=1);

namespace Pig\Tui;

/** One transcript search match, possibly spanning rows — upstream's `AltScreenSearchMatch`. */
final readonly class AltScreenSearchMatch
{
    /** @param list<AltScreenSearchSegment> $segments */
    public function __construct(public array $segments)
    {
    }
}
