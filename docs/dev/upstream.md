# Upstream

pig follows the latest pi (upstream `main`); see `AGENTS.md`. Until 2026-10 it was ported against a
pinned commit, `d0a4c37`, with pieces taken from upstream HEAD on request — comments and trap entries
written then still say "the anchor"; read them as history. This file is what is left to say: where
pig cannot do what upstream does, and what it does instead.

The program status port differs from upstream in two places, both platform. Upstream sends the
support query inside its kitty keyboard query and reads the answers out of `StdinBuffer`'s split
sequences; pig makes no keyboard query, so `ProcessTerminal::start()` sends the OSC 7501 query with
its own DA1 sentinel and sifts the two answers out of the raw read, holding back a tail that could be
a reply cut by the read — never a lone escape, which is a key. And upstream hears every compaction as
a `compaction_start`/`compaction_end` session event, while here only the overflow one is an event:
`/compact` and the threshold check run in the mode, which tells `ProgramStatusReporter` itself
through `compactionStart()`/`compactionEnd()`. A cancelled overflow compaction reads as `error` for a
moment and becomes `idle` at the `agent_settled` that follows it, since `abort()` set the flag.

Upstream reads the clipboard through a native Node addon on macOS and Windows and falls back
to `wl-paste` / `xclip` / PowerShell on Linux. PHP has no addon, so every platform goes
through a command here — including macOS, which uses `pngpaste` when it is installed and
AppleScript, which always is, when it is not. Under Termux it is Termux:API's
`termux-clipboard-get` / `termux-clipboard-set` and nothing else, text only, as upstream's.
