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
  - `pig-antigravity`：Google Antigravity 免费商用模型支持、多账号轮转管理、429 自动换号无缝续跑、`/antigravity.usage` 额度面板与 `generate_image` 生图工具。
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

该安装脚本会自动检测您的 PHP 版本与必需扩展，通过 Composer 全局安装 `pigagent/pig`，并自动帮您将 Composer 全局 bin 路径加入 `PATH` 环境变量。

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

扩展、技能、提示模板、主题可以打成一个**包**一起分发——和 pi 一样是一个 git 仓库或本地目录，只是没有 npm：

```bash
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

settings 里的条目可以收窄要加载的内容，语法与 pi 相同——省略某类=全部加载，`[]`=一个不要，`!glob` 排除，`+path` / `-path` 精确增减一个文件：

```json
{ "packages": [{ "source": "git:github.com/user/pig-tools", "extensions": ["!extensions/legacy.php"], "skills": [] }] }
```

pig 不会在包里跑任何包管理器：需要第三方库的包自带 `vendor/`（有 `vendor/autoload.php` pig 会 require），`Pig\*` 由宿主提供——包里不要 `require` `pigagent/pig`。包只支持 PHP；`npm:` 和 `composer:` 来源会被按名拒绝。

---

## 使用指南

### 1. 终端交互模式（默认）

在项目所在目录中直接启动 `pig`：

```bash
pig
```

- **配置 API Key**：启动时通过环境变量传入 `ANTHROPIC_API_KEY=sk-... pig`，或使用 `--api-key <key>` 为当前运行单独设置而不写入磁盘。
- **Google Vertex AI 与 Amazon Bedrock**：与 pi 一致，云平台自己的凭据在场时无需 Key。Vertex 读 `GOOGLE_CLOUD_API_KEY`，或 Application Default Credentials（`gcloud auth application-default login`、`GOOGLE_APPLICATION_CREDENTIALS` 指向的服务账号文件、或元数据服务器），并需 `GOOGLE_CLOUD_PROJECT` 与 `GOOGLE_CLOUD_LOCATION`。Bedrock 读 `AWS_BEARER_TOKEN_BEDROCK`，或 AWS SDK 的凭据链——`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`、`AWS_PROFILE` 与 `~/.aws`（assume role、`credential_process`、SSO）、web identity、ECS/EKS 与 EC2 角色——区域取 `AWS_REGION` 或 profile。选模型写作 `google-vertex/gemini-2.5-pro` 或 `amazon-bedrock/us.anthropic.claude-sonnet-4-5-20250929-v1:0`。
- **Azure OpenAI 与 Radius**：与 pi 一致。Azure 读 `AZURE_OPENAI_API_KEY`，端点取 `AZURE_OPENAI_BASE_URL` 或 `AZURE_OPENAI_RESOURCE_NAME`（`https://<name>.openai.azure.com/openai/v1`），API 版本取 `AZURE_OPENAI_API_VERSION`（默认 `v1`），部署名与模型不同时写在 `AZURE_OPENAI_DEPLOYMENT_NAME_MAP`（`gpt-5.4=prod,o3=reasoning`）；选模型写作 `azure/gpt-5.4`。Radius 网关读 `RADIUS_API_KEY`，模型如 `radius/balanced`。
- **pi 在这些 API 上内置的其余 provider**：各读 pi 的 Key 变量，选模型写作 `<provider>/<id>`（裸 id 指模型厂商自己的 provider）。DeepSeek `DEEPSEEK_API_KEY`、OpenRouter `OPENROUTER_API_KEY`（`openrouter/moonshotai/kimi-k2.6`）、Vercel AI Gateway `AI_GATEWAY_API_KEY`、Together `TOGETHER_API_KEY`、Fireworks `FIREWORKS_API_KEY`、Baseten `BASETEN_API_KEY`、Hugging Face `HF_TOKEN`、NVIDIA `NVIDIA_API_KEY`、MiniMax `MINIMAX_API_KEY` / `MINIMAX_CN_API_KEY`（`minimax-cn`）、Moonshot `MOONSHOT_API_KEY`（`moonshotai`、`moonshotai-cn`）、Kimi For Coding `KIMI_API_KEY`（`kimi-coding`）、Meta `META_API_KEY`、OpenCode Zen 与 Go `OPENCODE_API_KEY`（`opencode`、`opencode-go`）、小米 MiMo `XIAOMI_API_KEY` 及其 Token Plan `XIAOMI_TOKEN_PLAN_CN_API_KEY` / `_AMS_` / `_SGP_`（`xiaomi-token-plan-cn` …）、通义 Token Plan `QWEN_TOKEN_PLAN_API_KEY`（`qwen-token-plan`、`qwen-token-plan-individual`）与 `QWEN_TOKEN_PLAN_CN_API_KEY`（`qwen-token-plan-cn`）、智谱国内编程套餐 `ZAI_CODING_CN_API_KEY`（`zai-coding-cn`）、Ant Ling `ANT_LING_API_KEY`。OpenRouter、Kimi、Meta、xAI 的登录没有移植，Key 可用。xAI 的模型（`XAI_API_KEY`）与 pi 一致走它的 Responses API。
- **Cloudflare Workers AI 与 AI Gateway**：`CLOUDFLARE_API_KEY` 加 `CLOUDFLARE_ACCOUNT_ID`，网关还要 `CLOUDFLARE_GATEWAY_ID`（pi 保存这两个 ID 的登录没有移植）；选模型写作 `cloudflare-workers-ai/@cf/openai/gpt-oss-120b` 或 `cloudflare-ai-gateway/claude-sonnet-4-6`。
- **分类器与图片模型**（`Pig\Ai\Models::classify()`、`Models::generateImages()`，供基于 `pig/ai` 写的代码调用）：TypeSafe 的 System One `TYPESAFE_API_KEY`（`typesafe/jev-latest`，以及 OpenRouter、Vercel AI Gateway、OpenCode Zen、Workers AI 上的 Jev 与 decision 模型），llama.cpp `llama-server` 上按下一 token 概率读答案的对话模型（`llama-cpp-classify`），以及 OpenRouter 的图片模型。它们不是对话模型，`/model` 不列出。
- **订阅账号直接登录**：在对话中输入 `/login`，支持免配置 Key 直接登录 Claude Pro/Max、GitHub Copilot、ChatGPT Plus/Pro（用于 `openai-codex` 模型，如 `openai-codex/gpt-6.1-sol`——浏览器回调到 `localhost:1455`，或在无浏览器的机器上用设备码）或 Google Antigravity。Claude 登录与 pi 1.0 一致提供两种方式：浏览器回调到 `localhost:53692`（默认），或在无浏览器的机器上从 Anthropic 页面复制代码粘贴。凭据自动安全持久化于 `~/.pig/agent/auth.json`（或与 `~/.pi/agent/auth.json` 互通共享）。
- **恢复与续接历史会话**：
  - `pig -c` / `pig --continue`：毫秒级秒开接上当前目录下最新修改的历史会话。
  - `pig -r` / `pig --resume`：唤出带模糊搜索的历史会话选择器。
  - `pig --session <id>`：直接按会话 ID 或文件路径恢复会话。
