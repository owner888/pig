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
  - **CLI 命令行与自动化集成**：单次快速回答模式（`-p`）、JSON 事件行流式模式（`--mode json`）与长生命周期标准双向通信管道（`--mode rpc`）。
- **完善的纯 PHP 内置扩展生态**：
  - `pig-antigravity`：Google Antigravity 免费商用模型支持、多账号轮转管理、429 自动换号无缝续跑、`/antigravity.usage` 额度面板与 `generate_image` 生图工具。
  - `pig-web-search`：DuckDuckGo 实时网络搜索（`web_search`）、网页文本内容提取（`fetch_web_page`）与无头 Chrome 动态渲染（`browse_web_page`）。
  - `pig-computer`：防检测无头浏览器自动化控制，支持精准鼠标移动、点击、滚动、文本键入、高清截图与按域名持久化 Cookie。
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
pig update               # 自动拉取更新 pig 核心及所有已安装扩展（默认包含 --self 与 --extensions）
pig update --extensions  # 单独只更新扩展（支持 git pull、composer 依赖升级及内置扩展增量同步）
pig update --models      # 刷新并重新对齐模型目录
pig update --self        # 单独只更新 pig 主程序核心
```

---

## 使用指南

### 1. 终端交互模式（默认）

在项目所在目录中直接启动 `pig`：

```bash
pig
```

- **配置 API Key**：启动时通过环境变量传入 `ANTHROPIC_API_KEY=sk-... pig`，或使用 `--api-key <key>` 为当前运行单独设置而不写入磁盘。
- **订阅账号直接登录**：在对话中输入 `/login`，支持免配置 Key 直接登录 Claude Pro/Max、GitHub Copilot 或 Google Antigravity。Claude 登录与 pi 1.0 一致提供两种方式：浏览器回调到 `localhost:53692`（默认），或在无浏览器的机器上从 Anthropic 页面复制代码粘贴。凭据自动安全持久化于 `~/.pig/agent/auth.json`（或与 `~/.pi/agent/auth.json` 互通共享）。
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
```

---

## 纯原生扩展生态（100% 零依赖）

`pig` 的所有扩展均采用纯 PHP 编写，**绝不引入任何 Node.js、npm 或外部 composer 依赖**：

| 扩展组件 | 命名空间 / 路径 | 核心能力说明 |
| :--- | :--- | :--- |
| **`pig-antigravity`** | `extensions/pig-antigravity/` | **完整的 Antigravity provider**——模型表、线路协议、Google 登录、模型路由——通过 `registerProvider()` 注册进核心，与 pi 0.71 删除内置后社区 `pi-antigravity` 扩展的做法一致。另含多账号管理、429 自动换号（`before_retry`）、`/antigravity.usage`、`/antigravity.accounts` 与 `generate_image` 工具。 |
| **`pig-web-search`** | `extensions/pig-web-search/` | 实时网络搜索（`web_search`）、网页文本抓取（`fetch_web_page`）、无头 Chrome 动态渲染（`browse_web_page`）、`/search <query>` 命令。 |
| **`pig-computer`** | `extensions/pig-computer/` | 防检测无头浏览器自动化（鼠标移动、点击、滚轮、键盘键入、高清截图与持久化 Cookie）。 |
| **`pig-codemode`** | `extensions/pig-codemode/` | 在安全沙箱子进程（`open_basedir`, `disable_functions`）中批量并行执行多工具代码，节省巨量上下文。 |
| **`pig-mcp`** | `extensions/pig-mcp/` | Model Context Protocol 原生客户端，连接标准 stdio 与 HTTP MCP 服务，支持动态 OAuth 换票（`mcp.json`）。 |

### 扩展能做什么

一个扩展是一个返回 `function (ExtensionApi $pi)` 的 PHP 文件（或带 `index.php` 的目录）。API 对齐 pi 的 `ExtensionAPI`：

| | |
| :--- | :--- |
| **自带 provider** | `registerProvider(new Provider(id, name, models, api: StreamApi, oauth: OauthFlow, envKeys, resold))`——模型进注册表、协议挂在 `Api::Extension` 后面、登录进 `/login` 列表。`unregisterProvider()` 收回。 |
| **工具、命令、渲染器** | `registerTool()`、`removeTools()`、`registerCommand()`、`registerMessageRenderer()`、`registerLocale()`。 |
| **事件** | `on('…')`：会话生命周期、agent 循环、工具调用与结果、`context`，以及新增的 `before_provider_request`（改写 header 或 body）、`after_provider_response`（状态码与 header）、`before_retry`（改等待时长、重置计数或取消）、`model_select`、`thinking_level_select`。 |
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

在 pig 里输入 `/login`，或在没有终端 UI 的机器上用 `pig-ai login <provider>`。三个提供商，流程均与 pi 1.0.3 一致：

| 提供商 | 方式 | 说明 |
| :--- | :--- | :--- |
| **Anthropic（Claude Pro/Max）** | **浏览器**（默认）：pig 监听 `http://localhost:53692/callback`，打开 `claude.ai`，授权码自动回传。同时保留一个粘贴框——浏览器在另一台机器上时，把最终跳转的 URL 粘贴进去即可。**复制代码**（无头机器）：浏览器停在 Anthropic 自己的页面上，显示 `code#state`，复制粘贴。 | 令牌以 Claude Code 的身份发出——`claude-cli` User-Agent、两个 beta 头、工具名按 `Read`/`Bash`/`Edit`/`Write` 拼写——因为 Anthropic 就是把它签发给 Claude Code 的。53692 端口已在 Anthropic 注册，不可更改。 |
| **GitHub Copilot** | 设备码：pig 显示一个代码，你到 `github.com/login/device` 输入，pig 轮询直到通过。Enterprise 提示处留空即 `github.com`。 | 登录后自动为账号开通 Claude 与 Grok 系列模型。 |
| **Antigravity**（扩展） | 浏览器回调 `localhost:51121/oauth-callback`。这一行只在 `pig-antigravity` 加载时出现——它是扩展的 provider，不是核心的。 | 需要环境变量 `ANTIGRAVITY_CLIENT_ID` / `ANTIGRAVITY_CLIENT_SECRET`，或 `settings.json` 里的 `antigravity.clientId` / `antigravity.clientSecret`；pig 不内置。 |

令牌过期自动续期；文件与 pi 共用，任一工具登录即两边都已登录。`/logout` 可忘记某个登录。

---

## 系统架构与包划分

`pig` 在 `packages/` 目录下按清晰的职责分层组织：

- `packages/async/` (`Pig\Async\`): 协程运行时、非阻塞 TLS Socket、Future、Deferred 与基于 Fiber 的事件循环。
- `packages/ai/` (`Pig\Ai\`): 统一 LLM 协议驱动（Anthropic、OpenAI Completions、OpenAI Responses、Gemini），以及 `Pig\Ai\Extension\`——扩展自带 provider 时实现的 `Provider`/`StreamApi`/`OauthFlow`。
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
