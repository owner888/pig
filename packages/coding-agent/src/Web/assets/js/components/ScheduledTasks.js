import { network } from "../network.js";
import { escapeHtml, openModal } from "../utils.js";
import { t, currentLocale } from "../i18n.js";

/**
 * Scheduled tasks, after the Claude app's: a list, a form, and each task's runs.
 *
 * Every frequency the form offers is stored as a cron expression (or a single time, or nothing for
 * a task that only runs when asked), in the browser's own timezone — `ScheduledTask` on the server
 * says why. Reading a stored expression back into the form is the reverse of `cronFor()`, and
 * anything it does not recognise opens as "Custom".
 *
 * `watchScheduledTasks()` is the part that runs with the panel closed: it keeps the sidebar entry's
 * dot in step with any run that is waiting for an answer, and raises a browser notification when a
 * run finishes, fails or stops to ask — the three moments somebody who scheduled something away
 * from the keyboard wants to hear about.
 */

const TIMEZONE = Intl.DateTimeFormat().resolvedOptions().timeZone;

const LEVELS = ["off", "minimal", "low", "medium", "high", "xhigh"];

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

function formatMoment(ms) {
  return new Intl.DateTimeFormat(dateLocale(), { dateStyle: "medium", timeStyle: "short" }).format(new Date(ms));
}

