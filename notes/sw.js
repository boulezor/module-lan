/*
 * Service worker de l'appli Notes (portée : dossier /notes/ uniquement).
 * - Fichiers statiques (assets/, versionnés par ?v=) : servis depuis le cache, sinon réseau.
 * - Pages : réseau d'abord ; sans connexion, page « Hors connexion ».
 * - API, fichiers envoyés, envois : jamais mis en cache.
 */
const CACHE = 'notes-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(['offline.html', 'assets/icon-192.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== location.origin) return;

    if (url.pathname.includes('/assets/')) {
        event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
            if (response.ok) {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => cache.put(request, copy));
            }
            return response;
        })));
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('offline.html')));
    }
});
