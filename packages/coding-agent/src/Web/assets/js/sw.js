/**
 * The page's service worker, for one thing: Android Chrome has no `Notification` constructor for a
 * page (it throws `TypeError: Illegal constructor`) and shows one only through
 * `ServiceWorkerRegistration.showNotification()` (`attention.js`).
 *
 * It controls no page — its scope is `/assets/js/` — and caches nothing. A click on a notification
 * brings back the pig tab looked at last and tells every pig tab which notification it was; the one
 * that sent it opens its conversation. With no pig tab left, it opens the page.
 */

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const id = event.notification.data.id;
  event.waitUntil((async () => {
    // Most recently focused first, per the spec; the page is not controlled, hence `includeUncontrolled`.
    const pages = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    if (pages.length === 0) return self.clients.openWindow("/");
    await pages[0].focus();
    for (const page of pages) page.postMessage({ type: "notificationclick", id });
  })());
});
