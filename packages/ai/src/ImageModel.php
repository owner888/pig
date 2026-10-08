<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * An image-generation model: usable with `Models::generateImages()` only — upstream's
 * `ImageModel`, `BaseModel` plus `output`.
 */
final readonly class ImageModel
{
    /**
     * @param list<'text'|'image'> $input what the model accepts
     * @param list<'text'|'image'> $output "Always includes `"image"`; `"text"` means the model can
     *        also return text blocks"
     * @param array<string, string> $headers extra headers every request to it carries
     * @param array<string, mixed>|null $inputLimits upstream's `inputLimits`, which the generator's
     *        `applyImageInputMetadata()` writes on an image model that takes images
     */
    public function __construct(
        public string $id,
        public string $name,
        public ImageApi $api,
        public string $provider,
        public string $baseUrl,
        public array $input = ['text'],
        public array $output = ['image'],
        public Pricing $pricing = new Pricing(),
        public array $headers = [],
        public ?array $inputLimits = null,
    ) {
    }
}
