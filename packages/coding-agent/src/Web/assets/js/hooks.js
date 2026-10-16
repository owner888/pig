/**
 * What an extension's `$ctx->ui` tells the page, kept per conversation — the parts of
 * `TerminalUi` that are state rather than a dialog: the widgets above and below the prompt
 * (`setWidget`), the footer statuses (`setStatus`) and the title (`setTitle`). `app.js` draws
 * them; this file only decides what there is to draw, so it can be run without a page.
 *
 * The requests are `RpcUi`'s, which are upstream's `extension_ui_request` lines, and one of
 * pig's own: `setTheme`, which the page applies to itself (`app.js`).
 *
 * Widget and status text keeps its escape sequences, and `ansiToHtml()` draws them as the
 * terminal would. The child draws with the page's theme — the page tells it which (`set_theme`)
 * — so a status coloured with `theme()->fg('accent', …)` is the page's accent.
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
        lines: req.widgetLines.map((line) => String(line)),
        placement: req.widgetPlacement === "belowEditor" ? "below" : "above",
      });
    } else {
      state.widgets.delete(req.widgetKey);
    }
    return "widgets";
  }
  if (req.method === "setStatus") {
    if (typeof req.statusText === "string" && req.statusText !== "") state.statuses.set(req.statusKey, req.statusText);
    else state.statuses.delete(req.statusKey);
    return "statuses";
  }
  if (req.method === "setTitle") {
    state.title = typeof req.title === "string" && req.title !== "" ? stripAnsi(req.title) : null;
    return "title";
  }
  return null;
}

/** The widgets on one side of the prompt, in the order they were first set, escapes and all. */
export function widgetsAt(state, placement) {
  return [...state.widgets.entries()]
    .filter(([, widget]) => widget.placement === placement)
    .map(([key, widget]) => ({ key, lines: widget.lines }));
}

/** The statuses joined as the footer shows them, as text. */
export function statusLine(state) {
  return sortedStatuses(state).map(stripAnsi).join(" ");
}

/** The statuses joined as the footer shows them, coloured, as HTML. */
export function statusHtml(state) {
  return sortedStatuses(state).map(ansiToHtml).join(" ");
}

/** Upstream's footer order: by key. */
function sortedStatuses(state) {
  return [...state.statuses.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([, text]) => text);
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
 * Text with its terminal styling taken off, for where there is no styling to be had: the page
 * title. CSI (`\x1b[…X`) and OSC (`\x1b]…` ended by BEL or ST) — what `Pig\Tui\Ansi` produces.
 */
export function stripAnsi(text) {
  return String(text).replace(/\x1b\[[0-?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, "");
}

/** CSI and OSC, as `stripAnsi()` matches them, with a CSI's parameters and final byte captured. */
const ESCAPE = /\x1b\[([0-?]*)[ -/]*([@-~])|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g;

/**
 * The sixteen terminal colours as the page's theme tokens — the palette `terminalTheme()` gives
 * the page's terminals, so `\x1b[31m` is the same red here as there.
 */
const BASIC = ["surface", "error", "success", "warning", "info", "accent", "syntax-variable", "text-primary"];
function basicColour(index) {
  if (index === 8) return "var(--color-dim)";
  return `var(--color-${BASIC[index % 8]})`;
}

/** xterm's 256 colours: the sixteen, the 6×6×6 cube, the 24 greys. */
function colour256(n) {
  if (n < 16) return basicColour(n);
  if (n < 232) {
    const level = (v) => (v === 0 ? 0 : 55 + v * 40);
    const i = n - 16;
    return `rgb(${level(Math.floor(i / 36))}, ${level(Math.floor(i / 6) % 6)}, ${level(i % 6)})`;
  }
  const grey = 8 + (n - 232) * 10;
  return `rgb(${grey}, ${grey}, ${grey})`;
}

const escapeText = (text) => text.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

/**
 * Text with terminal styling drawn as HTML: SGR's colours (the sixteen, 256 and truecolor, in
 * either slot), bold, dim, italic, underline, inverse and strikethrough — what `Theme`'s `fg()`,
 * `bg()` and `bold()` and `Pig\Tui\Ansi` write. The text is escaped; every other escape
 * sequence (cursor movement, an OSC 8 link) is dropped and its text kept.
 */
export function ansiToHtml(text) {
  const style = { fg: null, bg: null, bold: false, dim: false, italic: false, underline: false, inverse: false, strike: false };
  const reset = () => Object.assign(style, { fg: null, bg: null, bold: false, dim: false, italic: false, underline: false, inverse: false, strike: false });
  let html = "";
  let at = 0;

  const emit = (chunk) => {
    if (chunk === "") return;
    let fg = style.fg, bg = style.bg;
    if (style.inverse) { [fg, bg] = [bg ?? "var(--color-background)", fg ?? "var(--color-text-primary)"]; }
    const css = [];
    if (fg) css.push(`color:${fg}`);
    if (bg) css.push(`background-color:${bg}`);
    if (style.bold) css.push("font-weight:700");
    if (style.dim) css.push("opacity:0.6");
    if (style.italic) css.push("font-style:italic");
    const lines = [style.underline && "underline", style.strike && "line-through"].filter(Boolean);
    if (lines.length) css.push(`text-decoration:${lines.join(" ")}`);
    html += css.length ? `<span style="${css.join(";")}">${escapeText(chunk)}</span>` : escapeText(chunk);
  };

  const sgr = (params) => {
    const codes = params === "" ? [0] : params.split(/[;:]/).map((p) => (p === "" ? 0 : Number(p)));
    for (let i = 0; i < codes.length; i++) {
      const c = codes[i];
      if (c === 0) reset();
      else if (c === 1) style.bold = true;
      else if (c === 2) style.dim = true;
      else if (c === 3) style.italic = true;
      else if (c === 4) style.underline = true;
      else if (c === 7) style.inverse = true;
      else if (c === 9) style.strike = true;
      else if (c === 22) { style.bold = false; style.dim = false; }
      else if (c === 23) style.italic = false;
      else if (c === 24) style.underline = false;
      else if (c === 27) style.inverse = false;
      else if (c === 29) style.strike = false;
      else if (c >= 30 && c <= 37) style.fg = basicColour(c - 30);
      else if (c >= 90 && c <= 97) style.fg = basicColour(c - 90 + 8);
      else if (c === 39) style.fg = null;
      else if (c >= 40 && c <= 47) style.bg = basicColour(c - 40);
      else if (c >= 100 && c <= 107) style.bg = basicColour(c - 100 + 8);
      else if (c === 49) style.bg = null;
      else if (c === 38 || c === 48) {
        let value = null;
        if (codes[i + 1] === 5 && i + 2 < codes.length) { value = colour256(codes[i + 2] & 255); i += 2; }
        else if (codes[i + 1] === 2 && i + 4 < codes.length) {
          const [r, g, b] = codes.slice(i + 2, i + 5).map((v) => Math.max(0, Math.min(255, v | 0)));
          value = `rgb(${r}, ${g}, ${b})`;
          i += 4;
        } else break;
        if (c === 38) style.fg = value; else style.bg = value;
      }
    }
  };

  const source = String(text);
  for (const match of source.matchAll(ESCAPE)) {
    emit(source.slice(at, match.index));
    at = match.index + match[0].length;
    if (match[2] === "m") sgr(match[1]);
  }
  emit(source.slice(at));
  return html;
}
