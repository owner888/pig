/**
 * The extensions' panels (`ExtensionApi::registerWebPanel()`): an entry each in the sidebar's
 * Extensions group, and a window drawn from the blocks the extension's `render` returns — see
 * `WebPanel` for them. Nothing of the extension runs here: it describes, the page draws, in the
 * page's own components and theme.
 *
 * `blocksToHtml()` is the drawing and needs no page, so it can be run under Node.
 */

const escapeText = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
const TONES = new Set(["muted", "error", "warning", "success"]);
const tone = (value) => (TONES.has(value) ? ` tone-${value}` : "");

/** One button. Its action, arguments and question ride on data attributes for `openPanel()`. */
function actionHtml(a) {
  if (!a || typeof a.action !== "string" || a.action === "") return "";
  return `<button class="mini-btn panel-action${a.tone === "danger" ? " danger" : ""}"`
    + ` data-action="${escapeText(a.action)}" data-args="${escapeText(JSON.stringify(a.args ?? {}))}"`
    + (a.confirm ? ` data-confirm="${escapeText(a.confirm)}"` : "")
    + (a.title ? ` title="${escapeText(a.title)}"` : "")
    + `>${escapeText(a.label ?? a.action)}</button>`;
}

const actionsHtml = (actions) => (Array.isArray(actions) ? actions.map(actionHtml).join("") : "");

function blockHtml(b) {
  if (!b || typeof b !== "object") return "";
  switch (b.type) {
    case "heading":
      return `<div class="panel-heading">${escapeText(b.text)}</div>`;
    case "text":
      return `<div class="panel-text${tone(b.tone)}">${escapeText(b.text)}</div>`;
    case "list": {
      const items = Array.isArray(b.items) ? b.items : [];
      if (items.length === 0) return b.empty ? `<div class="panel-text tone-muted">${escapeText(b.empty)}</div>` : "";
      return `<div class="panel-list">${items.map((item) => `
        <div class="panel-item${item.active ? " active" : ""}">
          <span class="panel-item-dot"></span>
          <span class="panel-item-main">
            <span class="panel-item-title">${escapeText(item.title)}</span>
            ${item.detail ? `<span class="panel-item-detail">${escapeText(item.detail)}</span>` : ""}
          </span>
          ${item.meta ? `<span class="panel-item-meta${tone(item.tone)}">${escapeText(item.meta)}</span>` : ""}
          ${actionsHtml(item.actions)}
        </div>`).join("")}</div>`;
    }
    case "meter": {
      const value = Number(b.value);
      const pct = Number.isFinite(value) ? Math.max(0, Math.min(100, Math.round(value * 100))) : 0;
      const level = pct === 0 ? " empty" : pct < 20 ? " low" : "";
      return `<div class="panel-meter">
          <div class="panel-meter-header"><span class="panel-meter-label">${escapeText(b.label)}</span><span class="panel-meter-pct">${pct}%</span></div>
          <div class="panel-meter-bar${level}"><div style="width:${pct}%"></div></div>
          ${b.detail ? `<div class="panel-meter-detail">${escapeText(b.detail)}</div>` : ""}
        </div>`;
    }
    case "card":
      return `<div class="panel-card-group">
          ${b.title ? `<div class="panel-heading">${escapeText(b.title)}</div>` : ""}
          <div class="panel-card">${blocksToHtml(b.blocks)}</div>
        </div>`;
    case "actions":
      return `<div class="panel-actions">${actionsHtml(b.actions)}</div>`;
    case "lazy":
      return typeof b.section === "string" && b.section !== ""
        ? `<div class="panel-lazy" data-section="${escapeText(b.section)}"><div class="panel-text tone-muted">${escapeText(b.placeholder ?? "…")}</div></div>`
        : "";
    default:
      // A block this page does not know: nothing, rather than a broken window.
      return "";
  }
}

/** A panel's blocks as HTML. Every string is text; a block of a kind the page lacks is left out. */
export function blocksToHtml(blocks) {
  return Array.isArray(blocks) ? blocks.map(blockHtml).join("") : "";
}

