<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Antigravity;

/**
 * Result of an image generation call.
 */
final readonly class GeneratedImageResult
{
    /**
     * @param list<string>                                $savedPaths paths on disk where images were saved
     * @param list<array{data: string, mimeType: string}> $images     base64 data and mime type for each image
     * @param list<string>                                $text       any explanatory text returned
     * @param string                                      $model      the model that generated the image
     */
    public function __construct(
        public array $savedPaths,
        public array $images,
        public array $text,
        public string $model,
    ) {
    }
}
