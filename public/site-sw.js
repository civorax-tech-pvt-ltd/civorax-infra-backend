// Service worker for the CivoraX site app (/site/app).
// Keeps a copy of the app page so it opens without internet; data and queued
// entries live in IndexedDB and are sent by the page itself when back online.
const CACHE = 'civorax-site-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('civorax-site-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.endsWith('/site/app')) {
        return;
    }

    // Network first so updates arrive; fall back to the saved copy when offline.
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response.ok && !response.redirected) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(url.pathname, copy));
                }

                return response;
            })
            .catch(() => caches.match(url.pathname).then((cached) => cached || new Response(
                '<h3 style="font-family:sans-serif;padding:24px">You are offline. Open the site app once while online so it can work offline.</h3>',
                { headers: { 'Content-Type': 'text/html' } },
            ))),
    );
});
