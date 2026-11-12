# Skills

Skills give pig specialized instructions and supporting files for a particular kind of work. pig advertises each available skill by name and description, then loads its full instructions only when the task calls for them.

Use a skill when a workflow needs more context than a prompt template but does not need a new executable integration point. Skills can bundle scripts, references, and assets alongside their instructions.

pig implements the [Agent Skills specification](https://agentskills.io/specification). Invalid names and over-long descriptions produce warnings rather than stopping startup; only a missing description keeps a `SKILL.md` from loading.

## Create a skill

A skill is a directory containing `SKILL.md`:

```text
pdf-tools/
├── SKILL.md
├── scripts/
│   └── extract.sh
├── references/
│   └── formats.md
└── assets/
    └── template.json
```

Start `SKILL.md` with frontmatter followed by direct instructions:

```markdown
---
name: pdf-tools
description: Extract text and tables from PDF files. Use when reading, converting, or inspecting PDFs.
---

# PDF tools

Read `references/formats.md` before converting a document. Run scripts relative to this skill directory.
```

The description determines when the model considers loading the skill. State both what the skill does and when it applies. Avoid descriptions such as “Helps with PDFs,” which do not provide enough routing information.

Use relative paths from the skill directory when referring to bundled files. pig tells the model where the skill lives so it can resolve those paths.

## Understand how skills load

At startup, pig scans configured skill locations and adds each skill’s name, description, and path to the system prompt. It does not add the full instructions.

When a task matches, the model reads `SKILL.md` and follows its instructions. This keeps detailed guidance out of context until it is needed. A model might fail to load a relevant skill, so use `/skill:name` when you need to force it.

Arguments after `/skill:name` are appended to the loaded instructions as a user request:

```text
/skill:pdf-tools extract report.pdf
```

Set `disable-model-invocation: true` in frontmatter when a skill should be available only through its explicit command. The `enableSkillCommands` [setting](/docs/latest/settings) controls whether skill commands appear in interactive command discovery; manually entered `/skill:name` commands still work.

<a id="choose-where-it-loads"></a>

## Add it to pig

Place the skill in your user (`~/.pig/agent/skills/`) or project (`.pig/skills/`) skills directory. Directories containing `SKILL.md` are discovered recursively; `node_modules` and `vendor` are skipped. Unlike upstream pi, pig does not apply `.gitignore` rules while walking a skills directory.

pig also supports the Agent Skills locations `~/.agents/skills/` and `.agents/skills/`. Project `.agents/skills/` directories are discovered from the working directory through its ancestors, stopping at the repository root when one exists, and load only in trusted projects. pig no longer reads `~/.claude/skills`, `~/.codex/skills`, `~/.pi/agent/skills`, or `.pi/skills`; add such a directory to the `skills` setting if you want it. `--skill <path>` and `--skills-dir <dir>` add skills for one run, and `--no-skills` turns discovery off.

pig also accepts a standalone `.md` file directly inside a skills directory as a skill when its frontmatter has a description (files without one are ignored), but a directory containing `SKILL.md` is the portable form and should be preferred. See [Settings](/docs/latest/settings#resources) and [pig Packages](/docs/latest/packages) for additional locations.

Project skills can instruct the model to run scripts or modify files. Review unfamiliar skills and their supporting files before granting project trust.

## Write portable frontmatter

The Agent Skills specification defines these fields:

| Field | Purpose |
|---|---|
| `name` | Command and display name |
| `description` | Routing description shown to the model |
| `license` | License name or bundled license file |
| `compatibility` | Environment requirements |
| `metadata` | Additional key-value metadata |
| `allowed-tools` | Experimental pre-approved tool list |
| `disable-model-invocation` | Hide the skill from automatic model selection |

Names use lowercase letters, numbers, and hyphens, with no leading, trailing, or consecutive hyphens. They can contain at most 64 characters; descriptions can contain at most 1024. A name that breaks these rules is reported but still loaded. Without a `name`, the skill takes its directory's name.

pig neither requires nor warns when the declared name differs from the parent directory. Other Agent Skills implementations may enforce that requirement, so matching names remain the portable choice.

`SKILL.md` files without a description are not loaded. pig reads frontmatter line by line rather than with a full YAML parser, so a malformed frontmatter block is not refused the way upstream pi refuses it; keep frontmatter to simple `key: value` lines. Name collisions keep the first discovered skill and produce a warning.

## Validate and share a skill

Run pig from a location where the skill is discoverable, then inspect the startup diagnostics and `/skill:name` command. Run `/reload` after editing a skill during an active session.

Use a [pig package](/docs/latest/packages) to distribute one or more skills. Keep environment setup inside the skill and document any required system prerequisites.

For examples, see the [Anthropic skills collection](https://github.com/anthropics/skills) and upstream pi's [skills collection](https://github.com/badlogic/pi-skills), which uses the same Agent Skills format.

## Showcase: Domain-specific Skills and On-demand Packages

For specialized scenarios or niche domains (such as game automation or vertical workflows), avoid packing them directly into the pig core or general-purpose extensions. Keeping them separate prevents context bloat and token overhead for general users.

The recommended best practice is publishing them as **on-demand packages**, installed with a single command when needed and removable anytime.

### Real-world Case: what an app needs, read only when it is used

[pig-android-use](/docs/latest/android) keeps what it knows about particular apps out of the skill list altogether. Its one skill, `android-use`, tells the model to read `apps/<app>/APP.md` before working in an app; the 24 notes cost nothing until then. The note on the game Pokémon TCG Pocket (`apps/pokemon-tcgp/APP.md`) is an example: the game is one Unity canvas, so the note says what `android_tree` gives there (OCR text and the screenshot), which screens there are, where the battle tab is, and how packs, Wonder Pick, missions and solo battles go — no pixel coordinates, which would hold for one screen size only.

```text
"在后台副屏打开宝可梦 TCG Pocket，把免费卡包和得卡挑战领了"
```

A workflow that is not about one app — a release checklist, a house style — is a skill, published as a package and installed when wanted.
