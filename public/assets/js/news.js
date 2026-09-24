(function () {
    'use strict';

    // Подписи приходят из разметки (JSON в подвале), как и у frontend.js.
    var labels = {};
    var labelsNode = document.getElementById('frontend-labels');
    if (labelsNode) {
        try {
            labels = JSON.parse(labelsNode.textContent || '{}');
        } catch (error) {
            labels = {};
        }
    }
    var label = function (key, fallback) {
        return typeof labels[key] === 'string' && labels[key] !== '' ? labels[key] : fallback;
    };

    // --- Скелетоны: снимаем состояние загрузки, когда картинка готова ---
    function clearSkeleton(img) {
        var box = img.closest('.skeleton');
        if (box) { box.classList.remove('skeleton'); }
    }
    document.querySelectorAll('.skeleton img').forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
            clearSkeleton(img);
        } else {
            img.addEventListener('load', function () { clearSkeleton(img); });
            img.addEventListener('error', function () {
                // Fallback обложки (YouTube hqdefault) при ошибке загрузки.
                var fb = img.getAttribute('data-fallback');
                if (fb && img.src !== fb) { img.src = fb; }
                else { clearSkeleton(img); }
            });
        }
    });

    // --- Слайдер галереи новости ---
    document.querySelectorAll('[data-news-slider]').forEach(function (slider) {
        var slides = Array.prototype.slice.call(slider.querySelectorAll('.news-slider__slide'));
        if (slides.length < 2) { return; }
        var index = 0;

        function show(next) {
            slides[index].classList.remove('is-active');
            index = (next + slides.length) % slides.length;
            slides[index].classList.add('is-active');
            // Лениво «разбудим» соседние картинки.
            var img = slides[index].querySelector('img[loading="lazy"]');
            if (img && img.dataset.pending !== '0') { img.dataset.pending = '0'; }
        }

        var prev = slider.querySelector('.news-slider__nav--prev');
        var next = slider.querySelector('.news-slider__nav--next');
        if (prev) { prev.addEventListener('click', function () { show(index - 1); }); }
        if (next) { next.addEventListener('click', function () { show(index + 1); }); }
    });

    // --- Ленивый YouTube: превью -> iframe только по клику ---
    // Штатный end screen YouTube нельзя отключить параметром rel=0. После
    // завершения IFrame API сразу закрывает его исходной обложкой новости.
    var youtubeApiPromise = null;
    function loadYoutubeApi() {
        if (window.YT && typeof window.YT.Player === 'function') {
            return Promise.resolve(window.YT);
        }
        if (youtubeApiPromise) { return youtubeApiPromise; }

        youtubeApiPromise = new Promise(function (resolve, reject) {
            var previousReady = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function () {
                if (typeof previousReady === 'function') { previousReady(); }
                resolve(window.YT);
            };

            var script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            script.async = true;
            script.onerror = function () { reject(new Error('YouTube API unavailable')); };
            document.head.appendChild(script);
        });

        return youtubeApiPromise;
    }

    document.querySelectorAll('[data-youtube]').forEach(function (box) {
        var btn = box.querySelector('.news-video__play');
        var target = btn || box;
        target.addEventListener('click', function () {
            if (box.classList.contains('is-playing')) { return; }
            var embed = box.getAttribute('data-embed');
            if (!embed) { return; }
            var originalThumb = box.querySelector('.news-video__thumb');
            var endThumb = originalThumb ? originalThumb.cloneNode(true) : null;
            var replayLabel = box.getAttribute('data-replay-label') || 'Посмотреть ещё раз';

            var iframe = document.createElement('iframe');
            var separator = embed.indexOf('?') === -1 ? '?' : '&';
            iframe.setAttribute('src', embed + separator + 'origin=' + encodeURIComponent(window.location.origin));
            iframe.setAttribute('title', 'YouTube video');
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allow', 'autoplay; encrypted-media');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            iframe.className = 'news-video__iframe';

            var endCard = document.createElement('div');
            endCard.className = 'news-video__end';
            endCard.hidden = true;
            if (endThumb) {
                endThumb.className = 'news-video__thumb news-video__end-thumb';
                endCard.appendChild(endThumb);
            }
            var replay = document.createElement('button');
            replay.type = 'button';
            replay.className = 'news-video__replay';
            replay.textContent = replayLabel;
            endCard.appendChild(replay);

            box.innerHTML = '';
            box.classList.remove('skeleton');
            box.classList.add('is-playing');
            box.appendChild(iframe);
            box.appendChild(endCard);

            loadYoutubeApi().then(function (YT) {
                var player = new YT.Player(iframe, {
                    events: {
                        onStateChange: function (event) {
                            if (event.data === YT.PlayerState.ENDED) {
                                endCard.hidden = false;
                                box.classList.add('is-ended');
                            } else if (event.data === YT.PlayerState.PLAYING) {
                                endCard.hidden = true;
                                box.classList.remove('is-ended');
                            }
                        }
                    }
                });

                replay.addEventListener('click', function () {
                    endCard.hidden = true;
                    box.classList.remove('is-ended');
                    player.seekTo(0, true);
                    player.playVideo();
                });
            }).catch(function () {
                // Сам iframe остаётся рабочим; недоступен только свой end screen.
                endCard.remove();
            });
        });
    });

    // Слайдер галереи и режим чтения нужны только странице новости, поэтому
    // живут здесь, а не в общем бандле: news.js эта страница грузит и так, а
    // общий бандл получает каждая страница сайта. Лайтбокс остаётся в
    // frontend.js (он работает и в тексте обычных страниц) и зовёт
    // root.__ndgShow лениво — в момент листания, когда этот файл уже выполнен.

    // Детальная новость: слайдер медиа-модуля (главное фото + миниатюры + счётчик).
    document.querySelectorAll('[data-ndgallery]').forEach(function (root) {
        var slides = root.querySelectorAll('.newsdetail-gallery__slide');
        if (slides.length < 2) { return; }
        var thumbs = root.querySelectorAll('[data-ndg-thumb]');
        var counter = root.querySelector('[data-ndg-current]');
        // Подпись и автор активного снимка: тексты всех слайдов лежат в
        // data-атрибуте, при листании подставляется нужная пара.
        var captionBox = root.querySelector('[data-ndg-captions]');
        var captions = [];
        if (captionBox) {
            try { captions = JSON.parse(captionBox.getAttribute('data-ndg-captions') || '[]'); } catch (e) { captions = []; }
        }
        var captionText = root.querySelector('[data-ndg-caption-text]');
        var captionCredit = root.querySelector('[data-ndg-caption-credit]');
        var idx = 0;
        var show = function (i) {
            idx = (i + slides.length) % slides.length;
            slides.forEach(function (s, n) { s.classList.toggle('is-active', n === idx); });
            thumbs.forEach(function (t, n) { t.classList.toggle('is-active', n === idx); });
            if (counter) { counter.textContent = String(idx + 1); }
            if (captionBox && captions[idx]) {
                if (captionText) { captionText.textContent = captions[idx].caption || ''; }
                if (captionCredit) {
                    var credit = captions[idx].credit;
                    captionCredit.textContent = credit ? label('photoCredit', 'Фото:') + ' ' + credit : '';
                    // Пустой блок прячем целиком: иначе остаётся висеть
                    // точка-разделитель перед пустотой.
                    captionCredit.hidden = !credit;
                }
                captionBox.style.visibility = (captions[idx].caption || captions[idx].credit) ? '' : 'hidden';
            }
        };
        root.__ndgShow = show;
        var prev = root.querySelector('[data-ndg-prev]');
        var next = root.querySelector('[data-ndg-next]');
        if (prev) { prev.addEventListener('click', function () { show(idx - 1); }); }
        if (next) { next.addEventListener('click', function () { show(idx + 1); }); }
        thumbs.forEach(function (t, n) { t.addEventListener('click', function () { show(n); }); });
        root.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { show(idx - 1); }
            if (e.key === 'ArrowRight') { show(idx + 1); }
        });
    });

    // === Режим чтения для новостей (Reader Mode) ===
    (function () {
        var overlay = document.getElementById('reader-mode-overlay');
        if (!overlay) { return; }

        var body = document.body;
        var progress = document.getElementById('reader-progress');
        var articleContent = overlay.querySelector('.reader-mode-container');
        var fontSizeLevel = 1.0;
        var readerLastFocus = null;

        var setReaderIsolation = function (enabled) {
            if (!enabled) {
                document.querySelectorAll('[data-reader-inert]').forEach(function (el) {
                    el.removeAttribute('inert');
                    el.removeAttribute('data-reader-inert');
                });
                return;
            }

            var node = overlay;
            while (node && node !== document.body) {
                var parent = node.parentElement;
                if (!parent) { break; }
                Array.prototype.forEach.call(parent.children, function (sibling) {
                    if (sibling !== node && !sibling.hasAttribute('inert')) {
                        sibling.setAttribute('inert', '');
                        sibling.setAttribute('data-reader-inert', '');
                    }
                });
                node = parent;
            }
        };

        var updateProgress = function () {
            if (overlay.hidden) { return; }
            var scrollTop = overlay.scrollTop;
            var scrollHeight = overlay.scrollHeight - overlay.clientHeight;
            var pct = scrollHeight > 0 ? Math.min(100, Math.max(0, (scrollTop / scrollHeight) * 100)) : 0;
            if (progress) {
                progress.style.setProperty('--reader-progress', pct + '%');
                progress.setAttribute('aria-valuenow', String(Math.round(pct)));
            }
        };

        overlay.addEventListener('scroll', updateProgress, { passive: true });

        // Тело статьи в разметке оверлея пустое: содержимое переносится из
        // основной статьи при первом открытии. В разметке оно раньше печаталось
        // вторым экземпляром и удваивало DOM на каждой новости.
        var fillReaderBody = function () {
            var target = overlay.querySelector('[data-reader-body]');
            if (!target || target.getAttribute('data-reader-filled') === '1') { return; }
            var source = document.querySelector(target.getAttribute('data-reader-source') || '');
            if (!source) { return; }
            var copy = source.cloneNode(true);
            // id внутри копии сделали бы дубликаты в документе — снимаем их,
            // якорные ссылки внутри режима чтения всё равно не используются.
            copy.removeAttribute('id');
            copy.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
            while (copy.firstChild) { target.appendChild(copy.firstChild); }
            target.setAttribute('data-reader-filled', '1');
        };

        var openReader = function (trigger) {
            readerLastFocus = trigger || document.activeElement;
            fillReaderBody();
            overlay.hidden = false;
            body.classList.add('reader-mode-active');
            setReaderIsolation(true);
            overlay.scrollTop = 0;
            updateProgress();
            var closeButton = overlay.querySelector('[data-reader-close]');
            if (closeButton) { closeButton.focus(); }
        };

        var closeReader = function () {
            if (overlay.hidden) { return; }
            overlay.hidden = true;
            body.classList.remove('reader-mode-active');
            setReaderIsolation(false);
            if (readerLastFocus && readerLastFocus.focus) { readerLastFocus.focus(); }
        };

        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-reader-mode-toggle]');
            if (toggle) {
                e.preventDefault();
                openReader(toggle);
                return;
            }
            if (e.target.closest('[data-reader-close]')) {
                e.preventDefault();
                closeReader();
            }
        });

        overlay.querySelectorAll('button[data-reader-theme]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var theme = btn.getAttribute('data-reader-theme');
                overlay.setAttribute('data-reader-theme', theme);
                overlay.querySelectorAll('button[data-reader-theme]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });
            });
        });

        overlay.querySelectorAll('button[data-reader-font]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-reader-font');
                if (action === 'inc' && fontSizeLevel < 1.6) {
                    fontSizeLevel += 0.15;
                } else if (action === 'dec' && fontSizeLevel > 0.7) {
                    fontSizeLevel -= 0.15;
                }
                if (articleContent) {
                    articleContent.style.setProperty('--reader-scale', fontSizeLevel.toFixed(2));
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (overlay.hidden) { return; }
            if (e.key === 'Escape' || e.code === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                closeReader();
            } else if (e.key === 'Tab') {
                var focusable = Array.prototype.filter.call(
                    overlay.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'),
                    function (el) { return el.offsetParent !== null; }
                );
                if (!focusable.length) { return; }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
    })();
})();
