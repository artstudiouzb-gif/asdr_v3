/**
 * Слайдеры: блок «Слайдер» (галерея изображений) и обложка со слайдами.
 * Разметка у них разная, поведение — одно, поэтому логика общая: показ слайда,
 * точки, стрелки, клавиатура, свайп и необязательная автопрокрутка.
 */
(function () {
    'use strict';

    // Проверяем при каждом тике: «остановка анимаций» в панели настроек
    // переключается на лету, а закэшированное значение этого не заметит.
    var reduceMotion = function () {
        return window.asdrReduceMotion
            ? window.asdrReduceMotion()
            : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    };

    function initSlider(root, options) {
        var slides = root.querySelectorAll(options.slide);
        if (slides.length < 2) {
            return;
        }
        var current = 0;
        var dots = root.querySelectorAll('[data-slide-index]');
        var status = root.querySelector('[data-slider-status]');

        function show(index, announce) {
            slides[current].classList.remove('is-active');
            slides[current].setAttribute('aria-hidden', 'true');
            current = (index + slides.length) % slides.length;
            slides[current].classList.add('is-active');
            slides[current].setAttribute('aria-hidden', 'false');
            dots.forEach(function (dot, dotIndex) {
                var active = dotIndex === current;
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-current', active ? 'true' : 'false');
            });
            if (announce && status) {
                status.textContent = slides[current].getAttribute('aria-label') || String(current + 1);
            }
        }

        var prev = root.querySelector(options.prev);
        var next = root.querySelector(options.next);
        if (prev) {
            prev.addEventListener('click', function () { stopAuto(); show(current - 1, true); });
        }
        if (next) {
            next.addEventListener('click', function () { stopAuto(); show(current + 1, true); });
        }
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                stopAuto();
                show(Number(dot.getAttribute('data-slide-index')) || 0, true);
            });
        });

        root.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                stopAuto();
                show(current - 1, true);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                stopAuto();
                show(current + 1, true);
            } else if (event.key === 'Home') {
                event.preventDefault();
                stopAuto();
                show(0, true);
            } else if (event.key === 'End') {
                event.preventDefault();
                stopAuto();
                show(slides.length - 1, true);
            }
        });

        var startX = null;
        var startY = null;
        root.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'mouse') { return; }
            startX = event.clientX;
            startY = event.clientY;
        }, { passive: true });
        root.addEventListener('pointerup', function (event) {
            if (startX === null || startY === null) { return; }
            var dx = event.clientX - startX;
            var dy = event.clientY - startY;
            startX = null;
            startY = null;
            if (Math.abs(dx) < 40 || Math.abs(dx) <= Math.abs(dy)) { return; }
            stopAuto();
            show(current + (dx < 0 ? 1 : -1), true);
        }, { passive: true });

        // --- Автопрокрутка ---
        // Выключена, если посетитель просил меньше движения: карусель, которая
        // едет сама, для него — помеха, а не украшение.
        var delay = Number(root.getAttribute('data-autoplay')) * 1000;
        var timer = null;

        function startAuto() {
            if (timer !== null || !delay || reduceMotion() || document.hidden) { return; }
            timer = window.setInterval(function () { show(current + 1, false); }, delay);
        }

        function stopAuto() {
            if (timer === null) { return; }
            window.clearInterval(timer);
            timer = null;
        }

        if (delay && !reduceMotion()) {
            // Пауза, пока посетитель читает слайд: курсор над ним, фокус внутри
            // или вкладка ушла в фон.
            root.addEventListener('mouseenter', stopAuto);
            root.addEventListener('mouseleave', startAuto);
            root.addEventListener('focusin', stopAuto);
            root.addEventListener('focusout', function (event) {
                if (!root.contains(event.relatedTarget)) { startAuto(); }
            });
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) { stopAuto(); } else { startAuto(); }
            });
            startAuto();
        }
    }

    /**
     * Случайный порядок кадров. Перемешивает браузер, а не сервер: страницы
     * кэшируются общим ключом, и порядок, выбранный при сборке, застыл бы для
     * всех посетителей до сброса кэша. Без JS карусель остаётся рабочей — она
     * просто идёт в порядке редактора.
     */
    function shuffleSlides(root) {
        var track = root.querySelector('.block-slider__track');
        if (!track) { return; }
        var slides = Array.prototype.slice.call(track.querySelectorAll('.block-slider__slide'));
        if (slides.length < 2) { return; }

        for (var i = slides.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = slides[i];
            slides[i] = slides[j];
            slides[j] = tmp;
        }
        slides.forEach(function (slide, index) {
            // Активным становится первый после перемешивания, иначе на экране
            // остался бы кадр, который редактор поставил первым.
            slide.classList.toggle('is-active', index === 0);
            slide.setAttribute('aria-hidden', index === 0 ? 'false' : 'true');
            var img = slide.querySelector('img');
            if (img && index === 0) { img.removeAttribute('loading'); }
            track.appendChild(slide);
        });
    }

    /**
     * Карусель «случайная цель». Сервер отрисовал одну цель, но она уедет в
     * кэш страницы и станет общей для всех посетителей. Свежую цель просим
     * здесь: ответ не кэшируется, и у каждого получается своя.
     *
     * Не ответил сервер — остаётся отрисованная цель. Пустой карусели не
     * бывает ни при каком отказе, и без JS виджет тоже работает.
     */
    function loadRandomGoal(host, slider, done) {
        var track = slider.querySelector('.block-slider__track');
        // Адрес приходит из разметки: он несёт язык страницы, а скрипт про
        // текущий язык ничего не знает — жёсткий «/goals/random» приносил на
        // узбекскую страницу русскую цель.
        var url = host.getAttribute('data-goal-slider') || '/goals/random';
        if (!track || !window.fetch) {
            done();
            return;
        }

        window.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                return response.ok && response.status !== 204 ? response.json() : null;
            })
            .then(function (data) {
                if (!data || !data.slides) {
                    return;
                }
                // Разметку собрал наш же /goals/random — это шаблон сервера,
                // а не текст со страницы: адреса и подписи он уже экранировал.
                track.innerHTML = data.slides;
                // Название и описание принадлежат цели, а не виджету: подменив
                // кадры без текста, мы подписали бы новые снимки прежним именем.
                var text = host.querySelector('[data-goal-text]');
                if (text) {
                    text.innerHTML = data.text || '';
                }
                // Подпись карусели — это имя цели. setAttribute экранирует
                // значение сам, склеивать его в строку HTML не нужно.
                if (data.label) {
                    slider.setAttribute('aria-label', data.label);
                }
                // Точек ровно столько, сколько кадров у новой цели: у прежней
                // их могло быть больше или меньше.
                rebuildDots(slider, track.querySelectorAll('.block-slider__slide').length);
            })
            .catch(function () {})
            .then(done, done);
    }

    function rebuildDots(root, count) {
        var dots = root.querySelector('.block-slider__dots');
        if (!dots) {
            return;
        }
        if (count < 2) {
            dots.textContent = '';
            return;
        }

        // Точки собираются узлами, а не строкой HTML. Подпись берётся из
        // разметки (она переводится), и склейка её в строку с innerHTML — это
        // тот самый случай, когда кавычка в переводе выносит атрибут наружу.
        // setAttribute экранирует значение сам, и разбирать нечего.
        var sample = dots.querySelector('.block-slider__dot');
        var label = sample ? (sample.getAttribute('aria-label') || '').replace(/\d+\s*$/, '') : '';

        var fragment = document.createDocumentFragment();
        for (var i = 0; i < count; i++) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'block-slider__dot' + (i === 0 ? ' is-active' : '');
            dot.setAttribute('data-slide-index', String(i));
            dot.setAttribute('aria-label', label + (i + 1));
            dot.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            fragment.appendChild(dot);
        }

        dots.textContent = '';
        dots.appendChild(fragment);
    }

    document.querySelectorAll('.block-slider').forEach(function (slider) {
        var start = function () {
            if (slider.hasAttribute('data-slider-shuffle')) {
                shuffleSlides(slider);
            }
            initSlider(slider, {
                slide: '.block-slider__slide',
                prev: '.block-slider__prev',
                next: '.block-slider__next'
            });
        };

        // Кадры подменяются до запуска: initSlider запоминает список слайдов,
        // и запущенная на старых кадрах карусель листала бы уже удалённые узлы.
        // Признак висит на обёртке, а не на самом слайдере: подменяются и
        // текст цели, и её кадры, а лежат они рядом, а не один в другом.
        var goalHost = slider.closest ? slider.closest('[data-goal-slider]') : null;
        if (goalHost) {
            loadRandomGoal(goalHost, slider, start);
        } else {
            start();
        }
    });

    document.querySelectorAll('[data-hero-slider]').forEach(function (hero) {
        initSlider(hero, {
            slide: '.block-hero__slide',
            prev: '[data-hero-prev]',
            next: '[data-hero-next]'
        });
    });
})();
