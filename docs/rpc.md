# RPC Mode

RPC mode runs pig as a long-lived subprocess controlled through JSON records on stdin and stdout. Use it for language-independent integrations, process isolation, IDEs, and custom user interfaces.

For an in-process PHP integration, prefer the [PHP SDK](/docs/latest/sdk). For cross-language integration (Python, Go, Node.js, Rust), launch pig in RPC mode over standard subprocess streams.

| Interface | Process boundary | Control model | Best fit |
|---|---|---|---|
| [SDK](/docs/latest/sdk) | In process (PHP) | Direct PHP class methods and events | PHP hosts that want complete API access |
| RPC | Child process (Cross-language) | JSONL commands, responses, and events | Python, Go, Node.js, Rust, IDEs, or custom clients |

## Start RPC mode

```bash
pig --mode rpc --no-session
```

Normal CLI options still select the working folder, model, tools, resources, and session behavior. Common choices include `--provider`, `--model`, `--name`, `--no-session`, and `--session-dir`. See [Command Line](/docs/latest/cli) for the complete, version-specific interface; `pig --help` is authoritative for the installed version.

RPC mode rejects `@file` prompt arguments. Send prompts through the [`prompt`](/docs/latest/rpc-commands#prompt) command instead.

## Protocol records

The protocol has four record families:

| Direction | Record | Purpose |
|---|---|---|
| stdin | Command | Ask pig to prompt, inspect state, change configuration, or manage the session |
| stdout | `response` | Report whether one command succeeded and return any command data |
| stdout | Session event | Stream run, message, tool, queue, compaction, and retry activity |
| Both | Extension UI record | Forward supported extension interactions between pig and the client |

See [RPC Commands](/docs/latest/rpc-commands), [JSON Event Stream](/docs/latest/json), and [RPC Extension UI](/docs/latest/rpc-extension-ui) for the canonical record definitions.

### Correlate commands and responses

Every command accepts an optional string `id`. A matching response repeats it:

```json
{"id":"req-1","type":"get_state"}
{"id":"req-1","type":"response","command":"get_state","success":true,"data":{"...":"..."}}
```

Use unique IDs whenever more than one command can be outstanding. Command handling is asynchronous, so clients should correlate by ID rather than response order.

Session events generally have no command ID because they describe session activity. `bash_execution_update` is the exception: when the originating [`bash`](/docs/latest/rpc-commands#bash) command has an ID, its output events repeat that ID.

An `extension_ui_response` uses the ID supplied by its `extension_ui_request`. It does not produce a normal command response.

## Framing

RPC uses strict JSONL framing. Write one complete JSON object per record and terminate it with LF (`\n`). Read stdout as a byte or UTF-8 stream and split records only on LF. Strip an optional preceding carriage return to accept CRLF input.

Do not use a generic line reader that treats Unicode line or paragraph separators as record boundaries. In particular, Node.js `readline` also splits on `U+2028` and `U+2029`, which are valid inside JSON strings.

Read stdout continuously. pig honors stdout backpressure, but a client that stops reading can stall the process. Honor stdin backpressure when writing commands. Stdout is reserved for protocol records; diagnostics and application logging go to stderr.

## Run lifecycle

A successful `prompt` response means the prompt was accepted, queued, or handled. It does not mean model work completed:

```json
{"id":"req-2","type":"prompt","message":"Review this repository"}
{"id":"req-2","type":"response","command":"prompt","success":true,"data":{"disposition":"started"}}
```

`data.disposition` reports what happened to the prompt. If it is `"handled"`, no run started for this prompt, so don't wait for `agent_settled`. See [RPC Commands](/docs/latest/rpc-commands#prompt) for all values.

Continue consuming [events](/docs/latest/json) after that response. `agent_end` marks the end of one low-level agent run, but retries, overflow recovery, compaction, steering, or follow-up work can still follow. Wait for `agent_settled` when the client needs to know pig will not continue automatically.

Subscribe before sending a prompt to avoid missing a fast completion. `RpcClient::promptAndWait()` does this internally. If using separate `RpcClient` calls, install the event listener with `onEvent()` before `prompt()` and call `waitForIdle()` only while a run is active; `waitForIdle()`, `collectEvents()`, and `promptAndWait()` all wait for `agent_settled`.

## Errors

A failed command returns one response with `success: false`:

```json
{"id":"req-3","type":"response","command":"set_model","success":false,"error":"Model not found: invalid/model"}
```

Malformed JSON produces a parse response without a request ID (a trailing carriage return is stripped before parsing):

```json
{"type":"response","command":"parse","success":false,"error":"Failed to parse command: Unexpected token..."}
```

A success response only covers command handling. Provider failures and aborts after a prompt is accepted appear in the message and event stream.

Clients must also handle child-process startup failures, unexpected exits, stderr diagnostics, cancellation, and their own deadlines. Do not parse stderr as protocol data.

## Shutdown

Close the child's stdin to request an orderly shutdown. pig disposes the active runtime before exiting. Clients should still handle process signals and unexpected exits.

An extension can also request shutdown through its extension context. pig completes shutdown after the current command or after the active run emits `agent_settled`.

## Minimal client

This Python example uses a binary pipe reader, which splits on LF without treating Unicode separators as protocol boundaries:

```python
import json
import subprocess

process = subprocess.Popen(
    ["pig", "--mode", "rpc", "--no-session"],
    stdin=subprocess.PIPE,
    stdout=subprocess.PIPE,
)

assert process.stdin is not None
assert process.stdout is not None

command = {"id": "prompt-1", "type": "prompt", "message": "Hello"}
process.stdin.write(json.dumps(command).encode("utf-8") + b"\n")
process.stdin.flush()

while line := process.stdout.readline():
    record = json.loads(line)
    if record.get("type") == "message_update":
        update = record["assistantMessageEvent"]
        if update["type"] == "text_delta":
            print(update["delta"], end="", flush=True)
    elif record.get("type") == "agent_settled":
        print()
        break

process.stdin.close()
process.wait()
```

For PHP hosts, pig ships a maintained client, `Pig\CodingAgent\Rpc\RpcClient`, which starts `bin/pig --mode rpc` and has one method per command (`getState()`, `getAvailableModels()`, `exportHtml()`, `getTree()`, `getEntries()`, `getCommands()`, `navigateTree()`, `clone()`, and so on):

```php
use Pig\Async\Async;
use Pig\CodingAgent\Rpc\RpcClient;

$client = new RpcClient(cwd: '/some/project');
Async::run(function () use ($client): void {
    $client->start();
    $client->onEvent(static fn (array $event) => print $event['type'] . "\n");
    $client->promptAndWait('what does bin/pig do?');
    $client->stop();
});
```

## Reference

- [RPC Commands](/docs/latest/rpc-commands): every stdin command and response
- [JSON Event Stream](/docs/latest/json): shared stdout session events and streaming reconstruction
- [RPC Extension UI](/docs/latest/rpc-extension-ui): dialogs, notifications, responses, and limitations
- [Message Types](/docs/latest/message-types): messages and content blocks used by responses and events
- [Session File Format](/docs/latest/session-format): entries returned by session commands
- [`RpcMode.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Rpc/RpcMode.php) and [`RpcEvents.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Rpc/RpcEvents.php): command dispatch and event serialization
- [`RpcClient.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Rpc/RpcClient.php): PHP subprocess client implementation

## Moved reference anchors

The detailed references formerly on this page now have dedicated pages. These anchors preserve existing links.

<a id="prompt"></a>
<a id="steer"></a>
<a id="follow_up"></a>
<a id="abort"></a>
<a id="clear_queue"></a>
<a id="new_session"></a>
<a id="get_state"></a>
<a id="get_messages"></a>
<a id="set_model"></a>
<a id="cycle_model"></a>
<a id="get_available_models"></a>
<a id="set_thinking_level"></a>
<a id="cycle_thinking_level"></a>
<a id="get_available_thinking_levels"></a>
<a id="set_steering_mode"></a>
<a id="set_follow_up_mode"></a>
<a id="compact"></a>
<a id="set_auto_compaction"></a>
<a id="set_auto_retry"></a>
<a id="abort_retry"></a>
<a id="bash"></a>
<a id="abort_bash"></a>
<a id="get_session_stats"></a>
<a id="export_html"></a>
<a id="switch_session"></a>
<a id="fork"></a>
<a id="clone"></a>
<a id="get_fork_messages"></a>
<a id="get_entries"></a>
<a id="get_tree"></a>
<a id="get_last_assistant_text"></a>
<a id="set_session_name"></a>
<a id="get_commands"></a>

Command details moved to [RPC Commands](/docs/latest/rpc-commands).

<a id="message_update-streaming"></a>
<a id="bash_execution_update"></a>
<a id="compaction_start--compaction_end"></a>
<a id="summarization_retry_scheduled--summarization_retry_attempt_start--summarization_retry_finished"></a>

Event details moved to [JSON Event Stream](/docs/latest/json).

<a id="extension-ui-protocol"></a>

Extension interaction details moved to [RPC Extension UI](/docs/latest/rpc-extension-ui).
