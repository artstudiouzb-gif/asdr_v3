(function () {
    'use strict';
    var configNode = document.getElementById('push-config');
    var config = {};
    if (configNode) {
        try {
            config = JSON.parse(configNode.textContent || '{}');
        } catch (error) {
            config = {};
        }
    }
    if (!config.enabled || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        return;
    }

    var host = document.querySelector('[data-push-optin]') || document.querySelector('.site-footer__subscribe') || document.querySelector('.site-footer');
    if (Notification.permission === 'denied') { return; }

    function urlB64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) { out[i] = raw.charCodeAt(i); }
        return out;
    }

    var LABELS = config.labels || {};
    var LABEL_ON = LABELS.on || 'Уведомления включены';
    var LABEL_OFF = LABELS.off || 'Уведомления о новостях';
    // Подписи приходят переведёнными из разметки; литерал здесь — только
    // запасной вариант на случай устаревшего кэша страницы.
    var LABEL_PROMPT = LABELS.promptText || 'Будьте в курсе главных событий! Подпишитесь на мгновенные push-уведомления о новых публикациях.';
    var LABEL_ENABLE = LABELS.enable || 'Включить уведомления';
    var LABEL_LATER = LABELS.later || 'Позже';
    var renderIcon = window.asdrPublicIcon || function () { return ''; };
    var BELL_ICON = renderIcon('bell', 20);
    var CHECK_ICON = renderIcon('check', 18);

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var footerBtn = document.createElement('button');
    footerBtn.type = 'button';
    footerBtn.className = 'push-optin';

    var floatingBell = document.createElement('button');
    floatingBell.type = 'button';
    floatingBell.className = 'push-floating-bell';
    floatingBell.setAttribute('aria-label', LABEL_OFF);
    floatingBell.setAttribute('title', LABEL_OFF);
    floatingBell.innerHTML = BELL_ICON + '<span class="push-floating-bell__badge"></span>';

    var promptCard = document.createElement('div');
    promptCard.className = 'push-prompt-card';
    promptCard.hidden = true;
    promptCard.innerHTML =
        '<div class="push-prompt-card__header">' +
            '<span class="push-prompt-card__icon">' + BELL_ICON + '</span>' +
            '<span class="push-prompt-card__title">' + escapeHtml(LABEL_OFF) + '</span>' +
        '</div>' +
        '<p class="push-prompt-card__text">' + escapeHtml(LABEL_PROMPT) + '</p>' +
        '<div class="push-prompt-card__actions">' +
            '<button type="button" class="btn btn--small btn--primary" data-push-enable>' + escapeHtml(LABEL_ENABLE) + '</button>' +
            '<button type="button" class="btn btn--small btn--ghost" data-push-dismiss>' + escapeHtml(LABEL_LATER) + '</button>' +
        '</div>';

    document.body.appendChild(floatingBell);
    document.body.appendChild(promptCard);

    function setState(subscribed) {
        footerBtn.innerHTML = BELL_ICON + ' ' + escapeHtml(subscribed ? LABEL_ON : LABEL_OFF);
        footerBtn.classList.toggle('is-on', subscribed);
        footerBtn.setAttribute('aria-pressed', subscribed ? 'true' : 'false');

        floatingBell.classList.toggle('is-on', subscribed);
        floatingBell.innerHTML = (subscribed ? CHECK_ICON : BELL_ICON) + '<span class="push-floating-bell__badge"></span>';
        if (subscribed) {
            promptCard.hidden = true;
        }
    }

    navigator.serviceWorker.register('/push-sw.js').then(function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
            var isSubscribed = !!sub;
            setState(isSubscribed);

            if (host && !host.contains(footerBtn)) {
                host.appendChild(footerBtn);
            }

            var autoPrompt = config.autoPrompt !== false;
            var promptDelayMs = Math.max(1, (config.promptDelay || 15)) * 1000;

            var dismissedUntil = parseInt(localStorage.getItem('push_dismissed_until') || '0', 10);
            if (autoPrompt && !isSubscribed && Date.now() > dismissedUntil && Notification.permission !== 'denied') {
                // Отсчёт начинается с показа страницы, а не с её сборки: при
                // пререндере документ выполняется заранее и в скрытой вкладке
                // таймер истёк бы до прихода посетителя — карточка встретила
                // бы его уже открытой.
                var startPrompt = function () {
                    setTimeout(function () {
                        if (!isSubscribed) {
                            promptCard.hidden = false;
                        }
                    }, promptDelayMs);
                };
                if (window.asdrWhenActivated) { window.asdrWhenActivated(startPrompt); } else { startPrompt(); }
            }

            var toggleSubscription = function () {
                footerBtn.disabled = true;
                floatingBell.disabled = true;
                var enableBtn = promptCard.querySelector('[data-push-enable]');
                if (enableBtn) { enableBtn.disabled = true; }

                fetch('/push/key').then(function (response) {
                    if (!response.ok) { throw new Error('push unavailable'); }
                    return response.json();
                }).then(function (config) {
                    if (!config.csrf_token) { throw new Error('csrf unavailable'); }
                    return reg.pushManager.getSubscription().then(function (current) {
                        if (current) {
                            return fetch('/push/unsubscribe', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf_token },
                                body: JSON.stringify({ endpoint: current.endpoint })
                            }).then(function () { return current.unsubscribe(); }).then(function () { setState(false); });
                        }
                        return Notification.requestPermission().then(function (perm) {
                            if (perm !== 'granted') { return; }
                            return reg.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: urlB64ToUint8Array(config.key)
                            }).then(function (sub) {
                                return fetch('/push/subscribe', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf_token },
                                    body: JSON.stringify(sub.toJSON())
                                }).then(function () { setState(true); });
                            });
                        });
                    });
                }).catch(function () { /* пропуск ошибки */ })
                  .finally(function () {
                      footerBtn.disabled = false;
                      floatingBell.disabled = false;
                      if (enableBtn) { enableBtn.disabled = false; }
                  });
            };

            footerBtn.addEventListener('click', toggleSubscription);
            floatingBell.addEventListener('click', function () {
                if (promptCard.hidden) {
                    promptCard.hidden = false;
                } else {
                    toggleSubscription();
                }
            });

            promptCard.querySelector('[data-push-enable]').addEventListener('click', function () {
                toggleSubscription();
            });

            promptCard.querySelector('[data-push-dismiss]').addEventListener('click', function () {
                promptCard.hidden = true;
                localStorage.setItem('push_dismissed_until', String(Date.now() + 7 * 86400 * 1000));
            });
        });
    }).catch(function () { /* SW не зарегистрировался */ });
})();
