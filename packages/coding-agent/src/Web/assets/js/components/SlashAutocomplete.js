import { escapeHtml } from "../utils.js";
import { currentLocale } from "../i18n.js";

/**
 * Filtered command list optimized for the Web UI.
 * Pure TUI-only commands (`web`, `hotkeys`, `exit`, `quit`, `tree`, `label`, `resume`, `copy`, `login`, `logout`)
 * are excluded to keep the list focused, safe, and actionable in a browser.
 */
const COMMANDS = [
  {
    name: "compact",
    descZh: "压缩会话历史，释放上下文空间",
    descEn: "Summarise the conversation so far to save context",
  },
  {
    name: "export",
    descZh: "导出会话为 HTML、Markdown 或 PR 说明 (/export [md|pr])",
    descEn: "Export conversation as HTML, Markdown, or PR description (/export [md|pr])",
  },
  {
    name: "name",
    descZh: "查看或修改当前会话名称 (/name [新标题])",
    descEn: "Show or set current session title (/name [title])",
  },
  {
    name: "model",
    descZh: "切换当前大模型，或查看当前模型 (/model [名称])",
    descEn: "Switch models, or say which one (/model [name])",
  },
  {
    name: "thinking",
    descZh: "设置深度思考等级 (/thinking [off|low|medium|high])",
    descEn: "Set thinking level (/thinking [off|low|medium|high])",
  },
  {
    name: "diff",
    descZh: "查看 Git 工作区代码修改差异 (/diff [--staged])",
    descEn: "Show git working tree changes (/diff [--staged])",
  },
  {
    name: "commit",
    descZh: "智能审查代码变更并生成规范化 Git 提交",
    descEn: "Review changes and commit with Conventional Commits message",
  },
  {
    name: "session",
    descZh: "查看当前会话消耗的 Token 数量与费用",
    descEn: "Show token usage and cost for this session",
  },
  {
    name: "doctor",
    descZh: "系统运行环境与依赖健康体检",
    descEn: "Inspect system health, PHP runtime, binaries, and auth tokens",
  },
  {
    name: "accounts",
    descZh: "打开 Antigravity 账号与配额管理面板",
    descEn: "Open Antigravity accounts and quota manager",
  },
  {
    name: "antigravity.refresh",
    descZh: "刷新 Antigravity 配额状态与模型目录",
    descEn: "Refresh Antigravity quotas and model catalog",
  },
  {
    name: "antigravity.doctor",
    descZh: "运行 Antigravity 专用诊断",
    descEn: "Run Antigravity diagnostics",
  },
  {
    name: "antigravity.image",
    descZh: "使用 AI 模型生成图像 (/antigravity.image [提示词])",
    descEn: "Generate image with Antigravity (/antigravity.image [prompt])",
  },
  {
    name: "theme",
    descZh: "切换界面配色主题 (/theme [dark|light|labra])",
    descEn: "Switch theme (/theme [dark|light|labra])",
  },
  {
    name: "skills",
    descZh: "查看当前可用的 Agent 技能列表",
    descEn: "List available skills loaded into context",
  },
  {
    name: "tools",
    descZh: "查看模型当前可调用的全部工具列表",
    descEn: "List tools available to the model",
  },
  {
    name: "changelog",
    descZh: "查看 pig 版本更新日志",
    descEn: "View pig release changelog",
  },
  {
    name: "reload",
    descZh: "热重载扩展插件、技能与自定义指令",
    descEn: "Reload extensions, skills, commands, and context files",
  },
  {
    name: "bug",
    descZh: "生成错误报告并提交 GitHub Issue (/bug [问题说明])",
    descEn: "Generate bug report and open GitHub issue (/bug [details])",
  },
  {
    name: "new",
    descZh: "开启全新空白会话",
    descEn: "Start a fresh new conversation",
  },
  {
    name: "help",
    descZh: "查看所有指令与快捷键帮助",
    descEn: "Show keys and available commands",
  },
];

/**
 * SlashAutocomplete Component
 *
 * Provides floating auto-completion for slash commands when typing '/'
 * with full keyboard navigation (Up/Down/Enter/Tab/Esc) and fuzzy filtering.
 */
export class SlashAutocomplete {
  constructor({ inputElement, anchorElement, onSelect }) {
    this.input = inputElement;
    this.anchor = anchorElement || inputElement;
    this.onSelect = onSelect;
    this.isOpen = false;
    this.selectedIndex = 0;
    this.filteredCommands = [];

    this.popup = document.createElement("div");
    this.popup.className = "slash-autocomplete-popup";
    this.popup.style.display = "none";

    document.body.appendChild(this.popup);

    this.bindEvents();
  }