/** The panel a slash command opens, from `/api/panels`' list — `/accounts` for `commands: ["accounts"]`. */
export function panelForCommand(panels, text) {
  const name = String(text).trim().replace(/^\//, "");
  return panels.find((p) => Array.isArray(p.commands) && p.commands.includes(name)) ?? null;
}

// ---- the page ----------------------------------------------------------------------------

/**
 * Wire the panels into the page. `deps`: `openModal`, `t` and `locale()` from the page, and
 * `onChanged()`, called after any action — an action may change what the conversation can use
 * (an account switched), so the page asks again.
 */
export function createPanels({ openModal, t, locale, onChanged = () => {}, onError = () => {} }) {
  let panels = [];
  const group = document.getElementById("sidebar-panels");
  const list = document.getElementById("sidebar-panels-list");

  async function load() {
    try {
      const res = await fetch(`/api/panels?locale=${encodeURIComponent(locale())}`);
      panels = (await res.json()).panels ?? [];
    } catch {
      panels = [];
    }
    if (list) {
      list.innerHTML = panels.map((p) => `
        <button class="sidebar-nav-item" data-panel="${escapeText(p.id)}" title="${escapeText(p.title)}">
          <span class="sidebar-nav-icon">${escapeText(p.icon || "▣")}</span>
          <span>${escapeText(p.title)}</span>
        </button>`).join("");
      list.querySelectorAll("[data-panel]").forEach((el) => el.addEventListener("click", () => open(el.dataset.panel)));
    }
    if (group) group.hidden = panels.length === 0;
    return panels;
  }

  function open(id) {
    const panel = panels.find((p) => p.id === id);
    if (!panel) return;
    const url = `/api/panels/${encodeURIComponent(id)}`;
    let body = null;

    const draw = (blocks, error) => {
      if (!body) return;
      body.innerHTML = (error ? `<div class="panel-text tone-error">${escapeText(error)}</div>` : "") + blocksToHtml(blocks);
      body.querySelectorAll(".panel-lazy").forEach(fill);
    };

    const fill = async (el) => {
      try {
        const res = await fetch(`${url}?locale=${encodeURIComponent(locale())}&section=${encodeURIComponent(el.dataset.section)}`);
        const d = await res.json();
        if (!el.isConnected) return;
        el.innerHTML = d.ok ? blocksToHtml(d.blocks) : `<div class="panel-text tone-error">${escapeText(d.error)}</div>`;
      } catch {
        if (el.isConnected) el.innerHTML = `<div class="panel-text tone-error">${escapeText(t("panel_unreachable"))}</div>`;
      }
    };

    const refresh = async () => {
      try {
        const d = await (await fetch(`${url}?locale=${encodeURIComponent(locale())}`)).json();
        draw(d.ok ? d.blocks : [], d.ok ? null : d.error);
      } catch {
        draw([], t("panel_unreachable"));
      }
    };

    const run = async (button) => {
      if (button.dataset.confirm && !confirm(button.dataset.confirm)) return;
      let args = {};
      try { args = JSON.parse(button.dataset.args || "{}"); } catch {}
      button.disabled = true;
      try {
        const res = await fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: button.dataset.action, args, locale: locale() }),
        });
        const d = await res.json();
        draw(d.blocks ?? [], d.ok ? null : d.error);
        if (!d.ok && d.error) onError(d.error);
      } catch {
        draw([], t("panel_unreachable"));
      } finally {
        onChanged();
      }
    };

    openModal({
      title: panel.title,
      hideFooter: true,
      width: "520px",
      headerExtraHtml: `<button class="mini-btn panel-refresh" title="${escapeText(t("panel_refresh"))}">${escapeText(t("panel_refresh"))}</button>`,
      bodyHtml: `<div class="panel-body"><div class="panel-text tone-muted">${escapeText(t("loading_session"))}</div></div>`,
      onOpen: (modalEl) => {
        body = modalEl.querySelector(".panel-body");
        modalEl.querySelector(".panel-refresh")?.addEventListener("click", refresh);
        body.addEventListener("click", (e) => {
          const button = e.target.closest(".panel-action");
          if (button && !button.disabled) run(button);
        });
        refresh();
      },
    });
  }

  return {
    load,
    open,
    /** Open the panel `text` is the command of, and say whether there was one. */
    openForCommand(text) {
      const panel = panelForCommand(panels, text);
      if (panel) open(panel.id);
      return panel !== null;
    },
    /** The panels' commands, as the `/` list's rows. */
    commands() {
      return panels.flatMap((p) => (p.commands ?? []).map((name) => ({ name, description: p.title, source: "panel" })));
    },
  };
}
