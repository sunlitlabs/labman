// LabMan's service worker. It exists so the browser offers to install the app; it deliberately caches nothing,
// because a status page must never show old results or a signed-in page to the wrong person.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function (event) { event.respondWith(fetch(event.request)); });
