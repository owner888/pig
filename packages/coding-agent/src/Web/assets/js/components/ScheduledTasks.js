import { network } from "../network.js";
import { escapeHtml, openModal } from "../utils.js";
import { t, currentLocale } from "../i18n.js";

/**
 * Scheduled tasks, laid out as the Claude app lays its own out — the developer asked for the two to
 * match: a page in the main area with the tasks as cards (search, filter, sort and a "New task" menu
 * at the top), a page per task (its history on the left; instructions, schedule and permission on
 * the right; Run now and the switch at the top), and a dialog to create or change one.
 *
 * A task has any number of triggers (`ScheduledTask::$triggers`), each a cron expression — every
 * frequency the form offers is written as one — or a single time; none is a task that runs only
 * when asked. All in the browser's own timezone, recorded with the task: "every day at 09:00" is
 * the person's 09:00 whatever the server's clock says.
 *
 * `watchScheduledTasks()` is the part that runs with no page open: it keeps the sidebar entry's dot
 * in step with any run that is waiting for an answer, and raises a browser notification when a run
 * finishes, fails or stops to ask — the three moments somebody who scheduled something away from
 * the keyboard wants to hear about.
 */

const TIMEZONE = Intl.DateTimeFormat().resolvedOptions().timeZone;
const LEVELS = ["off", "minimal", "low", "medium", "high", "xhigh"];
const FREQUENCIES = ["hourly", "daily", "weekdays", "weekly", "custom"];

// The page's hint for "with pig": the Claude app's sentence, sent by the person, not by the page.
export const CREATE_WITH_CHAT_PROMPT_KEY = "sched_chat_prompt";

const ICONS = {
  search: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>`,
  filter: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="4" x2="6" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line><line x1="18" y1="4" x2="18" y2="20"></line><circle cx="6" cy="9" r="2" fill="var(--color-background)"></circle><circle cx="12" cy="15" r="2" fill="var(--color-background)"></circle><circle cx="18" cy="8" r="2" fill="var(--color-background)"></circle></svg>`,
  sort: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16"></path><path d="m3 8 4-4 4 4"></path><path d="M17 20V4"></path><path d="m13 16 4 4 4-4"></path></svg>`,
  chevron: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>`,
  chat: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.3 7.4L3 21l2.1-5.5A8.4 8.4 0 1 1 21 11.5z"></path><path d="M8 12c1.5 1.5 3 1.5 4 0s2.5-1.5 4 0"></path></svg>`,
  gear: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>`,
  clock: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15 14"></polyline></svg>`,
  laptop: `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="11" rx="1.5"></rect><path d="M2 19h20"></path></svg>`,
  play: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="7 4 20 12 7 20 7 4"></polygon></svg>`,
  auto: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6v12l7-6z"></path><path d="M12 6v12l7-6z"></path></svg>`,
  hand: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 11V6a2 2 0 0 0-4 0v5"></path><path d="M14 10V4a2 2 0 0 0-4 0v6"></path><path d="M10 10.5V6a2 2 0 0 0-4 0v8"></path><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"></path></svg>`,
  more: `<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="3" cy="8" r="1.5"></circle><circle cx="8" cy="8" r="1.5"></circle><circle cx="13" cy="8" r="1.5"></circle></svg>`,
  plus: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>`,
  minus: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"></line></svg>`,
  close: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"></line><line x1="18" y1="6" x2="6" y2="18"></line></svg>`,
  check: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
};
export const SCHEDULE_ICONS = ICONS;

