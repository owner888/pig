/**
 * The extensions' panels (`ExtensionApi::registerWebPanel()`): an entry each in the sidebar's
 * Extensions group or in the header's ⋯ menu — the extension's `placement`, unless the person moved
 * it (`placementOf()`, kept in this browser) — and a window drawn from the blocks the extension's
 * `render` returns — see `WebPanel` for them. A panel is moved to the other place from its own
 * window (the button beside Refresh); the ⋯ menu's separate Extensions list that did only that
 * went when the Packages panel came, two 🧩 entries for different things being one too many.
 * Nothing of the extension runs here: it describes, the page draws, in the page's own components
 * and theme.
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
    case "tabs": {
      // The first tab shown; `open()` switches them and keeps the choice across a redraw.
      const tabs = Array.isArray(b.tabs) ? b.tabs.filter((tab) => tab && typeof tab === "object") : [];
      if (tabs.length === 0) return "";
      return `<div class="panel-tabs">
          <div class="panel-tabs-bar" role="tablist">${tabs.map((tab, i) => `<button class="panel-tab${i === 0 ? " active" : ""}" role="tab" data-tab="${i}">${escapeText(tab.label)}</button>`).join("")}</div>
          ${tabs.map((tab, i) => `<div class="panel-tab-pane" data-tab="${i}"${i === 0 ? "" : " hidden"}>${blocksToHtml(tab.blocks)}</div>`).join("")}
        </div>`;
    }
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

const PLACEMENT_KEY = "pig_panel_placement";
const PLACES = new Set(["sidebar", "header"]);

/**
 * Where a panel goes: the person's choice for it, else the extension's `placement`, else the
 * sidebar. `overrides` is `{id: "sidebar"|"header"}`.
 */
export function placementOf(panel, overrides) {
  const chosen = overrides?.[panel.id];
  if (PLACES.has(chosen)) return chosen;
  return PLACES.has(panel.placement) ? panel.placement : "sidebar";
}

/** The person's moves, from this browser; none when storage is unavailable or holds nonsense. */
function readOverrides() {
  try {
    const value = JSON.parse(localStorage.getItem(PLACEMENT_KEY) || "{}");
    return value && typeof value === "object" && !Array.isArray(value) ? value : {};
  } catch {
    return {};
  }
}

function writeOverrides(overrides) {
  try { localStorage.setItem(PLACEMENT_KEY, JSON.stringify(overrides)); } catch {}
}

// ---- the page ----------------------------------------------------------------------------

/**
 * Wire the panels into the page. `deps`: `openModal`, `t` and `locale()` from the page; `act(panel,
 * action, args, locale, onNotice)`, which runs an action and resolves to `{ok, error?, blocks}` —
 * over the WebSocket, so the action can ask the person (`app.js` draws its questions) — and calls
 * `onNotice(request)` for each `notify()` it sends on the way; and `onChanged()`, called after any
 * action — an action may change what the conversation can use (an account switched), so the page
 * asks again.
 */
import { apiFetch } from "./utils.js";

