<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Terminal;
use Pig\Tui\Tui;

/**
 * Which earlier conversation, asked before anything else starts.
 *
 * `bin/pig --resume` with no path shows this. Without it the flag needed a path nobody has
 * memorised — sessions are named after a random id under a flattened project path — so what
 * actually happened was `Could not read the session at `, with nothing after the "at".
 *
 * A screen of its own, and then it is gone: it runs before the mode is built, on a `Tui` that
 * is started and stopped inside this call, so whatever is chosen is handed back as an ordinary
 * return value and the session is opened by the same code that opens a `--resume <path>`.
 *
 * Ported from upstream's `cli/session-picker.ts`; `SessionList` beside it is the component
 * half, from `components/session-selector.ts`, and is where the searching happens. Upstream's
 * has a third key — delete — which is not here: a session file is a record of something that
 * happened, and a list you navigate with the arrow keys is the wrong place to put an
 * irreversible key.
 */
final class SessionPicker
{
    /** How many fit on screen before it stops being a list you can read. */
    private const int VISIBLE = 10;

    /**
     * Ask, and answer with the path, or null if nobody chose.
     *
     * @param list<SessionInfo> $sessions newest first, as `SessionManager::listFor()` gives them
     */
    public static function ask(array $sessions, Palette $palette, ?Terminal $terminal = null): ?string
    {
        if ($sessions === []) {
            return null;
        }

        $tui = new Tui($terminal ?? new ProcessTerminal());
        $chosen = null;

        $list = new SessionList($sessions, $palette, self::VISIBLE);

        // Both handlers stop the *loop* and nothing else. Stopping the terminal here as
        // well would stop it twice — once from the handler and once on the way out — and
        // `ProcessTerminal::stop()` writes its escape sequences and restores `stty` every
        // time it is called. One way out, one stop; the same reason `InteractiveMode` routes
        // its own quit through `stop()` rather than reaching for the terminal.
        $list->setSelectHandler(static function (string $path) use (&$chosen): void {
            $chosen = $path;
            Loop::get()->stop();
        });

        $list->setCancelHandler(static function (): void {
            Loop::get()->stop();
        });

        $tui->addChild(new Spacer(1));
        $tui->addChild(new Text(
            $palette->fg('muted', 'Pick a session — type to search, enter to open, esc to start a new one'),
            1,
            0,
        ));
        $tui->addChild(new Spacer(1));
        $tui->addChild($list);
        $tui->setFocus($list);

        // The same one fiber the program runs in afterwards: the terminal is watched through
        // `Loop::onReadable()`, so this has to be a loop that turns and not a `readline()`.
        Async::run(static function () use ($tui): void {
            $tui->start();
            Loop::get()->run();
        });

        // The one stop, whichever way it ended: what follows either draws its own screen or
        // has no screen at all, and either way it starts from a terminal nobody else holds.
        $tui->stop();

        return $chosen;
    }

    /**
     * One line per session: what was said first, and when.
     *
     * The opening line rather than the id, because the id is six random bytes and the thing
     * anyone remembers about a conversation is how it started.
     *
     * @param list<SessionInfo> $sessions
     * @return list<SelectItem>
     */
    public static function items(array $sessions): array
    {
        $items = [];

        foreach ($sessions as $index => $info) {
            $items[] = new SelectItem(
                (string) $index,
                $info->opening === '' ? '(nothing was said)' : $info->opening,
                $info->when() . ' · ' . $info->messages . ' messages',
            );
        }

        return $items;
    }
}
