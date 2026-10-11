/**
 * What an extension's `$ctx->ui` tells the page, kept per conversation — the parts of
 * `TerminalUi` that are state rather than a dialog: the widgets above and below the prompt
 * (`setWidget`), the footer statuses (`setStatus`) and the title (`setTitle`). `app.js` draws
 * them; this file only decides what there is to draw, so it can be run without a page.
 *
 * The requests are `RpcUi`'s, which are upstream's `extension_ui_request` lines.
 */

/** A fresh state for one tab: what its child's extensions have put on screen. */
export function createHookState() {
  return { widgets: new Map(), statuses: new Map(), title: null };
}

/**
 * Apply one told-not-asked request to a tab's state. Answers which part changed —
 * `"widgets"`, `"statuses"` or `"title"` — or null for a request that is not one of these.
 *
 * A widget's placement is upstream's `aboveEditor` / `belowEditor`; one that names none is
 * above, as `TerminalUi::setWidget()` puts it. A request with no lines (or no status text)
 * clears the key, as upstream's `undefined` does.
 */
export function applyHookRequest(state, req) {
  if (req.method === "setWidget") {
    if (Array.isArray(req.widgetLines)) {
      // A key set again keeps its place, as it does in `TerminalUi`'s array.
      state.widgets.set(req.widgetKey, {
        lines: req.widgetLines.map((line) => stripAnsi(String(line))),
        placement: req.widgetPlacement === "belowEditor" ? "below" : "above",
      });
    } else {
      state.widgets.delete(req.widgetKey);
    }
    return "widgets";
  }
  if (req.method === "setStatus") {
    if (typeof req.statusText === "string" && req.statusText !== "") state.statuses.set(req.statusKey, stripAnsi(req.statusText));
    else state.statuses.delete(req.statusKey);
    return "statuses";
  }
  if (req.method === "setTitle") {
    state.title = typeof req.title === "string" && req.title !== "" ? stripAnsi(req.title) : null;
    return "title";
  }
  return null;
}

/** The widgets on one side of the prompt, in the order they were first set. */
export function widgetsAt(state, placement) {
  return [...state.widgets.entries()]
    .filter(([, widget]) => widget.placement === placement)
    .map(([key, widget]) => ({ key, lines: widget.lines }));
}

/** The statuses joined as the footer shows them. */
export function statusLine(state) {
  return [...state.statuses.values()].join(" · ");
}

/**
 * `notify`'s level, as `TerminalUi::notify()` draws it: an error is the red `Error:` line, a
 * warning the `Warning:` line, anything else the dim `say()` line — all three in the transcript.
 */
export function notifyKind(req) {
  const level = req.notifyType || "info";
  return level === "error" ? "error" : level === "warning" ? "warning" : "notice";
}

/** The dialog heading while a timeout runs — `TerminalUi::countdown()`'s `"{title} ({n}s)"`. */
export function countdownTitle(title, remainingSeconds) {
  return `${title} (${remainingSeconds}s)`;
}

/** Seconds a dialog has, from the request's `timeout` in milliseconds; null for none. */
export function timeoutSeconds(req) {
  return typeof req.timeout === "number" && req.timeout > 0 ? Math.ceil(req.timeout / 1000) : null;
}

/**
 * `get_commands`' answer as completion rows: the extensions' commands, the prompt templates and
 * the `skill:` commands — everything the TUI's `/` list offers beyond the built-ins.
 */
export function slashCommandsFrom(data) {
  const commands = Array.isArray(data?.commands) ? data.commands : [];
  return commands
    .filter((c) => c && typeof c.name === "string" && c.name !== "")
    .map((c) => ({ name: c.name, description: typeof c.description === "string" ? c.description : "", source: c.source || "" }));
}

/**
 * Text with its terminal styling taken off. An extension that colours a status with
 * `theme()->fg()` sends escape sequences meant for a terminal; drawn as text they are noise.
 * CSI (`\x1b[…X`) and OSC (`\x1b]…` ended by BEL or ST) — what `Pig\Tui\Ansi` produces.
 */
export function stripAnsi(text) {
  return String(text).replace(/\x1b\[[0-?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, "");
}