async function api(path, body) {
  const res = await fetch(path, body === undefined ? undefined : {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `${res.status} ${res.statusText}`);
  return data;
}

const pad = (n) => String(n).padStart(2, "0");

/** The page's language for dates, or the browser's when it is one `Intl` does not know (an imported pack). */
function dateLocale() {
  try {
    return Intl.DateTimeFormat.supportedLocalesOf([currentLocale]).length ? currentLocale : undefined;
  } catch (err) {
    return undefined; // a pack named with something that is not a language tag at all
  }
}

/** Monday-first weekday names in the page's language; cron counts Sunday as 0. */
function weekdayName(day) {
  // 2026-10-11 was a Sunday, so day d is the 11th plus d.
  return new Intl.DateTimeFormat(dateLocale(), { weekday: "long" }).format(new Date(2026, 9, 11 + Number(day)));
}

/** "7:52" — the Claude app's clock: no leading zero on the hour. */
function clock(hour, minute) {
  return `${Number(hour)}:${pad(minute)}`;
}

/** "今天 7:52", "明天 7:52", or the date and the time — the Claude app's "下次运行". */
export function formatMoment(ms) {
  const d = new Date(ms);
  const day = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const time = clock(d.getHours(), d.getMinutes());
  if (day === today) return t("sched_today_at", { time });
  if (day === today + 86_400_000) return t("sched_tomorrow_at", { time });
  if (day === today - 86_400_000) return t("sched_yesterday_at", { time });
  const date = new Intl.DateTimeFormat(dateLocale(), { month: "short", day: "numeric", ...(d.getFullYear() !== now.getFullYear() ? { year: "numeric" } : {}) }).format(d);
  return `${date} ${time}`;
}

/** A task's triggers, from what the server sends — an older task has `schedule` / `cron` / `runAt` only. */
function triggersOf(task) {
  if (Array.isArray(task?.triggers)) return task.triggers;
  if (task?.schedule === "cron" && task.cron) return [{ type: "cron", cron: task.cron }];
  if (task?.schedule === "once" && task.runAt) return [{ type: "once", runAt: task.runAt }];
  return [];
}

/** One trigger as the form's fields: the reverse of `triggerFor()`. */
function triggerFields(trigger) {
  const now = new Date();
  const fields = { kind: "cron", frequency: "daily", time: `${pad(now.getHours())}:${pad(now.getMinutes())}`, minute: 0, weekday: 1, once: "", cron: "" };
  if (!trigger) return fields;
  if (trigger.type === "once") {
    const d = new Date(trigger.runAt);
    return { ...fields, kind: "once", once: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}` };
  }
  const m = /^(\d{1,2}) (\*|\d{1,2}) \* \* (\*|1-5|[0-7])$/.exec(trigger.cron || "");
  if (m && m[2] === "*" && m[3] === "*") return { ...fields, frequency: "hourly", minute: Number(m[1]) };
  if (m && m[2] !== "*") {
    const time = `${pad(m[2])}:${pad(m[1])}`;
    if (m[3] === "*") return { ...fields, frequency: "daily", time };
    if (m[3] === "1-5") return { ...fields, frequency: "weekdays", time };
    return { ...fields, frequency: "weekly", time, weekday: Number(m[3]) % 7 };
  }
  return { ...fields, frequency: "custom", cron: trigger.cron || "" };
}

/** A row of the form as what the server stores, or null when it is not filled in. */
function triggerFor(f) {
  if (f.kind === "once") return f.once ? { type: "once", runAt: new Date(f.once).getTime() } : null;
  const [h, min] = (f.time || "09:00").split(":").map(Number);
  switch (f.frequency) {
    case "hourly": return { type: "cron", cron: `${Number(f.minute) || 0} * * * *` };
    case "daily": return { type: "cron", cron: `${min} ${h} * * *` };
    case "weekdays": return { type: "cron", cron: `${min} ${h} * * 1-5` };
    case "weekly": return { type: "cron", cron: `${min} ${h} * * ${f.weekday}` };
    default: return f.cron.trim() ? { type: "cron", cron: f.cron.trim() } : null;
  }
}

/** Minutes `timezone` is ahead of UTC at this moment. */
function zoneOffset(timezone) {
  try {
    const parts = Object.fromEntries(new Intl.DateTimeFormat("en-US", { timeZone: timezone, hourCycle: "h23", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" })
      .formatToParts(new Date()).map((p) => [p.type, p.value]));
    const asUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour % 24, +parts.minute);
    return Math.round((asUtc - Math.floor(Date.now() / 60_000) * 60_000) / 60_000);
  } catch (err) {
    return -new Date().getTimezoneOffset();
  }
}

/**
 * A cron trigger written in `timezone`, moved to the browser's — "every day at 08:52" in Singapore is
 * 07:52 here at UTC+7, and the Claude app shows the person's own time. Only the shapes the form
 * writes are moved; a day-of-week one whose time crosses midnight, or a custom expression, is left
 * as it is.
 */
function localTrigger(trigger, timezone) {
  if (!timezone || timezone === TIMEZONE || trigger.type !== "cron") return trigger;
  const m = /^(\d{1,2}) (\d{1,2}) \* \* (\*|1-5|[0-7])$/.exec(trigger.cron || "");
  if (!m) return trigger;
  const total = Number(m[2]) * 60 + Number(m[1]) + (-new Date().getTimezoneOffset() - zoneOffset(timezone));
  if ((total < 0 || total >= 1440) && m[3] !== "*") return trigger;
  const minutes = ((total % 1440) + 1440) % 1440;
  return { type: "cron", cron: `${minutes % 60} ${Math.floor(minutes / 60)} * * ${m[3]}` };
}

/** One trigger in words, in the browser's time: "每天 7:52". */
export function describeTrigger(trigger, timezone = null) {
  trigger = localTrigger(trigger, timezone);
  const f = triggerFields(trigger);
  if (f.kind === "once") return t("sched_once_at", { when: formatMoment(trigger.runAt) });
  const [h, m] = f.time.split(":");
  switch (f.frequency) {
    case "hourly": return t("sched_hourly_at", { minute: pad(f.minute) });
    case "daily": return t("sched_daily_at", { time: clock(h, m) });
    case "weekdays": return t("sched_weekdays_at", { time: clock(h, m) });
    case "weekly": return t("sched_weekly_at", { day: weekdayName(f.weekday), time: clock(h, m) });
    default: return t("sched_custom_desc", { cron: trigger.cron });
  }
}

/** When a task runs, in words: each trigger, or "only when asked". */
export function describeSchedule(task) {
  const triggers = triggersOf(task);
  return triggers.length === 0 ? t("sched_manual") : triggers.map((x) => describeTrigger(x, task.timezone)).join(t("sched_and"));
}

function statusLabel(status) {
  return t("sched_status_" + status);
}

function duration(run) {
  if (!run.finishedAt) return "";
  const s = Math.max(0, Math.round((run.finishedAt - run.startedAt) / 1000));
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${pad(s % 60)}s`;
}

/**
 * A small menu under `anchor`: `items` are `{label, icon?, checked?, onSelect}`. Closes on a pick,
 * a click elsewhere or Escape.
 */
function openMenu(anchor, items, { align = "right", className = "" } = {}) {
  document.querySelector(".sp-menu")?.remove();
  const menu = document.createElement("div");
  menu.className = "sp-menu " + className;
  for (const item of items) {
    const row = document.createElement("button");
    row.type = "button";
    row.className = "sp-menu-item" + (item.danger ? " danger" : "");
    row.innerHTML = `${item.icon ? `<span class="sp-menu-icon">${item.icon}</span>` : ""}<span class="sp-menu-text"><span>${escapeHtml(item.label)}</span>${item.hint ? `<small>${escapeHtml(item.hint)}</small>` : ""}</span>${item.checked ? `<span class="sp-menu-check">${ICONS.check}</span>` : ""}`;
    row.addEventListener("click", (e) => { e.stopPropagation(); close(); item.onSelect(); });
    menu.appendChild(row);
  }
  document.body.appendChild(menu);
  const rect = anchor.getBoundingClientRect();
  const width = menu.getBoundingClientRect().width;
  let left = align === "right" ? rect.right - width : rect.left;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  menu.style.left = `${left}px`;
  const height = menu.getBoundingClientRect().height;
  const below = rect.bottom + 6;
  menu.style.top = `${below + height > window.innerHeight - 8 ? Math.max(8, rect.top - height - 6) : below}px`;

  const onDoc = (e) => { if (!menu.contains(e.target)) close(); };
  const onKey = (e) => { if (e.key === "Escape") { e.stopPropagation(); close(); } };
  function close() {
    menu.remove();
    document.removeEventListener("mousedown", onDoc, true);
    window.removeEventListener("keydown", onKey, true);
  }
  setTimeout(() => {
    document.addEventListener("mousedown", onDoc, true);
    window.addEventListener("keydown", onKey, true);
  });
  return close;
}

/**
 * The pages, drawn into `host` — an element in the main area the page shows in place of the
 * conversation. `onShow` / `onHide` swap the two; `onOpenConversation(cwd, sessionFile)` opens a
 * task's conversation in a tab; `onCreateWithChat()` starts a conversation with the Claude app's
 * sentence in the prompt, unsent; `onPageChange(taskId|null)` says which task's page is on screen,
 * for the sidebar's highlight.
 */
export function createScheduledPages({ host, onShow, onHide, onOpenConversation, onCreateWithChat, onPageChange = () => {} }) {
  let state = { tasks: [], owner: true, problems: [] };
  let view = null; // {name: "list"} | {name: "task", id}
  const prefs = { query: "", filter: "all", sort: "next" };

  const reload = async () => {
    try {
      state = await api("/api/scheduled");
    } catch (err) {
      state = { tasks: [], owner: false, problems: [err.message] };
    }
    if (view) render();
  };

  network.addMessageListener((m) => { if (m.type === "scheduled_update" && view) reload(); });

  function show(next) {
    const first = !view;
    view = next;
    onShow();
    onPageChange(next.name === "task" ? next.id : null);
    render();
    if (first) reload();
  }

  function close() {
    if (!view) return;
    view = null;
    host.replaceChildren();
    onPageChange(null);
    onHide();
  }

  function render() {
    if (!view) return;
    if (view.name === "list") renderList();
    else renderTask(state.tasks.find((x) => x.id === view.id));
  }

  // ---- the list ----------------------------------------------------------------------------------

  function visibleTasks() {
    const q = prefs.query.trim().toLowerCase();
    let tasks = state.tasks.filter((task) => {
      if (prefs.filter === "enabled" && !task.enabled) return false;
      if (prefs.filter === "paused" && task.enabled) return false;
      return !q || task.name.toLowerCase().includes(q) || task.prompt.toLowerCase().includes(q);
    });
    const byName = (a, b) => a.name.localeCompare(b.name, dateLocale());
    if (prefs.sort === "name") tasks = tasks.sort(byName);
    else if (prefs.sort === "created") tasks = tasks.sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));
    else tasks = tasks.sort((a, b) => (a.nextRunAt ?? Infinity) - (b.nextRunAt ?? Infinity) || byName(a, b));
    return tasks;
  }

  function renderList() {
    host.innerHTML = `
      <div class="sp sp-list-page">
        <div class="sp-head">
          <h1 class="sp-title">${escapeHtml(t("scheduled_tasks"))}</h1>
          <div class="sp-tools">
            <button class="sp-icon-btn sp-search-btn" title="${escapeHtml(t("sched_search"))}">${ICONS.search}</button>
            <button class="sp-icon-btn sp-filter-btn${prefs.filter !== "all" ? " on" : ""}" title="${escapeHtml(t("sched_filter"))}">${ICONS.filter}</button>
            <button class="sp-icon-btn sp-sort-btn" title="${escapeHtml(t("sched_sort"))}">${ICONS.sort}</button>
            <button class="sp-new-btn" ${state.owner ? "" : "disabled"}>${escapeHtml(t("sched_new"))}${ICONS.chevron}</button>
          </div>
        </div>
        <div class="sp-search"${prefs.query ? "" : " hidden"}><input class="sp-search-input" placeholder="${escapeHtml(t("sched_search_placeholder"))}" value="${escapeHtml(prefs.query)}"></div>
        ${state.owner ? "" : `<div class="sp-note">${escapeHtml(t("sched_not_owner"))}</div>`}
        ${state.problems.map((p) => `<div class="sp-note error">${escapeHtml(p)}</div>`).join("")}
        <div class="sp-cards"></div>
      </div>
    `;
    const searchBox = host.querySelector(".sp-search");
    const searchInput = host.querySelector(".sp-search-input");
    host.querySelector(".sp-search-btn").addEventListener("click", () => {
      searchBox.hidden = !searchBox.hidden;
      if (!searchBox.hidden) searchInput.focus();
      else if (prefs.query) { prefs.query = ""; searchInput.value = ""; drawCards(); }
    });
    searchInput.addEventListener("input", () => { prefs.query = searchInput.value; drawCards(); });
    searchInput.addEventListener("keydown", (e) => { if (e.key === "Escape") { searchBox.hidden = true; prefs.query = ""; searchInput.value = ""; drawCards(); } });

    const filterBtn = host.querySelector(".sp-filter-btn");
    filterBtn.addEventListener("click", () => openMenu(filterBtn, ["all", "enabled", "paused"].map((f) => ({
      label: t("sched_filter_" + f),
      checked: prefs.filter === f,
      onSelect: () => { prefs.filter = f; filterBtn.classList.toggle("on", f !== "all"); drawCards(); },
    }))));
    const sortBtn = host.querySelector(".sp-sort-btn");
    sortBtn.addEventListener("click", () => openMenu(sortBtn, ["next", "name", "created"].map((s) => ({
      label: t("sched_sort_" + s),
      checked: prefs.sort === s,
      onSelect: () => { prefs.sort = s; drawCards(); },
    }))));
    const newBtn = host.querySelector(".sp-new-btn");
    newBtn.addEventListener("click", () => openMenu(newBtn, [
      { label: t("sched_create_with_chat"), icon: ICONS.chat, onSelect: () => { close(); onCreateWithChat(); } },
      { label: t("sched_create_manually"), icon: ICONS.gear, onSelect: () => openTaskForm(null) },
    ]));
    drawCards();
  }

  function drawCards() {
    const box = host.querySelector(".sp-cards");
    if (!box) return;
    box.replaceChildren();
    const tasks = visibleTasks();
    if (tasks.length === 0) {
      box.innerHTML = `<div class="sp-empty">${escapeHtml(t(state.tasks.length === 0 ? "sched_empty" : "sched_no_match"))}</div>`;
      return;
    }
    for (const task of tasks) {
      const card = document.createElement("button");
      card.type = "button";
      card.className = "sp-card" + (task.enabled ? "" : " off");
      const last = task.lastRun;
      const status = task.running ? (last?.status || "running") : (task.unread ? "unread" : null);
      card.innerHTML = `
        <div class="sp-card-title"><span>${escapeHtml(task.name)}</span>${status ? `<span class="sp-status-dot ${escapeHtml(status)}" title="${escapeHtml(status === "unread" ? t("sched_unread") : statusLabel(status))}"></span>` : ""}</div>
        <div class="sp-card-prompt">${escapeHtml(task.prompt)}</div>
        <div class="sp-card-meta"><span>${escapeHtml(task.enabled ? describeSchedule(task) : t("sched_paused"))}</span><span class="sp-meta-sep">·</span><span class="sp-meta-icon" title="${escapeHtml(t("sched_needs_computer"))}">${ICONS.laptop}</span></div>
      `;
      card.addEventListener("click", () => show({ name: "task", id: task.id }));
      box.appendChild(card);
    }
  }

  // ---- a task ------------------------------------------------------------------------------------

  async function renderTask(task) {
    if (!task) {
      host.innerHTML = `<div class="sp"><div class="sp-empty">${escapeHtml(t("sched_task_gone"))}</div></div>`;
      return;
    }
    const triggers = triggersOf(task);
    const auto = task.permissionMode === "auto";
    host.innerHTML = `
      <div class="sp sp-task-page">
        <div class="sp-head">
          <h1 class="sp-title">${escapeHtml(task.name)}</h1>
          <div class="sp-tools">
            <button class="sp-run-btn" ${state.owner && !task.running ? "" : "disabled"}>${ICONS.play}<span>${escapeHtml(t(task.running ? "sched_status_running" : "sched_run_now"))}</span></button>
            <label class="sp-switch-label"><span>${escapeHtml(t(task.enabled ? "sched_on" : "sched_off"))}</span><input type="checkbox" class="sp-switch" ${task.enabled ? "checked" : ""} ${state.owner ? "" : "disabled"}></label>
            <button class="sp-icon-btn sp-more-btn" title="${escapeHtml(t("more_options"))}">${ICONS.more}</button>
          </div>
        </div>
        <div class="sp-sub">
          <span class="sp-chip" title="${escapeHtml(t(state.owner ? "sched_computer_ready" : "sched_not_owner"))}">${ICONS.laptop}<span>${escapeHtml(t("sched_needs_computer"))}</span><span class="sp-online${state.owner ? "" : " off"}"></span></span>
          ${task.nextRunAt ? `<span>${escapeHtml(t("sched_next_run_label", { when: formatMoment(task.nextRunAt) }))}</span>` : (task.enabled ? "" : `<span>${escapeHtml(t("sched_paused"))}</span>`)}
        </div>
        <div class="sp-rule"></div>
        <div class="sp-cols">
          <section class="sp-history">
            <h2 class="sp-h">${escapeHtml(t("sched_history"))}</h2>
            <div class="sp-runs"><div class="sp-empty-card">${escapeHtml(t("loading_sessions"))}</div></div>
          </section>
          <aside class="sp-side">
            <div class="sp-side-block sp-instructions-block">
              <h2 class="sp-h">${escapeHtml(t("sched_instructions"))}</h2>
              <div class="sp-instructions" title="${escapeHtml(t("sched_edit"))}">${escapeHtml(task.prompt)}</div>
            </div>
            <div class="sp-side-block">
              <h2 class="sp-h">${escapeHtml(t("sched_repeat"))}</h2>
              ${triggers.length === 0 ? `<div class="sp-side-value">${escapeHtml(t("sched_manual"))}</div>` : triggers.map((x) => `<div class="sp-side-value">${escapeHtml(describeTrigger(x, task.timezone))}</div>`).join("")}
            </div>
            <div class="sp-side-block">
              <h2 class="sp-h">${escapeHtml(t("sched_permission"))}</h2>
              <div class="sp-perm"><span class="sp-perm-icon">${auto ? ICONS.auto : ICONS.hand}</span><div><div class="sp-side-value">${escapeHtml(t(auto ? "sched_perm_auto" : "sched_perm_ask"))}</div><div class="sp-side-hint">${escapeHtml(t(auto ? "sched_perm_auto_hint" : "sched_perm_ask_hint"))}</div></div></div>
            </div>
          </aside>
        </div>
      </div>
    `;

    host.querySelector(".sp-run-btn").addEventListener("click", async (e) => {
      e.currentTarget.disabled = true;
      try { await api("/api/scheduled/run", { id: task.id }); } catch (err) { showError(err.message); }
    });
    const toggle = host.querySelector(".sp-switch");
    toggle.addEventListener("change", async () => {
      try {
        await api("/api/scheduled/toggle", { id: task.id, enabled: toggle.checked });
      } catch (err) {
        toggle.checked = !toggle.checked;
        showError(err.message);
      }
    });
    host.querySelector(".sp-instructions").addEventListener("click", () => openTaskForm(task));
    const more = host.querySelector(".sp-more-btn");
    more.addEventListener("click", () => openMenu(more, [
      { label: t("sched_edit"), onSelect: () => openTaskForm(task) },
      ...(task.sessionFile ? [{ label: t("sched_open_task_conversation"), onSelect: () => openConversation(task.cwd, task.sessionFile) }] : []),
      { label: t("sched_delete"), danger: true, onSelect: () => confirmDelete(task) },
    ]));

    let runs = [];
    try { runs = (await api("/api/scheduled/runs?id=" + encodeURIComponent(task.id))).runs; } catch (err) { runs = null; }
    const box = host.querySelector(".sp-runs");
    if (!box || view?.id !== task.id) return;
    if (runs === null) { box.innerHTML = `<div class="sp-empty-card error">${escapeHtml(t("sched_runs_failed"))}</div>`; return; }
    if (runs.length === 0) { box.innerHTML = `<div class="sp-empty-card">${escapeHtml(t("sched_no_runs"))}</div>`; return; }
    box.replaceChildren();
    for (const run of runs) {
      const row = document.createElement(run.sessionFile ? "button" : "div");
      if (run.sessionFile) row.type = "button";
      row.className = "sp-run" + (run.sessionFile ? " openable" : "");
      row.innerHTML = `
        <span class="sp-status-dot ${escapeHtml(run.status)}"></span>
        <span class="sp-run-when">${escapeHtml(formatMoment(run.startedAt))}${run.trigger === "manual" ? ` · ${escapeHtml(t("sched_trigger_manual"))}` : ""}</span>
        <span class="sp-run-status">${escapeHtml(statusLabel(run.status))}</span>
        <span class="sp-run-took">${escapeHtml(duration(run))}</span>
        ${run.error ? `<div class="sp-run-error">${escapeHtml(run.error)}</div>` : ""}
      `;
      if (run.sessionFile) {
        row.title = t("sched_open_conversation");
        row.addEventListener("click", () => openConversation(run.cwd || task.cwd, run.sessionFile));
      }
      box.appendChild(row);
    }
  }

  function openConversation(cwd, sessionFile) {
    close();
    onOpenConversation(cwd, sessionFile.split("/").pop());
  }

  function confirmDelete(task) {
    openModal({
      title: t("sched_delete_title", { name: task.name }),
      bodyHtml: `<div class="sp-confirm-text">${escapeHtml(t("sched_delete_body"))}</div>`,
      confirmText: t("sched_delete"),
      cancelText: t("cancel"),
      isDanger: true,
      onConfirm: async () => {
        try {
          await api("/api/scheduled/delete", { id: task.id });
          show({ name: "list" });
          await reload();
          return true;
        } catch (err) {
          showError(err.message);
          return true;
        }
      },
    });
  }

  function showError(message) {
    const page = host.querySelector(".sp");
    if (!page) return;
    const note = document.createElement("div");
    note.className = "sp-note error";
    note.textContent = message;
    page.querySelector(".sp-rule, .sp-cards")?.before(note);
  }

  // ---- the form ----------------------------------------------------------------------------------

  async function openTaskForm(task) {
    const editing = !!task;
    let mode = task ? (task.permissionMode || "ask") : "auto";
    let model = task?.model || "";
    let modelLabel = t("sched_default_model");
    const rows = triggersOf(task).map((x) => triggerFields(localTrigger(x, task?.timezone)));
    if (!task) rows.push(triggerFields(null));
    // `manual` is the form's way of saying "no trigger": a row of its own kind, alone.
    const manual = { on: editing && rows.length === 0 };
    if (manual.on) rows.push({ ...triggerFields(null), kind: "manual" });

    const { modal, close: closeForm } = openModal({
      title: "",
      hideFooter: true,
      width: "min(600px, 94vw)",
      bodyHtml: `
        <form class="sf" novalidate>
          <div class="sf-head">
            <div>
              <div class="sf-title">${escapeHtml(t(editing ? "sched_edit_title" : "sched_new_title"))}</div>
              ${editing ? "" : `<button type="button" class="sf-alt">${escapeHtml(t("sched_use_chat_instead"))}</button>`}
            </div>
            <button type="button" class="sf-close" aria-label="${escapeHtml(t("cancel"))}">${ICONS.close}</button>
          </div>
          <label class="sf-label">${escapeHtml(t("sched_name"))}
            <input class="sf-input" name="name" autocomplete="off" value="${escapeHtml(task?.name || "")}" placeholder="${escapeHtml(t("sched_name_placeholder"))}">
          </label>
          <div class="sf-composer">
            <textarea class="sf-textarea" name="prompt" rows="6" placeholder="${escapeHtml(t("sched_prompt_placeholder"))}">${escapeHtml(task?.prompt || "")}</textarea>
            <div class="sf-composer-bar">
              <button type="button" class="sf-pill sf-mode"></button>
              <button type="button" class="sf-pill sf-model"></button>
            </div>
          </div>
          <div class="sf-banner"></div>
          <div class="sf-box sf-triggers">
            <div class="sf-box-label">${escapeHtml(t("sched_trigger"))}</div>
            <div class="sf-rows"></div>
          </div>
          <details class="sf-box sf-advanced">
            <summary><span>${escapeHtml(t("sched_advanced"))}</span><span class="sf-advanced-chevron">${ICONS.chevron}</span></summary>
            <div class="sf-advanced-body">
              <label class="sf-label">${escapeHtml(t("sched_folder"))}
                <input class="sf-input mono" name="cwd" list="sf-folders" autocomplete="off" value="${escapeHtml(task?.cwd || "")}"><datalist id="sf-folders"></datalist>
              </label>
              <label class="sf-label">${escapeHtml(t("sched_thinking"))}
                <select class="sf-input" name="thinkingLevel"><option value="">${escapeHtml(t("sched_default_thinking"))}</option>${LEVELS.map((l) => `<option value="${l}">${l}</option>`).join("")}</select>
              </label>
              <div class="sf-hint">${escapeHtml(t("sched_timezone", { tz: TIMEZONE }))}</div>
            </div>
          </details>
          <div class="sf-error" hidden></div>
          <div class="sf-foot">
            <button type="button" class="sf-btn sf-cancel">${escapeHtml(t("cancel"))}</button>
            <button type="submit" class="sf-btn primary">${escapeHtml(t(editing ? "sched_save" : "sched_create"))}</button>
          </div>
        </form>
      `,
    });
    modal.classList.add("sf-modal");
    modal.querySelector(".modal-header").remove();

    const form = modal.querySelector("form");
    const field = (name) => form.elements.namedItem(name);
    field("thinkingLevel").value = task?.thinkingLevel || "";
    form.querySelector(".sf-close").addEventListener("click", closeForm);
    form.querySelector(".sf-cancel").addEventListener("click", closeForm);
    form.querySelector(".sf-alt")?.addEventListener("click", () => { closeForm(); close(); onCreateWithChat(); });

    // The permission and the model, in the bar under the instructions, as the Claude app's form has them.
    const modeBtn = form.querySelector(".sf-mode");
    const modelBtn = form.querySelector(".sf-model");
    const banner = form.querySelector(".sf-banner");
    const drawMode = () => {
      modeBtn.innerHTML = `${mode === "auto" ? ICONS.auto : ICONS.hand}<span>${escapeHtml(t(mode === "auto" ? "sched_perm_auto" : "sched_perm_ask"))}</span>${ICONS.chevron}`;
      banner.textContent = t(mode === "auto" ? "sched_banner_auto" : "sched_banner_ask");
    };
    const drawModel = () => { modelBtn.innerHTML = `<span>${escapeHtml(modelLabel)}</span>${ICONS.chevron}`; };
    drawMode();
    drawModel();
    modeBtn.addEventListener("click", () => openMenu(modeBtn, ["auto", "ask"].map((m) => ({
      label: t(m === "auto" ? "sched_perm_auto" : "sched_perm_ask"),
      hint: t(m === "auto" ? "sched_perm_auto_hint" : "sched_perm_ask_hint"),
      icon: m === "auto" ? ICONS.auto : ICONS.hand,
      checked: mode === m,
      onSelect: () => { mode = m; drawMode(); },
    })), { align: "left", className: "sp-menu-wide" }));
    let models = [];
    modelBtn.addEventListener("click", () => openMenu(modelBtn, [
      { label: t("sched_default_model"), checked: model === "", onSelect: () => { model = ""; modelLabel = t("sched_default_model"); drawModel(); } },
      ...models.map((m) => {
        const value = `${m.provider}/${m.id}`;
        const label = `${m.name || m.id}`;
        return { label, hint: m.provider, checked: model === value, onSelect: () => { model = value; modelLabel = label; drawModel(); } };
      }),
    ], { className: "sp-menu-scroll" }));

    // The triggers: a row each, the first with the "+", the rest with a "−".
    const rowsBox = form.querySelector(".sf-rows");
    const drawRows = () => {
      rowsBox.replaceChildren();
      rows.forEach((r, i) => {
        const row = document.createElement("div");
        row.className = "sf-row";
        const kindLabel = t(r.kind === "once" ? "sched_kind_once" : r.kind === "manual" ? "sched_kind_manual" : "sched_kind_cron");
        row.innerHTML = `
          <button type="button" class="sf-chip sf-kind">${ICONS.clock}<span>${escapeHtml(kindLabel)}</span>${ICONS.chevron}</button>
          ${r.kind === "cron" ? `<button type="button" class="sf-chip outline sf-freq"><span>${escapeHtml(t("sched_freq_" + r.frequency))}</span>${ICONS.chevron}</button>` : ""}
          ${r.kind === "cron" && ["daily", "weekdays", "weekly"].includes(r.frequency) ? `<input type="time" class="sf-chip outline sf-time" value="${r.time}">` : ""}
          ${r.kind === "cron" && r.frequency === "weekly" ? `<select class="sf-chip outline sf-weekday">${[1, 2, 3, 4, 5, 6, 0].map((d) => `<option value="${d}"${Number(r.weekday) === d ? " selected" : ""}>${escapeHtml(weekdayName(d))}</option>`).join("")}</select>` : ""}
          ${r.kind === "cron" && r.frequency === "hourly" ? `<label class="sf-chip outline sf-minute-wrap">${escapeHtml(t("sched_minute_prefix"))}<input type="number" min="0" max="59" class="sf-minute" value="${r.minute}">${escapeHtml(t("sched_minute_suffix"))}</label>` : ""}
          ${r.kind === "cron" && r.frequency === "custom" ? `<input class="sf-chip outline sf-cron mono" placeholder="0 9 * * 1-5" value="${escapeHtml(r.cron)}">` : ""}
          ${r.kind === "once" ? `<input type="datetime-local" class="sf-chip outline sf-once" value="${r.once}">` : ""}
          <span class="sf-row-gap"></span>
          ${r.kind === "manual" ? "" : `<button type="button" class="sf-row-btn" title="${escapeHtml(t(i === 0 ? "sched_add_trigger" : "sched_remove_trigger"))}">${i === 0 ? ICONS.plus : ICONS.minus}</button>`}
        `;
        const kindBtn = row.querySelector(".sf-kind");
        kindBtn.addEventListener("click", () => openMenu(kindBtn, ["cron", "once", "manual"].map((k) => ({
          label: t("sched_kind_" + k),
          hint: t("sched_kind_" + k + "_hint"),
          checked: r.kind === k,
          onSelect: () => {
            if (k === "manual") { rows.splice(0, rows.length, { ...triggerFields(null), kind: "manual" }); }
            else { r.kind = k; }
            drawRows();
          },
        })), { align: "left", className: "sp-menu-wide" }));
        const freqBtn = row.querySelector(".sf-freq");
        freqBtn?.addEventListener("click", () => openMenu(freqBtn, FREQUENCIES.map((x) => ({
          label: t("sched_freq_" + x),
          checked: r.frequency === x,
          onSelect: () => { r.frequency = x; drawRows(); },
        })), { align: "left" }));
        row.querySelector(".sf-time")?.addEventListener("change", (e) => { r.time = e.target.value; });
        row.querySelector(".sf-weekday")?.addEventListener("change", (e) => { r.weekday = Number(e.target.value); });
        row.querySelector(".sf-minute")?.addEventListener("input", (e) => { r.minute = Number(e.target.value); });
        row.querySelector(".sf-cron")?.addEventListener("input", (e) => { r.cron = e.target.value; });
        row.querySelector(".sf-once")?.addEventListener("change", (e) => { r.once = e.target.value; });
        row.querySelector(".sf-row-btn")?.addEventListener("click", () => {
          if (i === 0) rows.push(triggerFields(null)); else rows.splice(i, 1);
          drawRows();
        });
        rowsBox.appendChild(row);
      });
    };
    drawRows();
    field("name").focus();

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const error = form.querySelector(".sf-error");
      const triggers = [];
      for (const r of rows) {
        if (r.kind === "manual") continue;
        const trigger = triggerFor(r);
        if (!trigger) { error.textContent = t("sched_trigger_incomplete"); error.hidden = false; return; }
        triggers.push(trigger);
      }
      const payload = {
        id: task?.id,
        name: field("name").value,
        prompt: field("prompt").value,
        cwd: field("cwd").value.trim(),
        triggers,
        timezone: TIMEZONE,
        model: model || null,
        thinkingLevel: field("thinkingLevel").value || null,
        permissionMode: mode,
        enabled: task ? task.enabled : true,
      };
      try {
        const saved = await api("/api/scheduled/save", payload);
        // Asked here because saving is a click: a browser only asks when somebody has just done something.
        if ("Notification" in window && Notification.permission === "default") Notification.requestPermission();
        closeForm();
        await reload();
        show({ name: "task", id: saved.task.id });
      } catch (err) {
        error.textContent = err.message;
        error.hidden = false;
      }
    });

    // The folders and the models, filled once they arrive.
    try {
      const [folders, list] = await Promise.all([api("/api/folders"), api("/api/models")]);
      const datalist = form.querySelector("#sf-folders");
      for (const w of folders.workspaces || []) {
        const o = document.createElement("option");
        o.value = w.path;
        datalist.appendChild(o);
      }
      if (!field("cwd").value) field("cwd").value = folders.currentCwd || "";
      models = list;
      const current = list.find((m) => `${m.provider}/${m.id}` === model);
      if (current) { modelLabel = current.name || current.id; drawModel(); }
      else if (model) { modelLabel = model; drawModel(); }
    } catch (err) {
      /* The fields still take typing; only the suggestions are missing. */
    }
  }

  return {
    openList: () => show({ name: "list" }),
    openTask: (id) => show({ name: "task", id }),
    openNew: () => openTaskForm(null),
    close,
    isOpen: () => !!view,
  };
}

