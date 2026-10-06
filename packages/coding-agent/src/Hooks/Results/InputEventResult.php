<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\Ai\ImageContent;

/**
 * What an `input` handler decided. Upstream's three-armed union, as three constructors:
 * `continue()` (send it as it is), `transform($text)` (send this instead, and let the next
 * handler see it), `handled()` (the extension dealt with it; send nothing).
 */
final readonly class InputEventResult
{
    /** @param list<ImageContent>|null $images null keeps the images that came in */
    private function __construct(
        public string $action,
        public ?string $text = null,
        public ?array $images = null,
    ) {
    }

    public static function continue(): self
    {
        return new self('continue');
    }

    /** @param list<ImageContent>|null $images */
    public static function transform(string $text, ?array $images = null): self
    {
        return new self('transform', $text, $images);
    }

    public static function handled(): self
    {
        return new self('handled');
    }
}
