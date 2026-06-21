self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Pass-through to network (ensures app is installable without caching stale dynamic pages/assets)
    event.respondWith(fetch(event.request));
});
