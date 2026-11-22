/**
 * The page's service worker, for notifications only: Android Chrome has no `Notification`
 * constructor for a page (it throws `TypeError: Illegal constructor`) and shows one only through
 * `ServiceWorkerRegistration.showNotification()` (`attention.js`); and a push the server sends
 * while nobody's page is looked at (`HttpServer::pushAway()`) arrives here, the page frozen or gone.
 *
 * It controls no page — its scope is `/assets/js/` — and caches nothing. A click on a notification
 * brings back the pig tab looked at last. One a page showed is told to every pig tab, by its id, and
 * the one that showed it opens its conversation; a pushed one names its conversation, and the tab
 * brought back opens it. With no pig tab left, it opens the page.
 */

self.addEventListener("push", (event) => {
  // Every push is shown: iOS takes one that shows nothing as a reason to drop the subscription.
  const message = event.data ? event.data.json() : {};
  const title = message.title || "pig";
  const tag = message.tag || title;
  event.waitUntil(self.registration.showNotification(title, {
    body: message.body || "",
    tag,
    renotify: true,
    icon: "/favicon.svg",
    data: { cwd: message.cwd ?? null, sessionFile: message.sessionFile ?? null },
  }));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const data = event.notification.data;
  event.waitUntil((async () => {
    // Most recently focused first, per the spec; the page is not controlled, hence `includeUncontrolled`.
    const pages = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    if (pages.length === 0) return self.clients.openWindow("/");
    await pages[0].focus();
    const told = data.id != null ? pages : [pages[0]];
    for (const page of told) page.postMessage({ type: "notificationclick", ...data });
  })());
});
