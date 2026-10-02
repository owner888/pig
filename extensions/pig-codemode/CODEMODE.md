# Codemode

The `codemode` tool lets the model write a PHP script that calls pig's other tools. Only the
script's output reaches the model, so a script can run calls in parallel and filter large
results before the model sees them.

## Scripts

The tool input is raw PHP source, not JSON and not a markdown code fence, with no `<?php`. It
runs as the body of a function in a fresh sandboxed `php` process, so top-level `return`
works and `$tools` is in scope. The sandbox has no shell, file system, network or `include`;
`disable_functions` and `open_basedir` enforce it. String, array, math, JSON, regex and date
functions all work. Scripts reach the outside world only through tools.

A script may start with an options line:

```php
// @options: {"max_output_tokens": 2000, "timeout_ms": 60000}
```

- `max_output_tokens` (default 10000) limits the output. Longer output keeps its start and end,
  and the full text is written to a temp file whose path is included in the result.
- `timeout_ms` is a hard deadline for the whole script. It is unset by default.

The result starts with `Script completed` or `Script failed`, the wall time, and the output. A
failed script keeps its partial output, followed by `Script error:` and the error. Tool calls
are real: calls made before a failure are not undone. Calls still running when the script ends
are cancelled.

## Globals

| Global | Purpose |
|---|---|
| `$tools->name([...])` | Call a tool. See [Call tools](#call-tools). |
| `text($value)` | Add a text item to the output. Strings are added as is, other values as JSON. |
| `image($value)` | Add an image to the output: a base64 `data:` URL, an `['image_url' => ...]` array, or an MCP `ImageContent` block such as `$result['content'][0]`. Remote URLs are not supported. PNG, JPEG, GIF and WebP are accepted. |
| `echo` / `print` | Like `text()`. |
| `return $value` | A top-level `return` adds the value like `text()`. |
| `exit_script()` | End the script successfully. PHP's `exit` would end the sandbox instead. |
| `parallel([...])` | Run the closures' tool calls at the same time; answers their results in order, keys kept. One that throws makes `parallel()` throw. |
| `parallel_settled([...])` | Like `parallel()`, but each result is `['ok' => true, 'value' => ...]` or `['ok' => false, 'error' => '...']`, so one failure does not stop the rest. |
| `store($key, $value)` / `load($key)` | Keep small JSON values across `codemode` calls. See [Store values](#store-values). |
| `ALL_TOOLS` | Every callable tool as `['name' => ..., 'description' => ...]`, including tools the description does not list. |
| `search_tools($query, ['limit' => 8, 'namespace' => ...])` | Rank callable tools by relevance (BM25, default limit 8). Answers `['name' => ..., 'description' => ...]` entries. |
| `describe_tool($name)` | A tool's description and PHP declaration, or `null`. |
| `$tools->has($name)` | Whether a tool exists, for a script that probes before calling. |

## Call tools

Every tool the session can call is a method of `$tools`, named by its identifier: characters
that are not valid in a PHP identifier become `_`, so the MCP tool `mcp__dev-radius__search`
is `$tools->mcp__dev_radius__search([...])`. Each method takes one associative array with the
tool's arguments.

What a call answers depends on the tool:

- Tools with an output schema answer a structured array. MCP tools answer their whole
  `CallToolResult`, including `isError` and `structuredContent`.
- Other tools, such as `read`, `edit`, `write` and `bash`, answer their text output as a string.
- A result that carries pictures — `read` on a PNG, `generate_image` — answers
  `['text' => '...', 'images' => [['type' => 'image', 'data' => <base64>, 'mimeType' => 'image/png'], ...]]`.
  Show one with `image($r['images'][0])`; do not `text()` or `return` the `data`, it is large and
  the model cannot read it as text.

A call that fails, is blocked, or gets invalid arguments throws an exception carrying the
tool's error text. Use `parallel_settled()` to keep the results of the calls that succeed.
Calling a tool that does not exist throws an error naming the close matches.

```php
[$a, $b] = parallel([
    fn () => $tools->read(['path' => 'a.txt']),
    fn () => $tools->read(['path' => 'b.txt']),
]);
return strlen($a) + strlen($b);
```

The `codemode` description lists tools with their PHP declarations, grouped by namespace (for
example one MCP server). Tools with `codemode-deferred` exposure are not listed, so the
description stays the same while MCP servers connect. Listed declarations share a budget of
3000 estimated tokens (`codemode.inlineBudget` in the settings). Scripts find the other tools
with `search_tools()`, `describe_tool()`, or by filtering `ALL_TOOLS`.

## Store values

`store($key, $value)` keeps a JSON value under a string key for later `codemode` calls;
storing `null` deletes the key. `load($key)` answers the value, or `null`. Writes are kept only
when the script succeeds: each successful script that stores values appends a `codemode-store`
entry to the session, so resumed sessions keep the values and each branch sees only the values
written on its path.

The store is for small state such as IDs, cursors or summaries. One value may have at most
262144 characters of JSON and all values together at most 1048576. Do not store image data;
show images with `image()` or write them to a file with a tool.

## Limits

- A script has a 256 MB memory limit. Running out fails the script; filter or aggregate large
  data instead of accumulating it.
- Scripts cannot start other `codemode` scripts.
