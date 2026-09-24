<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;

/**
 * A `SettingsList` row's options, as a titled list that says how to get out of it.
 *
 * It exists for one reason, which is worth stating because it is invisible and was a bug
 * first: **`Container` is a `Component` and not an `InputHandler`.** A container draws its
 * children and forwards nothing, so a submenu built as a bare container opened, drew a list
 * with a cursor on it, and then ignored every key — the arrows and Enter went nowhere, and
 * Escape did not even close it. The title and the hint are why a container is wanted at all;
 * this is that container with the keys wired through to the list inside it.
 *
 * Upstream's `SelectSubmenu`, from `settings-selector.ts`. Here rather than in `pig/tui` for
 * the same reason it is there rather than in its own package: it is a shape the settings
 * screen needs, not a component anybody else has asked for.
 *
 * The title and the hint arrive already painted, because the caller is the one holding a
 * palette — this is arrangement, not a second place that knows which theme is on.
 */
final class SettingsSubmenu extends Container implements InputHandler
{
    public function __construct(
        private readonly SelectList $list,
        string $title,
        string $hint,
    ) {
        $this->addChild(new Text($title, 0, 0));
        $this->addChild(new Spacer(1));
        $this->addChild($list);
        $this->addChild(new Spacer(1));
        $this->addChild(new Text($hint, 0, 0));
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $this->list->handleInput($data);
    }
}