- **模型选择与动态切换**：
  - `pig --model sonnet` 或 `pig --model antigravity/gemini-3.8-flash`
  - `pig --model sonnet:high`（同时设置模型与思考强度）
  - 按 `Ctrl+L` 呼出模型选择菜单；按 `Ctrl+P` / `Shift+Ctrl+P` 快捷切换上一/下一模型。
  - `pig --list-models [query]`：查看当前已配置密钥的所有可用模型、上下文上限与定价。
- **生成期间实时交互与输入快捷键**：
  - **长按 `⌘`（Command）键 ~0.5s**：如同 **Blink Shell / iPadOS** 般在屏幕中央弹出快捷键速查面板（HUD），松开即自动消失，支持 TUI 终端与 Web 界面。
  - 按 **Shift+Enter**：输入框内**换行**（多行自由编写 Prompt 或粘贴代码块，绝不误发）。
  - 空闲时按 **回车（Enter）** 或 **Command+Enter**（`⌘+Enter` / `Ctrl+Enter`）：发送消息（Submit）。
  - 模型生成期间按 **Command+Enter**（或 **Alt+Enter**）：**排队追问（Follow-up）**（加入后续队列，等本轮完全结束后再自动处理）。
  - 模型生成期间按 **回车（Enter）**：**抢占转向（Steer）**（当前工具执行完立刻优先处理你的新指令）。
  - 按 **Alt+Up**：将已排队的待发送内容全部撤回编辑输入框。
  - 按 **Escape**：立即终止当前生成轮次或取消重试等待。
  - 按 **Ctrl+G**：将输入框内容调起外部编辑器（支持在 `settings.json` 中配置 `"externalEditor": "vim"`，支持 `$VISUAL` 或 `$EDITOR`，未配置时自动回退至系统可用编辑器）进行长文本编辑，保存即自动带回。
