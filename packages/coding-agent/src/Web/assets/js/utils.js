/**
 * General purpose utilities for pig web UI.
 */

export function escapeHtml(str) {
  if (str === null || str === undefined) return "";
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

export function cleanSessionTitle(raw) {
  if (!raw) return "";
  const cleaned = String(raw).replace(/\s*•\s*⚡.*$/u, "").trim();
  if (cleaned.startsWith("⚡")) return "";
  return cleaned;
}

export function formatSessionFilename(name, fallback = "(in-memory)") {
  if (!name) return fallback;
  const raw = name.split("/").pop();
  if (raw.length <= 26) return raw;
  const dot = raw.lastIndexOf(".");
  const ext = dot !== -1 ? raw.slice(dot) : "";
  const base = dot !== -1 ? raw.slice(0, dot) : raw;
  const head = base.slice(0, 16);
  const tail = base.slice(-4);
  return `${head}…${tail}${ext}`;
}

/**
 * Unified, reusable Modal Dialog system matching the Accounts popup design.
 * Perfect for mobile touch, centering, keyboard Esc/Enter and backdrop closing.
 */
export function openModal({
  title = "",
  bodyHtml = "",
  confirmText = "OK",
  cancelText = "Cancel",
  isDanger = false,
  hideFooter = false,
  headerExtraHtml = "",
  width = "460px",
  onOpen = (modalEl, closeFn) => {},
  onConfirm = async (modalEl, closeFn) => true,
  onCancel = () => {},
}) {
  const backdrop = document.createElement("div");
  backdrop.className = "modal-backdrop";

  const modal = document.createElement("div");
  modal.className = "modal";
  if (width) modal.style.width = width;

  modal.innerHTML = `
    <div class="modal-header">
      <span>${escapeHtml(title)}</span>
      <div style="display:flex; align-items:center; gap:10px;">
        ${headerExtraHtml}
        <span class="modal-close">×</span>
      </div>
    </div>
    <div class="modal-body">${bodyHtml}</div>
    ${hideFooter ? "" : `
      <div class="modal-footer">
        <button class="modal-btn modal-cancel-btn">${escapeHtml(cancelText)}</button>
        <button class="modal-btn ${isDanger ? "danger" : "primary"} modal-confirm-btn">${escapeHtml(confirmText)}</button>
      </div>
    `}
  `;

  backdrop.appendChild(modal);
  document.body.appendChild(backdrop);

  let closed = false;
  const close = () => {
    if (closed) return;
    closed = true;
    window.removeEventListener("keydown", onKeyDown);
    backdrop.remove();
  };

  const cancel = () => {
    onCancel();
    close();
  };

  const confirm = async () => {
    const confirmBtn = modal.querySelector(".modal-confirm-btn");
    if (confirmBtn) {
      confirmBtn.disabled = true;
      confirmBtn.style.opacity = "0.7";
    }
    try {
      const ok = await onConfirm(modal, close);
      if (ok !== false) {
        close();
      }
    } finally {
      if (confirmBtn && !closed) {
        confirmBtn.disabled = false;
        confirmBtn.style.opacity = "";
      }
    }
  };

  const onKeyDown = (e) => {
    if (e.key === "Escape") {
      e.preventDefault();
      cancel();
    } else if (e.key === "Enter" && !hideFooter) {
      if (e.target?.tagName === "TEXTAREA" || e.isComposing || e.keyCode === 229) {
        return;
      }
      e.preventDefault();
      confirm();
    }
  };
  window.addEventListener("keydown", onKeyDown);

  backdrop.addEventListener("click", (e) => {
    if (e.target === backdrop) cancel();
  });
  modal.querySelector(".modal-close").addEventListener("click", cancel);
  if (!hideFooter) {
    modal.querySelector(".modal-cancel-btn").addEventListener("click", cancel);
    modal.querySelector(".modal-confirm-btn").addEventListener("click", confirm);
  }

  onOpen(modal, close);

  return { modal, backdrop, close };
}
