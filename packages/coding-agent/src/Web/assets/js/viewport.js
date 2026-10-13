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
 * (index.html). Anything taller than `BAR` is a keyboard, and is made room for as before.
 */
const vv = window.visualViewport;
const BAR = 150;

function fit() {
  // A few pixels are a scrollbar or rounding, not a bar.
  const covered = window.innerHeight - vv.height;
  const height = covered >= 24 && covered < BAR ? window.innerHeight : vv.height;
  document.documentElement.style.setProperty("--app-height", `${Math.round(height)}px`);
  // What Safari scrolled to bring the field into view; with the page fitting, nothing needs to be.
  if (window.scrollY !== 0 || vv.offsetTop !== 0) window.scrollTo(0, 0);
}

if (vv) {
  vv.addEventListener("resize", fit);
  vv.addEventListener("scroll", fit);
  fit();
}
