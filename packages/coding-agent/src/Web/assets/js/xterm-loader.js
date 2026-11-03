/**
 * xterm, its fit addon and its stylesheet, fetched the first time a terminal is opened rather than
 * with the page: 344KB of the page's 894KB, for panels most visits never open. Both terminals — the
 * local one and a node's SSH one — come through here, so it is fetched once. A failed fetch is
 * forgotten, so the next open tries again.
 *
 * The modules are the official builds by their own names (`VendoredXtermTest`).
 */
let loading = null;

export function loadXterm() {
  loading ??= Promise.all([
    import("./vendor/xterm.mjs"),
    import("./vendor/addon-fit.mjs"),
    new Promise((resolve, reject) => {
      const link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = "/assets/css/vendor/xterm.css";
      link.onload = resolve;
      link.onerror = reject;
      document.head.appendChild(link);
    }),
  ]).then(([xterm, fit]) => ({ Terminal: xterm.Terminal, FitAddon: fit.FitAddon }))
    .catch((e) => { loading = null; throw e; });
  return loading;
}
