<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

/** Where pig keeps what belongs to the person rather than to a project. */
final class Config
{
    /**
     * `~/.pig`, or whatever `PIG_HOME` says.
     *
     * Settings, sessions and a global `AGENTS.md` live here. Overridable because tests
     * must not read or write the real one, and because a person with several setups
     * should be able to keep them apart.
     */
    public static function home(): string
    {
        $override = getenv('PIG_HOME');

        if ($override !== false && $override !== '') {
            return rtrim($override, '/');
        }

        $home = getenv('HOME');

        return ($home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/')) . '/.pig';
    }
}
