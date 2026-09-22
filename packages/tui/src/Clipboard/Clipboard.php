<?php

declare(strict_types=1);

namespace Pig\Tui\Clipboard;

/**
 * What the system clipboard holds.
 *
 * An interface because reading it means running other programs, which a test must not do
 * and a sandbox may not allow. Both methods answer null for "nothing of that kind here",
 * which covers an empty clipboard and a machine with no way to read one — the caller does
 * the same thing either way.
 */
interface Clipboard
{
    public function text(): ?string;

    public function image(): ?ClipboardImage;
}
