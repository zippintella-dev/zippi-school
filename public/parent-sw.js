/**
 * Zippi Parent — service worker.
 *
 * Deliberately minimal. It exists so the app is installable to the home screen
 * and so a dropped connection shows a real message instead of the browser's
 * dinosaur.
 *
 * ⚠ It does NOT cache trip data, and must not start. A parent looking at a
 * cached "on the bus" from twenty minutes ago is worse than a parent seeing an
 * error — this screen answers "where is my child right now", and a stale answer
 * to that question is a safety problem, not a UX one. Static assets only;
 * every /parent/* request goes to the network.
 */
const CACHE = 'zippi-parent-v1';
const SHELL = ['/css/zippi.css', '/css/parent.css', '/parent-icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Never serve a page or any trip data from cache.
  if (e.request.method !== 'GET' || url.pathname.startsWith('/parent')) return;

  // Static assets: cache-first, they are versioned by CACHE name.
  if (SHELL.includes(url.pathname)) {
    e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request)));
  }
});
