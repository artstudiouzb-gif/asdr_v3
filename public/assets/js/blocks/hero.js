/**
 * Hero runtime: media sessions, carousel state and browser lifecycle.
 * Each instance owns its timers and pause reasons. No automatic restart after
 * keyboard focus or manual navigation (WAI-ARIA carousel pattern).
 * Server-rendered content and posters remain useful without this enhancement.
 */
(function () {
    'use strict';

    var MOBILE_QUERY = '(max-width: 720px)';
    var instances = new WeakMap();
    var videoSessions = new WeakMap();
    var youtubeSessions = new WeakMap();

    function reduceMotion() {
        return window.asdrReduceMotion ? window.asdrReduceMotion()
            : window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function isMobile() { return window.matchMedia(MOBILE_QUERY).matches; }
    function pad(n) { return String(n).padStart(2, '0'); }

    function videoAllowed(el) {
        return !reduceMotion() && !document.hidden
            && !(navigator.connection && navigator.connection.saveData)
            && !(isMobile() && el.getAttribute('data-hero-mobile-media') === 'image');
    }

    // A play() promise belongs to one activation. A rejected old attempt must
    // never hide a video that has already been started by a newer session.
    function startVideo(video) {
        if (!videoAllowed(video)) { stopVideo(video); return; }
        if (videoSessions.has(video)) { return; }
        var session = new AbortController();
        videoSessions.set(video, session);
        var source = video.querySelector('source');
        if (source && !source.hasAttribute('src')) {
            var mobile = video.getAttribute('data-hero-video-mobile');
            source.src = mobile && isMobile()
                && video.getAttribute('data-hero-mobile-media') === 'mobile_video'
                ? mobile : source.getAttribute('data-hero-src');
            video.load();
        }
        function fail() {
            if (videoSessions.get(video) === session) { stopVideo(video); }
        }
        video.addEventListener('error', fail, { capture: true, signal: session.signal });
        video.addEventListener('playing', function () {
            if (videoSessions.get(video) === session) { video.hidden = false; }
        }, { signal: session.signal });
        try {
            var attempt = video.play();
            if (attempt && attempt.catch) { attempt.catch(fail); }
        } catch (e) { fail(); }
    }

    function stopVideo(video) {
        var session = videoSessions.get(video);
        if (session) { session.abort(); videoSessions.delete(video); }
        video.pause();
        video.hidden = true;
    }

    // Keep the poster until the player reports PLAYING, not merely a message
    // or iframe load. Removing a session cancels its listener AND timeout.
    function startYoutube(box) {
        if (!videoAllowed(box)) { stopYoutube(box); return; }
        if (youtubeSessions.has(box)) { return; }
        var src = box.getAttribute('data-hero-yt-src');
        if (!src) { return; }
        var origin;
        try {
            var url = new URL(src);
            if (url.origin !== 'https://www.youtube-nocookie.com') { return; }
            origin = url.origin;
        } catch (e) { return; }
        var session = new AbortController();
        var frame = document.createElement('iframe');
        frame.src = src;
        frame.title = '';
        frame.tabIndex = -1;
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('allow', 'autoplay; encrypted-media');
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        var timeout = window.setTimeout(function () { stopYoutube(box); }, 4000);
        youtubeSessions.set(box, { session: session, frame: frame, timeout: timeout });
        window.addEventListener('message', function (event) {
            if (event.source !== frame.contentWindow || event.origin !== origin) { return; }
            var message;
            try { message = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; }
            catch (e) { return; }
            if (!message || typeof message !== 'object') { return; }
            if (message.event === 'onError') { stopYoutube(box); return; }
            var playing = message.event === 'onStateChange' && message.info === 1
                || message.event === 'infoDelivery' && message.info && message.info.playerState === 1;
            if (playing) {
                window.clearTimeout(timeout);
                box.classList.add('is-ready');
            }
        }, { signal: session.signal });
        frame.addEventListener('load', function () {
            frame.contentWindow.postMessage('{"event":"listening"}', origin);
        }, { signal: session.signal });
        box.hidden = false;
        box.appendChild(frame);
    }

    function stopYoutube(box) {
        var active = youtubeSessions.get(box);
        if (active) {
            active.session.abort();
            window.clearTimeout(active.timeout);
            active.frame.remove();
            youtubeSessions.delete(box);
        }
        box.classList.remove('is-ready');
        box.hidden = true;
    }

    function activateMedia(slide) {
        slide.querySelectorAll('[data-hero-video]').forEach(startVideo);
        slide.querySelectorAll('[data-hero-youtube]').forEach(startYoutube);
    }

    function deactivateMedia(slide) {
        slide.querySelectorAll('[data-hero-video]').forEach(stopVideo);
        slide.querySelectorAll('[data-hero-youtube]').forEach(stopYoutube);
    }

    /*
     * Прозрачная шапка лежит поверх обложки, и её светлый набор — белое лого,
     * белое меню — читается только на тёмном кадре. Гадать по картинке не
     * нужно: сервер посчитал схему фона слайда и положил её в
     * data-hero-scheme. Цвет текста слайда для этого не годится: редактор
     * задаёт его вручную и обычно одинаковым на всех слайдах. Ниже по странице
     * обложек может быть несколько, но под шапкой лежит только первая:
     * остальные её не касаются.
     */
    function headerFollows(root) {
        if (!document.querySelector('.site-header--transparent')) {
            return false;
        }
        // Прозрачная шапка лежит поверх первого блока страницы. Обложка ниже
        // по странице шапки не касается: под ней тогда другой блок, и
        // подстраивать набор под чужой кадр — та же потеря контраста наоборот.
        var block = root.closest('.cms-block') || root;
        var first = document.querySelector('.cms-block');

        return first ? first === block : document.querySelector('[data-hero]') === root;
    }

    var headerTimer = null;

    /*
     * Набор шапки меняется скачком, а кадр — за время перехода. Если
     * переключить его сразу по клику, тёмный логотип окажется поверх ещё не
     * ушедшего тёмного кадра: замерено — через 30 мс новый кадр виден на 1 %,
     * и рассинхрон держится треть секунды. Поэтому смена приходится на
     * середину перехода, где кадры равны и подмена не читается. При
     * «меньше движения» перехода нет — нет и задержки.
     */
    function syncHeaderToSlide(root, slide, duration) {
        if (!headerFollows(root)) {
            return;
        }
        window.clearTimeout(headerTimer);
        var light = !!slide && slide.getAttribute('data-hero-scheme') === 'light';
        if (light === document.body.classList.contains('is-hero-light')) {
            return;
        }

        var delay = reduceMotion() ? 0 : Math.round((duration || 0) / 2);
        if (delay <= 0) {
            document.body.classList.toggle('is-hero-light', light);

            return;
        }

        headerTimer = window.setTimeout(function () {
            document.body.classList.toggle('is-hero-light', light);
        }, delay);
    }

    function initHero(root) {
        if (instances.has(root)) { return; }
        var slides = Array.from(root.querySelectorAll('[data-hero-slide]'));
        if (!slides.length) { return; }
        var lifecycle = new AbortController();
        var signal = lifecycle.signal;
        var current = 0;
        var duration = Number(root.getAttribute('data-hero-duration')) || 700;
        var interval = Number(root.getAttribute('data-hero-autoplay')) || 0;
        var toggle = root.querySelector('[data-hero-toggle]');
        var dots = Array.from(root.querySelectorAll('[data-hero-goto]'));
        var counter = root.querySelector('[data-hero-current]');
        var progress = root.querySelector('[data-hero-progress]');
        var status = root.querySelector('[data-hero-status]');
        var state = {
            playing: !reduceMotion() && !(interval && isMobile()),
            hovered: false,
            visible: !('IntersectionObserver' in window),
            pageHidden: document.hidden
        };
        var timer = null;
        var frame = null;
        var leaving = new Map();
        var startedAt = 0;
        var pointerIntent = null;

        function autoplayAllowed() {
            return slides.length > 1 && interval > 0 && state.playing
                && !state.hovered && state.visible && !state.pageHidden && !reduceMotion();
        }

        function setProgress(value) {
            if (progress) { progress.style.setProperty('--hero-progress', String(value)); }
        }

        function slideInterval() {
            var own = Number(slides[current].getAttribute('data-hero-slide-duration')) || 0;
            return own > 0 ? own : interval;
        }

        function tick() {
            if (!autoplayAllowed()) { return; }
            setProgress(Math.min(1, (performance.now() - startedAt) / slideInterval()));
            frame = window.requestAnimationFrame(tick);
        }

        function stopAuto() {
            window.clearTimeout(timer);
            window.cancelAnimationFrame(frame);
            timer = frame = null;
            setProgress((current + 1) / slides.length);
        }

        function startAuto() {
            stopAuto();
            if (!autoplayAllowed()) { return; }
            startedAt = performance.now();
            setProgress(0);
            timer = window.setTimeout(function () { go(current + 1, false); }, slideInterval());
            if (progress) { frame = window.requestAnimationFrame(tick); }
        }

        // All environmental changes pass through the same reconciliation.
        // Hover never clears a keyboard/user pause; visibility never clears hover.
        function refresh() {
            if (reduceMotion()) { state.playing = false; }
            var mediaRunning = state.playing && state.visible && !state.pageHidden;
            if (mediaRunning) { activateMedia(slides[current]); }
            else { deactivateMedia(slides[current]); }
            root.setAttribute('data-hero-paused', mediaRunning ? 'false' : 'true');
            if (toggle) {
                var paused = !state.playing;
                toggle.setAttribute('data-paused', paused ? 'true' : 'false');
                // Action label changes; this is a command button, not aria-pressed.
                toggle.setAttribute('aria-label', toggle.getAttribute(paused ? 'data-label-play' : 'data-label-pause'));
                toggle.disabled = reduceMotion();
            }
            startAuto();
        }

        function pause() { state.playing = false; refresh(); }

        function go(index, announce) {
            var next = ((index % slides.length) + slides.length) % slides.length;
            if (next === current) { return; }
            root.setAttribute('data-hero-dir', index < current ? 'prev' : 'next');
            var previous = slides[current];
            previous.classList.remove('is-active');
            previous.classList.add('is-leaving');
            previous.setAttribute('aria-hidden', 'true');
            previous.setAttribute('inert', '');
            deactivateMedia(previous);
            window.clearTimeout(leaving.get(previous));
            leaving.set(previous, window.setTimeout(function () {
                previous.classList.remove('is-leaving');
                leaving.delete(previous);
            }, reduceMotion() ? 0 : duration));
            current = next;
            var slide = slides[current];
            window.clearTimeout(leaving.get(slide));
            leaving.delete(slide);
            slide.classList.remove('is-leaving');
            slide.classList.add('is-active');
            slide.removeAttribute('inert');
            slide.setAttribute('aria-hidden', 'false');
            syncHeaderToSlide(root, slide, duration);
            dots.forEach(function (dot) {
                var active = Number(dot.getAttribute('data-hero-goto')) === current;
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-current', active ? 'true' : 'false');
            });
            if (counter) { counter.textContent = pad(current + 1); }
            if (status) { status.textContent = announce ? slide.getAttribute('aria-label') : ''; }
            refresh();
        }

        function manualGo(index) { pause(); go(index, true); }

        root.addEventListener('click', function (event) {
            var control = event.target.closest('button');
            if (!control || control.closest('[data-hero]') !== root) { return; }
            if (control === toggle) {
                // pointerdown precedes focusin: remember the action the visitor
                // actually clicked before focus pauses the carousel.
                state.playing = pointerIntent === null ? !state.playing : pointerIntent;
                pointerIntent = null;
                state.hovered = false;
                refresh();
            } else if (control.hasAttribute('data-hero-prev')) { manualGo(current - 1); }
            else if (control.hasAttribute('data-hero-next')) { manualGo(current + 1); }
            else if (control.hasAttribute('data-hero-goto')) { manualGo(Number(control.getAttribute('data-hero-goto'))); }
        }, { signal: signal });
        if (toggle) {
            toggle.addEventListener('pointerdown', function () { pointerIntent = !state.playing; }, { signal: signal });
            toggle.addEventListener('pointercancel', function () { pointerIntent = null; }, { signal: signal });
            toggle.addEventListener('keydown', function () { pointerIntent = null; }, { signal: signal });
        }
        root.addEventListener('focusin', pause, { signal: signal });
        root.addEventListener('mouseenter', function () { state.hovered = true; refresh(); }, { signal: signal });
        root.addEventListener('mouseleave', function () { state.hovered = false; pointerIntent = null; refresh(); }, { signal: signal });
        root.addEventListener('keydown', function (event) {
            // Leave editing, browser shortcuts and nested controls alone.
            if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
                || event.target.closest('input,textarea,select,[contenteditable],video,audio')) { return; }
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                manualGo(current + (event.key === 'ArrowLeft' ? -1 : 1));
            }
        }, { signal: signal });

        if (root.hasAttribute('data-hero-swipe')) {
            var touch = null;
            root.addEventListener('touchstart', function (event) {
                touch = event.touches.length === 1 ? event.touches[0] : null;
            }, { passive: true, signal: signal });
            root.addEventListener('touchcancel', function () { touch = null; }, { passive: true, signal: signal });
            root.addEventListener('touchend', function (event) {
                if (!touch || !event.changedTouches.length) { return; }
                var end = Array.from(event.changedTouches).find(function (item) { return item.identifier === touch.identifier; });
                if (!end) { return; }
                var dx = end.clientX - touch.clientX;
                var dy = end.clientY - touch.clientY;
                touch = null;
                if (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy)) {
                    manualGo(current + (dx < 0 ? 1 : -1));
                }
            }, { passive: true, signal: signal });
        }

        function visibility() { state.pageHidden = document.hidden; refresh(); }
        document.addEventListener('visibilitychange', visibility, { signal: signal });
        window.addEventListener('pagehide', function () { state.pageHidden = true; refresh(); }, { signal: signal });
        window.addEventListener('pageshow', visibility, { signal: signal });
        document.addEventListener('asdr:motion-change', refresh, { signal: signal });
        window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', refresh, { signal: signal });
        window.matchMedia(MOBILE_QUERY).addEventListener('change', function () {
            if (isMobile() && interval) { state.playing = false; }
            refresh();
        }, { signal: signal });
        if (navigator.connection) { navigator.connection.addEventListener('change', refresh, { signal: signal }); }
        var observer;
        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(function (entries) {
                state.visible = entries[0].isIntersecting;
                refresh();
            });
            observer.observe(root);
        }
        function destroy() {
            stopAuto();
            lifecycle.abort();
            if (observer) { observer.disconnect(); }
            leaving.forEach(function (id) { window.clearTimeout(id); });
            slides.forEach(function (slide) {
                deactivateMedia(slide);
                slide.classList.remove('is-leaving');
                slide.removeAttribute('aria-hidden');
                slide.removeAttribute('inert');
            });
            if (headerFollows(root)) { window.clearTimeout(headerTimer); }
            root.removeAttribute('data-hero-ready');
            instances.delete(root);
        }
        root.addEventListener('asdr:hero-destroy', destroy, { signal: signal });
        instances.set(root, { destroy: destroy });
        slides.forEach(function (slide, index) {
            slide.classList.toggle('is-active', index === current);
            slide.setAttribute('aria-hidden', index === current ? 'false' : 'true');
            slide.toggleAttribute('inert', index !== current);
        });
        root.setAttribute('data-hero-ready', '');
        dots.forEach(function (dot) {
            var active = Number(dot.getAttribute('data-hero-goto')) === current;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-current', active ? 'true' : 'false');
        });
        if (status) { status.textContent = ''; }
        if (counter) { counter.textContent = pad(current + 1); }
        syncHeaderToSlide(root, slides[current]);
        refresh();
    }

    function init() { document.querySelectorAll('[data-hero]').forEach(initHero); }
    document.addEventListener('asdr:hero-init', init);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init, { once: true }); }
    else { init(); }
})();
