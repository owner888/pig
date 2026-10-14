# Known traps


Three shapes account for nearly every find in this file, and they are described where the audit that
found them is written down: **a rule present in one place and absent in its sibling**, **a thing
wired up at one end only**, and **PHP's value model is not JSON's** — all three at the end of
[A hook's compaction summary was indistinguishable from pig's own](extensions.md#a-hooks-compaction-summary-was-indistinguishable-from-pigs-own).
Before reading a file against upstream, ask the three questions there:
who else does this, who is at the other end, and is this value the same thing in both languages.

And ask the first of those three of **upstream** as well as of pig. Upstream carries second copies of
its own code — `mom`'s five tools and its diff renderer, `bash-executor.ts` beside `bash.ts`,
`google-gemini-cli.ts`'s event-stream reader beside `proxy.ts`' — and each pair is a corpus somebody
already wrote: where the two disagree, one of them is wrong and the diff is short. Three of the finds
below came out of exactly that — `proxy.ts`' event-stream reader dropping every `data:{…}` line its
own sibling handles, `mom`'s diff renderer numbering the wrong lines, and the three disagreeing
spellings of the sanitize composition, one of which is safe only because its caller had already
cleaned the text.

One file per area, each with its own index:

- [The terminal UI](tui.md) — rendering, widths, keys, the editor, themes, fullscreen
- [pig web](web.md) — the page, the WebSocket, tabs, login, the terminal drawer, the service
- [The other modes](rpc.md) — `--mode rpc`, print and JSON mode, `--mode mcp`
- [Providers](providers.md) — each provider API, the model registry, thinking levels, usage, overflow
- [Signing in](auth.md) — OAuth flows, API keys, `auth.json`
- [Sessions](session.md) — the session file, compaction, branches, resuming, the system prompt, retries
- [Tools and the CLI](tools.md) — built-in tools, subprocesses, command-line flags, directories and configuration
- [PHP and the async runtime](runtime.md) — Fiber, Loop, Future, sockets, and where PHP is not JavaScript
- [Extensions](extensions.md) — extensions, hooks, MCP, codemode, skills, prompt templates, the bundled extensions
- [Testing and releasing](testing.md) — the test suite, mutation sweeps, live provider tests, packaging
