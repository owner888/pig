import { escapeHtml } from "../utils.js";
import { t } from "../i18n.js";
import { renderDiff, renderDiffFromUnified } from "../markdown.js";

/** Browser affordances; all tools use the active theme's execution colors. */
const TOOL_CONFIGS = {
  bash: { icon: "⚡", defaultOpen: true },
  read: { icon: "📄", defaultOpen: false },
  edit: { icon: "✏️", defaultOpen: true },
  write: { icon: "💾", defaultOpen: true },
  grep: { icon: "🔍", defaultOpen: false },
  find: { icon: "🔎", defaultOpen: false },
  ls: { icon: "📁", defaultOpen: false },
  web_search: { icon: "🌐", defaultOpen: false },
  fetch_web_page: { icon: "🌐", defaultOpen: false },
  browse_web_page: { icon: "🖥️", defaultOpen: false },
  computer: { icon: "🖱️", defaultOpen: true },
  generate_image: { icon: "🎨", defaultOpen: true },
};

/**
 * ToolCard Component
 *
 * Browser tool execution cards sharing the TUI's result data and theme semantics:
 * - Specific tool icons and accent left-borders
 * - Status pills (running pulse, done checkmark, error badge)
 * - Rich action summaries (file paths, bash commands, diffs)
 * - Output copy action button
 * - Clean collapsible container
 */
export class ToolCard {
  constructor(evt) {
    this.toolCallId = evt.toolCallId;
    this.toolName = evt.toolName || "";
    this.args = evt.arguments || {};
    this.rawOutput = "";
    this.result = null;
    this.isError = false;
    this.isFinished = false;
    this.startTime = Date.now();

    const cfg = TOOL_CONFIGS[this.toolName] || { icon: "🔧", defaultOpen: true };
    this.config = cfg;

    this.element = document.createElement("div");
    this.element.id = `tool-${this.toolCallId}`;
    this.element.className = "tool-card";
    this.element.dataset.tool = this.toolName;
    this.element.dataset.args = JSON.stringify(this.args);

    this.renderInitial();
  }

  getDisplayTitle() {
    const args = this.args;
    switch (this.toolName) {
      case "bash": {
        const cmd = args.command || "";
        const timeout = args.timeout ? ` (timeout ${args.timeout}s)` : "";
        return `$ ${cmd}${timeout}`;
      }
      case "read": {
        const path = args.path || "";
        let range = "";
        if (args.offset !== undefined || args.limit !== undefined) {
          const start = args.offset || 1;
          const end = args.limit !== undefined ? `-${Number(start) + Number(args.limit) - 1}` : "";
          range = `:${start}${end}`;
        }
        return `read ${path}${range}`;
      }
      case "edit":
        return `edit ${args.path || ""}`;
      case "write":
        return `write ${args.path || ""}`;
      case "grep":
        return `grep /${args.pattern || ""}/${args.path ? " in " + args.path : ""}`;
      case "find":
        return `find "${args.pattern || ""}"${args.path ? " in " + args.path : ""}`;
      case "ls":
        return `ls ${args.path || "."}`;
      case "web_search":
        return `web_search "${args.query || ""}"`;
      case "fetch_web_page":
      case "browse_web_page":
        return `${this.toolName} ${args.url || ""}`;
      case "computer":
        return `computer ${args.action || "action"}${args.coordinate ? ` [${args.coordinate.join(", ")}]` : ""}${args.text ? ` "${args.text}"` : ""}`;
      case "generate_image":
        return `generate_image "${(args.prompt || "").slice(0, 50)}"`;
      default:
        return this.toolName;
    }
  }

  renderInitial() {
    const title = this.getDisplayTitle();
    const isExpanded = this.config.defaultOpen;

    let bodyHtml = "";
    if (this.toolName === "edit" && typeof this.args.oldText === "string" && typeof this.args.newText === "string") {
      bodyHtml = renderDiff(this.args.oldText, this.args.newText);
    } else {
      bodyHtml = `<div class="tool-terminal">${escapeHtml(t("tool_running"))}</div>`;
    }

    this.element.innerHTML = `
      <div class="tool-header">
        <div class="tool-header-left">
          <span class="tool-header-chevron ${isExpanded ? 'expanded' : ''}">▶</span>
          <span class="tool-header-icon">${this.config.icon}</span>
          <span class="tool-header-title" title="${escapeHtml(title)}">${escapeHtml(title)}</span>
        </div>
        <div class="tool-header-right">
          <button class="tool-copy-btn" title="${escapeHtml(t("copy"))}" onclick="event.stopPropagation(); window.copyToolOutput?.(this)">📋</button>
          <span class="tool-header-status running">
            <span class="tool-status-dot"></span>
            ${escapeHtml(t("tool_running"))}
          </span>
        </div>
      </div>
      <div class="tool-body ${isExpanded ? 'expanded' : ''}">${bodyHtml}</div>
      <div class="tool-details-footer" hidden></div>
    `;

    const header = this.element.querySelector(".tool-header");
    header.addEventListener("click", () => this.toggleExpand());
  }

