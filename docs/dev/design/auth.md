# Design: Signing in

OAuth flows, API keys, `auth.json`. Moved out of `CLAUDE.md` as written — "this file" in the entries below means `CLAUDE.md`, where they were first written. The index is generated from the headings below.

- [Signing in instead of pasting a key](#signing-in-instead-of-pasting-a-key)
- [The device flow](#the-device-flow)
- [The loopback the browser comes back to](#the-loopback-the-browser-comes-back-to)
- [Google's sign-in](#googles-sign-in)
- [Where the keys live](#where-the-keys-live)

## Signing in instead of pasting a key

`Ai\Utils\Oauth\` is upstream's `ai/src/utils/oauth/`, and **all four flows are ported now**.
They arrived in that order and the order is the story: Anthropic's, which is what a Claude Pro or
Max subscription is used through, and GitHub Copilot's went first because neither needed anything
pig did not already have. The two Google ones waited on a piece that did not exist — they are
loopback PKCE flows that want an HTTP server on `127.0.0.1` and a browser opened at it, so
`CallbackServer` had to be written before either could work.

Anthropic's is **pi 1.0.3's**, not the anchor's — the one flow updated past the anchor on purpose,
because the anchor's endpoints had moved under it (`console.anthropic.com`'s callback page is a 301
to `platform.claude.com`, measured) and its three scopes are half of what a token now carries.
`/login` asks which way in first, as pi does: **browser**, where `CallbackServer` listens on
`localhost:53692/callback` and the paste box is open beside it for a browser on another machine,
whichever answers first winning and the other closed (`Anthropic::firstOf()`, the race
`McpOauth` already runs); or **copy code**, Anthropic's own page showing a `code#state` to bring
back. Copilot's is a **device flow**: pig asks GitHub for a pair of codes, shows one, and then asks
over and over whether it has been typed in yet.

The far end is `Providers\ClaudeCode`: an `sk-ant-oat` token goes out as a bearer token under
**both** betas (`claude-code-20250219,oauth-2025-04-20`), `user-agent: claude-cli/2.1.280`,
`x-app: cli`, Claude Code's identity as the first system block — and **its tools spelled Claude
Code's way**, `read` → `Read`, `bash` → `Bash`, on the declaration, on the calls already in the
history, and mapped back to the name this request declared on the call that comes back. Upstream
calls that "stealth mode" and has carried it since January 2026, a week after the anchor; a token
Anthropic issued to Claude Code is checked against what Claude Code's requests look like, and the
anchor's half of it was the half that still passed.

Five things worth keeping straight:

- **`state` is the verifier**, not a second random string. Upstream's choice and Anthropic's
  flow: the callback hands back `code` and `state`, so the state travels home — through the
  socket or the clipboard — and the exchange can tell the code came from the request it made.
  **A state that is not the verifier is refused**, on both paths; upstream throws "OAuth state
  mismatch" there too.
- **A paste is read in every shape pi reads it** — the whole redirect URL, `code=…&state=…`,
  `code#state`, a bare code — and **half a paste is refused before anything is sent.** The
  copy-code page always hands over both halves, so a bare code there is a paste that lost its
  end, and saying that is an answer somebody can act on where `invalid_grant` is not. The paste
  is trimmed for the same reason: a code copied out of a terminal arrives with a newline on it
  about half the time.
- **The new refresh token replaces the old one.** Anthropic rotates them, so a session that
  kept sending the one it signed in with would work exactly once more.
- **An expired token read outside a coroutine is answered as it is, and the decision is made
  before the refresh is tried.** `fresh()` used to attempt the renewal and catch `Future::await()`'s
  "inside a coroutine" error — but the refresh *connects* before it first awaits, so offline or
  without DNS the socket error came first and `restoreSettings()` crashed startup with an expired
  token, the one case the catch existed for. `\Fiber::getCurrent() === null` is checked up front
  now; the first turn renews inside the loop.
- **Five minutes is taken off every expiry**, which is upstream's margin. A token that expires
  in flight fails the request it was attached to rather than the next one, and that request is
  a whole turn.
- **`available()` is false for the three that are not ported**, and they are still named.
  Both halves matter: the credentials file is keyed by these strings and shared with pi, so a
  name pig cannot sign in with is a name pig still has to *read* — and offering a sign-in that
  cannot finish is the same mistake as offering a model whose protocol is not ported.

`Pkce` is upstream's `pkce.ts` with two differences, both because PHP has what JavaScript had to
reach for: it is not asynchronous (`hash()` returns a string where Web Crypto's `digest()`
returns a promise), and `random_bytes()` *throws* when it cannot be a CSPRNG, where
`crypto.getRandomValues` has no failure to report. A machine with no entropy source stops the
sign-in rather than continuing with something guessable.

The client id is written out rather than hidden behind `atob()` of a base64 string as upstream
has it. It is in a published npm package and in every request this makes, so it is not a secret
— and a reader who cannot see what it is has to go and decode it to find out.

The token endpoint is a constructor parameter so the flow can be tested against a loopback
server. That is not a seam existing for the test alone, which this project otherwise refuses:
every provider in `ai` takes its base URL from outside already, and the alternative here is an
authentication flow with no coverage at all.

## The device flow

`Oauth\GithubCopilot` is upstream's `github-copilot.ts`. It ends with a swap that is easy to
miss: what the device flow produces is a **GitHub** token, and Copilot's API does not accept one.
`refresh()` trades it at `copilot_internal/v2/token` for a short-lived Copilot token — so the
GitHub token is stored as the `refresh` half and the Copilot token as the `access` half, which is
exactly what those two fields mean everywhere else here, and it is also why there is no separate
renewal endpoint: renewing *is* the swap, done again.

Four things about it:

- **The polling can be stopped.** The one addition to upstream, and the reason this flow waited
  for a decision: upstream loops for fifteen minutes with nothing able to interrupt it, which in
  pig is a fiber parked where nobody can reach it — the failure the hook dialogs already have a
  rule about. An `AbortSignal` ends it, escape raises one through `interrupt()`, and a
  cancellation comes back as **null rather than an exception**, because somebody changing their
  mind is an outcome.
- **Where Copilot answers is in the token.** Its claims carry
  `proxy-ep=proxy.individual.githubcopilot.com`, which becomes `api.individual.…`; a business
  account says something else and an enterprise install answers at `copilot-api.<domain>`. So
  the `api.individual…` in `Ai\Models` is a default and never the answer once there is a token —
  `Providers\OpenAiCompletions`, `OpenAiResponses` and `Anthropic` (Copilot's Claude models, with
  the token as a bearer) all ask `GithubCopilot::baseUrl()` instead, which is where the token and the request meet. Upstream rewrites it in its model
  registry, which pig has no equivalent of because pig's registry knows nothing about
  credentials.
- **Some models are off until the account accepts them**, Claude's and Grok's among them, so
  `enableModels()` POSTs a policy for each one after signing in. A refusal for one model is
  reported and does not stop the rest — the question asked was "may this account use that
  model", and no is an answer rather than a failure of a sign-in that is already finished. It is
  the one swallowed exception in this module and the docblock says why. The ids come from
  `Ai\Models` rather than from the flow: which models exist is the registry's question and the
  flow should not have a second answer to it.
- **`authorization_pending` is not an error and everything else is.** `slow_down` adds five
  seconds to the interval, as upstream does; anything else — `access_denied`, `expired_token` —
  ends the wait at once, because asking again says the same thing and a quarter of an hour spent
  on an answer that has already arrived is the wrong behaviour.

Something typed at the enterprise prompt that is not a host is **refused**, as upstream refuses
it: carrying on against github.com would sign somebody in to the wrong GitHub and look like it
had worked. Blank means github.com, which is what `allowEmpty` on the prompt is for.

## The loopback the browser comes back to

Google's flow has no device code and no paste: it redirects to
`http://localhost:8085/oauth2callback?code=…&state=…`, so something on this machine has to be
listening. Upstream reaches for Node's `http.createServer`; PHP has no HTTP server, so
`Oauth\CallbackServer` is one — deliberately the narrowest one that answers the question. One
path, three query parameters, one page written back, stop.

Five things about it:

- **The port is not negotiable.** `8085` is part of the redirect URI registered with Google, so
  a flow that picked a free port instead would be refused by Google rather than by the socket.
  `listen()` is therefore a separate step from `await()`: a port that cannot be taken has to be
  reported *before* anybody is sent to a browser, and it is reported by name.
- **`listen()` first, then open the browser.** The order matters and the split is what allows
  it — a browser that arrives before the socket exists gets a connection refused, and the
  sign-in is over with nothing to show for it.
- **It is on the loop.** `await()` parks the fiber on a `Deferred` while the loop keeps serving
  the terminal. A blocking accept would stop the UI for as long as the person took to sign in.
- **A browser opens more than one connection.** The callback, and usually a favicon request
  behind it. Each is read separately and only the one asking for the callback path answers the
  question; everything else gets a 404.
- **Cancel on Google's screen is a refusal, not a cancellation.** An `error=` in the callback
  throws, where escape returns null — something was said and it was no, which is different from
  walking away.

`stream_socket_server()` both warns and returns false, so the warning is caught with a handler
rather than suppressed: it is the only place the reason appears, and `@` is not allowed here.

`InteractiveMode`'s `onAuth` now **opens the browser** and prints the URL as an OSC 8 hyperlink,
which is upstream's behaviour and helps the two flows that were already here. The link text is
the URL itself rather than upstream's "Click here to login": a terminal that does not understand
the sequence shows the label, and a label is not something anybody can copy. `Ansi::strip()`
already knew about OSC 8, so the line measures correctly. Opening is best effort — the URL is on
screen, so a headless box or one without `xdg-open` is one extra copy-and-paste rather than a
broken sign-in.

## Google's sign-in

`Oauth\GeminiCli` is upstream's `google-gemini-cli.ts`, and the third shape of sign-in in three
flows: Anthropic's shows a URL and takes a paste, Copilot's shows a code and polls, and this one
**redirects to this machine** — `CallbackServer` listens, the browser goes to Google, and the code
arrives on a socket rather than through a person.

It is also the only one that does not finish when the tokens arrive. A Code Assist token is
useless without a Cloud project to spend it against and most accounts have none, so `project()`
asks for one and provisions a free-tier one when there is none — an API call that answers "not
yet" and has to be asked again. That is what `projectId` on `Credentials` is for, and why
`Provider::apiKey()` encodes it alongside the token: an api key field carries one string, and
Code Assist needs two things.

Six things worth keeping straight:

- **The state is checked and a mismatch is refused.** It is the verifier, so a code that came back
  with a different state came from a request this process never made. Upstream calls it a possible
  CSRF attack; this is the one check in the module that is about somebody else rather than about a
  mistake, and it has a test that drives a real socket to reach it.
- **`listen()` before the browser is sent anywhere**, which is the whole reason `CallbackServer`
  is two steps.
- **There has to be a refresh token**, and its absence gets its own complaint rather than being
  folded into "no tokens". Google only sends one for `access_type=offline` with `prompt=consent`,
  both of which are in the URL — so a sign-in that came back without one is worth saying plainly,
  because the fix is to try again.
- **A half-built project is not a finished one.** `onboardUser` answers with an id while it is
  still working, so `done` is checked as well: taking that id would name something nothing can be
  spent against.
- **Provisioning can be given up on**, which upstream cannot. Ten attempts three seconds apart is
  half a minute of a fiber nobody can reach.
- **The email is optional and its failure is ignored**, which is upstream's rule and the only
  place in the module where swallowing an error is right: it is a label on the credential and
  nothing downstream reads it.

**Google's client id and secret are not in this repository**, which is the one real difference
from upstream. They are constructor arguments, and `Auth::googleClient()` finds them — the
environment (`GEMINI_CLI_CLIENT_ID`, `GEMINI_CLI_CLIENT_SECRET`) and then the settings file
(`geminiCli.clientId`, `geminiCli.clientSecret`), which is pig's usual order. A flow built without
them is refused **in the constructor**, not at the first request: a flow that sends somebody to a
browser and then fails is worse than one that never starts, and the caller is what knows where to
look. Two pushes were refused before this was the answer — see the trap below, which is about what
scanners do rather than about what is secret.

Both READMEs say so now, because `/login` offers Gemini CLI and a reader who picks it needs to
know that two values have to come from somewhere else — and that port 8085 has to be free.

`available()` is true for it now. It was false for two turns while the protocol and the models
were missing, because a sign-in that unlocks nothing to choose is the same mistake as a model
whose protocol is not ported, from the other end.

`Providers\GoogleGeminiCli` is the protocol, and three things are its own:

- **The request is wrapped**: `{project, model, request: {…a Gemini request…}, userAgent,
  requestId}`, posted to `v1internal:streamGenerateContent?alt=sse`.
- **The answer is wrapped too**, one key deeper, so it unwraps and hands `GoogleShared::onChunk()`
  the same thing `Google` hands it. A chunk with no `response` in it is skipped rather than read as
  an empty candidate.
- **Thinking is only mentioned when it was asked for.** The one place the body differs from the
  public endpoint's: there, a turn that wants none has to say `thinkingBudget: 0`; here a
  `thinkingConfig` on a model that cannot think is rejected.

And the key is two things in one string — `Provider::apiKey()` encodes `{token, projectId}` —
which is taken apart in `credentials()`. **An ordinary Gemini API key arriving there is refused by
name**, because that is the likely mistake and a 401 three layers later names nothing.

**Retries are upstream's two layers.** Inside the provider, `retryProviderRequest()` retries the
initial request when the caller asks (`maxRetries`, from `retry.provider.maxRetries`); around the
turn, the session retries a failure whose text matches upstream's retryable patterns. Neither reads a
stated wait out of the message: a `retry-after` header is honoured by the provider layer (refused
past `maxRetryDelayMs`), and a quota wall is the provider's to word so that the session does not
retry it — pi-antigravity's `Quota reached. Please wait …`.

## Where the keys live

`Auth` is upstream's `core/auth-storage.ts`, named after `Settings` rather than after upstream's
`AuthStorage` — both are one JSON file under the home directory with a typed reader over it, and
the developer chose to keep those two matching rather than `SessionManager`'s kept suffix. One
entry per provider, in upstream's own shape, either `{"type":"api_key","key":…}` or
`{"type":"oauth","refresh":…,"access":…,"expires":…}`.

**It is pi's file, not a copy of it.** `Auth::discover()` opens `~/.pi/agent/auth.json` when
that exists and only falls back to `~/.pig/agent/auth.json`. That is not the same decision as reading
pi's sessions, and the reason is specific: Anthropic **rotates** refresh tokens, so the old one
is void the moment a new one is issued. Two files holding the same token is two tools taking it
in turns to log each other out — whichever refreshes first wins, and the other has to sign in
again. One file is the only arrangement where signing in once means signing in once. `Config`'s
docblock says so too, because it used to claim pig never writes in there.

Six things about it:

- **A renewal is taken under the file's lock, and the file is read again once it is held.**
  Anthropic rotates refresh tokens, so two pigs renewing one expired token at the same instant —
  two terminals open on one account is all it takes — both send the same refresh token, the
  provider rotates it on the first and refuses the second, and that pig is signed out. Upstream's
  `refreshStoredOAuthCredential()` is double-checked locking over `proper-lockfile`;
  `Auth::fresh()` is the same over `Pig\CodingAgent\FileLock`, which is `flock()` on
  `auth.json.lock`: in PHP core, and released by the kernel when the holder dies, so there is no
  stale lock to detect and no `onCompromised`. The wait polls `LOCK_NB` every 100ms through
  `Async::delay()` so the loop keeps serving the keyboard, and gives up after 25s (upstream's
  `REFRESH_LOCK_WAIT_MS`) with the file named. The MCP extension's refresh takes the same lock, per
  server. Regression tests: `FileLockTest` (a second `php` holding the file),
  `AuthTest::testARenewalWaitsForAnotherProcessAndUsesTheTokenItWrote`,
  `testARenewalNobodyElseIsDoingGoesAheadUnderTheLock`.
- **The order is upstream's**: what was typed, then a stored API key, then a stored OAuth token,
  then the environment. The last step is `Stream::envApiKey()` rather than a second table of
  variable names — it is upstream's `getEnvApiKey()` and it already knows the Anthropic order:
  `ANTHROPIC_AUTH_TOKEN` (sent as `Authorization: Bearer`), then `ANTHROPIC_OAUTH_TOKEN`, then
  `ANTHROPIC_API_KEY`.
- **A renewal that fails is reported, not cleaned up.** Upstream deletes the stored credential
  and carries on to the environment, so one blocked network call throws away a login that was
  perfectly good — and the person finds out by being asked to sign in again for no reason.
- **A failed *write* throws**, where `Settings` records a problem and carries on. A preference
  that did not stick is a preference somebody sets again; a token that did not stick is a
  sign-in that said it worked, and the next thing that happens is an authentication error
  nobody can connect back to this.
- **`0600` in a `0700` directory, and the mode is set before there is anything to read.**
  Upstream writes the file and then chmods it, which leaves it world-readable for as long as
  those two calls take — long enough.
- **A provider name neither tool knows is kept.** The file is shared, so an entry pig has no
  enum case for still has to survive being read and written back.

`getApiKey` on `AgentOptions` is what it plugs into — a closure asked **per turn** rather than a
string resolved once, which is what makes renewing an expiring token mid-conversation possible
at all. It was already there and had no caller; `CodingAgent::create()` gained a parameter to
pass it through.

`/login` and `/logout` are the same `SelectList` in the same place as `/model`, `/resume` and
`/tree`, rather than a port of upstream's `oauth-selector.ts` — a fourth picker that behaves
like the other three is worth more than a literal port of a component that does less. Two
differences from upstream, both saying more: a provider pig cannot sign in with is greyed
**and** says why when it is chosen, where upstream ignores the key and reads as broken; and
`/logout` lists only what is actually signed in, because offering the other three would be
three ways of doing nothing.

Signing in **spawns**, because the paste box parks the fiber it is asked on and the command is
running inside the loop's own input callback — suspending there suspends the loop that has to
deliver the keystrokes. Same reason `send()` and `startCompaction()` spawn.

**`--no-session` does not apply to credentials.** It means "do not write this conversation down";
a stored sign-in is still the way in, and a token renewed during the run has to be kept or the
next run begins by renewing one that was already replaced.

`setFallbackResolver` is here as `setCustomProviderKeys()`, narrowed: upstream takes a closure
and the one thing the closure ever did was look a provider up in a map, so pig takes the map.
The indirection bought a second place to look when a key does not resolve. It sits **after** the
stored answers and **before** `Stream::envApiKey()`, which is the only order that works —
`envApiKey()` has a fixed table of variable names and answers null for a provider it has never
heard of, which is every provider this is for.

`setRuntimeApiKey` has its caller now: **`--api-key <key>`**, for this run and this provider
only, never written to `auth.json`. Three things about it, the first two being differences from
upstream:

- **It is applied after the model is resolved**, because the provider it belongs to is that
  model's — and, as upstream, it is refused unless a model was named (`--model`, `--provider` with
  `--model`, or `--models`): `--api-key requires a model to be specified via …`. A key aimed at
  whatever the settings happen to default to is a key sent to the wrong provider.
- **An empty value is refused by name.** `api-key` had to go in `Arguments::TAKES_A_VALUE` — a
  key is exactly the kind of thing that does not start with a dash — and an option at the end
  of the line with nothing after it gets `''`. Storing that would put an empty string at the
  top of the precedence order, where it beats the environment and then fails as a missing key
  three layers away.
- It beats everything else, including a stored sign-in, because it is the most recent thing
  anybody said.

**`CodingAgent::apiKey()` is gone**, and this is the other half of the same story. It read the
environment itself, naming `ANTHROPIC_API_KEY` where `Stream::envApiKey()` prefers
`ANTHROPIC_OAUTH_TOKEN` — so for `CodingAgent::create()` callers with both variables set, which
one won depended on which door you came in by. Its `default => strtoupper($provider) .
'_API_KEY'` arm was worse: `github-copilot` came out as `GITHUB-COPILOT_API_KEY`, which is not
a name a shell can even export. `create()` now passes a null key straight through and `Stream`
is the only thing that reads the environment. `examples/agent.php` is the one caller affected,
and the change is that it gains upstream's precedence.
