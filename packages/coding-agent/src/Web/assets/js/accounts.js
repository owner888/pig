import { escapeHtml } from "./utils.js";
import { t, __t } from "./i18n.js";

let accountsModal = null;
let onStateNeedsRefresh = () => {};

export function setAccountsStateHook(fn) {
  onStateNeedsRefresh = fn;
}

export function closeAccounts() {
  if (accountsModal) {
    accountsModal.remove();
    accountsModal = null;
  }
}

export async function openAccounts() {
  closeAccounts();
  accountsModal = document.createElement("div");
  accountsModal.className = "modal-backdrop";
  accountsModal.addEventListener("click", (e) => {
    if (e.target === accountsModal) closeAccounts();
  });
  accountsModal.innerHTML = `
    <div class="modal">
      <div class="modal-header">
        <span>${escapeHtml(t("accounts_title"))}</span>
        <span style="display:flex; gap:8px; align-items:center;">
          <button class="mini-btn" id="accounts-rotate" title="${escapeHtml(t("accounts_rotate_title"))}">${escapeHtml(t("accounts_rotate"))}</button>
          <button class="mini-btn" id="accounts-refresh" title="${escapeHtml(t("accounts_refresh_title"))}">${escapeHtml(t("accounts_refresh"))}</button>
          <span class="modal-close" id="accounts-close">×</span>
        </span>
      </div>
      <div class="modal-body">
        <div id="accounts-list"><span style="color:var(--text-dim)">${escapeHtml(t("loading_session"))}</span></div>
        <div class="modal-section">${escapeHtml(t("quota_active"))}</div>
        <div id="accounts-usage"><span style="color:var(--text-dim)">...</span></div>
      </div>
    </div>`;
  document.body.appendChild(accountsModal);
  document.getElementById("accounts-close").addEventListener("click", closeAccounts);
  document.getElementById("accounts-refresh").addEventListener("click", () => {
    loadAccounts();
    loadUsage();
  });
  document.getElementById("accounts-rotate").addEventListener("click", () => accountAction("/api/accounts/rotate", {}));
  await loadAccounts();
  loadUsage();
}

export function expiryLabel(ms) {
  if (!ms) return "";
  const left = ms - Date.now();
  if (left <= 0) return t("token_expired");
  const m = Math.round(left / 60000);
  return m < 60 ? t("token_left_m", { m }) : t("token_left_h", { h: Math.round(m / 60) });
}

export function renderAccounts(d) {
  const list = document.getElementById("accounts-list");
  if (!list) return;
  list.innerHTML = "";
  if (!d.ok) {
    list.innerHTML = `<span style="color:var(--error)">${escapeHtml(d.error || "Could not read accounts")}</span>`;
    return;
  }
  if (!d.accounts || d.accounts.length === 0) {
    list.innerHTML = `<span style="color:var(--text-dim)">${t("no_accounts")}</span>`;
    return;
  }
  for (const a of d.accounts) {
    const row = document.createElement("div");
    row.className = "account-row" + (a.active ? " active" : "");
    const expired = a.expires && a.expires <= Date.now();
    row.innerHTML = `
      <span class="account-dot"></span>
      <span class="account-email" title="${escapeHtml(a.id)}">${escapeHtml(a.email)}</span>
      <span class="account-meta ${expired ? "expired" : ""}">${escapeHtml(expiryLabel(a.expires))}</span>
      ${a.active ? `<span class="account-meta">${escapeHtml(t("active"))}</span>` : `<button class="mini-btn act-use">${escapeHtml(t("use"))}</button>`}
      <button class="mini-btn danger act-remove" title="${escapeHtml(t("remove_title"))}">${escapeHtml(t("remove"))}</button>`;
    const use = row.querySelector(".act-use");
    if (use) use.addEventListener("click", () => accountAction("/api/accounts/activate", { id: a.id }));
    row.querySelector(".act-remove").addEventListener("click", () => {
      if (confirm(t("remove_confirm", { email: a.email }))) accountAction("/api/accounts/remove", { id: a.id });
    });
    list.appendChild(row);
  }
  for (const p of d.problems || []) {
    const line = document.createElement("div");
    line.style.cssText = "color:var(--error); font-size:11px; margin-top:6px;";
    line.textContent = p;
    list.appendChild(line);
  }
}

export async function loadAccounts() {
  try {
    const res = await fetch("/api/accounts");
    renderAccounts(await res.json());
  } catch (e) {
    renderAccounts({ ok: false, error: "Could not reach pig." });
  }
}

