# pig Browser Service (Docker 反检测浏览器与 Computer Use 环境)

提供基于原生 Chromium + Xvfb 虚拟屏幕的真实反检测浏览器容器服务，支持 **Cloudflare 盾 / 百度 / 谷歌验证码绕过**，并提供原生 Computer Use 动作控制 API（截屏、拟真鼠标平滑移动点击、键盘输入、滚屏）与 Cookie/Profile 持久化能力。

---

## 快速启动

### 方式 1：Docker Compose 启动（推荐）

在 `docker/browser/` 目录下执行：

```bash
docker compose up -d
```

### 方式 2：单行 Docker 运行

```bash
docker run -d \
  --name pig-browser \
  -p 9523:9523 \
  -v ~/.pig/agent:/data \
  --shm-size=2gb \
  pigagent/browser:latest
```

启动后服务监听在 `http://127.0.0.1:9523`。

---

## 京东/淘宝等电商免登录操作（Cookie 挂载）

1. **导出当前浏览器 Cookie**：
   在您平时使用的 Chrome 浏览器中登录京东或淘宝，使用浏览器插件（如 **EditThisCookie** 或 **Cookie-Editor**）导出为 JSON 格式。
2. **保存到 `~/.pig/agent/cookies.json`**：
   ```bash
   # 将导出的 JSON 粘贴保存到 ~/.pig/agent/cookies.json
   mkdir -p ~/.pig/agent
   vim ~/.pig/agent/cookies.json
   ```
3. **自动注入与持久化**：
   容器启动后会自动扫描并向浏览器会话注入该文件中的全部 Cookie，且浏览器的全部缓存与登录凭据将持久化保存在 `~/.pig/agent/browser_profile/`，以后访问均无需重复登录。

---

## API 接口定义

| 接口 | 方法 | 请求体说明 | 返回结果 |
|---|---|---|---|
| `/health` | GET | 无 | 服务健康状态与视口分辨率 (`1920x1080`) |
| `/navigate` | POST | `{"url": "https://...", "timeout": 45000}` | 打开网页，等待加载完成 |
| `/screenshot` | POST | `{"fullPage": false, "format": "png"}` | 截取当前视口高清截图（Base64） |
| `/click` | POST | `{"x": 450, "y": 210}` 或 `{"selector": "#submit-btn"}` | 拟真平滑贝塞尔鼠标移动并点击 |
| `/mouse_move` | POST | `{"x": 450, "y": 210}` | 拟真平滑移动鼠标指针 |
| `/type` | POST | `{"text": "人体工学椅", "selector": "#key", "delay": 50}` | 键盘拟真逐字敲击输入 |
| `/press` | POST | `{"key": "Enter"}` | 触发按键 (`Enter`, `Backspace`, `Tab`, `Escape`) |
| `/scroll` | POST | `{"deltaY": 400}` | 平滑滚屏浏览页面 |
| `/text` | GET | 无 | 提取当前页面标题、URL 及过滤后的纯净文本 |
| `/cookies` | POST | `{"cookies": [...]}` | 动态热注入一组 Cookie 数组 |
