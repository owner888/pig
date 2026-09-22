<?php

declare(strict_types=1);

namespace Pig\Tui\Clipboard;

/**
 * A pasted picture, put somewhere the agent can reach it.
 *
 * A terminal cannot carry image bytes in a line of text, and the agent's tools work on
 * paths. So a picture pasted into the prompt becomes a file, and what goes into the
 * prompt is that file's name — which reads as a sentence ("what is wrong in
 * /tmp/pig-clipboard-x.png") and works with every tool that already takes a path.
 *
 * The files are left behind deliberately: the agent reads them after the prompt is sent,
 * so deleting one at paste time would delete it before anything looked at it. They land
 * in the system temp directory, which is what cleans them up.
 */
final class ClipboardFile
{
    private const string PREFIX = 'pig-clipboard-';

    /**
     * Write $image to a new file and return its path, or null if it could not be written.
     *
     * @param string|null $directory defaults to the system temp directory
     */
    public static function write(ClipboardImage $image, ?string $directory = null): ?string
    {
        $extension = $image->extension();

        if ($extension === null || $image->bytes === '') {
            return null;
        }

        $directory ??= sys_get_temp_dir();
        $path = rtrim($directory, '/') . '/' . self::PREFIX . bin2hex(random_bytes(8)) . '.' . $extension;

        return file_put_contents($path, $image->bytes) === false ? null : $path;
    }
}
