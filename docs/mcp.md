# MCP Servers

pig connects to [Model Context Protocol](https://modelcontextprotocol.io) servers over stdio or streamable HTTP and makes their tools available to the model.

pig can also be the server: `pig --mode mcp` serves a conversation to another agent as one tool, `ask`. See [Serve pig as an MCP Server](/docs/latest/mcp-server).

## Configure servers

Add servers to `~/.pig/agent/mcp.json`, or to `.pig/mcp.json` in a project. The format matches other MCP clients, so existing `mcpServers` entries can be copied over:

```json
{
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "."]
    },
    "docs": {
      "url": "https://example.com/mcp",
      "headers": { "Authorization": "Bearer ${DOCS_TOKEN}" },
      "exposure": "direct"
    }
  }
}
```

- stdio servers take `command`, `args`, `env`, and `cwd`. Relative `cwd` resolves against the session directory. A leading `~/` in `command`, an argument, or `cwd` names the home directory.
- HTTP servers take `url`, `headers`, and `oauth` (see [Sign in with OAuth](#sign-in-with-oauth)). The legacy SSE transport is not supported.
- `env` and `headers` values can reference environment variables (`${NAME}`) or commands (`!command`), like provider API keys.
- `timeout` sets the per-request timeout in seconds (default 60). Progress notifications from the server reset it.
- `enabled: false` keeps an entry without connecting to it.

Project entries replace global entries with the same name. A project `mcp.json` is only read after the project is trusted, because stdio servers run commands.

`pig mcp add` and `pig mcp remove` edit the file from a shell (see [MCP commands](/docs/latest/cli#mcp-commands)):

```bash
pig mcp add filesystem -- npx -y @modelcontextprotocol/server-filesystem .
pig mcp add docs --url https://example.com/mcp --bearer-token-env-var DOCS_TOKEN --exposure direct
pig mcp add -l tools --env API_KEY='${TOOLS_KEY}' -- uvx tools-mcp
pig mcp remove docs
```

Rules that are easy to get wrong:

- Server names may only contain letters, digits, `_`, and `-`. Tools are named `mcp__<server>__<tool>`.
- `type` is optional: a `command` makes a stdio server and a `url` a streamable HTTP server. When present, it must be `stdio`, `http`, or `streamable-http`. `sse` is rejected; most servers that document an SSE endpoint also serve streamable HTTP, often at `/mcp` instead of `/sse`.
- `command` is a single executable and `args` its arguments, not one shell string.
- Keep secrets out of the file: use `${NAME}` for environment variables, as in `"Authorization": "Bearer ${GITHUB_TOKEN}"`, or `!command` to run a command. A command must make up the whole value, so it has to print the header value itself: `"Authorization": "!echo Bearer $(gh auth token)"`.
- Invalid entries are skipped and reported; the other servers still connect.

## Set up servers

When asked to add an MCP server, the agent should:

1. Add simple servers with `pig mcp add` (add `-l` for the project file), or edit `mcp.json` directly for settings the command does not cover. Put personal servers and servers with credentials in `~/.pig/agent/mcp.json`. Use the project `.pig/mcp.json` only for servers the project itself needs, and only in trusted projects.
2. Convert entries written for other clients:
   - Claude Desktop, Claude Code, and Cursor use the same `mcpServers` shape; copy the entry.
   - VS Code uses a top-level `servers` object and `inputs` prompts; move the entry under `mcpServers` and replace `${input:...}` with `${NAME}` environment variables.
   - Codex uses TOML (`[mcp_servers.<name>]` with `command`, `args`, `env`, or `url`); write the same fields as JSON.
   - opencode uses `"type": "local"` with `command` as an array (split it into `command` and `args`), `"type": "remote"` for URLs, `environment` for `env`, and `{env:NAME}` for `${NAME}`.
3. Run `pig mcp list` to check the entry. It connects to every enabled server and prints the state, the tools, and errors such as the stderr of a stdio server that failed to start. It exits with 1 while anything is wrong.
4. For a server that needs a sign-in, run `pig mcp login <server>`. It opens the authorization page in the user's browser and waits until the user approves access; tell the user to approve it. A running session uses the new credentials on its next turn.
5. Tell the user to run `/reload` (or start a new session) so the running session connects to added or changed servers.

pig connects when a session starts. The first prompt waits up to 10 seconds for startup connections; the tools of servers that take longer become available once they connect. HTTP connections that fail with a network error or a transient status (408, 429, 5xx) are retried twice. A server that drops its connection shows as disconnected and is reconnected on the next call. When a server announces that its tool list changed, new tools are added and withdrawn tools become unreachable until the server offers them again.

Config errors, servers that failed to connect, and servers that need a sign-in are reported once after startup.

Log messages servers send with MCP logging notifications are appended to `~/.pig/agent/mcp.log` as `<time> [<server>] <level> <logger>: <message>`. The file is moved to `mcp.log.1` when it grows past 5 MB.

## Manage servers

`/mcp` opens the server manager. It lists every configured server with its state, tool count, exposure, and whether it comes from the global or the project `mcp.json`; servers that need attention come first. Select a server to:

- sign in, for OAuth servers that need it (see [Sign in with OAuth](#sign-in-with-oauth))
- see its tools, its command or URL, and the full connection error, including the tail of a stdio server's stderr
- reconnect
- sign out, which deletes the stored OAuth credentials
- change its exposure (see [Exposure](#exposure))
- disable or enable it

Exposure changes and enabling or disabling are saved to the `mcp.json` that defines the server; other content of the file is kept. Disabled servers stay listed so they can be enabled again.

Outside the interactive TUI, `/mcp` prints the server status. `/mcp login <server>`, `/mcp logout <server>`, and `/mcp reconnect <server>` run those actions directly.

From a shell, `pig mcp add`, `pig mcp remove`, `pig mcp list`, `pig mcp login <server>`, and `pig mcp logout <server>` manage servers without a session (see [MCP commands](/docs/latest/cli#mcp-commands)).

Stopping a stdio server closes its stdin, then sends SIGTERM and finally SIGKILL to its whole process group, so servers started through wrappers such as `npx` or `uvx` do not linger.

## Sign in with OAuth

Remote servers that use OAuth, such as Sentry, need no credentials in `mcp.json`:

```json
{
  "mcpServers": {
    "sentry": { "url": "https://mcp.sentry.dev/mcp" }
  }
}
```

When such a server rejects the connection, `/mcp` shows it as needing sign-in. Select it and choose "Sign in" (or run `/mcp login sentry`, or `pig mcp login sentry` in a shell) to open the authorization page in your browser. After you approve access, the browser redirects to a temporary server on `127.0.0.1` and pig connects. If the browser runs on another machine, for example over SSH, paste the URL it was redirected to into the sign-in screen instead.

pig identifies itself to the authorization server with its Client ID Metadata Document or by registering a client, stores tokens in `~/.pig/agent/mcp-auth.json`, and refreshes access tokens automatically when they expire or the server rejects them. If the server later asks for more scope than was granted, it shows as needing sign-in again, and signing in requests the new scope. "Sign out" in `/mcp` (or `/mcp logout sentry`) deletes the stored credentials.

OAuth applies to HTTP servers without an `Authorization` header. For authorization servers that do not support dynamic client registration, configure a pre-registered client:

```json
{
  "mcpServers": {
    "example": {
      "url": "https://mcp.example.com/mcp",
      "oauth": { "clientId": "my-client", "clientSecret": "${EXAMPLE_SECRET}", "callbackPort": 8765 }
    }
  }
}
```

The redirect URI must match the one registered for the client. `callbackPort` fixes it to `http://127.0.0.1:<port>/callback`. For another redirect URI, set `callbackUrl`, for example `"callbackUrl": "http://localhost:8080/oauth/callback"`. It must be an `http` URI on `localhost`, `127.0.0.1`, or `[::1]`, and is sent exactly as written. Without a port in `callbackUrl`, pig listens on `callbackPort`, or on a free port, and adds it to the URI; authorization servers accept any port for loopback redirects (RFC 8252). `clientSecret` is optional and can reference environment variables or commands.

Without `clientId`, pig identifies itself with its Client ID Metadata Document on pigagent.dev when the authorization server advertises support for documents and public clients. Otherwise it registers a client dynamically. A client registered before stays in use while its redirect URI matches, until you sign out. With the document, the client ID is `https://pigagent.dev/oauth/client.json` with the redirect URI `http://127.0.0.1:<port>/callback`. If the authorization server does not send the `iss` parameter in authorization responses (RFC 9207), pig uses a document and redirect path specific to the MCP server instead: `https://pigagent.dev/oauth/<id>/client.json` with `http://127.0.0.1:<port>/callback/<id>`.

pig registers instead of using the document when `clientName` is set or `callbackUrl` is not `/callback` on `localhost` or `127.0.0.1`. Set `clientRegistration` to choose the method: `auto` (default) as described, `dcr` to always register, or `cimd` to always use the document. With `cimd`, sign-in fails if the authorization server does not support documents for public clients. Use `dcr` when the authorization page reports an invalid client:

```json
{
  "mcpServers": {
    "example": { "url": "https://mcp.example.com/mcp", "oauth": { "clientRegistration": "dcr" } }
  }
}
```

`clientName` is the `client_name` sent when registering (default `pig`), for servers that only accept known clients. `pig mcp add --oauth-client-registration <mode>` and `--oauth-client-name <name>` set them when adding a server. `cimd` cannot be combined with `clientId` or `clientName`, and a `callbackUrl` must use `localhost` or `127.0.0.1` with the path `/callback`.

`scope` sets the scopes to request, separated by spaces, for servers that do not advertise the ones they need. Without it, pig requests the scopes the server advertises. When a server later asks for more scope, pig requests those on top of `scope`.

## Exposure

Each server's tools are registered as `mcp__<server>__<tool>`. The `exposure` setting controls how the model reaches them:

- `codemode` (default): the tools are callable from [`codemode`](/docs/latest/cli#tools) scripts and listed in the `codemode` tool's description, but are not declared to the model. Large MCP tool lists stay out of the model's tool declarations, and scripts can call several MCP tools, in parallel if needed, while returning only the part of the result the model needs. pig activates the `codemode` tool when such a server connects. Large servers do not fill the description: declarations share a token budget, and scripts find the remaining tools with `search_tools()` (see [`codemode`](/docs/latest/cli#how-codemode-works)).
- `codemode-deferred`: like `codemode`, but the tools are not listed in the `codemode` tool's description either. Scripts call them by name and find them with `search_tools()`, `describe_namespace()`, or in `ALL_TOOLS`. Use it for large servers that codemode scripts use rarely.
- `deferred`: the tools are not declared to the model until the [`tool_search`](/docs/latest/cli#tools) tool loads them. The model searches, and the matches are declared from its next call on and called directly, without codemode. pig activates the `tool_search` tool when such a server connects. Use it for large servers without codemode.
- `direct`: the tools are declared to the model like built-in tools, and are also callable from codemode scripts while `codemode` is active.
- `hidden`: the tools are registered but cannot be called.

`toolExposure` sets the exposure of single tools and overrides `exposure` for them. Keys are tool names as the server offers them, or patterns where `*` matches any characters. An exact name wins over patterns; among patterns, the first match in the object wins. With `hidden` as the server's exposure, only the listed tools are reachable:

```json
{
  "mcpServers": {
    "github": {
      "url": "https://api.githubcopilot.com/mcp/",
      "exposure": "deferred",
      "toolExposure": {
        "search_code": "direct",
        "get_*": "codemode",
        "delete_*": "hidden"
      }
    }
  }
}
```

`pig mcp list` marks tools whose exposure differs from the server's, and the Tools view in `/mcp` shows it too.

In pig the two tools do not share their tools: codemode scripts reach `codemode` and `codemode-deferred` tools (and every tool the agent has registered, including `deferred` tools once `tool_search` has loaded them), while `tool_search` searches and loads only `deferred` tools. Unlike upstream pi, a script cannot call a `deferred` tool that has not been loaded, and `tool_search` cannot load a `codemode` tool.

`codemode` is always registered but inactive; a server with `codemode` or `codemode-deferred` tools turns it on when it connects, and it stays on. `tool_search` is registered while any `deferred` tool is listed and goes away again when nothing needs it. Tools called from codemode scripts do not depend on the active tool set, so they stay callable after `/tree`, resume, and fork. Tools loaded by `tool_search` are recorded in the transcript like any other tool change and stay declared on that branch. To have `codemode` active without MCP servers too, add `"+codemode"` to `defaultTools` in [settings](/docs/latest/settings#tools). To keep MCP tools away from codemode, give the servers `direct` or `deferred` exposure.

Text results over 20KB reach the model with the middle cut out, in the format Codex uses: the start and end of the text around a `…N chars truncated…` marker. The full text is saved to a temp file whose path the result names. Codemode scripts always receive the whole result, so a script can filter a large result down to what the model needs.

Codemode scripts receive an MCP tool's whole `CallToolResult` (`content` blocks as sent by the server, `structuredContent`, and `isError`), and the `codemode` description declares it as a `CallToolResult` array shape. A result with `isError` is returned to scripts, not thrown, and is reported to the model as an error for direct calls. `image($result['content'][0])` forwards an image block to the model. The server's `instructions` describe its tools in the `codemode` description.

## Resources

When a connected server offers [resources](https://modelcontextprotocol.io/specification/2025-11-25/server/resources), pig adds the resource tools Codex and opencode use:

- `list_mcp_resources` lists resources as JSON: `{ server?, resources: [{ server, uri, name, ... }], nextCursor? }`. With `server`, it lists one page of that server, and `cursor` continues with the next one. Without, it lists every resource of every server.
- `list_mcp_resource_templates` lists URI templates for resources the servers do not list, in the same way.
- `read_mcp_resource` reads a resource given `server` and `uri`. Text resources reach the model as text and images as images; other binary resources are saved to temp files, and the model sees the file path. Scripts receive `['server' => ..., 'uri' => ..., 'contents' => [...]]`.

The tools reach every enabled server with resources whose exposure is not `hidden`, and take the widest exposure among them: `direct` if one of the servers is direct, else `codemode`, else `codemode-deferred`, else `deferred`. Resource links in tool results name `read_mcp_resource` and the server.

Resources for MCP Apps (`ui://` URIs or `text/html;profile=mcp-app`) are left out of the listings, since pig does not render them, and so are resource icons.

Reading and listing resources is retried once after a transient HTTP error (408, 429, 5xx). Tool calls are not retried, since the server may have run them.

## Permissions

Every MCP call goes through pig's tool pipeline, so `tool_call` and `tool_result` extension handlers, including permission gates, apply to MCP tools. Calls made from codemode scripts carry the `codemode` call's id as `parentToolCallId` and their own id `<script call id>/<n>`. pig does not pass on the tool annotations servers declare (`readOnlyHint`, `destructiveHint`, and so on): `$pig->getAllTools()` reports each tool's name, description, parameters, and whether it is active, so a permission extension decides by tool name and arguments (see [Extensions](/docs/latest/extensions#2-guard-dangerous-operations-tool_call)).

## Servers from extensions

Extensions can add servers for the current session with `$pig->registerMcpServer($name, $config)`, using the same config shape as `mcp.json`; `unregisterMcpServer($name)` closes one again and `getMcpServers()` lists them. A server registered while the extension loads connects when the session starts; one registered later connects right away. Registrations are not saved, so register again on every load. They connect like configured servers and appear in `/mcp` with the extension as their source. Enabling, disabling, and exposure changes for them apply to the current session only. A server in `mcp.json` with the same name takes precedence; `/mcp` lists the overridden registration. `pig mcp` shell commands do not load extensions and only see `mcp.json` servers.

## Other MCP extensions

An installed extension that registers the `/mcp` command replaces the built-in MCP support: pig then neither reads `mcp.json` in sessions nor connects servers, and `/mcp` belongs to that extension. Remove the extension to use the built-in support. To turn off the built-in support without installing another extension, disable `mcp` under Built-in in `pig config`, or set `"extensions": ["-builtin:mcp"]` in [settings](/docs/latest/settings#resources); `pig mcp` shell commands still work. Likewise, an extension that registers a tool named `codemode` or `tool_search` replaces the built-in tool of that name. `pig mcp` shell commands always use the built-in support.

## SDK

SDK sessions do not load the built-in extensions. Pass the MCP extension, the codemode extension for `codemode` and `codemode-deferred` servers, and the tool search extension for `deferred` servers in `extensionPaths`. See [SDK](/docs/latest/sdk#codemode-and-mcp).
