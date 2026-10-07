import { network } from "../network.js";
import { escapeHtml, openModal } from "../utils.js";
import { t } from "../i18n.js";
import { D as Terminal, o as FitAddon } from "../vendor/xterm.js";

/** Build xterm color scheme for node terminal */
function getTermTheme() {
  const theme = document.documentElement.getAttribute("data-theme") || "dark";
  if (theme === "light") {
    return {
      background: "#ffffff",
      foreground: "#1e293b",
      cursor: "#0284c7",
      cursorAccent: "#ffffff",
      selectionBackground: "rgba(2, 132, 199, 0.3)",
    };
  }
  if (theme === "labra") {
    return {
      background: "#121714",
      foreground: "#e2e6e3",
      cursor: "#ff66cc",
      cursorAccent: "#121714",
      selectionBackground: "rgba(255, 102, 204, 0.35)",
    };
  }
  return {
    background: "#070a12",
    foreground: "#e2e8f0",
    cursor: "#38bdf8",
    cursorAccent: "#070a12",
    selectionBackground: "rgba(56, 189, 248, 0.3)",
  };
}

/**
 * NodeWorkbench Component
 *
 * Full-featured SSH Node Workbench & SFTP File Explorer:
 * - Remote SSH Nodes management (CRUD, group organization, OpenSSH ~/.ssh/config discovery)
 * - Host key SHA-256 fingerprint verification and trust prompt
 * - Interactive Remote SSH Terminal powered by xterm.js
 * - SFTP remote directory browser and 512 KiB file editor/viewer
 */
export class NodeWorkbench {
  constructor() {
    this.nodes = [];
    this.sources = [];
    this.selectedNodeId = null;
    this.connectedNodeIds = new Set();
    this.activeSubTab = "terminal"; // "terminal" | "sftp"

    // Remote terminals: nodeId => array of { id, term, fit, container, active }
    this.remoteTerminals = new Map();
    this.activeTerminalIds = new Map(); // nodeId => terminalId

    // SFTP state
    this.currentRemotePath = "/";
    this.remoteEntries = [];
    this.currentEditingFile = null;

    this.isOpen = false;
    this.initElement();
    this.initNetwork();
  }

