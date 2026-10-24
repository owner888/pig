<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * "Compatibility settings for System One classifier APIs" — upstream's `SystemOneCompat`, on a
 * classifier model of the `typesafe-system-one` or `cloudflare-workers-ai-system-one` API. A field
 * left null is auto-detected from the provider, the base URL and the model id
 * (`SystemOneTransport::detectCompat()`).
 */
final readonly class SystemOneCompat
{
    /** TypeSafe's `assets` map (PNG and JPEG), the images referenced by name from the state. */
    public const string ASSETS = 'assets';

    /** Clef's `images` list of data URLs (PNG, JPEG and WebP, at most four). */
    public const string IMAGES = 'images';

    /** `image_url` parts placed before the state, as OpenRouter takes them; it drops `assets` silently. */
    public const string STATE_IMAGE_URLS = 'state-image-urls';

    /**
     * @param self::ASSETS|self::IMAGES|self::STATE_IMAGE_URLS|null $imageInput "How the service takes
     *        classifier images."
     * @param bool|null $sendImageOptInHeader "Whether requests with images send the
     *        TypeSafe-Danger-Zone-Images: true header, which TypeSafe's own API requires for its
     *        experimental image input."
     */
    public function __construct(
        public ?string $imageInput = null,
        public ?bool $sendImageOptInHeader = null,
    ) {
    }

    /** One from `models.json` or a catalog entry; null for anything that is not an object of these. */
    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            return null;
        }

        $value = (array) $value;
        $input = $value['imageInput'] ?? null;
        $header = $value['sendImageOptInHeader'] ?? null;
        $compat = new self(
            in_array($input, [self::ASSETS, self::IMAGES, self::STATE_IMAGE_URLS], true) ? $input : null,
            is_bool($header) ? $header : null,
        );

        return $compat->imageInput === null && $compat->sendImageOptInHeader === null ? null : $compat;
    }

    /** @return array<string, string|bool> the fields that are set, as the JSON carries them */
    public function toArray(): array
    {
        return array_filter(
            ['imageInput' => $this->imageInput, 'sendImageOptInHeader' => $this->sendImageOptInHeader],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /** This one's fields over `$base`'s — a model's compat over its provider's. */
    public function over(?self $base): self
    {
        return new self($this->imageInput ?? $base?->imageInput, $this->sendImageOptInHeader ?? $base?->sendImageOptInHeader);
    }
}
