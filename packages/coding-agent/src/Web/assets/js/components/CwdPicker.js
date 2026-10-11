import { escapeHtml, openModal } from "../utils.js";
import { t } from "../i18n.js";
import { splitTyped, matching, joinPath, folderName } from "../cwd.js";

export { folderName };

/**
 * Where a new conversation starts: the terminal's `pig` starts in the directory it was run from,
 * and the page lets the person choose one before the first message — the folders conversations
 * were held in (`/api/folders`), a path typed with its subdirectories completed, or a walk through
 * the tree (`/api/dirs`). The path handling is `cwd.js`, which needs no page.
 */

async function listDir(path) {
  try {
    const res = await fetch(`/api/dirs?path=${encodeURIComponent(path)}`);
    return await res.json();
  } catch {
    return { ok: false, error: t("panel_unreachable") };
  }
}

/**
 * Ask for a directory, starting at `current`; `onPick(path)` gets the real path of the one chosen.
 * Clicking a folder walks into it; Enter or the button starts there.
 */
export function openCwdPicker({ current, onPick }) {
  let shown = null; // the listing on screen: `/api/dirs`' answer for the folder being walked
  let typedPrefix = "";
  let use = async () => {}; // start in what is typed — set once the field exists

  openModal({
    title: t("cwd_pick_title"),
    width: "min(560px, 94vw)",
    confirmText: t("cwd_use"),
    cancelText: t("cancel"),
    // The button and Enter: the picker closes itself once the folder is known to be one.
    onConfirm: async () => { await use(); return false; },
    bodyHtml: `
      <div class="cwd-picker">
        <input class="cwd-path" spellcheck="false" autocomplete="off" placeholder="${escapeHtml(t("cwd_path_placeholder"))}" value="${escapeHtml(current || "")}">
        <div class="cwd-error" hidden></div>
        <div class="cwd-browse"></div>
        <div class="modal-section">${escapeHtml(t("cwd_recent"))}</div>
        <div class="cwd-recent"><div class="panel-text tone-muted">…</div></div>
      </div>`,
    onOpen: async (modal, close) => {
      const input = modal.querySelector(".cwd-path");
      const browse = modal.querySelector(".cwd-browse");
      const error = modal.querySelector(".cwd-error");
      const recent = modal.querySelector(".cwd-recent");

      const say = (text) => { error.hidden = !text; error.textContent = text || ""; };
      const pick = (path) => { close(); onPick(path); };

      const draw = () => {
        if (!shown?.ok) { browse.innerHTML = ""; return; }
        const names = matching(shown.dirs, typedPrefix);
        browse.innerHTML = `<div class="cwd-here">${escapeHtml(shown.path)}</div>`
          + (shown.parent ? `<div class="cwd-row" data-go="${escapeHtml(shown.parent)}">↰ ..</div>` : "")
          + names.map((d) => `<div class="cwd-row" data-go="${escapeHtml(joinPath(shown.path, d))}">📁 ${escapeHtml(d)}</div>`).join("")
          + (shown.truncated ? `<div class="panel-text tone-muted">…</div>` : "");
      };

      // Walk into a folder: list it, and put its path in the field.
      const go = async (path) => {
        const d = await listDir(path);
        if (!d.ok) { say(d.error); return; }
        say("");
        shown = d;
        typedPrefix = "";
        input.value = d.path;
        draw();
      };

      // Typing narrows the listing of the folder typed so far to the names that fit.
      let timer = null;
      input.addEventListener("input", () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
          const { dir, prefix } = splitTyped(input.value);
          const d = await listDir(dir === "" ? "." : dir);
          if (!d.ok) return;
          shown = d;
          typedPrefix = prefix;
          say("");
          draw();
        }, 150);
      });

      browse.addEventListener("click", (e) => {
        const row = e.target.closest("[data-go]");
        if (row) go(row.dataset.go);
      });

      // Enter, or the button: start in what is typed, once it is known to be a folder.
      use = async () => {
        const d = await listDir(input.value.trim() || current || "");
        if (d.ok) pick(d.path); else say(d.error);
      };
      input.addEventListener("keydown", (e) => {
        if (e.key !== "Enter" || e.isComposing || e.keyCode === 229) return;
        e.preventDefault();
        e.stopPropagation();
        use();
      });

      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
      go(current || "");

      try {
        const folders = await (await fetch("/api/folders")).json();
        const list = (folders.workspaces || []).filter((w) => w.path);
        recent.innerHTML = list.length === 0
          ? `<div class="panel-text tone-muted">${escapeHtml(t("cwd_no_recent"))}</div>`
          : list.map((w) => `
            <div class="cwd-row cwd-recent-row" data-pick="${escapeHtml(w.path)}" title="${escapeHtml(w.path)}">
              <span>📁 ${escapeHtml(w.name || folderName(w.path))}</span>
              <span class="cwd-recent-path">${escapeHtml(w.path)}</span>
              <span class="cwd-recent-count">${escapeHtml(t("cwd_sessions", { n: w.sessionCount ?? 0 }))}</span>
            </div>`).join("");
        recent.addEventListener("click", (e) => {
          const row = e.target.closest("[data-pick]");
          if (row) pick(row.dataset.pick);
        });
      } catch {
        recent.innerHTML = "";
      }
    },
  });
}
