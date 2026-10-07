import { network } from "../network.js";
import { D as Terminal, o as FitAddon } from "../vendor/xterm.js";

/** Build xterm color scheme matching pig's active UI theme */
function getTermTheme() {
  const theme = document.documentElement.getAttribute("data-theme") || "dark";
  if (theme === "light") {
    return {
      background: "#ffffff",
      foreground: "#1e293b",
      cursor: "#0284c7",
      cursorAccent: "#ffffff",
      selectionBackground: "rgba(2, 132, 199, 0.3)",
      black: "#1e293b",
      red: "#e11d48",
      green: "#16a34a",
      yellow: "#ca8a04",
      blue: "#2563eb",
      magenta: "#9333ea",
      cyan: "#0891b2",
      white: "#f8fafc",
      brightBlack: "#64748b",
      brightRed: "#f43f5e",
      brightGreen: "#22c55e",
      brightYellow: "#eab308",
      brightBlue: "#3b82f6",
      brightMagenta: "#a855f7",
      brightCyan: "#06b6d4",
      brightWhite: "#ffffff",
    };
  }

  if (theme === "labra") {
    return {
      background: "#121714",
      foreground: "#e2e6e3",
      cursor: "#ff66cc",
      cursorAccent: "#121714",
      selectionBackground: "rgba(255, 102, 204, 0.35)",
      black: "#1a1e1b",
      red: "#f87171",
      green: "#4ade80",
      yellow: "#facc15",
      blue: "#60a5fa",
      magenta: "#ff66cc",
      cyan: "#22d3ee",
      white: "#e2e6e3",
      brightBlack: "#4b5563",
      brightRed: "#ef4444",
      brightGreen: "#22c55e",
      brightYellow: "#eab308",
      brightBlue: "#3b82f6",
      brightMagenta: "#f472b6",
      brightCyan: "#06b6d4",
      brightWhite: "#ffffff",
    };
  }

  // Dark default
  return {
    background: "#070a12",
    foreground: "#e2e8f0",
    cursor: "#38bdf8",
    cursorAccent: "#070a12",
    selectionBackground: "rgba(56, 189, 248, 0.3)",
    black: "#0f172a",
    red: "#ef4444",
    green: "#22c55e",
    yellow: "#eab308",
    blue: "#3b82f6",
    magenta: "#a855f7",
    cyan: "#06b6d4",
    white: "#f8fafc",
    brightBlack: "#64748b",
    brightRed: "#f87171",
    brightGreen: "#4ade80",
    brightYellow: "#facc15",
    brightBlue: "#60a5fa",
    brightMagenta: "#c084fc",
    brightCyan: "#22d3ee",
    brightWhite: "#ffffff",
  };
}

/**
 * WebTerminal Component
 *
 * Full-featured interactive PTY pseudo-terminal powered by xterm.js:
 * - Real terminal emulation (supports vim, htop, less, interactive git, ssh)
 * - Multi-tab terminal sessions (create, close, switch tabs)
 * - 16ms micro-batching non-blocking streaming
 * - Window sizing and auto-fit on drawer resize / maximize
 * - Theme-synchronized terminal palettes (Dark, Labra, Light)
 * - Native clipboard copy / paste
 */
