<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Upstream's `ImagesContext`: the prompt, as text and reference images. */
final readonly class ImagesContext
{
    /** @param list<TextContent|ImageContent> $input */
    public function __construct(
        public array $input,
    ) {
    }
}
