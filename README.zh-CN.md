# pig — PHP AI Agent

[English](README.md) · **简体中文**

[pi](https://github.com/earendil-works/pi) 的 PHP 移植。pi 是一个 agent harness，以核心极小著称。
pig 保持相同的架构和文件划分，面向 PHP 8.3，**零运行时依赖**——不用 Guzzle、不用 ReactPHP、不用
amphp、不用 ncurses，只用标准库。

> **状态：能跑了。** `bin/pig` 就是一个可以在终端里对话的 coding agent：流式回答、七个工具的
> 实时输出、改动以 diff 呈现、Esc 打断、干活途中可以继续打字，`!命令` 跑一条 shell 命令并把结果
> 交给模型（`!!命令` 则不进上下文）。会话边聊边存——`--continue` 接上最近一次，`/resume` 从列表里
> 挑。还缺的是模型选择器，以及上下文满了之后的压缩。还缺的是它周围的东西——会话不会
> 保存、没有模型选择器、上下文满了也不会自动压缩。

## 包划分

| 包 | 命名空间 | 状态 |
|---|---|---|
| `pig/async` | `Pig\Async\` | 事件循环、Future、协程 —— **已完成** |
| `pig/ai` | `Pig\Ai\` | 统一 LLM API —— **Anthropic 全链路可用**；其余供应商待移植 |
| `pig/agent-core` | `Pig\Agent\` | 带工具调用和状态管理的 agent 循环 —— **已完成** |
| `pig/tui` | `Pig\Tui\` | 差分渲染的终端 UI —— **已完成** |
| `pig/coding-agent` | `Pig\CodingAgent\` | coding agent —— **工具、系统提示、交互式 CLI、会话持久化已完成**；上下文压缩待补 |

`pig/async` 在上游没有对应物：JavaScript 自带事件循环，PHP 没有。它的存在是为了让**一次
`stream_select()` 能同时等模型的 socket 和键盘**——这正是「流式输出途中能打断、能继续打字」
所依赖的前提。

## 跑一下

```bash
composer install
ANTHROPIC_API_KEY=sk-ant-... bin/pig
```

`--read-only` 去掉 edit、write、bash；`--theme light` 给浅色终端用；`--model <id>` 换模型；
`--continue` 接着上次聊。进去之后 `/help` 列出所有按键。

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
