<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Session\SessionInfo;
use Pig\CodingAgent\Session\SessionManager;
use Pig\Tui\Components\Spacer;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Terminal;
use Pig\Tui\TuiMainScreen;

/**
 * Which earlier conversation, asked before anything else starts.
 *
 * `bin/pig --resume` with no path shows this. Without it the flag needed a path nobody has
 * memorised — sessions are named after a random id under a flattened project path — so what
 * actually happened was `Could not read the session at `, with nothing after the "at".
 *
 * A screen of its own, and then it is gone: it runs before the mode is built, on a `TuiMainScreen` that
 * is started and stopped inside this call, so whatever is chosen is handed back as an ordinary
 * return value and the session is opened by the same code that opens a `--resume <path>`.
 *
 * Ported from upstream's `cli/session-picker.ts`; `SessionList` beside it is the component
 * half, from `components/session-selector.ts`, and is where the searching, sorting, scope and
 * delete keys live. Upstream's `--resume` picker has no rename (`showRenameHint: false`, no
 * `renameSession`), and neither has this one.
 *
 * **Three answers, not two**, which is why this returns a `SessionChoice`: upstream's picker takes
 * three callbacks and the third one exits the process.
 */
final class SessionPicker
{
    /** How many fit on screen before it stops being a list you can read. */
    private const int VISIBLE = 10;

    /**
     * Ask, and answer with the path, or with what was meant instead — see `SessionChoice`.
     *
     * @param list<SessionInfo> $sessions newest first, as `SessionManager::listFor()` gives them
     * @param (Closure(): list<SessionInfo>)|null $loadAll what Tab switches to — upstream's
     *        `SessionManager.listAll(sessionDir)`; every project's sessions when not given
     */
    public static function ask(array $sessions, ?Terminal $terminal = null, ?Closure $loadAll = null): SessionChoice
    {
        if ($sessions === []) {
            return new SessionChoice();
        }

        $tui = new TuiMainScreen($terminal ?? new ProcessTerminal());
        $chosen = null;
        $quit = false;

        $list = new SessionList(
            $sessions,
            self::VISIBLE,
            $loadAll ?? static fn (): array => SessionManager::listAll(PHP_INT_MAX)[0],
        );

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

        $list->setQuitHandler(static function () use (&$quit): void {
            $quit = true;
            Loop::get()->stop();
        });

        // `app.session.delete` (ctrl+d), confirmed in the list — upstream's resume picker has it too.
        $list->setDeleteHandler(SessionList::deleteSessionFile(...));

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

        return new SessionChoice($chosen, $quit);
    }
}
