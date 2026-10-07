<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * Upstream's `PendingTerminalColorQuery` in `tui.ts`: one `queryTerminalColors()` waiting for its
 * replies.
 *
 * @internal
 */
final class PendingTerminalColorQuery
{
    public ?RgbColor $foreground = null;

    public ?RgbColor $background = null;

    /** @var array<int, RgbColor|null> */
    public array $palette = [];

    /** @var array<string, true> targets that already replied, so duplicates do not count twice */
    public array $replied = [];

    /**
     * Receives the result: the future's completion until the timeout, then `onLateReply`. Null
     * once the query completed (on the DA1 reply or once every color replied); later replies are
     * ignored.
     *
     * @var (Closure(TerminalColors): void)|null
     */
    public ?Closure $deliver = null;

    public ?string $timer = null;
}
