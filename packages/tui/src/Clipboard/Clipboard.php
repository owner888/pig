<?php

declare(strict_types=1);

namespace Pig\Tui\Clipboard;

/**
 * What the system clipboard holds.
 *
 * An interface because reading it means running other programs, which a test must not do
 * and a sandbox may not allow. The two readers answer null for "nothing of that kind
 * here", which covers an empty clipboard and a machine with no way to read one — the
 * caller does the same thing either way.
 */
interface Clipboard
{
    public function text(): ?string;

    public function image(): ?ClipboardImage;

    /**
     * The files a file manager copied — Finder's, or Telegram's photo, which it puts on the
     * clipboard as the file it saved — by path; null when there are none. Upstream's
     * `readClipboardFilePaths()`.
     *
     * @return list<string>|null
     */
    public function filePaths(): ?array;

    /**
     * Put text on the clipboard.
     *
     * Returns false rather than throwing when there is no way to: a machine without
     * `xclip` is not a broken machine, and the caller says so once instead of unwinding.
     */
    public function write(string $text): bool;
}
