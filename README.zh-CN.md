# pig — PHP AI Agent

[English](README.md) · **简体中文**

[pi](https://github.com/earendil-works/pi) 的 PHP 移植。pi 是一个 agent harness，以核心极小著称。
pig 保持相同的架构和文件划分，面向 PHP 8.3，**零运行时依赖**——不用 Guzzle、不用 ReactPHP、不用
amphp、不用 ncurses，只用标准库。

> **状态：能跑了。** `bin/pig` 就是一个可以在终端里对话的 coding agent：流式回答、七个工具的
> 实时输出、改动以 diff 呈现、Esc 打断、干活途中可以继续打字，`!命令` 跑一条 shell 命令并把结果
> 交给模型（`!!命令` 则不进上下文）。会话边聊边存——`--continue` 接上最近一次，`/resume` 从列表里
> 挑。上下文快满了会自我总结之后接着聊，`/compact` 也可以随时手动压一次，`/model` 中途换模型。
> skills 从 `~/.pig/skills` 读，也顺带读 Claude 和 Codex 的那几个目录；工具返回的图片直接画在终端里。
> hooks 是 PHP 文件，能拦下一次工具调用、改写模型看到的内容，或者自己加一条斜杠命令；
> 放一个带 `index.php` 的文件夹进去，就是一个模型能调用的工具。

## 包划分

| 包 | 命名空间 | 状态 |
|---|---|---|
| `pig/async` | `Pig\Async\` | 事件循环、Future、协程 —— **已完成** |
| `pig/ai` | `Pig\Ai\` | 统一 LLM API —— **Anthropic、OpenAI chat-completions、OpenAI Responses、Gemini 四条全链路可用** |
| `pig/agent-core` | `Pig\Agent\` | 带工具调用和状态管理的 agent 循环 —— **已完成** |
| `pig/tui` | `Pig\Tui\` | 差分渲染的终端 UI —— **已完成** |
| `pig/coding-agent` | `Pig\CodingAgent\` | coding agent —— **工具、系统提示、交互式 CLI、会话持久化、上下文压缩、模型切换、skills、hooks、自定义工具已完成** |

`pig/async` 在上游没有对应物：JavaScript 自带事件循环，PHP 没有。它的存在是为了让**一次
`stream_select()` 能同时等模型的 socket 和键盘**——这正是「流式输出途中能打断、能继续打字」
所依赖的前提。

## 跑一下

```bash
composer install
ANTHROPIC_API_KEY=sk-ant-... bin/pig
```

`--read-only` 去掉 edit、write、bash；`--theme light` 给浅色终端用；`--continue` 接着上次聊。
进去之后 `/help` 列出所有按键。

Ctrl+G 把 prompt 里现在的内容丢进 `$VISUAL` 或 `$EDITOR`，改完再塞回来——给那种写到一半发现要写
三段的消息用。模型还在回答的时候也能用：编辑器拿着终端，回答在它背后继续到。

`--model` 不用写全 id，写一部分就行——`--model sonnet`、`--model 'opus 4.1'`——`--model sonnet:high`
还能顺手把思考档位一起设了。`--models` 列出全部。

内置 147 个模型：Anthropic 的、OpenAI 自家走 Responses API 的、Gemini，加上 Groq、Cerebras、xAI、
Zai、Mistral——这五家说的都是 OpenAI chat-completions。设好对应的 `*_API_KEY`，`--model` 就能指过去。

`/tree` 回到对话里更早的某个点，从那儿接着聊。没走的那条路还留在会话文件里——所以往回走不花什么代价，
而且随时能再走回来。

`/export` 把整段对话导出成一个自包含的 HTML 文件——markdown 渲染好、代码高亮好，里面一行 JS 都没有。

设置放在 `~/.pig/settings.json`，项目可以用 `.pig/settings.json` 覆盖。主题、模型、思考档位选过一次
就记住了。

`~/.pig/commands/` 或 `.pig/commands/` 下的一个 md 文件就是一条斜杠命令：`review.md` 就是
`/review`，正文就是 prompt，`$1`、`$@` 由后面跟的参数填进去。

`~/.pig/hooks/` 或 `.pig/hooks/` 下一个「返回 callable」的 PHP 文件就是一个 hook。它能收到十六个
事件——每次工具调用和它的结果、每一轮、发给模型之前的上下文、压缩、`/tree`、启动和退出——可以拦下
一次工具调用、改写模型看到的内容，或者自己注册一条斜杠命令：

```php
<?php // ~/.pig/hooks/no-force-push.php

use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;

return function (HookApi $pi): void {
    $pi->on('tool_call', function ($event) {
        if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', '--force')) {
            return new ToolCallEventResult(block: true, reason: '这里不许 force push。');
        }

        return null;
    });
};
```

hook 跑在 pig 进程里——所以它能直接返回对象、也能用 pig 自己的类；代价是 hook 里一个死循环或者一句
`exit()` 就能把整个会话带走。写坏了的 hook 会在启动时报到 shell 上而不是直接崩掉；`/hooks` 列出
加载了哪些，`--no-hooks` 则一个都不加载。

hook 还能在一轮**进行当中**问人，并且等答案：

```php
$pi->on('tool_call', fn ($event, $ctx) => $ctx->ui->confirm('让 bash 跑吗？', $event->input['command'] ?? '')
    ? null
    : new ToolCallEventResult(block: true, reason: '你说了不行。'));
