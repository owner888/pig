# pig — pi ported to PHP

A file-by-file port of [earendil-works/pi](https://github.com/earendil-works/pi): agent core,
unified LLM API, terminal UI, coding agent CLI. Zero runtime dependencies beyond PHP itself.

## Upstream anchor

**Port against commit `d0a4c37` (2026-01-02)** — the snapshot behind the "418 lines" claim
(`packages/agent/src/agent-loop.ts` was 417 lines + trailing newline there). Do not port
against upstream HEAD: by 2026-09 that file is 898 lines and `packages/agent/src` is 33k.

Upstream at that commit was still published as `@mariozechner/pi-*`; it is `@earendil-works/pi-*` now.

Reference checkout for diffing lives outside this repo — clone pi and `git checkout d0a4c37`.

## Decisions on record

| Decision | Choice | Why |
|---|---|---|
| Port fidelity | File-by-file literal | Class/file/method names track upstream so `git diff` against a newer upstream commit stays mechanical |
| Concurrency | Hand-written `Fiber` + `stream_select` loop | One `select()` must wait on the LLM socket and STDIN together — that is what makes Esc-to-interrupt and typing mid-stream possible |
| HTTP transport | Raw `tls://` streams, hand-written HTTP/1.1 | Sockets and STDIN are then the same kind of thing; one loop, no `ext-curl` |
| Unions | real union type where the arms are closed and few, marker interface otherwise | PHP has union types; what it lacks is naming one and typing an array's elements — see [Porting the unions](#porting-the-unions) |
| Layout | composer monorepo, `Pig\*` | Mirrors upstream's package split one-to-one |

### Deliberate exceptions to "prefer the platform library over your own"

Both were chosen explicitly, not by default:

- **TUI is hand-written ANSI + `stty`, not `php-tui/php-tui`** (the Ratatui port, which already
  has differential rendering and widgets). Chosen to port `pi-tui` literally.
- **Event loop is hand-written, not `amphp/amp` v3** (also Fiber-based, with SSE-capable HTTP).
  Chosen to keep the core dependency-free.

`Pig\Async` has no upstream counterpart at all — JS ships an event loop, PHP does not.

### Extensions

`ext-mbstring`, `ext-json`, `ext-openssl` and `ext-pcre` are required and are in every build worth
running. `pig/tui` additionally requires **`ext-pcntl`**, because SIGWINCH is the only way to learn
that the window was resized; without it the UI would draw at the startup width forever, so
`ProcessTerminal` refuses to start rather than doing that quietly. `ext-intl` is deliberately *not*
required — see [Four upstream dependencies that are not needed here](#four-upstream-dependencies-that-are-not-needed-here).

One external binary: `stty`. PHP core has no termios binding and ext-pcntl does not add one, so
raw mode and the window size go through `proc_open('stty …')` against `/dev/tty`.

## Layout

```
packages/async/      → Pig\Async\        (pig/async)       Loop, Future, Deferred, Async, Socket, Abort*
packages/ai/         → Pig\Ai\           (pig/ai)          unified LLM API, HTTP/SSE, Anthropic
packages/agent-core/ → Pig\Agent\        (pig/agent-core)  AgentLoop, Agent, tools, events
packages/tui/        → Pig\Tui\          (pig/tui)         renderer, widths, keys, editor, markdown
packages/coding-agent/ → Pig\CodingAgent\                  not started
```

Ported so far: all of `ai` (`types.ts`, `utils/event-stream.ts`, `stream.ts`, the Anthropic provider),
all of `agent-core` (`types.ts` 217 → `agent-loop.ts` 417 → `agent.ts` 439), and `tui`'s foundation
(`utils.ts` 712 → `terminal.ts` 138 → `tui.ts` 351 → `keys.ts` 547 → `autocomplete.ts` 576 →
`components/` bar the image pair). Left in `tui`: `image.ts` 87 and `terminal-image.ts` 340,
which are the Kitty and iTerm2 inline-image protocols. Then the coding agent's tools and CLI.

`markdown.ts` has no `marked` under it here: `Pig\Tui\Markdown\Lexer` and `Inline` are a
hand-written subset — see the dependency note above. They are not CommonMark and do not try
to be; what they cannot parse stays a paragraph and is drawn as the text it was.

### Four upstream dependencies that are not needed here

`pi-tui` pulls in `get-east-asian-width` and leans on `Intl.Segmenter`, both for one question:
how many columns will the terminal give this string? Neither is needed here.

- **Grapheme clusters**: PCRE's `\X` implements UAX #29 extended grapheme clusters, correctly,
  including ZWJ emoji sequences and regional-indicator flags. `Graphemes::split()` is one
  `preg_match_all` call — no ext-intl, no table of Unicode ranges.
- **Character width**: `mb_strwidth()` carries the East Asian Width table, and PCRE answers
  `\p{Extended_Pictographic}` and `\p{Emoji_Presentation}` for the emoji cases it does not cover.

`chalk` is the third, and `Pig\Tui\Style` replaces it: a TUI needs "wrap this string in a
style and close it again", which is one file, not a package. Components take styles as
`Closure(string): string`, so `Style::dim(...)` is what gets passed around.

`marked` is the fourth, and the one that was a real decision rather than an obvious win.
`markdown.ts` uses it for a token stream and throws its HTML away, and a terminal renderer
needs a subset — headings, emphasis, code spans and fences, lists, quotes, links, rules,
tables. The developer chose to write that subset here rather than take `league/commonmark`,
which would have brought `league/config`, `dflydev/dot-access-data` and a row of Symfony
polyfills with it and ended the zero-dependency claim. It is not CommonMark and does not
try to be; anything it cannot parse is drawn as the plain text it came from.

The one thing PCRE has no answer for is JavaScript's `\p{RGI_Emoji}`, which matches a whole emoji
*sequence*; PCRE properties test single codepoints. `Width` asks the question of the cluster's
first codepoint instead, which gives the same answer for everything a terminal actually draws.

### Porting the unions

PHP has union types. What it does not have is a way to **name** one or to use one as an array's
element type — `type Message = A|B` and `array<A|B>` are both parse errors. That, not any absence
of unions, is what decides how each of upstream's unions is encoded here:

| Union | Encoding | Why |
|---|---|---|
| `Message` (3 arms, closed) | real union type, aliased with `@phpstan-type` on `Context` | closed like upstream, and a `match` over it can be checked for exhaustiveness |
| `AssistantMessageEvent` (12 arms) | marker interface | with no alias, a union means copying twelve class names into every signature |
| `AgentMessage` (apps extend it) | marker interface | a union cannot be extended from outside the package |
| `Content` / `UserContent` / `AssistantContent` | marker interfaces | they are array elements, where the type is a docblock either way — and the interface still holds for a single block passed on its own |

**A marker interface is not exhaustively checkable.** Anything may implement one, so every
`match (true)` over an interface keeps its `default` arm. Only the closed unions get exhaustiveness.
At runtime the two encodings are equally safe: a `match` with no arm taken throws
`UnhandledMatchError` either way.

`UserContent` / `AssistantContent` exist so that "a user message cannot hold thinking" is a type
error rather than a convention. The twelve event classes are named after their wire strings —
`text_delta` → `TextDeltaEvent`; upstream leaves those arms anonymous, so the names are ours.

Deliberate deviations from upstream, both to spare every consumer an unpacking step:
`UserMessage` wraps a bare string into a `TextContent` at construction instead of keeping
`string | Content[]`, and `Context` takes `messages` first because PHP wants required parameters
before optional ones.

## Commands

```bash
composer install        # path repositories + PHPUnit 12
php test/lint.php       # php -l over every file (PHPUnit only parses what it loads)
vendor/bin/phpunit      # filter: vendor/bin/phpunit --filter Loop
```

PHPUnit 12 is the newest release that still runs on PHP 8.3, so it is what the floor allows.
PHPUnit 13 requires 8.4 and becomes available if the floor ever moves.

`test/AssertsThrows.php` adds one assertion on top of PHPUnit: `assertThrows()` asserts a throw
mid-test and hands back the exception, which `expectException()` cannot do because it scopes to
the whole method.

The dev container has no route to packagist, so tests run on the Mac, not in the agent sandbox.

## Known traps

### `stream_socket_enable_crypto()` returns `0`, not just `true`/`false`

On a non-blocking socket the TLS handshake needs several passes. `0` means "call me again after
the socket is readable"; treating it as failure breaks the connection intermittently — measured
2 passes against `api.anthropic.com`.

```php
while (true) {
    $ok = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($ok === true) break;
    if ($ok === false) throw new TransportError('TLS handshake failed');
    $r = [$sock]; $w = $e = null; stream_select($r, $w, $e, $timeout);  // $ok === 0
}
```

### A signal makes `stream_select()` return `false`

Terminal resize sends SIGWINCH. With a handler installed, an in-flight `stream_select()` is
interrupted and returns `false` with errno 4 — reproduced with `pcntl_fork` + `posix_kill`, and
the whole agent dies on the next resize. EINTR is not a failure: the handler already ran, so
return and let the next tick re-arm. Match the errno, not the message text, which is localized.

```php
error_clear_last();                        // so error_get_last() cannot report a stale warning
$ready = @stream_select($read, $write, $except, $seconds, $microseconds);

if ($ready === false) {
    $message = error_get_last()['message'] ?? 'unknown error';
    if (str_contains($message, 'Unable to select [4]')) return;   // EINTR
    throw new AsyncError("stream_select() failed: {$message}");
}
```

Regression test: `LoopTest::testASignalDuringSelectDoesNotKillTheLoop`.

### A tick polls after its callbacks, even when they ended the program

`tick()` runs deferred callbacks and only then polls. A coroutine always resumes in that first
phase, so the root coroutine can *finish* there — and the same tick then settles into
`stream_select()` with no timeout, waiting on watchers whose only interested party is gone.
Found as a hang in a test whose server socket stayed armed and never became readable again;
other tests survived only because a timer or a readable stream happened to break the poll.

`Async::run()` therefore wakes the loop from the fiber's `finally`. Anything else that finishes
work during the callback phase and leaves nothing to poll for must do the same.

```php
$fiber = new Fiber(static function () use ($main, $loop): mixed {
    try { return $main(); } finally { $loop->wake(); }
});
```

Regression test: `FutureTest::testRunReturnsWhenAWatcherOutlivesTheCoroutine` — with a watchdog,
because a regression here hangs the suite rather than failing it.

### Closing a stream without cancelling its watcher

`stream_select()` silently drops closed streams from the array, then fails with
`ValueError: No stream arrays were passed` — which names nothing useful. `Loop::poll()` checks
`is_resource()` per watcher and throws naming the watcher id instead. On EOF: `cancel()` first,
then `fclose()`.

### Resuming a Fiber that has not suspended yet

`$fiber->resume()` on a running fiber throws `FiberError: Cannot resume a fiber that is not
suspended`. So `FutureState` never invokes callbacks synchronously — every one goes through
`Loop::defer()`, which also keeps completion callbacks out of the completer's own stack.

### Forcing a render on resize skips the clear it needs

`Tui::requestRender(force: true)` empties `previousLines`, and `draw()` reads an empty
`previousLines` as "first frame ever" — which writes the new lines with no clear at all. A resize
needs the opposite: the previous frame must be remembered so the width change is *noticed*, and
then the screen and the scrollback are cleared before redrawing. So the resize handler calls
`requestRender()` plain, like upstream, and force stays for callers who know the screen was
overwritten by something else.

Caught by `TuiTest::testAResizeRedrawsEverythingAndClearsTheScrollback`, which asserts the
`\e[3J\e[2J\e[H` is there.

### A per-line prefix has to go on after the wrap, not before

`Markdown` styles a block, then wraps it. That is right for *inline* styling — the wrapper
carries escape codes across its own breaks — and wrong for anything that must appear at the
start of every line. A block quote built as `"│ " . $text` and wrapped afterwards comes out
with the border on its first row and the remaining rows hanging in the margin, which reads as
the quote having ended.

So `quote()` wraps its children to `width - 2` itself and prefixes each resulting line. Found
by looking at rendered output, not by a test — every assertion about widths and codes passed.

Regression test: `MarkdownTest::testEveryLineOfAWrappedQuoteKeepsItsBorder`.

### `stream_socket_pair()` with a dropped peer (tests)

`[$a] = stream_socket_pair(...)` garbage-collects the peer, putting `$a` at EOF — permanently
"readable", with `fread()` returning `''`. Keep both ends in scope.

## Version floor: PHP >= 8.3

`Fiber` arrived in 8.1 and the whole async runtime rests on it, so 8.1 is the absolute floor;
8.3 is the floor actually declared, because 8.1 is end-of-life and 8.2 loses security support at
the end of 2026. `require.php` in the three composer.json files is the source of truth.

Off-limits until the floor moves, even though the dev machine runs 8.4:

| Feature | Since |
|---|---|
| Property hooks, asymmetric visibility (`public private(set)`) | 8.4 |
| `new Foo()->bar()` without parentheses | 8.4 |
| `array_find` / `array_find_key` / `array_any` / `array_all` | 8.4 |

Where 8.4 would express something better, say so in a comment rather than leaving it unexplained —
`FutureState` reads that way, since on 8.4 its readers would collapse into `public private(set)`.

Verify against the floor, not just the dev version — `php8.3 test/lint.php`. That does two things,
because `php -l` only parses:

- linting with the **floor's own binary** rejects 8.4-only *syntax* at parse time;
- a grep catches 8.4-only *functions*, which `php -l` never resolves. `array_any()` lints clean on
  8.3 and fails only on the line that reaches it.

Neither catches a **missing `use`**: an unimported class is resolved at run time too, and shows up
only on the code path that touches it. Exercise every branch — `ThinkingStartEvent` went unimported
and only the thinking test found it.

## Conventions

- **A new composer dependency is the developer's call, every time.** Ask before adding one, and say what
  it would cost to write instead — something small enough to write in an afternoon gets written
  here. Zero runtime dependencies is the point of the project, not an accident of it. `partial-json`
  and `sanitize-unicode` were both replaced by a file each rather than pulled in.
- `README.md` and `README.zh-CN.md` are one document in two languages. Every change to one lands
  in the other in the same commit — a half-translated README is worse than an untranslated one.
- `declare(strict_types=1)` in every file; PSR-12; one class per file.
- `match` over `switch`, `#[\Override]` on every override, `str_contains`/`str_starts_with`,
  constructor property promotion, `readonly` for anything that should not change after construction.
- No `@` error suppression. A function that warns *and* returns false gets a `set_error_handler`
  around it, because the warning text is usually the only place the errno appears.
- No silent fallback: `catch` must re-throw (`throw new X(..., $e)`). No `?? default` to paper
  over a missing value, no `clamp`/floor without a reproduced bug behind it.
- `Deferred::complete()` twice throws. Where ported code relies on a JS promise ignoring its
  second resolve, the call site guards with `isComplete()` and says so in a comment — so the
  leniency stays local instead of becoming a global rule.
