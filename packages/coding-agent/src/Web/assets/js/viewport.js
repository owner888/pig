/**
 * The page is as tall as what can be seen of it.
 *
 * `100vh` on iPad Safari is the whole screen. When a field is focused, the keyboard — or, with a
 * hardware keyboard, the floating input bar (input mode, ↑ ↓, dictation) — covers the bottom of it,
 * and Safari scrolls the page up to keep the field in sight, taking the header with it. So the page
 * takes its height from `visualViewport` (`--app-height`) and stays scrolled to the top: the prompt
 * sits just above whatever covers the bottom, and the header stays where it is.
 */
const vv = window.visualViewport;

function fit() {
  document.documentElement.style.setProperty("--app-height", `${Math.round(vv.height)}px`);
  // What Safari scrolled to bring the field into view; with the page fitting, nothing needs to be.
  if (window.scrollY !== 0 || vv.offsetTop !== 0) window.scrollTo(0, 0);
}

if (vv) {
  vv.addEventListener("resize", fit);
  vv.addEventListener("scroll", fit);
  fit();
}
