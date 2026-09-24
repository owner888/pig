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
> 放一个带 `index.php` 的文件夹进去，就是一个模型能调用的工具。`-p` 打一次答案就退出，
> `--mode json` 和 `--mode rpc` 则干脆不要终端，改说 JSON 行——给编辑器用。

## 包划分

| 包 | 命名空间 | 状态 |
|---|---|---|
| `pig/async` | `Pig\Async\` | 事件循环、Future、协程 —— **已完成** |
| `pig/ai` | `Pig\Ai\` | 统一 LLM API —— **Anthropic、OpenAI chat-completions、OpenAI Responses、Gemini 四条全链路可用** |
| `pig/agent-core` | `Pig\Agent\` | 带工具调用和状态管理的 agent 循环 —— **已完成** |
| `pig/tui` | `Pig\Tui\` | 差分渲染的终端 UI —— **已完成** |
| `pig/coding-agent` | `Pig\CodingAgent\` | coding agent —— **工具、系统提示、交互式 CLI、会话持久化、上下文压缩、模型切换、skills、hooks、自定义工具、三种模式(终端 / print / RPC)已完成** |

`pig/async` 在上游没有对应物：JavaScript 自带事件循环，PHP 没有。它的存在是为了让**一次
`stream_select()` 能同时等模型的 socket 和键盘**——这正是「流式输出途中能打断、能继续打字」
所依赖的前提。

## 跑一下

```bash
composer install
ANTHROPIC_API_KEY=sk-ant-... bin/pig
```

`--read-only` 去掉 edit、write、bash；`--theme light` 给浅色终端用；`--api-key <key>` 只对这一次
运行生效、不存盘；`-c`/`--continue` 接着上次聊，
或者单独写个 `-r`/`--resume` 从列表里挑一个——在那个列表里直接打字就是搜索，搜的是整段对话里
说过的话，不只是开头那一句。`-h` 看所有旗标，`-v` 看版本。进去之后 `/help` 列出所有按键。

有 Claude Pro 或 Max 订阅的话不用配 key：`/login` 给你一个网址，打开点同意，把回来的 code 粘回来
就行。GitHub Copilot 的订阅也一样走 `/login`——它给你一个码，去 github.com 输进去，pig 这边等着，
按 esc 可以不等了。Gemini CLI（Google Cloud Code Assist）会开浏览器、在 `localhost:8085` 接回跳，
所以 8085 端口得空着；它还需要 Google 自己的 client id 和 secret，这两个 pig 不附带——设
`GEMINI_CLI_CLIENT_ID` 和 `GEMINI_CLI_CLIENT_SECRET`，或者在 `~/.pig/settings.json` 里写
`geminiCli.clientId` 和 `geminiCli.clientSecret`。第四个是 Antigravity，同样的走法但端口是
51121，也有自己一套 client id 和 secret（`ANTIGRAVITY_CLIENT_ID`、`ANTIGRAVITY_CLIENT_SECRET`，
或者 `antigravity.clientId`、`antigravity.clientSecret`）——它给你的是走 Google 订阅的 Gemini 3、
Claude 和 GPT-OSS。四种情况 token 都存在 `~/.pi/agent/auth.json`——pi 有这个文件就用它，所以登录
一次就是登录一次。`/logout` 忘掉它。

不是选项的东西就是要说的话，`@某个文件` 会被读在这句话前面：

```bash
bin/pig "bin/pig 是干什么的？"                # 问一句，然后继续留在终端里
bin/pig @src/Thing.php "这为什么慢？"         # 先文件，再问题
bin/pig @screenshot.png "这里哪儿不对？"
```

图片就当图片传进去，所以一张截图不用经过工具调用就能到模型手上。`--` 结束选项解析——给那种
开头是横杠或者 `@` 的话用。

Ctrl+G 把 prompt 里现在的内容丢进 `$VISUAL` 或 `$EDITOR`，改完再塞回来——给那种写到一半发现要写
三段的消息用。模型还在回答的时候也能用：编辑器拿着终端，回答在它背后继续到。

`--model` 不用写全 id，写一部分就行——`--model sonnet`、`--model 'opus 4.1'`——`--model sonnet:high`
还能顺手把思考档位一起设了。`--models` 把全部列出来，带上下文窗口和输出上限；`--models gem pro`
再筛一遍，模糊匹配，provider 和 id 当一整串来搜。

内置 171 个模型：Anthropic 的、OpenAI 自家走 Responses API 的、Gemini，加上 Groq、Cerebras、xAI、
Zai、Mistral——这五家说的都是 OpenAI chat-completions。设好对应的 `*_API_KEY`，`--model` 就能指过去。

GitHub Copilot 的十九个、Google Cloud Code Assist 的五个也在里面——都是订阅提供的模型，用的还是
原厂那些 id。所以 `gpt-5`、`gemini-2.5-pro` 一个名字对两个模型：裸写指的是原厂那个，订阅那边要写
`--model github-copilot/gpt-5` 或 `--model google-gemini-cli/gemini-2.5-pro`。

`/label 重构之前` 给当前这个点起个名字，`/tree` 会把名字连着当时说的话一起显示——用的是 pi 自己的
label entry，所以在哪边起的名字另一边都看得见。

`/tree` 回到对话里更早的某个点，从那儿接着聊。没走的那条路还留在会话文件里——所以往回走不花什么代价，
而且随时能再走回来。它还会问要不要把正在离开的那条分支总结一份：这样探索了一小时的东西会跟着你到新分支
上，而不是被留在原地。

会话用的是 **pi 自己的格式**、自己的目录布局——所以一边开的对话另一边能直接打开，`--resume`
会把 `~/.pi/agent/sessions/` 里的会话和 pig 自己的一起列出来。接着聊一个 pi 的会话，就按那个格式
追写回那个文件。

接着聊会回到那段对话当时用的模型和思考档位，而不是新开一段会用的那个。命令行上写了 `--model`
的话还是 `--model` 说了算。

`/changelog` 按版本列出改动，升级之后新增的那几条会自动显示一次（接着上次的会话时不显示）。

`/export` 把整段对话导出成一个自包含的 HTML 文件——markdown 渲染好、代码高亮好，里面一行 JS 都没有。

一轮因为服务端忙而失败——429、503、半路断掉的 socket——会**等一下再发一次**：从两秒开始翻倍，
最多三次，屏幕上写着服务端说了什么、还剩几秒、按 esc 停。因为对话超出模型上下文窗口而失败是另一回事，
也按另一回事处理：先总结再重发，因为同样那个请求四秒后一样长。设置里 `retry.enabled: false` 关掉前者。

pig 不认识的 provider 写在 `~/.pig/models.json` 里——自己的机器、代理、本地服务——之后它的模型
在所有用得到内置模型的地方都能用，`--model`、`/model`、`--models` 都算：

```json
{ "providers": { "my-box": {
  "baseUrl": "http://192.168.1.9:8080/v1", "apiKey": "MY_BOX_KEY",
  "api": "openai-completions",
  "models": [{ "id": "qwen3-coder", "name": "Qwen3 Coder", "reasoning": false,
               "input": ["text"], "contextWindow": 262144, "maxTokens": 32768 }] } } }
