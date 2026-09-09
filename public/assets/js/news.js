(function () {
    'use strict';

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
})();
