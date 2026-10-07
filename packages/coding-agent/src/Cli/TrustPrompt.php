<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\TrustChoice;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Terminal;
use Pig\Tui\TuiMainScreen;

/**
 * "Trust this project?", asked before anything of the project's is loaded.
 *
 * The same shape as `SessionPicker` and for the same reason: it runs before the mode exists, on a
 * A `TuiMainScreen` started and stopped inside the call, because the answer decides what `CodingAgent::session()`
 * is allowed to `require` — so it cannot be asked from inside the session it gates. Upstream asks
 * through its extension UI context during bootstrap; pig has no screen yet at that point and this
 * is the one.
 *
 * Escape is "do not trust, this session only": nothing written, nothing loaded. That is the only
 * safe reading of somebody declining to answer a question about running a stranger's code.
 */
final class TrustPrompt
{
    /**
     * @param list<TrustChoice> $choices
     */
    public static function ask(string $cwd, array $choices, ?Terminal $terminal = null): ?TrustChoice
    {
        $tui = new TuiMainScreen($terminal ?? new ProcessTerminal());
        $chosen = null;

        $items = [];

        foreach ($choices as $index => $choice) {
            // No description: a label beside one is capped at thirty columns, which cut
            // `Trust parent folder (/Users/ka` off at the point that said which folder.
            $items[] = new SelectItem((string) $index, $choice->label);
        }

        $list = new SelectList($items, count($items), Themes::getSelectListTheme());

        $list->setSelectHandler(static function (SelectItem $item) use (&$chosen, $choices): void {
            $chosen = $choices[(int) $item->value];
            Loop::get()->stop();
        });

        $list->setCancelHandler(static function (): void {
            Loop::get()->stop();
        });

        $tui->addChild(new Spacer(1));

        foreach (explode("\n", ProjectTrust::prompt($cwd)) as $line) {
            $tui->addChild(new Text($line === '' ? '' : Themes::theme()->fg('warning', $line), 1, 0));
        }

        $tui->addChild(new Spacer(1));
        $tui->addChild($list);
        $tui->addChild(new Spacer(1));
        $tui->addChild(new Text(Themes::theme()->fg('muted', 'enter to choose · esc for this session only, untrusted'), 1, 0));
        $tui->setFocus($list);

        Async::run(static function () use ($tui): void {
            $tui->start();
            Loop::get()->run();
        });

        $tui->stop();

        return $chosen;
    }
}
