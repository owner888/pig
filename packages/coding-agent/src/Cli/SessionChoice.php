<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

/**
 * What came out of the `--resume` list, which is one of three things and not two.
 *
 * A path is a path. The other two both leave the list with nothing chosen and mean opposite
 * things: **escape is "not this session", ctrl+c is "not pig"** — so a bare `?string` cannot
 * carry them both, and the version that tried answered ctrl+c with a brand new session.
 *
 * Upstream keeps them apart by exiting the process from inside the picker. Here the picker
 * answers and `bin/pig` exits, for the reason `Cli\SignIn` returns an exit code rather than
 * calling `exit()`: a class that ends the process is a class no test can call twice.
 */
final readonly class SessionChoice
{
    /**
     * @param string|null $path the session to open, or null for neither
     * @param bool        $quit leave, without starting anything
     */
    public function __construct(
        public ?string $path = null,
        public bool $quit = false,
    ) {
    }
}