/** A stored task as the form's fields: the reverse of `cronFor()`. */
function formFields(task) {
  const fields = { frequency: "daily", time: "09:00", minute: 0, weekday: 1, once: "", cron: "" };
  if (!task) return fields;

  if (task.schedule === "manual") return { ...fields, frequency: "manual" };
  if (task.schedule === "once") {
    const d = new Date(task.runAt);
    return { ...fields, frequency: "once", once: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}` };
  }

  const m = /^(\d{1,2}) (\*|\d{1,2}) \* \* (\*|1-5|[0-7])$/.exec(task.cron || "");
  if (m && m[2] === "*" && m[3] === "*") return { ...fields, frequency: "hourly", minute: Number(m[1]) };
  if (m && m[2] !== "*") {
    const time = `${pad(m[2])}:${pad(m[1])}`;
    if (m[3] === "*") return { ...fields, frequency: "daily", time };
    if (m[3] === "1-5") return { ...fields, frequency: "weekdays", time };
    return { ...fields, frequency: "weekly", time, weekday: Number(m[3]) % 7 };
  }
  return { ...fields, frequency: "custom", cron: task.cron };
}

/** The form's fields as what the server stores. */
function cronFor(f) {
  const [h, min] = (f.time || "09:00").split(":").map(Number);
  switch (f.frequency) {
    case "hourly": return `${Number(f.minute) || 0} * * * *`;
    case "daily": return `${min} ${h} * * *`;
    case "weekdays": return `${min} ${h} * * 1-5`;
    case "weekly": return `${min} ${h} * * ${f.weekday}`;
    case "custom": return f.cron.trim();
    default: return null;
  }
}

/** One line saying when a task runs. */
export function describeSchedule(task) {
  const f = formFields(task);
  switch (f.frequency) {
    case "manual": return t("sched_manual");
    case "once": return t("sched_once_at", { when: formatMoment(task.runAt) });
    case "hourly": return t("sched_hourly_at", { minute: pad(f.minute) });
    case "daily": return t("sched_daily_at", { time: f.time });
    case "weekdays": return t("sched_weekdays_at", { time: f.time });
    case "weekly": return t("sched_weekly_at", { day: weekdayName(f.weekday), time: f.time });
    default: return t("sched_custom_desc", { cron: task.cron });
  }
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
 * The panel. `onOpenConversation(cwd, sessionFile)` opens a run's conversation in a tab.
 */
export function openScheduledTasks({ onOpenConversation }) {
  let state = { tasks: [], owner: true, problems: [] };
  let view = { name: "list" };
  let body = null;
  let unlisten = null;
  let modalClose = null;

  const cleanup = () => { unlisten?.(); unlisten = null; };

  const reload = async () => {
    try {
      state = await api("/api/scheduled");
    } catch (err) {
      state = { tasks: [], owner: false, problems: [err.message] };
    }
    if (view.name !== "form") render();
  };

  const go = (next) => { view = next; render(); };

  function render() {
    if (!body) return;
    if (view.name === "list") renderList();
    else if (view.name === "detail") renderDetail(state.tasks.find((x) => x.id === view.id));
    else renderForm(view.task || null);
  }

  // ---- list ------------------------------------------------------------------------------------

  function renderList() {
    body.innerHTML = `
      <div class="sched-toolbar">
        <span class="sched-subtitle">${escapeHtml(t("sched_intro"))}</span>
        <button class="modal-btn primary sched-new" ${state.owner ? "" : "disabled"}>${escapeHtml(t("sched_new"))}</button>
      </div>
      ${state.owner ? "" : `<div class="sched-note">${escapeHtml(t("sched_not_owner"))}</div>`}
      ${state.problems.map((p) => `<div class="sched-note error">${escapeHtml(p)}</div>`).join("")}
      <div class="sched-list"></div>
    `;
    body.querySelector(".sched-new").addEventListener("click", () => go({ name: "form" }));
    const list = body.querySelector(".sched-list");

    if (state.tasks.length === 0) {
      list.innerHTML = `<div class="sidebar-empty">${escapeHtml(t("sched_empty"))}</div>`;
      return;
    }

    for (const task of state.tasks) {
      const row = document.createElement("div");
      row.className = "sched-row" + (task.enabled ? "" : " off");
      const last = task.lastRun;
      const when = task.running
        ? statusLabel(last?.status || "running")
        : task.nextRunAt ? t("sched_next", { when: formatMoment(task.nextRunAt) }) : (task.enabled ? "" : t("sched_paused"));
      row.innerHTML = `
        <input type="checkbox" class="sched-toggle" ${task.enabled ? "checked" : ""} ${state.owner ? "" : "disabled"} title="${escapeHtml(t("sched_enabled"))}">
        <div class="sched-row-main">
          <div class="sched-row-name">${escapeHtml(task.name)}</div>
          <div class="sched-row-meta">${escapeHtml(describeSchedule(task))} · ${escapeHtml(task.cwd.split("/").filter(Boolean).pop() || task.cwd)}</div>
        </div>
        <div class="sched-row-side">
          ${last ? `<span class="sched-dot ${escapeHtml(last.status)}" title="${escapeHtml(statusLabel(last.status))}"></span>` : ""}
          <span>${escapeHtml(when)}</span>
        </div>
      `;
      const toggle = row.querySelector(".sched-toggle");
      toggle.addEventListener("click", (e) => e.stopPropagation());
      toggle.addEventListener("change", async () => {
        try {
          await api("/api/scheduled/toggle", { id: task.id, enabled: toggle.checked });
        } catch (err) {
          toggle.checked = !toggle.checked;
          showError(err.message);
        }
      });
      row.addEventListener("click", () => go({ name: "detail", id: task.id }));
      list.appendChild(row);
    }
  }

  function showError(message) {
    const note = document.createElement("div");
    note.className = "sched-note error";
    note.textContent = message;
    body.prepend(note);
  }

  // ---- detail ----------------------------------------------------------------------------------

  async function renderDetail(task) {
    if (!task) return go({ name: "list" });

    body.innerHTML = `
      <button class="sidebar-view-all sched-back">${escapeHtml(t("sched_back"))}</button>
      <div class="sched-detail-head">
        <div class="sched-detail-name">${escapeHtml(task.name)}</div>
        <div class="sched-actions">
          ${task.sessionFile ? `<button class="modal-btn sched-open">${escapeHtml(t("sched_open_task_conversation"))}</button>` : ""}
          <button class="modal-btn primary sched-run" ${state.owner && !task.running ? "" : "disabled"}>${escapeHtml(t("sched_run_now"))}</button>
          <button class="modal-btn sched-edit" ${state.owner ? "" : "disabled"}>${escapeHtml(t("sched_edit"))}</button>
          <button class="modal-btn danger sched-delete" ${state.owner ? "" : "disabled"}>${escapeHtml(t("sched_delete"))}</button>
        </div>
      </div>
      <dl class="sched-facts">
        <dt>${escapeHtml(t("sched_frequency"))}</dt><dd>${escapeHtml(describeSchedule(task))}${task.enabled ? "" : " · " + escapeHtml(t("sched_paused"))}</dd>
        ${task.nextRunAt ? `<dt>${escapeHtml(t("sched_next_run"))}</dt><dd>${escapeHtml(formatMoment(task.nextRunAt))}</dd>` : ""}
        <dt>${escapeHtml(t("sched_folder"))}</dt><dd class="mono">${escapeHtml(task.cwd)}</dd>
        <dt>${escapeHtml(t("sched_model"))}</dt><dd class="mono">${escapeHtml(task.model || t("sched_default_model"))}${task.thinkingLevel ? " · " + escapeHtml(task.thinkingLevel) : ""}</dd>
      </dl>
      <div class="modal-section">${escapeHtml(t("sched_prompt"))}</div>
      <pre class="sched-prompt">${escapeHtml(task.prompt)}</pre>
      <div class="modal-section">${escapeHtml(t("sched_runs"))}</div>
      <div class="sched-runs"><div class="sidebar-empty">${escapeHtml(t("loading_sessions"))}</div></div>
    `;

    body.querySelector(".sched-back").addEventListener("click", () => go({ name: "list" }));
    body.querySelector(".sched-edit").addEventListener("click", () => go({ name: "form", task }));
    body.querySelector(".sched-open")?.addEventListener("click", () => {
      close();
      onOpenConversation(task.cwd, task.sessionFile.split("/").pop());
    });
    body.querySelector(".sched-run").addEventListener("click", async (e) => {
      e.currentTarget.disabled = true;
      try { await api("/api/scheduled/run", { id: task.id }); } catch (err) { showError(err.message); }
    });

    // Two clicks rather than a second dialog: the panel is already a modal.
    const del = body.querySelector(".sched-delete");
    let armed = null;
    del.addEventListener("click", async () => {
      if (!armed) {
        del.textContent = t("sched_delete_confirm");
        armed = setTimeout(() => { armed = null; del.textContent = t("sched_delete"); }, 3000);
        return;
      }
      clearTimeout(armed);
      try {
        await api("/api/scheduled/delete", { id: task.id });
        go({ name: "list" });
      } catch (err) { showError(err.message); }
    });

    let runs = [];
    try { runs = (await api("/api/scheduled/runs?id=" + encodeURIComponent(task.id))).runs; } catch (err) { /* drawn as empty below, with the reason */ runs = null; }
    const box = body.querySelector(".sched-runs");
    if (!box) return;
    if (runs === null) { box.innerHTML = `<div class="sidebar-empty error">${escapeHtml(t("sched_runs_failed"))}</div>`; return; }
    if (runs.length === 0) { box.innerHTML = `<div class="sidebar-empty">${escapeHtml(t("sched_no_runs"))}</div>`; return; }

    box.replaceChildren();
    for (const run of runs) {
      const row = document.createElement("div");
      row.className = "sched-run-row" + (run.sessionFile ? " openable" : "");
      row.innerHTML = `
        <span class="sched-dot ${escapeHtml(run.status)}"></span>
        <span class="sched-run-status">${escapeHtml(statusLabel(run.status))}</span>
        <span class="sched-run-when">${escapeHtml(formatMoment(run.startedAt))}${run.trigger === "manual" ? " · " + escapeHtml(t("sched_trigger_manual")) : ""}</span>
        <span class="sched-run-took">${escapeHtml(duration(run))}</span>
        ${run.error ? `<div class="sched-run-error">${escapeHtml(run.error)}</div>` : ""}
      `;
      if (run.sessionFile) {
        row.title = t("sched_open_conversation");
        row.addEventListener("click", () => {
          const sessionFile = run.sessionFile.split("/").pop();
          close();
          onOpenConversation(run.cwd || task.cwd, sessionFile);
        });
      }
      box.appendChild(row);
    }
  }

  // ---- form ------------------------------------------------------------------------------------

  async function renderForm(task) {
    const f = formFields(task);
    body.innerHTML = `
      <button class="sidebar-view-all sched-back">${escapeHtml(t("sched_back"))}</button>
      <form class="sched-form" novalidate>
        <label>${escapeHtml(t("sched_name"))}<input class="modal-input" name="name" required autocomplete="off" value="${escapeHtml(task?.name || "")}" placeholder="${escapeHtml(t("sched_name_placeholder"))}"></label>
        <label>${escapeHtml(t("sched_prompt"))}<textarea class="modal-input" name="prompt" rows="6" placeholder="${escapeHtml(t("sched_prompt_placeholder"))}">${escapeHtml(task?.prompt || "")}</textarea></label>
        <label>${escapeHtml(t("sched_folder"))}<input class="modal-input mono" name="cwd" list="sched-folders" autocomplete="off" value="${escapeHtml(task?.cwd || "")}"><datalist id="sched-folders"></datalist></label>
        <div class="sched-form-row">
          <label>${escapeHtml(t("sched_model"))}<select class="modal-input" name="model"><option value="">${escapeHtml(t("sched_default_model"))}</option></select></label>
          <label>${escapeHtml(t("sched_thinking"))}<select class="modal-input" name="thinkingLevel"><option value="">${escapeHtml(t("sched_default_thinking"))}</option>${LEVELS.map((l) => `<option value="${l}">${l}</option>`).join("")}</select></label>
        </div>
        <div class="sched-form-row">
          <label>${escapeHtml(t("sched_frequency"))}<select class="modal-input" name="frequency">
            ${["manual", "hourly", "daily", "weekdays", "weekly", "once", "custom"].map((x) => `<option value="${x}">${escapeHtml(t("sched_freq_" + x))}</option>`).join("")}
          </select></label>
          <label data-for="daily weekdays weekly">${escapeHtml(t("sched_time"))}<input class="modal-input" type="time" name="time" value="${f.time}"></label>
          <label data-for="weekly">${escapeHtml(t("sched_weekday"))}<select class="modal-input" name="weekday">${[1, 2, 3, 4, 5, 6, 0].map((d) => `<option value="${d}">${escapeHtml(weekdayName(d))}</option>`).join("")}</select></label>
          <label data-for="hourly">${escapeHtml(t("sched_minute"))}<input class="modal-input" type="number" min="0" max="59" name="minute" value="${f.minute}"></label>
          <label data-for="once">${escapeHtml(t("sched_when"))}<input class="modal-input" type="datetime-local" name="once" value="${f.once}"></label>
          <label data-for="custom">${escapeHtml(t("sched_cron"))}<input class="modal-input mono" name="cron" autocomplete="off" placeholder="0 9 * * 1-5" value="${escapeHtml(f.cron)}"></label>
        </div>
        <div class="sched-hint">${escapeHtml(t("sched_timezone", { tz: TIMEZONE }))}</div>
        <div class="sched-form-error sched-note error" hidden></div>
        <div class="sched-actions end">
          <button type="button" class="modal-btn sched-cancel">${escapeHtml(t("cancel"))}</button>
          <button type="submit" class="modal-btn primary">${escapeHtml(t("sched_save"))}</button>
        </div>
      </form>
    `;

    const form = body.querySelector("form");
    // `form.name` is the form's own name attribute, so fields are looked up by name explicitly.
    const field = (name) => form.elements.namedItem(name);
    const back = () => go(task ? { name: "detail", id: task.id } : { name: "list" });
    body.querySelector(".sched-back").addEventListener("click", back);
    body.querySelector(".sched-cancel").addEventListener("click", back);
    field("frequency").value = f.frequency;
    field("weekday").value = String(f.weekday);
    field("thinkingLevel").value = task?.thinkingLevel || "";

    const showFields = () => {
      for (const label of form.querySelectorAll("label[data-for]")) {
        label.hidden = !label.dataset.for.split(" ").includes(field("frequency").value);
      }
    };
    field("frequency").addEventListener("change", showFields);
    showFields();
    field("name").focus();

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const fields = Object.fromEntries(new FormData(form));
      const schedule = fields.frequency === "manual" ? "manual" : fields.frequency === "once" ? "once" : "cron";
      const payload = {
        id: task?.id,
        name: fields.name,
        prompt: fields.prompt,
        cwd: fields.cwd.trim(),
        schedule,
        cron: schedule === "cron" ? cronFor(fields) : null,
        runAt: schedule === "once" && fields.once ? new Date(fields.once).getTime() : null,
        timezone: TIMEZONE,
        model: fields.model || null,
        thinkingLevel: fields.thinkingLevel || null,
        enabled: task ? task.enabled : true,
      };
      const error = form.querySelector(".sched-form-error");
      try {
        const saved = await api("/api/scheduled/save", payload);
        // Asked here because saving is a click: a browser only asks when somebody has just done something.
        if ("Notification" in window && Notification.permission === "default") Notification.requestPermission();
        await reload();
        go({ name: "detail", id: saved.task.id });
      } catch (err) {
        error.textContent = err.message;
        error.hidden = false;
      }
    });

    // The two lists the form offers, filled once they arrive.
    try {
      const [folders, models] = await Promise.all([api("/api/folders"), api("/api/models")]);
      const list = form.querySelector("#sched-folders");
      for (const w of folders.workspaces || []) {
        const o = document.createElement("option");
        o.value = w.path;
        list.appendChild(o);
      }
      if (!field("cwd").value) field("cwd").value = folders.currentCwd || "";

      for (const m of models) {
        const o = document.createElement("option");
        o.value = `${m.provider}/${m.id}`;
        o.textContent = `${m.provider} / ${m.name || m.id}`;
        field("model").appendChild(o);
      }
      field("model").value = task?.model || "";
    } catch (err) {
      /* The fields still take typing; only the suggestions are missing. */
    }
  }

  const { modal, close: closeModal } = openModal({
    title: t("scheduled_tasks_title"),
    hideFooter: true,
    width: "min(720px, 94vw)",
    bodyHtml: `<div class="sched-body"></div>`,
    onCancel: cleanup,
    onOpen: (el) => { body = el.querySelector(".sched-body"); },
  });
  modalClose = closeModal;
  const close = () => { cleanup(); modalClose(); };
  modal.classList.add("sched-modal");

  unlisten = network.addMessageListener((m) => { if (m.type === "scheduled_update") reload(); });
  reload();
}

/**
 * Keeps the sidebar entry's dot and the browser notifications in step with the runs, whether or
 * not the panel is open. Returns the function that looks again.
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