export class WebTerminal {
  constructor({ getActiveCwd, onCwdChanged }) {
    this.getActiveCwd = getActiveCwd;
    this.onCwdChanged = onCwdChanged;
    this.cwd = getActiveCwd() || "";

    this.isOpen = false;
    this.isMaximized = false;
    this.tabs = [];
    this.activeTabId = null;
    this.tabCounter = 1;

    this.initElement();
    this.initNetwork();
    this.observeTheme();
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
          <div class="web-term-tabs-container">
            <div class="web-term-tabs-list" id="web-term-tabs-list"></div>
            <button class="web-term-add-tab-btn" id="web-term-add-tab-btn" title="New Terminal Tab">+</button>
          </div>
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
      <div class="web-term-viewport" id="web-term-viewport"></div>
    `;

    this.tabsListEl = this.element.querySelector("#web-term-tabs-list");
    this.addTabBtn = this.element.querySelector("#web-term-add-tab-btn");
    this.viewportEl = this.element.querySelector("#web-term-viewport");
    this.cwdBadge = this.element.querySelector(".web-term-cwd");

    this.bindEvents();
    document.body.appendChild(this.element);
  }

  bindEvents() {
    this.element.querySelector(".web-term-close-btn").addEventListener("click", () => this.close());
    this.element.querySelector(".web-term-clear-btn").addEventListener("click", () => this.clearActive());
    this.element.querySelector(".web-term-max-btn").addEventListener("click", () => this.toggleMaximize());
    this.addTabBtn.addEventListener("click", () => this.createTab());

    this.cwdBadge.addEventListener("click", () => {
      const activeCwd = this.getActiveCwd() || this.cwd;
      navigator.clipboard.writeText(activeCwd).then(() => {
        const orig = this.cwdBadge.textContent;
        this.cwdBadge.textContent = "✓ Copied";
        setTimeout(() => this.updateCwdBadge(), 1200);
      });
    });

    this.element.querySelectorAll(".web-term-quick-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const cmd = btn.getAttribute("data-cmd");
        if (cmd) {
          this.sendInput(cmd + "\r");
        }
      });
    });

    window.addEventListener("resize", () => {
      if (this.isOpen) {
        this.fitActiveTab();
      }
    });
  }

  initNetwork() {
    network.addMessageListener((msg) => {
      if (!msg || typeof msg !== "object") return;

      if (msg.type === "terminal_output") {
        const tab = this.tabs.find((t) => t.id === msg.terminalId);
        if (tab && tab.term) {
          tab.term.write(msg.data);
        }
      } else if (msg.type === "terminal_exit") {
        const tab = this.tabs.find((t) => t.id === msg.terminalId);
        if (tab) {
          tab.exitCode = msg.exitCode;
          tab.term?.write(`\r\n\x1b[90m[Process exited with code ${msg.exitCode ?? 0}]\x1b[0m\r\n`);
          this.renderTabs();
        }
      }
    });
  }

  observeTheme() {
    const observer = new MutationObserver(() => {
      const newTheme = getTermTheme();
      for (const t of this.tabs) {
        if (t.term) {
          t.term.options.theme = newTheme;
        }
      }
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
  }

  createTab(command = null, name = null) {
    const id = "term-" + Math.random().toString(36).slice(2, 9);
    const tabName = name || `Term ${this.tabCounter++}`;
    const targetCwd = this.getActiveCwd() || this.cwd || "/";

    const container = document.createElement("div");
    container.className = "web-term-xterm-instance";
    container.style.display = "none";
    this.viewportEl.appendChild(container);

    const term = new Terminal({
      theme: getTermTheme(),
      fontFamily: '"SF Mono", "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace',
      fontSize: 13,
      cursorBlink: true,
      scrollback: 8000,
      allowProposedApi: true,
    });

    const fit = new FitAddon();
    term.loadAddon(fit);
    term.open(container);

    // Native copy / paste binding
    term.attachCustomKeyEventHandler((event) => {
      if (event.type !== "keydown") return true;
      const key = event.key?.toLowerCase();
      if ((event.ctrlKey || event.metaKey) && key === "v") {
        return false;
      }
      if (event.ctrlKey && !event.shiftKey && !event.altKey && key === "c") {
        if (term.hasSelection()) {
          const ta = term.textarea;
          if (ta) {
            ta.value = term.getSelection();
            ta.select();
          }
          return false;
        }
      }
      return true;
    });

    term.onData((data) => {
      network.send({
        type: "terminal_input",
        terminalId: id,
        data,
      });
    });

    const tab = {
      id,
      name: tabName,
      cwd: targetCwd,
      container,
      term,
      fit,
      exitCode: null,
    };

    this.tabs.push(tab);
    this.renderTabs();
    this.switchTab(id);

    // Initial spawn on backend
    requestAnimationFrame(() => {
      try {
        fit.fit();
      } catch (e) {}

      network.send({
        type: "terminal_create",
        terminalId: id,
        cwd: targetCwd,
        cols: term.cols || 80,
        rows: term.rows || 24,
        command: command || undefined,
      });
    });

    return tab;
  }

  switchTab(id) {
    const tab = this.tabs.find((t) => t.id === id);
    if (!tab) return;

    this.activeTabId = id;

    for (const t of this.tabs) {
      if (t.id === id) {
        t.container.style.display = "block";
      } else {
        t.container.style.display = "none";
      }
    }

    this.renderTabs();

    requestAnimationFrame(() => {
      try {
        tab.fit.fit();
        network.send({
          type: "terminal_resize",
          terminalId: tab.id,
          cols: tab.term.cols,
          rows: tab.term.rows,
        });
      } catch (e) {}
      tab.term.focus();
    });
  }

  closeTab(id, e) {
    if (e) {
      e.stopPropagation();
    }

    const index = this.tabs.findIndex((t) => t.id === id);
    if (index === -1) return;

    const tab = this.tabs[index];
    network.send({ type: "terminal_kill", terminalId: id });

    tab.term.dispose();
    tab.container.remove();
    this.tabs.splice(index, 1);

    if (this.activeTabId === id) {
      const nextTab = this.tabs[index] || this.tabs[index - 1] || null;
      if (nextTab) {
        this.switchTab(nextTab.id);
      } else {
        this.activeTabId = null;
        this.close();
      }
    } else {
      this.renderTabs();
    }
  }

  renderTabs() {
    this.tabsListEl.innerHTML = "";

    for (const t of this.tabs) {
      const tabEl = document.createElement("div");
      tabEl.className = "web-term-tab" + (t.id === this.activeTabId ? " active" : "");
      tabEl.innerHTML = `
        <span class="web-term-tab-name">${t.name}</span>
        ${t.exitCode !== null ? `<span class="web-term-tab-status">[exit]</span>` : ""}
        <button class="web-term-tab-close" title="Close">×</button>
      `;

      tabEl.addEventListener("click", () => this.switchTab(t.id));
      tabEl.querySelector(".web-term-tab-close").addEventListener("click", (e) => this.closeTab(t.id, e));

      this.tabsListEl.appendChild(tabEl);
    }
  }

  fitActiveTab() {
    const tab = this.tabs.find((t) => t.id === this.activeTabId);
    if (tab && tab.fit && tab.term) {
      try {
        tab.fit.fit();
        network.send({
          type: "terminal_resize",
          terminalId: tab.id,
          cols: tab.term.cols,
          rows: tab.term.rows,
        });
      } catch (e) {}
    }
  }

  clearActive() {
    const tab = this.tabs.find((t) => t.id === this.activeTabId);
    if (tab && tab.term) {
      tab.term.clear();
      this.sendInput("\x0c"); // Ctrl+L
    }
  }

  sendInput(data) {
    if (!this.activeTabId) return;
    network.send({
      type: "terminal_input",
      terminalId: this.activeTabId,
      data,
    });
  }

  updateCwdBadge() {
    const targetCwd = this.getActiveCwd() || this.cwd || "~";
    this.cwdBadge.textContent = targetCwd.length > 36 ? "..." + targetCwd.slice(-33) : targetCwd;
    this.cwdBadge.title = targetCwd;
  }

  toggle() {
    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  }

  open() {
    this.element.style.display = "flex";
    requestAnimationFrame(() => {
      this.element.classList.add("visible");
      this.isOpen = true;
      this.updateCwdBadge();

      if (this.tabs.length === 0) {
        this.createTab();
      } else {
        this.fitActiveTab();
        const tab = this.tabs.find((t) => t.id === this.activeTabId);
        if (tab && tab.term) {
          tab.term.focus();
        }
      }
    });
  }

  close() {
    this.element.classList.remove("visible");
    this.isOpen = false;
    setTimeout(() => {
      if (!this.isOpen) {
        this.element.style.display = "none";
      }
    }, 220);
  }

  toggleMaximize() {
    this.isMaximized = !this.isMaximized;
    this.element.classList.toggle("maximized", this.isMaximized);
    const maxBtn = this.element.querySelector(".web-term-max-btn");
    if (maxBtn) {
      maxBtn.textContent = this.isMaximized ? "🗗" : "⛶";
    }
    setTimeout(() => this.fitActiveTab(), 220);
  }
}