  initElement() {
    this.element = document.createElement("div");
    this.element.className = "node-workbench-modal modal-backdrop";
    this.element.style.display = "none";

    this.element.innerHTML = `
      <div class="node-workbench-window">
        <div class="node-workbench-sidebar">
          <div class="node-sidebar-header">
            <div class="node-sidebar-title-row">
              <span class="node-sidebar-title" data-i18n="nodes_title">SSH 节点工作台</span>
              <button class="node-btn-icon node-add-btn" title="添加节点 / Add Node">+</button>
            </div>
            <div class="node-sidebar-search-row">
              <input type="text" class="node-search-input" placeholder="搜索节点 / Search..." spellcheck="false">
            </div>
          </div>
          <div class="node-sidebar-list" id="node-sidebar-list">
            <div class="node-empty-hint">加载中 / Loading...</div>
          </div>
          <div class="node-sidebar-footer">
            <button class="node-import-btn" id="node-import-ssh-btn" data-i18n="node_import_ssh">从 ~/.ssh/config 导入</button>
          </div>
        </div>

        <div class="node-workbench-content">
          <div class="node-content-header" id="node-content-header" style="display:none;">
            <div class="node-header-info">
              <span class="node-header-name" id="node-header-name">Node</span>
              <span class="node-header-target" id="node-header-target">user@host:22</span>
              <span class="node-badge" id="node-badge-status">未连接</span>
              <span class="node-badge-fp" id="node-badge-fp" style="display:none;"></span>
            </div>
            <div class="node-header-actions">
              <button class="node-action-btn" id="node-connect-btn" data-i18n="node_connect">连接</button>
              <button class="node-action-btn node-btn-danger" id="node-disconnect-btn" style="display:none;" data-i18n="node_disconnect">断开</button>
              <button class="node-action-btn" id="node-edit-btn" data-i18n="edit_node">编辑</button>
              <button class="node-close-window-btn" id="node-close-window-btn">✕</button>
            </div>
          </div>

          <!-- Sub-tabs bar: SSH Terminal vs SFTP Files -->
          <div class="node-subtabs-bar" id="node-subtabs-bar" style="display:none;">
            <div class="node-subtabs-left">
              <button class="node-subtab-btn active" data-subtab="terminal" data-i18n="node_terminal">SSH 终端</button>
              <button class="node-subtab-btn" data-subtab="sftp" data-i18n="node_sftp">SFTP 文件</button>
            </div>
            <div class="node-subtabs-right" id="node-terminal-tabs-ctrl">
              <button class="node-subtab-add-term" id="node-add-term-btn" title="新建 SSH 终端 / New Terminal">+</button>
            </div>
          </div>

          <!-- Empty View -->
          <div class="node-empty-view" id="node-empty-view">
            <div class="node-empty-icon">🖥️</div>
            <div class="node-empty-title" data-i18n="nodes_title">SSH 节点工作台</div>
            <div class="node-empty-desc">在左侧选择或添加远程 SSH 节点以连接终端或管理 SFTP 文件。</div>
          </div>

          <!-- Terminal Workspace Pane -->
          <div class="node-pane-terminal" id="node-pane-terminal" style="display:none;">
            <div class="node-terminal-instances" id="node-terminal-instances"></div>
          </div>

          <!-- SFTP Workspace Pane -->
          <div class="node-pane-sftp" id="node-pane-sftp" style="display:none;">
            <div class="node-sftp-toolbar">
              <button class="node-sftp-up-btn" id="node-sftp-up-btn" title="上一级 / Up">↑</button>
              <input type="text" class="node-sftp-path-input" id="node-sftp-path-input" value="/">
              <button class="node-sftp-browse-btn" id="node-sftp-browse-btn" data-i18n="node_browse">浏览</button>
            </div>
            <div class="node-sftp-filelist" id="node-sftp-filelist"></div>
          </div>
        </div>
      </div>
    `;

    this.sidebarListEl = this.element.querySelector("#node-sidebar-list");
    this.searchInput = this.element.querySelector(".node-search-input");
    this.contentHeader = this.element.querySelector("#node-content-header");
    this.headerNameEl = this.element.querySelector("#node-header-name");
    this.headerTargetEl = this.element.querySelector("#node-header-target");
    this.statusBadgeEl = this.element.querySelector("#node-badge-status");
    this.fpBadgeEl = this.element.querySelector("#node-badge-fp");
    this.connectBtn = this.element.querySelector("#node-connect-btn");
    this.disconnectBtn = this.element.querySelector("#node-disconnect-btn");
    this.editBtn = this.element.querySelector("#node-edit-btn");
    this.closeWindowBtn = this.element.querySelector("#node-close-window-btn");
    this.subtabsBar = this.element.querySelector("#node-subtabs-bar");
    this.emptyView = this.element.querySelector("#node-empty-view");
    this.paneTerminal = this.element.querySelector("#node-pane-terminal");
    this.terminalInstancesEl = this.element.querySelector("#node-terminal-instances");
    this.paneSftp = this.element.querySelector("#node-pane-sftp");
    this.sftpPathInput = this.element.querySelector("#node-sftp-path-input");
    this.sftpFileListEl = this.element.querySelector("#node-sftp-filelist");

    this.bindEvents();
    document.body.appendChild(this.element);
  }

