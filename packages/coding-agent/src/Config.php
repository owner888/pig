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

        return self::homeDirectory() . '/.pig';
    }

    /**
     * Where pi keeps its own things.
     *
     * `~/.pi/agent`, and the `agent` is not a typo: upstream's `getAgentDir()` is
     * `join(homedir(), ".pi", "agent")`, so its sessions are under `~/.pi/agent/sessions/`
     * rather than `~/.pi/sessions/`.
     *
     * pig reads sessions from there and appends to the one it opened. It also **writes** one
     * file: `auth.json`, when pi already has one. That is deliberate and `Auth` says why —
     * Anthropic rotates refresh tokens, so two files holding the same token is two tools
     * taking it in turns to log each other out.
     *
     * **`PI_CODING_AGENT_DIR` is upstream's own override**, and it is read first for the same reason
     * the skills loader reads Claude's and Codex's directories: somebody who already told one tool
     * where things are should not have to tell the other. The name is built rather than written
     * there — `${APP_NAME.toUpperCase()}_CODING_AGENT_DIR` with `APP_NAME` from `package.json` — so
     * reading it off `getAgentDir()` alone gives `PI_AGENT_DIR`, which is what this used to look for
     * and what nothing sets. `PI_HOME` is pig's own, for a test and for anyone who prefers it.
     */
    public static function piHome(): string
    {
        foreach (['PI_CODING_AGENT_DIR', 'PI_HOME'] as $variable) {
            $override = getenv($variable);

            if ($override !== false && $override !== '') {
                return rtrim($override, '/');
            }
        }

        return self::homeDirectory() . '/.pi/agent';
    }

    private static function homeDirectory(): string
    {
        $home = getenv('HOME');

        return $home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/');
    }
}
