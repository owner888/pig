# Releasing pig

Moved out of `AGENTS.md`, which goes into every request; this is read when a release is made.
Nothing is committed, tagged or pushed until the developer has reviewed the change and said to.

## Steps, in order

1. **Regenerate the model catalogue**, as upstream's `build` runs `generate-models` first:
   `php scripts/generate-models.php` (it ends by running `build-models.php`), its result in a commit
   of its own so models.dev's drift stays out of the release's real changes, then the tests. Also
   after porting anything that changes the generator's rules or `BuiltinCatalog.php`; not in
   between, since each run pulls whatever models.dev changed meanwhile.
2. **Update the documentation before the tag**, without being reminded. Go over everything
   user-visible in the release (commands, options, settings, slash commands, file formats,
   defaults) and update, in order:
   1. `README.md` + `README.zh-CN.md`;
   2. the site docs, `../smart-book/app/Views/pig/docs/{en,zh-cn}/*.md` (a new page also goes into
      `nav.json`) — pigagent.dev/docs serves them;
   3. `php scripts/sync-docs.php`, which copies the site's English pages into `docs/*.md`, the copy
      the package ships (`DocsTest` fails while they differ);
   4. `CHANGELOG.md`'s `## Unreleased` becomes `## [x.y.z] - date`, and the file is copied to
      `../smart-book/app/Views/pig/CHANGELOG.md`, which the site's banner version and /changelog
      page read.

   A purely internal refactor does 4 only. The release notes say what each of the four changed, or
   why one was not.
3. **Bump the patch version and tag it**: every release raises the version (`v0.2.3` → `v0.2.4`)
   and gets a new tag of its own; an existing tag is never overwritten (`git tag -f`, `git push -f`).

## CHANGELOG format

It matches upstream pi's exactly: `## [x.y.z] - YYYY-MM-DD` (e.g. `## [0.87.1] - 2026-09-22`), and
the items grouped under `### New Features` (major highlights, new model workflows), `### Added`
(new capabilities, options, APIs, tools), `### Changed` (behaviour, defaults, refactoring) and
`### Fixed` (bugs, crashes, protocol corrections). Only sections with items are included, and each
entry says what changed and why.

## Scripts

```bash
php test/live.php                 # the providers against the real endpoints — costs money, needs keys
                                  # `… google` for one provider, `… google/<model-id>` for one model
php scripts/generate-models.php   # rewrite the built-in rows (scripts/BuiltinCatalog.php) from models.dev,
                                  # then the pi-format catalogue Ai\Models reads (packages/ai/data/models/)
                                  # `--dry-run` prints them; `--from <file>` reads a saved api.json
php scripts/build-models.php      # rebuild packages/ai/data/models/ after editing BuiltinCatalog.php by hand
php scripts/bench-tui.php [session.jsonl] [regular|fullscreen] [cols] [rows]
                                  # ms and bytes per frame on a real conversation against a fake terminal
php scripts/eval.php [--json] [--keep] [name …] [-- <bin/pig options>]
                                  # the cases under evals/ through `bin/pig -p` — costs money
```

`test/AssertsThrows.php` adds `assertThrows()`, which asserts a throw mid-test and hands back the
exception (`expectException()` scopes to the whole method). PHPUnit 12 is the newest that runs on
PHP 8.3; 13 needs 8.4.
