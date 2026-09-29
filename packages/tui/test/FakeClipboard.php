<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Clipboard\ClipboardImage;

/** A clipboard holding exactly what a test put on it. */
final class FakeClipboard implements Clipboard
{
    public int $imageReads = 0;

    /** What was last written to it, so a test can see what a copy would have put there. */
    public ?string $written = null;

    /** Set false to stand in for a machine with no way to copy. */
    public bool $writable = true;

    public function __construct(private ?string $text = null, private ?ClipboardImage $image = null)
    {
    }

    #[\Override]
    public function write(string $text): bool
    {
        if (!$this->writable) {
            return false;
        }

        $this->written = $text;

        return true;
    }

    #[\Override]
    public function text(): ?string
    {
        return $this->text;
    }

    #[\Override]
    public function image(): ?ClipboardImage
    {
        $this->imageReads++;

        return $this->image;
    }
}
