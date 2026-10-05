import { escapeHtml } from "../utils.js";
import { t, currentLocale } from "../i18n.js";

/** Lightweight ANSI escape sequence to styled HTML converter */
function ansiToHtml(str) {
  if (!str) return "";
  const ansiColors = {
    "30": "#64748b", "31": "#ef4444", "32": "#22c55e", "33": "#eab308",
    "34": "#3b82f6", "35": "#a855f7", "36": "#06b6d4", "37": "#f8fafc",
    "90": "#94a3b8", "91": "#f87171", "92": "#4ade80", "93": "#facc15",
    "94": "#60a5fa", "95": "#c084fc", "96": "#22d3ee", "97": "#ffffff"
  };

  let clean = escapeHtml(str);
  // Replace standard color codes
  clean = clean.replace(/\x1b\[([0-9;]+)m/g, (match, codes) => {
    const parts = codes.split(";");
    if (parts.includes("0")) return '</span>';
    let style = "";
    if (parts.includes("1")) style += "font-weight:bold;";
    for (const code of parts) {
      if (ansiColors[code]) style += `color:${ansiColors[code]};`;
    }
    return style ? `<span style="${style}">` : "";
  });

  // Strip other ANSI control sequences
  clean = clean.replace(/\x1b\[[0-9;]*[a-zA-Z]/g, "");
  return clean;
}

/**
 * WebTerminal Component
 *
 * Full-featured lightweight browser terminal drawer with:
 * - Dynamic cwd awareness defaulting to active tab's workspace
 * - Fast ANSI color output rendering (git status, ls, tests, diffs)
 * - Command history navigation via Up/Down arrow keys
 * - Built-in cd directory tracking
 * - Maximize/minimize drawer controls
 */
export class WebTerminal {
  constructor({ getActiveCwd, onCwdChanged }) {
    this.getActiveCwd = getActiveCwd;
    this.onCwdChanged = onCwdChanged;
    this.cwd = getActiveCwd() || "";
    this.user = "user";
    this.hostname = "pig";
    this.home = "/";

    this.history = [];
    this.historyIndex = -1;
    this.tempInput = "";
    this.isRunning = false;
    this.isMaximized = false;
    this.isOpen = false;

    this.initElement();
    this.fetchInfo();
  }

  initElement() {
    this.element = document.createElement("div");
    this.element.className = "web-terminal-drawer";
    this.element.style.display = "none";

    this.element.innerHTML = `
      <div class="web-term-header">
        <div class="web-term-header-left">
          <span class="web-term-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="4 17 10 11 4 5"></polyline>
              <line x1="12" y1="19" x2="20" y2="19"></line>
            </svg>
          </span>
          <span class="web-term-title">Web Terminal</span>
          <span class="web-term-cwd" title="Click to copy path">~</span>
        </div>
        <div class="web-term-header-right">
          <button class="web-term-quick-btn" data-cmd="git status" title="git status">git status</button>
          <button class="web-term-quick-btn" data-cmd="ls -la" title="ls -la">ls -la</button>
          <button class="web-term-btn web-term-clear-btn" title="Clear (Ctrl+L)">🧹</button>
          <button class="web-term-btn web-term-max-btn" title="Toggle Fullscreen">⛶</button>
          <button class="web-term-btn web-term-close-btn" title="Close (Esc)">✕</button>
        </div>
      </div>
      <div class="web-term-viewport">
        <div class="web-term-output"></div>
        <div class="web-term-prompt-line">
          <span class="web-term-prompt-prefix">
            <span class="term-user">user@pig</span>:<span class="term-path">~</span><span class="term-dollar">$</span>
          </span>
          <input type="text" class="web-term-input" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off">
          <span class="web-term-loader" style="display:none;">⏳</span>
        </div>
      </div>
    `;

    this.outputEl = this.element.querySelector(".web-term-output");
    this.inputEl = this.element.querySelector(".web-term-input");
    this.promptPrefix = this.element.querySelector(".web-term-prompt-prefix");
    this.cwdBadge = this.element.querySelector(".web-term-cwd");
    this.loaderEl = this.element.querySelector(".web-term-loader");

    this.bindEvents();
    document.body.appendChild(this.element);
  }

  bindEvents() {
    this.element.querySelector(".web-term-close-btn").addEventListener("click", () => this.close());
    this.element.querySelector(".web-term-clear-btn").addEventListener("click", () => this.clear());
    this.element.querySelector(".web-term-max-btn").addEventListener("click", () => this.toggleMaximize());

    this.cwdBadge.addEventListener("click", () => {
      navigator.clipboard.writeText(this.cwd).then(() => {
        const orig = this.cwdBadge.textContent;
        this.cwdBadge.textContent = "✓ Copied";
        setTimeout(() => this.updatePrompt(), 1200);
      });
    });

    this.element.querySelectorAll(".web-term-quick-btn").forEach(btn => {
      btn.addEventListener("click", () => {
        const cmd = btn.getAttribute("data-cmd");
        if (cmd) {
          this.inputEl.value = cmd;
          this.executeCommand(cmd);
        }
      });
    });

    this.inputEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        const cmd = this.inputEl.value.trim();
        if (cmd) {
          this.executeCommand(cmd);
        }
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        this.navigateHistory(-1);
      } else if (e.key === "ArrowDown") {
        e.preventDefault();
        this.navigateHistory(1);
      } else if (e.key === "Escape") {
        this.close();
      } else if (e.key === "l" && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        this.clear();
      }
    });

    // Keep focus inside terminal viewport when clicking anywhere in terminal
    this.element.querySelector(".web-term-viewport").addEventListener("click", () => {
      this.inputEl.focus();
    });
  }

  async fetchInfo() {
    try {
      const activeCwd = this.getActiveCwd();
      const res = await fetch(`/api/terminal/info?cwd=${encodeURIComponent(activeCwd || "")}`);
      if (res.ok) {
        const data = await res.json();
        this.user = data.user || this.user;
        this.hostname = data.hostname || this.hostname;
        this.cwd = data.cwd || this.cwd;
        this.home = data.home || this.home;
        this.updatePrompt();
      }
    } catch (e) {}
  }

  formatPath(p) {
    if (!p) return "~";
    if (this.home && p.startsWith(this.home)) {
      return "~" + p.slice(this.home.length);
    }
    return p;
  }

  updatePrompt() {
    const short = this.formatPath(this.cwd);
    this.cwdBadge.textContent = short;
    this.cwdBadge.title = this.cwd;
    this.promptPrefix.innerHTML = `<span class="term-user">${escapeHtml(this.user)}@${escapeHtml(this.hostname)}</span>:<span class="term-path">${escapeHtml(short)}</span><span class="term-dollar">$</span> `;
  }

  navigateHistory(delta) {
    if (this.history.length === 0) return;

    if (this.historyIndex === -1) {
      this.tempInput = this.inputEl.value;
    }

    let newIndex = this.historyIndex + delta;
    if (newIndex < -1) newIndex = -1;
    if (newIndex >= this.history.length) newIndex = this.history.length - 1;

    this.historyIndex = newIndex;
    if (this.historyIndex === -1) {
      this.inputEl.value = this.tempInput;
    } else {
      this.inputEl.value = this.history[this.history.length - 1 - this.historyIndex] || "";
    }
  }

  async executeCommand(cmd) {
    if (this.isRunning) return;

    // Add to history
    this.history.push(cmd);
    this.historyIndex = -1;
    this.tempInput = "";

    const shortPath = this.formatPath(this.cwd);
    const lineRecord = document.createElement("div");
    lineRecord.className = "term-history-entry";
    lineRecord.innerHTML = `
      <div class="term-command-line">
        <span class="term-user">${escapeHtml(this.user)}@${escapeHtml(this.hostname)}</span>:<span class="term-path">${escapeHtml(shortPath)}</span><span class="term-dollar">$</span>
        <span class="term-command-text">${escapeHtml(cmd)}</span>
      </div>
      <div class="term-command-output"><span class="term-running-spinner">⏳ Running...</span></div>
    `;

    this.outputEl.appendChild(lineRecord);
    this.inputEl.value = "";
    this.isRunning = true;
    this.loaderEl.style.display = "inline-block";
    this.scrollToBottom();

    const outputContainer = lineRecord.querySelector(".term-command-output");

    try {
      const res = await fetch("/api/terminal/exec", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ command: cmd, cwd: this.cwd }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        outputContainer.innerHTML = `<span style="color:#ef4444;">Error: ${escapeHtml(data.error || "Command failed")}</span>`;
      } else {
        let outHtml = "";
        if (data.stdout) outHtml += ansiToHtml(data.stdout);
        if (data.stderr) {
          outHtml += `<span style="color:#f87171;">${ansiToHtml(data.stderr)}</span>`;
        }

        if (data.exitCode !== 0) {
          outHtml += `<div class="term-exit-pill" style="color:#ef4444; font-size:11px; margin-top:4px;">✖ exited with code ${data.exitCode}</div>`;
        }

        outputContainer.innerHTML = outHtml || `<span style="color:#64748b; font-style:italic;">(no output)</span>`;

        if (data.cwd && data.cwd !== this.cwd) {
          this.cwd = data.cwd;
          this.updatePrompt();
          if (typeof this.onCwdChanged === "function") {
            this.onCwdChanged(this.cwd);
          }
        }
      }
    } catch (err) {
      outputContainer.innerHTML = `<span style="color:#ef4444;">Network error: ${escapeHtml(err.message)}</span>`;
    } finally {
      this.isRunning = false;
      this.loaderEl.style.display = "none";
      this.scrollToBottom();
      this.inputEl.focus();
    }
  }

  clear() {
    this.outputEl.innerHTML = "";
    this.inputEl.focus();
  }

  toggleMaximize() {
    this.isMaximized = !this.isMaximized;
    this.element.classList.toggle("maximized", this.isMaximized);
    const maxBtn = this.element.querySelector(".web-term-max-btn");
    maxBtn.textContent = this.isMaximized ? "🗗" : "⛶";
    this.scrollToBottom();
    this.inputEl.focus();
  }

  scrollToBottom() {
    const viewport = this.element.querySelector(".web-term-viewport");
    if (viewport) {
      viewport.scrollTop = viewport.scrollHeight;
    }
  }

  open() {
    const activeCwd = this.getActiveCwd();
    if (activeCwd && activeCwd !== this.cwd) {
      this.cwd = activeCwd;
      this.updatePrompt();
    }

    this.element.style.display = "flex";
    this.isOpen = true;
    requestAnimationFrame(() => {
      this.element.classList.add("visible");
      this.inputEl.focus();
      this.scrollToBottom();
    });
  }

  close() {
    this.element.classList.remove("visible");
    setTimeout(() => {
      if (!this.element.classList.contains("visible")) {
        this.element.style.display = "none";
        this.isOpen = false;
      }
    }, 200);
  }

  toggle() {
    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  }
}