```

这次工具调用就停在那儿，屏幕上弹出选择，按下去它接着往下走——handler 拿到的就是一个普通的 `bool`。
`select`、`input`、多行 `editor`、`notify`、一行带 key 的 footer 状态、以及 `custom`（自己画组件）
也都有。Esc 等于「不行」，所以走开不回答不会把工具放过去。

`~/.pig/tools/` 或 `.pig/tools/` 下一个带 `index.php` 的**文件夹**就是一个模型能调用的工具——
之所以是文件夹，因为这个目录里同时还放着 pig 下载来的 `fd` 和 `rg`，直接躺在那儿的文件是那两个。
加载机制和 hooks 完全一样，代价也一样；`/tools` 列出模型手上所有工具各自从哪来，`--no-tools` 一个
都不加载：

```php
<?php // ~/.pig/tools/wc/index.php

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolApi;

return fn (CustomToolApi $pi) => new CustomTool(
    name: 'wc',
    label: '数行数',
    description: 'Count the lines in a file.',
    parameters: [
        'type' => 'object',
        'properties' => ['path' => ['type' => 'string', 'description' => 'the file']],
        'required' => ['path'],
    ],
    execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult(
        [new TextContent(trim($pi->exec(['wc', '-l', $params['path']])->stdout))],
    ),
);
```

`$ctx` 就是这次会话：到目前为止的对话、正在回答的是哪个模型、agent 是不是在忙、一个能把它停下来的
口子，以及和 hook 同一个 `ui`。工具还能收到「会话开始 / 切换 / 跳转 / 结束」四个时机——自己存着状态
的工具靠这个重建或者放手。它也能自己画这次调用和这次的结果，所以一个答案是张表的工具，不必
被塞进给文件设计的那套排版里。

skill 就是一个带 `SKILL.md` 的文件夹。pig 读 `~/.pig/skills` 和 `.pig/skills`，同时也读
`~/.claude/skills`、`.claude/skills` 和 `~/.codex/skills`——给别的 agent 写的 skill 在这儿直接能用。
`/skills` 列出找到了哪些、各自来自哪个目录。

`examples/ask.php` 是同一套东西，完全不带 UI：

```bash
ANTHROPIC_API_KEY=sk-ant-... php examples/ask.php "天为什么是蓝的？"
```

这行命令底下的每一层都是 pig 自己的：一个非阻塞 TLS socket、手写的 HTTP/1.1、边到边解的 SSE、
建在 `Fiber` 上的协程。回答途中按 Ctrl-C，走的就是 TUI 用的那条 abort 路径。

## 环境要求

PHP >= 8.3，需要 `ext-json`、`ext-mbstring`、`ext-openssl`，终端 UI 还需要 `ext-pcntl`。
唯一必须的外部程序是 `stty`。`find` 和 `grep` 两个工具需要 `fd` 和 `rg`，机器上没有的话 pig 会在
第一次用到时下载到 `~/.pig/tools/`（`PIG_OFFLINE=1` 可以关掉）。

`Fiber` 是 PHP 8.1 引入的，整套地基都建在它上面，所以 8.1 是绝对下限。实际声明的下限定在 8.3：
8.1 已经 EOL，8.2 的安全支持 2026 年底到期。

## 开发

```bash
composer install
php test/lint.php      # 对每个文件跑 php -l
vendor/bin/phpunit
```

验证要对着**下限版本**跑，而不是只对着你本机的 PHP：8.3 会在解析期就拒绝 8.4-only 语法，
而顺手用上一个下限没有的特性太容易了。

移植规则、已定下的决策、以及目前踩到的坑，见 [CLAUDE.md](CLAUDE.md)。

## 上游对照

以 pi 的 `d0a4c37`（2026-01-02）为基准移植——就是 `agent-loop.ts` 还只有 418 行、harness 尚未
膨胀的那个快照。类名、文件名、方法名都跟上游对齐，这样 diff 新版上游时是机械操作。

## 许可

MIT
