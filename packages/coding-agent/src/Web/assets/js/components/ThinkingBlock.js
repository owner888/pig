import { escapeHtml } from "../utils.js";
import { t } from "../i18n.js";
import { renderMarkdown } from "../markdown.js";

/**
 * ThinkingBlock Component
 *
 * Encapsulates the `<thinking>` streaming block with:
 * - Live pulsing indicator during thought generation
 * - Dynamic elapsed seconds & word/character count telemetry
 * - Auto-collapse on thought completion to keep main message clear
 * - Expandable/collapsible interactive summary
 */
export class ThinkingBlock {
  constructor(initialText = "") {
    this.text = initialText || "";
    this.startTime = Date.now();
    this.endTime = null;
    this.timer = null;
    this.isFinished = false;

    this.element = document.createElement("details");
    this.element.className = "thinking-box";
    this.element.open = true;

    this.element.innerHTML = `
      <summary class="thinking-summary">
        <div class="thinking-summary-left">
          <span class="thinking-indicator-dot pulsing"></span>
          <span class="thinking-label">${escapeHtml(t("thinking"))}</span>
          <span class="thinking-meta">0.1s</span>
        </div>
        <span class="thinking-chevron">▾</span>
      </summary>
      <div class="thinking-content prose"></div>
    `;

    this.labelEl = this.element.querySelector(".thinking-label");
    this.metaEl = this.element.querySelector(".thinking-meta");
    this.dotEl = this.element.querySelector(".thinking-indicator-dot");
    this.contentEl = this.element.querySelector(".thinking-content");

    if (this.text) {
      this.contentEl.innerText = this.text;
      this.updateMeta();
    }

    this.startTimer();
  }

  startTimer() {
    this.timer = setInterval(() => {
      if (this.isFinished) return;
      this.updateMeta();
    }, 200);
  }

  updateMeta() {
    const elapsed = ((this.endTime ? this.endTime : Date.now()) - this.startTime) / 1000;
    const durStr = elapsed < 60 ? `${elapsed.toFixed(1)}s` : `${Math.floor(elapsed / 60)}m ${(elapsed % 60).toFixed(0)}s`;
    
    // Character / word count estimation
    const charCount = this.text.length;
    let countStr = "";
    if (charCount > 0) {
      countStr = ` · ${charCount > 1000 ? (charCount / 1000).toFixed(1) + "k" : charCount} ${t("words_count", { count: "" }).trim()}`;
    }

    if (this.metaEl) {
      this.metaEl.textContent = `${durStr}${countStr}`;
    }
  }

  appendDelta(delta) {
    if (!delta) return;
    this.text += delta;
    if (this.contentEl) {
      this.contentEl.innerText = this.text;
    }
    this.updateMeta();
  }

  finish() {
    if (this.isFinished) return;
    this.isFinished = true;
    this.endTime = Date.now();
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }

    if (this.dotEl) {
      this.dotEl.classList.remove("pulsing");
      this.dotEl.classList.add("done");
    }

    if (this.labelEl) {
      this.labelEl.textContent = t("thinking_done");
    }

    this.updateMeta();

    // Auto-collapse when finished so the assistant text is front and center
    // User can click anytime to re-expand and read the full thought chain
    if (this.text.length > 50) {
      this.element.open = false;
    }
  }
}
