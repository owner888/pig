/**
 * Shared physical WebSocket connection for multiplexed tabs.
 */

import { t } from "./i18n.js";

export const clientId = (() => {
  try {
    let v = sessionStorage.getItem("pig-client-id");
    if (!v) {
      v = Math.random().toString(36).slice(2) + Date.now().toString(36);
      sessionStorage.setItem("pig-client-id", v);
    }
    return v;
  } catch (e) {
    return Math.random().toString(36).slice(2);
  }
})();

/** How often the page looks at the clock to tell that the computer slept. */
const CLOCK_TICK = 5000;

/**
 * A gap between two looks at the clock longer than this is a sleep (or a tab the browser stopped
 * running for as long). Timers do not run while the computer sleeps, so the gap is the sleep.
 */
const SLEPT_AFTER = 30000;

/** Whether two looks at the clock `CLOCK_TICK` apart were really this far apart: a sleep between. */
export function sleptBetween(before, now) {
  return now - before > SLEPT_AFTER;
}

export class SharedNetwork {
  constructor() {
    this.ws = null;
    this.reconnectTimer = null;
    this.reconnectDelay = 1000;
    this.closed = false;
    this.messageListeners = new Set();
    this.closeListeners = new Set();

    // Callbacks provided by app.js / tabs
    this.getTabs = () => [];
    this.getActiveTab = () => null;
    this.showStatus = (msg) => {};
    this.hideStatus = () => {};
    this.onReachable = () => {};

    window.addEventListener("online", () => this.reconnectNow());
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState !== "visible") return;
      if (this.slept) this.restart();
      else this.reconnectNow();
    });

    // After a sleep the socket may still say OPEN and be dead — the server's half dropped by its
    // own heartbeat, or a network between them gone — and nothing tells the page until it writes:
    // the developer opened his laptop on a run that never moved again. A new connection costs
    // little and its `onBound()` reloads the conversation and asks the child what it is doing, so
    // a sleep is answered with one. While the page is hidden the browser may run its timers once a
    // minute, which looks the same; that is left until the page is looked at again.
    this.slept = false;
    this.lastTick = Date.now();
    setInterval(() => {
      const now = Date.now();
      if (sleptBetween(this.lastTick, now)) this.slept = true;
      this.lastTick = now;
      if (this.slept && document.visibilityState === "visible") this.restart();
    }, CLOCK_TICK);
  }

  /** A new connection whatever the old one says it is: after a sleep, OPEN is not to be believed. */
  restart() {
    this.slept = false;
    // The sleep is answered; the next connection's messages are measured from now.
    this.lastTick = Date.now();
    if (this.closed) return;
    if (this.ws) {
      try {
        this.ws.onopen = this.ws.onmessage = this.ws.onclose = this.ws.onerror = null;
        this.ws.close();
      } catch (e) {}
      this.ws = null;
      this.connectionLost();
    }
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    this.reconnectDelay = 1000;
    this.connect();
  }

  init({ getTabs, getActiveTab, showStatus, hideStatus, onReachable }) {
    if (getTabs) this.getTabs = getTabs;
    if (getActiveTab) this.getActiveTab = getActiveTab;
    if (showStatus) this.showStatus = showStatus;
    if (hideStatus) this.hideStatus = hideStatus;
    if (onReachable) this.onReachable = onReachable;
  }

  addMessageListener(fn) {
    this.messageListeners.add(fn);
    return () => this.messageListeners.delete(fn);
  }

  /** Called when the connection drops — what was waiting on an answer over it will not get one. */
  addCloseListener(fn) {
    this.closeListeners.add(fn);
    return () => this.closeListeners.delete(fn);
  }

  connectionLost() {
    // A connection refused or dropped because the session is gone (logged out elsewhere, a new
    // password) is a trip to the login page, not a reconnect loop.
    fetch("/api/login/state", { cache: "no-store" })
      .then((r) => r.json())
      .then((state) => { if (state.loggedIn === false) location.replace("/login"); })
      .catch(() => {});
    for (const t of this.getTabs()) t.onConnectionLost();
    for (const listener of this.closeListeners) {
      try { listener(); } catch (err) { console.error("Close listener error:", err); }
    }
  }

  connect() {
    if (this.closed) return;
    if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) return;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.ws) {
      try {
        this.ws.onopen = this.ws.onmessage = this.ws.onclose = this.ws.onerror = null;
        this.ws.close();
      } catch (e) {}
    }
    this.showStatus(t("connecting"));
    const protocol = location.protocol === "https:" ? "wss:" : "ws:";
    const ws = new WebSocket(`${protocol}//${location.host}/ws`);
    this.ws = ws;

    ws.onopen = () => {
      this.hideStatus();
      this.onReachable();
      this.reconnectDelay = 1000;
      // Resubscribe every tab currently open in the browser window over this shared connection
      const tabs = this.getTabs();
      for (const t of tabs) {
        t.subscribe();
      }
    };

    ws.onmessage = (e) => {
      // Read just after a sleep, a message is the first of what piled up while it lasted — a
      // frozen page measured busy for seconds per look drawing that backlog one event at a time.
      // Like Telegram's "too long" answer, the backlog is dropped with its connection and the new
      // one's `onBound()` loads the last page instead. Hidden, the page is not drawing anyway
      // (`catchup.js`), and the clock check waits until it is seen.
      if (document.visibilityState === "visible" && sleptBetween(this.lastTick, Date.now())) {
        this.restart();
        return;
      }
      let m;
      try {
        m = JSON.parse(e.data);
      } catch (err) {
        return;
      }

      for (const listener of this.messageListeners) {
        try {
          listener(m);
        } catch (err) {
          console.error("Message listener error:", err);
        }
      }

      const tabs = this.getTabs();
      if (m.tabId) {
        const target = tabs.find((t) => t.id === m.tabId);
        if (target) {
          target.onServerMessage(m);
          return;
        }
      }
      // Global error or message without tabId: deliver to active tab
      const active = this.getActiveTab();
      if (active) active.onServerMessage(m);
    };

    ws.onclose = () => {
      this.connectionLost();
      this.scheduleReconnect();
    };

    ws.onerror = () => this.connectionLost();
  }

  reconnectNow() {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      if (this.reconnectTimer) {
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = null;
      }
      this.reconnectDelay = 1000;
      this.connect();
    }
  }

  scheduleReconnect() {
    if (this.reconnectTimer || this.closed) return;
    this.showStatus(t("connecting"));
    const delay = this.reconnectDelay + Math.floor(Math.random() * 500);
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
    this.reconnectDelay = Math.min(this.reconnectDelay * 1.5, 10000);
  }

  send(obj) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(obj));
    }
  }
}

export const network = new SharedNetwork();
