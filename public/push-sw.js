/* Service worker webpush: показывает уведомление и открывает ссылку по клику. */
'use strict';

self.addEventListener('push', function (event) {
    if (!event.data) { return; }
    var data = {};
    try { data = event.data.json(); } catch (e) { data = { title: event.data.text() }; }
    var title = data.title || 'Новое уведомление';
    var options = {
        body: data.body || '',
        icon: data.icon || '/favicon.ico',
        badge: data.badge || '/favicon.ico',
        image: data.image || null,
        tag: data.tag || 'site-news',
        vibrate: [100, 50, 100],
        data: { url: (data.data && data.data.url) || data.url || '/' },
        actions: [
            { action: 'open', title: 'Читать далее' }
        ]
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].url === url && 'focus' in list[i]) { return list[i].focus(); }
        }
        return clients.openWindow(url);
    }));
});
