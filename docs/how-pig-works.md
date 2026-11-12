# How pig Works

pig coordinates model requests, tool execution, context assembly, and session storage. A session is pig's record of a conversation, including messages, tool calls and results, model changes, compactions, and other events.

Messages and events in a session form a tree. Each path through that tree is a branch. The branch ending at the current entry is the active branch and supplies the history for the next model request.

## Agent loop

A submitted message is added to the active branch. pig builds a model request from the system prompt, active branch, available tools, and model settings, then sends it through the selected provider.

The provider streams an assistant response, which can contain text and tool calls. pig records the response, executes each tool call, and records the results. That completes one turn. If tool results or queued messages require another model request, pig starts another turn. Otherwise, the run ends.

Steering messages enter after the current assistant turn. Follow-up messages enter after the agent has finished its pending work. Aborting stops the current run and returns queued messages to the editor.

## Context

The active branch supplies conversation history. pig converts its session entries into model-compatible user, assistant, and tool-result messages.

pig builds the system prompt from its base instructions and discovered context files. The request also carries tool definitions and skill descriptions.

Full skill instructions are loaded on demand. Extensions can add instructions or transform context.

Prompt templates expand editor input before it becomes a user message. Selected files, images, pasted text, and shell output can become message content.

## Sessions

Persistent sessions are JSONL files. Each tree entry has an ID and refers to its parent. The current entry identifies the active branch.

Continuing from an earlier entry creates another branch in the same file. Forking and cloning copy selected history into a new session file.

Model context is reconstructed from the active branch. Compaction inserts a summary entry that replaces older messages in subsequent model requests. The original entries remain in the session tree.

## Interfaces

Interactive mode renders session and agent events in the terminal. Print mode runs a prompt and writes the final response. JSON mode writes agent events as JSONL.

RPC mode accepts JSONL commands on stdin and writes responses and events to stdout. Web mode serves a browser UI that runs each conversation as an RPC child process. MCP mode serves one conversation to another agent as an `ask` tool. The PHP SDK creates and controls agent sessions in process.

All interfaces use the same agent and session mechanisms.

## Extensions and resources

Extensions are pure PHP modules loaded into the pig process. Their factory closures register tools, commands, shortcuts, providers, event handlers, renderers, and terminal UI.

Skills provide on-demand instructions and supporting files. Prompt templates provide reusable message text. Themes provide terminal colors. pig packages distribute these resources through the official gallery, git, or local directories.

## Trust and permissions

pig first chooses the session (`--continue`, `--resume`, `--session`, and so on), because a resumed session from another project continues in that project's directory. It then resolves project trust for that directory before loading project settings and resources. After the trust decision and project-resource loading, pig loads context files. Enabled tools use the operating-system permissions of the pig process. Extensions execute inside that process.
