# pig — PHP AI Agent

[English](README.md) · **简体中文**

[pi](https://github.com/earendil-works/pi) 的 PHP 移植。pi 是一个 agent harness，以核心极小著称。
pig 保持相同的架构和文件划分，面向 PHP 8.3，**零运行时依赖**——不用 Guzzle、不用 ReactPHP、不用
amphp、不用 ncurses，只用标准库。

> **状态：能跑了。** `bin/pig` 就是一个可以在终端里对话的 coding agent：流式回答、工具的
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
| `pig/agent-core` | `Pig\Agent\` | 带工具调用、工具入参 JSON Schema 校验和状态管理的 agent 循环 —— **已完成** |
| `pig/tui` | `Pig\Tui\` | 差分渲染的终端 UI —— **已完成** |
| `pig/coding-agent` | `Pig\CodingAgent\` | coding agent —— **工具、系统提示、交互式 CLI、会话持久化、上下文压缩、模型切换、skills、hooks、自定义工具、三种模式(终端 / print / RPC)已完成** |

`pig/async` 在上游没有对应物：JavaScript 自带事件循环，PHP 没有。它的存在是为了让**一次
`stream_select()` 能同时等模型的 socket 和键盘**——这正是「流式输出途中能打断、能继续打字」
所依赖的前提。

**走代理**——有些网络不走代理根本连不上 provider：

```bash
bin/pig --proxy socks5://127.0.0.1:7891      # 或者 http://127.0.0.1:7890
https_proxy=http://127.0.0.1:7890 bin/pig    # 或者 all_proxy，或者 settings 里的 proxy.url
```

支持 HTTP CONNECT 和 SOCKS5，代理要求账号密码也能带。**TLS 是在隧道打通之后才开始的**，校验的是
provider 的证书，所以代理只负责搬字节、读不到内容；域名是交给代理去解析的，因为需要代理的网络里
本地 DNS 往往也是坏的。回环地址永远直连，`no_proxy` 和 `proxy.bypass` 可以再加，`--no-proxy` 全部
关掉。

`CodingAgent::session()` 把整个启动过程变成一次调用：解析模型和 thinking 级别、加载 skills / 上下文
文件 / hooks / 自定义工具、打开或新建会话文件，返回一个 session 加一串警告。`bin/pig` 只是它的调用
方，而不是它住的地方——于是那套优先级顺序可以被测试，而不是跑一遍用眼睛看。

另一个同名但完全不同的东西：`Agent\StreamProxy` 是把对话发给一台**网关服务器**，由它保管 provider
的 key 并代为调用，走的是上游的 `/api/stream` 协议，所以为 pi 写的网关可以直接用。它默认不开启——
是一个你自己传进去的 `streamFn`——因为它等于把对话交给网关的运营方：对「不想让 key 落在笔记本上」的
团队这正是目的，对其他人则正是不该用它的理由。

工具调用在执行前会按工具自己的 JSON Schema 校验一遍：`Ai\Utils\JsonSchema` 是 draft-07 的一个
子集，错误信息沿用 AJV 的措辞——因为读这条报错的是**模型**，它要靠这句话自我纠正。遇到没实现的
关键字是忽略而不是判失败，所以给工具 schema 加一个关键字永远不会把工具搞坏。

## 跑一下

```bash
curl -fsSL https://raw.githubusercontent.com/owner888/pig/main/install.sh | sh
ANTHROPIC_API_KEY=sk-ant-... pig
```

这个脚本会先查 PHP 和扩展、再用 Composer 装、最后问你要不要把 Composer 的全局 bin 目录加进 PATH
——最后这步最容易漏，因为 Composer 一个字都不会提，然后 `pig` 就是 `command not found`。

直接用 Composer 也行，只是最后那步得自己来：

```bash
composer global require pigagent/pig
echo "export PATH=\"$(composer global config bin-dir --absolute):\$PATH\"" >> ~/.zshrc
exec $SHELL
```

想改 pig 本身就克隆下来，下面的内容两种装法完全一样，只是把
`pig` 换成 `bin/pig`：

```bash
git clone https://github.com/owner888/pig && cd pig && composer install
ANTHROPIC_API_KEY=sk-ant-... bin/pig
```

`--read-only` 去掉 edit、write、bash；`--theme light` 给浅色终端用；`--api-key <key>` 只对这一次
运行生效、不存盘；`-c`/`--continue` 接着上次聊，
或者单独写个 `-r`/`--resume` 从列表里挑一个——在那个列表里直接打字就是搜索，搜的是整段对话里
说过的话，不只是开头那一句。在那儿按 esc 是「不挑了，开个新的」，ctrl+c 是「不用了」，直接退出。
`--session <id>` 用 session ID 或文件路径直接恢复之前的会话（退出会话时会打印该命令）。
进去之后 `/resume` 也是同一个列表，同样能搜。`-h` 看所有旗标，`-v` 看版本。进去之后 `/help`
列出所有按键——包括输入框自己那些编辑键——和所有命令。`/reload` 可以在不重启 pig 的情况下实时热重载扩展、技能、自定义命令、工具和上下文规则文件（如 `CLAUDE.md` / `AGENTS.md`）；升级 pig 本体核心代码后仍需重启进程。按 shift+ctrl+d 写一份 debug log：当前这一帧、
每行实际占多少列、以及整段对话，报 bug 就附这个。

每次启动会在后台问 Packagist 一次「有没有更新的 pig」，有就在对话下面说一声，并提示运行 `pig update`
一键自更新。答案是「没有」、网络不通、或者中间出了任何别的问题，都一个字不说。平时随时可以运行 `pig update`
更新 pig 到最新版本（或 `pig update --models` 刷新模型目录）。`--no-update-check` 让这一次不问，`~/.pig/settings.json` 里写 `"update": {"check": false}` 就是
彻底关掉。`/changelog` 看改了什么；比你上次看到的版本更新的那几条，升级之后会单独给你看一次。

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

另外还有 `bin/pig-ai login`——同样这四种登录，但走纯命令行，给那种 ssh 上去装机的场合用；
`bin/pig-ai list` 列出它们。

那个目录里如果还留着老版本 pi 的 `oauth.json`，pig 第一次跑的
时候会把它搬过来，并且说清搬了哪几个 provider——旧文件是改名，不是删掉。

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

模型干活的时候照样能打字，这是事件循环存在的意义，键位和上游一样有两个：**Enter 是 steer**——消息
插在当前正在跑的工具后面，给「不对，是另一个文件」这种话用；**Alt+Enter（Option+Enter）排一条
follow-up**，等这一轮整个结束再发。排着的会显示在 prompt 上方，标着 `Steering:` 或 `Follow-up:`；
**Alt+Up** 把它们全部拿回编辑器里、什么都不打断；Escape 也拿回来，但顺便把这一轮停掉。

模型默认拿到四个工具——read、bash、edit、write，和上游一致；`--tools read,grep,find,ls` 直接指定
这一组，`--read-only` 换成只读的那四个。工具少是有意的：给模型七个，它每一轮都要花一部分精力在
「用哪个」上，而用 `bash` 里的 `rg` 搜索本来就是 prompt 让它做的事。

`--model` 不用写全 id，写一部分就行——`--model sonnet`、`--model 'opus 4.1'`——`--model sonnet:high`
还能顺手把思考档位一起设了。`--list-models` 把**有 key 的**那些列出来，带上下文窗口和输出上限；
`--list-models gem pro` 再筛一遍，模糊匹配，provider 和 id 当一整串来搜。`/model` 给的是同一份列表，
切到没有 key 的模型会直接被拒绝并说清是哪个，而不是等下一轮请求才失败。Ctrl+L 直接开这份列表；
不想开列表的话，Ctrl+P 往后跳一个模型，Shift+Ctrl+P 往前跳一个。

`--models` 把这一次会话**收窄**到其中几个，逗号分隔，之后 Ctrl+P 只在这几个里转：
`--models sonnet,haiku` 从 sonnet 开始，两个来回切；`--models 'anthropic/*:high'` 把 Anthropic
的模型全收进来，每个都设成 high。每一项可以是 glob，也可以是名字的一部分，结尾加 `:档位` 就是给
这一项单独设档。`/model` 给的也是这份收窄后的列表——列表和快捷键说的是同一件事。

内置 171 个模型：Anthropic 的、OpenAI 自家走 Responses API 的、Gemini，加上 Groq、Cerebras、xAI、
Zai、Mistral——这五家说的都是 OpenAI chat-completions。设好对应的 `*_API_KEY`，`--model` 就能指过去。

GitHub Copilot 的十九个、Google Cloud Code Assist 的五个也在里面——都是订阅提供的模型，用的还是
原厂那些 id。所以 `gpt-5`、`gemini-2.5-pro` 一个名字对两个模型：裸写指的是原厂那个，订阅那边要写
`--model github-copilot/gpt-5` 或 `--model google-gemini-cli/gemini-2.5-pro`。

`/label 重构之前` 给当前这个点起个名字，`/tree` 会把名字连着当时说的话一起显示——用的是 pi 自己的
label entry，所以在哪边起的名字另一边都看得见。

`/tree` 回到对话里更早的某个点，从那儿接着聊。它画的是**整棵**对话树、连分叉一起——所以「没走的那条路」
是一行可以把光标移上去的东西，而不只是文件里还留着：

```
  • user: port the tree selector
    • assistant: Here is what it does…
    ├─ user: actually, do the proxy first
    │  └─ assistant: Right — starting with CONNECT…
    └─ • user: no, keep going with the tree
          • assistant: Carrying on…
```

`•` 标的是你正在走的那条路。Ctrl+O 循环五种过滤（全部 / 不看工具结果 / 只看你说的 / 只看你起过名的 /
一点不漏），直接打字就是搜索——打了什么就显示在行上面那条 `Search:` 里，`l` 给光标那一行起名字。往回走时它会问要不要把正在离开的那条分支总结一份：
这样探索了一小时的东西会跟着你到新分支上，而不是被留在原地。回到**你自己说过的**某句话时，那句话会从
对话里退出来、回到输入框里，让你换个说法重新问。

会话用的是 **pi 自己的格式**、自己的目录布局——所以一边开的对话另一边能直接打开，`--resume`
会把 `~/.pi/agent/sessions/` 里的会话和 pig 自己的一起列出来。接着聊一个 pi 的会话，就按那个格式
追写回那个文件。

接着聊会回到那段对话当时用的模型和思考档位，而不是新开一段会用的那个。命令行上写了 `--model`
的话还是 `--model` 说了算。

`/changelog` 按版本列出改动，升级之后新增的那几条会自动显示一次（接着上次的会话时不显示）。

`/export` 把整段对话导出成一个自包含的 HTML 文件——markdown 渲染好、代码高亮好，里面一行 JS 都没有。
已经存在磁盘上的会话不用打开也能导：`pig --export <session.jsonl> [out.html]`。

`/bug [哪里出了问题]` 写一份 bug 报告——pig / PHP / 系统版本、模型和 provider（绝不含密钥）、最后一条
provider 错误、`/doctor` 的体检结果、最近几次崩溃，以及只有你点头才带上的对话记录——存到
`~/.pig/agent/bug-reports/`，复制到剪贴板，再打开一个已经预填好的 GitHub issue。不点那个链接，
什么都不会离开这台机器。pig 自己崩掉时会把崩溃记下来，下次启动会说一声，并告诉你用 `/bug` 报告。

一轮因为服务端忙而失败——429、503、半路断掉的 socket——会**等一下再发一次**：从两秒开始翻倍，
最多三次，屏幕上写着服务端说了什么、还剩几秒、按 esc 停。服务端自己说了配额几时恢复的话，就等它说的那么久、
不再翻倍——配额四十秒后才回来，两秒后再发就是再挨三次拒绝。超过一分钟就不等了：这一轮当场结束，
把服务端原话报给你——一个吃完午饭才继续的重试不算重试。因为对话超出模型上下文窗口而失败是另一回事，
也按另一回事处理：先总结再重发，因为同样那个请求四秒后一样长。总结到一半按 esc 也能停——不管是 `/compact`
叫起来的还是上下文满了自己起来的。设置里 `retry.enabled: false` 关掉前者。

pig 不认识的 provider 写在 `~/.pig/models.json` 里——自己的机器、代理、本地服务——之后它的模型
在所有用得到内置模型的地方都能用，`--model`、`/model`、`--list-models` 都算：

```json
{ "providers": { "my-box": {
  "baseUrl": "http://192.168.1.9:8080/v1", "apiKey": "MY_BOX_KEY",
  "api": "openai-completions",
  "models": [{ "id": "qwen3-coder", "name": "Qwen3 Coder", "reasoning": false,
               "input": ["text"], "contextWindow": 262144, "maxTokens": 32768 }] } } }
```

`apiKey` 如果有同名环境变量，那它就是**变量名**，key 本身不用写进文件；而一个全大写的名字、对应变量却没设，会被当成**没有 key**，启动时指名说出来，而不是把这个名字本身当 key 发给 endpoint。模型可以带一个 `"cost": { "input": …, "output": …, "cacheRead": …, "cacheWrite": … }` 块，单位是**每百万 token 多少美元** —— 每百万三美元写 `3.0`，不是 `0.000003`；不写就是免费，本地模型就是这样。价格写成非数字会被指名、该模型跳过，而不是在 `/session` 和状态栏里悄悄变成零成本。`api` 是
`openai-completions`、`openai-responses`、`anthropic-messages`、`google-generative-ai` 之一，
写在 provider 上或每个模型上都行；`authHeader: true` 会把 key 以 `Authorization: Bearer …`
发出去，给需要这样的代理用。文件里有问题的地方会打印出来然后跳过——同一个文件里没问题的部分、
以及所有内置模型，照常可用。格式就是 pi 的，pig 自己没有这个文件时会读 pi 的
`~/.pi/agent/models.json`。

设置放在 `~/.pig/settings.json`，项目可以用 `.pig/settings.json` 覆盖。主题、模型、思考档位选过一次
就记住了。`/settings` 把会话里能改的都列出来——主题、思考档位、要不要画出思考过程、要不要画出图片、
跑到一半打的话是一条一条递过去还是一起递、自动压缩、自动重试——每一项后面写着现在是什么。回车改光标那一行，列表不关；改完按 esc。

应用占用的按键——esc、ctrl+c/d/z、shift+tab、ctrl+p、ctrl+l、ctrl+o、ctrl+t、ctrl+g、alt+enter、alt+up——可以在
`~/.pig/agent/keybindings.json` 里挪位置，用的是 pi 的动作名和按键写法：
`{"app.model.select": "ctrl+m", "app.tools.expand": ["ctrl+e", "shift+ctrl+o"]}`。一条绑定**替换**
默认值，所以把 ctrl+o 挪走就把它让给了 tmux；`[]` 解绑；帮助里显示的是真正生效的键；文件里写错的
键或动作会在启动时在 shell 上报一行。

`~/.pig/commands/` 或 `.pig/commands/` 下的一个 md 文件就是一条斜杠命令：`review.md` 就是
`/review`，正文就是 prompt，`$1`、`$@` 由后面跟的参数填进去。这些和 hook 注册的那些在终端之外一样
好用——`pig -p "/review src/Foo.php"` 发的是那段 prompt，`pig -p "/deploy staging"` 跑的是 hook 的命令。

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

也正因如此，项目自己的 `.pig/` **不经你同意不会被加载。** pig 第一次打开一个带 `.pig/`（hooks、
tools、extensions、skills、commands 或 `settings.json`）或 `extensions/` 下有 PHP 的目录时，会先问
一句要不要信任这个项目，答案记在 `~/.pig/agent/trust.json`（信任一次父目录，它下面所有 checkout
都算）。未信任的项目 `.pig/` 全部不读，并在屏幕上说明；你自己的 `~/.pig/agent/` 永远是你的。
`/trust` 改保存的决定；没有终端可问的时候（`-p`、`--mode json|rpc`），没决定过的项目一律
**不信任**——在陌生仓库里跑脚本不会跑起那个仓库的 hooks。

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

扩展是 `~/.pig/agent/extensions/`、`.pig/extensions/` 或 `extensions/` 里的 PHP 文件（或带 `index.php` 的文件夹），
把 hook、命令和 tool 统一到一个入口里。Factory 收到的是继承了 `HookApi` 的 `ExtensionApi`，额外提供了 `registerTool()`。
`--no-extensions` 一个都不加载，`--extension <path>` 加载指定文件。

skill 就是一个带 `SKILL.md` 的文件夹。pig 读 `~/.pig/skills` 和 `.pig/skills`，同时也读
`~/.claude/skills`、`.claude/skills`、`~/.codex/skills`、`~/.pi/agent/skills` 和 `.pi/skills`——给别的
agent 写的、或者迁过来之前给 pi 写的 skill，在这儿都直接能用。两个目录里有同名 skill 是**覆盖**而不是
报错：`pig > pi > claude > codex`，项目目录盖过家目录，`--skills-dir` 盖过所有。`/skills` 列出找到了
哪些、各自来自哪个目录，启动时也会说某次覆盖是从哪个文件手里拿走这个名字的。`--no-skills` 一个都不加载，
跟 `--no-hooks`、`--no-tools` 对它们各自那摊一样。别的工具的那五个目录还能一个一个单独关掉，用的是上游
自己的设置键——`skills.enableCodexUser`、`enableClaudeUser`、`enableClaudeProject`、`enablePiUser`、
`enablePiProject`——`~/.claude/skills` 太大、不该整份摆到模型面前时就用这个。pig 自己那两个目录没有对应的
键：那两个归 `skills.enabled` 和 `--no-skills` 管，更细的用 `skills.ignoredSkills` 写 glob。

`Rpc\RpcClient` 是 `--mode rpc` 的另一端：启动 agent、发那二十二条命令、把事件交回来，每个方法都是
挂起自己的 fiber 而不是返回 promise——所以宿主读起来像一个会阻塞的程序，而实际上什么都没阻塞。

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

`--mode web` 基于纯 PHP 原生全双工 WebSocket 与非阻塞 HTTP 引擎启动浏览器交互聊天界面，零外部依赖（1:1 像素级复刻 `pi-web`，支持多工作区目录/会话导航抽屉、实时遥测状态栏与全双工流式 RPC）：

```bash
bin/pig --mode web
```

在终端交互模式下，你也可以随时敲 `/web` 在浏览器中直接唤起图形界面。

想关掉终端后它还在跑，就以守护进程方式启动：

```bash
pig web start -d              # fork 到后台；pid 和日志写在 ~/.pig/agent 下
pig web status                # 在跑退出码 0，没在跑退出码 3
pig web stop                  # 先 SIGTERM，3 秒后仍在就 SIGKILL
pig web restart --port 9000   # stop + start -d；--host 和 --port 都认
```

`pig web start` 不带 `-d` 就等同于前台的 `--mode web`。背后没有引框架：两次 `pcntl_fork()`、
`posix_setsid()`、一个 pid 文件——所以 `-d` 和 `stop` 需要 `ext-posix`，除此之外什么都没变。

浏览器里打开过的每个会话在聊天区上方都有一个**标签页**——用会话名或第一句话做标题，正在运行的标签页
会有脉冲动画圆点，`×` 关掉，`+` 新建——在浏览器中持久保存。Web Shell 采用上游 `pi-web` 的独立进程模型：
每个会话由进程池（`SessionPool`）中各自独立的 `pig --mode rpc` 子进程承载，支持多标签页真正并发跑任务，
流式输出与工具调用互不干扰。从终端里用 `/web` 唤起时，终端自身的会话进程与网页标签页相互独立。

会**提问**的 hook——`tool_call` 守卫里的 `confirm()`、`select`、`input`、`editor`——在 `--mode web`
启动时会在网页里问：问题以 RPC 模式同一条 `hook_ui_request` 经 WebSocket 发出，页面弹出对话框，答案
恢复停在那里的工具调用。Esc 就是不。从终端里用 `/web` 唤起的情况下，提问仍在终端里，因为两个地方问
同一个问题，多了一个。

**accounts** 按钮（或输入 `/accounts`）打开 `pig-antigravity` 扩展保存的 Antigravity 账号面板：哪个
Google 账号正在用、每个 token 还剩多久、使用 / 移除 / 轮换，以及当前账号各配额池的剩余与重置时间——
和 `/antigravity.usage` 打印的是同一组数字。所有改动走的是 `/antigravity.accounts` 同一套代码，所以
账号库和 `auth.json` 一起动。

二十三条命令——prompt、插话、打断、换模型、压缩上下文、跑一条 shell 命令、在对话树上走、导出——
回答以终端里画的那同一套流式事件发出来。hook 依然能**问**：问题作为一行 `hook_ui_request` 发出去，
这次工具调用就停在那儿等宿主回答——和终端里是同一个把戏，换了条传输通道。

`examples/ask.php` 是同一套东西，完全不带 UI：

```bash
ANTHROPIC_API_KEY=sk-ant-... php examples/ask.php "天为什么是蓝的？"
```

这行命令底下的每一层都是 pig 自己的：一个非阻塞 TLS socket、手写的 HTTP/1.1、边到边解的 SSE、
建在 `Fiber` 上的协程。回答途中按 Ctrl-C，走的就是 TUI 用的那条 abort 路径。

### 内置工具

pig 默认携带与 upstream 对齐的 4 个基础编程工具：`read`、`bash`、`edit`、`write`。
高级文件检索（`grep`、`find`、`ls`）可通过 `--tools` 开启：

```bash
bin/pig --tools read,bash,edit,write,grep,find,ls
```

网络搜索与网页抓取通过纯 PHP 原生扩展 `extensions/pig-web-search/` 提供——`web_search`（DuckDuckGo）、
`fetch_web_page`（服务端发来的 HTML 转成文字）、`browse_web_page` 与 `/search <query>`——核心 Agent
仍然只有四个工具。`browse_web_page` 专治靠 JavaScript 拼出来的页面：用本机已装的 Chrome 以 headless
模式启动，通过 pig 自己写的 WebSocket 客户端说 DevTools Protocol，等页面加载完，再从活的 DOM 里把文字
读出来。没有 Puppeteer、没有 driver、不下载任何东西——大约两百行 PHP；没装 Chrome 的机器会被明确告知，
而不是在三层之下莫名失败。

**MCP 服务器**由 `extensions/pig-mcp/` 从 `~/.pig/agent/mcp.json` 与 `<project>/.pig/mcp.json`
读取——文件与键名都是 pi 的，给 pi 写的配置原样可用——每个服务器的工具以 `mcp__<server>__<tool>`
的名字交给模型，走的是和 `bash` 一样的 hook 与权限门。支持 stdio 与 streamable HTTP，`env` 和
`headers` 里的 `${VAR}` 与 `!command`。`/mcp` 在终端里是一个管理界面（工具列表、重连、曝光方式、
启用/停用，改动写回它来自的那个 `mcp.json`），没有终端时打印状态；shell 里有 `pig mcp add|remove|list`。
没有 `Authorization` 头的 HTTP 服务器走 OAuth 登录（发现、动态客户端注册、PKCE、本机回调），
令牌存在 `~/.pig/agent/mcp-auth.json`，连接会自己续期；授权服务器不支持动态注册的（GitHub 就是），
在 `"oauth": { "clientId", "clientSecret", "callbackPort" }` 里给一个预注册的客户端；授权服务器
公布错了或根本没公布的，用 `"authServerMetadataUrl"` 直接指定元数据文档。凭据按服务器名加 URL 存，
同一个 URL 的两个服务器可以是两个账号；`iss` 指向别的授权服务器的 code 在交换之前就被拒（RFC 9207）；
服务器要求更多 scope 时，新令牌保留已经拿到的 scope。`/mcp login|logout`
与 `pig mcp login|logout` 是入口。提供 resources 的服务器会带来 `list_mcp_resources`、
`list_mcp_resource_templates` 与 `read_mcp_resource` 三个工具；服务器的日志写到 `~/.pig/agent/mcp.log`。

**codemode**，PHP 版（`extensions/pig-codemode/`）：服务器用默认曝光方式时，模型只看到一个
`codemode` 工具，它的描述里带着服务器各工具的 PHP 签名；模型写一段脚本——
`$tools->mcp__gh__search_code([...])` 调工具，`parallel([...])` 并发互不依赖的调用，用普通 PHP
过滤结果，`return` 它想看的部分。三个慢工具只花一个的时间，六十个工具的服务器只占一份目录，大结果
只占脚本留下的那部分。脚本跑在一个带 `disable_functions`、`open_basedir` 和 `memory_limit` 的
子进程 `php -n` 里；嵌套调用走 pig 自己的工具和 hook，权限门在脚本里一样生效。`"exposure": "deferred"` 的服务器，其工具先不声明给模型，等模型用 `tool_search`（按名字、描述、
schema 做 BM25 检索）找到再加载——六十个工具的服务器因此只占八个工具的上下文。工具描述很短——
开头几句、每个全局函数一行、再加 `extensions/pig-codemode/CODEMODE.md` 的路径，模型需要细节时自己去读；
脚本调了一个不存在的工具，错误里会列出最接近的几个名字。

```json
{ "mcpServers": {
  "fs": { "command": "npx", "args": ["-y", "@modelcontextprotocol/server-filesystem", "."] },
  "github": { "url": "https://api.githubcopilot.com/mcp/", "headers": { "Authorization": "Bearer ${GITHUB_TOKEN}" } }
} }
```

## 环境要求

PHP >= 8.3，需要 `ext-json`、`ext-mbstring`、`ext-openssl`，终端 UI 还需要 `ext-pcntl`。
唯一必须的外部程序是 `stty`。`find` 和 `grep` 两个工具需要 `fd` 和 `rg`，机器上没有的话 pig 会在
第一次用到时下载到 `~/.pig/tools/`（`PIG_OFFLINE=1` 可以关掉）。

`Fiber` 是 PHP 8.1 引入的，整套地基都建在它上面，所以 8.1 是绝对下限。实际声明的下限定在 8.3：
8.1 已经 EOL，8.2 的安全支持 2026 年底到期。

**运行时零 Composer 依赖**，这里面包括 provider 协议本身：HTTP 客户端、event-stream 解析器和五个
provider 全是自己写的。上游一行都不用写——Anthropic / OpenAI / Google 的 SDK 替 pi 干了这件事。这
不是偏好：PHP **根本没有** OpenAI 和 Gemini 的官方 SDK，而官方 Anthropic PHP SDK 的流式是同步迭代
PSR-18 响应体，会把那一次同时盯着模型 socket 和键盘的 `stream_select()` 堵死——连带堵死 Esc 打断和
流式途中继续打字。完整的账在 CLAUDE.md 里，而且这是一个**带日期的判断**，不是原则。

## 开发

```bash
composer install
php test/lint.php      # 对每个文件跑 php -l
vendor/bin/phpunit
php test/live.php      # 对真实 API 跑一遍 provider —— 要花钱、要密钥
php scripts/generate-models.php   # 从 models.dev 更新模型注册表
```

模型注册表的行是生成出来的,跟上游一样:`scripts/generate-models.php` 读 models.dev、只留协议已经
移植的那些模型、就地重写 `Ai\Models` 里的表 —— `--dry-run` 只打印不写,而且每次都会报出新增、消失
和改动了哪些字段,这样一次重新生成是可以读的而不是只能信的。表以外的东西 —— base URL、两张订阅目录、
撞名规则 —— 都是手写的,不会被动。

`test/live.php` 不属于测试套件、也不会自己跑：每个 provider 十二个场景打真实 API，因为只有这样才能
回答「provider 会不会**接受** pig 发出去的东西」—— 重放的 thinking 签名、为中断的调用编造出来的 tool
result、从别的 provider 接过来的对话、超过窗口的 prompt。密钥从 pig 自己读的地方读，所以已经登录过的
机器什么都不用配；`php test/live.php anthropic google` 指定跑哪几个，`php test/live.php
google/<模型 id>` 指定跑哪一个模型。模型 id 必须是 registry 里有的，而 registry 钉在上游锚点那一版；
锚点之后发布的模型写进 `~/.pig/models.json`，之后这里和 `bin/pig` 都能用。每次调用都压在几百 token 以内。

验证要对着**下限版本**跑，而不是只对着你本机的 PHP：8.3 会在解析期就拒绝 8.4-only 语法，
而顺手用上一个下限没有的特性太容易了。

`PIG_TIMING=1 bin/pig` 会把启动每一步各花了多少时间打到 stderr 上，在终端界面接管之前。

移植规则、已定下的决策、以及目前踩到的坑，见 [CLAUDE.md](CLAUDE.md)。

## 上游对照

以 pi 的 `d0a4c37`（2026-01-02）为基准移植——就是 `agent-loop.ts` 还只有 418 行、harness 尚未
膨胀的那个快照。类名、文件名、方法名都跟上游对齐，这样 diff 新版上游时是机械操作。

## 许可

MIT
