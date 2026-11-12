# CLI Integration

Running `pig` without arguments opens the interactive terminal interface. When input or output is piped or redirected, pig automatically switches to print mode. You can also explicitly choose print, JSON, or RPC mode for scripts and applications.

All modes share the same agent loop, sessions, tools, and extensions. The mode determines how input reaches pig, how output is delivered, and whether the process remains available for subsequent commands.

The SDK is not a CLI mode. It embeds the agent directly into PHP processes. See the [SDK Guide](/docs/latest/sdk) when direct in-process PHP integration is preferable to process communication.

## Selecting a Mode

| Mode | Interface | Lifecycle | Use Case |
|---|---|---|---|
| Interactive | Terminal UI | Runs until user exits | Direct human interaction with pig |
| Print | Final text on stdout | Single execution | Scripts that need the assistant's final response |
| JSON | JSONL events on stdout | Single execution | Processes requiring structured execution progress |
| RPC | JSONL commands, responses & events | Long-running daemon | External programs or other languages controlling pig |

Regardless of mode, CLI options independently govern working directory, model selection, tools, and session logging. See [Command Line](/docs/latest/cli) for complete options.

## Printing to stdout

Print mode executes the prompt, writes the final assistant text to stdout, and exits:

```bash
pig -p "Summarize the changes in this repository"
# or, with stdout redirected
pig "Summarize the changes in this repository" > summary.txt
```

`--mode text` alone does not select print mode: on a terminal it still opens the interactive UI. Use `-p` to run once.

Use print mode when only the final text is needed for shell pipelines or automation jobs. Intermediate events are not exposed.

Print mode writes errors and warnings to stderr. An assistant turn ending in `error` or `aborted` exits with status 1. Print mode with no prompt at all exits with status 0 and prints nothing.

## Streaming JSON Events

JSON mode writes the session header, followed by newline-delimited JSON (JSONL) events:

```bash
pig --mode json "Review this repository" > events.jsonl
```

All prompts are provided at process launch. The process streams all execution events and exits once complete.

Streaming `message_update` events emit incremental deltas rather than accumulated snapshots. `agent_settled` marks the end of all automated work for the current turn.

stdout is reserved strictly for JSONL data. Logs and warnings are directed to stderr. See [JSON Event Stream](/docs/latest/json) for payload contracts.

## Controlling pig with RPC

RPC mode keeps pig running while an external host process exchanges JSON commands and events over stdin/stdout:

```bash
pig --mode rpc --no-session
```

Commands are JSON objects written to stdin. Responses and events are JSON objects written to stdout, each occupying a single line.

Attaching an `id` to a command causes pig to echo the same ID in the matching response.

See [RPC Protocol](/docs/latest/rpc), [RPC Commands](/docs/latest/rpc-commands), and [JSON Event Stream](/docs/latest/json) for protocol details.

## References

- [Command Line](/docs/latest/cli): Flags and mode options
- [JSON Event Stream](/docs/latest/json): Event structure reference
- [RPC Protocol](/docs/latest/rpc): RPC lifecycle, framing, and error handling
- [RPC Commands](/docs/latest/rpc-commands): Command and response contracts
- [RPC Extension UI](/docs/latest/rpc-extension-ui): Extension interactive dialogs
- [PHP SDK Guide](/docs/latest/sdk): In-process PHP integration
