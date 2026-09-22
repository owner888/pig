#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * What is on the clipboard, and what Ctrl+V would do with it.
 *
 *   php examples/clipboard.php
 *
 * Copy a picture first — a screenshot, or an image from a browser — and this reports the
 * file it would have written and the line it would have typed into the prompt. With no
 * picture on the clipboard it falls back to the text, which is what Ctrl+V does too.
 *
 * There is no portable way to read a clipboard, so this is also the quickest way to find
 * out whether this machine has one of the tools that can: macOS uses `pngpaste` when it
 * is installed and AppleScript when it is not, Wayland uses `wl-paste`, X11 uses `xclip`,
 * and WSL uses PowerShell because a Windows screenshot never reaches the Linux clipboard.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pig\Tui\Clipboard\ClipboardFile;
use Pig\Tui\Clipboard\SystemClipboard;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Style;

$clipboard = new SystemClipboard();
$image = $clipboard->image();

if ($image !== null) {
    $path = ClipboardFile::write($image);
    $size = ImageDimensions::of(base64_encode($image->bytes), $image->mimeType);
    $pixels = $size === null ? 'size unknown' : "{$size->widthPx}x{$size->heightPx}";

    echo Style::green('image on the clipboard'), ': ', $image->mimeType, ', ',
        number_format(strlen($image->bytes)), " bytes, {$pixels}\n";

    if ($path === null) {
        echo Style::red('could not write it to a file'), "\n";

        exit(1);
    }

    echo "written to: {$path}\n";
    echo 'Ctrl+V would type: ', Style::dim($path), "\n";

    exit(0);
}

$text = $clipboard->text();

if ($text === null || $text === '') {
    echo Style::dim("nothing on the clipboard, or no tool on this machine can read it\n");

    exit(0);
}

$lines = substr_count($text, "\n") + 1;

echo Style::green('text on the clipboard'), ': ', number_format(strlen($text)),
    " bytes over {$lines} line", $lines === 1 ? '' : 's', "\n";
echo 'Ctrl+V would type: ', Style::dim(str_replace("\n", '⏎', mb_strimwidth($text, 0, 60, '…'))), "\n";