- **常用会话控制命令**：
  - `/name <新标题>`：查看或修改当前会话标题（终端底部与 Web 界面即时联动更新）。
  - `/label <标签名>`：为当前会话树节点打上书签标记。
  - `/tree`：将整场会话各分支以树状图可视化展示，可随时回跳到任意历史分支。
  - `/compact`：手动触发会话上下文智能压缩摘要。
  - `/export [file.html]`：将当前会话导出为离线单文件 HTML，自带语法高亮与折叠。
  - `/reload`：不重启进程，热重载所有扩展、Skills、工具与项目上下文文件（`CLAUDE.md` / `AGENTS.md`）。
  - `/doctor`：运行全面系统诊断，排查 PHP 扩展、网络端点、权限与外部工具依赖。

### 2. Web UI 界面模式（`pig web`）

`pig` 自带高颜值 Web 聊天界面，对齐 `pi-web`，支持多工作区目录树、多 Tab 并行对话、内联重命名/删除会话以及全双工流式通信：

```bash
# 前台启动（自动启动服务并在系统默认浏览器中打开页面）
pig --mode web
# 或在终端交互模式下随时键入 /web 启动并自动唤起浏览器

# 生产级后台持久守护进程（推荐）
pig web start -d              # 后台常驻启动（默认监听 127.0.0.1:8080，支持 --port / --host）
pig web status                # 查看守护进程运行状态与 PID
pig web restart               # 重启 Web 服务
pig web stop                  # 安全平滑终止 Web 进程
```

使用浏览器或手机访问 `http://localhost:8080`：
- **本地交互式 PTY 终端抽屉**：随时按快捷键 <code>Ctrl+`</code> 或 <code>Cmd+`</code>（或点击顶栏终端图标）呼出集成 `xterm.js` 的全功能多标签页伪终端，原生支持 `vim`、`nvim`、`nano`、`htop`、`tmux` 等全屏按键控制。
- **SSH 远程节点工作台与 SFTP 文件管理**：点击顶栏 `🖥️ 节点` 管理远程服务器资产，SHA-256 主机指纹安全校验，`~/.ssh/config` 一键导入，远程交互终端（`ssh -tt`）与 SFTP 文件在线浏览/编辑（上限 512 KiB）。
- **自动唤起默认浏览器**：在终端 TUI 会话中敲 `/web` 会在端口绑定后自动唤起系统默认浏览器打开界面，无需手动复制 URL。
- **单长连接多 Tab 并行**：在同一页面内自由切换多个工作区与会话，后台任务互不干扰。
- **原生中英文双语一键切换**：支持顶部导航 `[中 / EN]` 即时无刷新切换界面语言，默认自适应浏览器首选语言。
- **侧边栏快捷操作**：鼠标悬停（或手机触屏直接点击）即可随时重命名（`✏️`）或安全删除（`🗑️`）会话。
- **手机端深度适配**：专为移动端小屏幕（<768px）优化布局与触控响应。
- **Antigravity 账号管理面板**：随时查看各 Google 账号的配额池与重置倒计时。

