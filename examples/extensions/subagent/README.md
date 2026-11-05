# Subagent Example

Delegate tasks to specialized subagents with isolated context windows.

A port of pi's `examples/extensions/subagent/`. Like pi's, it is an **example, not a built-in**:
pig does not load it until you link it into your extensions directory, and the `subagent` tool
costs the model a choice on every turn, so only do that when you want it.

## Features

- **Isolated context**: Each subagent runs in a separate `pig` process
- **Streaming output**: See tool calls and progress as they happen
- **Parallel streaming**: All parallel tasks stream updates simultaneously
- **Markdown rendering**: Final output rendered with proper formatting (expanded view)
- **Usage display**: Shows turns, tokens, cost, and context usage inside each subagent result
- **Abort support**: Escape kills the subagent processes

## Structure

```
subagent/
├── README.md            # This file
├── index.php            # The extension (entry point): the tool, its renderers
├── Subagent.php         # Helpers and runSingleAgent()
├── Agents.php           # Agent discovery logic
├── agents/              # Sample agent definitions
│   ├── scout.md         # Fast recon, returns compressed context
│   ├── planner.md       # Creates implementation plans
│   ├── reviewer.md      # Code review
│   └── worker.md        # General-purpose (full capabilities)
└── prompts/             # Workflow presets (prompt templates)
    ├── implement.md     # scout -> planner -> worker
    ├── scout-and-plan.md    # scout -> planner (no implementation)
    └── implement-and-review.md  # worker -> reviewer -> worker
```

## Installation

From the repository root, symlink the files:

```bash
# Symlink the extension (a folder with index.php and the files it requires)
mkdir -p ~/.pig/agent/extensions/subagent
for f in examples/extensions/subagent/*.php; do
  ln -sf "$(pwd)/$f" ~/.pig/agent/extensions/subagent/$(basename "$f")
done

# Symlink agents
mkdir -p ~/.pig/agent/agents
for f in examples/extensions/subagent/agents/*.md; do
  ln -sf "$(pwd)/$f" ~/.pig/agent/agents/$(basename "$f")
done

# Symlink workflow prompts
mkdir -p ~/.pig/agent/prompts
for f in examples/extensions/subagent/prompts/*.md; do
  ln -sf "$(pwd)/$f" ~/.pig/agent/prompts/$(basename "$f")
done
```

Run `/reload` in an existing session, or start a new one.

## Security Model

This tool executes a separate `pig` subprocess with a delegated system prompt and tool/model
configuration.

**Project-local agents** (`.pig/agents/*.md`) are repo-controlled prompts that can instruct the
model to read files, run bash commands, etc.

**Default behavior:** Only loads **user-level agents** from `~/.pig/agent/agents`.

To enable project-local agents, pass `agentScope: "both"` (or `"project"`). Only do this for
repositories you trust.

When running interactively, the tool prompts for confirmation before running project-local agents
unless the session's project is trusted and the agents come from that project's `.pig/agents`
directory. Agents loaded from another task `cwd` still require confirmation. Set
`confirmProjectAgents: false` to disable confirmation. In `-p` and the other modes with no UI, the
confirmation cannot be given, and the call is canceled.

## Usage

### Single agent
```
Use scout to find all authentication code
```

### Parallel execution
```
Run 2 scouts in parallel: one to find models, one to find providers
```

### Chained workflow
```
Use a chain: first have scout find the read tool, then have planner suggest improvements
```

### Workflow prompts
```
/implement add Redis caching to the session store
/scout-and-plan refactor auth to support OAuth
/implement-and-review add input validation to API endpoints
```

## Tool Modes

| Mode | Parameter | Description |
|------|-----------|-------------|
| Single | `{ agent, task }` | One agent, one task |
| Parallel | `{ tasks: [...] }` | Multiple agents run concurrently (max 8, 4 concurrent) |
| Chain | `{ chain: [...] }` | Sequential with `{previous}` placeholder |

## Output Display

**Collapsed view** (default):
- Status icon (✓/✗/⏳) and agent name
- Last 5-10 items (tool calls and text)
- Usage stats: `3 turns ↑input ↓output RcacheRead WcacheWrite $cost ctx:contextTokens model`

**Expanded view** (Ctrl+O):
- Full task text
- All tool calls with formatted arguments
- Final output rendered as Markdown
- Per-task usage (for chain/parallel)

**Parallel mode streaming**:
- Shows all tasks with live status (⏳ running, ✓ done, ✗ failed)
- Updates as each task makes progress
- Returns each completed task summary to the parent model with its own 50 KB limit
- Returns failure diagnostics from stderr/error messages when a child exits before producing output

## Agent Definitions

Agents are markdown files with YAML frontmatter:

```markdown
---
name: my-agent
description: What this agent does
tools: read, grep, find, ls
model: claude-haiku-4-5
---

System prompt for the agent goes here.
```

When `model` is omitted, the subagent inherits the dispatching session's active model and thinking
level. The sample agents name pi's models; change `model:` to one you have.

**Locations:**
- `~/.pig/agent/agents/*.md` - User-level (with `agentScope: "user"` or `"both"`)
- `.pig/agents/*.md` - Project-level (only with `agentScope: "project"` or `"both"`)

Project agents override user agents with the same name when `agentScope: "both"`. The format is
pi's, so an agent file written for pi or for the community pi subagent packages works as it is.

## Sample Agents

| Agent | Purpose | Model | Tools |
|-------|---------|-------|-------|
| `scout` | Fast codebase recon | Haiku | read, grep, find, ls, bash |
| `planner` | Implementation plans | Sonnet | read, grep, find, ls |
| `reviewer` | Code review | Sonnet | read, grep, find, ls, bash |
| `worker` | General-purpose | Sonnet | (all default) |

## Workflow Prompts

| Prompt | Flow |
|--------|------|
| `/implement <query>` | scout → planner → worker |
| `/scout-and-plan <query>` | scout → planner |
| `/implement-and-review <query>` | worker → reviewer → worker |

## Error Handling

- **Exit code != 0 or signal termination**: Tool returns an error with preserved stderr/output
- **Spawn errors**: Tool returns the spawn diagnostic
- **stopReason "error"**: LLM error is propagated with its error message
- **Abort**: Escape kills the subagent process tree and preserves partial details/usage
- **Chain mode**: Stops at the first failing step and reports which step failed

## Limitations

- Output truncated to last 10 items in collapsed view (expand to see all)
- Single and chain output is capped at 50 KB/2,000 lines; full results remain in tool details
- Parallel output keeps a separate 50 KB budget per task, allowing up to roughly 400 KB across eight tasks
- Child usage is displayed in tool details but is not added to the calling session's usage totals
- Agents are snapshotted from each task's effective cwd when the tool invocation starts; relative task cwd values resolve from the session cwd
- Parallel mode limited to 8 tasks, 4 concurrent
