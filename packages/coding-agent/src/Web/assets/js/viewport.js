/**
 * The page is as tall as what can be seen of it — unless all that is hidden is a thin bar.
 *
 * `100vh` on iPad Safari is the whole screen. When a field is focused, the on-screen keyboard
 * covers the bottom half of it, and Safari scrolls the page up to keep the field in sight, taking
 * the header with it. So the page takes its height from `visualViewport` (`--app-height`) and
 * stays scrolled to the top: the prompt sits just above the keyboard, and the header stays put.
 *
 * With a hardware keyboard, what covers the bottom is only the floating input bar (input mode,
 * ↑ ↓, dictation), a strip a few dozen pixels tall. Shrinking the page for it lifted the prompt
 * every time it was focused. For that the page keeps its full height and lets the bar sit over its
 * last row, the selectors and the numbers, which are under the prompt for this reason
 * (index.html). Anything as tall as `KEYBOARD` is a keyboard, and is made room for as before.
 *
 * Two things made the page shake with the bar:
 * - **The bar slides in**, so `visualViewport` reports a few heights on the way. Any of them under
 *   a threshold meant "shrink", the next one "full height" — a jump on every focus. Everything
 *   short of a keyboard is the full height now, with no lower bound.
 * - **Safari keeps the field 40px clear of what covers the screen** (`MARGIN`). Closer than that,
 *   it panned the page up as the bar came up and back a frame later — on every focus — and
 *   scrolled it on every keystroke, which `fit()` scrolled back. The prompt is now raised by what
 *   that takes (`--bar-lift`), and by nothing when it is clear already, so Safari has nothing to
 *   reveal.
 *
 * That lift, made the moment the bar came up, was itself the prompt moving on every focus. So it
 * is remembered (`LIFT_KEY`, this browser) and kept while the prompt is not focused too: the room
 * for the bar is made once, the first time it is measured, and after that focusing moves nothing.
 * The on-screen keyboard resizes the page anyway, and there the lift is 0.
 */
const vv = window.visualViewport;
const root = document.documentElement;
const KEYBOARD = 150;
// Measured on an iPad in Safari, with a `?vvdebug` overlay since removed: with the bar up
// the visible height was 770 and Safari panned the page whenever the prompt's bottom edge was
// lower than 40px above that (731 panned, 730 did not).
const MARGIN = 40;
const LIFT_KEY = "pig_bar_lift";

let kept = 0;
try { kept = Math.max(0, Math.min(KEYBOARD, parseInt(localStorage.getItem(LIFT_KEY) || "0", 10) || 0)); } catch {}

/** What the bar overlaps of the focused prompt now, with its margin; 0 when it is not focused. */
function overlap(covered) {
  const input = document.getElementById("prompt-input");
  if (covered <= 0 || !input || document.activeElement !== input) return 0;
  // Where the prompt's bottom edge would be without the current lift.
  const current = parseFloat(root.style.getPropertyValue("--bar-lift")) || 0;
  const bottom = input.getBoundingClientRect().bottom + current;
  return Math.max(0, Math.ceil(bottom + MARGIN - (vv.offsetTop + vv.height)));
}

function fit() {
  // The screen's height. Not `innerHeight` alone: as the bar comes up Safari reports, for a frame,
  // `innerHeight` shrunk to what is visible *and* the visible part panned down by the bar's height
  // (measured: inner=770 vv=770@68, then inner=838 vv=770@0). Taking that
  // `innerHeight` shrank the page for a frame, which was the whole page jumping on focus.
  const full = Math.max(window.innerHeight, vv.height + vv.offsetTop);
  const covered = full - vv.height;
  const keyboard = covered >= KEYBOARD;
  root.style.setProperty("--app-height", `${Math.round(keyboard ? vv.height : full)}px`);
  // What Safari scrolled to bring the field into view; with the page fitting, nothing needs to be.
  // First, so the prompt is measured where it will be seen.
  if (window.scrollY !== 0 || vv.offsetTop !== 0) window.scrollTo(0, 0);
  if (!keyboard) {
    const needed = overlap(covered);
    if (needed > kept) {
      kept = needed;
      try { localStorage.setItem(LIFT_KEY, String(kept)); } catch {}
    }
  }
  root.style.setProperty("--bar-lift", `${keyboard ? 0 : kept}px`);
}

if (vv) {
  vv.addEventListener("resize", fit);
  vv.addEventListener("scroll", fit);
  // The bar can be up before the prompt has focus (another field had it), and focus moves without
  // the viewport changing.
  document.addEventListener("focusin", fit);
  document.addEventListener("focusout", () => setTimeout(fit, 0));
  fit();
}

