// Service worker : affiche les rappels push du planning et ouvre l'appli au clic.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data.text() }; }
    event.waitUntil(self.registration.showNotification(data.title || 'LAN', {
        body: data.body || '',
        icon: 'assets/icon-192.png',
        badge: 'assets/badge-96.png',
        tag: data.tag || 'lan',
        renotify: true,
        vibrate: [200, 100, 200],
        data: { url: data.url || 'planning.php' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || 'planning.php', self.registration.scope).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const client of windows) {
            if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
                return client.focus().then((c) => c.navigate ? c.navigate(url) : c);
            }
        }
        return self.clients.openWindow(url);
    }));
});
