/**
 * Main application controller for pig web UI (Native ES Module).
 */

import "./viewport.js";
import { escapeHtml, cleanSessionTitle, formatSessionFilename, openModal, enterModalLayer, leaveModalLayer } from "./utils.js";
import { t, __t, currentLocale, setLocale, applyI18n, onLocaleChanged, loadExtensionLocales, openLangModal, customLocales, extensionLocales } from "./i18n.js";
import { openThemeModal, initTheme, applyTheme, hasTheme, onThemeApplied, currentTheme } from "./theme.js";
import { clientId, network } from "./network.js";
import { renderMarkdown, renderDiff, renderDiffFromUnified, copyCode } from "./markdown.js";
import { createPanels } from "./panels.js";
import { ThinkingBlock } from "./components/ThinkingBlock.js";
import { ToolCard } from "./components/ToolCard.js";
import { EmptyState } from "./components/EmptyState.js";
import { openImageLightbox } from "./components/ImageLightbox.js";
import { SlashAutocomplete } from "./components/SlashAutocomplete.js";
import { primeAttention, notifyAway } from "./attention.js";
import { createHookState, applyHookRequest, widgetsAt, statusHtml, ansiToHtml, notifyKind, countdownTitle, timeoutSeconds, slashCommandsFrom } from "./hooks.js";
import { WebTerminal } from "./components/WebTerminal.js";
import { NodeWorkbench } from "./components/NodeWorkbench.js";
import { openScheduledTasks, watchScheduledTasks } from "./components/ScheduledTasks.js";
import { openCwdPicker, folderName } from "./components/CwdPicker.js";

    const chatArea = document.getElementById("chat-area");
    const promptInput = document.getElementById("prompt-input");
    const sendBtn = document.getElementById("send-btn");
    const stopBtn = document.getElementById("stop-btn");
    const attachBtn = document.getElementById("attach-btn");
    const fileInput = document.getElementById("file-input");
    const pendingImagesBar = document.getElementById("pending-images");
    const toggleSidebar = document.getElementById("toggle-sidebar");
    const sidebar = document.getElementById("sidebar");
    const sidebarList = document.getElementById("sidebar-list");
    const cwdDisplay = document.getElementById("cwd-display");
    const sessionTitleDisplay = document.getElementById("session-title-display");
    const sessionFileBadge = document.getElementById("session-file-badge");
    const sessionFileText = document.getElementById("session-file-text");
    const filenameDisplay = sessionFileText;
    const telemetryStats = document.getElementById("telemetry-stats");
    const providerSelect = document.getElementById("provider-select");
    const modelSelect = document.getElementById("model-select");
    const thinkingSelect = document.getElementById("thinking-select");
    const scrollBottomBtn = document.getElementById("scroll-bottom-btn");
    const newSessionBtn = document.getElementById("new-session-btn");
    const searchSessionsBtn = document.getElementById("search-sessions-btn");
    const sidebarBackdrop = document.getElementById("sidebar-backdrop");
    const langBtn = document.getElementById("lang-btn");
    const themeBtn = document.getElementById("theme-btn");

    let tabSeq = 0;

    // 32 hex digits. `crypto.getRandomValues()` rather than `randomUUID()`, which a page served over
    // plain http to another device does not have.
    const randomHex = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, "0")).join("");
    // This page load's id, the same across reconnects (`HttpServer`'s `start_session`).
    const pageId = randomHex();

    class Tab {
      constructor(cwd, sessionFile) {
        this.id = "tab-" + (++tabSeq);
        this.cwd = cwd;
        this.sessionFile = sessionFile || null;   // null until the child names its file
        this.sessionPath = null;
        this.name = null;
        this.opening = null;
        this.bound = false;
        this.pending = new Map();                 // rpc id -> {resolve, reject, timer}
        this.nextId = 0;
        this.state = null;                        // last get_state answer
        this.stats = null;
        this.ended = false;                       // the child is gone; next bind restarts it

        // Stream state, per tab.
        this.isRunning = false;
        // What is waiting to go in, from `queue_update`, and what has just left that queue and is
        // about to arrive as a user message — drawn then, and not when it was typed.
        this.queue = { steering: [], followUp: [] };
        this.delivering = [];
        this.currentAssistantBlock = null;
        this.currentAssistantText = "";
        this.currentThinkingText = "";
        this.currentThinkingBox = null;
        this.userScrolledUp = false;
        this.toolCards = new Map();
        this.unsent = null;             // {text, requestId} of a prompt whose answer was lost (see sendPrompt)
        this.bashCards = new Map();   // rpc id -> ToolCard for a `!command`
        this.bashRunning = false;     // a `!command` is in flight (Stop sends abort_bash)
        this._emptyState = null;
        this.hook = createHookState();  // what this child's extensions have put on screen
        this.commands = [];             // `get_commands`: extension, prompt template and skill commands
        this.messagesLoaded = false;    // until the first `get_messages` is drawn, which clears the chat
        this.earlyNotices = [];         // `notify` lines that arrived before that, drawn after it

        this.scroller = document.createElement("div");
        this.scroller.className = "chat-scroll";
        this.scroller.hidden = true;
        // Following the turn stops only when the user scrolls up — a scroll this page makes only ever
        // goes down — and resumes once they are back within 80px of the bottom.
        this.lastScrollTop = 0;
        this.scroller.addEventListener("scroll", () => {
          const top = this.scroller.scrollTop;
          const d = this.scroller.scrollHeight - top - this.scroller.clientHeight;
          if (d <= 80) this.userScrolledUp = false;
          else if (top < this.lastScrollTop) this.userScrolledUp = true;
          this.lastScrollTop = top;
          if (this === active) scrollBottomBtn.style.display = this.userScrolledUp ? "flex" : "none";
          // Coming up on the top of what is drawn: draw the part before it (`backfill()`), asking
          // the child for it first once what was fetched has all been drawn (`fetchOlder()`).
          if (this.olderMessages && nearTop(this)) backfill(this, 0);
          else if (this.remoteStart > 0 && nearTop(this)) fetchOlder(this);
        });
        // A block that grows after it was added (an image decoding, a card filling in) is followed too.
        const blockResized = new ResizeObserver(() => {
          if (!this.userScrolledUp) this.scroller.scrollTop = this.scroller.scrollHeight;
        });
        new MutationObserver((records) => {
          for (const r of records) {
            for (const n of r.addedNodes) if (n.nodeType === Node.ELEMENT_NODE) blockResized.observe(n);
            for (const n of r.removedNodes) if (n.nodeType === Node.ELEMENT_NODE) blockResized.unobserve(n);
          }
        }).observe(this.scroller, { childList: true });
        chatArea.appendChild(this.scroller);
      }

      // The welcome shown until the first message. While it is there the conversation has not
      // started, and its folder can still be changed (`drawCwdChip()`).
      get emptyState() { return this._emptyState; }
      set emptyState(value) {
        this._emptyState = value;
        if (this === active) drawCwdChip();
      }

      get key() { return (this.cwd || "") + "::" + (this.sessionFile || ("__new__::" + clientId + ":" + this.id)); }

      subscribe() {
        this.bound = false;
        network.send({
          type: "start_session",
          tabId: this.id,
          cwd: this.cwd,
          sessionFile: this.sessionFile || undefined,
          // So a reconnect finds this tab's child again even before its conversation is saved.
          pageId,
          // The page's theme, so a child started for this tab draws its extensions' colours in it
          // from the first line — `session_start` included.
          theme: currentTheme || undefined,
        });
      }

      close() {
        network.send({ type: "detach_session", tabId: this.id });
        this.failPending("tab closed");
        this.scroller.remove();
      }

      onConnectionLost() {
        this.bound = false;
        this.failPending("connection closed");
      }

      /** Send an rpc command and resolve with its response's `data` (or reject on failure). */
      rpc(command, timeoutMs = 30000, onId = null) {
        return new Promise((resolve, reject) => {
          if (!this.bound) { reject(new Error("no active session")); return; }
          const id = "ui-" + (++this.nextId);
          if (typeof onId === "function") onId(id);
          // timeoutMs <= 0 means "wait as long as it takes": a `!command` has no deadline.
          const timer = timeoutMs > 0 ? setTimeout(() => {
            this.pending.delete(id);
            reject(new Error(`${command.type}: no answer in ${timeoutMs / 1000}s`));
          }, timeoutMs) : null;
          this.pending.set(id, { resolve, reject, timer });
          network.send({
            type: "rpc_command",
            tabId: this.id,
            command: { ...command, id },
          });
        });
      }

      failPending(why) {
        for (const [id, p] of this.pending) { clearTimeout(p.timer); p.reject(new Error(why)); }
        this.pending.clear();
      }
      // ---- what the server says ----------------------------------------------------------
      onServerMessage(m) {
        if (m.type === "session_bound") {
          this.bound = true;
          this.ended = false;
          if (m.sessionFile) this.sessionFile = m.sessionFile;
          if (this === active) { pigReachable(); hideStatusBanner(); }
          this.onBound();
          return;
        }
        if (m.type === "session_ended") {
          this.bound = false;
          this.ended = true;
          this.failPending("the agent exited");
          this.render(() => { setRunningState(false); appendErrorMessage(t("agent_exited", { reason: m.reason ? ": " + m.reason : "" })); });
          agentWorkingChanged();
          return;
        }
        if (m.type === "error") {
          const p = m.id ? this.pending.get(m.id) : null;
          if (p) { this.pending.delete(m.id); clearTimeout(p.timer); p.reject(new Error(m.message)); }
          this.render(() => {
            setRunningState(false);
            appendErrorMessage(m.message);
          });
          return;
        }
        if (m.type === "rpc_event") this.onRpcEvent(m.event);
      }

      onRpcEvent(evt) {
        if (!evt) return;
        if (evt.type === "response") {
          const p = this.pending.get(evt.id);
          if (p) {
            this.pending.delete(evt.id); clearTimeout(p.timer);
            if (evt.success) p.resolve(evt.data ?? null);
            else p.reject(Object.assign(new Error(evt.error || `${evt.command} refused`), { refused: true }));
          } else if (!evt.success) {
            // An asynchronous failure (e.g. prompt threw in child process) or mismatched ID:
            // render the error banner so failures never disappear in silence.
            this.render(() => {
              setRunningState(false);
              appendErrorMessage(evt.error || `${evt.command || "command"} failed`);
            });
          }
          return;
        }
        if (evt.type === "bash_execution_update") {
          const card = this.bashCards?.get(evt.id);
          if (card) card.updateProgress((card.rawOutput || "") + (evt.delta || ""));
          return;
        }
        if (evt.type === "extension_ui_request") { this.render(() => onHookUiRequest(evt)); return; }
        if (evt.type === "extension_error" || evt.type === "tool_error") {
          this.render(() => appendErrorMessage((evt.extensionPath ? evt.extensionPath + ": " : "") + evt.error));
          return;
        }
        this.render(() => onEvent(evt));
      }

      /** Run `fn` with this tab as the one the DOM helpers draw into. */
      render(fn) {
        const before = renderingTab;
        renderingTab = this;
        try { fn(); } finally { renderingTab = before; }
      }

      async onBound() {
        // A child someone else started — another browser, a scheduled run — draws in whatever
        // theme it was given; this page's is the one it should draw in now.
        this.tellTheme(currentTheme);
        // A fresh child, or a reconnect to one: load what it has, then ask what it is. A tab in
        // the background loads its conversation when it is opened (`openTab()`): after a reconnect
        // every tab binds at once, and every history at once is the biggest thing a page asks
        // the server to send it.
        if (this === active) await this.loadMessages();
        else this.needsMessages = true;
        await this.refreshState();
        if (this === active) applyActiveTabToChrome();
      }

      /** pig's `set_theme`: what `theme()` gives this child's extensions is the page's theme. */
      tellTheme(theme) {
        if (!theme || !this.bound) return;
        // A theme only this page has (a child in another directory finds other custom themes) is
        // refused, and the child keeps the one it had: nothing to tell anyone.
        this.rpc({ type: "set_theme", theme }).catch(() => {});
      }

      /** The commands the `/` list offers beyond the built-ins, as the TUI's list does. */
      async loadCommands() {
        try {
          this.commands = slashCommandsFrom(await this.rpc({ type: "get_commands" }));
          if (this === active) slashAutocomplete?.setExtraCommands(extraCommands(this));
        } catch (err) { if (this === active && !this.ended) pigUnreachable(t("what_command_list"), err); }
      }

      async refreshState() {
        try {
          const [s, stats] = await Promise.all([this.rpc({ type: "get_state" }), this.rpc({ type: "get_session_stats" })]);
          this.state = s;
          this.stats = stats;
          this.isPersisted = (typeof s.isPersisted === "boolean") ? s.isPersisted : !!(s.sessionFile && (s.messageCount > 0));
          if (this.isPersisted && s.sessionFile) {
            this.sessionPath = s.sessionFile;
            this.sessionFile = s.sessionFile.split("/").pop();
          } else if (!this.isPersisted) {
            this.sessionPath = null;
            this.sessionFile = null;
          }
          if (s.cwd) this.cwd = s.cwd;
          this.name = s.sessionName || null;
          if (s.opening) this.opening = s.opening;
          this.isRunning = !!s.isStreaming;
          this.render(() => setRunningState(this.isRunning));
          this.showStoppedMidRun();
          saveTabs(); renderTabs();
          if (this === active) applyActiveTabToChrome();
        } catch (err) { if (this === active && !this.ended) pigUnreachable(t("what_session_state"), err); }
        // Apart, so a child that cannot list its commands still has its state drawn — and after
        // every refresh, so a `/reload` that brought new ones is offered on the next `/`.
        this.loadCommands();
      }

      /**
       * pig's: a conversation a stopped process left mid-run says so under it, with a button that
       * carries it on (`continue`, `AgentSession::continueRun()`) — the TUI's notice and `/continue`.
       * Nothing runs on its own. Drawn once both the state and the conversation are in.
       */
      showStoppedMidRun() {
        this.scroller.querySelector(".msg-stopped")?.remove();
        if (!this.messagesLoaded || !this.state?.isStoppedMidRun || this.isRunning) return;
        this.render(() => {
          const block = document.createElement("div");
          block.className = "msg-block msg-warning msg-stopped";
          block.innerHTML = `${escapeHtml(t("stopped_mid_run"))} <button class="modal-btn primary">${escapeHtml(t("continue_run"))}</button>`;
          block.querySelector("button").addEventListener("click", async () => {
            block.remove();
            this.state.isStoppedMidRun = false;
            try {
              const { outcome } = await this.rpc({ type: "continue" });
              if (outcome === "finished") this.render(() => appendNoticeMessage(t("continue_finished")));
            } catch (err) {
              this.render(() => appendErrorMessage(err.message || t("send_failed")));
            }
          });
          chatScroll.appendChild(block);
          scrollToBottomIfNeeded();
        });
      }

      async loadMessages() {
        try {
          // The end of the conversation only (`RpcMode::messagesPage()`): the whole of a long one —
          // screenshots and all — was hundreds of megabytes, more than the server could pass on.
          // The rest is asked for as the reader scrolls up (`fetchOlder()`). A child from before
          // `limit` answers with everything and no `start`, which is drawn as it was.
          this.needsMessages = false;
          const token = this.remoteToken = {};
          const d = await this.rpc({ type: "get_messages", limit: FIRST_LOAD, ...imageRefs(this) });
          if (this.remoteToken !== token) return;
          this.render(() => renderMessages(withImageUrls(this, d.messages || [])));
          this.remoteStart = Number.isInteger(d.start) ? d.start : 0;
          // A `session_start` handler's `notify` arrives before the conversation does, and drawing
          // the conversation clears the chat; the TUI keeps those lines, so they are put back.
          if (!this.messagesLoaded) {
            this.messagesLoaded = true;
            this.render(() => this.earlyNotices.forEach(drawNotify));
            this.earlyNotices = [];
          }
          this.showStoppedMidRun();
        } catch (err) { if (this === active && !this.ended) pigUnreachable(t("what_conversation"), err); }
      }

      close() {
        this.closed = true;
        if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
        if (this.ws) { try { this.ws.onclose = null; this.ws.close(); } catch (e) {} }
        this.failPending("tab closed");
        this.scroller.remove();
      }
    }




    let tabs = [];
    let active = null;        // the tab the person is looking at
    let slashAutocomplete = null;
    let renderingTab = null;  // the tab an event is being drawn into right now (see Tab.render)

    // The old page-wide names, resolved against the tab being rendered into (an event) or the
    // active one (a keystroke). Reads and writes both go to the same tab, so the helpers below
    // are unchanged.
    const T = () => renderingTab || active;
    try {
      Object.defineProperties(window, {
        // `renderTarget`: where an older part of a long conversation is being drawn, off the page
        // (`backfill()`); otherwise the tab's scroller.
        chatScroll:            { configurable: true, get: () => T()?.renderTarget || T()?.scroller || chatArea },
        isRunning:             { configurable: true, get: () => !!T()?.isRunning,            set: (v) => { if (T()) T().isRunning = v; } },
        currentAssistantBlock: { configurable: true, get: () => T()?.currentAssistantBlock ?? null, set: (v) => { if (T()) T().currentAssistantBlock = v; } },
        currentAssistantText:  { configurable: true, get: () => T()?.currentAssistantText ?? "",    set: (v) => { if (T()) T().currentAssistantText = v; } },
        currentThinkingText:   { configurable: true, get: () => T()?.currentThinkingText ?? "",     set: (v) => { if (T()) T().currentThinkingText = v; } },
        currentThinkingBox:    { configurable: true, get: () => T()?.currentThinkingBox ?? null,    set: (v) => { if (T()) T().currentThinkingBox = v; } },
        userScrolledUp:        { configurable: true, get: () => !!T()?.userScrolledUp,       set: (v) => { if (T()) T().userScrolledUp = v; } },
      });
    } catch (e) {}

    // 3-Level Cascading Selectors: Provider -> Model -> Thinking Level
    let currentProvider = "";
    let currentModelId = "";
    let availableModels = [];
    let pendingImages = []; // list of {mimeType: string, data: string, url: string}
    let userIsInteracting = false;
    let preferredThinkingLevel = "medium";

    // Sidebar Toggle
    toggleSidebar.addEventListener("click", () => {
      sidebar.classList.toggle("collapsed");
      toggleSidebar.innerText = sidebar.classList.contains("collapsed") ? "›" : "‹";
      refreshSidebar();
    });

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener("click", () => {
        sidebar.classList.add("collapsed");
        toggleSidebar.innerText = "›";
      });
    }

    // Wide enough for the conversations beside the chat — a MacBook, an iPad held sideways — the
    // sidebar starts open; a phone or an iPad upright keeps it closed, where it would cover or
    // squeeze the chat. Crossing the width (turning the iPad, resizing the window) opens or closes
    // it once; a toggle in between is left alone until the next crossing.
    const wideScreen = window.matchMedia("(min-width: 1024px)");
    const sidebarFollowsWidth = () => {
      sidebar.classList.toggle("collapsed", !wideScreen.matches);
      toggleSidebar.innerText = wideScreen.matches ? "‹" : "›";
      refreshSidebar();
    };
    wideScreen.addEventListener("change", sidebarFollowsWidth);

    // ---- custom dropdown over native <select> ------------------------------------------------
    //
    // A native <select> opens its list wherever the OS decides: macOS anchors it on the *selected*
    // row, so with the sixth of seven providers chosen the menu floats a hundred pixels above the
    // pill with nothing between. The menu here is a DOM element anchored flush above the pill,
    // whatever is selected. The <select> stays as the source of truth (its options, its value,
    // its "change" event) so the three cascading handlers below do not know the difference.
    //
    // Touch devices keep the native picker — iOS's bottom wheel is the better control there, and
    // a hover-driven menu is the wrong shape for a thumb.
    const isTouchDevice = window.matchMedia("(hover: none) and (pointer: coarse)").matches;
    let openDropdown = null;

    function closeDropdown() {
      if (!openDropdown) return;
      openDropdown.menu.classList.remove("show");
      openDropdown.wrap.classList.remove("open");
      openDropdown = null;
    }

    // `floating`: the menu opens below the select at a fixed position, for a select inside a
    // dialog, whose scrolling body would otherwise clip a menu hung off the select.
    function enhanceSelect(select, { floating = false } = {}) {
      if (isTouchDevice) return;

      const wrap = document.createElement("div");
      wrap.className = "select-wrap";
      select.parentNode.insertBefore(wrap, select);
      wrap.appendChild(select);

      const menu = document.createElement("div");
      menu.className = "dropdown-menu" + (floating ? " floating" : "");
      menu.setAttribute("role", "listbox");
      wrap.appendChild(menu);

      let activeIndex = -1;

      function build() {
        menu.innerHTML = "";
        const options = Array.from(select.options);
        options.forEach((opt, i) => {
          const item = document.createElement("div");
          item.className = "dropdown-item" + (opt.selected ? " selected" : "") + (opt.disabled ? " disabled" : "");
          item.setAttribute("role", "option");
          item.dataset.index = String(i);
          item.textContent = opt.textContent;
          if (!opt.disabled) {
            item.addEventListener("mousedown", (e) => { e.preventDefault(); choose(i); });
            item.addEventListener("mouseenter", () => setActive(i));
          }
          menu.appendChild(item);
        });
        activeIndex = select.selectedIndex;
      }

      function setActive(i) {
        activeIndex = i;
        menu.querySelectorAll(".dropdown-item").forEach((el, idx) => {
          el.classList.toggle("active", idx === i);
        });
        const el = menu.children[i];
        if (el && typeof el.scrollIntoView === "function") {
          el.scrollIntoView({ block: "nearest" });
        }
      }

      function choose(i) {
        if (i < 0 || i >= select.options.length || select.options[i].disabled) return;
        const changed = select.selectedIndex !== i;
        select.selectedIndex = i;
        close();
        if (changed) select.dispatchEvent(new Event("change", { bubbles: true }));
      }

      function open() {
        if (select.disabled) return;
        closeDropdown();
        build();
        if (floating) {
          const r = select.getBoundingClientRect();
          menu.style.top = `${r.bottom + 6}px`;
          menu.style.left = `${r.left}px`;
          menu.style.width = `${r.width}px`;
          menu.style.maxHeight = `${Math.max(120, Math.min(280, window.innerHeight - r.bottom - 18))}px`;
        }
        menu.classList.add("show");
        wrap.classList.add("open");
        openDropdown = { menu, wrap };
        setActive(select.selectedIndex);
      }

      function close() {
        if (openDropdown && openDropdown.menu === menu) closeDropdown();
      }

      // Swallow the native popup: mousedown is what opens it, and preventing that leaves the
      // pill focusable and the keyboard working on the <select> itself.
      select.addEventListener("mousedown", (e) => {
        e.preventDefault();
        select.focus();
        if (menu.classList.contains("show")) close(); else open();
      });

      select.addEventListener("keydown", (e) => {
        const isOpen = menu.classList.contains("show");
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
          e.preventDefault();
          if (!isOpen) { open(); return; }
          const dir = e.key === "ArrowDown" ? 1 : -1;
          let next = activeIndex;
          for (let n = 0; n < select.options.length; n++) {
            next = (next + dir + select.options.length) % select.options.length;
            if (!select.options[next].disabled) break;
          }
          setActive(next);
        } else if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          if (isOpen) choose(activeIndex); else open();
        } else if (e.key === "Escape" && isOpen) {
          e.preventDefault();
          close();
        } else if (e.key === "Tab") {
          close();
        }
      });

      select.addEventListener("blur", () => setTimeout(close, 0));
    }

    document.addEventListener("mousedown", (e) => {
      if (openDropdown && !openDropdown.wrap.contains(e.target)) closeDropdown();
    });
    window.addEventListener("resize", closeDropdown);
    // A floating menu stays where it was opened, so scrolling whatever holds the select closes it.
    window.addEventListener("scroll", (e) => {
      if (openDropdown?.menu.classList.contains("floating") && !openDropdown.menu.contains(e.target)) closeDropdown();
    }, true);

    enhanceSelect(providerSelect);
    enhanceSelect(modelSelect);
    enhanceSelect(thinkingSelect);

    // Auto-grow textarea
    promptInput.addEventListener("input", () => {
      promptInput.style.height = "40px";
      promptInput.style.height = Math.max(40, Math.min(promptInput.scrollHeight, 160)) + "px";
    });

    // Return to bottom (whether a tab follows its turn is the tab's own scroll listener)
    scrollBottomBtn.addEventListener("click", () => {
      userScrolledUp = false;
      scrollBottomBtn.style.display = "none";
      chatScroll.scrollTo({ top: chatScroll.scrollHeight, behavior: "smooth" });
    });

    function scrollToBottomIfNeeded() {
      if (!userScrolledUp) {
        chatScroll.scrollTop = chatScroll.scrollHeight;
      }
    }

    /** The tab bar shows a working dot per tab, so any tab's running state changing redraws it. */
    function agentWorkingChanged() {
      renderTabs();
    }

    let isComposing = false;
    let compositionEndTime = 0;
    promptInput.addEventListener("compositionstart", () => { isComposing = true; });
    promptInput.addEventListener("compositionend", () => {
      isComposing = false;
      compositionEndTime = Date.now();
    });

    promptInput.addEventListener("keydown", (e) => {
      // Enter sends. Shift+Enter and Option/Alt+Enter are left to the textarea, which breaks the
      // line on both. While a turn runs, Enter steers it and Cmd/Ctrl+Enter queues the message
      // for after it — the TUI's keys (`app.message.followUp`), where Alt+Enter is a new line too.
      const isPlainEnter = e.key === "Enter" && !e.shiftKey && !e.altKey;
      const isModifierEnter = e.key === "Enter" && (e.metaKey || e.ctrlKey);

      if (isPlainEnter || isModifierEnter) {
        if (e.isComposing || isComposing || e.keyCode === 229 || (Date.now() - compositionEndTime < 60)) {
          return;
        }
        e.preventDefault();
        hideCommandHud();
        submitMessage(isModifierEnter);
      } else if (e.key === "Escape" && isRunning) {
        e.preventDefault();
        abortTurn();
      }
      // Note: Shift+Enter naturally inserts a newline into the textarea!
    });

    // Command key long-press HUD (Blink Shell / iPadOS strict solo-modifier style)
    let commandHudTimer = null;
    let isCommandHudOpen = false;
    let metaIsCombo = false;
    const commandHudModal = document.getElementById("command-hud-modal");

    function showCommandHud() {
      if (isCommandHudOpen || metaIsCombo) return;
      isCommandHudOpen = true;
      if (commandHudModal) commandHudModal.style.display = "flex";
    }

    function hideCommandHud() {
      if (commandHudTimer) {
        clearTimeout(commandHudTimer);
        commandHudTimer = null;
      }
      if (isCommandHudOpen) {
        isCommandHudOpen = false;
        if (commandHudModal) commandHudModal.style.display = "none";
      }
    }

    document.addEventListener("keydown", (e) => {
      if (e.key === "Meta") {
        // Strict Solo-Modifier check: if Shift, Alt, or Ctrl is pressed simultaneously
        // (such as macOS Shift+Cmd+4 screenshot shortcut), abort immediately!
        if (e.shiftKey || e.altKey || e.ctrlKey) {
          metaIsCombo = true;
          hideCommandHud();
          return;
        }

        if (!commandHudTimer && !isCommandHudOpen) {
          metaIsCombo = false;
          // 850ms threshold matching iPadOS/Blink standards to eliminate hesitation false-positives
          commandHudTimer = setTimeout(() => {
            showCommandHud();
          }, 850);
        }
      } else {
        // Any subsequent keypress immediately marks this Command press as a combo and cancels HUD
        metaIsCombo = true;
        hideCommandHud();
      }
    });

    document.addEventListener("keyup", (e) => {
      if (e.key === "Meta") {
        metaIsCombo = false;
        hideCommandHud();
      }
    });

    window.addEventListener("blur", () => {
      metaIsCombo = false;
      hideCommandHud();
    });

    sendBtn.addEventListener("click", () => submitMessage());
    stopBtn.addEventListener("click", abortTurn);
    attachBtn.addEventListener("click", () => fileInput.click());
    newSessionBtn.addEventListener("click", () => startNewSession());
    searchSessionsBtn.addEventListener("click", () => openSessionSearch());

    // File input attachment
    fileInput.addEventListener("change", (e) => {
      handleFiles(e.target.files);
      fileInput.value = "";
    });

    // Clipboard image paste
    window.addEventListener("paste", (e) => {
      const items = e.clipboardData?.items;
      if (!items) return;
      const files = [];
      for (const item of items) {
        if (item.type.indexOf("image") !== -1) {
          const file = item.getAsFile();
          if (file) files.push(file);
        }
      }
      if (files.length > 0) {
        handleFiles(files);
      }
    });

    // Drag and drop images
    window.addEventListener("dragover", (e) => e.preventDefault());
    window.addEventListener("drop", (e) => {
      e.preventDefault();
      if (e.dataTransfer?.files?.length) {
        handleFiles(e.dataTransfer.files);
      }
    });

    function handleFiles(files) {
      for (const f of files) {
        if (!f.type.startsWith("image/")) continue;
        const reader = new FileReader();
        reader.onload = (e) => {
          const result = e.target.result;
          const base64 = result.split(",")[1];
          pendingImages.push({
            mimeType: f.type,
            data: base64,
            url: result,
          });
          renderPendingImages();
        };
        reader.readAsDataURL(f);
      }
    }

    function renderPendingImages() {
      if (pendingImages.length === 0) {
        pendingImagesBar.style.display = "none";
        pendingImagesBar.innerHTML = "";
        return;
      }
      pendingImagesBar.style.display = "flex";
      pendingImagesBar.innerHTML = "";
      pendingImages.forEach((img, idx) => {
        const item = document.createElement("div");
        item.className = "pending-img-item";
        item.innerHTML = `
          <img src="${img.url}" class="pending-img-thumb" alt="${escapeHtml(t("attach_title"))}">
          <div class="pending-img-del" title="${escapeHtml(t("remove_image"))}">×</div>
        `;
        item.querySelector(".pending-img-del").addEventListener("click", () => {
          pendingImages.splice(idx, 1);
          renderPendingImages();
        });
        pendingImagesBar.appendChild(item);
      });
    }

    function setRunningState(running) {
      isRunning = running;
      // The buttons are the page's, so only the tab being looked at drives them; a background
      // tab finishing its turn must not flip the stop button under a foreground one still running.
      if (T() === active) {
        sendBtn.style.display = running ? "none" : "flex";
        stopBtn.style.display = running ? "flex" : "none";
      }
      agentWorkingChanged();
    }

    // `Prompt\SkillBlock::parse()`'s pattern: a `/skill:name` prompt is recorded as the skill's
    // text in a `<skill>` block, with whatever the person typed after it below. The TUI draws
    // that as a collapsed `[skill] name` line (`SkillInvocationMessageComponent`); before this
    // the page poured the whole SKILL.md into the user bubble on every replay.
    const SKILL_BLOCK = /^<skill name="([^"]+)" location="([^"]+)">\n([\s\S]*?)\n<\/skill>(?:\n\n([\s\S]+))?$/;

    function appendSkillInvocation(name, location, content) {
      const block = document.createElement("details");
      block.className = "compaction-box hook-message skill-invocation";
      block.innerHTML = `<summary class="compaction-header"><span class="compaction-title">[skill]</span> ${escapeHtml(name)}<span class="skill-location">${escapeHtml(location)}</span></summary><div class="compaction-summary-text prose">${renderMarkdown(content)}</div>`;
      chatScroll.appendChild(block);
    }

    function appendUserMessage(text, images = []) {
      const tab = T();
      if (tab?.emptyState) {
        tab.emptyState.remove();
        tab.emptyState = null;
      }
      chatScroll.querySelector(".empty-state-hero")?.remove();

      const skill = text ? text.match(SKILL_BLOCK) : null;
      if (skill) {
        appendSkillInvocation(skill[1], skill[2], skill[3]);
        text = skill[4] || "";
        if (!text && images.length === 0) {
          scrollToBottomIfNeeded();
          return;
        }
      }

      const block = document.createElement("div");
      block.className = "msg-block msg-user";

      if (text) {
        const p = document.createElement("div");
        p.className = "msg-user-text prose";
        p.innerHTML = renderMarkdown(text);
        block.appendChild(p);

        // Also check if text contains an image file path (like clipboard paste /path/to/pig-clipboard-xxx.png)
        const match = text.match(/^\s*(\/[^\s\n]+\.(?:png|jpg|jpeg|gif|webp))/i);
        if (match && images.length === 0) {
          const imgPath = match[1];
          const grid = document.createElement("div");
          grid.className = "msg-images-grid";
          const imgEl = document.createElement("img");
          imgEl.src = `/api/file?path=${encodeURIComponent(imgPath)}`;
          imgEl.className = "msg-image-thumb";
          imgEl.title = t("clipboard_image", { path: imgPath });
          imgEl.addEventListener("click", () => openImageLightbox(imgEl.src));
          grid.appendChild(imgEl);
          block.appendChild(grid);
        }
      }

      if (images.length > 0) {
        const grid = document.createElement("div");
        grid.className = "msg-images-grid";
        for (const img of images) {
          const src = img.url || `data:${img.mimeType};base64,${img.data}`;
          const imgEl = document.createElement("img");
          imgEl.loading = "lazy";
          imgEl.src = src;
          imgEl.className = "msg-image-thumb";
          imgEl.title = t("open_full_image");
          imgEl.addEventListener("click", () => openImageLightbox(src));
          grid.appendChild(imgEl);
        }
        block.appendChild(grid);
      }

      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    function appendErrorMessage(errorText) {
      const block = document.createElement("div");
      block.className = "msg-block msg-error";
      block.innerHTML = `<strong>Error:</strong> ${escapeHtml(errorText)}`;
      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    // The TUI's `Warning:` line, which `notify(…, "warning")` draws.
    function appendWarningMessage(text) {
      const block = document.createElement("div");
      block.className = "msg-block msg-warning";
      block.innerHTML = `<strong>Warning:</strong> ${escapeHtml(text)}`;
      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    // The TUI's `say()`: one dim line in the transcript for something the session did on its
    // own (a model fallback, say) — not an error, not the model speaking.
    function appendNoticeMessage(text) {
      const block = document.createElement("div");
      block.className = "msg-block msg-notice";
      block.innerText = text;
      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    function ensureAssistantBlock() {
      if (!currentAssistantBlock) {
        currentAssistantBlock = document.createElement("div");
        currentAssistantBlock.className = "msg-block msg-assistant prose";
        chatScroll.appendChild(currentAssistantBlock);
      }
      return currentAssistantBlock;
    }

    // ---- events from a tab's child -----------------------------------------------------------
    //
    // Always called inside `Tab.render()`, so `chatScroll`, `currentAssistantBlock` and the rest
    // resolve to the tab the event belongs to — a turn in a background tab draws into that
    // tab's hidden scroller. `refreshState()` with no argument is the rendering tab's.
    function onEvent(evt) {
      const tab = T();
      if (tab?.emptyState) {
        tab.emptyState.remove();
        tab.emptyState = null;
      }
      chatScroll.querySelector(".empty-state-hero")?.remove();

      if (evt.type === "queue_update") {
        const was = [...tab.queue.steering, ...tab.queue.followUp];
        tab.queue = { steering: evt.steering || [], followUp: evt.followUp || [] };
        const now = [...tab.queue.steering, ...tab.queue.followUp];
        // What left the queue is either about to be delivered or was taken back; `message_start`
        // tells which, and the turn's end forgets the rest.
        for (const text of was) {
          const at = now.indexOf(text);
          if (at === -1) tab.delivering.push(text); else now.splice(at, 1);
        }
        if (tab === active) renderQueue();
        return;
      }

      if (evt.type === "message_start" && evt.message?.role === "user") {
        const content = Array.isArray(evt.message.content) ? evt.message.content : [{ type: "text", text: String(evt.message.content ?? "") }];
        const text = content.filter((c) => c.type === "text").map((c) => c.text).join("");
        // By its text, as `AgentSession` takes it off the queue; else the oldest, for a queued
        // `/template` whose text was expanded on the way in.
        let at = tab.delivering.indexOf(text);
        if (at === -1 && tab.delivering.length > 0) at = 0;
        if (at !== -1) {
          tab.delivering.splice(at, 1);
          appendUserMessage(text, content.filter((c) => c.type === "image").map((c) => ({ mimeType: c.mimeType, data: c.data })));
        }
      }

      if (evt.type === "message_start") {
        if (currentThinkingBox?.finish) {
          currentThinkingBox.finish();
        }
        currentAssistantBlock = null;
        currentAssistantText = "";
      } else if (evt.type === "message_end") {
        const message = evt.message;
        if (message?.role === "custom") appendHookMessage(message);
        if (message?.role === "compactionSummary" || message?.role === "branchSummary") appendSummaryMessage(message);
        if (message?.role === "assistant" && message.stopReason === "aborted" && !message.content?.some(c => c.type === "toolCall")) {
          appendErrorMessage(t("operation_aborted"));
        }
      } else if (evt.type === "agent_start" || evt.type === "turn_start") {
        setRunningState(true);
        // A run started — by the button or anyone else — so it is not stopped any more.
        if (tab?.state) tab.state.isStoppedMidRun = false;
        tab?.scroller.querySelector(".msg-stopped")?.remove();
      } else if (evt.type === "agent_end" || evt.type === "turn_end") {
        setRunningState(false);
        if (currentThinkingBox?.finish) {
          currentThinkingBox.finish();
        }
        currentAssistantBlock = null;
        currentAssistantText = "";
        currentThinkingText = "";
        currentThinkingBox = null;
        if (evt.type === "agent_end") {
          if (tab) tab.delivering = [];
          tab?.refreshState();
          refreshSidebar();
        }
        const err = evt.error || lastErrorMessage(evt.messages);
        if (err) {
          appendErrorMessage(err);
        }
      } else if (evt.type === "message_update") {
        // `RpcMode` sends upstream's `assistantMessageEvent`, the delta alone; it is what is drawn.
        const d = evt.assistantMessageEvent || {};
        if (d.type === "thinking_delta") {
          currentThinkingText += d.delta || "";
          const box = ensureAssistantBlock();
          if (!currentThinkingBox) {
            currentThinkingBox = new ThinkingBlock();
            box.insertBefore(currentThinkingBox.element, box.firstChild);
          }
          if (currentThinkingBox.appendDelta) {
            currentThinkingBox.appendDelta(d.delta || "");
          }
          scrollToBottomIfNeeded();
        } else if (d.type === "text_delta") {
          if (currentThinkingBox?.finish) {
            currentThinkingBox.finish();
          }
          currentAssistantText += d.delta || "";
          const box = ensureAssistantBlock();
          let prose = box.querySelector(".assistant-prose");
          if (!prose) {
            prose = document.createElement("div");
            prose.className = "assistant-prose";
            box.appendChild(prose);
          }
          prose.innerHTML = renderMarkdown(currentAssistantText);
          scrollToBottomIfNeeded();
        }
      } else if (evt.type === "tool_execution_start") {
        if (currentThinkingBox?.finish) {
          currentThinkingBox.finish();
        }
        currentAssistantBlock = null;
        currentAssistantText = "";
        tab.toolCards = tab.toolCards || new Map();
        // A call a stopped run left without a result was drawn with the conversation; carrying the
        // run on (`continue`) answers it in that card rather than in a second one.
        if (!tab.toolCards.get(evt.toolCallId)?.element.isConnected) {
          const card = new ToolCard({ ...evt, arguments: evt.args });
          tab.toolCards.set(evt.toolCallId, card);
          chatScroll.appendChild(card.element);
        }
        scrollToBottomIfNeeded();
      } else if (evt.type === "tool_execution_update") {
        const card = tab?.toolCards?.get(evt.toolCallId);
        if (card) {
          card.updateProgress(textOf(evt.partialResult?.content));
        } else {
          updateToolCardProgress({ toolCallId: evt.toolCallId, output: textOf(evt.partialResult?.content) });
        }
      } else if (evt.type === "tool_execution_end") {
        const card = tab?.toolCards?.get(evt.toolCallId);
        if (card) {
          card.finish({ result: evt.result, isError: evt.isError });
        } else {
          updateToolCard({ toolCallId: evt.toolCallId, result: evt.result, isError: evt.isError });
        }
        currentAssistantBlock = null;
        currentAssistantText = "";
      } else if (evt.type === "auto_retry_start") {
        if (tab === active) showStatusBanner(t("retrying_banner", { delay: Math.round((evt.delayMs || 0) / 1000), attempt: evt.attempt, maxAttempts: evt.maxAttempts, error: evt.errorMessage }));
      } else if (evt.type === "auto_retry_end") {
        if (tab === active) hideStatusBanner();
        tab?.refreshState();
        if (!evt.success && evt.finalError) {
          appendErrorMessage(evt.finalError);
        }
      } else if (evt.type === "model_fallback") {
        // pig's own event (v0.5.4): the quota wall moved the session to a `fallbackModels` entry
        // and the turn is already running again on it. Same sentence as the TUI, and the model
        // bar is re-read so it shows where the session went.
        (tab ? tab.refreshState() : Promise.resolve()).then(() => {
          const level = tab?.state?.thinkingLevel;
          const suffix = level && level !== "off" ? ` · ${t("thinking_level_suffix", { level })}` : "";
          appendNoticeMessage(t("model_fallback_notice", { from: `${evt.from.provider}/${evt.from.id}`, to: `${evt.to.provider}/${evt.to.id}` }) + suffix);
        });
      } else if (evt.type === "summarization_retry_scheduled") {
        // Upstream's three summarization retry events: a compaction or branch summary hit a
        // transient error and will be tried again — the same banner the turn's own retry uses.
        if (evt.errorMessage) appendErrorMessage(evt.errorMessage);
        if (tab === active) showStatusBanner(t("retrying_banner", { delay: Math.round((evt.delayMs || 0) / 1000), attempt: evt.attempt, maxAttempts: evt.maxAttempts, error: evt.errorMessage || "" }));
      } else if (evt.type === "summarization_retry_attempt_start") {
        if (tab === active) showStatusBanner(t(evt.source === "branchSummary" ? "summarising_branch_banner" : "compacting_banner"));
      } else if (evt.type === "summarization_retry_finished") {
        if (tab === active) hideStatusBanner();
      } else if (evt.type === "compaction_start") {
        if (tab === active) showStatusBanner(t("compacting_banner"));
      } else if (evt.type === "compaction_end") {
        if (tab === active) hideStatusBanner();
        if (evt.result) appendSummaryMessage({ role: "compactionSummary", summary: evt.result.summary, tokensBefore: evt.result.tokensBefore, timestamp: Date.now() });
        if (evt.errorMessage) appendErrorMessage(evt.errorMessage);
        tab?.refreshState();
      } else if (evt.type === "session_info_changed") {
        if (tab) {
          tab.name = evt.name || null;
          saveTabs();
          renderTabs();
          if (tab === active) applyActiveTabToChrome();
        }
        tab?.refreshState();
        refreshSidebar();
      }
    }

    /** Extract any fatal error recorded on the last assistant message of a turn. */
    function lastErrorMessage(messages) {
      if (!Array.isArray(messages) || messages.length === 0) return null;
      const last = messages[messages.length - 1];
      if (!last) return null;
      if (last.errorMessage) return last.errorMessage;
      if (last.stopReason === "error") return t("generation_error");
      return null;
    }

    /** The text blocks of an rpc content list, joined — what a tool's partial output reads as. */
    function textOf(content) {
      if (!Array.isArray(content)) return "";
      return content.filter((c) => c && c.type === "text").map((c) => c.text || "").join("");
    }

    function createToolCard(evt) {
      return new ToolCard(evt).element;
    }

    /**
     * The card for a tool call — in the tab being rendered into, not the document. Two children
     * can mint the same `call_N` id, and `getElementById` would find whichever tab drew first.
     */
    function toolCardFor(toolCallId) {
      const root = chatScroll;
      return root ? root.querySelector(`#${CSS.escape("tool-" + toolCallId)}`) : null;
    }

    function updateToolCard(evt) {
      const card = toolCardFor(evt.toolCallId);
      if (!card) return;

      let args = {};
      try {
        if (card.dataset.args) args = JSON.parse(card.dataset.args);
      } catch (e) {}
      const replacement = new ToolCard({
        toolCallId: evt.toolCallId,
        toolName: card.dataset.tool || "tool",
        arguments: args,
      });
      replacement.finish({ result: evt.result, isError: evt.isError });
      card.replaceWith(replacement.element);
    }

    function updateToolCardProgress(evt) {
      const card = toolCardFor(evt.toolCallId);
      if (!card) return;

      const body = card.querySelector(".tool-body");
      if (body && evt.output) {
        body.innerHTML = `<div class="tool-terminal">${escapeHtml(evt.output)}</div>`;
        body.scrollTop = body.scrollHeight;
      }
    }

    function showStatusBanner(text) {
      const banner = document.getElementById("status-banner");
      if (banner) {
        banner.innerText = text;
        banner.style.display = "flex";
      }
    }

    // A page that cannot reach pig must say so. Every loader used to swallow its failure, so a
    // server that was not answering looked like a page that was still loading — "Loading
    // session..." in the header, for ever, with nothing to read. One function owns the sentence
    // and retries; a later success clears it.
    let unreachable = null;
    function pigUnreachable(what, err) {
      const why = err && err.message ? err.message : String(err);
      unreachable = t("unreachable", { error: `${what}: ${why}` });
      showStatusBanner(unreachable);
      setTimeout(() => { refreshState(); active?.loadMessages(); if (availableModels.length === 0) loadModels(); }, 3000);
    }
    function pigReachable() {
      if (unreachable) { unreachable = null; hideStatusBanner(); }
      window.webTerminal?.reattachAll();
    }
    async function fetchJson(url, what) {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const d = await res.json();
      pigReachable();
      return d;
    }

    function hideStatusBanner() {
      const banner = document.getElementById("status-banner");
      if (banner) {
        banner.style.display = "none";
        banner.innerText = "";
      }
    }

    /** A new conversation is a new tab — and a new child. */
    async function startNewSession(cwd = null) {
      pendingImages = [];
      renderPendingImages();

      // A cwd passed in (the tab bar's '+') wins; otherwise the active tab's, then the server's.
      const targetCwd = (typeof cwd === "string" && cwd.trim() !== "")
        ? cwd.trim()
        : (active?.cwd || serverCwd);

      openTab(new Tab(targetCwd, null));

      if (window.innerWidth <= 768 && !sidebar.classList.contains("collapsed")) {
        sidebar.classList.add("collapsed");
        toggleSidebar.innerText = "›";
      }

      refreshSidebar();
    }

    async function submitMessage(followUp = false) {
      // A keystroke or a click: the moment a page may unlock sound and ask about notifications.
      primeAttention();
      const text = promptInput.value.trim();
      const imgsToSend = [...pendingImages];

      if (!text && imgsToSend.length === 0) return;

      promptInput.value = "";
      promptInput.style.height = "38px";
      pendingImages = [];
      renderPendingImages();

      if (!active) return;
      const tab = active;

      // ---- `!command` / `!!command` ---------------------------------------------------------
      // The TUI's two bash doors, over rpc. `!` runs the command and its output joins the
      // conversation as a BashExecution (the model reads it next turn, zero tokens to run);
      // `!!` runs it and remembers nothing. Neither goes anywhere near the model to execute,
      // which is what makes it deterministic — before this they were sent as prompt text and
      // the model decided whether to run them. The card streams through `bash_execution_update` events
      // and the Stop button sends `abort_bash`, so a `flutter run` can be ended from here.
      if (text.startsWith("!")) {
        const remember = !text.startsWith("!!");
        const command = text.replace(/^!!?/, "").trim();
        if (!command) return;
        await runBangCommand(tab, command, remember);
        return;
      }

      // ---- the slash commands the page answers itself -------------------------------------
      // Each is an rpc command to the active tab's child (`RpcMode`'s `doctor`, `bug`, `diff`,
      // `export_markdown`): the child owns the session, so the child answers about it.
      if (text === "/doctor") {
        appendUserMessage(text);
        try {
          const data = await active.rpc({ type: "doctor" });
          const card = document.createElement("div");
          card.className = "tool-card";
          card.innerHTML = `
            <div class="tool-header">
              <span class="tool-header-title">pig doctor</span>
              <span class="tool-header-status">${escapeHtml(t("doctor_status"))}</span>
            </div>
            <div class="tool-body"><pre><code>${escapeHtml(data.plain || "")}</code></pre></div>
          `;
          chatScroll.appendChild(card);
        } catch (err) { appendErrorMessage(err.message); }
        scrollToBottomIfNeeded();
        return;
      }

      // A command an extension's panel answers in this page — `/accounts` opens Antigravity's.
      if (panels.openForCommand(text)) return;

      if (text === "/bug" || text.startsWith("/bug ")) {
        appendUserMessage(text);
        const hint = text.slice(4).trim();
        const includeTranscript = confirm(t("bug_include_transcript"));
        try {
          const data = await active.rpc({ type: "bug", hint, includeTranscript });
          const block = document.createElement("div");
          block.className = "msg-block msg-assistant prose";
          block.innerHTML = `<p>\u2713 Bug report written to <code>${escapeHtml(data.path)}</code>.</p><p><a href="${escapeHtml(data.issueUrl)}" target="_blank" rel="noopener" style="color:var(--color-primary);">Open a GitHub issue with it prefilled \u2197</a></p>`;
          chatScroll.appendChild(block);
          if (navigator.clipboard) navigator.clipboard.writeText(data.report).catch(() => {});
        } catch (err) { appendErrorMessage(err.message); }
        scrollToBottomIfNeeded();
        return;
      }

      if (text === "/export" || text.startsWith("/export ")) {
        appendUserMessage(text);
        const sub = text.slice(7).trim();
        const asPr = sub === "pr" || sub.startsWith("pr ");
        const asMd = asPr || sub === "md" || sub === "markdown";
        const loading = document.createElement("div");
        loading.className = "msg-block msg-assistant";
        loading.innerText = t(asPr ? "export_pr_generating" : "exporting");
        chatScroll.appendChild(loading);
        scrollToBottomIfNeeded();
        try {
          if (asMd) {
            const data = await active.rpc({ type: "export_markdown", format: asPr ? "pr" : "md" }, 180000);
            loading.remove();
            if (asPr) {
              const card = document.createElement("div");
              card.className = "msg-block msg-assistant prose";
              card.innerHTML = renderMarkdown(data.markdown || "");
              chatScroll.appendChild(card);
            } else {
              const blob = new Blob([data.markdown || ""], { type: "text/markdown" });
              window.open(URL.createObjectURL(blob), "_blank");
            }
          } else {
            const data = await active.rpc({ type: "export_html" });
            loading.remove();
            const block = document.createElement("div");
            block.className = "msg-block msg-assistant prose";
            block.innerHTML = `<p>\u2713 Exported to <code>${escapeHtml(data.path || "")}</code></p>`;
            chatScroll.appendChild(block);
          }
        } catch (err) { loading.remove(); appendErrorMessage(err.message); }
        scrollToBottomIfNeeded();
        return;
      }

      if (text === "/diff" || text.startsWith("/diff ")) {
        appendUserMessage(text);
        try {
          const data = await active.rpc({ type: "diff", args: text.slice(5).trim() });
          if (data.diff) {
            const card = document.createElement("div");
            card.className = "tool-card";
            card.innerHTML = `
              <div class="tool-header">
                <span class="tool-header-title">git diff</span>
                <span class="tool-header-status">${escapeHtml(t("diff_status"))}</span>
              </div>
              <div class="tool-body">${renderDiffFromUnified(data.diff)}</div>
            `;
            chatScroll.appendChild(card);
          } else {
            const empty = document.createElement("div");
            empty.className = "msg-block msg-assistant";
            empty.innerText = t("diff_empty");
            chatScroll.appendChild(empty);
          }
        } catch (err) { appendErrorMessage(err.message); }
        scrollToBottomIfNeeded();
        return;
      }

      // A tab whose child exited restarts it on the next message: re-bind, wait for the child,
      // then send. `subscribe()` with the same file reaches the same key, and the pool spawns anew.
      if (tab.ended || !tab.bound) {
        tab.subscribe();
        const until = Date.now() + 10000;
        while (!tab.bound && Date.now() < until) await new Promise((r) => setTimeout(r, 50));
        if (!tab.bound) {
          promptInput.value = text;
          pendingImages = imgsToSend;
          renderPendingImages();
          appendErrorMessage(t("agent_unreachable"));
          return;
        }
      }

      setRunningState(true);

      // Always `prompt`, always with a `streamingBehavior`: the child decides whether a turn is
      // running at the moment the message arrives, and only then does the behavior count. The
      // page used to decide from `tab.state.isStreaming`, which is `get_state`'s answer from when
      // the tab was bound and never follows a turn, so a message sent mid-turn went out as a
      // plain `prompt` and came back "Agent is already processing".
      const images = imgsToSend.map(img => ({ type: "image", mimeType: img.mimeType, data: img.data }));
      let requestId = null;
      try {
        // Drawn by what the child did with it, as the TUI does: a message that started a turn is
        // the conversation now; a queued one waits above the input as `Steering:`/`Follow-up:`
        // and joins the conversation when it is delivered (`message_start` in `onEvent()`).
        // The answer comes before the turn's first event, so the bubble is still in order.
        //
        // `requestId` (pig's): a prompt whose answer never came — the connection dropped, the child
        // went away — may or may not have been taken, so sending the same text again sends the same
        // id, and a child that already took it answers `duplicate` rather than asking twice.
        requestId = tab.unsent?.text === text ? tab.unsent.requestId : randomHex();
        tab.unsent = null;
        const { disposition } = await tab.rpc({ type: "prompt", message: text, images, streamingBehavior: followUp ? "followUp" : "steer", requestId });
        if (disposition === "duplicate") tab.refreshState();
        else if (disposition !== "queued") tab.render(() => appendUserMessage(text, imgsToSend));
      } catch (err) {
        // Refused by the child: nothing was taken, so the next send is a new request.
        if (!err.refused && requestId !== null) tab.unsent = { text, requestId };
        setRunningState(false);
        promptInput.value = text;
        pendingImages = imgsToSend;
        renderPendingImages();
        appendErrorMessage(err.message || t("send_failed"));
      }
    }

    // ---- the queue, above the input — the TUI's `showQueue()` ------------------------------
    //
    // Each line says which queue it is in, because the two mean different things: `Steering:`
    // arrives after the tool that is running, `Follow-up:` after the turn. The way back out is
    // under them, as in the TUI: everything queued goes back into the input, in front of what
    // is being typed, and the turn carries on.
    const queuedBar = document.getElementById("queued-messages");

    function renderQueue() {
      const q = active?.queue || { steering: [], followUp: [] };
      const lines = [...q.steering.map((m) => ["Steering", m]), ...q.followUp.map((m) => ["Follow-up", m])];
      queuedBar.hidden = lines.length === 0;
      queuedBar.innerHTML = lines.map(([kind, m]) => `<div class="queued-line">${escapeHtml(kind)}: ${escapeHtml(m)}</div>`).join("")
        + (lines.length ? `<button type="button" class="queued-dequeue">↳ ${escapeHtml(t("queue_dequeue"))}</button>` : "");
    }

    async function takeQueueBack(tab) {
      let queued;
      try { queued = await tab.rpc({ type: "clear_queue" }); } catch (err) { return; }
      const texts = [...(queued.steering || []), ...(queued.followUp || [])];
      if (texts.length === 0) return;
      promptInput.value = [...texts, promptInput.value].filter((x) => x.trim() !== "").join("\n\n");
      promptInput.dispatchEvent(new Event("input"));
      promptInput.focus();
    }

    queuedBar.addEventListener("click", (e) => {
      if (e.target.closest(".queued-dequeue") && active) takeQueueBack(active);
    });

    async function abortTurn() {
      // Stop means "stop whatever this tab is doing": a `!command` has no agent turn to abort.
      if (active?.bashRunning) {
        try { await active.rpc({ type: "abort_bash" }); } catch (err) {}
        return;
      }
      // As the TUI's Escape: what was queued comes back into the input instead of going with
      // the turn it was waiting for.
      if (active) await takeQueueBack(active);
      try { await active?.rpc({ type: "abort" }); } catch (err) {}
    }

    /**
     * Run a `!command` through the child's `bash` rpc command and draw it as a bash card.
     *
     * The card is registered under the rpc id *before* the command is sent (via `onId`), so
     * the first `bash_execution_update` event — which can arrive before the response — finds it. The
     * rpc has no timeout: `!` is "I am watching this", and the Stop button is how it ends.
     */
    async function runBangCommand(tab, command, remember) {
      if (tab.bashRunning) {
        appendErrorMessage(t("command_running"));
        return;
      }
      if (tab.ended || !tab.bound) {
        tab.subscribe();
        const until = Date.now() + 10000;
        while (!tab.bound && Date.now() < until) await new Promise((r) => setTimeout(r, 50));
        if (!tab.bound) { appendErrorMessage(t("agent_unreachable")); return; }
      }

      tab.render(() => {
        tab.emptyState?.remove(); tab.emptyState = null;
        chatScroll.querySelector(".empty-state-hero")?.remove();
      });

      const card = new ToolCard({ toolCallId: "bang-" + Date.now(), toolName: "bash", arguments: { command } });
      if (!remember) {
        const title = card.element.querySelector(".tool-header-title");
        if (title) title.textContent = "$$ " + command;
        card.element.title = t("bang_not_added");
      }
      tab.render(() => { chatScroll.appendChild(card.element); scrollToBottomIfNeeded(); });

      tab.bashRunning = true;
      tab.render(() => setRunningState(true));

      let rpcId = null;
      try {
        const data = await tab.rpc(
          { type: "bash", command, excludeFromContext: !remember },
          0,
          (id) => { rpcId = id; tab.bashCards.set(id, card); },
        );
        // `data` is upstream's BashResult: output, exitCode?, cancelled, truncated, fullOutputPath?.
        const details = {};
        if (data?.exitCode !== undefined && data.exitCode !== null) details.exitCode = data.exitCode;
        if (data?.cancelled) details.cancelled = true;
        if (data?.fullOutputPath) details.fullOutputPath = data.fullOutputPath;
        card.finish({
          result: { content: [{ type: "text", text: data?.output ?? "" }], details },
          isError: !!data?.cancelled || (typeof data?.exitCode === "number" && data.exitCode !== 0),
        });
      } catch (err) {
        card.finish({ result: String(err?.message || err), isError: true });
      } finally {
        if (rpcId) tab.bashCards.delete(rpcId);
        tab.bashRunning = false;
        tab.render(() => { setRunningState(false); scrollToBottomIfNeeded(); });
      }
    }

    /** The active tab's state, refreshed — what the old page-wide `refreshState()` meant. */
    async function refreshState() {
      await active?.refreshState();
    }

    // ---- a hook asks ----------------------------------------------------------------------
    //
    // `extension_ui_request` is the RPC protocol's line, sent to every browser; the first to answer
    // wins and the rest see the dialog go. A tool call is parked on the other end until the
    // answer arrives, so every dialog here can be escaped — escape is "no" for a confirm and
    // "nothing chosen" for the rest, which is the only safe reading of a question nobody answered.
    let hookDialog = null;

    // The answer goes to whoever asked: by default the child of the tab the dialog was opened for —
    // `openHookDialog` records it, because the person may have switched tabs since — and for an
    // extension panel's action, the server, which hands it to the action (`panels.js`).
    function replyTo(tab) {
      // Not `tab.rpc()`: that puts an id of its own on the command, and the answer's id is the question's.
      return (reply) => network.send({ type: "rpc_command", tabId: tab?.id, command: { type: "extension_ui_response", ...reply } });
    }

    /** Answer the dialog that is open as a cancel, and close it. */
    function cancelHookDialog() {
      if (!hookDialog) return;
      const { id, send } = hookDialog;
      closeHookDialog();
      send({ id, cancelled: true });
    }

    function closeHookDialog() {
      if (hookDialog) { clearInterval(hookDialog.countdown); leaveModalLayer(hookDialog.el); hookDialog.el.remove(); hookDialog = null; }
    }

    function openHookDialog(req, reply = null) {
      // The same question arriving again is not a second question.
      if (hookDialog && hookDialog.id === req.id) return;
      // One at a time, as TerminalUi has it: a second question would take the keys from the first.
      cancelHookDialog();

      const el = document.createElement("div");
      el.className = "modal-backdrop hook-dialog";
      const send = reply ?? replyTo(T() || active);
      const answered = (answer) => { closeHookDialog(); send({ id: req.id, ...answer }); };
      el.addEventListener("click", (e) => { if (e.target === el) answered({ cancelled: true }); });

      // The page's other dialogs' footer (`openModal()`): the hint on the left, the answers on the
      // right as `modal-btn`s, the one Enter gives filled in.
      const footer = (hint, buttons = "") => `<div class="modal-footer"><span class="hook-hint">${escapeHtml(t(hint))}</span>${buttons}</div>`;
      const pair = (no, yes) => `<button class="modal-btn" ${no[0]}>${escapeHtml(t(no[1]))}</button><button class="modal-btn primary" ${yes[0]}>${escapeHtml(t(yes[1]))}</button>`;
      let body = "";
      let foot = "";
      if (req.method === "confirm") {
        body = `<div class="hook-message">${escapeHtml(req.message || "")}</div>`;
        foot = footer("hook_hint_confirm", pair(["data-no", "hook_no"], ["data-yes autofocus", "hook_yes"]));
      } else if (req.method === "select") {
        body = `<div>${(req.options || []).map((o, i) => `<div class="hook-option" data-i="${i}">${escapeHtml(o)}</div>`).join("")}</div>`;
        foot = footer("hook_hint_select");
      } else if (req.method === "input") {
        body = `<input class="hook-input" placeholder="${escapeHtml(req.placeholder || "")}" autofocus>`;
        foot = footer("hook_hint_input", pair(["data-cancel", "cancel"], ["data-ok", "hook_ok"]));
      } else if (req.method === "editor") {
        body = `<textarea class="hook-editor" autofocus>${escapeHtml(req.prefill || "")}</textarea>`;
        foot = footer("hook_hint_editor", pair(["data-cancel", "cancel"], ["data-ok", "hook_submit"]));
      } else {
        return;
      }

      const title = req.title || t("hook_asks");
      let remaining = timeoutSeconds(req);
      el.innerHTML = `<div class="modal"><div class="modal-header"><span class="hook-title">${escapeHtml(remaining === null ? title : countdownTitle(title, remaining))}</span><span class="modal-close">×</span></div><div class="modal-body">${body}</div>${foot}</div>`;
      document.body.appendChild(el);
      hookDialog = { id: req.id, el, countdown: null, send };
      enterModalLayer(el);
      // `TerminalUi::countdown()`: the heading counts down, and at zero the dialog goes. The child
      // has given up on it by then (`RpcUi` withdraws the question), so nothing is sent back.
      if (remaining !== null) {
        const heading = el.querySelector(".hook-title");
        hookDialog.countdown = setInterval(() => {
          remaining--;
          if (remaining <= 0) { closeHookDialog(); return; }
          heading.textContent = countdownTitle(title, remaining);
        }, 1000);
      }
      el.querySelector(".modal-close").addEventListener("click", () => answered({ cancelled: true }));

      if (req.method === "confirm") {
        el.querySelector("[data-yes]").addEventListener("click", () => answered({ confirmed: true }));
        el.querySelector("[data-no]").addEventListener("click", () => answered({ confirmed: false }));
        el.querySelector("[data-yes]").focus();
        el.onkeydown = (e) => { if (e.key === "Enter") answered({ confirmed: true }); };
      } else if (req.method === "select") {
        const opts = [...el.querySelectorAll(".hook-option")];
        let at = 0;
        const focus = () => opts.forEach((o, i) => o.classList.toggle("focus", i === at));
        focus();
        opts.forEach((o) => o.addEventListener("click", () => answered({ value: req.options[+o.dataset.i] })));
        el.tabIndex = -1; el.focus();
        el.onkeydown = (e) => {
          if (e.key === "ArrowDown") { at = Math.min(opts.length - 1, at + 1); focus(); e.preventDefault(); }
          else if (e.key === "ArrowUp") { at = Math.max(0, at - 1); focus(); e.preventDefault(); }
          else if (e.key === "Enter" && opts.length) answered({ value: req.options[at] });
        };
      } else {
        const field = el.querySelector(".hook-input, .hook-editor");
        field.focus();
        el.querySelector("[data-ok]").addEventListener("click", () => answered({ value: field.value }));
        el.querySelector("[data-cancel]").addEventListener("click", () => answered({ cancelled: true }));
        field.onkeydown = (e) => {
          if (e.isComposing || e.keyCode === 229) return;
          if (e.key === "Enter" && (req.method === "input" || e.ctrlKey || e.metaKey)) { e.preventDefault(); answered({ value: field.value }); }
        };
      }
    }

    function onHookUiRequest(req) {
      // An extension's `$ctx->ui->systemNotify()`: for someone looking elsewhere (`attention.js`).
      if (req.method === "systemNotify") {
        const tab = T();
        notifyAway({ title: req.title || "pig", body: req.body || "", onOpen: tab ? () => openTab(tab) : null });
        return;
      }
      if (req.method === "notify") {
        const tab = T();
        if (tab && !tab.messagesLoaded) tab.earlyNotices.push(req); else drawNotify(req);
        return;
      }
      if (req.method === "set_editor_text") { if (T() === active) promptInput.value = req.text || ""; return; }
      // pig's own: an extension's `setTheme()`. The page has one theme, so every tab changes, and
      // `onThemeApplied` tells each tab's child. A name the page does not have changes nothing.
      if (req.method === "setTheme") { if (hasTheme(req.theme)) applyTheme(req.theme); return; }
      const tab = T();
      const changed = applyHookRequest(tab.hook, req);
      if (changed !== null) {
        if (tab === active) {
          drawHookChrome(tab);
          if (changed === "statuses" && tab.state) applyState(tab.state);
        }
        return;
      }
      openHookDialog(req);
    }

    /** `TerminalUi::notify()`: all three levels are lines in the transcript. */
    function drawNotify(req) {
      const kind = notifyKind(req);
      if (kind === "error") appendErrorMessage(req.message || "");
      else if (kind === "warning") appendWarningMessage(req.message || "");
      else appendNoticeMessage(req.message || "");
    }

    // The widgets above and below the prompt, the status in the footer line, the page title:
    // what `TerminalUi` draws around its editor, from the active tab's extensions.
    const hookWidgetsAbove = document.getElementById("hook-widgets-above");
    const hookWidgetsBelow = document.getElementById("hook-widgets-below");
    const pageTitle = document.title;
    /** The active tab's statuses as HTML, drawn ahead of the numbers by `applyState()`. */
    let hookStatus = "";
    onThemeApplied((theme) => { for (const tab of tabs) tab.tellTheme(theme); });
    function drawHookChrome(tab) {
      for (const [el, placement] of [[hookWidgetsAbove, "above"], [hookWidgetsBelow, "below"]]) {
        if (!el) continue;
        const widgets = widgetsAt(tab.hook, placement);
        el.innerHTML = widgets.map((w) => `<div class="hook-widget" data-key="${escapeHtml(w.key)}">${w.lines.map(ansiToHtml).join("\n")}</div>`).join("");
        el.hidden = widgets.length === 0;
      }
      hookStatus = statusHtml(tab.hook);
      document.title = tab.hook.title ?? pageTitle;
    }

    document.addEventListener("keydown", (e) => {
      // The key is this dialog's alone: a panel under it must not close with it.
      if (e.key === "Escape" && hookDialog) { e.stopPropagation(); cancelHookDialog(); }
    });

    // ---- the extensions' panels ----------------------------------------------------------
    //
    // An entry each in the sidebar's Extensions group (`panels.js`); an action may change what a
    // conversation can use — an account switched — so the active tab is asked again after one.
    // A panel's action runs on the server over the WebSocket, so what it asks (`$ui->confirm()`…)
    // is drawn here as a hook's question is, and answered back to it; what it tells (`notify()`)
    // goes to the panel. One entry per action still running, by request id.
    const panelRuns = new Map();
    network.addMessageListener((m) => {
      const run = m.requestId ? panelRuns.get(m.requestId) : null;
      if (!run) return;
      if (m.type === "panel_ui" && m.request) {
        if (m.request.method === "notify") run.onNotice(m.request);
        else openHookDialog(m.request, (reply) => network.send({ type: "panel_ui_response", requestId: m.requestId, reply }));
      } else if (m.type === "panel_result") {
        panelRuns.delete(m.requestId);
        run.resolve(m);
      }
    });
    network.addCloseListener(() => {
      for (const [id, run] of panelRuns) run.resolve({ ok: false, error: t("panel_unreachable") });
      panelRuns.clear();
    });
    function actOnPanel(panel, action, args, locale, onNotice) {
      return new Promise((resolve) => {
        const requestId = "panel-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
        panelRuns.set(requestId, { resolve, onNotice });
        network.send({ type: "panel_action", requestId, panel, action, args, locale });
      });
    }
    const panels = createPanels({ openModal, t, locale: () => currentLocale, act: actOnPanel, onChanged: () => refreshState() });
    /** The `/` list's rows beyond the built-ins: the panels' commands, then the conversation's own. */
    function extraCommands(tab) {
      const own = panels.commands();
      const names = new Set(own.map((c) => c.name));
      return [...own, ...(tab?.commands ?? []).filter((c) => !names.has(c.name))];
    }
    async function loadPanels() {
      await panels.load();
      if (active) slashAutocomplete?.setExtraCommands(extraCommands(active));
    }

    if (themeBtn) {
      themeBtn.addEventListener("click", openThemeModal);
    }

    if (langBtn) {
      langBtn.addEventListener("click", openLangModal);
    }

    // The header's ⋯ menu
    const moreMenuBtn = document.getElementById("more-menu-btn");
    const moreMenuDropdown = document.getElementById("more-menu-dropdown");
    const moreThemeBtn = document.getElementById("more-theme-btn");
    const moreLangBtn = document.getElementById("more-lang-btn");

    function toggleMoreMenu(show) {
      if (!moreMenuDropdown) return;
      const willShow = (typeof show === "boolean") ? show : (moreMenuDropdown.style.display === "none");
      moreMenuDropdown.style.display = willShow ? "flex" : "none";
    }

    if (moreMenuBtn) {
      moreMenuBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleMoreMenu();
      });
    }
    // Whatever was picked — an entry here or an extension's panel (`panels.js`) — the menu closes.
    moreMenuDropdown?.addEventListener("click", (e) => {
      if (e.target.closest(".more-dropdown-item")) toggleMoreMenu(false);
    });

    if (moreThemeBtn) {
      moreThemeBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleMoreMenu(false);
        openThemeModal();
      });
    }

    // API keys (`/api/keys`): every provider a key can be typed in for, what it has now, and a
    // field to store or replace one. Opened from the ⋯ menu, and by itself when no model has a key
    // (`loadModels()`), because then nothing in the page can answer.
    async function openKeysModal(noModels = false) {
      let providers;
      try {
        providers = (await fetchJson("/api/keys", t("what_keys"))).providers;
      } catch (err) {
        pigUnreachable(t("what_keys"), err);
        return;
      }

      const stateText = (p) => ({
        stored: t("key_stored"),
        oauth: t("key_oauth"),
        env: t("key_env", { env: p.env.join(", ") }),
      })[p.state] || t("key_none");

      const post = async (path, body) => {
        const res = await fetch(path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        providers = data.providers;
      };

      openModal({
        title: t("api_keys"),
        bodyHtml: `
          <div style="display:flex; flex-direction:column; gap:10px;">
            ${noModels ? `<div style="color:var(--color-text-secondary); font-size:13px;">${escapeHtml(t("keys_none_hint"))}</div>` : ""}
            <select class="select-pill select-wide" id="key-provider"></select>
            <div id="key-provider-hint" style="color:var(--color-dim); font-size:12px;"></div>
            <input class="modal-input" id="key-value" type="password" autocomplete="off" placeholder="${escapeHtml(t("key_placeholder"))}">
            <div id="key-error" style="color:var(--color-error); font-size:12px;" hidden></div>
            <div class="modal-section">${escapeHtml(t("keys_configured"))}</div>
            <div id="key-list" style="display:flex; flex-direction:column; gap:6px;"></div>
          </div>
        `,
        confirmText: t("save"),
        cancelText: t("close"),
        onOpen: (modalEl) => {
          const select = modalEl.querySelector("#key-provider");
          const hint = modalEl.querySelector("#key-provider-hint");
          const input = modalEl.querySelector("#key-value");
          const error = modalEl.querySelector("#key-error");
          const list = modalEl.querySelector("#key-list");
          const say = (text) => { error.hidden = !text; error.textContent = text || ""; };

          const showHint = () => {
            const p = providers.find((x) => x.id === select.value);
            hint.textContent = p ? stateText(p) : "";
            // A subscription is signed in from the terminal; a key typed here would sign it out.
            input.disabled = p?.state === "oauth";
          };

          const render = () => {
            // What is chosen stays chosen; the first time, the provider picked under the input,
            // then the first one with a key, then the first.
            const pick = (id) => (providers.some((p) => p.id === id) ? id : null);
            const chosen = pick(select.value) ?? pick(providerSelect.value)
              ?? providers.find((p) => p.state !== "none")?.id ?? providers[0]?.id;
            select.innerHTML = "";
            for (const p of providers) {
              const opt = document.createElement("option");
              opt.value = p.id;
              opt.textContent = p.state === "none" ? p.id : `${p.id} (${t("keys_configured")})`;
              select.appendChild(opt);
            }
            if (chosen) select.value = chosen;
            showHint();

            list.innerHTML = "";
            const configured = providers.filter((p) => p.state !== "none");
            if (configured.length === 0) {
              list.innerHTML = `<div style="color:var(--color-dim); font-size:12px;">${escapeHtml(t("keys_empty"))}</div>`;
            }
            for (const p of configured) {
              const row = document.createElement("div");
              row.style.cssText = "display:flex; align-items:center; gap:8px; font-size:13px;";
              row.innerHTML = `<span style="flex:1; min-width:0;">${escapeHtml(p.id)} <span style="color:var(--color-dim); font-size:12px;">${escapeHtml(stateText(p))}</span></span>`;
              if (p.state === "stored") {
                const remove = document.createElement("button");
                remove.className = "mini-btn";
                remove.textContent = t("delete");
                remove.addEventListener("click", async () => {
                  try {
                    await post("/api/keys/remove", { provider: p.id });
                    say("");
                    render();
                    loadModels();
                  } catch (err) { say(err.message); }
                });
                row.appendChild(remove);
              }
              list.appendChild(row);
            }
          };

          enhanceSelect(select, { floating: true });
          select.addEventListener("change", () => { say(""); showHint(); });
          modalEl._keys = { select, input, say, render };
          render();
          input.focus();
        },
        onConfirm: async (modalEl) => {
          const { select, input, say } = modalEl._keys;
          const key = input.value.trim();
          if (!key) return true;
          try {
            await post("/api/keys/set", { provider: select.value, key });
          } catch (err) {
            say(err.message);
            return false;
          }
          await loadModels();
          return true;
        },
      });
    }
    document.getElementById("more-keys-btn")?.addEventListener("click", (e) => {
      e.stopPropagation();
      toggleMoreMenu(false);
      openKeysModal();
    });

    // Logging out (`WebLogin`): the session ends on the server, and the page goes to the login.
    async function logOut() {
      try { await fetch("/api/logout", { method: "POST" }); } catch {}
      location.replace("/login");
    }
    document.getElementById("more-logout-btn")?.addEventListener("click", (e) => {
      e.stopPropagation();
      toggleMoreMenu(false);
      logOut();
    });

    // The extensions' panels: the list opens from `panels.js`; here the menu only closes.
    document.getElementById("more-panels-btn")?.addEventListener("click", (e) => {
      e.stopPropagation();
      toggleMoreMenu(false);
    });

    if (moreLangBtn) {
      moreLangBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleMoreMenu(false);
        openLangModal();
      });
    }

    document.addEventListener("click", (e) => {
      if (activeSessionDropdown) {
        if (!activeSessionDropdown.menu.contains(e.target) && !activeSessionDropdown.btn.contains(e.target)) {
          closeSessionDropdown();
        }
      }
      if (moreMenuDropdown && moreMenuDropdown.style.display !== "none") {
        if (!moreMenuDropdown.contains(e.target) && !moreMenuBtn.contains(e.target)) {
          toggleMoreMenu(false);
        }
      }
    });

    window.addEventListener("scroll", (e) => {
      if (activeSessionDropdown && !activeSessionDropdown.menu.contains(e.target)) {
        closeSessionDropdown();
      }
    }, true);
    window.addEventListener("resize", closeSessionDropdown);
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && activeSessionDropdown) {
        closeSessionDropdown();
      }
    });

    // Enter with nothing focused goes to the prompt, as in Telegram: after reading, clicking in the
    // conversation or switching tabs, the next Enter puts the cursor back where typing happens.
    // Only a bare Enter, and only when it has no other job — a focused button, link, field or
    // `<summary>` keeps it, and so does anything open over the page (a dialog, the ⌘ HUD, an
    // image, the terminal, a menu).
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Enter" || e.defaultPrevented || e.isComposing || e.shiftKey || e.altKey || e.metaKey || e.ctrlKey) return;
      const target = e.target instanceof Element ? e.target : null;
      if (target?.closest("input, textarea, select, button, a[href], summary, [contenteditable], [role='button'], [tabindex]")) return;
      const shown = (selector) => [...document.querySelectorAll(selector)].some((el) => el.getClientRects().length > 0);
      if (shown(".modal-backdrop, .hud-backdrop, .image-lightbox-overlay, .web-terminal-drawer.visible, .session-dropdown-menu")) return;
      if (moreMenuDropdown && moreMenuDropdown.style.display !== "none") return;
      e.preventDefault();
      promptInput.focus();
      const end = promptInput.value.length;
      promptInput.setSelectionRange(end, end);
    });

    // ---- session tabs -------------------------------------------------------------------
    //
    // ---- the tab bar -----------------------------------------------------------------------
    //
    // A tab is a `Tab` above: a conversation with a child process of its own. `localStorage`
    // keeps which ones were open (directory + file), so a reload reopens them; the children
    // are the server's and survive the reload for `IDLE_TTL`, which is why a reopened tab is
    // usually the same process and not a cold start.
    const sessionTabs = document.getElementById("session-tabs");
    const TABS_KEY = "pig-session-tabs:" + location.host;
    let serverCwd = null;

    function saveTabs() {
      try {
        localStorage.setItem(TABS_KEY, JSON.stringify({
          active: active ? active.key : null,
          tabs: tabs.map((t) => ({ cwd: t.cwd, sessionFile: t.sessionFile, name: t.name, opening: t.opening })),
        }));
      } catch (e) {}
    }

    function tabLabel(tab) {
      // Not the filename: a UUID is not a label anybody can tell from the next one.
      const fallback = __t("tab_new_session");
      const raw = tab.name || tab.opening || fallback;
      return cleanSessionTitle(raw) || fallback;
    }

    /** Make `tab` the one on screen. */
    function openTab(tab) {
      if (!tabs.includes(tab)) tabs.push(tab);
      if (active && active !== tab) active.scroller.hidden = true;
      active = tab;
      tab.scroller.hidden = false;
      if (!tab.bound) tab.subscribe();
      else if (tab.needsMessages) tab.loadMessages();

      // Show friendly EmptyState if tab has no messages yet
      if (tab.scroller.children.length === 0 && !tab.emptyState) {
        tab.emptyState = new EmptyState((prompt) => {
          promptInput.value = prompt;
          promptInput.focus();
        });
        tab.scroller.appendChild(tab.emptyState.element);
      }

      applyActiveTabToChrome();
      saveTabs();
      renderTabs();
      // The scroller was hidden, so its scroll position is whatever it was; a tab you come back
      // to that was following its turn should still be at the bottom.
      if (!tab.userScrolledUp) tab.scroller.scrollTop = tab.scroller.scrollHeight;
      scrollBottomBtn.style.display = tab.userScrolledUp ? "flex" : "none";
      promptInput.focus();
    }

    /** Find the tab on `cwd`/`sessionFile`, or open one. */
    function openOrFocus(cwd, sessionFile) {
      const found = tabs.find((t) => t.cwd === cwd && t.sessionFile && t.sessionFile === sessionFile);
      openTab(found || new Tab(cwd, sessionFile));
    }

    // The strip scrolls sideways when the tabs do not fit (a swipe, a trackpad, or the wheel — see
    // below). Redrawing it empties it, which puts it back at the start, so the scroll is carried
    // over; and when the active tab changes, it is brought into view — only then, because the strip
    // is redrawn on every turn's start and end, and someone scrolled left to close an old tab
    // should not be thrown back to the live one.
    let revealedTab = null;
    function renderTabs() {
      const scrolled = sessionTabs.scrollLeft;
      sessionTabs.innerHTML = "";
      for (const t of tabs) {
        const el = document.createElement("div");
        const isActive = t === active;
        el.className = "session-tab" + (isActive ? " active" : "") + (t.isRunning ? " working" : "") + (t.ended ? " ended" : "");
        el.title = (t.cwd ? t.cwd + "\n" : "") + (t.sessionFile || "") + (t.ended ? "\n(agent exited)" : "");
        const label = document.createElement("span");
        label.className = "session-tab-label";
        label.textContent = tabLabel(t);
        const close = document.createElement("span");
        close.className = "session-tab-close";
        close.textContent = "×";
        close.title = __t("close_tab"); // `t` is the tab here
        close.addEventListener("click", (e) => { e.stopPropagation(); closeTab(t); });
        el.appendChild(label);
        el.appendChild(close);
        el.addEventListener("click", () => { if (!isActive) openTab(t); });
        sessionTabs.appendChild(el);
      }
      const plus = document.createElement("div");
      plus.className = "session-tab-new";
      plus.textContent = "+";
      plus.title = t("new_session");
      plus.addEventListener("click", () => startNewSession(active?.cwd));
      sessionTabs.appendChild(plus);

      sessionTabs.scrollLeft = scrolled;
      if (active !== revealedTab) {
        revealedTab = active;
        revealActiveTab(plus.offsetWidth);
      }
    }

    /** Scroll the strip, and only the strip, until the active tab is clear of its edges and the `+`. */
    function revealActiveTab(covered) {
      const el = sessionTabs.querySelector(".session-tab.active");
      if (!el) return;
      const left = el.offsetLeft;
      const right = left + el.offsetWidth;
      const visible = sessionTabs.clientWidth - covered;
      if (left < sessionTabs.scrollLeft) sessionTabs.scrollLeft = left;
      else if (right > sessionTabs.scrollLeft + visible) sessionTabs.scrollLeft = right - visible;
    }

    // A mouse wheel turns vertically; over the strip that means sideways, or a desktop without a
    // trackpad has no way to reach the tabs off the edge.
    sessionTabs.addEventListener("wheel", (e) => {
      if (sessionTabs.scrollWidth <= sessionTabs.clientWidth || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
      sessionTabs.scrollLeft += e.deltaY;
      e.preventDefault();
    }, { passive: false });

    /**
     * Closing a tab detaches this browser from its child; the server reaps the child after
     * `IDLE_TTL` if nobody else is on it and no turn is running — so closing a tab mid-turn does
     * not kill the turn, and reopening the file within the minute finds the same process.
     */
    async function closeTab(tab) {
      const at = tabs.indexOf(tab);
      if (at === -1) return;
      const wasActive = tab === active;
      tabs.splice(at, 1);
      tab.close();
      if (!wasActive) { saveTabs(); renderTabs(); return; }
      active = null;
      const next = tabs[Math.min(at, tabs.length - 1)];
      if (next) openTab(next); else await startNewSession();
    }

    function updateSessionFileBadge(tObj) {
      if (!sessionFileBadge || !sessionFileText) return;
      if (!tObj) {
        sessionFileText.innerText = "";
        sessionFileBadge.title = "";
        return;
      }
      const rawName = tObj.sessionFile;
      const fullPath = tObj.sessionPath || (rawName && tObj.cwd ? `${tObj.cwd}/${rawName}` : rawName);
      if (!rawName) {
        sessionFileText.innerText = t("in_memory");
        sessionFileText.style.fontStyle = "italic";
        sessionFileText.style.opacity = "0.75";
        sessionFileBadge.title = t("in_memory_hint");
      } else {
        sessionFileText.innerText = formatSessionFilename(rawName);
        sessionFileText.style.fontStyle = "normal";
        sessionFileText.style.opacity = "1";
        sessionFileBadge.title = (fullPath ? `${fullPath}\n` : `${rawName}\n`) + t("click_to_copy");
      }
    }

    if (sessionFileBadge) {
      sessionFileBadge.addEventListener("click", () => {
        const cur = active;
        if (!cur) return;
        const targetText = cur.sessionPath || cur.sessionFile;
        if (!targetText) return;
        const copyFn = (navigator.clipboard && navigator.clipboard.writeText)
          ? navigator.clipboard.writeText(targetText)
          : Promise.reject(new Error("No clipboard API"));
        copyFn.then(() => {
          sessionFileBadge.classList.add("copied");
          sessionFileText.innerText = __t("copied");
          setTimeout(() => {
            sessionFileBadge.classList.remove("copied");
            if (active === cur) updateSessionFileBadge(cur);
          }, 1200);
        }).catch(() => {
          const temp = document.createElement("textarea");
          temp.value = targetText;
          document.body.appendChild(temp);
          temp.select();
          try {
            document.execCommand("copy");
            sessionFileBadge.classList.add("copied");
            sessionFileText.innerText = __t("copied");
            setTimeout(() => {
              sessionFileBadge.classList.remove("copied");
              if (active === cur) updateSessionFileBadge(cur);
            }, 1200);
          } catch (e) {}
          document.body.removeChild(temp);
        });
      });
    }

    /** The header, the dropdowns and the telemetry line all describe the active tab. */
    // ---- the folder a new conversation starts in ------------------------------------------
    //
    // The terminal's `pig` starts where it was run; here the person chooses, until the first
    // message: the chip by the model pickers shows the folder and opens the picker. Choosing
    // another folder replaces the empty tab with one there — a new child in that directory.
    const cwdChip = document.getElementById("cwd-chip");
    function drawCwdChip() {
      if (!cwdChip) return;
      const tab = active;
      const open = !!tab && !!tab.emptyState && !tab.isRunning;
      cwdChip.hidden = !open;
      if (!open) return;
      cwdChip.textContent = "📁 " + folderName(tab.cwd || serverCwd || "~");
      cwdChip.title = (tab.cwd || serverCwd || "") + "\n" + t("cwd_chip_title");
    }
    cwdChip?.addEventListener("click", () => {
      const tab = active;
      if (!tab?.emptyState) return;
      openCwdPicker({ current: tab.cwd || serverCwd || "", onPick: (path) => moveEmptyTab(tab, path) });
    });
    function moveEmptyTab(tab, path) {
      if (!tab.emptyState || path === tab.cwd) return;
      const at = tabs.indexOf(tab);
      if (at === -1) return;
      const fresh = new Tab(path, null);
      tabs.splice(at, 1, fresh);
      tab.close();
      openTab(fresh);
      refreshSidebar();
    }

    /** The task conversations with a run nobody has looked at yet, by file name (`watchScheduledTasks`). */
    let unreadTasks = new Set();

    /**
     * The conversation on screen, if it is a task's with news, is read now — on screen meaning the
     * active tab of a page that is not in the background.
     */
    function markScheduledSeen() {
      const file = active?.sessionFile;
      if (!file || !unreadTasks.has(file) || document.visibilityState !== "visible") return;
      unreadTasks.delete(file);
      fetch("/api/scheduled/seen", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sessionFile: file }),
      }).catch(() => { /* the next scheduled_update asks again */ });
    }

    function applyActiveTabToChrome() {
      const cur = active;
      if (!cur) return;
      drawCwdChip();
      cwdDisplay.innerText = cur.cwd ? (cur.cwd.split("/").filter(Boolean).pop() || cur.cwd) : "~";
      if (sessionTitleDisplay) {
        const cleanName = cleanSessionTitle(cur.name || cur.opening);
        sessionTitleDisplay.innerText = cleanName ? ` • ${cleanName}` : "";
      }
      updateSessionFileBadge(cur);
      renderQueue();
      markScheduledSeen();
      // The sidebar row of the conversation on screen follows the tab, without a refetch.
      sidebarList.querySelectorAll(".session-item").forEach((el) => {
        el.classList.toggle("active", !!cur.sessionPath && el.dataset.sessionPath === cur.sessionPath);
      });
      sendBtn.style.display = cur.isRunning ? "none" : "flex";
      stopBtn.style.display = cur.isRunning ? "flex" : "none";
      drawHookChrome(cur);
      slashAutocomplete?.setExtraCommands(extraCommands(cur));
      const s = cur.state;
      if (!s) { telemetryStats.innerText = ""; return; }
      applyState(s);
    }

    /** `get_state`'s answer, drawn into the chrome. Shape is `RpcMode::state()`'s. */
    function applyState(s) {
      if (!s || !active) return;
      const cur = active;
      const model = s.model || {};
      const window_ = model.contextWindow || 0;
      const tokens = s.contextTokens || 0;
      const pct = window_ > 0 ? (tokens / window_ * 100).toFixed(1) : "0.0";
      const windowLabel = window_ >= 1000000 ? (window_ / 1000000).toFixed(1) + "M" : Math.round(window_ / 1000) + "k";
      const stats = cur.stats || {};
      // A virtual model routes each request; say where the latest response went, as the TUI's
      // footer does (`model → routed-id • level`).
      const routed = s.routedModel ? ` → ${s.routedModel.id}${s.routedModel.thinkingLevel ? ` • ${s.routedModel.thinkingLevel}` : ""}` : "";
      telemetryStats.innerHTML = (hookStatus ? `<span class="hook-status">${hookStatus}</span>  ` : "")
        + escapeHtml(`↑${formatK(stats.tokens?.input || 0)} ↓${formatK(stats.tokens?.output || 0)} $${Number(stats.cost || 0).toFixed(3)} ${pct}%/${windowLabel}${routed}`);

      if (userIsInteracting) return;
      const provider = model.provider, modelId = model.id, level = s.thinkingLevel;
      if (provider && availableModels.length > 0 && providerSelect.value !== provider) {
        currentProvider = provider;
        providerSelect.value = provider;
        updateModelsForProvider(provider, modelId);
      } else if (modelId && availableModels.length > 0 && modelSelect.value !== modelId) {
        currentModelId = modelId;
        modelSelect.value = modelId;
        updateThinkingLevels(modelId, level);
      } else if (level && thinkingSelect.value !== level) {
        thinkingSelect.value = level;
      }
    }

    function formatK(n) {
      if (n < 1000) return n;
      if (n < 1000000) return (n / 1000).toFixed(1) + "k";
      return (n / 1000000).toFixed(1) + "M";
    }

    // 3-Level Cascading Selectors: Provider -> Model -> Thinking Level
    let keysAsked = false;
    async function loadModels() {
      try {
        availableModels = await fetchJson("/api/models", t("what_model_list"));
        populateProviders(availableModels);
        // No model has a key, so nothing in the page can answer: ask for one, once per page.
        if (availableModels.length === 0 && !keysAsked) {
          keysAsked = true;
          openKeysModal(true);
        }
      } catch (err) { pigUnreachable(t("what_model_list"), err); }
    }

    function populateProviders(models) {
      const providers = [...new Set(models.map(m => m.provider))].filter(Boolean);
      providerSelect.innerHTML = "";
      for (const p of providers) {
        const opt = document.createElement("option");
        opt.value = p;
        opt.innerText = p;
        providerSelect.appendChild(opt);
      }

      if (currentProvider && providers.includes(currentProvider)) {
        providerSelect.value = currentProvider;
      } else if (providers.includes("antigravity")) {
        providerSelect.value = "antigravity";
      } else if (providers.length > 0) {
        providerSelect.value = providers[0];
      }

      currentProvider = providerSelect.value;
      updateModelsForProvider(currentProvider, currentModelId);
    }

    function updateModelsForProvider(provider, targetModelId = null) {
      const filtered = availableModels.filter(m => m.provider === provider);
      modelSelect.innerHTML = "";

      for (const m of filtered) {
        const opt = document.createElement("option");
        opt.value = m.id;
        opt.innerText = m.id;
        modelSelect.appendChild(opt);
      }

      if (targetModelId && filtered.some(m => m.id === targetModelId)) {
        modelSelect.value = targetModelId;
      } else if (filtered.length > 0) {
        modelSelect.value = filtered[0].id;
      }

      currentModelId = modelSelect.value;
      updateThinkingLevels(currentModelId);
    }

    function updateThinkingLevels(modelId, targetLevel = null) {
      // By provider *and* id: the same id is often offered by several providers (raccoonx's and
      // Google's gemini-3.8-flash), and the first match drew another model's levels.
      const model = availableModels.find(m => m.provider === providerSelect.value && m.id === modelId);
      const levels = model?.thinkingLevels || ["off", "low", "medium", "high"];
      const canReason = model ? (model.reasoning && levels.some(l => l !== "off")) : true;

      thinkingSelect.innerHTML = "";
      for (const lvl of levels) {
        const opt = document.createElement("option");
        opt.value = lvl;
        opt.innerText = lvl;
        thinkingSelect.appendChild(opt);
      }

      // Determine active level preserving user preference
      let chosen = null;

      if (targetLevel && levels.includes(targetLevel)) {
        chosen = targetLevel;
      } else if (canReason) {
        // If model supports thinking, NEVER default to off!
        if (levels.includes(preferredThinkingLevel)) {
          chosen = preferredThinkingLevel;
        } else if (levels.includes("medium")) {
          chosen = "medium";
        } else if (levels.includes("high")) {
          chosen = "high";
        } else {
          // First non-off level available
          chosen = levels.find(l => l !== "off") || levels[0] || "medium";
        }
      } else {
        // Non-reasoning model can only be off
        chosen = "off";
      }

      thinkingSelect.value = chosen;
      if (chosen !== "off") {
        preferredThinkingLevel = chosen;
      }
    }

    providerSelect.addEventListener("change", () => {
      currentProvider = providerSelect.value;
      updateModelsForProvider(currentProvider);
      sendModelChange();
    });

    modelSelect.addEventListener("change", () => {
      currentModelId = modelSelect.value;
      updateThinkingLevels(currentModelId);
      sendModelChange();
    });

    thinkingSelect.addEventListener("change", async () => {
      const level = thinkingSelect.value;
      if (level !== "off") preferredThinkingLevel = level;
      try {
        await active?.rpc({ type: "set_thinking_level", level });
        await active?.refreshState();
      } catch (err) { appendErrorMessage(err.message); }
    });

    // One `set_model` to the active tab's child; its `get_state` afterwards is what the dropdowns
    // redraw from — and the refusal, when there is one, is the response's error.
    async function sendModelChange() {
      if (!active) return;
      userIsInteracting = true;
      const modelId = modelSelect.value;
      const provider = providerSelect.value;
      const thinkingLevel = thinkingSelect.value;
      try {
        await active.rpc({ type: "set_model", modelId, provider, thinkingLevel });
        userIsInteracting = false;
        await active.refreshState();
      } catch (err) {
        appendErrorMessage(err.message);
        await active.refreshState();
      } finally {
        setTimeout(() => { userIsInteracting = false; }, 300);
      }
    }

    // ---- Sidebar: conversations across every project, grouped by the day they were last written to ----

    const SIDEBAR_RECENTS = 30;
    const SEARCH_PAGE = 50;
    let sidebarSeq = 0;

    /** Today, Yesterday, or the date — of the day a conversation was last written to. */
    function dayLabel(ms) {
      const day = new Date(ms);
      day.setHours(0, 0, 0, 0);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const days = Math.round((today - day) / 86400000);
      if (days <= 0) return t("today");
      if (days === 1) return t("yesterday");
      const options = { month: "long", day: "numeric" };
      if (day.getFullYear() !== today.getFullYear()) options.year = "numeric";
      return new Intl.DateTimeFormat(currentLocale, options).format(day);
    }

    function groupTitle(text) {
      const el = document.createElement("div");
      el.className = "sidebar-group-title";
      el.textContent = text;
      return el;
    }

    function emptyNote(text, isError = false) {
      const el = document.createElement("div");
      el.className = "sidebar-empty" + (isError ? " error" : "");
      el.textContent = text;
      return el;
    }

    let activeSessionDropdown = null;

    function closeSessionDropdown() {
      if (!activeSessionDropdown) return;
      activeSessionDropdown.item.classList.remove("menu-open");
      activeSessionDropdown.menu.remove();
      activeSessionDropdown = null;
    }

    function toggleSessionDropdown(item, btn, s, labelEl) {
      if (activeSessionDropdown?.btn === btn) {
        closeSessionDropdown();
        return;
      }
      closeSessionDropdown();

      item.classList.add("menu-open");

      const menu = document.createElement("div");
      menu.className = "session-dropdown-menu";

      // 1. 重命名 (Rename)
      const renameItem = document.createElement("button");
      renameItem.className = "session-dropdown-item";
      renameItem.type = "button";
      renameItem.innerHTML = `
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
          <path d="m15 5 4 4"/>
        </svg>
        <span>${escapeHtml(t("rename") || t("rename_session"))}</span>
      `;
      renameItem.addEventListener("click", (e) => {
        e.stopPropagation();
        closeSessionDropdown();
        startRenameSession(item, labelEl, s, s.cwd);
      });

      // 2. 删除 (Delete)
      const deleteItem = document.createElement("button");
      deleteItem.className = "session-dropdown-item danger";
      deleteItem.type = "button";
      deleteItem.innerHTML = `
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 6h18"/>
          <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
          <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
          <line x1="10" y1="11" x2="10" y2="17"/>
          <line x1="14" y1="11" x2="14" y2="17"/>
        </svg>
        <span>${escapeHtml(t("delete") || t("delete_session"))}</span>
      `;
      deleteItem.addEventListener("click", (e) => {
        e.stopPropagation();
        closeSessionDropdown();
        confirmDeleteSession(item, s, s.cwd);
      });

      menu.append(renameItem, deleteItem);
      document.body.appendChild(menu);

      const rect = btn.getBoundingClientRect();
      const menuRect = menu.getBoundingClientRect();

      let left = rect.right - menuRect.width;
      if (left < 8) left = 8;
      if (left + menuRect.width > window.innerWidth - 8) {
        left = window.innerWidth - menuRect.width - 8;
      }
      menu.style.left = `${left}px`;

      let top = rect.bottom + 4;
      if (top + menuRect.height > window.innerHeight - 8 && rect.top > menuRect.height + 8) {
        top = rect.top - menuRect.height - 4;
      }
      menu.style.top = `${top}px`;

      activeSessionDropdown = { item, btn, menu };
    }

    /** One conversation: its name, the folder it belongs to, more options menu on hover. */
    function sessionRow(s, onOpen = null) {
      const item = document.createElement("div");
      item.className = "drawer-item session-item" + (active?.sessionPath === s.path ? " active" : "");
      item.dataset.sessionPath = s.path;

      const label = document.createElement("span");
      label.className = "drawer-label";
      label.title = s.filename;
      label.textContent = cleanSessionTitle(s.opening) || s.filename;

      const folder = document.createElement("span");
      folder.className = "drawer-badge";
      folder.title = s.cwd;
      folder.textContent = s.cwd.split("/").filter(Boolean).pop() || s.cwd;

      const moreBtn = document.createElement("button");
      moreBtn.className = "drawer-more-btn";
      moreBtn.type = "button";
      moreBtn.title = t("more_options");
      moreBtn.setAttribute("aria-label", t("more_options"));
      moreBtn.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
          <circle cx="3" cy="8" r="1.5"></circle>
          <circle cx="8" cy="8" r="1.5"></circle>
          <circle cx="13" cy="8" r="1.5"></circle>
        </svg>
      `;
      moreBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleSessionDropdown(item, moreBtn, s, label);
      });

      // A conversation a scheduled task started says which, with the sidebar entry's own clock.
      if (s.scheduledTask) {
        const clock = document.createElement("span");
        clock.className = "drawer-scheduled";
        clock.title = t("sched_started_by", { name: s.scheduledTask });
        clock.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>`;
        label.prepend(clock);
      }

      item.append(label, folder, moreBtn);
      // A run has finished there since it was last open: the dot goes once it is (`markScheduledSeen()`).
      if (s.scheduledUnread) {
        const dot = document.createElement("span");
        dot.className = "drawer-unread-dot";
        dot.title = t("sched_unread");
        item.insertBefore(dot, folder);
      }
      item.addEventListener("click", () => {
        closeSessionDropdown();
        onOpen?.();
        switchSession(s.path, s.filename, s.cwd);
      });
      return item;
    }

    /** The recent conversations under the New / Search / Scheduled entries. Nothing is fetched while closed. */
    async function refreshSidebar() {
      if (sidebar.classList.contains("collapsed")) return;
      const mine = ++sidebarSeq;

      let data;
      try {
        const res = await fetch(`/api/sessions/all?limit=${SIDEBAR_RECENTS}`);
        data = await res.json();
      } catch (err) {
        if (mine === sidebarSeq) sidebarList.replaceChildren(emptyNote(t("failed_sessions"), true));
        return;
      }
      // Two refreshes in flight (agent_end and session_info_changed arrive together): the later one draws.
      if (mine !== sidebarSeq) return;

      closeSessionDropdown();
      sidebarList.replaceChildren();
      if (data.sessions.length === 0) {
        sidebarList.appendChild(emptyNote(t("no_sessions")));
        return;
      }

      let group = null;
      for (const s of data.sessions) {
        const label = dayLabel(s.modified);
        if (label !== group) {
          group = label;
          sidebarList.appendChild(groupTitle(label));
        }
        sidebarList.appendChild(sessionRow(s));
      }

      if (data.total > data.sessions.length) {
        const all = document.createElement("button");
        all.className = "sidebar-view-all";
        all.textContent = t("view_all", { count: data.total });
        all.addEventListener("click", () => openSessionSearch());
        sidebarList.appendChild(all);
      }
    }

    /**
     * Search and "View all" are one panel: an empty query lists every conversation newest first,
     * a query lists the best matches — over the name and everything said, as `--resume` searches.
     */
    function openSessionSearch() {
      openModal({
        title: t("all_sessions"),
        hideFooter: true,
        width: "640px",
        bodyHtml: `
          <input class="modal-input" id="session-search-input" type="text" placeholder="${escapeHtml(t("search_sessions_placeholder"))}" autocomplete="off">
          <div id="session-search-results" class="session-search-results"></div>
        `,
        onOpen: (modalEl, close) => {
          const input = modalEl.querySelector("#session-search-input");
          const results = modalEl.querySelector("#session-search-results");
          let seq = 0;
          let timer = null;
          let offset = 0;
          let group = null;

          const load = async (append) => {
            const q = input.value.trim();
            const mine = ++seq;
            if (!append) {
              offset = 0;
              group = null;
            }
            const url = q === ""
              ? `/api/sessions/all?limit=${SEARCH_PAGE}&offset=${offset}`
              : `/api/sessions/all?limit=${SEARCH_PAGE}&q=${encodeURIComponent(q)}`;

            let data;
            try {
              data = await (await fetch(url)).json();
            } catch (err) {
              if (mine === seq) results.replaceChildren(emptyNote(t("failed_sessions"), true));
              return;
            }
            if (mine !== seq) return;

            if (!append) results.replaceChildren();
            results.querySelector(".sidebar-view-all")?.remove();
            if (!append && data.sessions.length === 0) {
              results.appendChild(emptyNote(q === "" ? t("no_sessions") : t("no_results")));
              return;
            }

            for (const s of data.sessions) {
              // Matches come best first, so only the full list is grouped by day.
              if (q === "") {
                const label = dayLabel(s.modified);
                if (label !== group) {
                  group = label;
                  results.appendChild(groupTitle(label));
                }
              }
              results.appendChild(sessionRow(s, close));
            }

            offset += data.sessions.length;
            if (q === "" && offset < data.total) {
              const more = document.createElement("button");
              more.className = "sidebar-view-all";
              more.textContent = t("load_more", { count: data.total - offset });
              more.addEventListener("click", () => load(true));
              results.appendChild(more);
            }
          };

          input.addEventListener("input", () => {
            clearTimeout(timer);
            timer = setTimeout(() => load(false), 250);
          });
          input.focus();
          load(false);
        },
      });
    }

    /** Rename session via a clean, mobile-friendly centered Modal (matching Account popup) */
    function startRenameSession(item, labelEl, session, cwd) {
      const currentCleanName = cleanSessionTitle(labelEl.textContent) || session.filename;

      openModal({
        title: t("rename_session"),
        bodyHtml: `
          <div style="display:flex; flex-direction:column; gap:10px;">
            <label style="color:var(--color-text-secondary); font-size:12px;">${escapeHtml(t("rename_label"))}</label>
            <input class="modal-input" id="rename-session-input" type="text" value="${escapeHtml(currentCleanName)}" placeholder="${escapeHtml(t("rename_placeholder"))}" autocomplete="off">
            <div style="color:var(--color-dim); font-size:11px; font-family:var(--font-mono); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(session.filename)}">
              ${escapeHtml(t("file_label"))}${escapeHtml(session.filename)}
            </div>
          </div>
        `,
        confirmText: t("save"),
        cancelText: t("cancel"),
        onOpen: (modalEl) => {
          const input = modalEl.querySelector("#rename-session-input");
          if (input) {
            input.focus();
            input.select();
          }
        },
        onConfirm: async (modalEl) => {
          const input = modalEl.querySelector("#rename-session-input");
          const newName = cleanSessionTitle(input?.value ?? "");
          if (!newName || newName === currentCleanName) {
            return true;
          }

          try {
            const res = await fetch("/api/sessions/rename", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ path: session.path, name: newName, cwd: cwd }),
            });
            const data = await res.json();
            if (!data.success) {
              alert(data.error || t("rename_failed"));
              return false;
            }
            labelEl.textContent = newName;
            refreshSidebar();
            const matchedTab = tabs.find((t) => t.sessionPath === session.path || t.sessionFile === session.filename);
            if (matchedTab) {
              matchedTab.name = newName;
              renderTabs();
              if (active === matchedTab) applyActiveTabToChrome();
            }
            return true;
          } catch (e) {
            alert("Network error renaming session");
            return false;
          }
        }
      });
    }

    /** Delete session via unified Modal dialog */
    function confirmDeleteSession(item, session, cwd) {
      const displayName = cleanSessionTitle(session.opening) || session.filename;

      openModal({
        title: t("delete_session"),
        bodyHtml: `
          <div style="line-height:1.6; font-size:13px;">
            ${escapeHtml(t("delete_confirm"))}
            <div style="margin:12px 0; padding:10px 12px; background:var(--color-hover); border:1px solid var(--color-border); border-radius:6px; font-weight:600; color:var(--color-text-primary); word-break:break-word;">
              ${escapeHtml(displayName)}
            </div>
            <div style="color:var(--color-error); font-size:12px;">⚠️ ${escapeHtml(t("delete_hint"))}</div>
          </div>
        `,
        confirmText: t("delete"),
        cancelText: t("cancel"),
        isDanger: true,
        onConfirm: async () => {
          try {
            const res = await fetch("/api/sessions/delete", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ path: session.path, cwd: cwd }),
            });
            const data = await res.json();
            if (!data.success) {
              alert(data.error || t("delete_failed"));
              return false;
            }

            item.style.transition = "opacity 0.2s, height 0.2s, padding 0.2s";
            item.style.opacity = "0";
            item.style.height = "0px";
            item.style.paddingTop = "0px";
            item.style.paddingBottom = "0px";
            setTimeout(() => {
              item.remove();
              refreshSidebar();
            }, 200);

            const matchedTab = tabs.find((t) => t.sessionPath === session.path || t.sessionFile === session.filename);
            if (matchedTab) {
              closeTab(matchedTab);
            }
            return true;
          } catch (e) {
            alert("Network error deleting session");
            return false;
          }
        }
      });
    }

    /** Picking a conversation in the sidebar opens it as a tab (or focuses the one it is in). */
    async function switchSession(path, filename, cwd) {
      if (window.innerWidth <= 768 && !sidebar.classList.contains("collapsed")) {
        sidebar.classList.add("collapsed");
        toggleSidebar.innerText = "›";
      }
      openOrFocus(cwd, filename);
    }

    /**
     * Draw a conversation from `get_messages`' answer. Called inside `Tab.render()`, so
     * `chatScroll` is that tab's. The shapes are `SessionCodec::encode()`'s — pi's file
     * format, which is why `toolCall` / `toolResult` are the names here and `thinking` carries
     * its text under `thinking`.
     */
    function appendSummaryMessage(message) {
      // Both a message event and compaction_end may announce the same summary.
      if (Array.from(chatScroll.querySelectorAll("[data-summary-key]")).some(el => el.dataset.summaryKey === `${message.role}:${message.timestamp}`)) return;
      const branch = message.role === "branchSummary";
      const block = document.createElement("details");
      block.className = "compaction-box";
      block.dataset.summaryKey = `${message.role}:${message.timestamp}`;
      const description = branch
        ? t(message.fromHook ? "branch_summary_hook" : "branch_summary_done")
        : (message.tokensBefore > 0 ? t("compacted_from", { count: Number(message.tokensBefore).toLocaleString() }) : t("compacted_done"));
      block.innerHTML = `<summary class="compaction-header"><span class="compaction-title">[${branch ? "branch summary" : "compaction"}]</span><span class="compaction-description">${escapeHtml(description)}</span></summary><div class="compaction-summary-text prose">${renderMarkdown(message.summary || "")}</div>`;
      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    function appendHookMessage(message) {
      if (message.display === false) return;
      const text = textOf(message.content);
      const lines = text.split("\n");
      const cut = lines.length > 5;
      const block = document.createElement(cut ? "details" : "div");
      block.className = "compaction-box hook-message";
      const label = `<span class="compaction-title">[${escapeHtml(message.customType)}]</span>`;
      block.innerHTML = cut
        ? `<summary class="compaction-header">${label}<div class="hook-preview prose">${renderMarkdown(lines.slice(0, 5).join("\n"))}<span class="compaction-description">${escapeHtml(t("more_lines", { count: lines.length - 5 }))}</span></div></summary><div class="compaction-summary-text prose">${renderMarkdown(text)}</div>`
        : `${label}<div class="compaction-summary-text prose">${renderMarkdown(text)}</div>`;
      for (const image of (message.content || []).filter(c => c.type === "image")) {
        const img = document.createElement("img");
        img.className = "msg-image-thumb";
        img.loading = "lazy";
        img.src = image.url || `data:${image.mimeType};base64,${image.data}`;
        img.addEventListener("click", () => openImageLightbox(img.src));
        block.appendChild(img);
      }
      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    /** Draw `msgs` into `chatScroll`, in order. */
    function drawMessages(msgs) {
      const tab = T();
      for (const m of msgs) {
        if (m.role === "user") {
          let text = "";
          let images = [];
          if (typeof m.content === "string") {
            text = m.content;
          } else if (Array.isArray(m.content)) {
            for (const c of m.content) {
              if (c.type === "text") text += c.text || "";
              if (c.type === "image") images.push(c);
            }
          }
          appendUserMessage(text, images);
        } else if (m.role === "assistant") {
          if (Array.isArray(m.content)) {
            for (const c of m.content) {
              if (c.type === "thinking" && (c.thinking || c.text)) {
                const tb = new ThinkingBlock(c.thinking || c.text);
                tb.finish();
                chatScroll.appendChild(tb.element);
              } else if (c.type === "tool_call" || c.type === "toolCall") {
                const card = new ToolCard({
                  toolCallId: c.id,
                  toolName: c.name,
                  arguments: c.arguments,
                });
                if (tab) tab.toolCards.set(c.id, card);
                chatScroll.appendChild(card.element);
              } else if (c.type === "text" && c.text) {
                const block = document.createElement("div");
                block.className = "msg-block msg-assistant prose";
                block.innerHTML = renderMarkdown(c.text);
                chatScroll.appendChild(block);
              }
            }
          }
          if (m.stopReason === "error" && m.errorMessage) {
            appendErrorMessage(m.errorMessage);
          } else if (m.stopReason === "aborted" && !m.content?.some(c => c.type === "toolCall")) {
            appendErrorMessage(t("operation_aborted"));
          }
        } else if (m.role === "tool_result" || m.role === "toolResult") {
          let card = tab?.toolCards?.get(m.toolCallId);
          if (!card) {
            card = new ToolCard({
              toolCallId: m.toolCallId,
              toolName: m.toolName || "tool",
              arguments: {},
            });
            if (tab) tab.toolCards.set(m.toolCallId, card);
            chatScroll.appendChild(card.element);
          }
          card.finish({
            result: { content: m.content || [], details: m.details },
            isError: m.isError,
          });
        } else if (m.role === "compactionSummary" || m.role === "branchSummary") {
          appendSummaryMessage(m);
        } else if (m.role === "custom") {
          appendHookMessage(m);
        } else if (m.role === "bashExecution") {
          const card = new ToolCard({ toolCallId: `bash-${m.timestamp}`, toolName: "bash", arguments: { command: m.command } });
          card.finish({
            result: { content: [{ type: "text", text: m.output }], details: { exitCode: m.exitCode, cancelled: m.cancelled, fullOutputPath: m.fullOutputPath } },
            isError: !!m.cancelled || (typeof m.exitCode === "number" && m.exitCode !== 0),
          });
          chatScroll.appendChild(card.element);
        }
      }
    }

    /**
     * How much of a conversation is drawn, and when. A long one is drawn from the end: the last
     * `FIRST_DRAW` messages at once, so it opens as fast as a short one; then about `PREFILL` more
     * before them while the page is idle; and the rest only as the reader scrolls up — a `BACKFILL`
     * at a time whenever they come within `NEAR_TOP` screens of the top, so the next part is drawn
     * before it is reached. Every block is a real one when it comes into view (the stutter
     * `content-visibility` had, drawing each block as it scrolled in, is not back), and a
     * conversation nobody scrolls back through does not cost its whole history in the page.
     * Cuts are made at a user message, so a tool call and its result are always drawn together.
     */
    const FIRST_DRAW = 60;
    const PREFILL = 120;
    // Asked of the child at a time: the first draw and its prefill, then a few parts more.
    const FIRST_LOAD = FIRST_DRAW + PREFILL;
    const FETCH_MORE = 180;
    const BACKFILL = 60;
    const NEAR_TOP = 2;

    /** Where to cut `msgs` so that about `count` messages are after it: at a user message. */
    function cutBefore(msgs, end, count) {
      for (let i = Math.max(0, end - count); i > 0; i--) {
        if (msgs[i].role === "user") return i;
      }
      return 0;
    }

    function renderMessages(msgs) {
      {
        const tab = T();
        chatScroll.innerHTML = "";
        if (tab) {
          tab.toolCards = tab.toolCards || new Map();
          tab.toolCards.clear();
          // What was not drawn of the conversation before is not drawn now.
          tab.olderMessages = null;
        }

        if (!Array.isArray(msgs) || msgs.length === 0) {
          if (tab) {
            tab.emptyState = new EmptyState((prompt) => {
              promptInput.value = prompt;
              promptInput.focus();
            });
            chatScroll.appendChild(tab.emptyState.element);
          }
          return;
        }

        if (tab?.emptyState) {
          tab.emptyState.remove();
          tab.emptyState = null;
        }

        const cut = tab ? cutBefore(msgs, msgs.length, FIRST_DRAW) : 0;
        drawMessages(cut > 0 ? msgs.slice(cut) : msgs);
        chatScroll.scrollTop = chatScroll.scrollHeight;
        if (cut > 0) {
          tab.olderMessages = { msgs, end: cut };
          backfill(tab, PREFILL);
        }
      }
    }

    /** Whether the reader is within `NEAR_TOP` screens of the top of what is drawn. */
    function nearTop(tab) {
      return tab.scroller.scrollTop < tab.scroller.clientHeight * NEAR_TOP;
    }

    /**
     * Draw the next part of `tab.olderMessages` before what is on screen, when the page is idle;
     * and the part after that while `budget` lasts or the reader is near the top. Each part is
     * drawn into a fragment (`renderTarget`) and put in front in one go; the reader's place is kept
     * — at the bottom if they were there (the tab's ResizeObserver follows), or on the same line
     * if they had scrolled up.
     */
    function backfill(tab, budget) {
      const older = tab.olderMessages;
      if (!older || tab.backfilling) return;
      tab.backfilling = true;
      const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 16));
      idle(() => {
        tab.backfilling = false;
        // Drawn afresh since (a reload, a reconnect): this part belongs to what was there before.
        if (tab.olderMessages !== older || tab.closed) return;
        const start = cutBefore(older.msgs, older.end, BACKFILL);
        const scroller = tab.scroller;
        const fromBottom = scroller.scrollHeight - scroller.scrollTop;
        const fragment = document.createDocumentFragment();
        tab.render(() => {
          tab.renderTarget = fragment;
          try { drawMessages(older.msgs.slice(start, older.end)); } finally { tab.renderTarget = null; }
        });
        scroller.prepend(fragment);
        scroller.scrollTop = tab.userScrolledUp ? scroller.scrollHeight - fromBottom : scroller.scrollHeight;
        const left = budget - (older.end - start);
        older.end = start;
        if (start === 0) {
          tab.olderMessages = null;
          if (tab.remoteStart > 0 && nearTop(tab)) fetchOlder(tab);
          return;
        }
        if (left > 0 || nearTop(tab)) backfill(tab, left);
      }, { timeout: 500 });
    }

    /**
     * History's images by reference (`RpcMode::imagesAsRefs()`), each fetched from
     * `/api/session-image` by an `<img loading="lazy">` as it comes into view: a conversation of
     * screenshots was megabytes for every part of its history the page asked for. Only for a
     * conversation with a file, which is what the server finds the image's child by.
     */
    function imageRefs(tab) {
      return tab.sessionFile && tab.cwd ? { images: "refs" } : {};
    }

    /** Give every image `ref` a URL the server answers with the image. */
    function withImageUrls(tab, messages) {
      const walk = (node) => {
        if (Array.isArray(node)) { node.forEach(walk); return; }
        if (!node || typeof node !== "object") return;
        if (node.type === "image" && node.ref && !node.data) {
          node.url = `/api/session-image?${new URLSearchParams({ cwd: tab.cwd, file: tab.sessionFile, ref: node.ref })}`;
          return;
        }
        Object.values(node).forEach(walk);
      };
      walk(messages);
      return messages;
    }

    /**
     * Ask the child for the part of the conversation before what has been fetched, and draw it
     * (`backfill()`). One request at a time per tab; a reload in the meantime (`remoteToken`)
     * leaves the answer unused.
     */
    async function fetchOlder(tab) {
      if (tab.fetchingOlder || !(tab.remoteStart > 0) || tab.olderMessages) return;
      tab.fetchingOlder = true;
      const token = tab.remoteToken;
      try {
        const d = await tab.rpc({ type: "get_messages", limit: FETCH_MORE, before: tab.remoteStart, ...imageRefs(tab) });
        if (tab.remoteToken !== token || tab.closed) return;
        const msgs = withImageUrls(tab, d.messages || []);
        tab.remoteStart = Number.isInteger(d.start) ? d.start : 0;
        if (msgs.length > 0) {
          tab.olderMessages = { msgs, end: msgs.length };
          backfill(tab, 0);
        }
      } catch (err) {
        // Asked again on the next scroll near the top.
      } finally {
        tab.fetchingOlder = false;
      }
    }

    // ---- boot ------------------------------------------------------------------------------
    //
    // The tabs this browser had open come back from localStorage; the one that was active
    // comes back active. With none saved, one new conversation in the server's directory —
    // `/api/running` says which that is, and also names children still alive from before a
    // reload, which is how a tab reopened within the minute finds its process rather than a
    // cold start.
    async function boot() {
      applyI18n();
      sidebarFollowsWidth();
      loadExtensionLocales();
      loadModels();
      network.connect();
      let saved = null;
      try { saved = JSON.parse(localStorage.getItem(TABS_KEY) || "null"); } catch (e) {}
      try {
        const r = await fetchJson("/api/running", t("what_server"));
        serverCwd = r.cwd;
      } catch (err) { serverCwd = null; }

      const list = Array.isArray(saved?.tabs) ? saved.tabs : (Array.isArray(saved) ? [] : []);
      for (const t of list) {
        if (!t || !t.cwd) continue;
        const tab = new Tab(t.cwd, t.sessionFile || null);
        tab.name = t.name || null;
        tab.opening = t.opening || null;
        tabs.push(tab);
      }
      const wanted = saved?.active ? tabs.find((t) => t.key === saved.active) : null;
      if (tabs.length === 0) {
        openTab(new Tab(serverCwd || "", null));
      } else {
        openTab(wanted || tabs[0]);
      }
      renderTabs();
    }

    loadPanels();
    network.init({
      getTabs: () => tabs,
      getActiveTab: () => active,
      showStatus: showStatusBanner,
      hideStatus: hideStatusBanner,
      onReachable: pigReachable,
    });

    initTheme();

    slashAutocomplete = new SlashAutocomplete({
      inputElement: promptInput,
      anchorElement: document.querySelector(".input-wrapper"),
      onSubmit: () => submitMessage(),
    });

    const webTerminal = new WebTerminal({
      getActiveCwd: () => active?.cwd || serverCwd || "",
      onCwdChanged: (newCwd) => {
        if (active && !active.sessionFile) {
          active.cwd = newCwd;
          applyActiveTabToChrome();
          saveTabs();
          renderTabs();
        }
      }
    });
    window.webTerminal = webTerminal;

    // Scheduled tasks: the panel, and the dot beside it — a run waiting for an answer, or else a run
    // that has finished since its conversation was last open, as the Claude app marks one.
    const scheduledDot = document.getElementById("scheduled-tasks-dot");
    document.getElementById("scheduled-tasks-btn")?.addEventListener("click", () => {
      openScheduledTasks({ onOpenConversation: (cwd, sessionFile) => switchSession(null, sessionFile, cwd) });
    });
    watchScheduledTasks({ onAttention: (waiting, tasks) => {
      unreadTasks = new Set(tasks.filter((task) => task.unread && task.sessionFile).map((task) => task.sessionFile.split("/").pop()));
      if (scheduledDot) {
        scheduledDot.hidden = !waiting && unreadTasks.size === 0;
        scheduledDot.classList.toggle("unread", !waiting);
        scheduledDot.title = t(waiting ? "sched_status_needs_input" : "sched_unread");
      }
      markScheduledSeen();
    } });
    document.addEventListener("visibilitychange", () => markScheduledSeen());
    // A run is a new conversation, and its time in the list moves as it works.
    network.addMessageListener((m) => { if (m.type === "scheduled_update") refreshSidebar(); });

    const nodeWorkbench = new NodeWorkbench();
    document.getElementById("nodes-btn")?.addEventListener("click", () => nodeWorkbench.open());
    document.getElementById("more-nodes-btn")?.addEventListener("click", () => {
      moreMenuDropdown.style.display = "none";
      nodeWorkbench.open();
    });

    document.getElementById("terminal-btn")?.addEventListener("click", () => webTerminal.toggle());
    document.getElementById("more-terminal-btn")?.addEventListener("click", () => {
      moreMenuDropdown.style.display = "none";
      webTerminal.toggle();
    });

    window.addEventListener("keydown", (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === "`") {
        e.preventDefault();
        webTerminal.toggle();
      }
      if ((e.ctrlKey || e.metaKey) && (e.key === "k" || e.key === "K")) {
        e.preventDefault();
        openSessionSearch();
      }
    });

    onLocaleChanged(() => { refreshSidebar(); loadPanels(); });
    boot();

