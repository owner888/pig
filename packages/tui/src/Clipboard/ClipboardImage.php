<?php

declare(strict_types=1);

namespace Pig\Tui\Clipboard;

/** A picture taken off the clipboard, with the format it came in. */
final readonly class ClipboardImage
{
    public function __construct(public string $bytes, public string $mimeType)
    {
    }

    /** The file extension for this format, or null for one nothing here can name. */
    public function extension(): ?string
    {
        return match ($this->mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }
}
