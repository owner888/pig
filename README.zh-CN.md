# pig — PHP AI Agent

[English](README.md) · **简体中文**

[pi](https://github.com/earendil-works/pi) 的 PHP 移植。pi 是一个 agent harness，以核心极小著称。
pig 保持相同的架构和文件划分，面向 PHP 8.3，**零运行时依赖**——不用 Guzzle、不用 ReactPHP、不用
amphp、不用 ncurses，只用标准库。

> **状态：早期。** 协程运行时已完成并有测试覆盖。LLM API、agent 循环、终端 UI、coding agent CLI
> 按这个顺序移植中。现在还不能当 agent 用。

## 包划分

| 包 | 命名空间 | 状态 |
|---|---|---|
| `pig/async` | `Pig\Async\` | 事件循环、Future、协程 —— **已完成** |
| `pig/ai` | `Pig\Ai\` | 统一多供应商 LLM API —— 进行中 |
| `pig/agent-core` | `Pig\Agent\` | 带工具调用和状态管理的 agent 循环 —— 未开始 |
| `pig/tui` | `Pig\Tui\` | 差分渲染的终端 UI —— 未开始 |
| `pig/coding-agent` | `Pig\CodingAgent\` | 交互式 coding agent CLI —— 未开始 |

`pig/async` 在上游没有对应物：JavaScript 自带事件循环，PHP 没有。它的存在是为了让**一次
`stream_select()` 能同时等模型的 socket 和键盘**——这正是「流式输出途中能打断、能继续打字」
所依赖的前提。

## 环境要求

PHP >= 8.3，需要 `ext-json`、`ext-mbstring`、`ext-openssl`，终端 UI 还需要 `ext-pcntl`。

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
