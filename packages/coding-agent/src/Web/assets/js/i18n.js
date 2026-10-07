import { escapeHtml, openModal } from "./utils.js";
import { applyTheme, currentTheme } from "./theme.js";

    const I18N = {
      "zh-CN": {
        "workspaces": "工作区",
        "directories": "项目目录",
        "sessions": "历史会话",
        "new_session": "+ 新建会话",
        "new_session_title": "创建新会话",
        "more_options": "更多操作",
        "menu_theme": "主题",
        "menu_lang": "语言",
        "menu_account": "账号",
        "back_workspaces": "‹ 返回工作区",
        "sessions_count": "{count} 个会话",
        "loading_folders": "正在加载工作区...",
        "failed_folders": "加载工作区失败",
        "loading_sessions": "正在加载会话...",
        "failed_sessions": "加载会话失败",
        "no_sessions": "该目录暂无历史会话",
        "rename_session": "重命名会话",
        "delete_session": "删除会话",
        "delete_confirm": "确定要将此会话移至废纸篓吗？",
        "delete_hint": "此操作会将对应的 .jsonl 会话文件移入 ~/.pig/agent/sessions/trash/ 回收站目录，以便安全恢复。",
        "rename_label": "请输入此会话的新标题：",
        "rename_placeholder": "会话标题...",
        "file_label": "文件：",
        "save": "保存",
        "cancel": "取消",
        "confirm": "确定",
        "delete": "删除",
        "accounts": "🔑 账号",
        "terminal": "终端",
        "terminal_title": "网页终端 (Ctrl+`)",
        "toggle_sidebar": "展开/折叠侧边栏",
        "click_to_copy": "点击复制会话文件路径",
        "loading_session": "正在加载会话...",
        "copy": "复制",
        "copied": "已复制",
        "nodes": "SSH 节点",
        "nodes_title": "SSH 节点工作台",
        "add_node": "添加节点",
        "edit_node": "编辑节点",
        "node_name": "名称",
        "node_group": "分组",
        "node_host": "主机地址",
        "node_port": "端口",
        "node_user": "用户名",
        "node_auth": "认证方式",
        "node_key_path": "私钥路径",
        "node_password": "密码 / 口令",
        "node_default_dir": "默认目录",
        "node_connect": "连接",
        "node_disconnect": "断开",
        "node_connected": "已连接",
        "node_connecting": "连接中...",
        "node_trust": "信任并连接",
        "node_fingerprint_prompt": "检测到未信任的主机密钥指纹，请核对：",
        "node_sftp": "SFTP 文件",
        "node_browse": "浏览",
        "node_terminal": "SSH 终端",
        "node_import_ssh": "从 ~/.ssh/config 导入",
        "scroll_bottom": "↓ 查看新消息",
        "provider": "服务商",
        "model": "模型",
        "thinking_level": "思考等级",
        "placeholder": "向 pig 提问...",
        "attach_title": "添加图片（或直接粘贴剪贴板）",
        "send_title": "发送消息（Enter）",
        "stop_title": "停止生成（Escape）",
        "hud_title": "⌘ 快捷键速查",
        "hud_hint": "松开 ⌘ 键关闭",
        "hud_send": "发送消息 / 加入跟进队列",
        "hud_newline": "输入换行",
        "hud_enter": "发送消息 / 提交提示词",
        "hud_stop": "停止生成 / 关闭弹窗",
        "hud_clear": "清空输入 / 打断",
        "hud_tools": "展开 / 折叠工具详情",
        "hud_model": "切换模型",
        "tool_executing": "... 正在执行命令",
        "tool_reading": "正在读取文件...",
        "tool_writing": "正在写入文件...",
        "tool_searching": "正在搜索...",
        "tool_finding": "正在查找文件...",
        "tool_listing": "正在列出目录...",
        "tool_web_searching": "正在进行网络搜索...",
        "tool_fetching_page": "正在获取网页正文...",
        "tool_rendering_dom": "正在浏览器中渲染网页 DOM...",
        "tool_computer_action": "正在执行浏览器自动化操作...",
        "tool_running": "⏳ 执行中...",
        "tool_done": "✔ 完成",
        "tool_failed": "✖ 失败",
        "thinking": "思考过程...",
        "thinking_done": "已深度思考",
        "thinking_duration": "{seconds} 秒",
        "words_count": "{count} 字",
        "tool_output_copied": "已复制输出",
        "empty_welcome_title": "今天有什么我可以帮您的？",
        "empty_welcome_subtitle": "在下方输入任务，或点击快捷指令快速开始",
        "empty_chip_git": "查看 Git 状态与最近提交",
        "empty_chip_tests": "运行单元测试套件",
        "empty_chip_files": "查看项目核心文件与架构",
        "empty_chip_doctor": "诊断系统与环境状态 (/antigravity.doctor)",
        "retrying_banner": "⟳ 将在 {delay} 秒后重试（第 {attempt}/{maxAttempts} 次）：{error}",
        "compacting_banner": "上下文已满 — 正在生成会话历史摘要...",
        "compacted_from": "已压缩原有 {count} tokens 的上下文",
        "compacted_done": "上下文已压缩",
        "branch_summary_done": "分支已生成摘要",
        "branch_summary_hook": "分支摘要由 hook 提供",
        "more_lines": "… 还有 {count} 行",
        "command_cancelled": "（已取消）",
        "command_exit": "（退出码 {code}）",
        "output_truncated": "输出已截断。完整输出：{path}",
        "operation_aborted": "操作已中止",
        "connecting": "正在连接服务器...",
        "disconnected": "与服务器断开连接，正在重连...",
        "unreachable": "无法连接到 pig：{error} — 正在重连…",
        "accounts_title": "Antigravity 账号管理",
        "accounts_rotate": "轮换",
        "accounts_rotate_title": "切换到下一个可用账号",
        "accounts_refresh": "刷新",
        "accounts_refresh_title": "重新获取配额",
        "quota_active": "配额 — 当前使用中账号",
        "no_accounts": "暂无 Antigravity 账号。请在终端执行 <code>/login antigravity</code>。",
        "active": "使用中",
        "use": "启用",
        "remove": "移除",
        "remove_title": "从本机移除此账号",
        "remove_confirm": "确定要移除账号 {email} 吗？其登录凭据将从本机移除。",
        "token_expired": "令牌已过期",
        "token_left_m": "令牌剩余 {m} 分钟",
        "token_left_h": "令牌剩余 {h} 小时",
        "switch_lang": "语言",
        "switch_lang_title": "语言与语言包设置",
        "tab_new_session": "新会话",
        "in_memory": "（内存暂存）",
        "in_memory_hint": "会话尚未保存至磁盘（首轮回复后自动写入）",
        "delete_permanently": "永久删除",
        "delete_warning": "此操作无法撤销。该文件中的所有历史对话记录将被永久删除。",
        "lang_settings_title": "语言与语言包设置",
        "lang_select_label": "界面显示语言",
        "lang_zh_cn": "简体中文 (Simplified Chinese)",
        "lang_en": "English (英语)",
        "lang_custom_badge": "自定义",
        "lang_ext_badge": "扩展提供",
        "import_pack_btn": "📥 导入语言包 (.json)",
        "export_pack_btn": "📤 导出当前语言包 (.json)",
        "import_pack_hint": "支持导入 Telegram 风格的 JSON 键值语言包文件。",
        "import_success": "成功导入语言包：{name}！",
        "import_error": "导入失败：文件必须是包含有效词条的 JSON 字典对象",
        "delete_custom_lang": "删除此自定义语言包",
        "delete_custom_confirm": "确定删除自定义语言包「{name}」吗？",
        "close": "关闭",
        "quota_gemini_models": "Gemini 模型",
        "quota_claude_gpt_models": "Claude 与 GPT 模型",
        "quota_claude_models": "Claude 模型",
        "quota_gpt_models": "GPT 模型",
        "quota_weekly_limit": "周限额剩余",
        "quota_five_hour_limit": "5 小时限额剩余",
        "quota_daily_limit": "日限额剩余",
        "quota_hourly_limit": "小时限额剩余",
        "quota_monthly_limit": "月限额剩余",
        "quota_resets_now": "立即重置",
        "quota_resets_m": "{m} 分钟后重置",
        "quota_resets_hm": "{h} 小时 {m} 分后重置",
        "quota_resets_d": "{d} 天后重置",
        "quota_fetching": "正在获取配额…",
        "quota_unavailable": "配额不可用",
        "quota_empty_groups": "未返回配额信息。",
        "theme_settings": "界面主题设置",
        "theme_select_label": "选择界面配色方案：",
        "theme_dark": "暗夜黑 (Dark)",
        "theme_dark_desc": "默认暗色主题，低对比度护眼，紫色与青色高光",
        "theme_labra": "赛博朋克墨绿 (Labra)",
        "theme_labra_desc": "来自 omarchy-labra 的极简墨绿配洋红粉高光",
        "theme_light": "清新浅白 (Light)",
        "theme_light_desc": "优雅明亮主题，适合日间采光与高清晰度阅读"
      },
      "en": {
        "workspaces": "Workspaces",
        "directories": "Directories",
        "sessions": "Sessions",
        "new_session": "+ New Session",
        "new_session_title": "Create a new session",
        "more_options": "More options",
        "menu_theme": "Theme",
        "menu_lang": "Language",
        "menu_account": "Account",
        "back_workspaces": "‹ Workspaces",
        "sessions_count": "{count} sessions",
        "loading_folders": "Loading workspaces...",
        "failed_folders": "Failed to load folders",
        "loading_sessions": "Loading sessions...",
        "failed_sessions": "Failed to load sessions",
        "no_sessions": "No sessions in this folder",
        "rename_session": "Rename Session",
        "delete_session": "Delete Session",
        "delete_confirm": "Are you sure you want to permanently delete this session?",
        "delete_hint": "This will move the session file to ~/.pig/agent/sessions/trash for safe recovery.",
        "rename_label": "Enter a new title for this session:",
        "rename_placeholder": "Session title...",
        "file_label": "File: ",
        "save": "Save",
        "cancel": "Cancel",
        "confirm": "Confirm",
        "delete": "Delete",
        "accounts": "🔑 Accounts",
        "terminal": "Terminal",
        "terminal_title": "Web Terminal (Ctrl+`)",
        "toggle_sidebar": "Toggle Sidebar",
        "click_to_copy": "Click to copy session file path",
        "loading_session": "Loading session...",
        "copy": "Copy",
        "copied": "Copied!",
        "nodes": "SSH Nodes",
        "nodes_title": "SSH Node Workbench",
        "add_node": "Add Node",
        "edit_node": "Edit Node",
        "node_name": "Name",
        "node_group": "Group",
        "node_host": "Host",
        "node_port": "Port",
        "node_user": "Username",
        "node_auth": "Auth Method",
        "node_key_path": "Private Key Path",
        "node_password": "Password / Passphrase",
        "node_default_dir": "Default Directory",
        "node_connect": "Connect",
        "node_disconnect": "Disconnect",
        "node_connected": "Connected",
        "node_connecting": "Connecting...",
        "node_trust": "Trust & Connect",
        "node_fingerprint_prompt": "Untrusted host key fingerprint detected, please verify:",
        "node_sftp": "SFTP Files",
        "node_browse": "Browse",
        "node_terminal": "SSH Terminal",
        "node_import_ssh": "Import from ~/.ssh/config",
        "scroll_bottom": "↓ New messages",
        "provider": "Provider",
        "model": "Model",
        "thinking_level": "Thinking Level",
        "placeholder": "Ask pig a question",
        "attach_title": "Attach image (or paste clipboard)",
        "send_title": "Send message (Enter)",
        "stop_title": "Stop (Escape)",
        "hud_title": "⌘ Shortcuts",
        "hud_hint": "Release ⌘ to close",
        "hud_send": "Send message / Follow-up queue",
        "hud_newline": "Insert newline",
        "hud_enter": "Send message / Submit prompt",
        "hud_stop": "Stop turn / Close dialog",
        "hud_clear": "Clear prompt / Interrupt",
        "hud_tools": "Expand / collapse tools",
        "hud_model": "Model selector",
        "tool_executing": "... executing command",
        "tool_reading": "Reading file...",
        "tool_writing": "Writing to file...",
        "tool_searching": "Searching...",
        "tool_finding": "Finding files...",
        "tool_listing": "Listing directory...",
        "tool_web_searching": "Searching web...",
        "tool_fetching_page": "Fetching web page...",
        "tool_rendering_dom": "Rendering live DOM in browser...",
        "tool_computer_action": "Executing browser action...",
        "tool_running": "⏳ Running...",
        "tool_done": "✔ Done",
        "tool_failed": "✖ Failed",
        "thinking": "Thinking...",
        "thinking_done": "Thought",
        "thinking_duration": "{seconds}s",
        "words_count": "{count} words",
        "tool_output_copied": "Output copied",
        "empty_welcome_title": "How can I help you today?",
        "empty_welcome_subtitle": "Type a prompt below or pick a quick start option",
        "empty_chip_git": "Check Git status and recent commits",
        "empty_chip_tests": "Run test suite",
        "empty_chip_files": "Review project structure and core files",
        "empty_chip_doctor": "Diagnose system status (/antigravity.doctor)",
        "retrying_banner": "⟳ Retrying in {delay}s (attempt {attempt}/{maxAttempts}): {error}",
        "compacting_banner": "Context is full — compacting conversation summary...",
        "compacted_from": "Compacted from {count} tokens",
        "compacted_done": "Context compacted",
        "branch_summary_done": "Branch summarised",
        "branch_summary_hook": "Branch summary provided by a hook",
        "more_lines": "… {count} more lines",
        "command_cancelled": "(cancelled)",
        "command_exit": "(exit {code})",
        "output_truncated": "Output truncated. Full output: {path}",
        "operation_aborted": "Operation aborted",
        "connecting": "Connecting to server...",
        "disconnected": "Disconnected from server. Retrying...",
        "unreachable": "Cannot reach pig: {error} — retrying…",
        "accounts_title": "Antigravity Accounts",
        "accounts_rotate": "Rotate",
        "accounts_rotate_title": "Switch to the next account",
        "accounts_refresh": "Refresh",
        "accounts_refresh_title": "Fetch quota again",
        "quota_active": "Quota — Active Account",
        "no_accounts": "No Antigravity accounts. Run <code>/login antigravity</code> in the terminal.",
        "active": "active",
        "use": "Use",
        "remove": "Remove",
        "remove_title": "Forget this account",
        "remove_confirm": "Forget {email}? Its tokens are removed from this machine.",
        "token_expired": "token expired",
        "token_left_m": "token {m}m left",
        "token_left_h": "token {h}h left",
        "switch_lang": "Language",
        "switch_lang_title": "Language & Language Pack Settings",
        "tab_new_session": "new session",
        "in_memory": "(in-memory)",
        "in_memory_hint": "Session not yet saved to disk (will save on first turn)",
        "delete_permanently": "Delete Permanently",
        "delete_warning": "This action cannot be undone. All conversation records in this file will be permanently removed.",
        "lang_settings_title": "Language & Language Packs",
        "lang_select_label": "Display Language",
        "lang_zh_cn": "简体中文 (Simplified Chinese)",
        "lang_en": "English",
        "lang_custom_badge": "Custom",
        "lang_ext_badge": "Extension",
        "import_pack_btn": "📥 Import Language Pack (.json)",
        "export_pack_btn": "📤 Export Current Pack (.json)",
        "import_pack_hint": "Supports importing Telegram-style key-value JSON language packs.",
        "import_success": "Successfully imported language pack: {name}!",
        "import_error": "Failed to import: file is not a valid JSON dictionary",
        "delete_custom_lang": "Delete this custom language pack",
        "delete_custom_confirm": "Delete custom language pack '{name}'?",
        "close": "Close",
        "quota_gemini_models": "Gemini Models",
        "quota_claude_gpt_models": "Claude and GPT models",
        "quota_claude_models": "Claude Models",
        "quota_gpt_models": "GPT Models",
        "quota_weekly_limit": "Weekly Limit Remaining",
        "quota_five_hour_limit": "Five Hour Limit Remaining",
        "quota_daily_limit": "Daily Limit Remaining",
        "quota_hourly_limit": "Hourly Limit Remaining",
        "quota_monthly_limit": "Monthly Limit Remaining",
        "quota_resets_now": "resets now",
        "quota_resets_m": "resets in {m}m",
        "quota_resets_hm": "resets in {h}h {m}m",
        "quota_resets_d": "resets in {d}d",
        "quota_fetching": "Fetching…",
        "quota_unavailable": "Quota unavailable",
        "quota_empty_groups": "No quota groups returned.",
        "theme_settings": "Theme Settings",
        "theme_select_label": "Select interface color scheme:",
        "theme_dark": "Dark Night (Dark)",
        "theme_dark_desc": "Default dark theme with balanced contrast, violet & teal accents",
        "theme_labra": "Cyberpunk Olive (Labra)",
        "theme_labra_desc": "Minimalist deep green-black background with vibrant hot pink highlights",
        "theme_light": "Fresh Light (Light)",
        "theme_light_desc": "Clean and bright theme for daylight and high-contrast reading"
      }
    };

    // ---- Locale & Language Pack Management (Telegram-inspired) -----------------------------
    let customLocales = {};
    try {
      customLocales = JSON.parse(localStorage.getItem("pig_web_custom_locales") || "{}");
      for (const [loc, pack] of Object.entries(customLocales)) {
        if (pack && pack.translations) {
          I18N[loc] = { ...(I18N["en"] || {}), ...pack.translations };
        }
      }
    } catch (e) { customLocales = {}; }

    let extensionLocales = {};
    async function loadExtensionLocales() {
      try {
        const res = await fetch("/api/locales");
        const data = await res.json();
        if (data.success && data.locales) {
          extensionLocales = data.locales;
          for (const [loc, pack] of Object.entries(extensionLocales)) {
            if (pack && pack.translations) {
              I18N[loc] = { ...(I18N["en"] || {}), ...pack.translations };
            }
          }
          applyI18n();
        }
      } catch (e) {}
    }

    let currentLocale = localStorage.getItem("pig_web_locale");
    if (!currentLocale) {
      currentLocale = (navigator.language && navigator.language.startsWith("zh")) ? "zh-CN" : "en";
    }

    function __t(key, params = {}) {
      const dict = I18N[currentLocale] || I18N["zh-CN"] || {};
      let str = dict[key] ?? (I18N["en"]?.[key] ?? key);
      for (const [k, v] of Object.entries(params)) {
        str = str.replaceAll(`{${k}}`, v);
      }
      return str;
    }
    const t = __t;

    const localeListeners = new Set();
    function onLocaleChanged(cb) {
      localeListeners.add(cb);
      return () => localeListeners.delete(cb);
    }

    function setLocale(lang) {
      if (!I18N[lang]) lang = "zh-CN";
      currentLocale = lang;
      localStorage.setItem("pig_web_locale", lang);
      document.documentElement.lang = lang;
      applyI18n();
      for (const cb of localeListeners) {
        try { cb(lang); } catch (e) {}
      }
    }

    function applyI18n() {
      // 1. Text content
      document.querySelectorAll("[data-i18n]").forEach(el => {
        const key = el.dataset.i18n;
        if (key) el.textContent = t(key);
      });

      // 2. Attributes
      document.querySelectorAll("[data-i18n-attr]").forEach(el => {
        const pairs = el.dataset.i18nAttr.split(",");
        for (const pair of pairs) {
          const [attr, key] = pair.split(":");
          if (attr && key) {
            el.setAttribute(attr.trim(), t(key.trim()));
          }
        }
      });

      // 3. Update lang switch button
      const langBtn = document.getElementById("lang-btn");
      if (langBtn) {
        langBtn.textContent = `🌐 ${t("switch_lang")}`;
        langBtn.title = t("switch_lang_title");
      }

      // 4. Update sidebar subtitle
      const sub = document.getElementById("sidebar-subtitle");
      if (sub && (typeof window !== "undefined" && (window.__currentView === "workspaces" || !window.__currentView))) {
        sub.textContent = t("directories");
      }

      // 5. Update theme switch button label
      try {
        applyTheme(currentTheme);
      } catch (e) {}
    }

    /** Export active locale translations as a Telegram-style JSON language pack */
    function exportLanguagePack() {
      const activeDict = I18N[currentLocale] || I18N["zh-CN"];
      const label = currentLocale === "zh-CN"
        ? "简体中文"
        : (currentLocale === "en" ? "English" : (customLocales[currentLocale]?.label || currentLocale));

      const payload = {
        _meta: {
          locale: currentLocale,
          label: label,
          author: "pig user",
          version: "1.0.0",
          exported_at: new Date().toISOString()
        },
        ...activeDict
      };

      const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `pig-locale-${currentLocale}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    }

    /** Import a Telegram-style JSON language pack file and apply immediately */
    function importLanguagePackFile(file) {
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (e) => {
        try {
          const data = JSON.parse(e.target.result);
          if (!data || typeof data !== "object" || Array.isArray(data)) {
            alert(t("import_error"));
            return;
          }

          let locale = data._meta?.locale;
          let label = data._meta?.label;

          // If no _meta, derive from filename e.g. pig-locale-ja.json -> ja
          if (!locale) {
            const m = file.name.match(/(?:locale-)?([a-zA-Z0-9_-]+)\.json$/i);
            locale = m ? m[1] : "custom-" + Date.now();
          }
          if (!label) label = locale;

          const translations = {};
          for (const [k, v] of Object.entries(data)) {
            if (k !== "_meta" && typeof v === "string") {
              translations[k] = v;
            }
          }

          if (Object.keys(translations).length === 0) {
            alert(t("import_error"));
            return;
          }

          customLocales[locale] = { label, translations };
          localStorage.setItem("pig_web_custom_locales", JSON.stringify(customLocales));

          // Merge into live I18N
          I18N[locale] = { ...(I18N["en"] || {}), ...translations };

          setLocale(locale);
          alert(t("import_success", { name: label }));
        } catch (err) {
          alert(t("import_error") + ": " + err.message);
        }
      };
      reader.readAsText(file);
    }

    /** Telegram-style Language & Language Packs Management Modal */
    function openLanguageModal() {
      const renderModalBody = () => {
        let html = `
          <div style="font-size:12px; color:var(--text-muted); margin-bottom:10px; font-weight:600;">
            ${escapeHtml(t("lang_select_label"))}
          </div>
          <div id="lang-list-container" style="display:flex; flex-direction:column; gap:6px; margin-bottom:18px;">
            <div class="lang-item-row ${currentLocale === 'zh-CN' ? 'active' : ''}" data-loc="zh-CN">
              <span>${escapeHtml(t("lang_zh_cn"))}</span>
              ${currentLocale === 'zh-CN' ? '<span>✓</span>' : ''}
            </div>
            <div class="lang-item-row ${currentLocale === 'en' ? 'active' : ''}" data-loc="en">
              <span>${escapeHtml(t("lang_en"))}</span>
              ${currentLocale === 'en' ? '<span>✓</span>' : ''}
            </div>
        `;

        // Render extension locales
        for (const [loc, pack] of Object.entries(extensionLocales)) {
          if (loc === "zh-CN" || loc === "en") continue;
          const isActive = currentLocale === loc;
          html += `
            <div class="lang-item-row ${isActive ? 'active' : ''}" data-loc="${escapeHtml(loc)}">
              <span>${escapeHtml(pack.label || loc)}</span>
              <div style="display:flex; gap:6px; align-items:center;">
                <span class="lang-badge">${escapeHtml(t("lang_ext_badge"))}</span>
                ${isActive ? '<span>✓</span>' : ''}
              </div>
            </div>
          `;
        }

        // Render custom imported locales
        for (const [loc, pack] of Object.entries(customLocales)) {
          if (loc === "zh-CN" || loc === "en") continue;
          const isActive = currentLocale === loc;
          html += `
            <div class="lang-item-row ${isActive ? 'active' : ''}" data-loc="${escapeHtml(loc)}">
              <span>${escapeHtml(pack.label || loc)}</span>
              <div style="display:flex; gap:6px; align-items:center;">
                <span class="lang-badge">${escapeHtml(t("lang_custom_badge"))}</span>
                <button class="mini-btn danger del-custom-lang" data-del="${escapeHtml(loc)}" title="${escapeHtml(t("delete_custom_lang"))}" style="padding:1px 5px; font-size:11px;">×</button>
                ${isActive ? '<span>✓</span>' : ''}
              </div>
            </div>
          `;
        }

        html += `
          </div>
          <div style="border-top:1px solid var(--border); padding-top:14px; display:flex; flex-direction:column; gap:10px;">
            <div style="font-size:11px; color:var(--text-dim); line-height:1.4;">
              ${escapeHtml(t("import_pack_hint"))}
            </div>
            <div style="display:flex; gap:8px;">
              <input type="file" id="lang-pack-input" accept=".json" style="display:none;">
              <button id="import-pack-btn" class="mini-btn" style="flex:1; padding:8px 12px; font-size:12px; font-weight:600;">
                ${escapeHtml(t("import_pack_btn"))}
              </button>
              <button id="export-pack-btn" class="mini-btn" style="flex:1; padding:8px 12px; font-size:12px; font-weight:600;">
                ${escapeHtml(t("export_pack_btn"))}
              </button>
            </div>
          </div>
        `;
        return html;
      };

      const modalInstance = openModal({
        title: t("lang_settings_title"),
        bodyHtml: renderModalBody(),
        hideFooter: true,
        width: "420px",
        onOpen: (modalEl, closeFn) => {
          const bindEvents = () => {
            modalEl.querySelectorAll(".lang-item-row").forEach(row => {
              row.addEventListener("click", (e) => {
                if (e.target.closest(".del-custom-lang")) return;
                const loc = row.dataset.loc;
                if (loc) {
                  setLocale(loc);
                  modalEl.querySelector(".modal-body").innerHTML = renderModalBody();
                  bindEvents();
                }
              });
            });

            modalEl.querySelectorAll(".del-custom-lang").forEach(btn => {
              btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const loc = btn.dataset.del;
                const name = customLocales[loc]?.label || loc;
                if (confirm(t("delete_custom_confirm", { name }))) {
                  delete customLocales[loc];
                  delete I18N[loc];
                  localStorage.setItem("pig_web_custom_locales", JSON.stringify(customLocales));
                  if (currentLocale === loc) setLocale("zh-CN");
                  modalEl.querySelector(".modal-body").innerHTML = renderModalBody();
                  bindEvents();
                }
              });
            });

            const importBtn = modalEl.querySelector("#import-pack-btn");
            const packInput = modalEl.querySelector("#lang-pack-input");
            const exportBtn = modalEl.querySelector("#export-pack-btn");

            if (importBtn && packInput) {
              importBtn.addEventListener("click", () => packInput.click());
              packInput.addEventListener("change", () => {
                if (packInput.files && packInput.files[0]) {
                  importLanguagePackFile(packInput.files[0]);
                  closeFn();
                }
              });
            }

            if (exportBtn) {
              exportBtn.addEventListener("click", () => exportLanguagePack());
            }
          };

          bindEvents();
        }
      });
    }

export { I18N, currentLocale, t, __t, setLocale, applyI18n, onLocaleChanged, loadExtensionLocales, openLanguageModal as openLangModal, openLanguageModal, customLocales, extensionLocales };
