# Serve pig as an MCP Server

pig can be the server side of the [Model Context Protocol](https://modelcontextprotocol.io) as well as the client: `--mode mcp` serves one conversation to another agent — Claude Code, Claude Desktop, Cursor, another pig — as a single tool, `ask`. The caller hands pig a question or a job; pig runs a turn with its own tools, hooks and extensions in its working directory, and hands the final answer back.

For connecting pig *to* MCP servers, see [Connect MCP Servers](/docs/latest/mcp).

---

## Start it

```bash
# Streamable HTTP (default): http://127.0.0.1:8089/mcp
pig --mode mcp

# Elsewhere on the machine, or on the LAN
pig --mode mcp --mcp-host 0.0.0.0 --mcp-port 9000

# stdio, for a host that spawns its servers itself
pig --mode mcp --mcp-stdio

# Over an existing conversation (`-c` for the most recent one)
pig --mode mcp --session <id>
```

Everything that applies to a terminal session applies here — `--model`, `--thinking`, `--tools`, `-e`, project trust, `settings.json`. The server is the same session with a different front.

| Option | Behavior |
|---|---|
| `--mcp-host <h>` | Address to listen on (default `127.0.0.1`) |
| `--mcp-port <n>` | Port to listen on (default `8089`) |
| `--mcp-stdio` | One JSON-RPC message per line on stdin/stdout instead of HTTP; the host closing stdin stops it |

`--mode mcp` takes no prompt on the command line: the questions arrive as tool calls.

---

## Register it with the other agent

Claude Code:

```bash
# HTTP
claude mcp add --transport http pig http://127.0.0.1:8089/mcp

# stdio — Claude Code starts pig itself, in the project directory it is run from
claude mcp add pig -- pig --mode mcp --mcp-stdio
```

Any other MCP client takes the same URL or the same command in its own configuration file.

---

## The `ask` tool

```json
{ "name": "ask", "arguments": { "prompt": "What does bin/pig do on startup?" } }
```

- **One conversation.** Every call is a turn in the same session, so a follow-up can refer to an earlier answer ("now fix the second one"). Start pig with `--session` to continue a conversation you already have, or `--no-session` for one that is not written down.
- **The answer is the final text**, as `pig -p` prints it: the last assistant message's text blocks, with thinking and tool calls left out. A turn that fails (quota, refused request) comes back as a result with `isError: true` and the reason as its text.
- **Calls queue.** A call made while a turn is running waits for it and then takes its own turn, in arrival order. Nothing is refused and nothing is dropped.
- **Progress while it runs.** A caller that sends `_meta.progressToken` gets `notifications/progress` with each piece of text as the model produces it. Over HTTP the stream also carries a comment line every 15 seconds so nothing in between decides it is idle.

---

## On the wire

Over HTTP this is Streamable HTTP (MCP 2025-03-26 and later) in its smallest reading:

- Every message is a `POST /mcp`. `initialize`, `ping` and `tools/list` are answered as one JSON document; `tools/call` as a `text/event-stream` that ends with the response and then closes.
- `initialize` issues an `Mcp-Session-Id`, which the client sends back on every request. An id the server does not know — after a restart, say — is answered `404`, which the specification defines as "initialize again", so a client that outlives pig recovers on its own. `DELETE /mcp` forgets the id.
- `GET /mcp` is `405`: pig has nothing to say that is not an answer to a request.

Over stdio there is no session id and no keep-alive, because a pipe needs neither.

---

## What it is not

- Not an exposure of pig's RPC commands or built-in tools. One tool, on purpose: the other agent gets a colleague to ask, not a second copy of the filesystem.
- Not authenticated. `--mcp-host 127.0.0.1` is the default for that reason; anything that can reach the port can run `ask`, and `ask` can run bash. Put it on a LAN or a public address only behind something that checks who is calling.