export function createPanels({ openModal, t, locale, act, onChanged = () => {} }) {
  let panels = [];
  let overrides = readOverrides();
  const group = document.getElementById("sidebar-panels");
  const list = document.getElementById("sidebar-panels-list");
  const header = document.getElementById("header-panels");

  async function load() {
    try {
      const res = await apiFetch(`/api/panels?locale=${encodeURIComponent(locale())}`);
      panels = (await res.json()).panels ?? [];
    } catch {
      panels = [];
    }
    draw();
    return panels;
  }

  /** Each panel in its place: the sidebar's Extensions group, or an entry in the header's ⋯ menu. */
  function draw() {
    const at = (where) => panels.filter((p) => placementOf(p, overrides) === where);
    const icon = (p) => escapeText(p.icon || "▣");
    if (list) {
      list.innerHTML = at("sidebar").map((p) => `
        <button class="sidebar-nav-item" data-panel="${escapeText(p.id)}" title="${escapeText(p.title)}">
          <span class="sidebar-nav-icon">${icon(p)}</span>
          <span>${escapeText(p.title)}</span>
        </button>`).join("");
    }
    if (group) group.hidden = at("sidebar").length === 0;
    if (header) {
      header.innerHTML = at("header").map((p) => `
        <div class="more-dropdown-item" data-panel="${escapeText(p.id)}" title="${escapeText(p.title)}">
          <span class="more-item-icon">${icon(p)}</span>
          <span class="more-item-text">${escapeText(p.label || p.title)}</span>
        </div>`).join("");
    }
    for (const el of [list, header]) {
      el?.querySelectorAll("[data-panel]").forEach((b) => b.addEventListener("click", () => open(b.dataset.panel)));
    }
  }

  /** Put a panel in the header or the sidebar, for this browser. */
  function move(id, where) {
    overrides = { ...overrides, [id]: where };
    writeOverrides(overrides);
    draw();
  }

  function open(id) {
    const panel = panels.find((p) => p.id === id);
    if (!panel) return;
    const url = `/api/panels/${encodeURIComponent(id)}`;
    let body = null;

    // What the last action said with `notify()`, kept above the panel until the next one.
    let notices = [];
    // The tab chosen in each `tabs` block, by its place in the panel, so a redraw after an action
    // (a package installed) stays on the tab the person was looking at.
    let chosenTabs = [];

    // A tab's pane shown and its lazy sections asked for — only now: a tab nobody opens costs
    // nothing (the Packages panel's market is a GitHub search).
    const showTab = (group, index) => {
      group.querySelectorAll(":scope > .panel-tabs-bar > .panel-tab").forEach((tab) => tab.classList.toggle("active", tab.dataset.tab === String(index)));
      group.querySelectorAll(":scope > .panel-tab-pane").forEach((pane) => {
        pane.hidden = pane.dataset.tab !== String(index);
        if (!pane.hidden) pane.querySelectorAll(".panel-lazy:not([data-filled])").forEach(fill);
      });
    };
    const noticeHtml = (n) => `<div class="panel-text${tone(n.notifyType)}">${escapeText(n.message)}</div>`;

    const draw = (blocks, error) => {
      if (!body) return;
      body.innerHTML = notices.map(noticeHtml).join("")
        + (error ? `<div class="panel-text tone-error">${escapeText(error)}</div>` : "") + blocksToHtml(blocks);
      body.querySelectorAll(".panel-tabs").forEach((group, i) => {
        if (chosenTabs[i] !== undefined && group.querySelector(`:scope > .panel-tabs-bar > [data-tab="${chosenTabs[i]}"]`)) showTab(group, chosenTabs[i]);
      });
      body.querySelectorAll(".panel-lazy").forEach((el) => { if (!el.closest("[hidden]") && !el.dataset.filled) fill(el); });
    };

    const fill = async (el) => {
      el.dataset.filled = "1";
      try {
        const res = await apiFetch(`${url}?locale=${encodeURIComponent(locale())}&section=${encodeURIComponent(el.dataset.section)}`);
        const d = await res.json();
        if (!el.isConnected) return;
        el.innerHTML = d.ok ? blocksToHtml(d.blocks) : `<div class="panel-text tone-error">${escapeText(d.error)}</div>`;
      } catch {
        if (el.isConnected) el.innerHTML = `<div class="panel-text tone-error">${escapeText(t("panel_unreachable"))}</div>`;
      }
    };

    const refresh = async () => {
      notices = [];
      try {
        const d = await (await apiFetch(`${url}?locale=${encodeURIComponent(locale())}`)).json();
        draw(d.ok ? d.blocks : [], d.ok ? null : d.error);
      } catch {
        draw([], t("panel_unreachable"));
      }
    };

    const run = (button) => {
      if (!button.dataset.confirm) return send(button);
      // The page's own dialog over the panel, not the browser's.
      openModal({
        title: panel.title,
        bodyHtml: `<div class="panel-text">${escapeText(button.dataset.confirm)}</div>`,
        confirmText: t("confirm"),
        cancelText: t("cancel"),
        isDanger: button.classList.contains("danger"),
        onConfirm: async () => { send(button); return true; },
      });
    };

    const send = async (button) => {
      let args = {};
      try { args = JSON.parse(button.dataset.args || "{}"); } catch {}
      button.disabled = true;
      notices = [];
      try {
        const d = await act(id, button.dataset.action, args, locale(), (notice) => {
          notices.push(notice);
          body?.insertAdjacentHTML("afterbegin", noticeHtml(notice));
        });
        if (d.blocks) draw(d.blocks, d.ok ? null : d.error);
        else draw([], d.error ?? t("panel_unreachable"));
      } finally {
        button.disabled = false;
        onChanged();
      }
    };

    openModal({
      title: panel.title,
      hideFooter: true,
      width: "520px",
      headerExtraHtml: `<button class="mini-btn panel-move"></button><button class="mini-btn panel-refresh" title="${escapeText(t("panel_refresh"))}">${escapeText(t("panel_refresh"))}</button>`,
      bodyHtml: `<div class="panel-body"><div class="panel-text tone-muted">${escapeText(t("loading_session"))}</div></div>`,
      onOpen: (modalEl) => {
        body = modalEl.querySelector(".panel-body");
        modalEl.querySelector(".panel-refresh")?.addEventListener("click", refresh);
        // To the other place, for this browser: the ⋯ menu or the sidebar.
        const mover = modalEl.querySelector(".panel-move");
        const label = () => {
          if (mover) mover.textContent = t(placementOf(panel, overrides) === "header" ? "panel_move_sidebar" : "panel_move_header");
        };
        label();
        mover?.addEventListener("click", () => {
          move(id, placementOf(panel, overrides) === "header" ? "sidebar" : "header");
          label();
        });
        body.addEventListener("click", (e) => {
          const tab = e.target.closest(".panel-tab");
          if (tab) {
            const group = tab.closest(".panel-tabs");
            chosenTabs[[...body.querySelectorAll(".panel-tabs")].indexOf(group)] = Number(tab.dataset.tab);
            showTab(group, tab.dataset.tab);
            return;
          }
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
