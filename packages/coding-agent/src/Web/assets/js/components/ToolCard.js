import { escapeHtml, copyText } from "../utils.js";
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
  // Its arguments are shown in the confirmation card drawn under it (`scheduleConfirmCard()`).
  schedule: { icon: "🕒", defaultOpen: false },
  codemode: { icon: "🧩", defaultOpen: true },
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

  /** The path argument as the model wrote it — `file_path` too, as upstream reads it. */
  rawPath() {
    const path = this.args.file_path ?? this.args.path;
    return typeof path === "string" ? path : "";
  }

  getDisplayTitle() {
    const args = this.args;
    const limit = (format) => (args.limit === undefined || args.limit === null ? "" : format.replace("%s", String(args.limit)));
    switch (this.toolName) {
      case "bash": {
        const cmd = args.command || "";
        const timeout = Number(args.timeout) > 0 ? ` (timeout ${args.timeout}s)` : "";
        return `$ ${cmd}${timeout}`;
      }
      case "read":
        return compactRead(this.rawPath()) ?? `read ${this.rawPath()}${readRange(args.offset, args.limit)}`;
      case "edit":
        return `edit ${this.rawPath()}`;
      case "write":
        return `write ${this.rawPath()}`;
      // Upstream's titles: grep writes ` limit N`, find and ls ` (limit N)`.
      case "grep":
        return `grep /${args.pattern || ""}/ in ${args.path || "."}${args.glob ? ` (${args.glob})` : ""}${limit(" limit %s")}`;
      case "find":
        return `find ${args.pattern || ""} in ${args.path || "."}${limit(" (limit %s)")}`;
      case "ls":
        return `ls ${args.path || "."}${limit(" (limit %s)")}`;
      case "web_search":
        return `web_search "${args.query || ""}"`;
      case "fetch_web_page":
      case "browse_web_page":
        return `${this.toolName} ${args.url || ""}`;
      case "computer":
        return `computer ${args.action || "action"}${args.coordinate ? ` [${args.coordinate.join(", ")}]` : ""}${args.text ? ` "${args.text}"` : ""}`;
      case "generate_image":
        return `generate_image "${(args.prompt || "").slice(0, 50)}"`;
      // The script is the body, as upstream's codemode row draws it under the title.
      case "codemode":
        return "codemode";
      default:
        return callWithArgs(this.toolName, args);
    }
  }

  renderInitial() {
    const title = this.getDisplayTitle();
    const isExpanded = this.config.defaultOpen;

    let bodyHtml = "";
    if (this.toolName === "edit" && typeof this.args.oldText === "string" && typeof this.args.newText === "string") {
      bodyHtml = renderDiff(this.args.oldText, this.args.newText);
    } else if (this.toolName === "codemode" && typeof this.args.code === "string") {
      bodyHtml = codemodeScript(this.args.code);
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
    if (this.toolName === "codemode") return; // the script stays until the result

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

  /**
   * Upstream's warning lines, which stay visible with the card collapsed: what the tool left
   * out, from its details. The exit code and a cancel are not repeated — the output says them.
   */
  #resultNotes() {
    const det = this.result?.details;
    if (!det || typeof det !== "object") return [];
    const tr = det.truncation && typeof det.truncation === "object" ? det.truncation : null;
    const cut = !!tr?.truncated;
    const size = formatSize(Number(tr?.maxBytes) || 50 * 1024);
    switch (this.toolName) {
      case "bash": {
        const parts = [];
        if (det.fullOutputPath) parts.push(t("full_output", { path: det.fullOutputPath }));
        if (cut) parts.push(tr.truncatedBy === "lines"
          ? t("truncated_showing", { shown: tr.outputLines, total: tr.totalLines })
          : t("truncated_shown", { shown: tr.outputLines, size }));
        return parts.length ? [`[${parts.join(". ")}]`] : [];
      }
      case "read":
        if (!cut) return [];
        if (tr.firstLineExceedsLimit) return [`[${t("first_line_exceeds", { size })}]`];
        return [`[${tr.truncatedBy === "lines"
          ? t("truncated_read_lines", { shown: tr.outputLines, total: tr.totalLines, max: tr.maxLines || 2000 })
          : t("truncated_shown", { shown: tr.outputLines, size })}]`];
      case "ls":
      case "find":
      case "grep": {
        const what = [
          det.entryLimitReached ? t("limit_entries", { count: det.entryLimitReached }) : null,
          det.resultLimitReached ? t("limit_results", { count: det.resultLimitReached }) : null,
          det.matchLimitReached ? t("limit_matches", { count: det.matchLimitReached }) : null,
          cut ? t("limit_size", { size }) : null,
          det.linesTruncated ? t("limit_long_lines") : null,
        ].filter(Boolean);
        return what.length ? [`[${t("truncated_limits", { what: what.join(", ") })}]`] : [];
      }
      default:
        return det.notice ? [det.notice] : [];
    }
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
        if (item?.type === "image" && (item.data || item.url)) images.push(item);
      }
    } else if (result.content && Array.isArray(result.content)) {
      for (const item of result.content) {
        if (item?.type === "text" && item.text) textOutput += item.text;
        if (item?.type === "image" && (item.data || item.url)) images.push(item);
      }
    } else if (result.output) {
      textOutput = typeof result.output === "string" ? result.output : JSON.stringify(result.output, null, 2);
    } else if (result.text) {
      textOutput = result.text;
    } else {
      textOutput = JSON.stringify(result, null, 2);
    }

    // Upstream's: the tool's own `[… Full output: …]` footer goes, since the warning says it.
    const det = result?.details;
    if (this.toolName === "bash" && det?.fullOutputPath && det?.truncation?.truncated) {
      const footer = textOutput.lastIndexOf("\n\n[");
      if (footer !== -1 && textOutput.trimEnd().endsWith("]") && textOutput.slice(footer).includes(det.fullOutputPath)) {
        textOutput = textOutput.slice(0, footer).trimEnd();
      }
    }

    this.rawOutput = textOutput;

    let imagesHtml = "";
    if (images.length > 0) {
      imagesHtml = `<div class="msg-images-grid" style="margin-top:8px;">${images.map(img => `<img src="${escapeHtml(img.url || `data:${img.mimeType || 'image/png'};base64,${img.data}`)}" loading="lazy" class="msg-image-thumb" onclick="window.openImageLightbox?.(this.src)" title="${escapeHtml(t("open_full_image"))}">`).join("")}</div>`;
    }

    if (this.toolName === "codemode") {
      this.rawOutput = textOutput;
      return codemodeResult(this.args.code, result, this.isError) + imagesHtml;
    }
    if (!this.isError && this.toolName === "edit" && typeof this.args.oldText === "string" && typeof this.args.newText === "string") {
      return renderDiff(this.args.oldText, this.args.newText) + imagesHtml;
    }
    // Upstream's write: the content without `\r` or trailing blank lines; a failure under it in red.
    if (this.toolName === "write") {
      const content = this.args.content ?? "";
      const shown = typeof content === "string"
        ? `<div class="tool-terminal">${escapeHtml(content.replace(/\r/g, "").replace(/\n+$/, ""))}</div>`
        : `<div class="tool-terminal tool-error-text">${escapeHtml(t("invalid_content_arg"))}</div>`;
      this.rawOutput = typeof content === "string" ? content : "";
      const failure = this.isError && textOutput ? `<div class="tool-terminal tool-error-text">${escapeHtml(textOutput)}</div>` : "";
      return shown + failure + imagesHtml;
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
    // textContent, not innerText: a collapsed card's body is not rendered, and what innerText
    // answers for that differs between browsers.
    const text = terminal ? terminal.textContent : "";
    if (!text) return;
    const show = (mark, color) => {
      btn.innerText = mark;
      btn.style.color = color;
      setTimeout(() => {
        btn.innerText = "📋";
        btn.style.color = "";
      }, 1500);
    };
    copyText(text).then(
      () => show("✓", "var(--color-tool-diff-added)"),
      (error) => {
        console.error("Copy failed:", error);
        show("✗", "var(--color-error)");
      },
    );
  };
}

