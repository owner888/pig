import { escapeHtml } from "../utils.js";
import { t } from "../i18n.js";
import { renderDiff, renderDiffFromUnified } from "../markdown.js";

/** Map tool names to icons and theme accent colors matching TUI */
const TOOL_CONFIGS = {
  bash: { icon: "⚡", label: "bash", color: "#38bdf8", defaultOpen: true },
  read: { icon: "📄", label: "read", color: "#34d399", defaultOpen: false },
  edit: { icon: "✏️", label: "edit", color: "#c084fc", defaultOpen: true },
  write: { icon: "💾", label: "write", color: "#fb923c", defaultOpen: true },
  grep: { icon: "🔍", label: "grep", color: "#facc15", defaultOpen: false },
  find: { icon: "🔎", label: "find", color: "#facc15", defaultOpen: false },
  ls: { icon: "📁", label: "ls", color: "#94a3b8", defaultOpen: false },
  web_search: { icon: "🌐", label: "web_search", color: "#60a5fa", defaultOpen: false },
  fetch_web_page: { icon: "🌐", label: "fetch_web_page", color: "#60a5fa", defaultOpen: false },
  browse_web_page: { icon: "🖥️", label: "browse_web_page", color: "#60a5fa", defaultOpen: false },
  computer: { icon: "🖱️", label: "computer", color: "#f43f5e", defaultOpen: true },
  generate_image: { icon: "🎨", label: "generate_image", color: "#ec4899", defaultOpen: true },
};

/**
 * ToolCard Component
 *
 * Implements full TUI-parity tool execution cards with:
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

    const cfg = TOOL_CONFIGS[this.toolName] || { icon: "🔧", label: this.toolName, color: "#64748b", defaultOpen: true };
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
    if (this.toolName === "edit" && this.args.oldText && this.args.newText) {
      bodyHtml = renderDiff(this.args.oldText, this.args.newText);
    } else {
      bodyHtml = `<div class="tool-terminal">${escapeHtml(t("tool_running"))}</div>`;
    }

    this.element.innerHTML = `
      <div class="tool-header" style="border-left-color: ${this.config.color};">
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

    const body = this.element.querySelector(".tool-body");
    if (body) {
      body.innerHTML = this.formatResultBody();
    }
  }

  formatResultBody() {
    const result = this.result;
    if (result === undefined || result === null) {
      return "";
    }

    // 1. If result carries unified diff (edit tool)
    if (result.diff) {
      return renderDiffFromUnified(result.diff);
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

    // Check details for truncation / status
    let detailsFooter = "";
    if (result.details && typeof result.details === "object") {
      const det = result.details;
      const notes = [];
      if (det.cancelled) notes.push("(cancelled)");
      if (det.exitCode !== undefined && det.exitCode !== 0) notes.push(`(exit ${det.exitCode})`);
      if (det.fullOutputPath) notes.push(`Output truncated. Full output: ${det.fullOutputPath}`);
      if (det.notice) notes.push(det.notice);
      if (notes.length > 0) {
        detailsFooter = `<div class="tool-details-footer">${escapeHtml(notes.join("\n"))}</div>`;
      }
    }

    let imagesHtml = "";
    if (images.length > 0) {
      imagesHtml = `<div class="msg-images-grid" style="margin-top:8px;">${images.map(img => `<img src="data:${img.mimeType || 'image/png'};base64,${img.data}" class="msg-image-thumb" onclick="window.openImageLightbox?.(this.src)" title="Click to view full image">`).join("")}</div>`;
    }

    if (this.toolName === "edit" && this.args?.oldText && this.args?.newText && !result.diff) {
      return renderDiff(this.args.oldText, this.args.newText) + detailsFooter + imagesHtml;
    }

    return `<div class="tool-terminal">${escapeHtml(textOutput)}</div>${detailsFooter}${imagesHtml}`;
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
      btn.style.color = "#34d399";
      setTimeout(() => {
        btn.innerText = orig;
        btn.style.color = "";
      }, 1500);
    });
  };
}
