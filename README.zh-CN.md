# pig — PHP AI Agent (智能体)

[English](README.md) · **简体中文** · **官方网站与文档：** [pigagent.dev](https://pigagent.dev)

[pi](https://github.com/earendil-works/pi) 的完整高性能纯 PHP 移植版。相同架构、相同目录结构，专为 PHP >= 8.3 设计，**运行环境零依赖（Zero Runtime Dependencies）**—— 无需 Guzzle、无需 ReactPHP、无需 amphp、无需 ncurses，更不需要 Node.js。完全基于 PHP 标准库精心打造。

> **当前状态：100% 生产就绪。** `pig` 拥有 sub-500ms（毫秒级）超快首帧启动速度，支持流式对话、实时工具调用回显、代码修改可视化 Diff、按 Escape 实时中断、生成期间打字追问队列，且完全双向兼容上游 `pi` 的会话文件（`.jsonl`）、登录凭据（`auth.json`）、自定义模型（`models.json`）、项目授权信任（`trust.json`）与全局设置（`settings.json`）。

---

## 核心特性

- **运行环境零依赖**：100% 纯 PHP 实现，仅需基础扩展 `ext-json`、`ext-mbstring`、`ext-openssl`、`ext-pcntl` 与 `ext-pcre`。
- **毫秒级秒开（`pig -c` < 500ms）**：基于 O(1) 会话树回溯优化与轻量级 mtime 首行探测，即使加载包含数万行历史对话的 70MB+ 超大历史会话也仅需不到 0.5 秒。
- **三种交互体验**：
  - **终端 TUI 交互模式**：基于差异化增量渲染的高性能 ANSI 终端界面，支持动态动画、可视化 Diff、快捷键自定义与原生输入法（IME）光标防跳。
  - **持久化 Web UI 界面与 PTY 工作台（`pig web start -d` / `/web`）**：常驻后台守护进程，单物理长连接多 Tab 并行会话、内置 xterm.js 原生 PTY 终端抽屉、SSH 远程节点工作台与 SFTP 文件浏览器。
  - **CLI 命令行与自动化集成**：单次快速回答模式（`-p`）、JSON 事件行流式模式（`--mode json`）、长生命周期标准双向通信管道（`--mode rpc`）与 MCP server 模式（`--mode mcp`，让别的 agent 把 pig 当工具调用）。
- **完善的纯 PHP 内置扩展生态**：
  - `pig-antigravity`：Google Antigravity 免费商用模型支持、多账号轮转管理、429 自动换号无缝续跑、`/antigravity.usage` 额度面板、`generate_image` 生图工具与 `google_search` 搜索增强工具。
  - `pig-web-search`：DuckDuckGo 实时网络搜索（`web_search`）、网页文本内容提取（`fetch_web_page`）与无头 Chrome 动态渲染（`browse_web_page`）。
  - `pig-computer`：防检测无头浏览器自动化控制，支持精准鼠标移动、点击、滚动、文本键入、高清截图与按域名持久化 Cookie。
  - `pig-vless`：最简 VLESS 入站（仅 TCP，可选 TLS），让手机（Shadowrocket、v2rayNG）通过跑 pig 的这台机器上网。
  - `pig-codemode`：PHP 原生沙盒批量执行代码模式（`open_basedir`、`disable_functions`），成倍降低长上下文消耗。
  - `pig-mcp`：原生 Model Context Protocol 客户端，支持标准 stdio 与可流式 HTTP MCP 服务，集成动态 OAuth 授权认证。
- **工业级故障隔离与日志**：借鉴 Workerman 设计的结构化边界故障隔离（Fault Isolation），底层 Socket/SSL 瞬态断连自动重试，以及统一的五级彩色日志工具（`Pig\Logger`）。

---

## 安装与快速上手

### 快速一键安装（推荐）

```bash
curl -fsSL https://pigagent.dev/install.sh | sh
```

该安装脚本会自动检测您的 PHP 版本（>= 8.3）与必需扩展（macOS 下缺少 PHP 且检测到 Homebrew 时提示一键代安装）；若系统未安装 Composer 将通过官方程序（纯 PHP 方案）自动自举安装；随后通过 Composer 全局安装 `pigagent/pig`，并自动帮您将 Composer 全局 bin 路径加入 `PATH` 环境变量。

### Composer 全局安装

```bash
composer global require pigagent/pig
echo "export PATH=\"$(composer global config bin-dir --absolute):\$PATH\"" >> ~/.zshrc
exec $SHELL
```

### 源码克隆安装

```bash
git clone https://github.com/owner888/pig.git && cd pig
composer install
./bin/pig
```

### 一键升级更新

```bash
pig update               # 只更新 pig 本体（包会被跳过，并会提示）
pig update --extensions  # 更新已安装的包，以及内置扩展的副本
pig update --all         # 更新 pig 和全部包
pig update <source>      # 只更新一个包
pig update --models      # 刷新并重新对齐模型目录
```

### 包管理

扩展、技能、提示模板、主题可以打成一个**包**一起分发——和 pi 一样，可以是 Composer 包、git 仓库或本地目录，Composer 对应 pi 的 npm：

```bash
pig install composer:user/pig-tools              # composer require 到 ~/.pig/agent/composer
pig install composer:user/pig-tools@^1.2         # 版本约束；写确切版本（@1.2.3）就是钉住
pig install git:github.com/user/pig-tools        # 克隆到 ~/.pig/agent/git/github.com/user/pig-tools
pig install git:github.com/user/pig-tools@v1     # 钉住：update 只对齐到 v1，不会往前走
pig install https://github.com/user/pig-tools    # URL 按 git 处理
pig install ./my-tools                           # 原地加载，不拷贝
pig install git:github.com/user/pig-tools -l     # 写进项目的 .pig/settings.json（需要先信任项目）
pig list                                         # 两个作用域各配置了什么、装在哪
pig remove git:github.com/user/pig-tools
pig config                                       # 逐个开关包里的资源（Tab 切到项目覆盖）
pig -e git:github.com/user/pig-tools             # 只在这一次运行里试用一个包，不写 settings
```

一个包就是一个目录，里面有 `extensions/`（`.php` 文件，或带 `index.php` 的文件夹）、`skills/`、`prompts/`、`themes/` 中的任意几个；或者在 `composer.json` 里显式声明：

```json
{
  "name": "user/pig-tools",
  "keywords": ["pig-package"],
  "extra": {
    "pig": {
      "extensions": ["./src/Extension.php", "src/more/*.php", "!src/more/legacy.php"],
      "skills": ["./resources/skills"],
      "prompts": ["./prompts/*.md"],
      "themes": ["./themes/*.json"]
    }
  }
}
```

#### 上架一个包

[pigagent.dev/packages](https://pigagent.dev/packages) 对应 pi 的 npm 关键字 `pi-package`，只是换成 git：
所有打了 GitHub topic `pig-package` 的仓库都会被收录，不用登记，没有审核。

1. 把包放进一个独立的 GitHub 仓库——上面的约定目录，或带 `extra.pig` 的 `composer.json`。需要第三方库的包自己把 `vendor/` 提交进去。
2. 确认能装：`pig install git:github.com/user/repo`，再 `pig list` 看资源有没有被识别。
3. 在仓库页面（About → Topics）加上 topic `pig-package`。列表每小时刷新；卡片上的描述、star、最后推送时间都来自 GitHub，所以仓库 description 就是卡片文案。
4. 也可以再提交到 [Packagist](https://packagist.org)，这样就能用 `pig install composer:user/repo` 安装并带版本约束——相当于 pi 的 `npm publish`。这样发布的包把依赖写在 `require` 里，不提交 `vendor/`。

`composer.json` 里可选的 `extra.pig.image` / `extra.pig.video`（仓库内路径或 `https://` 地址）给卡片加预览，pig 本体忽略它们。去掉 topic 就下架。只收录 GitHub——别的 git 托管上的包能装，但市场里搜不到。

settings 里的条目可以收窄要加载的内容，语法与 pi 相同——省略某类=全部加载，`[]`=一个不要，`!glob` 排除，`+path` / `-path` 精确增减一个文件：

```json
{ "packages": [{ "source": "git:github.com/user/pig-tools", "extensions": ["!extensions/legacy.php"], "skills": [] }] }
```

`composer:` 包用 Composer 安装（需要在 PATH 上），每个作用域共用一个 Composer 项目——`~/.pig/agent/composer`，加 `-l` 时是 `.pig/composer`。这个项目的 `composer.json` 用 `replace` 声明替代 `pigagent/pig`，所以依赖 pig 的包拿到的是正在运行的 pig，而不会再装一份；同时禁用 Composer 插件。包的依赖来自这个项目的 `vendor/`；两个包要求同一个库的不兼容版本时，Composer 在安装时就会拒绝。`pig update` 会重新 require 没钉住的包——有约束就在约束内更新，没有约束就更新到最新版。

pig 不会在 git 包和本地包里跑任何包管理器：需要第三方库的这类包自带 `vendor/`（有 `vendor/autoload.php` pig 会 require），`Pig\*` 由宿主提供——这类包里不要 `require` `pigagent/pig`。包只支持 PHP；`npm:` 来源会被按名拒绝。

---

## 使用指南

### 1. 终端交互模式（默认）

在项目所在目录中直接启动 `pig`：

```bash
pig
```

- **配置 API Key**：启动时通过环境变量传入 `ANTHROPIC_API_KEY=sk-... pig`（`ANTHROPIC_AUTH_TOKEN` 以 Bearer 令牌发送），或用 `--api-key <key>` 配合 `--model` 为当前运行单独设置而不写入磁盘。
- **Google Vertex AI 与 Amazon Bedrock**：与 pi 一致，云平台自己的凭据在场时无需 Key。Vertex 读 `GOOGLE_CLOUD_API_KEY`，或 Application Default Credentials（`gcloud auth application-default login`、`GOOGLE_APPLICATION_CREDENTIALS` 指向的服务账号文件、或元数据服务器），并需 `GOOGLE_CLOUD_PROJECT` 与 `GOOGLE_CLOUD_LOCATION`。Bedrock 读 `AWS_BEARER_TOKEN_BEDROCK`，或 AWS SDK 的凭据链——`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`、`AWS_PROFILE` 与 `~/.aws`（assume role、`credential_process`、SSO）、web identity、ECS/EKS 与 EC2 角色——区域取 `AWS_REGION` 或 profile。选模型写作 `google-vertex/gemini-2.5-pro` 或 `amazon-bedrock/us.anthropic.claude-sonnet-4-5-20250929-v1:0`。
- **Azure OpenAI 与 Radius**：与 pi 一致。Azure 读 `AZURE_OPENAI_API_KEY`，端点取 `AZURE_OPENAI_BASE_URL` 或 `AZURE_OPENAI_RESOURCE_NAME`（`https://<name>.openai.azure.com/openai/v1`），API 版本取 `AZURE_OPENAI_API_VERSION`（默认 `v1`），部署名与模型不同时写在 `AZURE_OPENAI_DEPLOYMENT_NAME_MAP`（`gpt-5.4=prod,o3=reasoning`）；选模型写作 `azure/gpt-5.4`。Radius 网关读 `RADIUS_API_KEY`，模型如 `radius/balanced`。
- **pi 在这些 API 上内置的其余 provider**：各读 pi 的 Key 变量，选模型写作 `<provider>/<id>`（裸 id 指模型厂商自己的 provider）。DeepSeek `DEEPSEEK_API_KEY`、OpenRouter `OPENROUTER_API_KEY`（`openrouter/moonshotai/kimi-k2.6`；有 key 时只列出这个 key 能用的模型，以及它的上下文长度和价格）、Vercel AI Gateway `AI_GATEWAY_API_KEY`、Together `TOGETHER_API_KEY`、Fireworks `FIREWORKS_API_KEY`、Baseten `BASETEN_API_KEY`、Hugging Face `HF_TOKEN`、NVIDIA `NVIDIA_API_KEY`、MiniMax `MINIMAX_API_KEY` / `MINIMAX_CN_API_KEY`（`minimax-cn`）、Moonshot `MOONSHOT_API_KEY`（`moonshotai`、`moonshotai-cn`）、Kimi For Coding `KIMI_API_KEY`（`kimi-coding`）、Meta `META_API_KEY`、OpenCode Zen 与 Go `OPENCODE_API_KEY`（`opencode`、`opencode-go`）、小米 MiMo `XIAOMI_API_KEY` 及其 Token Plan `XIAOMI_TOKEN_PLAN_CN_API_KEY` / `_AMS_` / `_SGP_`（`xiaomi-token-plan-cn` …）、通义 Token Plan `QWEN_TOKEN_PLAN_API_KEY`（`qwen-token-plan`、`qwen-token-plan-individual`）与 `QWEN_TOKEN_PLAN_CN_API_KEY`（`qwen-token-plan-cn`）、智谱国内编程套餐 `ZAI_CODING_CN_API_KEY`（`zai-coding-cn`）、Ant Ling `ANT_LING_API_KEY`。OpenRouter、Kimi、Meta、xAI 的登录没有移植，Key 可用。xAI 的模型（`XAI_API_KEY`）与 pi 一致走它的 Responses API。
- **Cloudflare Workers AI 与 AI Gateway**：`CLOUDFLARE_API_KEY` 加 `CLOUDFLARE_ACCOUNT_ID`，网关还要 `CLOUDFLARE_GATEWAY_ID`（pi 保存这两个 ID 的登录没有移植）；选模型写作 `cloudflare-workers-ai/@cf/openai/gpt-oss-120b` 或 `cloudflare-ai-gateway/claude-sonnet-4-6`。
- **分类器与图片模型**（`Pig\Ai\Models::classify()`、`Models::generateImages()`，供基于 `pig/ai` 写的代码调用）：TypeSafe 的 System One `TYPESAFE_API_KEY`（`typesafe/jev-latest`，以及 OpenRouter、Vercel AI Gateway、OpenCode Zen、Workers AI 上的 Jev 与 decision 模型），llama.cpp `llama-server` 上按下一 token 概率读答案的对话模型（`llama-cpp-classify`），以及 OpenRouter 的图片模型。它们不是对话模型，`/model` 不列出。
- **订阅账号直接登录**：在对话中输入 `/login`，支持免配置 Key 直接登录 Claude Pro/Max、GitHub Copilot、ChatGPT Plus/Pro（用于 `openai-codex` 模型，如 `openai-codex/gpt-6.1-sol`——浏览器回调到 `localhost:1455`，或在无浏览器的机器上用设备码）、Kimi Code（用于 `kimi-coding` 模型，设备码登录）或 Google Antigravity。Claude 登录与 pi 1.0 一致提供两种方式：浏览器回调到 `localhost:53692`（默认），或在无浏览器的机器上从 Anthropic 页面复制代码粘贴。凭据自动安全持久化于 `~/.pig/agent/auth.json`（或与 `~/.pi/agent/auth.json` 互通共享）。
- **恢复与续接历史会话**：
  - `pig -c` / `pig --continue`：毫秒级秒开接上当前目录下最新修改的历史会话。
  - `pig -r` / `pig --resume`：唤出历史会话选择器——支持模糊词、`"短语"` 或 `re:<正则>` 搜索；Tab 在本目录与全部项目之间切换，`Ctrl+S` 循环切换树状 / 最近 / 模糊排序，`Ctrl+N` 只看已命名会话，`Ctrl+D` 删除。
  - `pig --session <id|path>`：按会话 ID 前缀或文件路径打开会话；只在别的项目里找到的 ID 会询问是否分叉到当前项目。恢复的会话在它自己的工作目录里继续。
  - `pig --fork <id|path>` 把一个会话复制成当前项目的新会话；`pig --session-id <id>` 打开或新建该 ID 的会话；`pig --no-session` 运行但不保存对话。
- **模型选择与动态切换**：
  - `pig --model sonnet`、`pig --model antigravity/gemini-3.8-flash`，或 `pig --provider openai --model gpt-5.5`
  - `pig --model sonnet:high`（同时设置模型与思考强度）
  - 按 `Ctrl+L` 呼出模型选择菜单；按 `Ctrl+P` / `Shift+Ctrl+P` 快捷切换上一/下一模型。
  - `pig --list-models [query]`：查看当前已配置密钥的所有可用模型、上下文上限与定价。
- **生成期间实时交互与输入快捷键**：
  - **长按 `⌘`（Command）键 ~0.85s**：如同 **Blink Shell / iPadOS** 般在屏幕中央弹出快捷键速查面板（HUD），松开即自动消失，支持 TUI 终端与 Web 界面。
  - 按 **Shift+Enter**：输入框内**换行**（多行自由编写 Prompt 或粘贴代码块，绝不误发）。
  - 空闲时按 **回车（Enter）** 或 **Command+Enter**（`⌘+Enter` / `Ctrl+Enter`）：发送消息（Submit）。
  - 模型生成期间按 **Command+Enter**（或 **Ctrl+Enter**）：**排队追问（Follow-up）**（加入后续队列，等本轮完全结束后再自动处理）。
  - 模型生成期间按 **回车（Enter）**：**抢占转向（Steer）**（当前工具执行完立刻优先处理你的新指令）。
  - 按 **Alt+Up**：将已排队的待发送内容全部撤回编辑输入框。
  - 按 **Escape**：立即终止当前生成轮次或取消重试等待。
  - 按 **Ctrl+G**：将输入框内容调起外部编辑器（支持在 `settings.json` 中配置 `"externalEditor": "vim"`，支持 `$VISUAL` 或 `$EDITOR`，未配置时自动回退至系统可用编辑器）进行长文本编辑，保存即自动带回。
  - 编辑器具备上游的编辑键——撤销、可 yank / yank-pop 的 kill ring、向前删词、跳到指定字符——所有按键（包括编辑键）都可以在 `~/.pig/agent/keybindings.json` 里按 pi 的 action id 重新绑定。
- **常用会话控制命令**：
  - `/name <新标题>`：查看或修改当前会话标题（终端底部与 Web 界面即时联动更新）。
  - `/label <标签名>`：为当前会话树节点打上书签标记。
  - `/tree`：将整场会话各分支以树状图可视化展示，可随时回跳到任意历史分支。
  - `/fork`：选一条你发过的消息，把会话截至该消息之前的部分物理分叉成一个独立的新会话文件，并把该消息放回输入框改写重问；`/clone` 把当前会话原样复制成一个新会话文件继续。新文件的头部以 `parentSession` 记录来源文件。
  - `/compact`：手动触发会话上下文智能压缩摘要。
  - `/continue`：接着跑一次因 pig 停掉（崩溃、被杀、机器休眠）而中断的运行。打开一个中途停掉的会话时，会话下方会提示这一点，你不说就什么都不执行；停在中途的工具调用只有只读工具会重跑，其他的连同已经输出的内容告诉模型「被中断」。Web 界面显示同样的提示和一个「继续」按钮。
  - `/export [file.html|file.jsonl]`：将当前会话导出为离线单文件 HTML（自带语法高亮与折叠），或把当前分支导出为会话文件。
  - `/import <file.jsonl>`：把会话文件复制进当前项目并恢复它。`/share`：已登录 Radius（`/login radius`）时上传到 Radius，否则把 HTML 导出上传为 GitHub 私密 gist（需要 `gh`）；`sessionShareDestination`（`auto`、`gist`、`radius`，`/settings` 里也有这一项）指定去处。
  - `/scoped-models`：选择 `Ctrl+P` 循环的模型范围，并可保存为 `enabledModels`。
  - `/reload`：不重启进程，热重载所有扩展、Skills、提示模板、主题、快捷键、工具与项目上下文文件（`CLAUDE.md` / `AGENTS.md`）。声明了 PHP 类的扩展在重载后仍运行启动时加载的那个版本（PHP 无法卸载类）；文件已改动时重载会明确提示，重启 `pig` 即可生效。
  - `/doctor`：运行全面系统诊断，排查 PHP 扩展、网络端点、权限与外部工具依赖。

### 2. Web UI 界面模式（`pig web`）

`pig` 自带高颜值 Web 聊天界面，对齐 `pi-web`，支持多工作区目录树、多 Tab 并行对话、内联重命名/删除会话以及全双工流式通信：

```bash
# 前台启动（自动启动服务并在系统默认浏览器中打开页面）
pig --mode web
# 或在终端交互模式下随时键入 /web 启动并自动唤起浏览器

# 生产级后台持久守护进程（推荐）
pig web start -d              # 后台常驻启动（默认监听 127.0.0.1:8088，支持 --port / --host）
pig web status                # 查看守护进程运行状态与 PID
pig web restart               # 重启 Web 服务
pig web stop                  # 安全平滑终止 Web 进程
pig web password              # 设置或修改 Web UI 的密码

# macOS：做成登录服务，电脑重启后自动起来
pig web install               # launchd LaunchAgent（同样支持 --port / --host）
pig web uninstall             # 移除服务
```

`pig web install` 写入 `~/Library/LaunchAgents/dev.pigagent.web.plist` 并加载：登录时由 launchd 启动服务，进程退出会被自动拉起，工作目录是执行 `install` 时所在的目录，并沿用当时 shell 的 `PATH` 和代理设置。shell 里导出的 API 密钥不会写进 plist——请用 `/login` 登录或放在 `auth.json` 里。安装后 `start`、`stop`、`status`、`restart` 都通过 `launchctl` 操作；`stop` 只到下次登录或 `pig web start` 为止，换端口就再执行一次 `pig web install --port N`。

使用浏览器或手机访问 `http://localhost:8088`：
- **登录**：Web UI 能在你的机器上开终端、跑 agent，所以始终要求密码。第一次启动时 `pig web start` 会打印一次性设置码，在网页上填入设置码并设定密码即可（也可以用 `pig web password` 设置）。来自其他网站的请求一律拒绝。
- **本地交互式 PTY 终端抽屉**：随时按快捷键 <code>Ctrl+`</code> 或 <code>Cmd+`</code>（或点击顶栏终端图标）呼出集成 `xterm.js` 的全功能多标签页伪终端，原生支持 `vim`、`nvim`、`nano`、`htop`、`tmux` 等全屏按键控制。
- **SSH 远程节点工作台与 SFTP 文件管理**：点击顶栏 `🖥️ 节点` 管理远程服务器资产，SHA-256 主机指纹安全校验，`~/.ssh/config` 一键导入，远程交互终端（`ssh -tt`）与 SFTP 文件在线浏览/编辑（上限 512 KiB）。
- **自动唤起默认浏览器**：在终端 TUI 会话中敲 `/web` 会在端口绑定后自动唤起系统默认浏览器打开界面，无需手动复制 URL。
- **单长连接多 Tab 并行**：在同一页面内自由切换多个工作区与会话，后台任务互不干扰；新建会话可通过输入框上的 📁 胶囊自由选定任意工作目录（历史项目、路径补全或目录树浏览器）。
- **原生中英文双语一键切换**：支持顶部导航 `[中 / EN]` 即时无刷新切换界面语言，默认自适应浏览器首选语言。
- **翻译消息**（同 Telegram），在语言设置里：每条回答下有「翻译」按钮，把它译成界面语言（「显示原文」换回来）；打开「翻译整个对话」则每条回答一到就翻。已经是「不翻译」语言（默认为界面语言，也可自选）的回答不显示按钮。翻译由当前会话的模型来做，每次多一个请求；你自己发的问题也有按钮；工具输出和思考块不翻。
- **Claude 风格侧边栏**：顶部是新建会话、搜索（`⌘K`）、定时任务；下方是所有项目的会话，按今天 / 昨天 / 日期分组并标出所在目录；「查看全部」和搜索打开同一个面板，可搜标题和内容。鼠标悬停（或手机触屏点击）即可重命名（`✏️`）或删除（`🗑️`）。
- **定时任务**：布局和 Claude 应用一样。点侧边栏的「定时任务」看到卡片式的任务列表（可搜索、筛选、排序），「新建任务」→「与 pig 一起创建」（开一个新对话，由 pig 问你做什么、多久一次、什么时间）或「手动设置」：一段提示词，加一个或多个触发方式——每小时、每天、工作日、每周、单次或自定义 cron——再选权限（「自动」会替运行中的确认回答「是」，不停下等批准；「手动」等你确认）、模型，「高级设置」里是工作目录和思考强度。每个任务有自己的会话，任务一保存就出现在侧边栏（带时钟标记），点它打开任务详情页（历史记录、指令、重复频率、权限、「立即运行」和启用开关），每次运行都接着在里面进行，能看到上次运行的结果（到点时你正在这个会话里说话，就跳过这一次）；运行结束后会话旁会出现蓝点，打开后消失；「手动」权限的任务运行中遇到钩子提问会停下等你回答，侧边栏显示提示点。任务只在 Web 服务运行时执行——用 `pig web start -d` 常驻——机器休眠期间错过的运行会在醒来后补跑一次，守护进程停止时正在进行的运行会在它重新启动后从会话记录接着跑（停在中途的工具调用只有只读工具——`read`、`grep`、`find`、`ls`——会重跑；其他的连同已经输出的内容告诉模型「被中断、可能已执行一部分」，不会把命令重跑一遍）。保存在 `~/.pig/agent/scheduled-tasks.json`。也可以直接在对话里说——「每天早上 9 点看看 owner/repo 有什么更新，告诉我本地代码要不要跟」——TUI 和网页都行：`schedule` 工具（`builtin:schedule`）先把任务给你确认（网页里是对话中的一张卡片，「取消」Esc、「安排」⌘/Ctrl+Enter），再交给正在运行的 `pig web` 保存。
- **手机端深度适配**：专为移动端小屏幕（<768px）优化布局与触控响应（iPadOS visualViewport 动态防键盘顶飞页头）。
- **扩展 Web 面板与 Antigravity 账号抽屉**：页头直达 🔑 账号管理面板，随时查看各 Google 账号的配额池与重置倒计时；自定义扩展亦可通过 `registerWebPanel()` 注入面板，支持通过 🧩 扩展菜单在页头与侧边栏自由切换。

### 3. 非交互式命令行与管道集成

```bash
# 纯文本单次回答模式：直接将结果输出到 stdout 并退出
pig -p "简述本项目的架构设计" | pbcopy
pig -p @error.log "分析该崩溃日志的根本原因"
git diff | pig -p "审查这些改动"           # 管道输入的内容排在消息最前面

# 不启动会话，检查某个 provider 的凭据
pig auth check --provider anthropic

# JSON 结构化流式模式：将每轮事件以 JSON 行流式输出
pig --mode json -p "解析 index.php"

# 长生命周期标准双向 RPC 管道模式（适合外部编辑器或宿主调用）
pig --mode rpc

# MCP server 模式：把当前对话作为 MCP server 提供给别的 agent（唯一工具 `ask`）
pig --mode mcp                            # Streamable HTTP，http://127.0.0.1:8089/mcp
pig --mode mcp --mcp-host 0.0.0.0 --mcp-port 9000
pig --mode mcp --mcp-stdio                # stdin/stdout 上的 JSON-RPC 行，给会自己拉起子进程的宿主
pig --mode mcp --session <id>             # 在已有对话上继续（`-c` 取最近一个）
```

在对方 agent 的 MCP 配置里注册即可——Claude Code 是
`claude mcp add --transport http pig http://127.0.0.1:8089/mcp`，或
`claude mcp add pig -- pig --mode mcp --mcp-stdio`——它会得到一个 `ask` 工具，每次调用都是同一个
pig 会话里的一轮对话，背后是 pig 自己的工具、hooks 和扩展；一轮进行中再来的 `ask` 排队等，不会失败。详见[作为 MCP Server 提供服务](https://pigagent.dev/docs/latest/mcp-server)。

### 4. 给手机用的 VLESS 入站（`pig-vless`）

内置扩展 `pig-vless` 在 agent 旁边开一个 [VLESS](https://xtls.github.io/development/protocols/vless.html) 入站，让跑 Shadowrocket 或 v2rayNG 的手机通过 pig 所在的这台机器上网。只做 TCP、单 UUID、给了证书才走 TLS——手机能用的最小版本，不是 Xray。

```text
/vless start      # 开端口（不开口不监听）
/vless status     # 配置、状态，以及给手机的 vless:// 链接
/vless stop       # 关掉；会话结束也会关
/vless restart
```

第一个会话会把 `vless.listen`（`0.0.0.0:10086`）和自动生成的 `vless.uuid` 写进 `settings.json`；要 TLS 加 `vless.cert` / `vless.key`（PEM）。详见[让手机通过 pig 上网](https://pigagent.dev/docs/latest/vless)。

---

## 纯原生扩展生态（100% 零依赖）

`pig` 的所有扩展均采用纯 PHP 编写，**绝不引入任何 Node.js、npm 或外部 composer 依赖**：

| 扩展组件 | 命名空间 / 路径 | 核心能力说明 |
| :--- | :--- | :--- |
| **`pig-antigravity`** | `extensions/pig-antigravity/` | **完整的 Antigravity provider**——模型表、线路协议、Google 登录、模型路由——通过 `registerProvider()` 注册进核心，与 pi 0.71 删除内置后社区 `pi-antigravity` 扩展的做法一致。另含多账号管理、429 配额墙时在请求内自动换号（与 pi-antigravity 一致）、`/antigravity.usage`、`/antigravity.accounts`、`generate_image` 生图工具与基于 Google Search Grounding 的 `google_search` 搜索工具（`/antigravity.search`）。 |
| **`pig-web-search`** | `extensions/pig-web-search/` | 实时网络搜索（`web_search`）、网页文本抓取（`fetch_web_page`）、无头 Chrome 动态渲染（`browse_web_page`）、`/search <query>` 命令。 |
| **`pig-computer`** | `extensions/pig-computer/` | 防检测无头浏览器自动化（鼠标移动、点击、滚轮、键盘键入、高清截图与持久化 Cookie）。 |
| **`pig-llama`** · `builtin:llama.cpp` | `extensions/pig-llama/` | `llama.cpp` provider：模型来自运行中的 `llama-server` router，`/login llama.cpp` 填地址和 key，`/llama` 加载、卸载、下载模型（含 Hugging Face 搜索）。 |
| **`pig-codemode`** · `builtin:codemode` | `extensions/pig-codemode/` | 在安全沙箱子进程（`open_basedir`, `disable_functions`）中批量并行执行多工具代码，节省巨量上下文。 |
| **`pig-tool-search`** · `builtin:tool-search` | `extensions/pig-tool-search/` | `tool_search`：在扩展延后注册的工具（MCP 的 `deferred` 暴露方式）里做 BM25 检索，并加载命中的工具。 |
| **`pig-mcp`** · `builtin:mcp` | `extensions/pig-mcp/` | Model Context Protocol 原生客户端，连接标准 stdio 与 HTTP MCP 服务，支持动态 OAuth 换票（`mcp.json`）。 |
| **`pig-schedule`** · `builtin:schedule` | `extensions/pig-schedule/` | `schedule` 工具：聊天里直接设置定时任务，每次改动先确认，由运行中的 `pig web` 保存。pig 自有。 |
| **`pig-vless`** | `extensions/pig-vless/` | 挂在 agent 旁边的 VLESS 入站，手机通过这台机器上网。仅 TCP、单 UUID，给了证书就走 TLS。加载时把 UUID 和监听地址写进 `settings.json`，但要 `/vless start` 才监听（`stop`、`restart`、`status`）；`/vless` 打印 `vless://` 链接。 |

标了 `builtin:` 的五个是**内置扩展**，与 pi 一致（`schedule` 是 pig 自有的）：每次都从 pig 自身加载、排在所有扩展之后，`pig update` 不再把它们复制到任何地方。在 `extensions` 设置里写 `-builtin:mcp`（或 `!builtin:*`）关掉，项目里的 `+`/`-` 条目优先于你的，`pig config` 在 *Built-in* 分组列出它们，`-e builtin:<name>` 在 `--no-extensions` 下也能单独加载，`--no-mcp` 不加载 `mcp`。若另一个扩展注册了同名工具或命令，`codemode`、`tool-search`、`mcp` 会让位并给出警告。`~/.pig/agent/extensions` 里残留的旧副本不会加载，pig 会提示，删掉即可。

### 扩展能做什么

一个扩展是一个返回 `function (ExtensionApi $pi)` 的 PHP 文件（或带 `index.php` 的目录）。API 对齐 pi 的 `ExtensionAPI`：

| | |
| :--- | :--- |
| **自带 provider** | `registerProvider(new Provider(id, name, models, api: StreamApi, oauth: OauthFlow, envKeys, resold, apiKeyAuth: ApiKeyAuth, classifiers))`——模型进注册表、协议挂在 `Api::Extension` 后面、登录（OAuth 或 api-key 流程）进 `/login` 列表。`unregisterProvider()` 收回。 |
| **工具、命令、渲染器** | `registerTool()`、`removeTools()`、`registerCommand()`、`registerMessageRenderer()`、`registerLocale()`。 |
| **事件** | `on('…')`：会话生命周期、agent 循环、工具调用与结果、`context`，以及与上游一致的 provider 事件——`before_provider_request`（替换请求 payload）、`before_provider_headers`（原地改 header）、`after_provider_response`（状态码与 header）、`provider_stream_event`（每个原始流事件）——还有 `model_select`、`thinking_level_select`。 |
| **命令行参数** | `registerFlag('name', 'boolean'|'string', 说明, 默认值)` 声明 `--name`；handler 里用 `getFlag()` 读。 |
| **会话** | `getSettings()`、`getModel()`、`setModel()`、`getThinkingLevel()`、`setThinkingLevel()`、`sendUserMessage(text, 'steer'|'followUp')`、`sendMessage()`、`setLabel()`、`getCommands()`、`exec()`。 |
| **Web UI** | `registerWebPanel(id, title, render, action, icon, commands, placement, label)` 在 `pig web` 侧边栏或页头放一个面板（用户可以自己挪位置）：`render` 返回区块（文字、带按钮的列表、进度条、卡片、延迟加载的段落），由页面按自己的主题画出来；按钮的 `action` 会拿到一个 `$ui`，和 `$ctx->ui` 一样可以 `confirm`、`select`、`input`、`notify`——Antigravity 账号面板就是这样做的。`registerHttpRoute('/api/prefix', fn (path, req) => ['status', 'body'])` 用来应答自己的请求。 |
| **第二个凭据存储** | `Auth::useSecondStore(provider, read, renewed)`，给在 `auth.json` 旁边另存多账号的 provider 用。 |

---

## 统一日志系统（`Pig\Logger`）

`pig` 内置完全对齐 `OmniPHP\Logger` 规范的企业级静态日志工具，可在任何扩展、工具或代码中直接使用：

```php
use Pig\Logger;

Logger::info("会话已初始化", ['id' => $sessionId]);
Logger::debug("正在调用工具", ['tool' => 'bash']);
Logger::warning("网络发生抖动，正在安排自动重试...");
Logger::error("API 请求发生故障", ['error' => $e->getMessage()]);

// 性能耗时分析打点
Logger::time('benchmark');
// ... 执行核心业务 ...
Logger::timeEnd('benchmark');
```

- **5 个标准日志级别**：`VERBOSE`（蓝色）、`DEBUG`（青色）、`INFO`（绿色）、`WARNING`（黄色）、`ERROR`（红色）。
- **动态级别过滤**：通过环境变量 `PIG_LOG_LEVEL=debug` 或 `LOG_LEVEL=info` 自由切换。
- **自动按天轮转与保存**：持久化保存在 `~/.pig/agent/logs/pig-YYYY-MM-DD.log`，默认保留 5 天（可通过 `PIG_LOG_KEEP_DAYS` 配置）。
- **TUI 界面防污染保护**：在终端交互模式下自动静音控制台输出（`Logger::setConsoleOutput(false)`），确保磁盘完整记录日志的同时绝不干扰 TUI 渲染。

---

## 配置文件与完全互通兼容

`pig` 与上游 `pi` 在会话文件、登录凭据和自定义配置上保持 **100% 格式与目录互通**。agent 目录是 `~/.pig/agent`，设置了 `PIG_CODING_AGENT_DIR`（对应 pi 的 `PI_CODING_AGENT_DIR`）时以它为准；环境变量一律是 pi 的 `PI_*` 对应的 `PIG_*` 写法：

| 配置文件路径 | 用途说明 |
| :--- | :--- |
| `~/.pig/agent/settings.json` | 全局偏好设置（主题、默认模型、思考强度、自动压缩、自动重试）。 |
| `~/.pig/agent/auth.json` | 各提供商的 API Key 与 OAuth Token（与 `~/.pi/agent/auth.json` 互通共享）。 |
| `~/.pig/agent/models.json` | 在 pi 的 `~/.pi/agent/models.json` 之后读取，为 pi 声明的 provider 在这里同样可用；两个文件都有的 provider 整个以 pig 的为准。自定义 OpenAI 兼容端点或本地模型（llama.cpp、vLLM、Ollama），以及对内置 provider 的覆盖（`modelOverrides`）。`apiKey` 与 headers 用 `$NAME` / `${NAME}` 引用环境变量，用 `!command` 取命令输出；其余写法（包括裸变量名）一律按字面值使用。 |
| `~/.pig/agent/mcp.json` | 标准 MCP 服务配置（stdio 与 HTTP）。 |
| `~/.pig/agent/trust.json` | 项目资源信任授权记录。 |
| `~/.pig/agent/keybindings.json` | 快捷键自定义映射表。 |
| `~/.pig/agent/themes/` | 自定义主题 JSON 目录（内置 `dark`、`light`、`labra`，支持任意第三方主题）。 |
| `~/.pig/agent/prompts/` | 提示模板：`review.md` 即 `/review`，支持 `$1`、`$@`、`${1:-默认值}`、`${@:2}` 等替换。 |
| `~/.pig/agent/skills/`、`~/.agents/skills/` | 技能（含 `SKILL.md` 的文件夹）；受信任项目的 `.pig/skills` 以及直到仓库根目录的各级 `.agents/skills` 也会读取。 |
| `~/.pig/agent/SYSTEM.md`、`APPEND_SYSTEM.md` | 替换或追加系统提示（受信任项目 `.pig/` 下的同名文件优先）；`--system-prompt` / `--append-system-prompt` 只对本次运行生效。 |
| `~/.pig/agent/sessions/` | 遵循标准 `.jsonl` 格式的持久化会话树日志。 |

**提示缓存预热。** 工具运行时间超过提供商的缓存寿命（Anthropic 为五分钟）时，pig 会在缓存条目过期前，以 1 个 token 的上限重发上一次请求——只在预计能省下至少 $0.05 时才发——让下一次请求从缓存读取提示，而不是再付一次写入的全价。每次预热都记在会话文件里，计入 `/session` 的花费。全局 `settings.json` 的 `cacheWarming`（或 `/settings` 里的 *Cache warming* 一行）默认 `streaming`（agent 运行期间），可设为 `idle`（两次运行之间也预热，最多 30 分钟）或 `off`。扩展可以通过 `cache_warming_decision` 事件否决或强制每一次预热。`-p` 从不预热。

**缓存花了多少。** `showCacheMissNotices: true` 会在本该从缓存读取的提示被重新计费时（2 万 token 或 1 角钱以上）、预热时、以及压缩或分支摘要计费时各加一行提示；`/session` 显示重新计费的总额。其他沿用 pi 键名的显示设置：`quietStartup`（`true` 或 `header`）、`autocompleteMaxVisible`、`doubleEscapeAction`（`tree`、`fork`、`none`）、`treeFilterMode`、`markdown.codeBlockIndent`、`terminal.imageWidthCells`、`terminal.showTerminalProgress`（在终端标签页显示进度），以及覆盖 pig 自动检测结果的 `terminal.images` / `trueColor` / `hyperlinks`。

**按模型、按工具。** `modelThinkingLevels` 按 `provider/id` 记住思考等级（`/settings` 里也有这一行），`thinkingBudgets` 按等级替换 token 预算，`enabledModels` 是没输入 `--models` 时的范围，`defaultTools` 设定内置工具（`["read", "bash"]`，或 `["+grep", "-write"]` 这样的增减；`-name` 也能去掉自定义工具）。`/skill:name 参数` 把技能当作消息发送（`enableSkillCommands`）。`compaction.modelOverrides` 按模型设定压缩预算，`branchSummary.reserveTokens` 和 `branchSummary.skipPrompt` 设定分支摘要的，`warnings.anthropicExtraUsage: false` 关掉 Claude 订阅提醒。

**图片、shell、会话。** 来自 `read`、`@file`、提示或任何工具的图片会在进入对话时缩到提供方的限制以内（2000×2000，或模型自己的），需要缩小时依赖 PHP 的 `gd` 扩展；`images.autoResize: false` 原样发送，`images.blockImages: true` 一张都不发。`shellCommandPrefix` 在每条命令前先跑一行（比如 `shopt -s expand_aliases`），`sessionDir`、`PIG_CODING_AGENT_SESSION_DIR` 或 `--session-dir <dir>` 把所有项目的会话放进同一个目录。`defaultProjectTrust`（`ask`、`always`、`never`）决定还没被决定过的项目（`-a`/`--approve` 或 `-na`/`--no-approve` 只替这一次运行决定，什么都不保存），`collapseChangelog: true` 在升级后只显示一行而不是完整更新说明，`steeringMode` / `followUpMode`（`one-at-a-time` 或 `all`）决定排队消息怎么交出去，`httpProxy` 是 `--proxy` 和环境变量都没给时用的代理，`transport`（`auto`、`websocket`、`websocket-cached`、`sse`）决定怎么连 ChatGPT 的 Codex 后端——默认走为会话保留的 WebSocket，失败时退回 SSE——`websocketConnectTimeoutMs` 是它握手的时限。

**图表、参数、署名。** 消息里的 ```mermaid 代码块会画成 Unicode 图（流程图、状态图、类图、ER 图和时序图——pi 所用渲染器的纯 PHP 移植）；`markdown.mermaid` 可选 `streaming`（默认）、`final` 或 `off`。pi 的短参数全部可用：`-t`/`-xt` 选工具，`-nt`/`--no-tools` 不带任何工具启动，`-nbt`/`--no-builtin-tools` 不带内置工具，`-ns`、`-np`、`-nc`、`-ne` 分别不加载技能、提示模板、`AGENTS.md`/`CLAUDE.md` 和扩展，`-n`/`--name` 给会话命名。`--skill`、`--prompt-template` 和 `--theme` 为本次运行加载一个文件或目录（都可重复，`-e` 现在也可以，且 `-ne` 下 `-e` 仍会加载）；启动用的主题是 `--use-theme <name>`，`--no-themes` 除内置主题和 `--theme` 指定的之外不找任何主题。不加载 `~/.pig/agent/tools` 和 `.pig/tools` 里的工具文件现在是 `--no-tool-files`。同样来自 pi 的还有：`--provider`、`--system-prompt`、`--append-system-prompt`、`--tui-mode regular|fullscreen`、`--verbose`、`--offline`（启动时不联网，等同 `PIG_OFFLINE=1`）和 `--update-check`。内置模型列表会从 pigagent.dev 自动更新——每个 provider 最多四小时查一次，且只在比 pig 自带的更新时才使用；`PIG_MODEL_CATALOG_URL` 可指定别的目录服务器，设为 `off` 则关闭。`bash` 命令能通过 `PIG_SESSION_ID`、`PIG_SESSION_FILE`、`PIG_PROVIDER`、`PIG_MODEL` 和 `PIG_REASONING_LEVEL` 得知当前会话；在 Windows 上可以用 `--tools` 或 `defaultTools` 打开 `powershell` 工具。pig 会告诉 OpenRouter、NVIDIA NIM 和 OpenCode 调用方是 pig；`enableInstallTelemetry: false` 或 `PIG_TELEMETRY=0` 可关掉，pig 不向任何地方发送安装报告。

### 登录

`/login` 先问用哪种方式，与 pi 一致：**Sign in with an account**（账号）、**Sign in with an API key**（API Key）或 **Sign in with Radius**。API Key 列表包含所有内置提供商（Anthropic、OpenAI、Gemini、DeepSeek、OpenRouter、Groq、Moonshot、Z.AI……）和扩展注册的提供商，可搜索，每一行标出是否已配置及来源（`✓ env: OPENAI_API_KEY`、`✓ configured`）；Key 保存在 `auth.json`。自定义 OpenAI 兼容网关可直接通过 `/login <name>` 或 `+ Add custom provider...` 快速接入，向导会交互式提示录入 Base URL 和 API Key，并自动请求端点拉取模型写入配置。`/login <provider>` 直接进入该提供商。尚未选择模型时，登录后自动切到该提供商的默认模型。Amazon Bedrock、Google Vertex AI 和 Cloudflare 两个提供商只从环境变量配置。

账号登录——`/login`，或在没有终端 UI 的机器上用 `pig-ai login <provider>`——流程均与 pi 一致：

| 提供商 | 方式 | 说明 |
| :--- | :--- | :--- |
| **Anthropic（Claude Pro/Max）** | **浏览器**（默认）：pig 监听 `http://localhost:53692/callback`，打开 `claude.ai`，授权码自动回传。同时保留一个粘贴框——浏览器在另一台机器上时，把最终跳转的 URL 粘贴进去即可。**复制代码**（无头机器）：浏览器停在 Anthropic 自己的页面上，显示 `code#state`，复制粘贴。 | 令牌以 Claude Code 的身份发出——`claude-cli` User-Agent、两个 beta 头、工具名按 `Read`/`Bash`/`Edit`/`Write` 拼写——因为 Anthropic 就是把它签发给 Claude Code 的。53692 端口已在 Anthropic 注册，不可更改。 |
| **GitHub Copilot** | 设备码：pig 显示一个代码，你到 `github.com/login/device` 输入，pig 轮询直到通过。Enterprise 提示处留空即 `github.com`。 | 登录后自动为账号开通 Claude 与 Grok 系列模型。 |
| **OpenAI（ChatGPT Plus/Pro）** | **浏览器**（默认）：pig 监听 `http://localhost:1455/auth/callback`（设置了 `PIG_OAUTH_CALLBACK_HOST` 时监听该地址），打开 `auth.openai.com`，授权码自动回传；同时保留粘贴框，可粘贴跳转 URL 或授权码。1455 端口被占用时（Codex CLI 也用它），只靠粘贴框完成登录。**设备码**（无头机器）：pig 显示一个代码，你到 `auth.openai.com/codex/device` 输入，pig 轮询直到通过。 | 只用于 `openai-codex` 模型——ChatGPT 的 Codex 后端，账号 id 从令牌里读出。 |
| **Radius** | **浏览器**（默认）：pig 监听 `http://127.0.0.1:1456/oauth/callback`，打开网关的授权页。**设备码**：在另一台设备上输入代码，pig 轮询直到通过。 | 使用 Radius 网关的公开模型列表；上游登录后还会拉取账号自己的模型列表，pig 暂未实现。 |
| **Kimi Code（订阅）** | 设备码：pig 显示带着代码的 `kimi.com` 地址，并轮询 `auth.kimi.com` 直到授权通过（`KIMI_CODE_OAUTH_HOST` 可指向别的主机）。 | 用于 `kimi-coding` 模型，可代替 `KIMI_API_KEY`。 |
| **Antigravity**（扩展） | 浏览器回调 `localhost:51121/oauth-callback`。这一行只在 `pig-antigravity` 加载时出现——它是扩展的 provider，不是核心的。 | 需要环境变量 `ANTIGRAVITY_CLIENT_ID` / `ANTIGRAVITY_CLIENT_SECRET`，或 `settings.json` 里的 `antigravity.clientId` / `antigravity.clientSecret`；pig 不内置。 |

令牌过期自动续期；文件与 pi 共用，任一工具登录即两边都已登录。`/logout` 删除已保存的登录或 API Key，环境变量和 `models.json` 不受影响。

---

## 系统架构与包划分

`pig` 在 `packages/` 目录下按清晰的职责分层组织：

- `packages/async/` (`Pig\Async\`): 协程运行时、非阻塞 TLS Socket、Future、Deferred 与基于 Fiber 的事件循环。
- `packages/ai/` (`Pig\Ai\`): 统一 LLM 协议驱动（Anthropic、OpenAI Completions、OpenAI Responses、Gemini、Vertex AI、Mistral、Amazon Bedrock ConverseStream——SigV4 签名与 AWS 凭据链均为纯 PHP 实现——Azure OpenAI Responses、ChatGPT Codex 后端，以及 pi 自己的 `pi-messages` 协议），以及 `Pig\Ai\Extension\`——扩展自带 provider 时实现的 `Provider`/`StreamApi`/`OauthFlow`。
- `packages/agent-core/` (`Pig\Agent\`): Agent 核心循环、工具生命周期与 JSON Schema 参数校验。
- `packages/tui/` (`Pig\Tui\`): 差异化终端渲染引擎、ANSI 样式与键盘输入事件解析器。
- `packages/coding-agent/` (`Pig\CodingAgent\`): CLI 调度器、会话树、自动上下文压缩、Web 守护进程与核心工具集。

---

## 环境要求与开发者指南

- **PHP >= 8.3**
- 必需扩展：`ext-json`、`ext-mbstring`、`ext-openssl`、`ext-pcntl`、`ext-pcre`。
- 可选扩展：`ext-posix`（仅后台守护运行 `pig web start -d` 需要）。
- 外部二进制工具：`stty`。（`fd` 与 `rg` 若系统未预装，pig 会在初次使用时自动下载至 `~/.pig/agent/bin/`）。

```bash
# 运行完整单元测试套件
vendor/bin/phpunit

# 扫描全项目语法
php test/lint.php

# 运行真实模型提供商端到端测试（需要配置对应 API Key）
php test/live.php
```

---

## 官方文档与资源

完整使用手册、扩展开发指南与 SDK 说明请访问官方网站：
- **官方文档**：[https://pigagent.dev/docs](https://pigagent.dev/docs)
- **模型大全**：[https://pigagent.dev/models](https://pigagent.dev/models)
- **扩展目录**：[https://pigagent.dev/packages](https://pigagent.dev/packages)
- **更新日志**：[https://pigagent.dev/changelog](https://pigagent.dev/changelog)

---

## 开源协议

MIT © [owner888](https://github.com/owner888)
