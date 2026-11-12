# pig

pig is an extensible AI agent that works from your terminal. Give it a goal and a working folder, and it can inspect files, run commands, edit content, and work through multi-step tasks.

Use pig for software development, research notes, writing projects, data files, or hobby work. You can use pig as is, prompt it to adapt itself to your workflow, or build other applications powered by pig using the SDK.

## Start using pig

New to pig? Follow the [Quickstart](/docs/latest/quickstart) to install pig, connect a model, and complete your first task.

If pig is already installed, choose what you want to do:

- [Use pig interactively](/docs/latest/usage) to add files, run commands, direct ongoing work, and export results.
- [Choose a model](/docs/latest/models) or connect a subscription, API key, local model, or compatible endpoint.
- [Continue or branch a session](/docs/latest/sessions) to resume work or explore another approach without losing history.
- [Configure pig](/docs/latest/configuration) for your preferences, working folders, instructions, and reusable resources.
- [Understand how pig works](/docs/latest/how-pig-works), including tools, context, sessions, and the agent loop.

## Customize pig

pig can reuse prompts, load specialized instructions, add executable integrations, change its terminal interface, connect model services, and distribute these resources as packages.
Choose the smallest mechanism that meets your need: [prompt templates](/docs/latest/prompt-templates) for reusable prompts, [skills](/docs/latest/skills) for on-demand instructions, [extensions](/docs/latest/extensions) for executable integrations, [themes](/docs/latest/themes) for colors, [custom providers](/docs/latest/custom-provider) for model services, and [pig packages](/docs/latest/packages) to distribute them.

## Automate or embed pig

- Use [print mode](/docs/latest/cli#invocation-and-output) for one-off and scripted tasks.
- Use [JSON event stream mode](/docs/latest/json) to consume structured events from one run.
- Use [RPC mode](/docs/latest/rpc) to control a separate pig process.
- Use the [PHP SDK](/docs/latest/sdk) to run pig inside an application.

## Find reference and setup information

Use the reference pages to look up [CLI options](/docs/latest/cli), [settings](/docs/latest/settings), [provider authentication](/docs/latest/providers), [keybindings](/docs/latest/keybindings), and [environment variables](/docs/latest/environment-variables).

For platform-specific help, see [Terminal Setup](/docs/latest/terminal-setup), [Windows](/docs/latest/windows), [tmux](/docs/latest/tmux), [Termux on Android](/docs/latest/termux), or [Containerization](/docs/latest/containerization).

## Work safely

pig's tools and extensions run with the permissions of the pig process. Project trust controls which project resources pig loads, but it does not sandbox tool calls. Review [Security](/docs/latest/security) before using untrusted files, repositories, extensions, or unattended automation.
