(function () {
    'use strict';

    var SUMMARY_URL = '/admin/notifications/summary';
    var CENTER_URL = '/admin/notifications';

    function bellIcon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icon-tabler-bell" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 5a2 2 0 0 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6"/><path d="M9 17v1a3 3 0 0 0 6 0v-1"/></svg>';
    }

    function loadStylesheet() {
        if (document.querySelector('link[data-admin-notifications-css]')) return;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/assets/css/admin-notifications.css';
        link.dataset.adminNotificationsCss = '1';
        document.head.appendChild(link);
    }

    function createShell() {
        var topbar = document.querySelector('.admin-topbar');
        var user = document.querySelector('.admin-user');
        if (!topbar || !user || document.querySelector('[data-notification-bell]')) return null;

        var root = document.createElement('div');
        root.className = 'admin-notification-bell';
        root.dataset.notificationBell = '1';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-notification-bell__button';
        button.setAttribute('aria-label', 'Уведомления');
        button.setAttribute('aria-expanded', 'false');
        button.innerHTML = bellIcon();

        var count = document.createElement('span');
        count.className = 'admin-notification-bell__count';
        count.hidden = true;
        button.appendChild(count);

        var panel = document.createElement('section');
        panel.className = 'admin-notification-bell__panel';
        panel.hidden = true;
        panel.setAttribute('aria-label', 'Последние уведомления');

        var head = document.createElement('div');
        head.className = 'admin-notification-bell__head';
        var title = document.createElement('strong');
        title.textContent = 'Уведомления';
        var status = document.createElement('span');
        status.textContent = 'Загрузка…';
        head.appendChild(title);
        head.appendChild(status);

        var list = document.createElement('div');
        list.className = 'admin-notification-bell__list';

        var foot = document.createElement('div');
        foot.className = 'admin-notification-bell__foot';
        var all = document.createElement('a');
        all.href = CENTER_URL;
        all.textContent = 'Открыть центр уведомлений';
        foot.appendChild(all);

        panel.appendChild(head);
        panel.appendChild(list);
        panel.appendChild(foot);
        root.appendChild(button);
        root.appendChild(panel);
        topbar.insertBefore(root, user);

        return { root: root, button: button, count: count, panel: panel, list: list, status: status };
    }

    function setCount(ui, value, pendingAck) {
        var total = Math.max(0, Number(value) || 0);
        ui.count.hidden = total === 0;
        ui.count.textContent = total > 99 ? '99+' : String(total);
        ui.button.setAttribute('aria-label', total > 0 ? 'Уведомления: ' + total + ' непрочитанных' : 'Уведомления');
        ui.status.textContent = total > 0 ? total + ' непрочит.' : 'Всё прочитано';
        if ((Number(pendingAck) || 0) > 0) {
            ui.status.textContent += ' · ' + pendingAck + ' требуют подтверждения';
        }
    }

    function safeAdminUrl(value) {
        return typeof value === 'string' && /^\/admin(?:\/|$)/.test(value) ? value : CENTER_URL;
    }

    function postAction(url, csrf) {
        var data = new URLSearchParams();
        data.set('_csrf', csrf || '');
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: data.toString()
        });
    }

    function renderItems(ui, payload) {
        ui.list.textContent = '';
        var items = Array.isArray(payload.items) ? payload.items : [];
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'admin-notification-bell__empty';
            empty.textContent = 'Новых событий нет.';
            ui.list.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            var link = document.createElement('a');
            link.href = safeAdminUrl(item.action_url);
            link.className = 'admin-notification-bell__item' + (item.read ? '' : ' is-unread');
            link.dataset.severity = String(item.severity || 'info');

            var dot = document.createElement('span');
            dot.className = 'admin-notification-bell__dot';
            dot.setAttribute('aria-hidden', 'true');

            var copy = document.createElement('span');
            copy.className = 'admin-notification-bell__copy';
            var title = document.createElement('strong');
            title.textContent = String(item.title || 'Уведомление');
            var message = document.createElement('span');
            message.textContent = String(item.message || '');
            var time = document.createElement('time');
            time.textContent = String(item.created_at || '');
            copy.appendChild(title);
            copy.appendChild(message);
            copy.appendChild(time);
            link.appendChild(dot);
            link.appendChild(copy);

            if (!item.read && item.id) {
                link.addEventListener('click', function (event) {
                    var destination = link.href;
                    event.preventDefault();
                    postAction('/admin/notifications/' + encodeURIComponent(item.id) + '/read', payload.csrf)
                        .catch(function () {})
                        .finally(function () { window.location.href = destination; });
                });
            }
            ui.list.appendChild(link);
        });
    }

    function refresh(ui) {
        return fetch(SUMMARY_URL, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.ok !== true) throw new Error('Unavailable');
                setCount(ui, payload.unread, payload.pending_ack);
                renderItems(ui, payload);
            })
            .catch(function () {
                ui.status.textContent = 'Недоступно';
                ui.status.classList.add('admin-notification-bell__error');
                ui.list.textContent = '';
                var error = document.createElement('div');
                error.className = 'admin-notification-bell__empty admin-notification-bell__error';
                error.textContent = 'Не удалось загрузить уведомления.';
                ui.list.appendChild(error);
            });
    }

    function init() {
        loadStylesheet();
        var ui = createShell();
        if (!ui) return;

        ui.button.addEventListener('click', function () {
            var open = ui.panel.hidden;
            ui.panel.hidden = !open;
            ui.root.classList.toggle('is-open', open);
            ui.button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) refresh(ui);
        });

        document.addEventListener('click', function (event) {
            if (!ui.panel.hidden && !ui.root.contains(event.target)) {
                ui.panel.hidden = true;
                ui.root.classList.remove('is-open');
                ui.button.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !ui.panel.hidden) {
                ui.panel.hidden = true;
                ui.root.classList.remove('is-open');
                ui.button.setAttribute('aria-expanded', 'false');
                ui.button.focus();
            }
        });

        refresh(ui);
        window.setInterval(function () { refresh(ui); }, 60000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
