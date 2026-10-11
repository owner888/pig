<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\ProcessTerminal;
use Pig\Tui\Terminal;
use Pig\Tui\TuiMainScreen;

/**
 * Upstream's `showStartupSelector()` (`cli/startup-ui.ts`): a question with a few answers, asked on
 * the terminal before the mode exists — the title above, the answers as a list, escape for none.
 *
 * The same arrangement as `TrustPrompt` and `SessionPicker`: a `TuiMainScreen` started and stopped
 * inside the call. `bin/pig` asks "cwd from session file does not exist … continue in current cwd"
 * with it (`SessionCwd::prompt()`), as upstream's `promptForMissingSessionCwd()` does.
 */
final class StartupSelector
{
    /**
     * @param list<string> $options the labels, in order
     * @return string|null the label chosen, or null for escape
     */
    public static function ask(string $title, array $options, ?Terminal $terminal = null): ?string
    {
        $tui = new TuiMainScreen($terminal ?? new ProcessTerminal());
        $chosen = null;
        $items = array_map(static fn (string $label): SelectItem => new SelectItem($label, $label), $options);
        $list = new SelectList($items, max(1, count($items)), Themes::getSelectListTheme());

        $list->setSelectHandler(static function (SelectItem $item) use (&$chosen): void {
            $chosen = $item->value;
            Loop::get()->stop();
        });

        $list->setCancelHandler(static function (): void {
            Loop::get()->stop();
        });

        $tui->addChild(new Spacer(1));

        foreach (explode("\n", $title) as $line) {
            $tui->addChild(new Text($line, 1, 0));
        }

        $tui->addChild(new Spacer(1));
        $tui->addChild($list);
        $tui->setFocus($list);

        Async::run(static function () use ($tui): void {
            $tui->start();
            Loop::get()->run();
        });

        $tui->stop();

        return $chosen;
    }
}
