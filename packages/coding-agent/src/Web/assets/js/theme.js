import { escapeHtml, openModal } from "./utils.js";
import { t, currentLocale, onLocaleChanged } from "./i18n.js";

export const THEMES = ["dark", "labra", "light"];
export let currentTheme = localStorage.getItem("pig_theme") || "dark";

export function applyTheme(theme) {
  if (!THEMES.includes(theme)) theme = "dark";
  currentTheme = theme;
  localStorage.setItem("pig_theme", theme);
  if (theme === "dark") {
    document.documentElement.removeAttribute("data-theme");
  } else {
    document.documentElement.setAttribute("data-theme", theme);
  }
  const themeBtn = document.getElementById("theme-btn");
  if (themeBtn) {
    const labelsZh = { dark: "🎨 暗色", labra: "🎨 Labra", light: "🎨 浅色" };
    const labelsEn = { dark: "🎨 Dark", labra: "🎨 Labra", light: "🎨 Light" };
    const map = (typeof currentLocale !== "undefined" && currentLocale === "zh-CN") ? labelsZh : labelsEn;
    themeBtn.textContent = map[theme] || ("🎨 " + theme);
  }
}

/** Theme Switcher Modal matching Language Settings popup design */
export function openThemeModal() {
  const themes = [
    {
      key: "dark",
      title: t("theme_dark"),
      desc: t("theme_dark_desc"),
      swatches: ["#0b0f17", "#1e293b", "#5eead4", "#a798d7"],
    },
    {
      key: "labra",
      title: t("theme_labra"),
      desc: t("theme_labra_desc"),
      swatches: ["#040704", "#151915", "#e33d84", "#89974a"],
    },
    {
      key: "light",
      title: t("theme_light"),
      desc: t("theme_light_desc"),
      swatches: ["#f8fafc", "#ffffff", "#0284c7", "#7459b4"],
    },
  ];

  const renderBody = () => {
    let rows = themes.map(th => `
      <div class="theme-item-card ${currentTheme === th.key ? 'active' : ''}" data-theme="${th.key}">
        <div class="theme-card-left">
          <div class="theme-card-title">${escapeHtml(th.title)}</div>
          <div class="theme-card-desc">${escapeHtml(th.desc)}</div>
        </div>
        <div class="theme-card-right">
          <div class="theme-swatches">
            ${th.swatches.map(c => `<span class="theme-swatch" style="background:${c};"></span>`).join("")}
          </div>
          <span class="theme-check" style="color:#38bdf8; font-weight:700; width:16px; text-align:center;">
            ${currentTheme === th.key ? "✓" : ""}
          </span>
        </div>
      </div>
    `).join("");

    return `
      <div style="font-size:12px; color:var(--text-muted); margin-bottom:12px; font-weight:600;">
        ${escapeHtml(t("theme_select_label"))}
      </div>
      <div style="display:flex; flex-direction:column; gap:8px;">
        ${rows}
      </div>
    `;
  };

  openModal({
    title: t("theme_settings"),
    bodyHtml: renderBody(),
    hideFooter: true,
    width: "480px",
    onOpen: (modalEl, closeFn) => {
      modalEl.querySelectorAll(".theme-item-card").forEach(card => {
        card.addEventListener("click", () => {
          const selected = card.dataset.theme;
          applyTheme(selected);
          modalEl.querySelectorAll(".theme-item-card").forEach(c => {
            const isActive = c.dataset.theme === selected;
            c.classList.toggle("active", isActive);
            const checkEl = c.querySelector(".theme-check");
            if (checkEl) checkEl.textContent = isActive ? "✓" : "";
          });
          setTimeout(closeFn, 180);
        });
      });
    }
  });
}

export function initTheme() {
  const urlParams = new URLSearchParams(window.location.search);
  const queryTheme = urlParams.get("theme");
  if (queryTheme) {
    applyTheme(queryTheme);
  } else {
    applyTheme(currentTheme);
  }
  if (urlParams.get("test_theme_modal")) {
    setTimeout(openThemeModal, 250);
  }

  onLocaleChanged(() => {
    applyTheme(currentTheme);
  });
}
