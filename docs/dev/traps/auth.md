# Known traps: Signing in

OAuth flows, API keys, `auth.json`. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [A browser opens a connection it never uses, and closing it under its watcher killed the sign-in](#a-browser-opens-a-connection-it-never-uses-and-closing-it-under-its-watcher-killed-the-sign-in)
- [`parse_url()` is not `new URL()`, and a typed domain went through with a space in it](#parse_url-is-not-new-url-and-a-typed-domain-went-through-with-a-space-in-it)
- [A model with no API key could be switched to, listed, and resumed onto](#a-model-with-no-api-key-could-be-switched-to-listed-and-resumed-onto)
- [A credential a scanner knows cannot live in the repository, encoded or not](#a-credential-a-scanner-knows-cannot-live-in-the-repository-encoded-or-not)
- [One string had to move in three places at once](#one-string-had-to-move-in-three-places-at-once)
- [`auth.json` says `antigravity`, pig looked for `google-antigravity`](#authjson-says-antigravity-pig-looked-for-google-antigravity)
- [`Auth::fresh()` renewing an OAuth token outside a coroutine crashed in `Future::await()`](#authfresh-renewing-an-oauth-token-outside-a-coroutine-crashed-in-futureawait)

## A browser opens a connection it never uses, and closing it under its watcher killed the sign-in

The first real Claude browser sign-in ended with the tokens in hand and pig dead:
`pig crashed: Reader r505 watches a closed stream; cancel the watcher before closing it`. Chrome
opens a **speculative second connection** beside the callback — a preconnect — and never writes to
it, so its reader was still armed when the flow's `finally` called `CallbackServer::close()`, and
`close()` `fclose()`d every connection without cancelling the readers. The trap below about
closing a stream without cancelling its watcher, in a class whose `accept()` already knew the rule
for the *finished* path and not for the abandoned one. `drop()` cancels first now, `close()` goes
through `drop()`, and a reader that sees EOF drops its connection rather than staying armed on a
stream that will never be readable again.

Two things in the fix: the reader ids are kept in a map rather than captured by reference in the
closure, because the one place that needs them all is `close()`, which the closure cannot reach;
and the `(string)` on `array_keys()` is the hex-id trap — a numeric key comes back as an int.
Regression tests: `CallbackServerTest::testAConnectionThatNeverSaysAnythingDoesNotTakeTheLoopDownWhenTheServerCloses`
(red on the old `close()`, with the same message) and `testAConnectionTheBrowserHangsUpOnIsLetGo`.

## `parse_url()` is not `new URL()`, and a typed domain went through with a space in it

The divergence the Oauth sweep turned up, now closed. Upstream's `normalizeDomain` is
`new URL(trimmed.includes("://") ? trimmed : "https://" + trimmed).hostname` inside a `try`; pig's
was `parse_url('//' . $trimmed, PHP_URL_HOST)`. Run against each other over 34 inputs, they
**disagreed on thirteen**, and the answer was three separate things rather than one:

| | upstream | pig had |
|---|---|---|
| `COMPANY.GHE.COM` | `company.ghe.com` | `COMPANY.GHE.COM` |
| `a b`, `not a host!!` | null | accepted as a host |
| `a<b.com`, `a>b.com`, `a^b.com`, `a\|b.com` | null | accepted |
| `a%b.com`, `a%20b.com` | null | accepted |
| `a<TAB>b.com` | `ab.com` | `a_b.com` |
| `a\x01b.com`, `a\x7fb.com` | null | accepted |
| `münchen.de` | `xn--mnchen-3ya.de` | `münchen.de` |

WHATWG's host parser **lowercases**, **refuses the forbidden host code points**, and **punycodes a
non-ASCII domain**; `parse_url()` does none of the three. The first two are done now and the two
agree on 33 of the 34.

- **The space is the one anybody reaches.** A domain pasted with a word after it came back as a
  host, so the flow built `https://a b/login/device/code` and failed with pig's own
  `Cannot parse URL` — instead of `'…' is not a GitHub Enterprise domain.`, which is the sentence
  written for exactly this and the only one that tells somebody what to fix.
- **Lowercasing is not cosmetic here**: the typed spelling reaches `Credentials::enterpriseUrl` and
  `baseUrl()`, so the same domain typed two ways was stored and sent as two different strings.
- **Two removal steps have to happen before `parse_url()`, not after.** `parse_url()` hands a tab or
  a control character inside a host back as an **underscore**, so a check on the parsed host would
  be looking at `a_b.com` and find nothing wrong with it. WHATWG strips C0 controls and spaces off
  each end and removes every tab, newline and carriage return, and both run first.
- **The order is upstream's, and it is what makes two answers look arbitrary.** The scheme is
  prepended *first*: `ghe.com\x01` has its control stripped because it is still at the end of the
  string, and `\x01ghe.com` does not, because `https://` is now in front of it and the control is
  inside the host. Both answers are upstream's and both have a test.
- **`[` and `]` are forbidden host code points and are deliberately not refused**, because an IPv6
  literal comes back wrapped in them — `[::1]`, which is what upstream answers too.
- **`%` is refused outright.** WHATWG percent-decodes a domain before checking it, so `a%20b.com` is
  the space again and `a%b.com` is an invalid escape; both throw there. Refusing it without decoding
  matches both measured cases, which is the standard this file holds a pattern to.

**The one that remains is the IDN, and it cannot be closed here.** `idn_to_ascii()` is ext-intl,
which this project deliberately does not require — the whole of `Graphemes` and `Width` exists to
avoid it. So `münchen.de` is `xn--mnchen-3ya.de` upstream and passes through as typed here. Passing
through rather than refusing is the smaller divergence: it accepts everything upstream accepts, the
punycode spelling is what the DNS holds anyway and is taken as it is, and an IDN Enterprise host is
the one shape in that method nobody has ever reported. If it ever matters, the price is a required
extension and this paragraph is where to argue it.

Found in the same batch and worth its own line: **`OauthTest` had no `use PHPUnit\Framework\Attributes\DataProvider`**,
so the first data-provided case in it failed with `ArgumentCountError: Too few arguments`. The
missing-`use` trap's fourth guise, and the first in a test file — where it reads as a broken test
rather than as a missing import, because the attribute silently resolves to a class in the test's own
namespace and no provider is ever found.

Regression tests: `OauthTest::testAHostWithSomethingForbiddenInItIsNotOne` (ten rows, every one of
them null under `new URL` as well), `testATabInsideAHostIsRemovedRatherThanRenamingTheHost`,
`testAControlAtTheEndIsStrippedAndOneAtTheFrontIsNot`,
`testATypedDomainIsLowercasedTheWayTheUrlParserDoesIt`, `testAnIpv6LiteralKeepsItsBrackets`.

## A model with no API key could be switched to, listed, and resumed onto

Fifth find, and the pattern from the fourth one again with a different word in the middle: upstream
asks its model registry one question — *is there a key for this?* — in five places, and pig asked it
in none of them. Everything still worked, because `Stream` throws `No API key for provider: x` when
the turn goes out. That is the wrong place for it:

- **`AgentSession::setModel()`** switched to a model this machine cannot talk to, wrote a
  `model_change` into the session file recording the conversation as being on it, and failed on the
  *next* turn from inside `Stream` — where it reads as the provider's fault. Upstream's first two
  lines are the key check and the throw; pig's are now the same, before anything is applied.
- **`restoreSettings()`** restored such a model from the file, so `--continue` on an afternoon spent
  under a borrowed `--api-key` reopened onto a model whose every turn fails. Upstream's
  `restoreModelFromSession()` checks the key as well as the model and falls back on either.
- **Three listings** — `--list-models`, `/model`, and RPC's `get_available_models` — showed every model
  pig knows of. Upstream's word for all three is `getAvailable()`, and it means *there is a key*: a
  host drawing a menu from that list offered twenty models with nineteen of them broken, and
  `/model gemini` with no Google key switched to Gemini instead of saying there is no such model
  here.

`Auth::hasKeyFor()` and `Auth::availableModels()` are the one home for the question — `Auth` is
what pig has instead of upstream's `ModelRegistry`, and three lists filtered three ways is the
shape this whole audit keeps finding. `hasKeyFor()` deliberately does **not** ask `apiKey()`:
that renews an expiring OAuth token on the way past, which is right for a turn and wrong for a
list — drawing `/model` would refresh every signed-in provider, and one unreachable network
would empty a listing of twenty usable models. So an expired stored token counts as a key here,
where upstream (which does refresh) would drop the provider until the refresh succeeded. The
one-per-provider question is asked once, not once per model: twenty-one models share five
providers and the answer cannot change mid-list.

`setModel()` throwing is why `InteractiveMode::useModel()` now catches: the lists are filtered, so
what is left is a `models.json` provider whose key variable is empty and a sign-out in another
window between drawing the picker and choosing from it.

**Three tests were passing because the checks were missing**, which is the fourth time this audit
has found that: `CodingAgentSessionTest`'s resumed-thinking-level case restored a model it had no
key for, and two `RpcClientTest` cases switched to `anthropic/claude-3-5-haiku-latest` and expected
`claude-sonnet-4-5` in the model list from a home with no Anthropic key at all. And the
environment they run in was part of the problem: this container exports `GITHUB_TOKEN`, which is a
key for github-copilot and therefore nineteen models, so "no keys anywhere" quietly meant
"nineteen models". `Pig\Test\WithoutProviderKeys` takes all thirteen provider variables out for the
length of a test and puts them back — because `putenv()` is process-wide and `RpcClientTest`
spawns `bin/pig` with this process's environment on top of its own.

## A credential a scanner knows cannot live in the repository, encoded or not

`Oauth\GeminiCli` needs Google's client id and secret for the Gemini CLI. It carried them three
ways before it stopped carrying them at all, and each step is worth keeping:

1. **Written out**, with a comment arguing that neither is really secret — both ship in every
   Gemini CLI install and in a published npm package, and PKCE is what protects the exchange. All
   true. GitHub's push protection refused the push: it has patterns for exactly "Google OAuth
   Client ID" and "Google OAuth Client Secret".
2. **base64, as upstream's `atob()` wrappers have them.** Refused again, at the new line numbers:
   **the scanner decodes base64.** So upstream's encoding is not what gets past a scanner, and
   assuming it was is what cost the second push.
3. **Not in the repository.** They are handed to the constructor, and `CodingAgent\Auth`
   finds them: `GEMINI_CLI_CLIENT_ID` / `GEMINI_CLI_CLIENT_SECRET`, then `geminiCli.clientId` /
   `geminiCli.clientSecret` in the settings file — pig's usual order. Absent, `/login` refuses
   with a message naming both places and saying plainly that pig does not ship them.

Two rules out of it, and the second is the one that generalises:

- **Before writing any real credential into a file, assume the host scans for its provider's
  pattern — and that it decodes.** Obfuscating harder is an arms race against a control whose
  job is to protect that credential, and it fails on the day the scanner learns the trick.
- **A scanner that detects a credential also reports it to the issuer**, and Google revokes what
  is reported. So a plaintext copy in a public repository is a good way to break a sign-in for
  everyone using it, upstream included — which makes this a correctness argument and not only a
  policy one.

`Anthropic`'s and `GithubCopilot`'s client ids stay written out, because nothing objects to them:
one is a bare UUID, the other a GitHub OAuth *client* id, which is not a secret and is not what
GitHub looks for. The asymmetry is the scanners' and not a choice.

## One string had to move in three places at once

`auth.json` says `antigravity`. Making pig read it is not an alias — `Auth::apiKey()` does
`Provider::tryFrom($provider)`, so **the key in `auth.json`, the `Oauth\Provider` enum value and
the registry's provider name are all the same string**, and changing one without the others just
moves where the key goes missing. So all three moved together:

    Models::ANTIGRAVITY              'google-antigravity'  ->  'antigravity'
    Oauth\Provider::GoogleAntigravity ... = 'google-antigravity'  ->  Provider::Antigravity = 'antigravity'
    the registry's 7 stale rows       ->  the catalogue's 14, with thinkingLevelMap

No compatibility shim for the old name. It was upstream's before upstream deleted the provider,
nothing writes it any more, and a second accepted spelling is a second place to look when a key
does not resolve.

The antigravity models moved to `Api::Antigravity` at the same time, which made three things in
`GoogleGeminiCli` dead — `SANDBOX_ENDPOINT`, `SANDBOX_HEADERS` and `headersFor()`, the last of
which existed only to choose between two header sets for two providers sharing one class. They are
gone, and the two tests that covered them moved to `AntigravityTest` rather than being dropped:
the registry shape one, and **the `RESOLD` rule** — `claude-sonnet-4-6` is Anthropic's id and a
bare `--model sonnet` must not quietly go through Google. That rule did not change; only the id
and the provider name in its test did.

**What the thinkingLevelMap from the catalogue actually says**, now that it is wired:

    gemini-3.8-flash    low / medium / high
    gemini-3.1-pro      low / high
    gpt-oss-120b        medium
    the other eleven    high

Every row says `off => null`: **these models always think.** So `off` is not offered, which is
also why the routing tables still having an `off` target is not a contradiction — that is what to
send if something asks anyway. `minimal` and `xhigh` are null everywhere too, so the picker offers
three levels at most where it used to offer six, and `gemini-3.8-flash • medium` is now a thing pig
can say.

## `auth.json` says `antigravity`, pig looked for `google-antigravity`

The key in `auth.json` is `antigravity`, and it is the extension's `OauthFlow::id()` now — the
registry keys `Auth::signIn('antigravity')` by it, so the file and the provider name cannot drift
apart without a key going missing, which was the point of the rename. See "A provider an extension
brings" above.

## `Auth::fresh()` renewing an OAuth token outside a coroutine crashed in `Future::await()`

**Phenomenon**:
Resuming a session (`bin/pig -c` or `--resume`) whose last model used an OAuth provider (Antigravity, Anthropic OAuth) with a token that had just expired locally crashed on startup:
`Pig\Ai\Utils\Oauth\OauthError: Could not renew the antigravity token: Future::await() must be called inside a coroutine; wrap the entry point in Async::run()`.

**Cause**:
The bootstrap of `bin/pig -c` (`CodingAgent::session()`) runs before `Async::run()`, in plain synchronous code. `AgentSession::restoreSettings()` checked that the restored model had a usable key by calling `$this->keyFor($model)`, which reaches `$auth->apiKey()`; `Auth::apiKey()` saw the expired access token and called `$this->fresh()` to renew it over the network, whose `Socket::connect()` runs `Future::await()` — with no Fiber around it, that throws.

**Countermeasure**:
1. `Auth::fresh()` guards against the non-coroutine case: in a plain synchronous context (`Fiber::getCurrent() === null`) it never renews over the network, returning the credentials it has and leaving the renewal to the real turn inside `Async::run()`.
2. `AgentSession::restoreSettings()` pre-checks the model with `$this->auth->hasKeyFor($model->provider)`, which has no network side effect — no crash, and no needless remote request at startup.
