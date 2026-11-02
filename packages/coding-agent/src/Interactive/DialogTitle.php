<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Theme\Themes;

/**
 * A dialog's title, kept short enough that the choices under it stay on screen.
 *
 * A title can be anything a hook passed — the permission gate puts the whole command in it, and a
 * command can be a hundred-line heredoc. In fullscreen the dock shrinks what does not fit from the
 * bottom, so the Yes / No under such a title was cut away: the dialog had the keys, the turn waited
 * on it, and there was nothing on screen to answer. In the scrolling screen it pushed the top of the
 * dialog out of view instead. So past `$maxLines` the title keeps its head and its tail — the start
 * of a command and the end of it are what tell you what it does — and says how much is between.
 */
final class DialogTitle extends ThemedText
{
    /**
     * @param Closure(): string $build
     * @param Closure(): int $maxLines read at each render, so a resized terminal is followed
     */
    public function __construct(Closure $build, private readonly Closure $maxLines)
    {
        parent::__construct($build, 1, 0);
    }

    #[\Override]
    public function render(int $width): array
    {
        $lines = parent::render($width);
        $max = max(3, ($this->maxLines)());
        $hidden = count($lines) - $max;

        if ($hidden <= 0) {
            return $lines;
        }

        // One row goes to the marker, and so one more line than that is out of sight.
        $hidden++;
        $shown = $max - 1;
        $head = (int) ceil($shown * 2 / 3);
        $tail = $shown - $head;
        $marker = ' ' . Themes::theme()->fg('dim', "… {$hidden} more lines …");

        return [
            ...array_slice($lines, 0, $head),
            $marker,
            ...($tail > 0 ? array_slice($lines, -$tail) : []),
        ];
    }
}