### 3. 非交互式命令行与管道集成

```bash
# 纯文本单次回答模式：直接将结果输出到 stdout 并退出
pig -p "简述本项目的架构设计" | pbcopy
pig -p @error.log "分析该崩溃日志的根本原因"

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
| **`pig-antigravity`** | `extensions/pig-antigravity/` | **完整的 Antigravity provider**——模型表、线路协议、Google 登录、模型路由——通过 `registerProvider()` 注册进核心，与 pi 0.71 删除内置后社区 `pi-antigravity` 扩展的做法一致。另含多账号管理、429 配额墙时在请求内自动换号（与 pi-antigravity 一致）、`/antigravity.usage`、`/antigravity.accounts` 与 `generate_image` 工具。 |
| **`pig-web-search`** | `extensions/pig-web-search/` | 实时网络搜索（`web_search`）、网页文本抓取（`fetch_web_page`）、无头 Chrome 动态渲染（`browse_web_page`）、`/search <query>` 命令。 |
| **`pig-computer`** | `extensions/pig-computer/` | 防检测无头浏览器自动化（鼠标移动、点击、滚轮、键盘键入、高清截图与持久化 Cookie）。 |
| **`pig-codemode`** | `extensions/pig-codemode/` | 在安全沙箱子进程（`open_basedir`, `disable_functions`）中批量并行执行多工具代码，节省巨量上下文。 |
| **`pig-mcp`** | `extensions/pig-mcp/` | Model Context Protocol 原生客户端，连接标准 stdio 与 HTTP MCP 服务，支持动态 OAuth 换票（`mcp.json`）。 |
| **`pig-vless`** | `extensions/pig-vless/` | 挂在 agent 旁边的 VLESS 入站，手机通过这台机器上网。仅 TCP、单 UUID，给了证书就走 TLS。加载时把 UUID 和监听地址写进 `settings.json`，但要 `/vless start` 才监听（`stop`、`restart`、`status`）；`/vless` 打印 `vless://` 链接。 |

### 扩展能做什么

一个扩展是一个返回 `function (ExtensionApi $pi)` 的 PHP 文件（或带 `index.php` 的目录）。API 对齐 pi 的 `ExtensionAPI`：

| | |
| :--- | :--- |
| **自带 provider** | `registerProvider(new Provider(id, name, models, api: StreamApi, oauth: OauthFlow, envKeys, resold))`——模型进注册表、协议挂在 `Api::Extension` 后面、登录进 `/login` 列表。`unregisterProvider()` 收回。 |
| **工具、命令、渲染器** | `registerTool()`、`removeTools()`、`registerCommand()`、`registerMessageRenderer()`、`registerLocale()`。 |
| **事件** | `on('…')`：会话生命周期、agent 循环、工具调用与结果、`context`，以及与上游一致的 provider 事件——`before_provider_request`（替换请求 payload）、`before_provider_headers`（原地改 header）、`after_provider_response`（状态码与 header）、`provider_stream_event`（每个原始流事件）——还有 `model_select`、`thinking_level_select`。 |
| **命令行参数** | `registerFlag('name', 'boolean'|'string', 说明, 默认值)` 声明 `--name`；handler 里用 `getFlag()` 读。 |
| **会话** | `getSettings()`、`getModel()`、`setModel()`、`getThinkingLevel()`、`setThinkingLevel()`、`sendUserMessage(text, 'steer'|'followUp')`、`sendMessage()`、`setLabel()`、`getCommands()`、`exec()`。 |
| **Web UI** | `registerHttpRoute('/api/prefix', fn (path, req) => ['status', 'body'])` 在 `pig web` 里应答请求——Antigravity 账号面板就是这样接进去的。 |
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