/**
 * Keeps the sidebar entry's dot and the browser notifications in step with the runs, whether or
 * not a page is open. Returns the function that looks again.
 */
export function watchScheduledTasks({ onAttention }) {
  let last = null;

  const look = async () => {
    let data;
    try { data = await api("/api/scheduled"); } catch (err) { return; }

    const now = new Map(data.tasks.map((task) => [task.id, task]));
    onAttention(data.tasks.some((task) => task.lastRun?.status === "needs_input"), data.tasks);

    if (last && "Notification" in window && Notification.permission === "granted") {
      for (const task of data.tasks) {
        const run = task.lastRun;
        const before = last.get(task.id)?.lastRun;
        if (!run || (before?.id === run.id && before.status === run.status)) continue;
        if (!["done", "error", "needs_input"].includes(run.status)) continue;
        new Notification(task.name, { body: statusLabel(run.status) + (run.error ? ": " + run.error : ""), tag: "pig-scheduled-" + task.id });
      }
    }
    last = now;
  };

  network.addMessageListener((m) => { if (m.type === "scheduled_update") look(); });
  look();

  return look;
}

/**
 * The card a `schedule` call's confirmation is drawn as, in the conversation where the call was
 * made — the Claude app's "Claude wants to create a scheduled task". `call` is the tool call's
 * arguments, `req` the confirmation (`ScheduleTool::summary()` is its message). `answer(yes)`
 * sends the reply. Returns the element.
 */