/** Upstream's `COMPACT_RESOURCE_FILE_NAMES`. */
const COMPACT_RESOURCE_FILES = ["AGENTS.override.md", "AGENTS.md", "AGENTS.MD", "CLAUDE.md", "CLAUDE.MD"];

/** Upstream's `formatCompactReadCall()`: a skill or an AGENTS/CLAUDE file as one label, or null. */
function compactRead(path) {
  if (!path) return null;
  const parts = path.split(/[\\/]/).filter(Boolean);
  const file = parts.at(-1) || "";
  if (file === "SKILL.md") return `[skill] ${parts.at(-2) || file}`;
  if (COMPACT_RESOURCE_FILES.includes(file)) return `read resource ${path}`;
  return null;
}

/** Upstream's `formatReadLineRange()`: `:start`, `:start-end`, or nothing. */
function readRange(offset, limit) {
  if (offset === undefined && limit === undefined) return "";
  const start = offset === undefined ? 1 : Number(offset);
  if (!Number.isFinite(start)) return "";
  if (limit === undefined) return `:${start}`;
  if (!Number.isFinite(Number(limit))) return "";
  const end = start + Number(limit) - 1;
  return `:${start}${end !== 0 ? `-${end}` : ""}`;
}

/** Upstream's `formatToolCallWithArgs()`, collapsed: `name key=value …`, cut to 100 characters. */
function callWithArgs(name, args) {
  const entries = Object.entries(args || {});
  if (entries.length === 0) return name;
  const pairs = entries.map(([key, value]) => `${key}=${JSON.stringify(value)}`).join(" ");
  return `${name} ${pairs.length > 100 ? `${pairs.slice(0, 97)}...` : pairs}`;
}