`pig` 与上游 `pi` 在会话文件、登录凭据和自定义配置上保持 **100% 格式与目录互通**：

| 配置文件路径 | 用途说明 |
| :--- | :--- |
| `~/.pig/agent/settings.json` | 全局偏好设置（主题、默认模型、思考强度、自动压缩、自动重试）。 |
| `~/.pig/agent/auth.json` | 各提供商的 API Key 与 OAuth Token（与 `~/.pi/agent/auth.json` 互通共享）。 |
| `~/.pig/agent/models.json` | 自定义 OpenAI 兼容端点或本地模型（llama.cpp、vLLM、Ollama）。 |
| `~/.pig/agent/mcp.json` | 标准 MCP 服务配置（stdio 与 HTTP）。 |
| `~/.pig/agent/trust.json` | 项目资源信任授权记录。 |
| `~/.pig/agent/keybindings.json` | 快捷键自定义映射表。 |
| `~/.pig/agent/themes/` | 自定义主题 JSON 目录（内置 `dark`、`light`、`labra`，支持任意第三方主题）。 |
| `~/.pig/agent/sessions/` | 遵循标准 `.jsonl` 格式的持久化会话树日志。 |

### 订阅账号登录

在 pig 里输入 `/login`，或在没有终端 UI 的机器上用 `pig-ai login <provider>`。四个提供商，流程均与 pi 一致：

| 提供商 | 方式 | 说明 |
| :--- | :--- | :--- |
| **Anthropic（Claude Pro/Max）** | **浏览器**（默认）：pig 监听 `http://localhost:53692/callback`，打开 `claude.ai`，授权码自动回传。同时保留一个粘贴框——浏览器在另一台机器上时，把最终跳转的 URL 粘贴进去即可。**复制代码**（无头机器）：浏览器停在 Anthropic 自己的页面上，显示 `code#state`，复制粘贴。 | 令牌以 Claude Code 的身份发出——`claude-cli` User-Agent、两个 beta 头、工具名按 `Read`/`Bash`/`Edit`/`Write` 拼写——因为 Anthropic 就是把它签发给 Claude Code 的。53692 端口已在 Anthropic 注册，不可更改。 |
| **GitHub Copilot** | 设备码：pig 显示一个代码，你到 `github.com/login/device` 输入，pig 轮询直到通过。Enterprise 提示处留空即 `github.com`。 | 登录后自动为账号开通 Claude 与 Grok 系列模型。 |
| **OpenAI（ChatGPT Plus/Pro）** | **浏览器**（默认）：pig 监听 `http://localhost:1455/auth/callback`（设置了 `PI_OAUTH_CALLBACK_HOST` 时监听该地址），打开 `auth.openai.com`，授权码自动回传；同时保留粘贴框，可粘贴跳转 URL 或授权码。1455 端口被占用时（Codex CLI 也用它），只靠粘贴框完成登录。**设备码**（无头机器）：pig 显示一个代码，你到 `auth.openai.com/codex/device` 输入，pig 轮询直到通过。 | 只用于 `openai-codex` 模型——ChatGPT 的 Codex 后端，账号 id 从令牌里读出。 |
| **Antigravity**（扩展） | 浏览器回调 `localhost:51121/oauth-callback`。这一行只在 `pig-antigravity` 加载时出现——它是扩展的 provider，不是核心的。 | 需要环境变量 `ANTIGRAVITY_CLIENT_ID` / `ANTIGRAVITY_CLIENT_SECRET`，或 `settings.json` 里的 `antigravity.clientId` / `antigravity.clientSecret`；pig 不内置。 |

令牌过期自动续期；文件与 pi 共用，任一工具登录即两边都已登录。`/logout` 可忘记某个登录。

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
