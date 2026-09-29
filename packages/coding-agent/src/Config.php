<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Tui\Env;

/** Where pig keeps what belongs to the person rather than to a project. */
final class Config
{
    /**
     * `~/.pig/agent`, or whatever `PIG_HOME` says.
     *
     * Settings, sessions and a global `AGENTS.md` live here. Overridable because tests
     * must not read or write the real one, and because a person with several setups
     * should be able to keep them apart.
     *
     * **The `agent` is deliberate and was added later**, to match `piHome()` below — upstream's
     * `getAgentDir()` has always been `~/.pi/agent`, and pig kept everything one level up. The
     * two layouts being different shapes is the kind of difference that costs an afternoon every
     * time somebody compares a path across the two tools.
     *
     * No migration: `PIG_HOME` still points wherever it is told, and an existing `~/.pig` is not
     * searched as a fallback. The developer asked for the move rather than for compatibility with
     * the old layout, and a fallback that quietly reads the old directory forever is how two
     * homes end up half-populated. Moving the old `~/.pig/*` into `~/.pig/agent/` is the whole upgrade.
     */
    public static function home(): string
    {
        $override = getenv('PIG_HOME');

        if ($override !== false && $override !== '') {
            return rtrim($override, '/');
        }

        return self::homeDirectory() . '/.pig/agent';
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

    /**
     * The person's home directory, which is not `home()` — that is `~/.pig` inside it.
     *
     * Public because `Prompt\Skills` needs it for other tools' roots (`~/.codex/skills`) and
     * had its own copy of these four lines, fallback and all. One lookup, one answer.
     */
    public static function userHome(): string
    {
        return self::homeDirectory();
    }

    /**
     * The fallback is this method's own, and is the reason `Tui\Env::home()` answers null instead.
     *
     * pig's files have to go *somewhere*, so no `HOME` means the temp directory here. The two
     * other readers of that variable want the opposite — a `~` left alone, a path left
     * unshortened — and a `sys_get_temp_dir()` underneath all three would resolve `~/notes` to a
     * file in `/tmp`, which is what two hook loaders were caught doing.
     */
    private static function homeDirectory(): string
    {
        return Env::home() ?? sys_get_temp_dir();
    }
}
