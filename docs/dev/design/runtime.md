# Design: PHP and the async runtime

Fiber, Loop, Future, sockets, and where PHP is not JavaScript. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [Porting the unions](#porting-the-unions)

## Porting the unions

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

## HTTP/2: one connection, many requests

`Http\HttpClient::send()` offers `h2` and `http/1.1` in every TLS handshake — direct, or inside a
proxy's tunnel (`Proxy::open()` takes the list). `Socket::alpnProtocol()` says what the server chose:
`h2` becomes an `Http2\Http2Connection` held in `Http2Pool` by origin (and by proxy, since a
connection is the host's *through* that proxy). Anything else is HTTP/1.1, and a connection whose
body was read to its end (`Http1Body`'s `keep`) waits in `Http1Pool` for the next request to the
origin — four seconds, undici's `keepAliveTimeout`, or the server's `Keep-Alive: timeout` less one.
Nothing watches it there; `acquire()` reads what is on it, and anything (EOF or a byte) discards it.
Only a body with a known end is kept (chunked or `Content-Length`; `204`, `304`, `HEAD` and `1xx`
have none), never an HTTP/1.0 answer or one that said `Connection: close`. A kept connection that
fails at the first write or head is retried on a new one for `GET`/`HEAD`/`OPTIONS` only, as undici
does; a `POST` may have been acted on. There is no switch: the server's answer in the handshake is the
choice, as in Node. Plain `http` is never offered `h2`. `Http\ResponseBody` is the abstract class both `Http1Body` and
`Http2\Http2Body` extend, so whoever has a `Response` does not know which it holds.

- **Nothing reads in the background.** There is no reader coroutine and no standing watcher: whoever
  is waiting for something — a head, the next piece of a body, room in a send window — reads the
  socket itself (`Http2Connection::wait()`), and the others wait to be told something arrived and
  re-check what they were waiting for. The reader hands the socket on in a `finally`. So a pooled
  connection costs nothing between requests, a coroutine that dies mid-read leaks nothing across
  `Async::run()` calls, and a test needs no teardown beyond `Http2Pool::clear()`. What arrived while
  the connection sat idle (PING, GOAWAY, the server closing) is read before it is used again
  (`isUsable()` → `drain()`).
- **One write at a time.** `Socket::write()` suspends on a full kernel buffer, and two coroutines in
  it would interleave their frames. Every write goes through `locked()`, a frame at a time, never
  the whole body. `Socket` keeps one settle per await in flight (`pendingSettles`), because a reader
  and a writer wait on the same socket.
- **Stream ids go out in order**, so one is taken only inside the lock that writes its HEADERS.
  `HpackEncoder` keeps no dynamic table, which is what lets header blocks go out in any order and
  makes the peer's `SETTINGS_HEADER_TABLE_SIZE` irrelevant. `HpackDecoder` is the opposite: every
  header block is decoded, even one for a stream nobody wants any more, or the table of every later
  block is wrong.
- **Windows, and the hello.** A connection opens as Node's `fetch` opens one, read off the wire from Node 26
  (capture a client's frames with `Http2Server` and `NODE_EXTRA_CA_CERTS`): SETTINGS `ENABLE_PUSH=0`,
  `INITIAL_WINDOW_SIZE=262144`, then the connection's window made 512 KiB, and pseudo-headers in the order
  `:authority :method :path :scheme` — so a server that tells clients apart by their hello sees the same one.
  A window is a bandwidth–delay product, though: at 256 KiB a 10 MB download took 15 s where curl took 2.4 s.
  So a stream that has delivered a whole opening window (a download; a model's answer is far less) is raised
  to 8 MiB, and the connection with it, in later WINDOW_UPDATEs that nothing compares. Windows are given back
  as the body is read (`Http2Body`), not as it arrives. What is sent is held to the server's windows, spent
  before the write suspends so that two requests cannot spend them twice. What still differs from Node is
  the request's own headers: `fetch` adds `accept`, `accept-language`, `sec-fetch-mode`, `user-agent` and an
  `accept-encoding` of `br, gzip, deflate, zstd`; pig sends what the caller gave it and `identity`, since nothing
  here decompresses.
- **A timeout or an abort is one stream's.** The idle timeout runs from the start of the wait, not from
  the last traffic on the connection, so a stream is not kept alive by its neighbours; a stream that
  times out or is abandoned is cancelled (`RST_STREAM`, `CANCEL`) and the connection goes on. Only a
  closed socket, a protocol error, or a failed write ends it for everyone, with a message that starts
  `Connection closed` — which is what the session's retry reads.

Checked against: RFC 7541's Appendix C examples (`HpackTest`), a scripted loopback server
(`test/Http2Server.php`, `Http2ConnectionTest`), loopback servers over TLS whose certificate the client
is told to trust (`HttpClientTlsTest`: `h2` chosen and shared, a server without it getting HTTP/1.1 and a kept
connection, an untrusted certificate refused), and live — Cloudflare, Google and a CDN, direct and through
both proxy kinds, and Bedrock in Singapore (a tool turn, two requests on one connection).

**Trusting a certificate of your own** is `NODE_EXTRA_CA_CERTS` (`bin/pig` → `Socket::useExtraCaFile()`).
`SSL_CERT_FILE` does nothing here: PHP puts `openssl.cafile` before it, and a `cafile` in the stream context
replaces the system's certificates rather than adding to them, which is why `TlsRoots` writes a bundle of both.

**Open follow-ups for HTTP/2 and connection reuse** (none blocks a release; each is something not yet seen working):

- A real multi-turn conversation with tool calls and a long streamed answer against Anthropic, OpenAI, Google and Antigravity. Only Bedrock (`ap-southeast-1`) has been run; `eu-central-1` only as far as negotiating `h2`.
- A connection left idle for hours: the server's `GOAWAY`, `PING`, a close while idle, then the next request. Covered only by the scripted server.
- The idle timeout (`httpIdleTimeoutMs`, 300 s) on an HTTP/2 stream with other streams active.
- Windows: `Socket::readAvailable()` on an idle pooled connection, and the loop's polling, have not been tried there.
- Many coroutines at once on one connection (sub-agents): tested with 3 to 6, not under load.
- A kept HTTP/1.1 connection that fails on the first write is retried for `GET`/`HEAD`/`OPTIONS` only; a `POST` is left to the session's retry. Revisit if "Connection closed" shows up often on a first request after a pause.
- The 8 MiB window a download is raised to was picked from one link; other links may want another. A real run against Google (Antigravity) to see whether the Node-like hello changes anything.