  bindEvents() {
    this.input.addEventListener("input", () => this.handleInput());
    this.input.addEventListener("keydown", (e) => this.handleKeyDown(e));

    // Close when clicking outside
    document.addEventListener("click", (e) => {
      if (this.isOpen && !this.popup.contains(e.target) && e.target !== this.input) {
        this.close();
      }
    });

    window.addEventListener("resize", () => {
      if (this.isOpen) this.updatePosition();
    });
  }

  handleInput() {
    const val = this.input.value;

    // Check if input starts with slash and has no space yet (or currently editing slash command)
    if (val.startsWith("/") && !val.includes(" ") && !val.includes("\n")) {
      const query = val.slice(1).toLowerCase();
      this.filter(query);
    } else {
      this.close();
    }
  }

  filter(query) {
    const isZh = currentLocale === "zh-CN";

    this.filteredCommands = COMMANDS.filter((cmd) => {
      if (!query) return true;
      const desc = isZh ? cmd.descZh : cmd.descEn;
      return cmd.name.toLowerCase().includes(query) || desc.toLowerCase().includes(query);
    });

    if (this.filteredCommands.length === 0) {
      this.close();
      return;
    }

    this.selectedIndex = 0;
    this.render();
    this.open();
  }

  render() {
    const isZh = currentLocale === "zh-CN";

    let html = '<div class="slash-popup-list">';
    this.filteredCommands.forEach((cmd, idx) => {
      const isSelected = idx === this.selectedIndex;
      const desc = isZh ? cmd.descZh : cmd.descEn;
      html += `
        <div class="slash-popup-item ${isSelected ? 'active' : ''}" data-index="${idx}">
          <span class="slash-item-name">/${escapeHtml(cmd.name)}</span>
          <span class="slash-item-desc">${escapeHtml(desc)}</span>
        </div>
      `;
    });
    html += '</div>';

    const hintText = isZh ? "↑↓ 选择 · Enter / Tab 确认 · Esc 关闭" : "↑↓ Navigate · Enter / Tab Select · Esc Close";
    html += `<div class="slash-popup-hint">${escapeHtml(hintText)}</div>`;

    this.popup.innerHTML = html;

    this.popup.querySelectorAll(".slash-popup-item").forEach((el) => {
      el.addEventListener("mouseenter", () => {
        const idx = Number(el.dataset.index);
        this.setSelectedIndex(idx, false);
      });
      el.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const idx = Number(el.dataset.index);
        this.selectCommand(this.filteredCommands[idx]);
      });
    });
  }

  handleKeyDown(e) {
    if (!this.isOpen) return;

    if (e.key === "ArrowDown") {
      e.preventDefault();
      this.setSelectedIndex((this.selectedIndex + 1) % this.filteredCommands.length, true);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      this.setSelectedIndex((this.selectedIndex - 1 + this.filteredCommands.length) % this.filteredCommands.length, true);
    } else if (e.key === "Enter" || e.key === "Tab") {
      e.preventDefault();
      e.stopPropagation();
      const chosen = this.filteredCommands[this.selectedIndex];
      if (chosen) {
        this.selectCommand(chosen);
      }
    } else if (e.key === "Escape") {
      e.preventDefault();
      e.stopPropagation();
      this.close();
    }
  }

  setSelectedIndex(index, scrollTo) {
    this.selectedIndex = index;
    const items = this.popup.querySelectorAll(".slash-popup-item");
    items.forEach((el, idx) => {
      el.classList.toggle("active", idx === index);
    });

    if (scrollTo && items[index]) {
      items[index].scrollIntoView({ block: "nearest" });
    }
  }

  selectCommand(cmd) {
    if (!cmd) return;
    const completion = `/${cmd.name} `;
    this.input.value = completion;
    this.input.focus();
    this.input.selectionStart = this.input.selectionEnd = completion.length;
    this.close();

    if (typeof this.onSelect === "function") {
      this.onSelect(cmd);
    }
  }

  updatePosition() {
    const rect = this.anchor.getBoundingClientRect();
    const margin = 8;
    this.popup.style.left = `${Math.max(16, rect.left)}px`;
    this.popup.style.bottom = `${window.innerHeight - rect.top + margin}px`;
    this.popup.style.width = `${Math.min(520, Math.max(340, rect.width))}px`;
  }

  open() {
    this.updatePosition();
    this.popup.style.display = "flex";
    this.isOpen = true;
  }

  close() {
    this.popup.style.display = "none";
    this.isOpen = false;
  }

  destroy() {
    this.popup.remove();
  }
}
