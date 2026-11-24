/**
 * Whether the person is typing on an on-screen keyboard, for what Enter does in the prompt: there
 * Enter breaks the line and the send button sends, since glass has no Shift to break it with; with
 * a hardware keyboard — a desktop's, an iPad's — Enter sends, as in the terminal.
 *
 * No browser says which keyboard typed a key. An on-screen one takes a third of the screen or
 * more, which shows as the visual viewport shrinking against the tallest it has been at this width
 * (iOS keeps `innerHeight`; Android shrinks it with the keyboard, hence the tallest seen); a
 * hardware keyboard adds a shortcut bar at most.
 */

/** Below this share of the full height, the keyboard on screen is a typing one. */
const SHARE = 0.8;

const tallest = new Map(); // window.innerWidth → the tallest visual viewport seen at it

function height(viewport) {
  return viewport.height * viewport.scale;
}

function measure(win) {
  const viewport = win.visualViewport;
  if (!viewport) return;
  const width = win.innerWidth;
  tallest.set(width, Math.max(tallest.get(width) ?? 0, height(viewport)));
}

/** True on a touch screen with its keyboard up; there Enter is a new line. */
export function onScreenKeyboard(win = window) {
  if (!win.matchMedia("(hover: none) and (pointer: coarse)").matches) return false;
  const viewport = win.visualViewport;
  if (!viewport) return true;
  measure(win);
  const full = Math.max(tallest.get(win.innerWidth) ?? 0, win.innerHeight);
  return height(viewport) < full * SHARE;
}

/** Keep the tallest heights current; once, from the page. */
export function watchKeyboard(win = window) {
  measure(win);
  win.visualViewport?.addEventListener("resize", () => measure(win));
}