export function scheduleConfirmCard({ call, req, answer }) {
  const args = call?.arguments || call?.args || {};
  const action = args.action || "create";
  const facts = parseSummary(req.message || "");
  const el = document.createElement("div");
  el.className = "sc-card";

  const titleKey = { create: "sched_card_create", update: "sched_card_update", delete: "sched_card_delete", enable: "sched_card_enable", disable: "sched_card_disable", run: "sched_card_run" }[action] || "sched_card_create";
  const name = facts.Name || args.name || "";
  // The schedule in the person's own words and timezone, from the call when it says one.
  let when = facts.When || "";
  if (args.cron) when = describeTrigger({ type: "cron", cron: args.cron }, args.timezone || null) + " " + offsetLabel();
  else if (args.at) when = t("sched_once_at", { when: args.at });
  else if (args.manual === true) when = t("sched_manual");

  const raw = Object.entries(args).filter(([k]) => k !== "action");
  const rows = [
    [t("sched_card_task"), name],
    ...(when ? [[t("sched_card_schedule"), when]] : []),
    ...(action === "create" || action === "update" ? [[t("sched_card_where"), t("sched_card_your_computer")]] : []),
    [t("sched_card_source"), t("sched_card_your_request")],
    ...(facts.Prompt ? [[t("sched_card_instructions"), facts.Prompt]] : []),
    ...(!facts.Name ? [[t("sched_card_details"), req.message || ""]] : []),
  ];

  el.innerHTML = `
    <button type="button" class="sc-head" aria-expanded="true"><span>${escapeHtml(t(titleKey))}</span>${ICONS.chevron}</button>
    <div class="sc-raw">
      ${raw.map(([k, v]) => `<div class="sc-raw-key">${escapeHtml(k)}:</div><pre class="sc-raw-value">${escapeHtml(typeof v === "string" ? v : JSON.stringify(v))}</pre>`).join("")}
    </div>
    <div class="sc-truncated" hidden>${escapeHtml(t("sched_card_truncated"))}</div>
    <div class="sc-facts">
      ${rows.map(([k, v]) => `<div class="sc-k">${escapeHtml(k)}</div><div class="sc-v">${escapeHtml(v)}</div>`).join("")}
    </div>
    <div class="sc-foot">
      <button type="button" class="sc-btn sc-no">${escapeHtml(t("cancel"))}<kbd>Esc</kbd></button>
      <button type="button" class="sc-btn primary sc-yes">${escapeHtml(t(action === "create" ? "sched_card_schedule_btn" : "sched_card_confirm_btn"))}<kbd>${/Mac|iP/.test(navigator.platform) ? "⌘" : "Ctrl"}</kbd><kbd>↵</kbd></button>
    </div>
  `;
  const head = el.querySelector(".sc-head");
  head.addEventListener("click", () => {
    const open = head.getAttribute("aria-expanded") !== "true";
    head.setAttribute("aria-expanded", String(open));
    el.classList.toggle("collapsed", !open);
  });
  // "Input truncated" once the arguments are taller than the box shows.
  requestAnimationFrame(() => {
    const box = el.querySelector(".sc-raw");
    el.querySelector(".sc-truncated").hidden = box.scrollHeight <= box.clientHeight + 1;
  });

  let done = false;
  const finish = (yes) => {
    if (done) return;
    done = true;
    window.removeEventListener("keydown", onKey, true);
    el.classList.add("answered");
    el.querySelector(".sc-foot").innerHTML = `<span class="sc-answer">${escapeHtml(t(yes ? "sched_card_done" : "sched_card_declined"))}</span>`;
    answer(yes);
  };
  const onKey = (e) => {
    if (!el.isConnected) { window.removeEventListener("keydown", onKey, true); return; }
    if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); finish(false); }
    else if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) { e.preventDefault(); e.stopPropagation(); finish(true); }
  };
  window.addEventListener("keydown", onKey, true);
  el.querySelector(".sc-no").addEventListener("click", () => finish(false));
  el.querySelector(".sc-yes").addEventListener("click", () => finish(true));
  /** The question was answered elsewhere, or withdrawn: the card stops asking. */
  el.withdraw = () => { if (!done) { done = true; window.removeEventListener("keydown", onKey, true); el.classList.add("answered"); el.querySelector(".sc-foot").innerHTML = ""; } };
  return el;
}

/** "UTC+7" — the browser's offset, as the Claude app writes it after a time. */
function offsetLabel() {
  const minutes = -new Date().getTimezoneOffset();
  const sign = minutes >= 0 ? "+" : "-";
  const h = Math.floor(Math.abs(minutes) / 60);
  const m = Math.abs(minutes) % 60;
  return `UTC${sign}${h}${m ? ":" + pad(m) : ""}`;
}

/** `ScheduleTool::summary()`'s "Label: value" lines, with `Prompt:` taking the rest. */
function parseSummary(message) {
  const facts = {};
  const lines = message.split("\n");
  for (let i = 0; i < lines.length; i++) {
    const m = /^(Name|When|Folder|Model|Thinking|Permission|Prompt):\s?(.*)$/.exec(lines[i]);
    if (!m) continue;
    if (m[1] === "Prompt") { facts.Prompt = [m[2], ...lines.slice(i + 1)].join("\n").trim(); break; }
    facts[m[1]] = m[2];
  }
  return facts;
}
