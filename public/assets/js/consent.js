(function () {
    'use strict';

    var cfg = { required: false, privacyUrl: '' };
    var configNode = document.getElementById('consent-config');
    if (configNode) {
        try {
            cfg = JSON.parse(configNode.textContent || '{}');
        } catch (error) {
            cfg = { required: false, privacyUrl: '' };
        }
    }
    var COOKIE = 'cookie_consent';

    function hasConsent() {
        return document.cookie.split(';').some(function (c) {
            return c.trim().indexOf(COOKIE + '=1') === 0;
        });
    }
    function setConsent() {
        var d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.cookie = COOKIE + '=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
    }

    // Активирует инертный код счётчиков (перенос в исполняемый <script>).
    function runAnalytics() {
        var holder = document.getElementById('analytics-init');
        if (!holder || holder.dataset.done === '1') { return; }
        holder.dataset.done = '1';
        var s = document.createElement('script');
        // CSP: создаваемый инлайн-скрипт наследует nonce держателя, иначе
        // политика script-src его заблокирует.
        if (holder.nonce) { s.nonce = holder.nonce; }
        s.text = holder.textContent || '';
        document.head.appendChild(s);
    }

    function showBanner() {
        var bar = document.createElement('div');
        bar.className = 'cookie-banner';
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', 'Согласие на использование cookie');
        var text = document.createElement('span');
        text.className = 'cookie-banner__text';
        text.textContent = 'Мы используем cookie для аналитики. Продолжая, вы соглашаетесь с их использованием.';
        if (cfg.privacyUrl) {
            var link = document.createElement('a');
            link.href = cfg.privacyUrl;
            link.textContent = ' Политика конфиденциальности';
            text.appendChild(link);
        }
        var accept = document.createElement('button');
        accept.type = 'button';
        accept.className = 'cookie-banner__accept';
        accept.textContent = 'Принять';
        accept.addEventListener('click', function () {
            setConsent();
            runAnalytics();
            bar.parentNode && bar.parentNode.removeChild(bar);
        });
        var decline = document.createElement('button');
        decline.type = 'button';
        decline.className = 'cookie-banner__decline';
        decline.textContent = 'Отклонить';
        decline.addEventListener('click', function () {
            bar.parentNode && bar.parentNode.removeChild(bar);
        });
        bar.appendChild(text);
        bar.appendChild(accept);
        bar.appendChild(decline);
        document.body.appendChild(bar);
    }

    if (!cfg.required) {
        // Согласие не требуется — грузим счётчики сразу.
        runAnalytics();
        return;
    }
    if (hasConsent()) {
        runAnalytics();
    } else {
        document.addEventListener('DOMContentLoaded', showBanner);
        if (document.readyState !== 'loading') { showBanner(); }
    }
})();