  toggleExpand() {
    const body = this.element.querySelector(".tool-body");
    const chevron = this.element.querySelector(".tool-header-chevron");
    if (!body || !chevron) return;
    const isExp = body.classList.toggle("expanded");
    chevron.classList.toggle("expanded", isExp);
  }

  updateProgress(output) {
    if (output === undefined || output === null) return;
    this.rawOutput = String(output);
    const body = this.element.querySelector(".tool-body");
    if (!body) return;

    if (this.toolName === "edit") return; // keep diff view intact

    body.innerHTML = `<div class="tool-terminal">${escapeHtml(this.rawOutput)}</div>`;
  }

  finish({ result, isError }) {
    this.isFinished = true;
    this.isError = !!isError;
    this.result = result;

    const elapsed = ((Date.now() - this.startTime) / 1000).toFixed(1);

    const statusEl = this.element.querySelector(".tool-header-status");
    if (statusEl) {
      statusEl.className = `tool-header-status ${this.isError ? 'error' : 'done'}`;
      statusEl.innerHTML = this.isError
        ? `<span>✖</span> ${escapeHtml(t("tool_failed"))}`
        : `<span>✔</span> ${escapeHtml(t("tool_done"))} <span class="tool-duration">${elapsed}s</span>`;
    }

    this.element.dataset.status = this.isError ? "error" : "done";
    const body = this.element.querySelector(".tool-body");
    if (body) {
      body.innerHTML = this.formatResultBody();
      if (this.isError) {
        body.classList.add("expanded");
        this.element.querySelector(".tool-header-chevron")?.classList.add("expanded");
      }
    }
    // Status and truncation must remain visible even when the output is collapsed.
    const footer = this.element.querySelector(".tool-details-footer");
    footer.textContent = this.#resultNotes().join("\n");
    footer.hidden = footer.textContent === "";
  }

  #resultNotes() {
    const det = this.result?.details;
    if (!det || typeof det !== "object") return [];
    const notes = [];
    if (det.cancelled) notes.push(t("command_cancelled"));
    if (typeof det.exitCode === "number" && det.exitCode !== 0) notes.push(t("command_exit", { code: det.exitCode }));
    if (det.fullOutputPath) notes.push(t("output_truncated", { path: det.fullOutputPath }));
    if (det.notice) notes.push(det.notice);
    return notes;
  }

  formatResultBody() {
    const result = this.result;
    if (result === undefined || result === null) {
      return "";
    }

    // The execution result's diff wins over the arguments' preview.
    if (this.toolName === "edit" && result.details?.diff) {
      this.rawOutput = result.details.diff;
      return renderDiffFromUnified(result.details.diff);
    }

    // 2. Extract text and images if structured AgentToolResult
    let textOutput = "";
    const images = [];

    if (typeof result === "string") {
      textOutput = result;
    } else if (Array.isArray(result)) {
      for (const item of result) {
        if (item?.type === "text" && item.text) textOutput += item.text;
        if (item?.type === "image" && item.data) images.push(item);
      }
    } else if (result.content && Array.isArray(result.content)) {
      for (const item of result.content) {
        if (item?.type === "text" && item.text) textOutput += item.text;
        if (item?.type === "image" && item.data) images.push(item);
      }
    } else if (result.output) {
      textOutput = typeof result.output === "string" ? result.output : JSON.stringify(result.output, null, 2);
    } else if (result.text) {
      textOutput = result.text;
    } else {
      textOutput = JSON.stringify(result, null, 2);
    }

    this.rawOutput = textOutput;

    let imagesHtml = "";
    if (images.length > 0) {
      imagesHtml = `<div class="msg-images-grid" style="margin-top:8px;">${images.map(img => `<img src="data:${escapeHtml(img.mimeType || 'image/png')};base64,${escapeHtml(img.data)}" class="msg-image-thumb" onclick="window.openImageLightbox?.(this.src)" title="Click to view full image">`).join("")}</div>`;
    }

    if (!this.isError && this.toolName === "edit" && typeof this.args.oldText === "string" && typeof this.args.newText === "string") {
      return renderDiff(this.args.oldText, this.args.newText) + imagesHtml;
    }
    if (!this.isError && this.toolName === "write" && typeof this.args.content === "string") {
      this.rawOutput = this.args.content;
      return `<div class="tool-terminal">${escapeHtml(this.args.content)}</div>${imagesHtml}`;
    }

    return `<div class="tool-terminal">${escapeHtml(textOutput)}</div>${imagesHtml}`;
  }
}

// Global copy handler for tool output
if (typeof window !== "undefined") {
  window.copyToolOutput = function(btn) {
    const card = btn.closest(".tool-card");
    if (!card) return;
    const terminal = card.querySelector(".tool-terminal") || card.querySelector(".diff-container");
    const text = terminal ? terminal.innerText : "";
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.innerText;
      btn.innerText = "✓";
      btn.style.color = "var(--diff-add-text)";
      setTimeout(() => {
        btn.innerText = orig;
        btn.style.color = "";
      }, 1500);
    });
  };
}
