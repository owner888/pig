import { escapeHtml } from "../utils.js";
import { t } from "../i18n.js";

/**
 * EmptyState Component
 *
 * Renders a welcoming hero state for pristine conversations,
 * complete with Piglet mascot, friendly greeting, and quick-action chips.
 */
export class EmptyState {
  constructor(onSelectPrompt) {
    this.onSelectPrompt = onSelectPrompt;
    this.element = document.createElement("div");
    this.element.className = "empty-state-hero";
    this.render();
  }

  render() {
    const chips = [
      { id: "files", icon: "📁", text: t("empty_chip_files"), prompt: "请帮我梳理当前项目的架构全貌、核心模块与关键文件。" },
      { id: "tests", icon: "⚡", text: t("empty_chip_tests"), prompt: "请运行项目的自动化单元测试套件，并报告测试结果。" },
      { id: "git", icon: "📊", text: t("empty_chip_git"), prompt: "检查当前 Git 仓库状态、未提交修改及最近的提交记录。" },
      { id: "doctor", icon: "🩺", text: t("empty_chip_doctor"), prompt: "/antigravity.doctor" },
    ];

    this.element.innerHTML = `
      <div class="empty-state-logo-wrap">
        <svg class="empty-state-pig-logo" viewBox="0 0 48 48" width="64" height="64" fill="none" xmlns="http://www.w3.org/2000/svg">
          <!-- Piglet Head & Body -->
          <circle cx="24" cy="25" r="18" fill="#f472b6" fill-opacity="0.2" stroke="#f472b6" stroke-width="2.5"/>
          <!-- Snout -->
          <ellipse cx="24" cy="26" rx="8" ry="6" fill="#f472b6" fill-opacity="0.5" stroke="#f472b6" stroke-width="2"/>
          <circle cx="21.5" cy="26" r="1.5" fill="#831843"/>
          <circle cx="26.5" cy="26" r="1.5" fill="#831843"/>
          <!-- Eyes -->
          <circle cx="17" cy="18" r="2.2" fill="#fbcfe8"/>
          <circle cx="31" cy="18" r="2.2" fill="#fbcfe8"/>
          <circle cx="17.5" cy="17.5" r="1" fill="#831843"/>
          <circle cx="31.5" cy="17.5" r="1" fill="#831843"/>
          <!-- Ears -->
          <path d="M11 15C10 9 14 7 17 10" stroke="#f472b6" stroke-width="2.5" stroke-linecap="round" fill="#fbcfe8" fill-opacity="0.3"/>
          <path d="M37 15C38 9 34 7 31 10" stroke="#f472b6" stroke-width="2.5" stroke-linecap="round" fill="#fbcfe8" fill-opacity="0.3"/>
        </svg>
      </div>
      <h2 class="empty-state-title">${escapeHtml(t("empty_welcome_title"))}</h2>
      <p class="empty-state-subtitle">${escapeHtml(t("empty_welcome_subtitle"))}</p>
      <div class="empty-state-chips">
        ${chips.map(c => `
          <button class="empty-state-chip" data-prompt="${escapeHtml(c.prompt)}">
            <span class="chip-icon">${c.icon}</span>
            <span class="chip-text">${escapeHtml(c.text)}</span>
          </button>
        `).join("")}
      </div>
    `;

    this.element.querySelectorAll(".empty-state-chip").forEach(btn => {
      btn.addEventListener("click", () => {
        const prompt = btn.getAttribute("data-prompt");
        if (typeof this.onSelectPrompt === "function") {
          this.onSelectPrompt(prompt);
        }
      });
    });
  }

  remove() {
    if (this.element && this.element.parentNode) {
      this.element.remove();
    }
  }
}
