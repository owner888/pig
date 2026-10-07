/**
 * Shared physical WebSocket connection for multiplexed tabs.
 */

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

export class SharedNetwork {
  constructor() {
    this.ws = null;
    this.reconnectTimer = null;
    this.reconnectDelay = 1000;
    this.closed = false;
    this.messageListeners = new Set();

    // Callbacks provided by app.js / tabs
    this.getTabs = () => [];
    this.getActiveTab = () => null;
    this.showStatus = (msg) => {};
    this.hideStatus = () => {};
    this.onReachable = () => {};

    window.addEventListener("online", () => this.reconnectNow());
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") this.reconnectNow();
    });
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
    this.showStatus("Connecting to server...");
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
      let m;
      try {
        m = JSON.parse(e.data);
      } catch (err) {
        return;
      }
      if (m.type === "pong") return;

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
      const tabs = this.getTabs();
      for (const t of tabs) t.onConnectionLost();
      this.scheduleReconnect();
    };

    ws.onerror = () => {
      const tabs = this.getTabs();
      for (const t of tabs) t.onConnectionLost();
    };
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
    this.showStatus("Connecting to server...");
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
