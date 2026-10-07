/**
 * Main application controller for pig web UI (Native ES Module).
 */

import { escapeHtml, cleanSessionTitle, formatSessionFilename, openModal } from "./utils.js";
import { t, __t, currentLocale, setLocale, applyI18n, onLocaleChanged, loadExtensionLocales, openLangModal, customLocales, extensionLocales } from "./i18n.js";
import { THEMES, currentTheme, applyTheme, openThemeModal, initTheme } from "./theme.js";
import { clientId, network } from "./network.js";
import { renderMarkdown, renderDiff, renderDiffFromUnified, copyCode } from "./markdown.js";
import { openAccounts, closeAccounts, loadAccounts, loadUsage, setAccountsStateHook } from "./accounts.js";
import { ThinkingBlock } from "./components/ThinkingBlock.js";
import { ToolCard } from "./components/ToolCard.js";
import { EmptyState } from "./components/EmptyState.js";
import { openImageLightbox } from "./components/ImageLightbox.js";
import { SlashAutocomplete } from "./components/SlashAutocomplete.js";
import { WebTerminal } from "./components/WebTerminal.js";
import { NodeWorkbench } from "./components/NodeWorkbench.js";

    const chatArea = document.getElementById("chat-area");
    const promptInput = document.getElementById("prompt-input");
    const sendBtn = document.getElementById("send-btn");
    const stopBtn = document.getElementById("stop-btn");
    const attachBtn = document.getElementById("attach-btn");
    const fileInput = document.getElementById("file-input");
    const pendingImagesBar = document.getElementById("pending-images");
    const toggleSidebar = document.getElementById("toggle-sidebar");
    const sidebar = document.getElementById("sidebar");
    const sidebarHeader = document.getElementById("sidebar-header");
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
    const sidebarBackdrop = document.getElementById("sidebar-backdrop");
    const langBtn = document.getElementById("lang-btn");
    const themeBtn = document.getElementById("theme-btn");

    let tabSeq = 0;

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
        this.currentAssistantBlock = null;
        this.currentAssistantText = "";
        this.currentThinkingText = "";
        this.currentThinkingBox = null;
        this.userScrolledUp = false;
        this.toolCards = new Map();
        this.bashCards = new Map();   // rpc id -> ToolCard for a `!command`
        this.bashRunning = false;     // a `!command` is in flight (Stop sends abort_bash)
        this.emptyState = null;

        this.scroller = document.createElement("div");
        this.scroller.className = "chat-scroll";
        this.scroller.hidden = true;
        this.scroller.addEventListener("scroll", () => {
          const d = this.scroller.scrollHeight - this.scroller.scrollTop - this.scroller.clientHeight;
          this.userScrolledUp = d > 80;
          if (this === active) scrollBottomBtn.style.display = this.userScrolledUp ? "flex" : "none";
        });
        chatArea.appendChild(this.scroller);
      }

      get key() { return (this.cwd || "") + "::" + (this.sessionFile || ("__new__::" + clientId + ":" + this.id)); }

      subscribe() {
        this.bound = false;
        network.send({
          type: "start_session",
          tabId: this.id,
          cwd: this.cwd,
          sessionFile: this.sessionFile || undefined,
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
        if (m.type === "pong") return;
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
          this.render(() => { setRunningState(false); appendErrorMessage("The agent for this conversation exited" + (m.reason ? ": " + m.reason : ".") + " Send a message to start it again."); });
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
            if (evt.success) p.resolve(evt.data ?? null); else p.reject(new Error(evt.error || `${evt.command} refused`));
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
        if (evt.type === "bash_output") {
          const card = this.bashCards?.get(evt.id);
          if (card) card.updateProgress(evt.output || "");
          return;
        }
        if (evt.type === "hook_ui_request") { this.render(() => onHookUiRequest(evt)); return; }
        if (evt.type === "hook_error" || evt.type === "tool_error") {
          this.render(() => appendErrorMessage((evt.hookPath ? evt.hookPath + ": " : "") + evt.error));
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
        // A fresh child, or a reconnect to one: load what it has, then ask what it is.
        await this.loadMessages();
        await this.refreshState();
        if (this === active) applyActiveTabToChrome();
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
          saveTabs(); renderTabs();
          if (this === active) applyActiveTabToChrome();
        } catch (err) { if (this === active && !this.ended) pigUnreachable("the session state", err); }
      }

      async loadMessages() {
        try {
          const d = await this.rpc({ type: "get_messages" });
          this.render(() => renderMessages(d.messages || []));
        } catch (err) { if (this === active && !this.ended) pigUnreachable("the conversation", err); }
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
    let renderingTab = null;  // the tab an event is being drawn into right now (see Tab.render)

    // The old page-wide names, resolved against the tab being rendered into (an event) or the
    // active one (a keystroke). Reads and writes both go to the same tab, so the helpers below
    // are unchanged.
    const T = () => renderingTab || active;
    try {
      Object.defineProperties(window, {
        chatScroll:            { configurable: true, get: () => T()?.scroller || chatArea },
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
      if (!sidebar.classList.contains("collapsed") && currentView === "workspaces") {
        loadWorkspaces();
      }
    });

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener("click", () => {
        sidebar.classList.add("collapsed");
        toggleSidebar.innerText = "›";
      });
    }

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

    function enhanceSelect(select) {
      if (isTouchDevice) return;

      const wrap = document.createElement("div");
      wrap.className = "select-wrap";
      select.parentNode.insertBefore(wrap, select);
      wrap.appendChild(select);

      const menu = document.createElement("div");
      menu.className = "dropdown-menu";
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

    enhanceSelect(providerSelect);
    enhanceSelect(modelSelect);
    enhanceSelect(thinkingSelect);

    // Auto-grow textarea
    promptInput.addEventListener("input", () => {
      promptInput.style.height = "40px";
      promptInput.style.height = Math.max(40, Math.min(promptInput.scrollHeight, 160)) + "px";
    });

    // Smart scroll pinning & return to bottom
    chatScroll.addEventListener("scroll", () => {
      const distanceFromBottom = chatScroll.scrollHeight - chatScroll.scrollTop - chatScroll.clientHeight;
      if (distanceFromBottom > 80) {
        userScrolledUp = true;
        scrollBottomBtn.style.display = "flex";
      } else {
        userScrolledUp = false;
        scrollBottomBtn.style.display = "none";
      }
    });

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
      // Direct send on Enter (matching TUI), Shift+Enter inserts newline
      const isPlainEnter = e.key === "Enter" && !e.shiftKey;
      const isModifierEnter = e.key === "Enter" && (e.metaKey || e.ctrlKey);

      if (isPlainEnter || isModifierEnter) {
        if (e.isComposing || isComposing || e.keyCode === 229 || (Date.now() - compositionEndTime < 60)) {
          return;
        }
        e.preventDefault();
        hideCommandHud();
        submitMessage();
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

    sendBtn.addEventListener("click", submitMessage);
    stopBtn.addEventListener("click", abortTurn);
    attachBtn.addEventListener("click", () => fileInput.click());
    newSessionBtn.addEventListener("click", () => startNewSession());

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
          <img src="${img.url}" class="pending-img-thumb" alt="attachment">
          <div class="pending-img-del" title="Remove">×</div>
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

    function appendUserMessage(text, images = []) {
      const tab = T();
      if (tab?.emptyState) {
        tab.emptyState.remove();
        tab.emptyState = null;
      }
      chatScroll.querySelector(".empty-state-hero")?.remove();

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
          imgEl.title = "Local clipboard image: " + imgPath;
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
          imgEl.src = src;
          imgEl.className = "msg-image-thumb";
          imgEl.title = "Click to open full view";
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
          tab?.refreshState();
          if (currentView === "sessions" && selectedWorkspace) {
            enterWorkspace(selectedWorkspace);
          } else {
            loadWorkspaces();
          }
        }
        const err = evt.error || lastErrorMessage(evt.messages);
        if (err) {
          appendErrorMessage(err);
        }
      } else if (evt.type === "message_update") {
        // `RpcMode` sends the whole message so far plus the delta that changed it; the delta
        // is what streams, so it is what is drawn.
        const d = evt.delta || {};
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
        const card = new ToolCard(evt);
        tab.toolCards.set(evt.toolCallId, card);
        chatScroll.appendChild(card.element);
        scrollToBottomIfNeeded();
      } else if (evt.type === "tool_execution_update") {
        const card = tab?.toolCards?.get(evt.toolCallId);
        if (card) {
          card.updateProgress(textOf(evt.partial?.content));
        } else {
          updateToolCardProgress({ toolCallId: evt.toolCallId, output: textOf(evt.partial?.content) });
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
      } else if (evt.type === "retry_start") {
        if (tab === active) showStatusBanner(t("retrying_banner", { delay: Math.round(evt.delaySeconds), attempt: evt.attempt, maxAttempts: evt.maxAttempts, error: evt.error }));
      } else if (evt.type === "retry_end") {
        if (tab === active) hideStatusBanner();
        tab?.refreshState();
        if (!evt.succeeded && evt.error) {
          appendErrorMessage(evt.error);
        }
      } else if (evt.type === "auto_compaction_start") {
        if (tab === active) showStatusBanner(t("compacting_banner"));
      } else if (evt.type === "auto_compaction_end") {
        if (tab === active) hideStatusBanner();
        if (evt.summary) appendSummaryMessage(evt.summary);
        if (!evt.succeeded && evt.error) appendErrorMessage(evt.error);
        tab?.refreshState();
      } else if (evt.type === "session_info_changed") {
        if (tab) {
          tab.name = evt.name || null;
          saveTabs();
          renderTabs();
          if (tab === active) applyActiveTabToChrome();
        }
        tab?.refreshState();
        if (currentView === "sessions" && selectedWorkspace) {
          enterWorkspace(selectedWorkspace);
        } else {
          loadWorkspaces();
        }
      }
    }

    /** Extract any fatal error recorded on the last assistant message of a turn. */
    function lastErrorMessage(messages) {
      if (!Array.isArray(messages) || messages.length === 0) return null;
      const last = messages[messages.length - 1];
      if (!last) return null;
      if (last.errorMessage) return last.errorMessage;
      if (last.stopReason === "error") return "An error occurred during generation.";
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

      // If a valid cwd string is passed (e.g. from tab bar '+' button), use it.
      // If user is currently browsing a specific workspace in the sidebar, use that workspace's directory!
      // Otherwise fallback to the active tab's cwd or the server default cwd.
      const targetCwd = (typeof cwd === "string" && cwd.trim() !== "")
        ? cwd.trim()
        : ((currentView === "sessions" && selectedWorkspace?.path) || active?.cwd || serverCwd);

      openTab(new Tab(targetCwd, null));

      if (window.innerWidth <= 768 && !sidebar.classList.contains("collapsed")) {
        sidebar.classList.add("collapsed");
        toggleSidebar.innerText = "›";
      }

      if (currentView === "sessions" && selectedWorkspace) {
        enterWorkspace(selectedWorkspace);
      } else {
        loadWorkspaces();
      }
    }

    async function submitMessage() {
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
      // the model decided whether to run them. The card streams through `bash_output` events
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
              <span class="tool-header-status">diagnostics</span>
            </div>
            <div class="tool-body"><pre><code>${escapeHtml(data.plain || "")}</code></pre></div>
          `;
          chatScroll.appendChild(card);
        } catch (err) { appendErrorMessage(err.message); }
        scrollToBottomIfNeeded();
        return;
      }

      // The accounts panel is this page's answer to /antigravity.accounts
      if (text === "/accounts" || text === "/antigravity.accounts") {
        openAccounts();
        return;
      }

      if (text === "/bug" || text.startsWith("/bug ")) {
        appendUserMessage(text);
        const hint = text.slice(4).trim();
        const includeTranscript = confirm("Include the session transcript in the bug report?\n\nIt holds your messages, the model's output, and every file and command result read this session.");
        try {
          const data = await active.rpc({ type: "bug", hint, includeTranscript });
          const block = document.createElement("div");
          block.className = "msg-block msg-assistant prose";
          block.innerHTML = `<p>\u2713 Bug report written to <code>${escapeHtml(data.path)}</code>.</p><p><a href="${escapeHtml(data.issueUrl)}" target="_blank" rel="noopener" style="color:#38bdf8;">Open a GitHub issue with it prefilled \u2197</a></p>`;
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
        loading.innerText = asPr ? "Generating Pull Request description from session…" : "Exporting…";
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
            const data = await active.rpc({ type: "export" });
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
                <span class="tool-header-status">working tree</span>
              </div>
              <div class="tool-body">${renderDiffFromUnified(data.diff)}</div>
            `;
            chatScroll.appendChild(card);
          } else {
            const empty = document.createElement("div");
            empty.className = "msg-block msg-assistant";
            empty.innerText = "No git changes in working tree.";
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
          appendErrorMessage("Could not reach the agent for this conversation.");
          return;
        }
      }

      appendUserMessage(text, imgsToSend);
      setRunningState(true);

      // `steer` while a turn runs, `prompt` otherwise — what the single-session server used to
      // decide; now the page knows, because it holds the running flag per tab.
      const images = imgsToSend.map(img => ({ type: "image", mimeType: img.mimeType, data: img.data }));
      try {
        if (tab.state?.isStreaming) {
          await tab.rpc({ type: "steer", message: text });
        } else {
          await tab.rpc({ type: "prompt", message: text, images });
        }
      } catch (err) {
        setRunningState(false);
        promptInput.value = text;
        pendingImages = imgsToSend;
        renderPendingImages();
        appendErrorMessage(err.message || "Failed to send message.");
      }
    }

    async function abortTurn() {
      // Stop means "stop whatever this tab is doing": a `!command` has no agent turn to abort.
      if (active?.bashRunning) {
        try { await active.rpc({ type: "abort_bash" }); } catch (err) {}
        return;
      }
      try { await active?.rpc({ type: "abort" }); } catch (err) {}
    }

    /**
     * Run a `!command` through the child's `bash` rpc command and draw it as a bash card.
     *
     * The card is registered under the rpc id *before* the command is sent (via `onId`), so
     * the first `bash_output` event — which can arrive before the response — finds it. The
     * rpc has no timeout: `!` is "I am watching this", and the Stop button is how it ends.
     */
    async function runBangCommand(tab, command, remember) {
      if (tab.bashRunning) {
        appendErrorMessage("A command is already running. Press Stop first.");
        return;
      }
      if (tab.ended || !tab.bound) {
        tab.subscribe();
        const until = Date.now() + 10000;
        while (!tab.bound && Date.now() < until) await new Promise((r) => setTimeout(r, 50));
        if (!tab.bound) { appendErrorMessage("Could not reach the agent for this conversation."); return; }
      }

      tab.render(() => {
        tab.emptyState?.remove(); tab.emptyState = null;
        chatScroll.querySelector(".empty-state-hero")?.remove();
      });

      const card = new ToolCard({ toolCallId: "bang-" + Date.now(), toolName: "bash", arguments: { command } });
      if (!remember) {
        const title = card.element.querySelector(".tool-header-title");
        if (title) title.textContent = "$$ " + command;
        card.element.title = "!! — not added to the conversation";
      }
      tab.render(() => { chatScroll.appendChild(card.element); scrollToBottomIfNeeded(); });

      tab.bashRunning = true;
      tab.render(() => setRunningState(true));

      let rpcId = null;
      try {
        const data = await tab.rpc(
          { type: "bash", command, remember },
          0,
          (id) => { rpcId = id; tab.bashCards.set(id, card); },
        );
        // `data` is SessionCodec::encode(BashExecution): output, exitCode, cancelled, truncated, spillPath.
        const details = {};
        if (data?.exitCode !== undefined && data.exitCode !== null) details.exitCode = data.exitCode;
        if (data?.cancelled) details.cancelled = true;
        if (data?.spillPath) details.fullOutputPath = data.spillPath;
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
    // `hook_ui_request` is the RPC protocol's line, sent to every browser; the first to answer
    // wins and the rest see the dialog go. A tool call is parked on the other end until the
    // answer arrives, so every dialog here can be escaped — escape is "no" for a confirm and
    // "nothing chosen" for the rest, which is the only safe reading of a question nobody answered.
    let hookDialog = null;

    // The answer goes to the child that asked, which is the tab the dialog was opened for —
    // `openHookDialog` records it, because the person may have switched tabs since.
    function hookReply(reply) {
      const tab = hookDialog?.tab || active;
      tab?.send({ type: "rpc_command", command: { type: "hook_ui_response", ...reply } });
    }

    function closeHookDialog() {
      if (hookDialog) { hookDialog.el.remove(); hookDialog = null; }
    }

    function openHookDialog(req) {
      // The same question arriving again is not a second question.
      if (hookDialog && hookDialog.id === req.id) return;
      // One at a time, as TerminalUi has it: a second question would take the keys from the first.
      if (hookDialog) hookReply({ id: hookDialog.id, cancelled: true });
      closeHookDialog();

      const el = document.createElement("div");
      el.className = "modal-backdrop hook-dialog";
      const answered = (reply) => { closeHookDialog(); hookReply({ id: req.id, ...reply }); };
      el.addEventListener("click", (e) => { if (e.target === el) answered({ cancelled: true }); });

      let body = "";
      if (req.method === "confirm") {
        body = `<div class="hook-message">${escapeHtml(req.message || "")}</div>
          <div class="hook-actions"><button class="mini-btn" data-no>No</button><button class="mini-btn" data-yes autofocus>Yes</button></div>
          <div class="hook-hint">enter yes · esc no</div>`;
      } else if (req.method === "select") {
        body = `<div>${(req.options || []).map((o, i) => `<div class="hook-option" data-i="${i}">${escapeHtml(o)}</div>`).join("")}</div>
          <div class="hook-hint">↑↓ move · enter choose · esc cancel</div>`;
      } else if (req.method === "input") {
        body = `<input class="hook-input" placeholder="${escapeHtml(req.placeholder || "")}" autofocus>
          <div class="hook-actions"><button class="mini-btn" data-cancel>Cancel</button><button class="mini-btn" data-ok>OK</button></div>
          <div class="hook-hint">enter submit · esc cancel</div>`;
      } else if (req.method === "editor") {
        body = `<textarea class="hook-editor" autofocus>${escapeHtml(req.prefill || "")}</textarea>
          <div class="hook-actions"><button class="mini-btn" data-cancel>Cancel</button><button class="mini-btn" data-ok>Submit</button></div>
          <div class="hook-hint">ctrl+enter submit · esc cancel</div>`;
      } else {
        return;
      }

      el.innerHTML = `<div class="modal"><div class="modal-header"><span>${escapeHtml(req.title || "A hook asks")}</span><span class="modal-close">×</span></div><div class="modal-body">${body}</div></div>`;
      document.body.appendChild(el);
      hookDialog = { id: req.id, el, tab: T() };
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

    const hookStatuses = {};
    function onHookUiRequest(req) {
      if (req.method === "notify") {
        const level = req.level || "info";
        if (level === "error") appendErrorMessage(req.message);
        else { showStatusBanner(req.message); setTimeout(() => { if (document.getElementById("status-banner").innerText === req.message) hideStatusBanner(); }, 6000); }
        return;
      }
      if (req.method === "set_status") {
        if (req.statusText) hookStatuses[req.statusKey] = req.statusText; else delete hookStatuses[req.statusKey];
        const extra = Object.values(hookStatuses).join(" · ");
        telemetryStats.dataset.hook = extra;
        refreshState();
        return;
      }
      if (req.method === "set_editor_text") { promptInput.value = req.text || ""; return; }
      openHookDialog(req);
    }

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && hookDialog) { const id = hookDialog.id; closeHookDialog(); hookReply({ id, cancelled: true }); }
    });

    // ---- accounts panel -----------------------------------------------------------------
    //
    // The Antigravity sign-ins `antigravity-accounts.json` keeps: which one is live, when each
    // token runs out, and the live one's quota pools. Every button is an endpoint that goes
    // through `Auth`, so the store and `auth.json` move together exactly as `/antigravity.accounts`
    // moves them. Nothing here is cached: the panel re-asks every time it opens or changes.
    const accountsBtnEl = document.getElementById("accounts-btn");

    if (accountsBtnEl) {
      accountsBtnEl.addEventListener("click", openAccounts);
    }
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeAccounts();
    });

    if (themeBtn) {
      themeBtn.addEventListener("click", openThemeModal);
    }

    if (langBtn) {
      langBtn.addEventListener("click", openLangModal);
    }

    // Mobile More Menu dropdown handling
    const moreMenuBtn = document.getElementById("more-menu-btn");
    const moreMenuDropdown = document.getElementById("more-menu-dropdown");
    const moreThemeBtn = document.getElementById("more-theme-btn");
    const moreLangBtn = document.getElementById("more-lang-btn");
    const moreAccountsBtn = document.getElementById("more-accounts-btn");

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

    if (moreThemeBtn) {
      moreThemeBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleMoreMenu(false);
        openThemeModal();
      });
    }

    if (moreLangBtn) {
      moreLangBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleMoreMenu(false);
        openLangModal();
      });
    }

    if (moreAccountsBtn) {
      moreAccountsBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        toggleMoreMenu(false);
        openAccounts();
      });
    }

    document.addEventListener("click", (e) => {
      if (moreMenuDropdown && moreMenuDropdown.style.display !== "none") {
        if (!moreMenuDropdown.contains(e.target) && !moreMenuBtn.contains(e.target)) {
          toggleMoreMenu(false);
        }
      }
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

    function renderTabs() {
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
        close.title = "Close tab";
        close.addEventListener("click", (e) => { e.stopPropagation(); closeTab(t); });
        el.appendChild(label);
        el.appendChild(close);
        el.addEventListener("click", () => { if (!isActive) openTab(t); });
        sessionTabs.appendChild(el);
      }
      const plus = document.createElement("div");
      plus.className = "session-tab-new";
      plus.textContent = "+";
      plus.title = "New session";
      plus.addEventListener("click", () => startNewSession(active?.cwd));
      sessionTabs.appendChild(plus);
    }

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
    function applyActiveTabToChrome() {
      const cur = active;
      if (!cur) return;
      cwdDisplay.innerText = cur.cwd ? (cur.cwd.split("/").filter(Boolean).pop() || cur.cwd) : "~";
      if (sessionTitleDisplay) {
        const cleanName = cleanSessionTitle(cur.name || cur.opening);
        sessionTitleDisplay.innerText = cleanName ? ` • ${cleanName}` : "";
      }
      updateSessionFileBadge(cur);
      sendBtn.style.display = cur.isRunning ? "none" : "flex";
      stopBtn.style.display = cur.isRunning ? "flex" : "none";
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
      telemetryStats.innerText = (telemetryStats.dataset.hook ? telemetryStats.dataset.hook + "  " : "")
        + `↑${formatK(stats.input || 0)} ↓${formatK(stats.output || 0)} $${Number(stats.cost || 0).toFixed(3)} ${pct}%/${windowLabel}`;

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
    async function loadModels() {
      try {
        availableModels = await fetchJson("/api/models", "the model list");
        populateProviders(availableModels);
      } catch (err) { pigUnreachable("the model list", err); }
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
      const model = availableModels.find(m => m.id === modelId);
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

    // 1. Level 1: Workspaces/Directories
    async function loadWorkspaces() {
      currentView = "workspaces";
      sidebarHeader.innerHTML = `
        <span style="font-weight:600;">${escapeHtml(t("workspaces"))}</span>
        <span style="font-size:11px; color:var(--text-dim);">${escapeHtml(t("directories"))}</span>
      `;
      sidebarList.innerHTML = `<div style="padding:12px; color:var(--text-dim); font-size:12px;">${escapeHtml(t("loading_folders"))}</div>`;

      try {
        const res = await fetch("/api/folders");
        const data = await res.json();
        sidebarList.innerHTML = "";

        for (const ws of data.workspaces || []) {
          const item = document.createElement("div");
          item.className = "drawer-item" + (ws.isCurrent ? " active" : "");
          item.innerHTML = `
            <span class="drawer-label" title="${ws.path}">${ws.name}</span>
            <span class="drawer-badge">${escapeHtml(t("sessions_count", { count: ws.sessionCount }))}</span>
          `;
          item.addEventListener("click", () => enterWorkspace(ws));
          sidebarList.appendChild(item);
        }
      } catch (err) {
        sidebarList.innerHTML = `<div style="padding:12px; color:var(--error); font-size:12px;">${escapeHtml(t("failed_folders"))}</div>`;
      }
    }

    // 2. Level 2: Sessions under chosen workspace
    async function enterWorkspace(ws) {
      currentView = "sessions";
      selectedWorkspace = ws;
      sidebarHeader.innerHTML = `
        <button id="back-folders" class="back-btn">${escapeHtml(t("back_workspaces"))}</button>
        <span style="font-size:12px; font-weight:600; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${ws.name}</span>
      `;
      document.getElementById("back-folders").addEventListener("click", loadWorkspaces);

      sidebarList.innerHTML = `<div style="padding:12px; color:var(--text-dim); font-size:12px;">${escapeHtml(t("loading_sessions"))}</div>`;

      try {
        const res = await fetch(`/api/sessions?cwd=${encodeURIComponent(ws.path)}`);
        const sessions = await res.json();
        sidebarList.innerHTML = "";

        if (sessions.length === 0) {
          sidebarList.innerHTML = `<div style="padding:12px; color:var(--text-dim); font-size:12px;">${escapeHtml(t("no_sessions"))}</div>`;
          return;
        }

        for (const s of sessions) {
          const item = document.createElement("div");
          item.className = "drawer-item session-item";
          item.dataset.sessionPath = s.path;

          const label = document.createElement("span");
          label.className = "drawer-label";
          label.title = s.filename;
          label.textContent = cleanSessionTitle(s.opening) || s.filename;

          const actions = document.createElement("div");
          actions.className = "drawer-item-actions";

          const editBtn = document.createElement("button");
          editBtn.className = "drawer-action-btn edit-btn";
          editBtn.title = t("rename_session");
          editBtn.textContent = "✏️";
          editBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            startRenameSession(item, label, s, ws.path);
          });

          const delBtn = document.createElement("button");
          delBtn.className = "drawer-action-btn delete-btn";
          delBtn.title = t("delete_session");
          delBtn.textContent = "🗑️";
          delBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            confirmDeleteSession(item, s, ws.path);
          });

          actions.appendChild(editBtn);
          actions.appendChild(delBtn);

          item.appendChild(label);
          item.appendChild(actions);
          item.addEventListener("click", () => switchSession(s.path, s.filename, ws.path));
          sidebarList.appendChild(item);
        }
      } catch (err) {
        sidebarList.innerHTML = `<div style="padding:12px; color:var(--error); font-size:12px;">${escapeHtml(t("failed_sessions"))}</div>`;
      }
    }

    /** Rename session via a clean, mobile-friendly centered Modal (matching Account popup) */
    function startRenameSession(item, labelEl, session, cwd) {
      const currentCleanName = cleanSessionTitle(labelEl.textContent) || session.filename;

      openModal({
        title: t("rename_session"),
        bodyHtml: `
          <div style="display:flex; flex-direction:column; gap:10px;">
            <label style="color:var(--text-muted); font-size:12px;">${escapeHtml(t("rename_label"))}</label>
            <input class="modal-input" id="rename-session-input" type="text" value="${escapeHtml(currentCleanName)}" placeholder="${escapeHtml(t("rename_placeholder"))}" autocomplete="off">
            <div style="color:var(--text-dim); font-size:11px; font-family:var(--font-mono); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(session.filename)}">
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
              alert(data.error || "Failed to rename session");
              return false;
            }
            labelEl.textContent = newName;
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
            <div style="margin:12px 0; padding:10px 12px; background:rgba(255,255,255,0.04); border:1px solid var(--border); border-radius:6px; font-weight:600; color:var(--text); word-break:break-word;">
              ${escapeHtml(displayName)}
            </div>
            <div style="color:#f87171; font-size:12px;">⚠️ ${escapeHtml(t("delete_hint"))}</div>
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
              alert(data.error || "Failed to delete session");
              return false;
            }

            item.style.transition = "opacity 0.2s, height 0.2s, padding 0.2s";
            item.style.opacity = "0";
            item.style.height = "0px";
            item.style.paddingTop = "0px";
            item.style.paddingBottom = "0px";
            setTimeout(() => item.remove(), 200);

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
      // Both a message event and auto_compaction_end may announce the same summary.
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
        img.src = `data:${image.mimeType};base64,${image.data}`;
        img.addEventListener("click", () => openImageLightbox(img.src));
        block.appendChild(img);
      }
      chatScroll.appendChild(block);
      scrollToBottomIfNeeded();
    }

    function renderMessages(msgs) {
      {
        const tab = T();
        chatScroll.innerHTML = "";
        if (tab) {
          tab.toolCards = tab.toolCards || new Map();
          tab.toolCards.clear();
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
              result: { content: [{ type: "text", text: m.output }], details: { exitCode: m.exitCode, cancelled: m.cancelled, fullOutputPath: m.spillPath } },
              isError: !!m.cancelled || (typeof m.exitCode === "number" && m.exitCode !== 0),
            });
            chatScroll.appendChild(card.element);
          }
        }
        chatScroll.scrollTop = chatScroll.scrollHeight;
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
      loadExtensionLocales();
      loadModels();
      network.connect();
      let saved = null;
      try { saved = JSON.parse(localStorage.getItem(TABS_KEY) || "null"); } catch (e) {}
      try {
        const r = await fetchJson("/api/running", "the server");
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

    setAccountsStateHook(() => {
      refreshState();
    });

    network.init({
      getTabs: () => tabs,
      getActiveTab: () => active,
      showStatus: showStatusBanner,
      hideStatus: hideStatusBanner,
      onReachable: pigReachable,
    });

    initTheme();

    new SlashAutocomplete({
      inputElement: promptInput,
      anchorElement: document.querySelector(".input-wrapper"),
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
    });

    boot();

