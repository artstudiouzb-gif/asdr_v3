/**
 * Zero-Latency Predictive Hover Prefetching (Enterprise Navigation Engine)
 * Предзагружает внутренние страницы за 100-200 мс до физического клика.
 */
(function initInstantPrefetch() {
    if (typeof window === 'undefined' || !document.addEventListener) {
        return;
    }

    var prefetched = new Set();
    var parser = document.createElement('a');

    function isEligible(anchor) {
        if (!anchor || !anchor.href) return false;
        parser.href = anchor.href;

        // Только свой хост и протокол HTTP(S)
        if (parser.origin !== window.location.origin) return false;
        if (parser.protocol !== 'http:' && parser.protocol !== 'https:') return false;

        var path = parser.pathname;

        // Пропускаем админку, репозиторий, служебные URL и скачивание файлов
        if (path.startsWith('/admin') || path.startsWith('/repo') || path.startsWith('/install') || path === '/logout') {
            return false;
        }
        if (/\.(pdf|zip|docx?|xlsx?|pptx?|jpg|png|webp|svg|mp4)$/i.test(path)) {
            return false;
        }

        // Не предзагружаем текущую страницу
        if (path === window.location.pathname && parser.search === window.location.search) {
            return false;
        }

        return true;
    }

    function prefetch(url) {
        if (prefetched.has(url)) return;
        prefetched.add(url);

        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        link.as = 'document';
        document.head.appendChild(link);
    }

    function handleEvent(event) {
        var anchor = event.target.closest ? event.target.closest('a') : null;
        if (!anchor || !isEligible(anchor)) return;

        prefetch(anchor.href);
    }

    // Слушаем наведение мыши на десктопах и касание на смартфонах
    document.addEventListener('mouseover', handleEvent, { passive: true });
    document.addEventListener('touchstart', handleEvent, { passive: true });
})();
