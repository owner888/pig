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
| `image($value)` | Add an image to the output: a base64 `data:` URL, an `['image_url' => ...]` array, or an MCP `ImageContent` block such as `$result['content'][0]`. Remote URLs are not supported. The base64 is checked and the type is read from the bytes: PNG, JPEG, GIF and WebP are accepted, anything else throws `TypeError`. The image is also saved to a temp file, and the result names its path in front of the picture. |
| `echo` / `print` | Like `text()`. |
| `return $value` | A top-level `return` adds the value like `text()`. |
| `exit_script()` | End the script successfully. PHP's `exit` would end the sandbox instead. |
| `parallel([...])` | Run the closures' tool calls at the same time; answers their results in order, keys kept. One that throws makes `parallel()` throw. |
| `parallel_settled([...])` | Like `parallel()`, but each result is `['ok' => true, 'value' => ...]` or `['ok' => false, 'error' => '...']`, so one failure does not stop the rest. |
| `store($key, $value)` / `load($key)` | Keep small JSON values across `codemode` calls. See [Store values](#store-values). |
| `ALL_TOOLS` | Every callable tool as `['name' => ..., 'description' => ...]`, including tools the description does not list. |
| `search_tools($query, ['limit' => 8, 'namespace' => ...])` | Rank callable tools by relevance (BM25, default limit 8). Answers `['name' => ..., 'description' => ...]` entries. |
| `describe_tool($name)` | A tool's description and PHP declaration, or `null`. |
| `describe_namespace($name)` | A namespace of tools (one MCP server) as `['name', 'description'?, 'instructions'?, 'tools' => [identifier, ...]]`, or `null`. Its name, its identifier form, or the part after its last `__` all find it. |
| `$tools->has($name)` | Whether a tool exists, for a script that probes before calling. |
| `$models` | The model catalogue, classifiers and image generation. See [Models](#models). |

Several `text()` items and a returned value each start with a `==> text N/M <==` line in the
result, so the model can tell them apart; what `echo` wrote follows everything else in one
`<console_output>` block.

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
tool's error text; the arguments are checked against the tool's schema the way the model's own
calls are, so the message names the parameter. Use `parallel_settled()` to keep the results of
the calls that succeed. Each call is a `tool_execution_*` event of its own with
`parentToolCallId` set to the script's call, and its id is `<script call id>/<n>`.
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
with `search_tools()`, `describe_tool()`, `describe_namespace()`, or by filtering `ALL_TOOLS`.

`codemode.mode` in the settings decides how the model is shown the tools it already has:

- `on` (the default): each declared tool's description ends with how a script calls it
  (`Codemode: $tools->read([...]) resolves to a string.`), and the `codemode` description lists
  only the tools the model cannot otherwise see.
- `only`: the model is offered `codemode` alone — the other tools' declarations are left out of
  the request and listed in the `codemode` description instead, so every call goes through a
  script.

## Models

`$models` reaches the model catalogue and runs non-LLM models with the session's credentials:
classifiers, which answer typed questions about JSON state, and image models, which generate
images. Chat models are listed but cannot be run from scripts.

| Method | Answers |
|---|---|
| `$models->getModelsOfType($type, $provider = null)` | Every known model of a type (`'chat'`, `'image'`, `'classifier'`), optionally for one provider, as catalogue entries (`provider`, `id`, `name`, `api`, `input`, `cost`, ...; `headers` is never included). |
| `$models->getAvailableOfType($type, $provider = null)` | The models of a type whose provider has credentials. |
| `$models->getModelOfType($type, $provider, $id)` | One catalogue entry, or `null`. |
| `$models->classify($model, $context)` | Answers `$context['questions']` about `$context['state']`; the answers are in `$result['answers']` by question id. |
| `$models->generateImages($model, $context)` | Generates images from `$context['input']` text and image blocks; show the blocks of `$result['output']` with `image()`. Can take minutes. |

`classify()` and `generateImages()` use only the `provider` and `id` of `$model`, so
`['provider' => ..., 'id' => ...]` works as well. They do not throw on provider errors: check
`$result['stopReason']` (`stop`, `error` or `aborted`) and `$result['errorMessage']`. At most
four such calls run at once per script; more wait for a free slot, so `parallel()` over many
items is fine. Each call is a row in the result's call list with its cost, and their usage is
billed to the script's result like any model call.

Model ids differ between providers, for example `typesafe/jev-latest` and
`openrouter/typesafe/jev-1.13`. Use `$models->getAvailableOfType($type)` to find the ids that
work with the current credentials.

### Classify

The context is `['state' => [...], 'questions' => [id => question, ...]]`. A question is one of:

- `['type' => 'choice', 'instructions' => ..., 'criteria' => [label => meaning, ...]]` — pick
  one label; answered as `['choice', 'probabilities', 'confidence']`.
- `['type' => 'score', 'instructions' => ..., 'criteria' => [lowest, ..., highest]]` — score on
  an ordered scale; answered as `['score', 'confidence']`, `score` being the expected level
  index from 0.
- `['type' => 'bool', 'instructions' => ..., 'criteria' => ['true' => ..., 'false' => ...]]` —
  yes or no; answered as `['probability']`, the probability of `true`.

An optional `'images' => [block, ...]` adds pictures for a model whose `input` includes
`image` (`openai/gpt-6-luna`): each block is `['type' => 'image', 'data' => <base64>,
'mimeType' => ...]`, the shape `$tools->read()` answers under `images` for a picture, so a
read result can be passed straight through. A text-only model refuses a context with images.

```php
$jev = $models->getModelOfType('classifier', 'typesafe', 'jev-latest');
$results = parallel(array_map(fn (string $message) => fn () => $models->classify($jev, [
    'state' => ['message' => $message],
    'questions' => [
        'sentiment' => ['type' => 'choice', 'instructions' => 'How does the user feel about the product?',
            'criteria' => ['positive' => 'Satisfied or happy', 'negative' => 'Unhappy or frustrated', 'neutral' => 'Neither']],
        'urgency' => ['type' => 'score', 'instructions' => 'How urgently does this need a reply?',
            'criteria' => ['no reply needed', 'reply this week', 'reply today']],
    ],
]), $messages));
return array_map(fn ($r, $i) => $r['stopReason'] === 'stop'
    ? ['message' => $messages[$i], 'sentiment' => $r['answers']['sentiment']['choice'], 'urgency' => $r['answers']['urgency']['score']]
    : ['message' => $messages[$i], 'error' => $r['errorMessage']], $results, array_keys($results));
```

### Generate images

The context is `['input' => [block, ...]]`: the prompt as `['type' => 'text', 'text' => ...]`
blocks, plus `['type' => 'image', 'data' => <base64>, 'mimeType' => ...]` blocks to edit or use
as references. The result's `output` is the same kind of list. Show generated images with
`image($block)`; do not `text()` or `return` the `data`, it is large and the model cannot read
it as text. `image()` also saves each image to a temp file and names its path in the result.

```php
// @options: {"timeout_ms": 300000}
$painter = $models->getModelOfType('image', 'openrouter', 'google/gemini-2.5-flash-image');
$result = $models->generateImages($painter, ['input' => [['type' => 'text', 'text' => 'A red fox in the snow, watercolor']]]);
if ($result['stopReason'] !== 'stop') return $result['errorMessage'];
foreach ($result['output'] as $block) {
    if ($block['type'] === 'image') image($block); else text($block['text']);
}
```

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
- A script may output at most 16 MiB of text and image data, or 100,000 items (`text()`,
  `image()`, `echo`, the returned value). Past either the script fails with `LengthException`
  and keeps what it had output; print a summary, or write large data to a file with a tool.
- Scripts cannot start other `codemode` scripts.
