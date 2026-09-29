# Changelog

What changed, newest first. `pig` shows the entries newer than the version you last saw, once,
under the conversation; `/changelog` shows the whole file.

A `##` heading with a version in it starts an entry and everything until the next `##` belongs to
it — so an `## Unreleased` heading is a section nobody has versioned yet, and the notes under it
are deliberately *not* shown as part of the release above. See `Pig\CodingAgent\Changelog`.

**Releasing:** the version lives in the **git tag** and nowhere else. `composer.json` has no
`version` field on purpose — Packagist derives one from the tag and says a field "should be omitted",
because a field and a tag are the two copies that can disagree. `Pig\CodingAgent\Version` asks
Composer what it installed, `pig --version` prints that, and the update check compares it against
Packagist. So a release is: add the heading below, commit, `git tag v0.2.0`, push the tag. There is
no number to bump anywhere else, and a build with no tag reachable reports
`1.0.0+no-version-set` — Composer's own words — and asks Packagist nothing.

## Unreleased

- Nothing yet.

## 0.1.1

Nothing about how pig runs changed — no file under `packages/*/src` is touched. What changed is
getting it onto a machine, and what the test suite can see.

- **`install.sh`**, so `curl -fsSL https://pigagent.dev/install.sh | sh` is the one line. It runs
  `composer global require pigagent/pig` — the one way pig is distributed, so there is still a
  single answer to "which version am I running" — and adds the two things Composer will not do. It
  names **every** missing PHP extension at once *before* anything is installed, where a missing
  `ext-pcntl` otherwise surfaces half way through as a platform error that says nothing about what
  to install. And it offers to put Composer's global bin directory on your PATH, which is the step
  that turns a successful install into `command not found`. Every function is defined before the
  last line calls one, so a download cut off half way does nothing at all rather than half an
  install.
- **Both READMEs say how to install it**, with that PATH step as a prerequisite rather than a
  footnote: `composer global require` on its own really does end in `command not found`, so the
  instructions did not work as written.
- **`bin/pig` and `bin/pig-ai` are executable in git.** Composer sets the bit when it installs, so
  this one is for whoever clones the repository.
- **A mutation sweep over the eight files whose mistakes are not undoable** — the four tools that
  change your files or kill your processes, the arithmetic that decides what the model is shown of
  them, and the three classes that write the session file pi also reads. 831 mutations, and the
  tests it asked for are what is in this release. `Truncate` had no test of its own at all; Escape
  arriving between the model asking for a write and the write happening was pinned in none of the
  tools; two write-failure checks could never have fired; a compaction's kept range was not held to
  ending at the compaction; and every default the session decoders apply to a field pi leaves out
  was untested. **None of it was a behaviour change** — each guard was already doing the right
  thing, and nothing was holding it there.

## 0.1.0

First numbered version, and the first one that can tell you there is a newer one.

- **A file-by-file port of [pi](https://github.com/earendil-works/pi) against commit `d0a4c37`**,
  with zero runtime dependencies beyond PHP itself: the agent loop, a unified LLM API over five
  hand-written provider protocols, a terminal UI, and the coding agent CLI.
- **Five providers.** Anthropic, both OpenAI shapes, Gemini and Google Code Assist, plus everything
  that speaks an OpenAI-compatible endpoint — Groq, Cerebras, xAI, z.ai, Mistral, OpenRouter and
  GitHub Copilot. A model your build has never heard of is reachable by declaring it in
  `~/.pig/models.json`.
- **Four ways to sign in** rather than paste a key: Anthropic, GitHub Copilot, Gemini CLI and
  Antigravity. The credentials file is pi's own, so signing in once means signing in once.
- **pi's session file, field for field**, in pi's directory layout — a conversation started in
  either tool opens in the other, `--resume` lists both, and pi's v1 sessions are upgraded the way
  pi itself would.
- **Four modes**: a terminal UI, `-p` for one answer on stdout, `--mode json` for every event as a
  JSON line, and `--mode rpc` for a host driving it.
- **Hooks and custom tools**, loaded from PHP files under `~/.pig/hooks` and `~/.pig/tools`, with
  the whole of pi's hook API including the dialogs a hook can open mid-turn.
- **A proxy that is not optional where pig was written**: HTTP CONNECT and SOCKS5, with TLS
  starting only after the tunnel is open so the proxy carries pig's bytes and can read none of
  them.
- Reaching a provider through a gateway instead (`Agent\StreamProxy`), HTML and Markdown export,
  compaction, retries that read the provider's own stated wait, and `/tree` for going back to an
  earlier point in the conversation and taking it somewhere else.
