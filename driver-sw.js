const CACHE_NAME = 'cp-driver-cache-v1';

// Add the core files you want the driver app to load instantly
const ASSETS_TO_CACHE = [
    './img/pwa-icon-192.png',
    './img/pwa-icon-512.png',
    './login.php'
    // You can add your driver CSS or JS files here later
];

// 1. Install Event: Cache core assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        })
    );
    self.skipWaiting();
});

// 2. Activate Event: Clean up old caches if you update the version
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// 3. Fetch Event: Network-First Strategy
// It tries to get the newest data from the server. If the driver is offline, it falls back to the cache.
self.addEventListener('fetch', (event) => {
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request);
        })
    );
});