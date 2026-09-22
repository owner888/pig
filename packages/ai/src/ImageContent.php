<?php

declare(strict_types=1);

namespace Pig\Ai;

/** An image, inline. Not streamed — models take images, they do not emit them. */
final readonly class ImageContent implements UserContent
{
    /**
     * @param string $data     base64-encoded image bytes
     * @param string $mimeType e.g. image/png, image/jpeg
     */
    public function __construct(
        public string $data,
        public string $mimeType,
    ) {
    }
}