export async function accountAction(url, body) {
  try {
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const d = await res.json();
    if (!d.ok) {
      if (typeof window.__appendErrorMessage === "function") {
        window.__appendErrorMessage(d.error || "Account change failed.");
      }
    }
    renderAccounts(d);
    loadUsage();
    if (typeof onStateNeedsRefresh === "function") {
      onStateNeedsRefresh();
    }
  } catch (e) {
    if (typeof window.__appendErrorMessage === "function") {
      window.__appendErrorMessage("Account change failed.");
    }
  }
}

export function localizeQuotaName(rawName) {
  if (!rawName) return "";
  const trimmed = rawName.trim();
  const lower = trimmed.toLowerCase();

  if (lower === "gemini models") return __t("quota_gemini_models");
  if (lower === "claude and gpt models" || lower === "claude & gpt models") return __t("quota_claude_gpt_models");
  if (lower === "claude models") return __t("quota_claude_models");
  if (lower === "gpt models") return __t("quota_gpt_models");
  if (lower === "weekly limit remaining") return __t("quota_weekly_limit");
  if (lower === "five hour limit remaining" || lower === "5 hour limit remaining") return __t("quota_five_hour_limit");
  if (lower === "daily limit remaining" || lower === "24 hour limit remaining") return __t("quota_daily_limit");
  if (lower === "hourly limit remaining" || lower === "1 hour limit remaining") return __t("quota_hourly_limit");
  if (lower === "monthly limit remaining") return __t("quota_monthly_limit");

  return trimmed;
}

export function resetLabel(iso) {
  if (!iso) return "";
  const tVal = Date.parse(iso);
  if (isNaN(tVal)) return iso;
  const left = tVal - Date.now();
  if (left <= 0) return __t("quota_resets_now");
  const m = Math.round(left / 60000);
  if (m < 60) return __t("quota_resets_m", { m });
  const h = Math.floor(m / 60);
  return h < 48 ? __t("quota_resets_hm", { h, m: m % 60 }) : __t("quota_resets_d", { d: Math.round(h / 24) });
}

export async function loadUsage() {
  const box = document.getElementById("accounts-usage");
  if (!box) return;
  box.innerHTML = `<span style="color:var(--text-dim)">${escapeHtml(__t("quota_fetching"))}</span>`;
  try {
    const res = await fetch("/api/accounts/usage");
    const d = await res.json();
    if (!document.getElementById("accounts-usage")) return;
    if (!d.ok) {
      box.innerHTML = `<span style="color:var(--error)">${escapeHtml(d.error || __t("quota_unavailable"))}</span>`;
      return;
    }
    const u = d.usage;
    box.innerHTML = "";
    if (u.planLabel) {
      const plan = document.createElement("div");
      plan.style.cssText = "color:var(--text-muted); margin-bottom:8px;";
      plan.textContent = u.planLabel;
      box.appendChild(plan);
    }
    if (!u.groups || u.groups.length === 0) {
      box.innerHTML += `<span style="color:var(--text-dim)">${escapeHtml(u.quotaError || __t("quota_empty_groups"))}</span>`;
      return;
    }
    for (const g of u.groups) {
      const el = document.createElement("div");
      el.className = "quota-group";
      let cardHtml = `<div class="quota-group-name">${escapeHtml(localizeQuotaName(g.displayName))}</div><div class="quota-card">`;
      for (const b of g.buckets) {
        const pct = Math.max(0, Math.min(100, Math.round(b.remainingFraction * 100)));
        const cls = pct === 0 ? "empty" : pct < 20 ? "low" : "";
        const resetTxt = resetLabel(b.resetTime);
        cardHtml += `
          <div class="quota-item">
            <div class="quota-item-header">
              <span class="quota-item-title">${escapeHtml(localizeQuotaName(b.displayName))}</span>
              <span class="quota-pct">${pct}%</span>
            </div>
            <div class="quota-bar ${cls}"><div style="width:${pct}%"></div></div>
            ${resetTxt ? `<div class="quota-reset">${escapeHtml(resetTxt)}</div>` : ""}
          </div>`;
      }
      cardHtml += `</div>`;
      el.innerHTML = cardHtml;
      box.appendChild(el);
    }
  } catch (e) {
    if (box.isConnected) box.innerHTML = `<span style="color:var(--error)">${escapeHtml(__t("unreachable", { error: "network" }))}</span>`;
  }
}
