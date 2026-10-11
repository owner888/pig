import { escapeHtml, openModal } from "./utils.js";
import { t, onLocaleChanged } from "./i18n.js";

/**
 * The themes are the TUI's (src/Theme/*.json): the server writes their colours into /theme.css as
 * --color-* tokens, one :root[data-theme="<name>"] block each, and lists them at /api/themes.
 */
let themeList = { default: null, themes: [] };
export let currentTheme = localStorage.getItem("pig_theme");

/** A built-in theme's translated name, or the theme's own name for one i18n doesn't know. */
function themeText(name, suffix = "") {
  const key = `theme_${name}${suffix}`;
  const text = t(key);
  return text === key ? (suffix ? "" : name) : text;
}

export function applyTheme(theme) {
  const known = themeList.themes.some(th => th.name === theme);
  if (themeList.themes.length > 0 && !known) theme = themeList.default;
  if (!theme) return;
  currentTheme = theme;
  localStorage.setItem("pig_theme", theme);
  document.documentElement.setAttribute("data-theme", theme);
  const themeBtn = document.getElementById("theme-btn");
  if (themeBtn) themeBtn.textContent = "🎨 " + theme;
}

/** The terminal palette (xterm.js) from the active theme's tokens. */
export function terminalTheme() {
  const css = getComputedStyle(document.documentElement);
  const c = (name) => css.getPropertyValue(`--color-${name}`).trim();
  const palette = {
    black: c("surface"),
    red: c("error"),
    green: c("success"),
    yellow: c("warning"),
    blue: c("info"),
    magenta: c("accent"),
    cyan: c("syntax-variable"),
    white: c("text-primary"),
  };
  const bright = {};
  for (const [name, value] of Object.entries(palette)) {
    bright["bright" + name[0].toUpperCase() + name.slice(1)] = value;
  }
  return {
    background: c("background"),
    foreground: c("text-primary"),
    cursor: c("primary"),
    cursorAccent: c("background"),
    selectionBackground: c("selected-bg"),
    ...palette,
    ...bright,
    brightBlack: c("dim"),
  };
}

/** Theme Switcher Modal matching Language Settings popup design */
export function openThemeModal() {
  const renderBody = () => {
    let rows = themeList.themes.map(th => `
      <div class="theme-item-card ${currentTheme === th.name ? 'active' : ''}" data-theme-name="${escapeHtml(th.name)}">
        <div class="theme-card-left">
          <div class="theme-card-title">${escapeHtml(themeText(th.name))}</div>
          <div class="theme-card-desc">${escapeHtml(themeText(th.name, "_desc"))}</div>
        </div>
        <div class="theme-card-right">
          <div class="theme-swatches">
            ${th.swatches.map(c => `<span class="theme-swatch" style="background:${escapeHtml(c)};"></span>`).join("")}
          </div>
          <span class="theme-check" style="color:var(--color-primary); font-weight:700; width:16px; text-align:center;">
            ${currentTheme === th.name ? "✓" : ""}
          </span>
        </div>
      </div>
    `).join("");

    return `
      <div style="font-size:12px; color:var(--color-text-secondary); margin-bottom:12px; font-weight:600;">
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
          const selected = card.dataset.themeName;
          applyTheme(selected);
          modalEl.querySelectorAll(".theme-item-card").forEach(c => {
            const isActive = c.dataset.themeName === selected;
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

export async function initTheme() {
  const urlParams = new URLSearchParams(window.location.search);
  const wanted = urlParams.get("theme") || currentTheme;
  // Before the list arrives: a stored name is applied as it is, so the page does not flash the default.
  if (wanted) document.documentElement.setAttribute("data-theme", wanted);

  const res = await fetch("/api/themes");
  if (!res.ok) throw new Error(`GET /api/themes failed: ${res.status}`);
  themeList = await res.json();
  applyTheme(wanted);

  if (urlParams.get("test_theme_modal")) {
    setTimeout(openThemeModal, 250);
  }

  onLocaleChanged(() => {
    applyTheme(currentTheme);
  });
}
