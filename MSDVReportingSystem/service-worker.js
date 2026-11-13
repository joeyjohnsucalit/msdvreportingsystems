const CACHE_NAME = 'msdv-v5';   // bump this number every time you deploy changes

// Every path here must exist. They must match your real files and manifest icons.
const urlsToCache = [
    './',
    './index.html',
    './login.html',
    './manifest.json',
    './assets/css/login.css',
    './assets/images/mccLogo.png',
    './assets/images/mcc.jpeg',
    './assets/images/mccLogo192.png',
    './assets/images/mccLogo512.png'
];

// Same-origin paths that must never be cached (live data, e.g. your API)
const NO_CACHE = ['/api/', 'login.php'];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            Promise.all(
                urlsToCache.map(url =>
                    cache.add(url).catch(err =>
                        console.warn('SW: could not cache', url, err)
                    )
                )
            )
        )
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(names =>
            Promise.all(
                names
                    .filter(name => name !== CACHE_NAME)
                    .map(name => caches.delete(name))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // Only handle same-origin GET requests
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;
    if (NO_CACHE.some(path => url.pathname.includes(path))) return;

    // Network first, so users always get the latest page.
    // If offline, fall back to the cache.
    event.respondWith(
        fetch(req)
            .then(response => {
                if (response && response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(req, copy));
                }
                return response;
            })
            .catch(() =>
                caches.match(req).then(cached =>
                    cached || (req.mode === 'navigate' ? caches.match('./index.html') : undefined)
                )
            )
    );
});