  bindEvents() {
    this.closeWindowBtn.addEventListener("click", () => this.close());
    this.element.addEventListener("click", (e) => {
      if (e.target === this.element) this.close();
    });

    this.element.querySelector(".node-add-btn").addEventListener("click", () => this.showEditModal());
    this.editBtn.addEventListener("click", () => {
      const node = this.nodes.find((n) => n.id === this.selectedNodeId);
      if (node) this.showEditModal(node);
    });

    this.element.querySelector("#node-import-ssh-btn").addEventListener("click", () => this.importSshConfig());

    this.searchInput.addEventListener("input", () => this.renderSidebar());

    this.connectBtn.addEventListener("click", () => {
      if (!this.selectedNodeId) return;
      network.send({
        type: "node_request",
        action: "connect",
        requestId: "req-" + Math.random().toString(36).slice(2),
        nodeId: this.selectedNodeId,
      });
    });

    this.disconnectBtn.addEventListener("click", () => {
      if (!this.selectedNodeId) return;
      this.disconnectNode(this.selectedNodeId);
    });

    this.subtabsBar.querySelectorAll(".node-subtab-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const subtab = btn.getAttribute("data-subtab");
        if (subtab) this.switchSubTab(subtab);
      });
    });

    this.element.querySelector("#node-add-term-btn").addEventListener("click", () => {
      if (this.selectedNodeId) {
        this.openRemoteTerminal(this.selectedNodeId);
      }
    });

    this.element.querySelector("#node-sftp-browse-btn").addEventListener("click", () => {
      this.browseRemoteDir(this.sftpPathInput.value.trim());
    });

    this.element.querySelector("#node-sftp-up-btn").addEventListener("click", () => {
      const cur = this.sftpPathInput.value.trim().replace(/\/+$/, "") || "/";
      const parts = cur.split("/").filter(Boolean);
      parts.pop();
      const parent = "/" + parts.join("/");
      this.sftpPathInput.value = parent;
      this.browseRemoteDir(parent);
    });

    this.sftpPathInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        this.browseRemoteDir(this.sftpPathInput.value.trim());
      }
    });
  }

  initNetwork() {
    network.addMessageListener((msg) => {
      if (!msg || typeof msg !== "object") return;

      if (msg.type === "node_event") {
        this.handleNodeEvent(msg);
      }
    });
  }

  handleNodeEvent(msg) {
    if (msg.event === "state") {
      this.nodes = Array.isArray(msg.data?.nodes) ? msg.data.nodes : [];
      this.sources = Array.isArray(msg.data?.sources) ? msg.data.sources : [];
      this.renderSidebar();
      if (this.selectedNodeId) {
        this.updateHeader(this.selectedNodeId);
      }
      return;
    }

    if (msg.event === "fingerprint_prompt") {
      this.showFingerprintPrompt(msg.nodeId, msg.data?.fingerprint);
      return;
    }

    if (msg.event === "failure") {
      alert(`[SSH Error] ${msg.message || "Operation failed"}`);
      return;
    }

    if (msg.event === "result") {
      if (msg.action === "connect") {
        this.connectedNodeIds.add(msg.nodeId);
        this.updateHeader(msg.nodeId);
        // Automatically open terminal on connect if none exists
        if (!this.remoteTerminals.get(msg.nodeId)?.length) {
          this.openRemoteTerminal(msg.nodeId);
        }
      } else if (msg.action === "list") {
        this.remoteEntries = Array.isArray(msg.data?.entries) ? msg.data.entries : [];
        this.currentRemotePath = msg.data?.path || this.currentRemotePath;
        this.sftpPathInput.value = this.currentRemotePath;
        this.renderSftpFiles();
      } else if (msg.action === "read") {
        this.showFileEditor(msg.data?.path, msg.data?.text);
      } else if (msg.action === "write") {
        alert("✓ 文件保存成功 / File saved successfully!");
      }
    }
  }

  open() {
    this.element.style.display = "flex";
    this.isOpen = true;
    network.send({
      type: "node_request",
      action: "state",
      requestId: "init-state",
    });
  }

  close() {
    this.element.style.display = "none";
    this.isOpen = false;
  }

  renderSidebar() {
    const q = this.searchInput.value.toLowerCase().trim();
    const filtered = this.nodes.filter(
      (n) =>
        !q ||
        n.name.toLowerCase().includes(q) ||
        n.host.toLowerCase().includes(q) ||
        (n.group && n.group.toLowerCase().includes(q))
    );

    if (filtered.length === 0) {
      this.sidebarListEl.innerHTML = `<div class="node-empty-hint">${
        this.nodes.length === 0 ? "点击顶部 + 添加第一台 SSH 节点" : "未找到匹配节点"
      }</div>`;
      return;
    }

    // Group nodes
    const groups = {};
    for (const node of filtered) {
      const g = node.group || "Default";
      groups[g] = groups[g] || [];
      groups[g].push(node);
    }

    let html = "";
    for (const [groupName, groupNodes] of Object.entries(groups)) {
      html += `<div class="node-group-label">${escapeHtml(groupName)}</div>`;
      for (const node of groupNodes) {
        const isSelected = node.id === this.selectedNodeId;
        const isConnected = this.connectedNodeIds.has(node.id);
        html += `
          <div class="node-item ${isSelected ? "active" : ""}" data-node-id="${node.id}">
            <div class="node-item-status ${isConnected ? "connected" : ""}"></div>
            <div class="node-item-content">
              <div class="node-item-name">${escapeHtml(node.name)}</div>
              <div class="node-item-target">${escapeHtml(node.username)}@${escapeHtml(node.host)}:${node.port}</div>
            </div>
            <button class="node-item-delete-btn" data-delete-id="${node.id}" title="删除 / Delete">×</button>
          </div>
        `;
      }
    }

    this.sidebarListEl.innerHTML = html;

    this.sidebarListEl.querySelectorAll(".node-item").forEach((el) => {
      el.addEventListener("click", (e) => {
        if (e.target.closest(".node-item-delete-btn")) return;
        const id = el.getAttribute("data-node-id");
        if (id) this.selectNode(id);
      });
    });

    this.sidebarListEl.querySelectorAll(".node-item-delete-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const id = btn.getAttribute("data-delete-id");
        if (id && confirm("确定删除该节点吗？")) {
          network.send({
            type: "node_request",
            action: "delete",
            requestId: "del-" + id,
            nodeId: id,
          });
          if (this.selectedNodeId === id) {
            this.selectedNodeId = null;
            this.emptyView.style.display = "flex";
            this.contentHeader.style.display = "none";
            this.subtabsBar.style.display = "none";
            this.paneTerminal.style.display = "none";
            this.paneSftp.style.display = "none";
          }
        }
      });
    });
  }

  selectNode(id) {
    this.selectedNodeId = id;
    this.renderSidebar();
    this.updateHeader(id);

    this.emptyView.style.display = "none";
    this.contentHeader.style.display = "flex";
    this.subtabsBar.style.display = "flex";

    this.switchSubTab(this.activeSubTab);
  }

  updateHeader(id) {
    const node = this.nodes.find((n) => n.id === id);
    if (!node) return;

    this.headerNameEl.textContent = node.name;
    this.headerTargetEl.textContent = `${node.username}@${node.host}:${node.port}`;

    const isConnected = this.connectedNodeIds.has(id);
    this.statusBadgeEl.textContent = isConnected ? "已连接" : "未连接";
    this.statusBadgeEl.className = "node-badge " + (isConnected ? "connected" : "");

    if (node.fingerprint) {
      this.fpBadgeEl.style.display = "inline-block";
      this.fpBadgeEl.textContent = "🔒 " + (node.fingerprint.length > 20 ? node.fingerprint.slice(0, 18) + "…" : node.fingerprint);
      this.fpBadgeEl.title = node.fingerprint;
    } else {
      this.fpBadgeEl.style.display = "none";
    }

    this.connectBtn.style.display = isConnected ? "none" : "inline-flex";
    this.disconnectBtn.style.display = isConnected ? "inline-flex" : "none";
  }

  disconnectNode(id) {
    this.connectedNodeIds.delete(id);
    network.send({
      type: "node_request",
      action: "disconnect",
      requestId: "disc-" + id,
      nodeId: id,
    });
    this.updateHeader(id);
  }

  switchSubTab(tabName) {
    this.activeSubTab = tabName;
    this.subtabsBar.querySelectorAll(".node-subtab-btn").forEach((btn) => {
      btn.classList.toggle("active", btn.getAttribute("data-subtab") === tabName);
    });

    if (tabName === "terminal") {
      this.paneTerminal.style.display = "flex";
      this.paneSftp.style.display = "none";
      this.element.querySelector("#node-terminal-tabs-ctrl").style.display = "flex";
      const termId = this.activeTerminalIds.get(this.selectedNodeId);
      if (termId) this.focusRemoteTerminal(this.selectedNodeId, termId);
    } else {
      this.paneTerminal.style.display = "none";
      this.paneSftp.style.display = "flex";
      this.element.querySelector("#node-terminal-tabs-ctrl").style.display = "none";
      const node = this.nodes.find((n) => n.id === this.selectedNodeId);
      if (node && this.remoteEntries.length === 0) {
        this.browseRemoteDir(node.defaultDir || "/");
      }
    }
  }

  openRemoteTerminal(nodeId) {
    const termId = "ssh-" + Math.random().toString(36).slice(2, 9);
    const container = document.createElement("div");
    container.className = "node-xterm-instance";
    container.style.display = "none";
    this.terminalInstancesEl.appendChild(container);

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

    term.onData((data) => {
      network.send({
        type: "terminal_input",
        terminalId: termId,
        data,
      });
    });

    const tObj = { id: termId, term, fit, container };
    let terms = this.remoteTerminals.get(nodeId);
    if (!terms) {
      terms = [];
      this.remoteTerminals.set(nodeId, terms);
    }
    terms.push(tObj);
    this.activeTerminalIds.set(nodeId, termId);

    // Trigger terminal open on backend
    requestAnimationFrame(() => {
      try {
        fit.fit();
      } catch (e) {}

      network.send({
        type: "node_request",
        action: "terminal_open",
        requestId: "open-" + termId,
        nodeId,
        terminalId: termId,
        payload: {
          cols: term.cols || 80,
          rows: term.rows || 24,
        },
      });
    });

    this.focusRemoteTerminal(nodeId, termId);
  }

  focusRemoteTerminal(nodeId, termId) {
    const terms = this.remoteTerminals.get(nodeId) || [];
    for (const t of terms) {
      if (t.id === termId) {
        t.container.style.display = "block";
        requestAnimationFrame(() => {
          try {
            t.fit.fit();
            network.send({
              type: "terminal_resize",
              terminalId: t.id,
              cols: t.term.cols,
              rows: t.term.rows,
            });
          } catch (e) {}
          t.term.focus();
        });
      } else {
        t.container.style.display = "none";
      }
    }
  }

  browseRemoteDir(path) {
    if (!this.selectedNodeId) return;
    network.send({
      type: "node_request",
      action: "list",
      requestId: "ls-" + Math.random().toString(36).slice(2),
      nodeId: this.selectedNodeId,
      payload: { path },
    });
  }

  renderSftpFiles() {
    if (!this.remoteEntries.length) {
      this.sftpFileListEl.innerHTML = `<div class="node-empty-hint">空目录 / Empty directory</div>`;
      return;
    }

    let html = `
      <table class="node-sftp-table">
        <thead>
          <tr>
            <th style="width:40px;"></th>
            <th>名称 / Name</th>
            <th style="width:100px; text-align:right;">大小 / Size</th>
          </tr>
        </thead>
        <tbody>
    `;

    for (const item of this.remoteEntries) {
      const isDir = item.type === "dir";
      const icon = isDir ? "📁" : "📄";
      const sizeStr = isDir ? "-" : this.formatSize(item.size);
      html += `
        <tr class="node-sftp-row" data-name="${escapeHtml(item.name)}" data-is-dir="${isDir ? "1" : "0"}">
          <td style="text-align:center;">${icon}</td>
          <td class="node-sftp-name ${isDir ? "dir" : "file"}">${escapeHtml(item.name)}</td>
          <td style="text-align:right; color:var(--text-dim);">${sizeStr}</td>
        </tr>
      `;
    }

    html += `</tbody></table>`;
    this.sftpFileListEl.innerHTML = html;

    this.sftpFileListEl.querySelectorAll(".node-sftp-row").forEach((row) => {
      row.addEventListener("click", () => {
        const name = row.getAttribute("data-name");
        const isDir = row.getAttribute("data-is-dir") === "1";
        const basePath = this.currentRemotePath.replace(/\/+$/, "");
        const target = basePath + "/" + name;

        if (isDir) {
          this.browseRemoteDir(target);
        } else {
          // Read file
          network.send({
            type: "node_request",
            action: "read",
            requestId: "read-" + Math.random().toString(36).slice(2),
            nodeId: this.selectedNodeId,
            payload: { path: target },
          });
        }
      });
    });
  }

  formatSize(bytes) {
    if (!bytes || bytes < 1024) return (bytes || 0) + " B";
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / 1048576).toFixed(1) + " MB";
  }

  showFileEditor(path, text) {
    const modal = document.createElement("div");
    modal.className = "modal-backdrop";
    modal.innerHTML = `
      <div class="modal-card" style="width:720px; max-width:92vw;">
        <div class="modal-header">
          <div class="modal-title">📄 ${escapeHtml(path)}</div>
          <button class="modal-close-btn" id="sftp-edit-close">✕</button>
        </div>
        <div style="padding:14px;">
          <textarea class="node-editor-textarea" style="width:100%; height:380px; font-family:var(--font-mono); font-size:12.5px; background:rgba(0,0,0,0.3); border:1px solid var(--border); color:var(--text); padding:10px; border-radius:6px; resize:none;" spellcheck="false">${escapeHtml(text)}</textarea>
        </div>
        <div class="modal-footer" style="padding:10px 14px; display:flex; justify-content:flex-end; gap:8px;">
          <button class="btn btn-secondary" id="sftp-edit-cancel">取消</button>
          <button class="btn btn-primary" id="sftp-edit-save">保存写回远端</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    modal.querySelector("#sftp-edit-close").addEventListener("click", () => modal.remove());
    modal.querySelector("#sftp-edit-cancel").addEventListener("click", () => modal.remove());

    modal.querySelector("#sftp-edit-save").addEventListener("click", () => {
      const newText = modal.querySelector(".node-editor-textarea").value;
      network.send({
        type: "node_request",
        action: "write",
        requestId: "write-" + Math.random().toString(36).slice(2),
        nodeId: this.selectedNodeId,
        payload: { path, text: newText },
      });
      modal.remove();
    });
  }

  showFingerprintPrompt(nodeId, fingerprint) {
    const modal = document.createElement("div");
    modal.className = "modal-backdrop";
    modal.innerHTML = `
      <div class="modal-card" style="width:480px; max-width:90vw;">
        <div class="modal-header">
          <div class="modal-title">🔒 验证主机密钥指纹</div>
          <button class="modal-close-btn" id="fp-close">✕</button>
        </div>
        <div style="padding:16px; font-size:13px; line-height:1.6;">
          <p style="color:var(--text-muted); margin-bottom:12px;">检测到远程服务器的主机密钥指纹尚未加入信任列表，请核对是否可信：</p>
          <div style="font-family:var(--font-mono); font-size:12px; background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.25); color:#38bdf8; padding:8px 12px; border-radius:6px; word-break:break-all;">${escapeHtml(fingerprint)}</div>
        </div>
        <div class="modal-footer" style="padding:12px 16px; display:flex; justify-content:flex-end; gap:8px;">
          <button class="btn btn-secondary" id="fp-cancel">取消</button>
          <button class="btn btn-primary" id="fp-trust">信任并连接</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    modal.querySelector("#fp-close").addEventListener("click", () => modal.remove());
    modal.querySelector("#fp-cancel").addEventListener("click", () => modal.remove());
    modal.querySelector("#fp-trust").addEventListener("click", () => {
      network.send({
        type: "node_request",
        action: "trust",
        requestId: "trust-" + nodeId,
        nodeId,
        payload: { fingerprint },
      });
      modal.remove();
    });
  }

  showEditModal(node = null) {
    const isEdit = node !== null;
    const modal = document.createElement("div");
    modal.className = "modal-backdrop";
    modal.innerHTML = `
      <div class="modal-card" style="width:520px; max-width:92vw;">
        <div class="modal-header">
          <div class="modal-title">${isEdit ? "编辑 SSH 节点" : "添加 SSH 节点"}</div>
          <button class="modal-close-btn" id="node-form-close">✕</button>
        </div>
        <div style="padding:16px; display:flex; flex-direction:column; gap:12px; font-size:13px;">
          <div style="display:flex; gap:10px;">
            <div style="flex:1;">
              <label style="display:block; margin-bottom:4px; color:var(--text-muted);">名称 / Name</label>
              <input type="text" id="f-name" class="node-input" value="${escapeHtml(node?.name || "")}" placeholder="生产服务器">
            </div>
            <div style="width:130px;">
              <label style="display:block; margin-bottom:4px; color:var(--text-muted);">分组 / Group</label>
              <input type="text" id="f-group" class="node-input" value="${escapeHtml(node?.group || "Default")}" placeholder="Default">
            </div>
          </div>
          <div style="display:flex; gap:10px;">
            <div style="flex:1;">
              <label style="display:block; margin-bottom:4px; color:var(--text-muted);">主机地址 / Host</label>
              <input type="text" id="f-host" class="node-input" value="${escapeHtml(node?.host || "")}" placeholder="192.168.1.100">
            </div>
            <div style="width:90px;">
              <label style="display:block; margin-bottom:4px; color:var(--text-muted);">端口 / Port</label>
              <input type="number" id="f-port" class="node-input" value="${node?.port || 22}">
            </div>
          </div>
          <div style="display:flex; gap:10px;">
            <div style="flex:1;">
              <label style="display:block; margin-bottom:4px; color:var(--text-muted);">用户名 / Username</label>
              <input type="text" id="f-user" class="node-input" value="${escapeHtml(node?.username || "root")}">
            </div>
            <div style="flex:1;">
              <label style="display:block; margin-bottom:4px; color:var(--text-muted);">认证方式 / Auth</label>
              <select id="f-auth" class="node-input">
                <option value="key" ${node?.auth === "key" ? "selected" : ""}>私钥 (Private Key)</option>
                <option value="password" ${node?.auth === "password" ? "selected" : ""}>密码 (Password)</option>
                <option value="agent" ${node?.auth === "agent" ? "selected" : ""}>SSH Agent</option>
              </select>
            </div>
          </div>
          <div id="f-key-row" style="display:${node?.auth === "password" || node?.auth === "agent" ? "none" : "block"};">
            <label style="display:block; margin-bottom:4px; color:var(--text-muted);">私钥路径 / Key Path</label>
            <input type="text" id="f-keypath" class="node-input" value="${escapeHtml(node?.keyPath || "~/.ssh/id_ed25519")}" placeholder="~/.ssh/id_ed25519">
          </div>
          <div id="f-secret-row">
            <label style="display:block; margin-bottom:4px; color:var(--text-muted);" id="f-secret-label">${node?.auth === "password" ? "SSH 密码 / Password" : "密钥口令 / Passphrase (可选)"}</label>
            <input type="password" id="f-secret" class="node-input" placeholder="${isEdit ? "（保持不变请留空）" : ""}">
          </div>
          <div>
            <label style="display:block; margin-bottom:4px; color:var(--text-muted);">默认远端目录 / Default Directory</label>
            <input type="text" id="f-dir" class="node-input" value="${escapeHtml(node?.defaultDir || "/")}">
          </div>
        </div>
        <div class="modal-footer" style="padding:12px 16px; display:flex; justify-content:flex-end; gap:8px;">
          <button class="btn btn-secondary" id="node-form-cancel">取消</button>
          <button class="btn btn-primary" id="node-form-save">保存</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const authSelect = modal.querySelector("#f-auth");
    const keyRow = modal.querySelector("#f-key-row");
    const secretLabel = modal.querySelector("#f-secret-label");

    authSelect.addEventListener("change", () => {
      const val = authSelect.value;
      keyRow.style.display = val === "key" ? "block" : "none";
      secretLabel.textContent = val === "password" ? "SSH 密码 / Password" : "密钥口令 / Passphrase (可选)";
    });

    modal.querySelector("#node-form-close").addEventListener("click", () => modal.remove());
    modal.querySelector("#node-form-cancel").addEventListener("click", () => modal.remove());

    modal.querySelector("#node-form-save").addEventListener("click", () => {
      const name = modal.querySelector("#f-name").value.trim();
      const host = modal.querySelector("#f-host").value.trim();
      if (!name || !host) {
        alert("请填写名称和主机地址");
        return;
      }

      const payload = {
        id: node?.id,
        name,
        group: modal.querySelector("#f-group").value.trim() || "Default",
        host,
        port: parseInt(modal.querySelector("#f-port").value, 10) || 22,
        username: modal.querySelector("#f-user").value.trim() || "root",
        auth: authSelect.value,
        keyPath: modal.querySelector("#f-keypath")?.value.trim() || undefined,
        defaultDir: modal.querySelector("#f-dir").value.trim() || "/",
      };

      const secret = modal.querySelector("#f-secret").value;
      if (secret) {
        payload.secret = secret;
      }

      network.send({
        type: "node_request",
        action: "save",
        requestId: "save-" + Math.random().toString(36).slice(2),
        payload,
      });

      modal.remove();
    });
  }

  importSshConfig() {
    if (!this.sources.length) {
      alert("未检测到 ~/.ssh/config 来源");
      return;
    }

    let importedCount = 0;
    for (const s of this.sources) {
      if (s.name && s.host) {
        network.send({
          type: "node_request",
          action: "save",
          requestId: "import-" + s.name,
          payload: {
            name: s.name,
            group: "OpenSSH",
            host: s.host,
            port: s.port || 22,
            username: s.username || "root",
            auth: s.keyPath ? "key" : "agent",
            keyPath: s.keyPath,
            defaultDir: "/",
          },
        });
        importedCount++;
      }
    }

    alert(`已发起导入 ${importedCount} 台 SSH 节点！`);
  }
}
