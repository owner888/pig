/**
 * Telling the person something while they are looking elsewhere — another window, another app,
 * the page in a background tab.
 *
 * Three signs that need no permission and work over plain http: the page title gains `(n) ✓`, the
 * tab's icon a dot, and a short chime plays. All three clear when the page is looked at again. On
 * top of them, where the browser allows it — a secure context, `localhost` or https, and the
 * person said yes — a system notification, which a click brings the conversation back from. Over
 * `http://` on a LAN address `Notification` does not exist at all, so it is simply not offered.
 *
 * What sets it off is an extension's `$ctx->ui->systemNotify()` (`examples/extensions/
 * system-notify.php` on a turn settling): the page decides only how to say it.
 */

const MARK = /^\(\d+\) ✓ /;
let unseen = 0;
let audio = null;
let icon = null;       // { link, plain, dotted }

const canNotify = () => window.isSecureContext && "Notification" in window;

/** Whether the person is looking at something other than this page. */
export const away = () => document.hidden || !document.hasFocus();

/**
 * Call from something the person did (sending a message): browsers let a page start sound, and
 * ask for notifications, only from a gesture.
 */
export function primeAttention() {
  try {
    audio ??= new (window.AudioContext || window.webkitAudioContext)();
    if (audio.state === "suspended") audio.resume();
  } catch {}
  if (canNotify() && Notification.permission === "default") {
    Notification.requestPermission().catch(() => {});
  }
}

/** Tell the person. Nothing happens when they are on the page already. */
export function notifyAway({ title, body, onOpen }) {
  if (!away()) return;
  unseen++;
  document.title = `(${unseen}) ✓ ${document.title.replace(MARK, "")}`;
  dot(true);
  chime();
  if (canNotify() && Notification.permission === "granted") {
    try {
      const n = new Notification(title, { body, tag: title, icon: "/favicon.svg" });
      n.onclick = () => { window.focus(); onOpen?.(); n.close(); };
    } catch {}
  }
}

function clear() {
  if (unseen === 0 || away()) return;
  unseen = 0;
  document.title = document.title.replace(MARK, "");
  dot(false);
}

function chime() {
  if (!audio || audio.state !== "running") return;
  try {
    const now = audio.currentTime;
    const gain = audio.createGain();
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.15, now + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.35);
    gain.connect(audio.destination);
    for (const [freq, at] of [[880, 0], [1320, 0.12]]) {
      const osc = audio.createOscillator();
      osc.frequency.value = freq;
      osc.connect(gain);
      osc.start(now + at);
      osc.stop(now + at + 0.2);
    }
  } catch {}
}

async function dot(on) {
  try {
    if (!icon) {
      const link = document.querySelector('link[rel="icon"]');
      if (!link) return;
      const svg = await (await fetch(link.href)).text();
      const dotted = svg.replace("</svg>", '<circle cx="80" cy="20" r="18" fill="#22C55E" stroke="#fff" stroke-width="6"/></svg>');
      icon = { link, plain: link.href, dotted: `data:image/svg+xml,${encodeURIComponent(dotted)}` };
    }
    icon.link.href = on && unseen > 0 ? icon.dotted : icon.plain;
  } catch {}
}

window.addEventListener("focus", clear);
document.addEventListener("visibilitychange", clear);