```

`apiKey` 如果有同名环境变量，那它就是**变量名**，key 本身不用写进文件。`api` 是
`openai-completions`、`openai-responses`、`anthropic-messages`、`google-generative-ai` 之一，
写在 provider 上或每个模型上都行；`authHeader: true` 会把 key 以 `Authorization: Bearer …`
发出去，给需要这样的代理用。文件里有问题的地方会打印出来然后跳过——同一个文件里没问题的部分、
以及所有内置模型，照常可用。格式就是 pi 的，pig 自己没有这个文件时会读 pi 的
`~/.pi/agent/models.json`。

设置放在 `~/.pig/settings.json`，项目可以用 `.pig/settings.json` 覆盖。主题、模型、思考档位选过一次
就记住了。`/settings` 把会话里能改的都列出来——主题、思考档位、要不要画出思考过程、要不要画出图片、
跑到一半打的话是一条一条递过去还是一起递、自动压缩、自动重试——每一项后面写着现在是什么。回车改光标那一行，列表不关；改完按 esc。

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

hook 也能**说话**——`$pi->sendMessage('build', '测试挂了')` 把一条消息放进对话里让模型读到，
`display: false` 表示这条只给模型看、不占屏幕，`triggerTurn: true` 则让 agent 当场回答它。
hook 还能用 `registerMessageRenderer()` 自己画自己的消息。另外 `$pi->appendEntry('permissions',
$data)` 往会话文件里写一条模型永远看不到的东西——重启之后还在的 hook 状态，不占上下文。

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

一共三条进去的路，终端只是默认那条。`-p` 说一句、打出答案、退出——给 shell 脚本或者管道用：

```bash
bin/pig -p "一句话说清这个仓库是干什么的" | pbcopy
bin/pig -p @error.log "出了什么问题？"
```

`--mode json` 是同一次运行，只是把每个事件打到标准输出：终端会画的那份流式过程，给想解析它的
东西用。告警一律走标准错误，所以那些行全都是 JSON。

```bash
bin/pig --mode json -p "..." | jq -r 'select(.type=="message_update") | .delta.delta // empty'
```

`--mode rpc` 把 JSON 行当命令读，自己不会结束：给编辑器，或者任何从代码里驱动 pig 的东西用。

```bash
echo '{"id":"1","type":"prompt","message":"bin/pig 是干什么的？"}' | bin/pig --mode rpc
```

二十三条命令——prompt、插话、打断、换模型、压缩上下文、跑一条 shell 命令、在对话树上走、导出——
回答以终端里画的那同一套流式事件发出来。hook 依然能**问**：问题作为一行 `hook_ui_request` 发出去，
这次工具调用就停在那儿等宿主回答——和终端里是同一个把戏，换了条传输通道。

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

`PIG_TIMING=1 bin/pig` 会把启动每一步各花了多少时间打到 stderr 上，在终端界面接管之前。

移植规则、已定下的决策、以及目前踩到的坑，见 [CLAUDE.md](CLAUDE.md)。

## 上游对照

以 pi 的 `d0a4c37`（2026-01-02）为基准移植——就是 `agent-loop.ts` 还只有 418 行、harness 尚未
膨胀的那个快照。类名、文件名、方法名都跟上游对齐，这样 diff 新版上游时是机械操作。

## 许可

MIT
