# Known traps: Testing and releasing

The test suite, mutation sweeps, live provider tests, packaging. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [Every run of the test suite opened the developer's browser on Anthropic's authorize page](#every-run-of-the-test-suite-opened-the-developers-browser-on-anthropics-authorize-page)
- [`TransformMessages` measured properly, and the divergence pig is proud of had no test](#transformmessages-measured-properly-and-the-divergence-pig-is-proud-of-had-no-test)
- [The sweep's filter decided which decisions counted as unpinned](#the-sweeps-filter-decided-which-decisions-counted-as-unpinned)
- [Every bound in `JsonSchema` was checked from outside itself](#every-bound-in-jsonschema-was-checked-from-outside-itself)
- [A killed sweep leaves workers behind, and contention manufactures *kills*](#a-killed-sweep-leaves-workers-behind-and-contention-manufactures-kills)
- [Why mutating the accumulator hangs the suite, and what that says about reachability](#why-mutating-the-accumulator-hangs-the-suite-and-what-that-says-about-reachability)
- [A changelog and a version, and two tests that were pinning the absence of the file](#a-changelog-and-a-version-and-two-tests-that-were-pinning-the-absence-of-the-file)
- [Ten test files could not be loaded by PHPUnit at all, and the shim could not see it](#ten-test-files-could-not-be-loaded-by-phpunit-at-all-and-the-shim-could-not-see-it)
- [What the first real PHPUnit run said, once it could load the suite](#what-the-first-real-phpunit-run-said-once-it-could-load-the-suite)
- [Packaging for Packagist, and the version field that was the wrong answer to the right question](#packaging-for-packagist-and-the-version-field-that-was-the-wrong-answer-to-the-right-question)
- [831 mutations over the eight files whose mistakes are not undoable](#831-mutations-over-the-eight-files-whose-mistakes-are-not-undoable)
- [Two files that belonged in those eight, and the front door neither was tested through](#two-files-that-belonged-in-those-eight-and-the-front-door-neither-was-tested-through)
- [Pinning a port to the original's arithmetic](#pinning-a-port-to-the-originals-arithmetic)
- [A test that could not fail, and the experiment that found the case](#a-test-that-could-not-fail-and-the-experiment-that-found-the-case)
- [`phpunit | tail` hung after the whole suite had passed](#phpunit--tail-hung-after-the-whole-suite-had-passed)
- [The test suite wrote into the developer's real home](#the-test-suite-wrote-into-the-developers-real-home)
- [MCP startup notice test asserted before asynchronous servers settled](#mcp-startup-notice-test-asserted-before-asynchronous-servers-settled)
- [Headless Chrome launch timeout was too tight for CI runner contention](#headless-chrome-launch-timeout-was-too-tight-for-ci-runner-contention)

## Every run of the test suite opened the developer's browser on Anthropic's authorize page

Reported as *怎么每次 phpunit 就打开浏览器 Claude oauth，这样会很容易被封号的*. Since the Anthropic
1.0.3 sign-in landed (v0.3.56), `/login`'s `onAuth` says the URL and then hands it to `open` —
and `InteractiveModeTest` drives `/login` through a `FakeTerminal` in four tests, one of which
picks the copy-code way, whose flow calls `onAuth` at once. So `vendor/bin/phpunit` ran
`open https://claude.ai/oauth/authorize?…` on the Mac every time: a real sign-in page, with real
state, against an account that can be suspended for exactly that pattern. **The fake terminal
fakes the terminal and nothing else** — a subprocess the code starts is as real under a test as
under a person.

`openInBrowser()` honours `PIG_OFFLINE=1` now, the switch `ToolInstaller` already reads, and
`InteractiveModeTest::setUp()` sets it. The regression test puts a stand-in `open` on the PATH
and proves **both ends**: offline it is never run, and with the variable cleared it *is* — so the
guard cannot become "never open", and the online half runs against the stand-in rather than a
browser. Mutating the guard away turns the offline half red.

Three places start a browser and they are three answers to one question: this one reads
`PIG_OFFLINE`, `extensions/pig-mcp` reads `PIG_TESTING` (which `phpunit.xml.dist` sets and the
shim does not), and `WebMode` takes `openBrowser: false`. One `Browser::open()` honouring one
switch is the fix if a fourth appears; it is new surface, so it was not done here.

## `TransformMessages` measured properly, and the divergence pig is proud of had no test

The file the contaminated row above said was unmeasurable. Re-run with clean trees: **77 mutations,
56 killed, 21 survived, no timeouts** — so it was measurable all along, and 21 of its decisions were
not held down by anything. Writing four tests took that to **71 killed and 6 survivors, five of them
genuinely equivalent**. Three findings, and the first is the one to remember.

**The flush at the end of the conversation had no test.** `fillOrphanedCalls()` answers pending
calls once more after the loop — upstream's closing `closePendingToolCalls()` — and deleting that
trailing `$flush();` changed nothing in 2,480 tests. Every existing case put a user message *after* the
dangling call, which exercises the in-loop flush and says nothing about the end of the list — and
the end of the list is exactly where an interrupted turn leaves one. Escape during a tool call, then
`/model`, and the conversation goes out as it stands.

**A second flush bug the same tests could not see: `$pending` and `$answered` are emptied by
`$flush()`, and nothing noticed when they are not.** Every case had one turn with a call in it. A
conversation with two interrupted turns re-flushes the first turn's call on every later turn —
three invented results for one call by the end of a four-message conversation.

**The id rename had no coverage at all**: all of its lines survived, including the `$renamedIds`
map that keeps the tool *result* pointing at the call it was renamed from — nothing checked the
rename, the truncation, the result following it, or that the call is renamed *rather than
duplicated*.

Regression tests: `AnthropicTest::testAConversationThatEndsOnADanglingCallGetsOneToo`,
`testTwoInterruptedTurnsGetOneInventedResultEach`,
`OpenAiCompletionsTest::testCopilotsOwnIdsAreRemadeForItsOtherApiAndTheResultFollows`. That one
had to be sent by hand rather than through the `send()` helper, and the reason is on it: with a non-empty key `endpoint()` asks
`GithubCopilot::baseUrl()` where to go and the request leaves for the real Copilot API — which the
first version did, then failed reading a body nothing had received.

**The five that survive have a reason, and the reason was measured rather than assumed.** Four are
`$x = [];` initialisations and one is `$answered[…] = true` → `false`: `$x[] =` and `$x[k] =` create
the array without complaint, and `$answered` is only ever read through `isset()`, so the value
is irrelevant and undefined behaves as empty. `$answered`'s reset inside `$flush()` only matters if
a tool call id repeats across turns, which providers do not do.

*And the interesting part of checking that* is that the project's own runner is **stricter than the
shim these numbers came from**: `phpunit.xml` sets `failOnWarning="true"`, so a mutation that only
warns is killed there and survives here. Probed one by one rather than assumed — of the six, exactly
one warns (`$pending`'s init, because `foreach` reads it), so PHPUnit kills 72 of 77 where the shim
kills 71. Worth knowing in both directions: a survivor list from the shim is the pessimistic one,
and a shim green is not quite a PHPUnit green.

## The sweep's filter decided which decisions counted as unpinned

`packages/ai/src/Http` swept next — the three files this document calls **load-bearing in a way they
are not upstream**, because "a bug in them shows up as tokens going missing rather than as an error,
and there is no vendor implementation to fall back to". 530 mutations, and the loudest row read
`HttpClient::follow()` is entirely unpinned: the loop bound, both statement deletions, all six
mutations of the 3xx condition, the whole 307/308 block, and every line of `absolute()` — every
decision in the redirect logic, surviving.

**It was the harness, for the fourth time, and this is the fourth shape it has taken.** The sweep runs
the tests once per mutation through one filter, and that filter is a list of class-name prefixes
picked when the sweep was pointed at `Providers`. `follow()`'s four tests live in
**`ToolInstallerTest`** — the method was added for the download and its tests went where the caller
was — and `ToolInstaller` is not on the list. So the sweep was measuring `follow()` against a suite
that never ran a single redirect. Adding the name to the filter turned 15 of those survivors into
kills on the spot, with nothing in the repository changed.

*A filter is an argument about what is being measured, and a name left out of it manufactures
survivors exactly as a shared worker tree manufactures them.* The three earlier ones produced
confident wrong output too — three false survivors from a worker race, seventy-seven false timeouts
from a reused tree, one mutation whose replacement text never matched the file. **What separates them
from a real find is the same one step every time: take one number off the harness and reproduce it by
hand.** Here that step was `grep -rn -- '->follow(' --include='*.php' .`, which names the tests in
thirteen characters.

So the rule the instrument now carries, and the reason the Utils sweep below has a filter per file
rather than one for the directory: **derive the filter from the file's consumers, never from the
directory being swept.** `grep -rl` for the class over `packages/*/src` gives the consumers; their
test classes are the filter. And check the cost of the widest one — a filter covering everything runs
in 22 seconds here against a 30-second timeout, which is the *other* way to manufacture a row of
detections that are nothing of the kind.

**What survived the corrected run was worth having, and none of it was what the false row said.**
Four rules with no test, in a method whose docblock states three of them:

- **The 301/302/303-becomes-a-GET and 307/308-keeps-the-method rule had no coverage at all** — not
  the method, not the body, not the `content-length` that goes with it. Five mutations and a
  statement deletion, all silent. This is the one that matters: a redirected POST that keeps its
  body is a request the far end answers twice.
- **A `Location` with no slash on it was never resolved.** `absolute()`'s directory branch is the
  one a real CDN uses — `Location: real.tar.gz` beside the file that was asked for — and deleting
  the line that computes the directory changed nothing. The test named
  `testARelativeLocationIsResolvedAgainstWhereItCameFrom` does not follow a redirect at all: its
  canned server answers 200 with no `Location`, so `follow()` returns on its first line. *A test's
  name is not a claim anybody checks.*
- **`MAX_REDIRECTS` had no meaning.** `<=` and `<` both pass, so whether five means five redirects
  or four was not written down anywhere the code could be held to.
- **Three of `HttpClient`'s own error paths were unreachable from the suite**: a head past
  `MAX_HEAD_BYTES`, a connection closed mid-head, and a header line with no colon in it. The
  scheme check as well — mutating it to accept `ftp://` broke nothing.

The boundary between the first two of those is one test rather than two: exactly `MAX_HEAD_BYTES` of
head and then the hang-up must be refused for being **truncated** and not for being too big, which
pins the guard's `>` and reaches the truncation message in the same breath.

Two survivors stay, with the reason measured rather than argued. `$parts === false` in both
`absolute()` and `resolve()` is redundant — `isset($parts['scheme'])` on `false` is already false, so
the clause beside it throws anyway — though `parse_url()` does return false for real input
(`http://x:8O8O`, a port typed with a letter in a hand-written `models.json`), so the guard is not
dead, only doubled. And `$response->body->close()` between hops **is not a leak**: measured over four
runs of a two-redirect chain, the open-stream count after `follow()` returns is the same with the
call and without it, because reassigning `$response` drops the last reference and PHP closes the
stream. What the line buys is what its comment says and nothing more — the old socket is shut
*before* the next one is opened, rather than one line after, so a five-hop chain holds two sockets at
a time instead of six. There is no seam to observe a peak through, so it is recorded here instead.

Regression tests: eleven in `HttpClientTest`, and each of the twelve mutations above was re-applied
afterwards to confirm it now dies — ten killed outright, two (the closed-mid-head read and the
colonless header line) by hanging the suite, which is a detection. The four cases in
`ToolInstallerTest` are left where they are; a method's tests living beside its first caller is worth
raising rather than quietly moving, and putting the new ones under `HttpClientTest` is also what
keeps the default filter honest about this file from here on.

## Every bound in `JsonSchema` was checked from outside itself

`JsonSchema`'s 40, triaged, and they are one sentence: **the bounds were all tested on the wrong side
of themselves.** `minimum: 1` against 0, `maximum: 10` against 11, `minLength: 2` against one
character, `maxItems: 3` against four items, `maxProperties: 2` against three — every one of them
proves the bound rejects what is out of range, and not one of them says whether it accepts what is
*in* range. So `<` for `<=` and `>` for `>=` walked through all five, and `minimum: 1` rejecting the
value 1 would have been a green suite.

*A test that a bound rejects what is out of range is not a test of the bound.* The lower bounds were
pinned here by accident — `minItems: 2` has a valid case at exactly two items, so its own mutation
dies — which is the tell worth keeping: when one half of a symmetric pair is killed and the other
survives, the difference is usually the fixture and not the code.

**It matters more in this file than it would in most, because of who reads the message.** The whole
standard here is that a correction has to be one the model can act on, which is the argument the
`multipleOf` note already makes about refusing 0.3 as a multiple of 0.1. "must NOT have more than 3
items" against a list of exactly three is a correction nothing can act on: every edit the model makes
is still wrong, and the one thing it cannot do is disbelieve the validator. So an off-by-one that
rejects a **valid** document is the worse direction — an invalid one that slips through fails at the
provider, in the provider's own words, which is a sentence somebody can read.

Four more rules had no test, and two of them end in no message at all rather than a wrong one:

- **`isType()`'s `default => true` could have been `false`.** That is "an unknown keyword is ignored,
  never a failure" — the docblock's most important line — one level down, on the keyword a schema is
  likeliest to carry something unexpected in. And writing the test surfaced the consequence worth
  having on record: a `type` list is satisfied by any one arm, so an unknown name **in a union**
  satisfies everything and the names beside it stop narrowing. That is the rule rather than a hole in
  it, and it is now asserted rather than discovered.
- **`same()` handed two different kinds crashed under the mutation.** Both sides have to be arrays
  before the recursive walk starts; with `||` there instead of `&&`, `const: 5` against `[1,2]` is
  `count(5)` — a `TypeError` out of the validator. And a value of the wrong kind is the commonest
  thing this file exists to catch.
- **`multipleOf: 0` divided by zero**, once its guard was loosened by one operator. A schema somebody
  wrote wrong, and the keyword is skipped for the reason every unknown keyword is: the value is not
  what is broken.
- **`null` against a numeric bound.** `{"type": ["integer", "null"], "minimum": 1}` is an ordinary
  optional number, and PHP is perfectly happy to tell you that `null < 1` — so without the guard in
  front of the comparison every omitted number is too small. The same guard covers `true` and a
  string.

Plus two about the wording: **`uniqueItems` was only ever asked for and never absent**, so enforcing
uniqueness on every array broke nothing — where a list of the same string twice is an ordinary
argument. And **three identical items are one complaint, not two**: without the `break` the walk
reports each successive pair, so ten identical items arrive as nine messages saying the same thing.

That took 40 survivors to 27, and the 27 have reasons. Twenty-one are `?? null` turned into `?? ''`
in front of an `is_int()`, `is_string()` or `is_array()`, which `''` fails exactly as `null` does. One
is the `1e-9` tolerance, which differs only at exactly 1e-9. One is a `continue` that is genuinely
redundant, being the last statement before a branch its own condition has already excluded. And four
are initialisers and the `set_error_handler` pair, which **warn** when deleted — probed rather than
argued, `preg_match(): Compilation failed` and `Undefined variable $seen` — so `failOnWarning` kills
them where the shim does not, which is the shim caveat two entries up arriving for the third time.

Regression tests: `JsonSchemaTest::testAValueExactlyOnItsBoundSatisfiesIt` over nine bounds,
`testATypeNameThisHasNeverHeardOfIsIgnoredRatherThanRejected`,
`testAConstOfTheWrongKindIsAComplaintRatherThanACrash`, `testANullIsNotComparedAgainstANumericBound`,
`testAMultipleOfThatCannotDivideIsIgnoredRatherThanFatal`, `testWithoutUniqueItemsDuplicatesAreFine`,
`testThreeIdenticalItemsAreOneComplaintAboutTheFirstTwo`. Each of the thirteen mutations was
re-applied afterwards to confirm it dies.

**Both halves of this sweep say the same thing about the two files**, and it is worth stating once
rather than twice: `PartialJson` and `JsonSchema` are the two hand-written stand-ins for npm packages
in `packages/ai`, both were verified by a corpus against the real package, both corpora found real
bugs, and **neither corpus was kept**. What the suite had afterwards was 3 test methods for one and
38 for the other, over 785 lines that a provider's 400 is the only other check on. The traps above
already say a corpus is worth running and worth keeping the count of; the third clause is that it is
worth keeping as a **test**, because a count in this document is evidence about the day it was
written and a test is evidence every day.

## A killed sweep leaves workers behind, and contention manufactures *kills*

`packages/ai/src/Utils/Oauth` is the last directory the instrument had not been pointed at — 2,101
lines across ten files, four sign-in flows and a loopback HTTP server. 759 mutations. The finding
worth putting first is about the sweep and not about the code, because it is the one direction the
three earlier harness bugs never took.

**The first run was killed part-way through, and its eight test processes were not.** Nothing noticed:
the sweep's own per-mutation timeout lives in the Python that was killed, so eight `php` processes
carried on for **fifty minutes** — spinning, holding a CPU each. The replacement run recreated the
worker trees, so its files were clean, and it reported **759 run, 130 not killed**.

Re-run on an idle machine, the same 759 mutations report **272 not killed.** So the contaminated run
did not inflate the survivor list, it **halved** it — and that is the dangerous direction. Under 2×
CPU load the suite fails for reasons of its own: a canned server that answers on a timer answers too
late, a test that waits 50ms waits through the wrong window, an assertion about elapsed time comes
back wrong. Every one of those is a red suite, and a red suite is recorded as `killed`. **Contention
manufactures kills, so a contaminated sweep says the code is pinned when it is not.**

The three earlier bugs all produced false *survivors* — a worker race, a reused tree, a replacement
string that never matched — and a false survivor costs an afternoon chasing coverage that already
exists. A false kill costs the bug. So two rules, and the first is the cheap one:

- **Count the worker processes before trusting a number.** `ps -eo cmd | grep -c php` against the
  worker count, and `etimes` on any that look old. Eight workers means eight processes; thirteen
  means something from last time is still running, and the numbers from that run are worth nothing in
  either direction.
- **Kill the strays before re-running**, not just the run. The trees are recreated at startup, so the
  files are safe; the CPU is not.

One thing the two-pass design did earn, in the opposite way from expected. `InteractiveModeTest` is
**56 of the 62 seconds** a full-consumer filter costs here, and what it exercises of this directory is
the `/login` and `/logout` lists rather than the flows — so pass 1 ran without it and pass 2 re-ran
only the survivors with it. In the contaminated run pass 2 appeared to kill 29 of the first 50, which
looked like the filter-omission trap repeating. On the clean run it killed **0 of the 75 it reached**
before that run was killed too. So the suspicion was contamination as well, and the honest statement
is a sampled one: a quarter of the survivors were re-checked with `InteractiveModeTest` in the filter
and none of them died.

**What the 272 are.** By file, and the shape is worth reading before the individual finds:
`Anthropic.php` 6 of 37, `Provider.php` 1 of 27, and `Pkce`, `DeviceCode`, `OauthError` and
`Credentials` between them 3 of 13 — the pure parts are pinned. The survivors are in
`Antigravity.php` (51 of 128), `GeminiCli.php` (62 of 189), `GithubCopilot.php` (52 of 166) and
`CallbackServer.php` (67 of 191, plus 20 detected by hanging). And most of *those* are one of three
kinds: closing a socket or cancelling a watcher, where the end state is the same either way and there
is no seam to observe a peak through — the `HttpClient::follow()` measurement two entries up applies
unchanged; a guard on a value the caller cannot produce; and `?? null` against `''` in front of an
`is_string()`. These suites are thorough — 57 cases and 13 — and they cover nearly every rule this
document states about the four flows.

Three did not, and the first is a claim this file made about the platform:

**`CallbackServer::listen()`'s docblock said the warning is the only place the reason appears.**
Measured against a port already held: `$errstr` is `Address already in use` and the warning is
`stream_socket_server(): Unable to connect to tcp://127.0.0.1:8085 (Address already in use)` — the
same fact twice, and `$errstr` is the cleaner of the two, which is why the code prefers it. So the
handler earns its place by keeping a warning off the screen before the UI exists, not by carrying
information nothing else has; `$problem` is a fallback for a failure that populates one and not the
other. The fourth shape from the index, pointed at PHP rather than at this repository.

**And the test for it asserted the half that is a constant.** `testAPortSomebodyElseHoldsIsReportedByName`
checked the port number and the sentence after it, both of which are literals — so `unknown error` in
the middle passes, and deleting the whole `$reason` line passes. That is the `'ripgrep exited'` shape
exactly: *a test written against what the code says rather than against what the reader needs.* It
asserts `Address already in use` now, and that the message is **not** the warning's wording, since
both sources carry the reason and asserting the reason alone cannot say which one is used.

**`slow_down` adding five seconds had no test, and the first attempt at one held the mutation in
place.** The rule is upstream's and this document states it. The obvious test — serve `slow_down`,
abort, count the asks — passed identically with the five seconds and without, because
`MIN_INTERVAL` floors a declared interval of 0 at **one second**, so in a 150ms window there is
exactly one ask whatever the code does. Measured both ways rather than assumed, which is the only
reason it was caught: the window has to outlive one second for the two answers to differ at all. At
1.3s it is one ask against two, and the assertion is `assertSame(1, …)` rather than an upper bound,
because an upper bound is what was wrong the first time. *A test that cannot fail is not a test, and
the way to find out is to run it against the mutation it was written for.*

**Blank at the enterprise prompt had no test either.** `if (trim($typed) !== '' && $enterprise ===
null)` is two conditions, "something was typed" and "it is not a host", and either one alone turns
pressing Enter into an error — which is the whole of what `allowEmpty` on that prompt is for. `&&`
for `||` broke nothing.

**One divergence found on the way and left alone, because it is a decision rather than a fix.**
Upstream's `normalizeDomain` is `new URL("https://" + trimmed).hostname` in a `try`; pig's is
`parse_url('//' . $trimmed, PHP_URL_HOST)`. Run against the same inputs on both sides:

| typed | upstream | pig |
|---|---|---|
| `company.ghe.com` | `company.ghe.com` | `company.ghe.com` |
| `???`, `@@@` | null | null |
| `!!!` | `!!!` | `!!!` |
| **`a b`**, **`not a host!!`** | **null** | **accepted as a host** |

A space is a forbidden host code point to WHATWG and nothing to `parse_url`, so pig accepts a typed
domain with a space in it and goes on to build `https://a b/login/device/code`. It still fails — the
request cannot be made — but it fails with pig's own `Cannot parse URL` instead of the sentence
written for exactly this, and this document's claim that "something that is not a host is refused" is
narrower than it reads. A space is also the likeliest thing to be in a pasted domain. The fix is one
comparison; tightening a validation rule is the developer's call, so the measurement is here and the
change is not made. The regression test uses `???` so it pins the guard as written rather than the
behaviour somebody might want instead.

Regression tests: `CallbackServerTest::testAPortSomebodyElseHoldsIsReportedByName` (two assertions
added), `OauthTest::testSlowDownMakesTheNextAskWaitFiveSecondsLonger`,
`testBlankAtTheEnterprisePromptMeansGithubComRatherThanARefusal`,
`testSomethingTypedThatIsNotAHostIsRefusedBeforeAnythingIsAsked`. Each of the five mutations was
re-applied afterwards: four die, one is detected by hanging.

**With this the instrument has been over every directory in `packages/ai`**, and the tally across the
four sweeps is worth one line, because the *ratio* is the useful part rather than the totals:
providers 1,633 mutations with 191 survivors, `Http` 530 with 40, `Utils` 456 with 89 before the
tests and 63 after, `Utils/Oauth` 759 with 272. The last of those is not a worse-written directory —
it is four network flows whose bodies are socket discipline and whose decisions are already covered,
so the survivor count is dominated by lines with no observable effect. **A survivor rate is a fact
about what a suite can see, not a grade.**

## Why mutating the accumulator hangs the suite, and what that says about reachability

`AssistantMessageBuilder`'s 47 timeouts were recorded above as real and unexplained. They are
explained now, and the answer is a structural one about the five providers rather than about the
builder.

**Reproduced in one step** rather than inferred from the list: make `fail()` throw — one line, any
exception — and `AnthropicTest` does not fail, it **hangs**. The reason is the shape of `run()`:

```php
try {
    …the stream…
    $stream->push(new DoneEvent($message->stopReason, $message));
    $stream->end();
} catch (Throwable $error) {
    $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
    $failed = $builder->snapshot();
    $stream->push(new ErrorEvent($failed->stopReason, $failed));
    $stream->end();          // ← and nothing below this
}
```

`end()` is in both branches and in **no `finally`**, so a throw inside the `catch` never reaches it.
All five providers are `Async::spawn(fn () => $this->run(…))` with nobody awaiting the future, which
is the case this document already has a rule for, in as many words: *anything inside `Async::spawn()`
whose future nobody awaits must not be able to throw.* `run()`'s own `try` honours that for the body.
Its `catch` is outside any handler, so a throw there is lost and the stream is never closed — and a
consumer parked on a stream that never ends waits for ever. In a test that is a hung suite; in
`bin/pig` it is a spinner that never stops, because STDIN keeps the loop from ever going idle.

**So why is this a note and not a fix.** Nothing in that `catch` can throw. `fail()` assigns two
fields. `snapshot()` walks `$this->blocks`, whose every element `push()` initialises with a string or
an array, and hands them to four constructors that take exactly those types. `setUsage()` is
arithmetic. `EventStream::push()` returns early when the stream is done and cannot double-complete.
Checked one by one rather than assumed, because "can this throw" is the whole question here.

Which puts it in the category this project refuses: **a branch only a test could reach.** The 47
timeouts are therefore not a coverage gap — they are detections of a state no provider can be in,
and the correct reading of the sweep is that the builder is *pinned*, not that it is unprotected.

**And that distinction is the general lesson, because a hang and a failure mean different things
about reachability.** A mutation that makes the suite *fail* says the code under it is load-bearing
on a path the tests reach. A mutation that makes the suite *hang* says something else: the thing it
broke is also what the error handling depends on, so the failure path failed too. Every one of these
47 is that shape. Worth knowing before reading a timeout as either a detection or a defect — it is
a detection, and what it is detecting may be unreachable.

**A `finally` was the obvious answer and it was tried and reverted, which is the more useful half.**
The change looked free — the two `$stream->end()` calls become one below the `catch`, six files
(the five providers and `StreamProxy`, whose tail is byte-identical), a line removed rather than a
branch added. With `fail()` throwing it **still hangs**, and the reason is a fact about `EventStream`
worth knowing on its own: `end()` takes an optional result, and a bare `end()` **does not complete
`finalResult`** — that only happens when a terminal event is *pushed* and `isComplete()` recognises
it. So the `finally` closes the iteration and leaves `result()` unresolved, and a consumer awaiting
the turn's outcome waits exactly as long as before. The construction that does both is the one
`AgentLoop` already uses for this shape, `EventStream::fail($error)` — which would mean a nested
`try` inside the `catch`, for a state nothing can reach.

And the decisive fact is one that should have been checked before the option was offered at all:
**upstream has the same two branches and no `finally`.** `anthropic.ts` ends `push({type:"done"})`,
`stream.end()`, `catch`, `push({type:"error"})`, `stream.end()` — the shape pig ports. So the port is
faithful, the hazard is upstream's too, and the answer to "should this be structural" is that it is
not structural there either. Six files reverted, and what is left is this paragraph: *the option I
put to the developer was worse than it looked in two independent ways, and one `grep` of upstream
would have said so first.*

**One more harness note, and it is the cheapest of the four.** This sweep appeared to hang at
`77 mutations` with a single worker. It was not hung and it was not contaminated: the jobs were
submitted from a **generator** — `for i, f in enumerate(pool.submit(...) for m in jobs)` — so
`f.result()` blocked before the next `submit()` and eight workers ran one at a time, turning five
minutes into forty. The list has to be built before any result is read, which every earlier version
of the script did. *Three of this instrument's four bugs have looked like something other than what
they were, and the tell each time was a number that did not match the machine: thirteen processes for
eight workers, one process for eight workers.*

## A changelog and a version, and two tests that were pinning the absence of the file

Asked for directly: *一打开就可以看到是否有更新* — a start that says whether there is a newer pig, the way
pi's does. Three things were missing and the developer named all three: no `CHANGELOG.md`, no `bin`
key or `version` in `composer.json`, and no executable bit on `bin/pig` in git. Three answers
settled the shape — the version comes from **Packagist**, the notice **prints the command and
updates nothing** (which is pi's behaviour, and `pi update` is for *extensions*), and the check is
**on by default with a way off**.

What that produced: `CHANGELOG.md`, `"bin"` in `composer.json`, `CodingAgent\Version`,
`Cli\UpdateCheck`, `Cli\SelfUpdate` (`pig update`), `Settings::updateCheckEnabled()`, `--no-update-check`, and
`InteractiveMode::sayNewVersion()`. `bin/pig`'s `const VERSION = '0.1.0'` is
`define('VERSION', Version::current())`, because **a `const` needs a compile-time value** and a
second copy of the number is the thing `Version` exists to prevent.

Five things in it are decisions rather than code:

- **Every way the check can go wrong is silence.** A 404 — which is what Packagist answers until pig
  is published, so it is today's ordinary case — a 500, a body of the wrong shape, a proxy that
  refuses, a machine with no network: all null, nothing on screen. Not a silent fallback, because no
  value is being invented; the alternative is a warning every morning, which is how people learn to
  skip warnings.
- **It is spawned, inside the one `Async::run()` the interactive start already has.** A network call
  in front of the first frame is a start that hangs on a bad network, and the answer arriving while
  somebody is already typing is exactly what `sayNewVersion()` being public is for.
- **A pre-release is never offered and is always told**, which sounds contradictory and is two
  questions: a beta is not *offered* to somebody who did not ask for one, and somebody already
  running `0.2.0-beta.1` is told when `0.2.0` lands. `version_compare()` orders both correctly —
  `Changelog`'s rule, for `Changelog`'s reason — so the asymmetry is one `str_contains` on the way
  out and one regex on the way in.
- **The version lives in the git tag and nowhere else.** This bullet said `composer.json` for one
  batch; see the entry below for why that was wrong and what it cost.
- **"you have" is `$this->version`, not `Version::current()`.** The component already holds the
  string the banner drew, and reading the manifest a second time made the screen contradict itself
  three lines apart — banner `v0.0.0`, notice `you have 0.1.0` — about the one comparison the block
  exists to make.

**And the two tests that had to change are the entry.** Both went red on the *existence* of
`CHANGELOG.md`, having been written when there was none:

| | asserted | why it passed |
|---|---|---|
| `InteractiveModeTest::testSlashChangelogSaysSoWhenThereIsNoChangelog` | `No changelog entries found.` | `/changelog` calls `Changelog::parse()`, which reads the file at the repository root. The constructor's `$changelog` is the **upgrade note** only, so injecting nothing was never "no changelog" |
| `ChangelogTest::testPigsOwnPathIsBesideTheReadme` | `Changelog::parse() === []` | same file, and the assertion would have been green for a `path()` pointing anywhere at all |

So the one test of `/changelog` and the one test of the default path were both pinning that a file
did not exist. *A test whose subject is a file is worth asking what it asserts on the day the file
appears* — and the tell is the same one this document keeps naming: **an assertion that holds either
way is not an assertion.** `parse() === []` holds for a correct path to a missing file and for a
wrong path, which is two facts sharing one green. Both assert the pair now — `path()` names a file
this parser can read, and the version this pig is has an entry in it — and the empty answer keeps
its own case against a path that is not there, which is where a question about a missing file
belongs. Two docblocks asserting "pig has no `CHANGELOG.md`" went with them, in `Changelog` and in
two places in this document: the fourth shape from the index, on the file the feature is about.

**Each end was mutated separately**, which is the rule this feature had four chances to break and
broke twice. Eleven mutations: the `<=` (the version you have offered as an upgrade), the
pre-release skip, the `v` strip, the swallowed `Throwable`, `Version::path()`, both facts in the
notice, `Changelog::path()`, and `bin/pig`'s two. Nine die. **Two survived and one of them was a
real gap**: `Settings::updateCheckEnabled()` could `return true;` with the whole suite green — the
setting had no test at all, so a switch that works in production was inert in every test, which is
this document's commonest shape. Three `SettingsTest` cases close it, and the `!== false` is
deliberate rather than tidy: an empty `update` block is somebody who set a sibling key, and reading
that as "off" is a feature turning itself off because a neighbour was written.

Two things stay unpinned and the reason is on record rather than guessed:

- **`bin/pig`'s `$checkForUpdates` line.** Replacing it with `true` kills nothing, because the only
  consumer is the interactive branch, which needs a terminal, and the check's whole observable
  effect is a network call the container cannot make plus a block `sayNewVersion()` draws on its
  own. `--help` naming the flag is what there is. If this is ever worth closing, the piece to move
  is the decision — a `bin/pig` that asked something testable which check to make would be
  pinnable, and that is new surface rather than an audit's call.
- **`Version`'s cache.** `return self::$current = …` → `return …` survives: the answer is identical
  and the cost is one extra `file_get_contents` per call, with no seam to observe the peak through.
  The same standing as `HttpClient::follow()`'s `body->close()`.

**And the harness bit back, for the fifth time, in the fifth shape.** A mutation run hit my own
two-minute limit, the python was killed with SIGTERM mid-mutation, **its `finally` never ran**, and
the tree kept `$version = $version;` where the `v` strip had been. The next batch then measured two
mutations against a tree carrying a third — so one kill was partly somebody else's and the survivor
could have been anything. Caught by grepping for `ltrim` in a file I had just read and finding none.
The four earlier bugs were a worker race, reused trees, a replacement that never matched and a
filter missing a consumer; this one is **the harness being killed from outside**, and the fix is
that the restore copy is written to disk *before* the mutation is applied, the run gets a timeout
well inside mine, and the script asserts the file is byte-identical afterwards. *A `finally` is not
a guarantee when the signal comes from outside the process — the only durable undo is a copy on
disk made first.*

**What is left for the developer, and it is not a code change.** `bin/pig` and `bin/pig-ai` have no
executable bit in git and the container copy is not a git repository, so that one is one command on
the Mac: `git update-index --chmod=+x bin/pig bin/pig-ai`.

## Ten test files could not be loaded by PHPUnit at all, and the shim could not see it

The first real `vendor/bin/phpunit` run in a long while did not fail a test. It **could not build the
suite**:

```
PHP Fatal error: Cannot override final method PHPUnit\Framework\TestCase::run()
  in packages/ai/test/GenerateModelsTest.php on line 218
```

`TestCase::run()` is `final`, and overriding a final method is a fatal error **at class-load time** —
so PHPUnit's `TestSuiteLoader` died on the first file that did it and **no test in the repository
ran.** Not the file's tests: none of them, in any package.

`GenerateModelsTest` had a private `run()` that shells out to the generator. Renamed, and then the
question worth asking was not "is that file fixed" but **who else does this** — the first shape from
the index, pointed at a rule PHPUnit owns. `TestCase` has **70 final instance methods**, and nine
more collisions were sitting behind the first one:

| | |
|---|---|
| `AgentLoopTest::run()` → `drive()` | 9 call sites |
| `ModelsTest::any()` → `anyFrom()` | 4 |
| `OauthTest::run()` → `onTheLoop()` | 36 |
| `BashOutputTest::output()` → `outputOf()` | 2 |
| `PrintModeTest::run()` → `execute()` | 22 |
| `SignInTest::run()`/`output()` → `signIn()`/`said()` | 12 + 10 |
| `ToolTestCase::run()`/`output()` → `execute()`/`textOf()` | **77 + 56**, across six subclasses |

`ToolTestCase` is the base class the tool tests extend, so that pair alone is most of
`coding-agent`'s suite. `run` and `output` are the obvious names for "run the thing" and "what came
out", which is exactly why they were chosen six separate times and exactly why they are taken.

**The shim is why this survived**, and it is a new entry in the list of ways it is more permissive
than the real runner. The known ones were `failOnWarning`, `failOnNotice` and `failOnDeprecation` —
a mutation that only warns survives the shim and dies under PHPUnit. This one is worse in kind: the
shim's own `TestCase` has no `run()` to be final, so it ran, green, a suite PHPUnit **could not
load**. *A stand-in runner tells you about the tests; it does not tell you the real runner can start.*
So the shim now sweeps for it before running anything and refuses with the list, which was checked by
putting one collision back and watching it refuse.

The neighbouring PHPUnit 12 rule was swept at the same time and is clean: every method named by a
`#[DataProvider]` is `public static`, which that version requires and older ones did not.

## What the first real PHPUnit run said, once it could load the suite

With the ten final-method collisions gone, `vendor/bin/phpunit` ran for the first time in a long
while: **2595 tests, 2 errors, 5 failures, 1 deprecation.** Not one of them was a bug in pig's
behaviour, and that is the interesting part — every one was either a test coupled to the machine it
ran on, or a test that could not work under the real runner. So the entry is about the **kinds**.

**Kind one: a test that passes or fails by what the machine happens to have.** Two of these, both
green in the container and red on a Mac that had actually used pig:

- **`SearchToolsTest`'s two missing-tool tests emptied `PATH` and stopped there.** But
  `ExternalTool::locate()` looks in **pig's own `~/.pig/agent/tools` first**, deliberately, so a downloaded
  copy beats one the PATH shadows — which means on any machine where pig has ever fetched `fd`, the
  tool was found and nothing threw. A test about something being absent that only passes where it was
  never present. `PIG_CODING_AGENT_DIR` now points at an empty directory as well, which is what
  `ToolInstallerTest` had been doing in its `setUp` all along: *the answer was already in the
  repository, in the sibling that got it right.*
- **`InteractiveModeTest`'s export test asserted a path against the wrapped screen.** In the
  container `/tmp/pig-interactive-…` fits on one line; on macOS
  `/var/folders/mk/6fds…/T/pig-interactive-…/out.html` wraps, and the renderer split it mid-word as
  `out.` / `html`. Reproduced here by pointing `TMPDIR` at a path of that shape. Collapsing
  whitespace does **not** undo it — joining those two rows gives `out. html`, which is not the
  filename either — so `screenText()` renders at a width nothing wraps at. The rule: **assert layout
  against `screen()` and words against `screenText()`**, and a path in an assertion is words.

**Kind two: a test that could not work under the real runner, and the shim could not show it.**
`testATagCutWithAVeeIsNotTheVersionItReports` steered `Version::current()` with
`InstalledVersions::reload()` and reported `dev-main` instead of the fabricated `v0.9.0`. Reading
Composer's source says why: `getInstalled()` consults **every registered ClassLoader's dataset
before** the one `reload()` sets, so a reloaded version is ignored wherever an autoloader is
registered — which is every real run. **The shim registers no Composer ClassLoader**, so there the
reload was the only dataset and it worked. That is a new line in the list of ways the shim differs,
and a structural one rather than a strictness one: *anything that depends on
`ClassLoader::getRegisteredLoaders()` behaves differently under the shim.*

The fix was not a better seam. Stripping a tag's `v` was written out **twice** — `Version::current()`
and `UpdateCheck::newest()` — which is the first shape from the index on a one-line rule, and the
worst place for it, since the two are the opposite sides of one comparison and a disagreement makes
`v0.2.0` newer than `0.2.0`. It is `Version::plain()` once, both readers call it, and it is tested as
what it is: a pure function over a table. **New public API, so it was flagged rather than assumed.**

**Kind three: hygiene that only shows up in a long-lived process.** Two `OverflowTest` errors,
`Reader r1 watches a closed stream; cancel the watcher before closing it` — which is this document's
own trap, thrown from inside a test that had done nothing wrong. Two causes, both fixed:

- **`OverflowTest` and `HookRunnerTest` were the only two test classes that turn the loop and never
  `Loop::reset()`.** The loop is a singleton that outlives a test, so those two inherited whatever
  watchers the previous test left — and the error names *their* `Async::run()`. Found by sweeping
  every class for the pair (touches the loop, never resets), which is two out of forty-odd.
- **`CannedServer` never cancelled the watcher on its listening socket**, and nothing ever stopped a
  server: `OverflowTest` builds one per call and drops it. So the stream went away on garbage
  collection with its watcher still armed. It has `stop()` and a destructor now, cancelling before
  closing, and its two deferred writes are guarded with `is_resource()` so a stopped server cannot
  write to a closed peer. A helper is not exempt from the rule the rest of the tree follows.

The container could not reproduce the second one on its own — the probe said OK with the destructor
removed, because `Loop` holds the stream resource itself in its watcher record and so keeps it alive.
That is worth knowing: **the container's loop keeps a dropped socket open, so this class of leak is
invisible here and shows up wherever GC and test order differ.** Both fixes are correct by the rule
whether or not the ordering that exposed them can be staged again.

**And one deprecation, which `failOnDeprecation` makes a failure:** `ReflectionMethod::setAccessible()`
is deprecated in PHP 8.5 and has had no effect since 8.1 — reflection reaches a private method by
itself. One call in the tree, in `GoogleTest`, removed.

**The last two were half of a commit that did not travel.** Commit `98ed239` ("Align with Pi system
prompts") changed `SystemPrompt.php`'s wording to upstream's and left `SystemPromptTest` asserting
pig's earlier phrasing — `read-only work only` against `ONLY for read-only operations`, and `Use bash
for file work` against `Use bash for file operations like ls, grep, find`. The source was the
intended state and the assertions followed it, which is also the direction this file's own fidelity
rule points: where the two disagree about what upstream says, upstream decides.

Worth knowing for anybody reading an older note: **the prompt and its test were treated as off-limits
for a while**, as the developer's own working copy, so they were held out of every sync. That no
longer holds — the edit was upstream alignment rather than private work, and both files are ordinary
parts of the tree again. *A rule about which files not to touch is worth re-asking about, because the
reason for it expires quietly.*

## Packaging for Packagist, and the version field that was the wrong answer to the right question

The entry above got `Version` right in principle and wrong in fact. Its reasoning — *one written-down
number beats two* — is sound, and it was pointed at the wrong file: **Packagist derives a package's
version from the git tag**, and its own documentation says the `version` field "should be omitted",
for precisely the reason the entry gives. So `composer.json` holding `0.1.0` did not remove the second
copy, it *created* it, and the failure it warned about (a build from tag `v0.2.0` reporting itself out
of date for ever) became "somebody forgot to edit the field before tagging" — the same bug with a
manual step in front of it. *A rule about not writing a fact down twice is only as good as knowing
where the first copy already is.*

Both of these were the developer's calls, and both came out the way the facts pointed.

**One package, not six.** packagist.org does not host archives — it references the source — so it
cannot serve a package from a subdirectory, and multi-package repositories are a Private Packagist
feature. The monorepo therefore publishes as a single `pigagent/pig` whose root `composer.json` autoloads
all five namespaces out of `packages/*/src`. What that removed is worth listing, because every line
of it existed only to make the split work in a checkout: the `path` repository, the five
`pig/*: "@dev"` requires, `minimum-stability: dev` and `prefer-stable`. All four are root-only
settings anyway — **Composer reads `repositories` and `minimum-stability` from the root package and
nowhere else** — so `composer global require pigagent/pig` would have gone to Packagist for five packages
that are not there, with `@dev` constraints nothing would satisfy. The alternative, splitting into six
read-only repositories with a CI job to push them, buys `require pig/ai` for somebody who has never
asked for it, and costs six tags to keep in step — which is the rule at the top of this file.

The five `packages/*/composer.json` stay, and what they are has changed: **structure documentation,
not manifests.** Nothing reads them now. They are kept because the package split is the port's
skeleton and each one states what its package needs, but a requirement written only there is a
requirement Composer will not enforce — which is exactly how the next paragraph happened.

**`type` is `library`, not `project`.** `project` means "an application you `create-project` from";
the default is `library` and that is what a CLI installed with `composer global require` is.

**And collapsing found a requirement nothing declared: `ext-pcntl`.** This document has said since
`pig/tui` was ported that the extension is required, with the reason (SIGWINCH is the only way to
learn the window was resized) and the consequence (`ProcessTerminal` *refuses to start* rather than
drawing at the startup width for ever) — and **no `composer.json` in the tree listed it.** So
`composer install` succeeded on a machine without it and pig died at startup with a `TuiError`,
where Composer's whole job is to refuse beforehand. The fourth shape from the index, on a manifest:
a claim about the repository the repository did not keep. It is in the root's `require` now, with
`ext-json`, `ext-mbstring`, `ext-openssl` and `ext-pcre`, which the sub-packages declared and the
root — the only one Composer reads — did not.

**`Version` asks Composer instead of reading a file.** `Composer\InstalledVersions::getPrettyVersion('pigagent/pig')`,
`v` stripped. That costs one platform requirement, `composer-runtime-api ^2.0`, which is Composer's
own runtime rather than a package anything downloads — the same kind of entry as `php` or `ext-json`,
and it was put to the developer as a dependency question because that is the standing rule.
`getPrettyVersion` and not `getRootPackage()`: installed globally, pig is a *dependency* of the
global project, so the root package is that project and not pig.

**A checkout is not a release, and the unguarded shape of that is the dangerous one.** Measured, by
rewriting `installed.php`'s answer four ways:

| Composer says | `pig --version` | asked of Packagist |
|---|---|---|
| `0.1.0` (tag `v0.1.0`) | `pig 0.1.0` | yes |
| `v0.2.0` | `pig 0.2.0` | yes |
| `dev-main` (a branch) | `pig dev-main` | **no** |
| `1.0.0+no-version-set` (no tag reachable) | `pig 1.0.0+no-version-set` | **no** |

`dev-main` is obvious. `1.0.0+no-version-set` is the one to be careful about: it *reads as* `1.0.0`,
so an unguarded `version_compare()` puts a checkout ahead of every real release and the check goes
quiet — right answer, wrong reason, and it would have stayed quiet after `1.0.1` shipped too. The
gate is `UpdateCheck::A_RELEASE`, anchored at both ends so the suffix is refused rather than ignored
down to the number in front of it, and it lives with the other reasons that class says nothing rather
than as a condition in `bin/pig`. Both are printed as Composer words them, because `no-version-set`
is a sentence a developer can read and an invented `0.0.0` is a number they would have to go and
check.

**The two tests that asserted the field are gone the same way the changelog ones went.**
`testPigsOwnVersionComesFromComposerJsonAndNowhereElse` asserted `$manifest['version'] ===
Version::current()`, which was true and was a test of the wrong fact; it now asserts `composer.json`
has **no** `version` key, which is the decision. And `testTheChangelogHasAnEntryForTheVersionThisIs`
could only ever pass where the version was a release, so it skips in a checkout and says which
version made it skip — the assertion that matters runs on a tag, which is where it is wanted.

**Two things the verification tripped over, both stale generated state rather than source.** The
shim autoloads `packages/*/src` by hand and bypasses `vendor/`, so it had no
`Composer\InstalledVersions` and every version test died on a missing class; it hands that one file
over now. Worse: `vendor/composer/installed.json` still recorded `pig/ai` and `pig/async` as
requiring `php >=8.4`, from before the floor moved to 8.3 — so a `dump-autoload` regenerated
`platform_check.php` as `PHP_VERSION_ID >= 80400` and **every test that spawns `bin/pig` failed on
8.3**, 35 of them, with a Composer platform error that named nothing about pig. The metadata was
corrected to what `packages/*/composer.json` actually declare. *A generated file can be years out of
date and cost nothing until something regenerates a second file from it* — and collapsing to one
package removes this whole class, since there will be no `pig/*` entries in anybody's lock.

**Installed, and the first install failed on the one thing nothing warns about.**
`composer global require pigagent/pig` locked and installed `v0.1.0` cleanly — which is the real
acceptance test, since it exercises the `bin` declaration, the five namespaces' autoload and the
`ext-*` requirements at once — and then `pig` answered `command not found`, because Composer's global
bin directory is not on anybody's PATH until they put it there. Composer prints nothing about this.
The README said `composer global require …` and then `pig`, so **the install instructions did not
work as written**; both now name the one-time fix. It is a per-machine prerequisite shared by every
Composer-installed command rather than anything about pig — and it is worth knowing that it is
*not* the executable bit: a binary without one answers "permission denied", not "command not found".
The bit was missing from git all the same (`100644`) and is `100755` now, which matters for whoever
clones rather than installs.

**And it is not what upstream's users go through, which is worth knowing before dismissing it as
normal.** `npm install -g @earendil-works/pi-coding-agent` needs no PATH step, because **npm derives
its global prefix from the `node` binary's own location**, so a global install lands in the directory
that already holds `node` — and that directory is on PATH by definition, or you could not run `node`.
Composer has no equivalent property: `composer` lives in one directory and `$COMPOSER_HOME/vendor/bin`
is another, which nothing puts on PATH. So "every PHP CLI is like this" is true and is not the same
claim as "this is as good as it gets"; the JS side of the same tool simply does not have the problem.
Nothing pig ships can change it — a package cannot choose where Composer puts its bins.

**`pig update` is the CLI command, matching pi's `pi update`.** Instead of telling users to run
Composer directly, `UpdateCheck::COMMAND` is `'pig update'` and `bin/pig update` calls `Cli\SelfUpdate`.
It checks the runtime environment: in a git repository checkout it runs `git pull` followed by
`composer install`, while in a Composer global installation it executes `composer global update pigagent/pig`.
`pig update --extensions` updates installed extension packages via npm. `pig update --models` refreshes model catalogs.
Startup checks also spawn `PackageUpdateCheck` to check npm packages asynchronously and render
`Package Updates Available` boxes with `sayPackageUpdates()`, matching upstream pi's layout.
https://pigagent.dev/install.sh | sh` runs `composer global require` — the same one package from the
same Packagist — so `Version::current()` still reads Composer's answer and
`UpdateCheck::COMMAND` is still the right command for anybody who used it. What it adds is the two
things Composer will not do: **name every missing PHP extension before anything is installed**,
where a missing `ext-pcntl` otherwise surfaces mid-install as a platform error that names no fix, and
**offer to put Composer's global bin directory on the PATH**, which is the step that turns a
successful install into `command not found`.

Modelled on upstream's, and the reading corrected a claim made here first: pi's quickstart says "the
curl installer uses npm globally", which describes its **legacy** path — the default is a *managed*
install that fetches release metadata from `pi.dev/api`, runs `npm ci` into
`~/.pi/agent/install/releases/<version>` and symlinks a launcher onto the PATH. That layer exists to
pin transitive dependencies npm's global install does not, and **pig has none to pin**, so copying it
would buy nothing and re-create the two-version-sources problem below. What was worth taking is its
shape and four functions: picking the shell profile from `$SHELL`, `fish_add_path` versus `export
PATH`, a whole-line `grep -Fxq` so running the installer twice does not append twice, and asking
through `/dev/tty` — necessary because a piped script *is* standard input. 200 lines against
upstream's 1789; the 600 lines of logo animation and raw-key menus are not the part that works.

**The structural thing to keep: every function is defined first and the last line is the only call.**
Piped to `sh`, a download cut off half way still executes what arrived — so a script that did work
before its end would perform half an install. `InstallScriptTest::testNothingRunsUntilTheLastLine`
pins it, because nothing else would notice a refactor that moved the call up.

And the extension list is a **second copy of what `composer.json` requires**, deliberately: a shell
script cannot read the manifest before PHP is known to exist, which is the very case the preflight is
for. So it is pinned instead — the list, the minimum version in both the compared and the printed
form, and the package name against `Version::PACKAGE`. Five mutations, five kills.

**No phar and no Homebrew tap, for now, and the reason is not effort.** A second install route is a
second answer to *which version am I running* — the thing `Version` was just rebuilt to have exactly
one of — and worse, a `brew`-installed copy would be told to run `composer global update
pigagent/pig`, a command that does nothing for it. Closing that means `UpdateCheck::COMMAND` branching
on how pig was installed, which is a real feature rather than a packaging chore. Both routes are
feasible (box bundles `vendor/composer/installed.php`, so `InstalledVersions` answers inside a phar,
and `Changelog::path()` resolves under `phar://` once the file is bundled); the developer's call was
to wait, on the grounds that building a second distribution channel for friction that no user has hit
yet is exactly the anticipation this project keeps declining. The entry to reopen is this one.

**The vendor `pig` was taken, which is why the package is `pigagent/pig`.** Submitting answered what
nothing here could check: `pig` on Packagist holds an unrelated `pig/router`, and vendor names there
are first-come-first-served, so the claim route was closed and the name had to change before the
first release. `pigagent` matches the project's own domain. It cost two lines — `Version::PACKAGE`
and `composer.json`'s `name` — because the name is written **once** and `UpdateCheck::COMMAND` is
built from it; `UpdateCheckTest` asserts the manifest and the constant agree, which is the test that
makes a rename two lines rather than a hunt. **The binary is untouched**: `pig` is what `bin` says
and has nothing to do with the package name, so nothing a person types changed.

Worth knowing for the next name: `vendor/composer/installed.php` records the old one until
`composer install` or `update` runs — `dump-autoload` does **not** rewrite it, which is the same
staleness that turned a years-old `>=8.4` into 35 failures two paragraphs up. So a rename looks
broken (`Composer does not know about pigagent/pig`) until the lock is refreshed.

`authors` is `owner888` with a homepage, which is the GitHub account rather than the name the
standing rule keeps out of this repository. And `composer.json` no longer matches any lock, so the
Mac needs a `composer update` before anything else.

**And publishing has a consequence this document leans on in two places.** The back-compatibility
row says *"pig has never shipped, so there is nobody with an old file"*, and the session-format
section says there is *"no reader for the old shape, on purpose"* because *"a reader for it would be
compatibility with nothing"*. Both are arguments from having no users, and **the day `pigagent/pig` is on
Packagist they expire** — the rule they rest on is already stated correctly ("back-compatibility is a
debt to real users, and there are none until there is a release"), so nothing in it is wrong, but
the release is what starts the debt. Related and smaller: the root package is a path-repository
monorepo, so `composer global require pigagent/pig` cannot install it as it stands — the five
`packages/*` have to be published too, or the root has to stop depending on them by path. That is a
packaging decision and it is the developer's.

## 831 mutations over the eight files whose mistakes are not undoable

The instrument had been over every directory in `packages/ai`. This is `coding-agent`, narrowed to
the files where a bug is neither visible nor recoverable: `ReadTool`, `WriteTool`, `EditTool`, `Run`,
`Truncate`, `SessionManager`, `SessionEntries`, `SessionCodec` — the four tools that change the
user's files or kill their processes, the arithmetic that decides what the model is shown of them,
and the three classes that write the session file **pi also reads**. Everywhere else in the tree a
mistake shows up as a wrong answer somebody can see; here it shows up as a file that is gone.

**831 mutations, and the estimate before it ran was 945** — 0.38 per source line, the rate the five
earlier sweeps had been stable at. Off by 12%, which is worth knowing next time a sweep is budgeted
from the rate rather than counted: the rate is a planning figure, not a measurement.

Pass 1 detected 706 of the 831 — 692 killed outright and 14 by hanging the suite, which is a
detection too. **219 survived it before the tests below and 125 after.** What it found divides in
four, and the first is the one worth the words.

**`Truncate` had no test of its own.** 78 mutations, 40 survivors — the highest rate of the eight,
and nearly all of them one shape: `<=` became `<` and `>` became `>=` across `head()`, `tail()`,
`line()`, `size()` and the byte accounting, and the whole suite stayed green. **Every bound turned
out to be right**, which is not an argument for leaving it alone: the correctness came from a run
against upstream over 777 documents, the run was not kept, and *what a corpus proves it proves on the
day it ran.* Third time — after `PartialJson` and `JsonSchema`, whose entries say the same thing. So
`TruncateTest` is 21 cases written to `JsonSchemaTest`'s rule, **asserting that a bound rejects what
is out of range is not a test of the bound**: each pins the value that exactly reaches the limit *and*
the first one past it, because only the pair says where the edge is.

**Four real gaps, each one arm of a guard nobody could reach.**

- **The "Not a pig session file" guard was testable only on its easy arm.** `!is_array($header) ||
  ($header['type'] ?? null) !== 'session'` — the existing fixture wrote `just some text`, which fails
  **both** halves, so `||` → `&&` survived. Verified consequence: pig opens a `~/data/train.jsonl` as
  a conversation and appends to it. The same guard is in `describe()`, so it is also a row in
  `--resume`'s list that is not a conversation.
- **Escape between the model asking for a write and the write happening was unpinned in all four
  tools.** Deleting `$signal?->throwIfAborted()` from `read`, `write` or `edit` broke nothing. For
  `write` and `edit` that is the one thing escape has to mean: the file afterwards is the file that
  was there before.
- **Two write-failure detections never fired.** `file_put_contents(…) === false` in
  `SessionManager::write()` and in `WriteTool` — mutate the `false` and the throw is unreachable, and
  the model is told `Wrote  bytes to …` (false cast to a string) about a file that does not exist,
  then carries on editing it. Neither is easy to stage on purpose, so the tests use the two shapes
  that fail without needing a permission: a directory standing where the file should be, and a
  symlink into a directory that is not there. **A failing `file_put_contents` warns as well as
  answering false**, and a warning fails a test under `phpunit.xml`, so both scope a
  `set_error_handler` around the one call with the reason on it.
- **A compaction replayed the messages that came after it.** `keptFrom()` walks up to the compaction
  and `break`s there; without the break it collects the messages after it as well, and the walk then
  appends those a second time — a resumed conversation whose last exchange the model is shown twice.

**And a whole family the sweep is unusually good at: the default on every field pi omits.** The
session file is pi's, so a file written by pi — or by a pi old enough to predate a field — arrives
with that field missing, and what missing *means* is a decision per field rather than one rule. Every
one of them survived: `display` on a hook's message (absent means shown, or a hook that told somebody
something told nobody), `fromHook` on both summaries in both decoders (absent means pig's own, which
is pi's own rule for its own field, and it decides whether `Compaction::files()` carries the file
lists forward), `cancelled` and `truncated` on a typed `!command`, the `firstKeptEntryId` that is null
rather than an id matching nothing, and the timestamp that orders the catch-up flush. `millis()` had
no test at all, so `$at === false` → `!==` — every timestamp read back as *now* — was green.

Smaller, same batch: the `/resume` list's label was "the first thing said" and reading past it was
unpinned (every row labelled by the last thing typed into it); a conversation whose first message
opens with a **pasted screenshot** went through `opening()`'s `instanceof` in a way that only held
because of an `&&`; the message count counted messages rather than lines; `Run`'s timeout timer was
left armed when a command beat it, which keeps `isIdle()` false and is a session that finishes and
will not quit; both spill thresholds were unpinned at the exact limit, where a file is written that
nothing will ever name; and **`timeout: 0` meant no timeout where one operator over it means no
time** — the schema says `number` and the description says there is none by default, so a model
spelling "no limit" as 0 is ordinary, and the mutated guard kills every command instantly.

**The third kind is the survivors with a measured reason, and two are worth knowing.**
`stream_set_blocking($pipe, false)` in `Run::start()` looks load-bearing and is not: every read is
gated by `Loop::onReadable()`, and `fread` on a **blocking** pipe returns what is available rather
than filling the buffer — measured, 0.000s and `"one\n"` on both settings against `echo one; sleep
0.4; echo two`. So the line cannot be observed from outside and the mutation is equivalent.
`ReadTool` and `EditTool` each check the signal twice, and **neither check can be killed alone**
because nothing between them suspends: the tools are synchronous, so the signal cannot change, and
only deleting both changes anything (which `testAnEditThatEscapeStoppedLeavesTheFileAsItWas` catches).
The rest are the familiar three — `?? null` against `''` in front of a type check, initialisers whose
deletion *warns* (so `failOnWarning` kills them and the shim does not), and cleanup whose end state is
identical: `fclose($this->spill)` is belt-and-braces because `Run` is local to `execute()` and PHP
closes the handle when it goes, which is `HttpClient::follow()`'s `body->close()` again. The 125 in
one line: 56 are `?? null` against `''`, 41 are statement deletions (mostly those initialisers), and
the remaining 28 are operators inside the three shapes above.

| | mutations | survived, before | after |
|---|---|---|---|
| `Truncate` | 78 | 40 | 10 |
| `SessionManager` | 366 | 78 | 67 |
| `SessionEntries` | 71 | 23 | 13 |
| `SessionCodec` | 43 | 12 | 1 |
| `ReadTool` | 69 | 9 | 5 |
| `EditTool` | 25 | 5 | 5 |
| `WriteTool` | 17 | 3 | 1 |
| `Run` | 162 | 43 | 37 |

`EditTool`'s five do not move and each is accounted for above: the two signal checks that cannot be
killed apart, the read-only case that skips as root, and two failures — a read that cannot fail after
`is_file`, and a write that cannot fail after `is_writable` — with no way to stage either. **A
survivor rate is a fact about what a suite can see, not a grade**, which this file has said once
before and is the right reading of that row.

**The fourth kind is the harness, for the seventh time, and this one changed the source.** A
throwaway script re-applied each mutation to confirm it now dies, matching its target line by
`old in line` — and the pattern `            $this->closePipe($fd);` is a **substring of the
deeper-indented call inside `read()`**, so it deleted the wrong line. Then my own two-minute limit
SIGTERMed it mid-job, the `finally` did not run, and the tree kept a `read()` that never closes a
pipe at EOF. Caught by reading the file rather than by a failure: with the line gone the suite still
passed 28 of 28, because every command in it is short enough that `close()`'s own sweep tidies up
afterwards. Three rules, and the middle one is new:

- **Anchor a mutation to one line and refuse when the pattern matches more than one.** Substring
  matching across an indented tree finds the wrong statement silently.
- **The undo is a copy on disk written before the mutation** — already the rule (bug 6) — **and the
  restore is asserted byte for byte at the end.** The assertion is what turns "it probably restored"
  into a fact.
- Give the run a timeout well inside the caller's, so the process ends itself rather than being
  killed from outside.

Regression tests, and each of the mutations above was re-applied afterwards to confirm it now dies:
`TruncateTest` (21 cases, the whole file), `SessionManagerTest`'s eleven new ones (the two guards, the
self-parented entry, the orphan, the list's label and count, a cleared and a whitespace-only name,
the unwritable file, the compaction's trailing messages, the stray directory),
`SessionEntriesTest` and `SessionCodecTest` (new files, the defaults and the two `default => null`
arms), `PiFormatTest::testTheUpgradedFileKeepsOnlyOneClaimAboutWhereTheCutIs`,
`ReadToolTest`'s four, `EditToolTest`'s two, `FileToolsTest`'s two, and `BashToolTest`'s five.

## Two files that belonged in those eight, and the front door neither was tested through

The eight were chosen by one rule — *a mistake here is neither visible nor recoverable* — and two
files that fit it exactly were left out. **`Migrations`** moves somebody else's session files and
renames their `oauth.json`, and this document already says of it that "half-migrating somebody
else's data is worse than none". **`Auth`** writes the credentials file pig and pi *share*, and
Anthropic rotates refresh tokens, so one bad write there signs you out of both tools at once. 333
mutations over the two of them, 87 alive, and 62 after the tests below.

**The find is one shape and it is in both files: every test went in through the seam and none
through the door.** `MigrationsTest`'s sixteen cases all call `authToAuthJson($directory)` or
`sessionsFromAgentRoot($directory)` with a directory handed to them. `bin/pig` calls neither: it
calls `Migrations::run()`, with no argument. So four mutations survived together, and each is a
different way for the migration to silently not happen:

| mutation | what it would do in production |
|---|---|
| delete `self::authToAuthJson()` from `run()` | credentials never move; pi's `auth.json` never appears |
| delete `self::sessionsFromAgentRoot()` from `run()` | pi 0.30.0's stray sessions stay invisible to both tools |
| delete either `$directory ??= Config::piHome()` | the default is never resolved — the only path production takes |

None of the four could fail a test, because nothing in the suite ever reached `run()` or let a
directory default. One case does all four: point `PI_HOME` at a temp directory, put an `oauth.json`
and a stray session in it, call `run()`, and check both halves happened. **The rule, which is this
document's "wired at one end only" pointed at a test file rather than at the code: a class whose
tests all pass the seam an argument has no coverage of its default, and the default is what ships.**

Three smaller ones, each a guard that could not fail against the fixture it had:

- **`recordedCwd()`'s "not a session" check is `SessionManager`'s, with the same weak fixture.**
  `!is_array($header) || type !== 'session'` → `&&` survived, because the existing case writes
  `{"type":"notes"}` — which has no `cwd`, so a weakened guard has nowhere to file the file and
  leaves it alone by accident. The shape that moves is another tool's log with a `cwd` field in it,
  and then pig files somebody's unrelated `.jsonl` under `sessions/`. **Second time this exact pair
  of lines has been found under-tested for the same reason**; the sibling is four entries up.
- **An entry in pi's `oauth.json` that is not an object** was migrated when the `&&` became `||` —
  and `{"type": "oauth", ...$notAnArray}` writes an `auth.json` neither tool can read, from the only
  copy of those tokens.
- **`Auth::save()`'s write-failure throw never fired**, the third of these in one session. Its own
  docblock is the argument for the test: *"a token that did not stick is a sign-in that said it
  worked, and the next thing that happens is an authentication error nobody can connect to this."*

**And `antigravityClient()` was unpinned end to end** — every line of it, where its sibling
`googleClient()` was covered. That is the *"a rule present in one place and absent in its sibling"*
shape on a **test file**: the two methods are deliberately not one reader (different OAuth clients,
different scopes, and a Gemini CLI token is refused at Antigravity's endpoint), so the coverage has
to be two as well. Both now pin four things each — the environment wins, a variable exported with
nothing in it falls through to the settings (the `getenv()` trap), a setting that is present and
blank is not set, and **half a pair is refused rather than handed over with a gap in it**, which
otherwise sends somebody to a browser, takes their consent, and fails at Google's token endpoint
with nothing on screen naming the missing half.

`AuthTest::CLEARED` gained `ANTIGRAVITY_CLIENT_ID` and `_SECRET` in the same pass: without them, a
machine with that pair exported would have `testAFlowWithNoClientCredentialsIsRefusedBeforeAUrlIsShown`
open a browser and fail for a reason that has nothing to do with the code — the same "passes on what
the machine happens to have" as the missing-tool tests two entries up.

**Three things in `Auth` are not testable, the developer was asked, and the answer was to leave them
that way.** `login()`'s `setCredentials()` — the line that makes a sign-in persist at all —
`fresh()`'s store of a **rotated** refresh token, and the Copilot block that switches on the models
an account has not accepted: all three sit behind `new Anthropic()` / `new GithubCopilot()`
constructed inside `Auth` with no endpoint to point elsewhere. Every flow class *has* that seam
(`Anthropic`'s docblock says it is there "so a test can point it at a loopback server"); `Auth` is
the one caller that does not use it. Upstream is built the same way, so this is not a port gap — it
is new public surface, which made it the developer's call, and the call was **no**: a factory
argument on `Auth`'s constructor is machinery for three tests and nothing else asks for it.

So this paragraph is the record, and it is not an open question — **do not re-propose the seam.**
What would reopen it is a reproduced failure rather than the coverage, because the consequences are
the worst in the file: a sign-in that reports success and stores nothing, a rotated token thrown
away so the next turn is signed out, and a third of Copilot's model list failing on its first turn.
If one of those three is ever traced to a real bug, that is the day the seam is worth its keep, and
this is the entry to start from. `test/live.php` is the other route: it already reaches real
endpoints with real credentials, so a scenario that signs in and reads the file back would cover all
three without any new surface at all.

## Pinning a port to the original's arithmetic

`Fuzzy` is scored, and a scoring function is the one kind of port that can be wrong in a way no
ordinary test notices: every "the better match ranks first" assertion still passes while the
picker quietly feels different to type into.

So the numbers were taken from the original — `node --experimental-strip-types` over pi's own
`fuzzy.ts`, 24 pairs, scores printed — and compared against this one. All 24 matched exactly,
multibyte and digit-swap cases included, and they are now a data provider in `FuzzyTest`
(`testTheScoreIsTheOneUpstreamGives`). If one moves, the scoring changed.

Two deliberate deviations, both narrow:

- **Characters, not bytes.** The original counts UTF-16 units; `strpos()` looking for one byte of a
  three-byte character finds the middle of the character before it, and the index it returns then
  poisons every gap and boundary sum after it. `mb_str_split` and a character-index `indexOf`.
- **`[a-z]` and not `\p{L}`** in the digit-swap patterns, which *is* the original: the query is
  already lowercased, and a script whose letters are not these has no digit-order convention this
  would be fixing.

`usort()` is relied on being stable, as the original relies on `Array.prototype.sort` being
stable: rows that score alike keep the order they were handed in, which for the model picker is
the current model first and the default one second.

## A test that could not fail, and the experiment that found the case

`SelectItem::$searchText` carries upstream's `getModelSelectorSearchText` — provider, then
`provider/id`, then provider again, and the bare id *last*, so that `openai/gpt-5` ranks OpenAI's
own above a reseller's `openrouter/openai/gpt-5`.

Wiring it into the picker survived every mutation at first. The fallback haystack is the label and
the description, which between them already contain the id, the provider *and* the name — so the
same rows matched either way and only their order differed. A test asserting the right row is
present cannot see that.

Rather than assert on the wiring, the ranking was measured: both haystacks built over the real
registry, ten provider-prefixed queries filtered through each, top row compared. **Nine of ten
flipped.** The clearest is the one upstream's docblock describes: `openai/gpt` gives `openai/gpt-4.1`
with the search line and groq's `openai/gpt-oss-120b` without it, because that row's *id* contains
the provider the query names. That is now the regression test, and it fails on the mutation with
`groq · GPT OSS 120B` in the message.

The lesson is the general one: when a mutation survives, the missing test is usually a *comparison*
the assertions never make, and the way to find it is to run the two versions side by side over real
data rather than to reason about which case might differ.

## `phpunit | tail` hung after the whole suite had passed

**Symptom**: the full suite ran to the end, and the command never returned; with output to a file
instead of a pipe it finished.

**Cause**: `InteractiveModeTest::testSlashWebStatusAndRestartCommand` types `/web restart`, which
starts a real `pig web start -d` daemon in the test's home, and nothing stopped it: the daemon held
the pipe `tail` waits on, and port 8088.

**Rule**: a test that starts a daemon stops it in a `finally` (`/web stop`); after a run,
`ps -eo args | grep "[m]ode web"` shows nothing.

## The test suite wrote into the developer's real home

**Symptom**: the web sidebar listed sessions titled "hi" whose folder was `/var/folders/…/T`, and
test `.jsonl` files turned up in the Trash.

**Cause**: `SessionManager::create($cwd)` names the working directory; the file goes under
`Config::home()`. Four tests never pointed that home anywhere, and tests that do clear
`PIG_CODING_AGENT_DIR` in `tearDown()`, so the next one falls back to `$HOME/.pig/agent`. Web tests
wrote `nodes.json` the same way, and the session-delete tests ran the real `trash`.

**Rule**: `test/bootstrap.php` gives the whole run a throwaway `HOME` (removed at exit), unsets the
agent-dir variables, puts a deleting `trash` stand-in first on the `PATH` (macOS's `trash` and Finder
ignore `$HOME`), and appends the real `~/.pig/agent/bin` so downloaded `fd`/`rg` are still found. A
test still isolates what it asserts on; the bootstrap is the floor under the ones that forget. Check:
run the suite with `HOME` pointed at an empty directory and find nothing in it afterwards.

## MCP startup notice test asserted before asynchronous servers settled

**Symptom**: `McpExtensionTest::testTheExtensionConnectsAtSessionStartAndTheToolsReachTheAgent`
intermittently failed in CI (e.g. PHP 8.5) with `Failed asserting that '' contains "MCP servers need attention"`.

**Cause**: Session startup (`emitBeforeAgentStart`) only waits for servers declaring direct tools
(`waitForDirectServers()`); servers with codemode exposure (like the fixture's `broken` server)
connect concurrently in a background coroutine. If the direct server finishes first, `Async::run()`
returns before the background coroutine finishes and calls `$reportProblems($ctx)`.

**Rule**: Tests asserting on UI notices or state from background MCP connections must wait for the
background operations to settle (`$this->until(...)` or `$this->untilServersSettle()`) instead of
assuming all servers settled during `before_agent_start`.

## Headless Chrome launch timeout was too tight for CI runner contention

**Symptom**: `WebSearchExtensionTest::testAPageBuiltByJavascriptIsReadAfterItHasRun` threw
`RuntimeException: Chrome did not become ready within 15s.` intermittently on GitHub Actions
runners under CPU load (e.g. PHP 8.3 job while other PHP jobs passed in 13s).

**Cause**: `HeadlessBrowser::LAUNCH_TIMEOUT` was hardcoded to 15.0s. Cold-starting Chrome with a
new user data profile on a shared 2-vCPU CI runner takes 10–13 seconds normally, leaving no margin
when runner CPU throttles. Additionally, `awaitDevToolsLine` discarded stdout chunks, so if Chrome
output DevTools ws URL or diagnostic errors to stdout, they were lost.

**Rule**: Subprocess browser launch timeout is 30.0s, draining both stdout and stderr into `$seen`
and reporting trailing seen bytes if the launch fails or times out.

## `uncapturedClosureReads()` falsely flagged static class property accesses

**Symptom**: `php test/lint.php` flagged `self::$pending` and `self::$cache` inside a closure in `Dns.php` as uncaptured closure reads (`$pending is read inside a closure that neither takes it as a parameter...`).

**Cause**: `test/lint.php`'s closure static analysis swept for `T_VARIABLE` tokens inside closure bodies, but did not check if the preceding non-ignorable token was `T_DOUBLE_COLON` (`::`), mistaking static class property accesses (`self::$prop`, `Class::$prop`) for uncaptured local variables.

**Rule**: Static property accesses preceded by `T_DOUBLE_COLON` belong to class scope, not closure variable scope; static analysis must skip them when checking uncaptured closure variables.