/** `Truncate::size()`. */
function formatSize(bytes) {
  if (bytes < 1024) return `${bytes}B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)}KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)}MB`;
}

function codemodeScript(code) {
  const text = String(code).replace(/\r/g, "").replace(/\t/g, "    ").trimEnd();
  return text ? `<div class="tool-terminal">${escapeHtml(text)}</div>` : "";
}

/** Cents for larger amounts, two significant digits for fractions of a cent — upstream's `formatCost()`. */
function formatCost(cost) {
  return `$${cost >= 0.01 ? cost.toFixed(2) : cost.toPrecision(2)}`;
}

/**
 * Upstream's codemode result: the script, the nested calls with their status, time and cost (and
 * the total when more than one was priced), then the output without the `Script completed` header,
 * red when the run failed.
 */
function codemodeResult(code, result, isError) {
  const parts = [codemodeScript(code ?? "")];
  const calls = Array.isArray(result?.details?.calls) ? result.details.calls : [];
  if (calls.length > 0) {
    const icon = { running: "…", ok: "✓", error: "✗" };
    const lines = calls.map((call) => {
      const duration = call.durationMs === undefined ? "" : call.durationMs < 1000 ? ` ${Math.round(call.durationMs)}ms` : ` ${(call.durationMs / 1000).toFixed(1)}s`;
      const cost = call.cost ? ` ${formatCost(call.cost)}` : "";
      const error = call.error ? `\n    ${String(call.error).split("\n").join("\n    ")}` : "";
      return `${icon[call.status] ?? "⊘"} ${call.name}${call.args ? ` ${call.args}` : ""}${duration}${cost}${error}`;
    });
    const priced = calls.filter((call) => call.cost);
    if (priced.length > 1) lines.push(`Model calls: ${formatCost(priced.reduce((sum, call) => sum + call.cost, 0))}`);
    parts.push(`<div class="tool-terminal">${escapeHtml(lines.join("\n"))}</div>`);
  }
  const blocks = Array.isArray(result?.content) ? result.content : [];
  const header = /^Script (completed|failed)\nWall time [\d.]+ seconds\nOutput:\n$/;
  const output = blocks
    .filter((block, index) => !(index === 0 && block?.type === "text" && header.test(block.text || "")))
    .map((block) => (block?.type === "text" ? block.text || "" : "[image]"))
    .join("\n")
    .trim();
  if (output) parts.push(`<div class="tool-terminal${isError ? " tool-error-text" : ""}">${escapeHtml(output.replace(/\t/g, "    "))}</div>`);
  return parts.join("");
}
