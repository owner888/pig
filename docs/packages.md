# pig Packages

pig packages bundle extensions, skills, prompt templates, and themes as a standalone unit for distribution.

Under pig's pure PHP architecture, extensions and packages are distributed as clean PHP scripts and directory conventions, loaded directly by the native runtime.

---

## Installing & Managing Packages

### 1. Update Official Extensions

Synchronize official pure PHP extensions from the CLI:

```bash
pig update --extensions
```

This updates every installed package and walks `~/.pig/agent/extensions/`: a folder that is a git checkout gets `git pull`; a folder with a `composer.json` gets `composer update --no-dev` (when `composer` is on `PATH`); a folder that matches one of pig's bundled extensions is synced from pig's `extensions/` directory. Bundled extensions that are not there yet, such as `pig-vless`, are copied in. `pig-android-use`, `pig-antigravity`, `pig-computer`, `pig-gemini-video` and `pig-web-search` used to be among them and are packages now: the update removes their old copies and prints the `pig install git:github.com/owner888/…` line for each. The four built-in extensions (`pig-mcp`, `pig-llama`, `pig-codemode`, `pig-tool-search`) load from pig itself and are never copied; a stale copy of one is skipped with a warning.

### Install a Package

```bash
pig install git:github.com/user/repo        # user scope; cloned under ~/.pig/agent/git/
pig install -l git:github.com/user/repo@v1  # project scope, pinned; cloned under .pig/git/
pig install ./local/path                    # loaded from where it is
pig remove git:github.com/user/repo
```

`install` records the source in the `packages` setting. pig supports `git:` sources, `https://` and `ssh://` git URLs, and local paths; `npm:` and `composer:` sources are rejected.

### 2. Configure Extension Paths

Packages belong in the `packages` setting, which `pig install` maintains. Single extension files or directories can also be listed directly in the `extensions` setting of `~/.pig/agent/settings.json` (global) or `<cwd>/.pig/settings.json` (project):

```json
{
  "extensions": [
    "~/.pig/agent/extensions/pig-vless",
    "./extensions/my-custom-tool"
  ]
}
```

Or load temporarily via the command line:

```bash
pig --extension ./extensions/my-custom-tool
```

---

## Package Structure

A conventional pig package uses a clear, minimal directory layout:

```text
my-pig-package/
├── index.php          # Entry point returning closure function (ExtensionApi $pig): void
├── README.md          # Documentation and usage
├── skills/            # (Optional) Skills bundled with this package
│   └── my-skill/
│       └── SKILL.md
└── prompts/           # (Optional) Reusable prompt templates
    └── review.md
```

When loading, pig mounts all custom tools (`registerTool`), slash commands (`registerCommand`), and lifecycle hooks (`$pig->on(...)`).

---

## Listing a Package in the Gallery

[pigagent.dev/packages](https://pigagent.dev/packages) is built from GitHub: every repository with the topic `pig-package` is listed, the way `pi-package` on npm fills pi's gallery. No registration, no review.

1. **One repository per package.** Use the conventional directories (`extensions/`, `skills/`, `prompts/`, `themes/`) or declare them in `composer.json` under `extra.pig`. A package that needs libraries commits its own `vendor/`: `pig install` runs no Composer, and the loader requires `vendor/autoload.php` when it is there. (Only `pig update --extensions` runs `composer update --no-dev`, and only in non-git folders under `~/.pig/agent/extensions/` that have a `composer.json`.) Do not `require` `pigagent/pig` in the package's `composer.json`; the `Pig\*` classes come from the host.
2. **Check it installs.**

   ```bash
   pig install git:github.com/user/repo   # clones under ~/.pig/agent/git/github.com/user/repo
   pig list                                # the resources it found
   ```

3. **Add the topic `pig-package`** on the repository page (About → Topics). The list refreshes hourly.

The card shows the repository description, stars and last push — so the GitHub description is the card's text — plus the resource types and the install command. Removing the topic removes the package. Only GitHub is indexed; packages on other git hosts install fine but are not listed.

`extra.pig` may list `extensions`, `skills`, `prompts`, and `themes` paths. `extra.pig.changelogPath` (default `CHANGELOG.md`, `""` for none) displays release notes under "Extension Updates" upon package version changes. Two more optional fields add a preview — pig itself ignores them:

```json
{
  "extra": {
    "pig": {
      "extensions": ["./src/Extension.php"],
      "image": "./docs/preview.png",
      "video": "https://example.com/demo.mp4"
    }
  }
}
```

A relative path is read from the repository's default branch.

---

## Published Packages

Visit the [pig Packages Gallery](https://pigagent.dev/packages). These extensions ship in pig's own `extensions/` directory:

| Package | Description | Focus |
|---|---|---|
| **pig-mcp** | Model Context Protocol (MCP) client (built in) | Connect external MCP servers via stdio or streamable HTTP |
| **pig-codemode** | PHP script sandbox in a child `php` process (built in) | Batch, chain, and filter tool calls in one script; only the output reaches the model |
| **pig-tool-search** | Search and load deferred tools (built in) | Keeps large MCP tool lists out of the model's declarations |
| **pig-llama** | llama.cpp router integration (built in) | Local GGUF models, `/llama` |
| **pig-vless** | Minimal VLESS inbound for a phone | TCP relay, one UUID, optional TLS; `/vless start` |

The Web UI is part of pig itself (`pig --mode web`, `pig web start`); see [Web UI](/docs/latest/web).
