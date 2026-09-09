/**
 * Обложка (тип контента «Обложки»): переключение слайдов, индикатор,
 * автопрокрутка и фоновое медиа.
 *
 * Скрипт — прогрессивное улучшение. Без него первый слайд виден, остальные
 * показываются подряд (правило @media (scripting: none) в hero.css), кадры
 * замены стоят на месте видео, и обложка остаётся рабочей шапкой страницы.
 *
 * Правило фонового медиа одно на все случаи: под видео всегда лежит готовый
 * кадр, поэтому «не загрузилось», «автовоспроизведение запрещено», «выключено
 * на телефоне» и «меньше движения» обрабатываются одинаково — видео просто не
 * показывается. Пустой обложки не бывает.
 */
(function () {
    'use strict';

    /* Граница мобильной раскладки — та же, что в hero.css. */
    var MOBILE_QUERY = '(max-width: 720px)';

    /* Спрашиваем при каждой проверке: «остановка анимаций» в панели настроек
       отображения переключается на лету, и закэшированный ответ её не заметит. */
    function reduceMotion() {
        return window.asdrReduceMotion
            ? window.asdrReduceMotion()
            : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function isMobile() {
        return !!(window.matchMedia && window.matchMedia(MOBILE_QUERY).matches);
    }

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    // --- Фоновое видео ---------------------------------------------------

    /** Показывать ли видео этого слайда прямо сейчас. */
    function videoAllowed(el) {
        if (reduceMotion()) {
            return false;
        }

        return !(isMobile() && el.getAttribute('data-hero-mobile-media') === 'image');
    }

    function startVideo(video) {
        if (!videoAllowed(video)) {
            stopVideo(video);

            return;
        }

        if (!video.dataset.heroSourceReady) {
            var mobileSrc = video.getAttribute('data-hero-video-mobile');
            var source = video.querySelector('source');
            // Отдельный мобильный ролик подставляем до первой загрузки: менять
            // источник у уже игравшего видео означает скачать оба файла.
            if (mobileSrc && source && isMobile()
                && video.getAttribute('data-hero-mobile-media') === 'mobile_video') {
                source.setAttribute('src', mobileSrc);
                video.load();
            }
            video.dataset.heroSourceReady = '1';
        }

        if (!video.dataset.heroErrorBound) {
            // Файл не отдался (404, битый кодек, оборванная сеть) — прячем
            // видео и оставляем кадр-замену. Событие ошибки приходит уже
            // после успешного play(), поэтому одной проверки промиса мало.
            video.addEventListener('error', function () { stopVideo(video); }, true);
            video.dataset.heroErrorBound = '1';
        }

        video.hidden = false;
        var attempt = video.play();
        if (attempt && typeof attempt.catch === 'function') {
            // Браузер вправе отказать в автовоспроизведении. Это не ошибка и не
            // повод что-то чинить: под видео уже лежит нужный кадр.
            attempt.catch(function () { stopVideo(video); });
        }
    }

    function stopVideo(video) {
        try {
            video.pause();
        } catch (e) {
            /* видео могло не успеть инициализироваться — молча пропускаем */
        }
        video.hidden = true;
    }

    // --- Фоновое видео YouTube -------------------------------------------

    /**
     * Iframe создаётся только когда слайд показан, и остаётся прозрачным, пока
     * плеер не отзовётся. Не отозвался за отведённое время (ролик удалён,
     * встраивание запрещено, сеть недоступна) — убираем его совсем: чёрный
     * прямоугольник вместо обложки хуже, чем картинка.
     */
    function startYoutube(box) {
        if (!videoAllowed(box)) {
            stopYoutube(box);

            return;
        }
        if (box.querySelector('iframe')) {
            box.hidden = false;

            return;
        }

        var src = box.getAttribute('data-hero-yt-src');
        if (!src) {
            return;
        }

        var frame = document.createElement('iframe');
        frame.setAttribute('src', src);
        frame.setAttribute('title', '');
        frame.setAttribute('tabindex', '-1');
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('allow', 'autoplay; encrypted-media');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.setAttribute('loading', 'lazy');
        box.hidden = false;
        box.appendChild(frame);

        var settled = false;
        var ready = function (event) {
            if (settled || !frame.contentWindow || event.source !== frame.contentWindow) {
                return;
            }
            settled = true;
            window.removeEventListener('message', ready);
            box.classList.add('is-ready');
        };
        window.addEventListener('message', ready);

        frame.addEventListener('load', function () {
            // Рукопожатие JS API: плеер отвечает на «listening» потоком
            // сообщений. Ответ = ролик действительно играет.
            try {
                frame.contentWindow.postMessage('{"event":"listening"}', 'https://www.youtube-nocookie.com');
            } catch (e) {
                /* кросс-доменные ограничения — ждём таймаут */
            }
        });

        window.setTimeout(function () {
            if (settled) {
                return;
            }
            settled = true;
            window.removeEventListener('message', ready);
            stopYoutube(box);
        }, 4000);
    }

    function stopYoutube(box) {
        var frame = box.querySelector('iframe');
        if (frame) {
            frame.parentNode.removeChild(frame);
        }
        box.classList.remove('is-ready');
        box.hidden = true;
    }

    function activateMedia(slide) {
        var video = slide.querySelector('[data-hero-video]');
        if (video) {
            startVideo(video);
        }
        var yt = slide.querySelector('[data-hero-youtube]');
        if (yt) {
            startYoutube(yt);
        }
    }

    function deactivateMedia(slide) {
        var video = slide.querySelector('[data-hero-video]');
        if (video) {
            stopVideo(video);
        }
        var yt = slide.querySelector('[data-hero-youtube]');
        if (yt) {
            stopYoutube(yt);
        }
    }

    // --- Карусель ---------------------------------------------------------

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
        var light = !!slide && slide.getAttribute('data-hero-scheme') === 'light';
        if (light === document.body.classList.contains('is-hero-light')) {
            return;
        }

        window.clearTimeout(headerTimer);
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
        var slides = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
        if (!slides.length) {
            return;
        }

        // Один слайд — не карусель: медиа запускаем, всё остальное не нужно.
        if (slides.length === 1) {
            activateMedia(slides[0]);
            syncHeaderToSlide(root, slides[0]);

            return;
        }

        var current = 0;
        var duration = parseInt(root.getAttribute('data-hero-duration'), 10) || 700;
        var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-goto]'));
        var counter = root.querySelector('[data-hero-current]');
        var progress = root.querySelector('[data-hero-progress]');
        var status = root.querySelector('[data-hero-status]');
        var toggle = root.querySelector('[data-hero-toggle]');

        var interval = parseInt(root.getAttribute('data-hero-autoplay'), 10) || 0;
        // Поведение автопрокрутки не настраивается: пауза при наведении и при
        // действии нужна всегда, возобновление — через фиксированную паузу, а
        // на телефоне автопрокрутки нет (свайп важнее, и это экономит батарею).
        var resumeDelay = 10000;
        var pauseOnHover = true;
        var pauseOnInteraction = true;
        var autoplayOnMobile = false;

        var timer = null;
        var resumeTimer = null;
        var frame = null;
        var startedAt = 0;
        var stoppedByUser = false;
        var suspended = false;

        function autoplayAllowed() {
            if (!interval || stoppedByUser || suspended || reduceMotion()) {
                return false;
            }

            return !(isMobile() && !autoplayOnMobile);
        }

        function setProgress(value) {
            if (progress) {
                progress.style.setProperty('--hero-progress', String(value));
            }
        }

        /**
         * Сколько держать текущий слайд. У слайда бывает своя длительность:
         * короткая подпись и плотная инфографика читаются не за одно время,
         * и одним интервалом на всю карусель это не выражается.
         */
        function slideInterval() {
            var own = parseInt(slides[current].getAttribute('data-hero-slide-duration'), 10) || 0;

            return own > 0 ? own : interval;
        }

        function tick() {
            if (!autoplayAllowed()) {
                return;
            }
            // Полоса прогресса считается по длительности текущего слайда:
            // иначе на слайде со своим временем она уезжала бы вперёд показа.
            var elapsed = (Date.now() - startedAt) / slideInterval();
            setProgress(elapsed > 1 ? 1 : elapsed);
            frame = window.requestAnimationFrame(tick);
        }

        function stopAuto() {
            if (timer) {
                window.clearTimeout(timer);
                timer = null;
            }
            if (frame) {
                window.cancelAnimationFrame(frame);
                frame = null;
            }
            // Без автопрокрутки полоса показывает положение в списке, а не
            // обратный отсчёт: иначе индикатор выглядит сломанным.
            setProgress((current + 1) / slides.length);
        }

        /** Таймер всегда считается заново — и после ручного переключения тоже. */
        function startAuto() {
            stopAuto();
            if (!autoplayAllowed()) {
                return;
            }
            startedAt = Date.now();
            setProgress(0);
            timer = window.setTimeout(function () { go(current + 1, false); }, slideInterval());
            frame = window.requestAnimationFrame(tick);
        }

        function syncToggle() {
            if (!toggle) {
                return;
            }
            var paused = stoppedByUser;
            toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
            toggle.setAttribute(
                'aria-label',
                toggle.getAttribute(paused ? 'data-label-play' : 'data-label-pause') || ''
            );
        }

        /** Пауза после действия посетителя и, если разрешено, возврат к показу. */
        function noteInteraction() {
            if (!pauseOnInteraction || !interval) {
                return;
            }
            if (resumeTimer) {
                window.clearTimeout(resumeTimer);
                resumeTimer = null;
            }
            if (!resumeDelay) {
                stoppedByUser = true;
                syncToggle();
                stopAuto();

                return;
            }
            suspended = true;
            stopAuto();
            resumeTimer = window.setTimeout(function () {
                suspended = false;
                startAuto();
            }, resumeDelay);
        }

        function go(index, announce) {
            var next = ((index % slides.length) + slides.length) % slides.length;
            if (next === current) {
                return;
            }

            var forward = next > current || (current === slides.length - 1 && next === 0);
            root.setAttribute('data-hero-dir', forward ? 'next' : 'prev');

            var previous = slides[current];
            previous.classList.remove('is-active');
            previous.classList.add('is-leaving');
            previous.setAttribute('aria-hidden', 'true');
            previous.setAttribute('inert', '');
            deactivateMedia(previous);
            window.setTimeout(function () { previous.classList.remove('is-leaving'); }, duration);

            current = next;
            var slide = slides[current];
            slide.classList.add('is-active');
            slide.setAttribute('aria-hidden', 'false');
            slide.removeAttribute('inert');
            activateMedia(slide);
            syncHeaderToSlide(root, slide, duration);

            dots.forEach(function (dot) {
                var active = parseInt(dot.getAttribute('data-hero-goto'), 10) === current;
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-current', active ? 'true' : 'false');
            });
            if (counter) {
                counter.textContent = pad(current + 1);
            }
            if (announce && status) {
                status.textContent = slide.getAttribute('aria-label') || String(current + 1);
            }

            startAuto();
        }

        /** Переключение по воле посетителя: пересчитать таймер и, если нужно, встать на паузу. */
        function manualGo(index) {
            noteInteraction();
            go(index, true);
        }

        var prev = root.querySelector('[data-hero-prev]');
        var next = root.querySelector('[data-hero-next]');
        if (prev) {
            prev.addEventListener('click', function () { manualGo(current - 1); });
        }
        if (next) {
            next.addEventListener('click', function () { manualGo(current + 1); });
        }
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                manualGo(parseInt(dot.getAttribute('data-hero-goto'), 10) || 0);
            });
        });

        if (toggle) {
            toggle.addEventListener('click', function () {
                stoppedByUser = !stoppedByUser;
                syncToggle();
                if (stoppedByUser) {
                    stopAuto();
                } else {
                    suspended = false;
                    startAuto();
                }
            });
            syncToggle();
        }

        root.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                manualGo(current - 1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                manualGo(current + 1);
            }
        });

        if (pauseOnHover) {
            root.addEventListener('mouseenter', function () { suspended = true; stopAuto(); });
            root.addEventListener('mouseleave', function () { suspended = false; startAuto(); });
        }
        // Фокус внутри обложки — тоже взаимодействие: уезжающий из-под
        // клавиатуры слайд делает навигацию невозможной.
        root.addEventListener('focusin', function () { suspended = true; stopAuto(); });
        root.addEventListener('focusout', function (event) {
            if (!root.contains(event.relatedTarget)) {
                suspended = false;
                startAuto();
            }
        });

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopAuto();
            } else {
                startAuto();
            }
        });

        if (root.hasAttribute('data-hero-swipe')) {
            var startX = 0;
            var startY = 0;
            var tracking = false;
            root.addEventListener('touchstart', function (event) {
                if (event.touches.length !== 1) {
                    return;
                }
                tracking = true;
                startX = event.touches[0].clientX;
                startY = event.touches[0].clientY;
            }, { passive: true });
            root.addEventListener('touchend', function (event) {
                if (!tracking) {
                    return;
                }
                tracking = false;
                var touch = event.changedTouches[0];
                var dx = touch.clientX - startX;
                var dy = touch.clientY - startY;
                // Вертикальное движение — это прокрутка страницы, а не свайп:
                // иначе обложка воровала бы жест у всей страницы.
                if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) {
                    return;
                }
                manualGo(dx < 0 ? current + 1 : current - 1);
            }, { passive: true });
        }

        // Поворот экрана и смена настройки «меньше движения» меняют ответы
        // autoplayAllowed() и videoAllowed() — состояние пересобираем.
        var refresh = function () {
            activateMedia(slides[current]);
            startAuto();
        };
        if (window.matchMedia) {
            var mq = window.matchMedia(MOBILE_QUERY);
            if (mq.addEventListener) {
                mq.addEventListener('change', refresh);
            } else if (mq.addListener) {
                mq.addListener(refresh);
            }
        }
        document.addEventListener('asdr:motion-change', refresh);

        slides.forEach(function (slide, index) {
            if (index !== current) {
                slide.setAttribute('inert', '');
            }
        });
        activateMedia(slides[current]);
        syncHeaderToSlide(root, slides[current]);
        if (counter) {
            counter.textContent = pad(current + 1);
        }
        startAuto();
        if (!autoplayAllowed()) {
            setProgress((current + 1) / slides.length);
        }
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-hero]'), initHero);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
