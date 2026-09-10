(function () {
    'use strict';

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
    // Геометрию иконок страница уже несёт инлайном (Icon::injectSprite),
    // поэтому ссылаемся на символ внутри документа, а не на спрайт-файл:
    // ради пары иконок незачем тянуть двухмегабайтный спрайт Tabler.
    var asdrIcon = function (name, size, className) {
        name = String(name || '').trim().toLowerCase().replace(/^tabler-/, '');
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name)) { return ''; }
        size = Math.max(8, Math.min(160, Number(size) || 18));
        className = className || 'ui-icon';
        return '<svg class="icon icon-tabler ' + className + '" width="' + size + '" height="' + size
            + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            + '<use href="#tabler-' + name + '"></use></svg>';
    };
    window.asdrPublicIcon = asdrIcon;

    // Same-origin page hints belong to the shared frontend bundle, not to
    // executable snippets injected into every response.
    (function () {
        var prefetched = new Set();
        document.addEventListener('mouseover', function (event) {
            var anchor = event.target.closest('a');
            if (!anchor || !anchor.href || anchor.origin !== location.origin
                || anchor.href.includes('#') || anchor.href.includes('/admin')
                || prefetched.has(anchor.href)) {
                return;
            }
            prefetched.add(anchor.href);
            var link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = anchor.href;
            document.head.appendChild(link);
        }, { passive: true });
    })();

    // Фон внутри карусели обложки принадлежит слайду: пока слайд не показан,
    // его видео не играет, а YouTube даже не загружается — иначе страница
    // тянула бы все ролики сразу. Следим за классом слайда, а не за событиями
    // слайдера: так вся логика фона остаётся в одном месте.
    var heroSlideOf = function (el) {
        return el.closest ? el.closest('.block-hero__slide') : null;
    };
    var heroSlideShown = function (slide) {
        return slide === null || slide.classList.contains('is-active');
    };
    var onHeroSlideToggle = function (slide, handler) {
        if (!slide || !window.MutationObserver) { return; }
        new MutationObserver(handler).observe(slide, { attributes: true, attributeFilter: ['class'] });
    };

    // MP4 в Hero — декоративный фон, а не видеоплеер. Атрибутов разметки
    // достаточно в большинстве браузеров, но после возврата на вкладку или
    // системной паузы autoplay может не возобновиться сам. Восстанавливаем
    // фон без контролов, звука и видимой кнопки воспроизведения.
    (function () {
        var videos = document.querySelectorAll('[data-hero-background-video]');
        if (!videos.length) { return; }
        var reduceMotion = function () {
            return window.asdrReduceMotion
                ? window.asdrReduceMotion()
                : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        };

        // Тумблер «остановка анимаций» меняется на лету: по событию панели
        // ставим фон на паузу, иначе обещание интерфейса не выполняется.
        document.addEventListener('asdr:motion-change', function () {
            if (!reduceMotion()) { return; }
            videos.forEach(function (video) {
                video.pause();
                video.removeAttribute('autoplay');
            });
        });

        // Телефон: если редактор выбрал «показывать постер», ролик не грузим и
        // не запускаем — остаётся кадр из poster.
        var posterOnly = function (video) {
            return video.getAttribute('data-hero-video-mobile') === 'poster'
                && window.matchMedia
                && window.matchMedia('(max-width: 720px)').matches;
        };

        videos.forEach(function (video) {
            if (posterOnly(video)) {
                video.pause();
                video.removeAttribute('autoplay');
                video.setAttribute('preload', 'none');
                return;
            }
            if (reduceMotion()) {
                video.pause();
                video.removeAttribute('autoplay');
                video.setAttribute('preload', 'none');
                return;
            }
            var slide = heroSlideOf(video);
            var resume = function () {
                if (!heroSlideShown(slide)) { return; }
                video.controls = false;
                video.muted = true;
                video.defaultMuted = true;
                video.loop = true;
                video.playsInline = true;
                video.removeAttribute('controls');

                var promise = video.play();
                if (promise && typeof promise.catch === 'function') {
                    // Браузер сам повторит попытку после canplay/visibilitychange.
                    promise.catch(function () {});
                }
            };

            video.addEventListener('canplay', resume);
            video.addEventListener('ended', function () {
                // Резерв для браузеров, игнорирующих loop после выгрузки вкладки.
                video.currentTime = 0;
                resume();
            });
            // Опережающий перезапуск за 0.2 секунды до реального окончания видео,
            // чтобы избежать черного экрана/вспышки и появления кнопок управления.
            video.addEventListener('timeupdate', function () {
                if (video.duration && video.currentTime >= video.duration - 0.2) {
                    video.currentTime = 0;
                    video.play().catch(function () {});
                }
            });
            video.addEventListener('pause', function () {
                if (!document.hidden && !video.ended) { resume(); }
            });
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) { resume(); }
            });
            onHeroSlideToggle(slide, function () {
                if (heroSlideShown(slide)) {
                    // Слайд вернулся — показываем ролик с начала, а не с
                    // середины, на которой посетитель его оставил.
                    video.currentTime = 0;
                    resume();
                } else {
                    video.pause();
                }
            });

            resume();
        });
    })();

    // YouTube-фон загружается только рядом с viewport. До готовности плеера
    // Hero показывает poster, поэтому тяжёлый third-party iframe не блокирует
    // первый рендер и не вызывает пустую вспышку.
    (function () {
        var frames = document.querySelectorAll('[data-hero-youtube-background]');
        if (!frames.length) { return; }
        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        frames.forEach(function (frame) {
            if (reduceMotion) {
                frame.removeAttribute('data-src');
                return;
            }
            var container = frame.closest('[data-hero-youtube-container]');
            var slide = heroSlideOf(frame);
            var loaded = false;
            var readyTimer = null;
            var markReady = function () {
                if (container) { container.classList.add('is-ready'); }
            };
            var load = function () {
                if (loaded) { return; }
                var source = frame.getAttribute('data-src');
                if (!source) { return; }
                loaded = true;
                frame.src = source;
            };
            var command = function (name, args) {
                if (!loaded || !frame.contentWindow) { return; }
                frame.contentWindow.postMessage(JSON.stringify({
                    event: 'command',
                    func: name,
                    args: args || []
                }), '*');
            };
            var resume = function () {
                if (!heroSlideShown(slide)) { return; }
                command('mute');
                command('setLoop', [true]);
                command('playVideo');
                // Отправляем handshake-сообщение "listening", чтобы YouTube начал присылать события о состоянии
                if (frame.contentWindow) {
                    frame.contentWindow.postMessage(JSON.stringify({ event: 'listening' }), '*');
                }
            };

            frame.addEventListener('load', function () {
                resume();
                // Резерв: отдельные версии плеера не присылают playerState,
                // хотя видео уже отображается.
                readyTimer = window.setTimeout(markReady, 1200);
            });
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && loaded) { resume(); }
            });

            // Слушаем сообщения об изменении состояния плеера YouTube,
            // чтобы перехватить окончание или время воспроизведения и запустить ролик заново.
            window.addEventListener('message', function (e) {
                if (e.source !== frame.contentWindow) { return; }
                if (!/https?:\/\/(www\.)?youtube(-nocookie)?\.com/.test(e.origin)) { return; }
                try {
                    var data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
                    if (!data) { return; }

                    var ended = false;
                    var playing = false;
                    if (data.event === 'infoDelivery' && data.info) {
                        // Опережающий перезапуск за 0.3 секунды до конца видео, чтобы избежать черного экрана
                        if (typeof data.info.currentTime === 'number' && typeof data.info.duration === 'number') {
                            if (data.info.duration > 0 && data.info.currentTime >= data.info.duration - 0.3) {
                                ended = true;
                            }
                        }
                        if (data.info.playerState === 0) {
                            ended = true;
                        } else if (data.info.playerState === 1) {
                            playing = true;
                        }
                    } else if (data.event === 'onStateChange' && (data.info === 0 || data.data === 0)) {
                        ended = true;
                    } else if (data.event === 'onStateChange' && (data.info === 1 || data.data === 1)) {
                        playing = true;
                    }

                    if (playing) {
                        if (readyTimer !== null) {
                            window.clearTimeout(readyTimer);
                            readyTimer = null;
                        }
                        markReady();
                    }

                    if (ended) {
                        resume();
                        command('seekTo', [0, true]);
                    }
                } catch (err) {
                    // Игнорируем невалидные сообщения от сторонних скриптов
                }
            });

            if (slide) {
                onHeroSlideToggle(slide, function () {
                    if (heroSlideShown(slide)) {
                        load();
                        resume();
                    } else if (loaded) {
                        command('pauseVideo');
                    }
                });
                if (heroSlideShown(slide)) { load(); }
                return;
            }

            if ('IntersectionObserver' in window) {
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) { return; }
                        load();
                        observer.unobserve(frame);
                    });
                }, { rootMargin: '300px 0px', threshold: 0.01 });
                observer.observe(frame);
            } else if (document.readyState === 'complete') {
                load();
            } else {
                window.addEventListener('load', load, { once: true });
            }
        });
    })();

    // Переключатели главного меню: бургер (мобильные / макет «боковая панель»),
    // а также фон и кнопка закрытия off-canvas панели с полной поддержкой доступности (a11y).
    var lastBurgerTrigger = null;

    var setBackgroundInert = function (isInert) {
        var targets = document.querySelectorAll('header.site-header, main.site-content, footer.site-footer');
        targets.forEach(function (el) {
            if (isInert) {
                el.setAttribute('aria-hidden', 'true');
                if ('inert' in el) { el.inert = true; }
            } else {
                el.removeAttribute('aria-hidden');
                if ('inert' in el) { el.inert = false; }
            }
        });
    };

    var updateMobileMenuState = function (open) {
        var drawer = document.querySelector('[data-drawer]');
        if (open) {
            document.body.classList.add('mobile-menu-open');
            if (drawer) {
                drawer.removeAttribute('aria-hidden');
                drawer.setAttribute('aria-modal', 'true');
            }
            setBackgroundInert(true);
        } else {
            document.body.classList.remove('mobile-menu-open');
            if (drawer) {
                drawer.setAttribute('aria-hidden', 'true');
                drawer.removeAttribute('aria-modal');
            }
            setBackgroundInert(false);
        }

        var state = open ? 'true' : 'false';
        document.querySelectorAll('[data-mobile-menu-toggle], .site-burger').forEach(function (el) {
            el.setAttribute('aria-expanded', state);
        });

        if (open && drawer) {
            var closeBtn = drawer.querySelector('.site-drawer__close');
            var firstFocusable = closeBtn || drawer.querySelector('a[href], button:not([disabled])');
            if (firstFocusable) {
                setTimeout(function () {
                    firstFocusable.focus();
                }, 50);
            }
        } else if (!open && lastBurgerTrigger) {
            try { lastBurgerTrigger.focus(); } catch (err) {}
        }
    };

    var handleToggleClick = function (e) {
        var toggle = e.target.closest('[data-mobile-menu-toggle], .site-burger');
        if (!toggle) { return; }
        e.preventDefault();
        var open = !document.body.classList.contains('mobile-menu-open');
        if (open && toggle.classList.contains('site-burger')) {
            lastBurgerTrigger = toggle;
        }
        updateMobileMenuState(open);
    };

    document.addEventListener('click', handleToggleClick);

    var handleMenuKeydown = function (e) {
        if (!document.body.classList.contains('mobile-menu-open')) { return; }

        var isEscape = e.key === 'Escape' || e.key === 'Esc' || e.code === 'Escape' || e.keyCode === 27;
        if (isEscape) {
            e.preventDefault();
            updateMobileMenuState(false);
            return;
        }

        if (e.key === 'Tab' || e.keyCode === 9) {
            var drawer = document.querySelector('[data-drawer]');
            if (!drawer) { return; }
            var focusables = drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
            if (!focusables.length) { return; }

            var firstEl = focusables[0];
            var lastEl = focusables[focusables.length - 1];

            if (e.shiftKey && document.activeElement === firstEl) {
                e.preventDefault();
                lastEl.focus();
            } else if (!e.shiftKey && document.activeElement === lastEl) {
                e.preventDefault();
                firstEl.focus();
            }
        }
    };
    document.addEventListener('keydown', handleMenuKeydown);

    document.querySelectorAll('.site-drawer__panel .site-menu__link').forEach(function (link) {
        link.addEventListener('click', function () {
            updateMobileMenuState(false);
        });
    });

    // Priority navigation для desktop: горизонтальное меню сохраняется,
    // а только последние не поместившиеся пункты переносятся в «Ещё».
    // Полный drawer остаётся отдельным мобильным меню.
    (function () {
        var menus = document.querySelectorAll('[data-priority-menu]');
        if (!menus.length) { return; }
        var desktop = window.matchMedia('(min-width: 721px)');
        // Небольшой запас поглощает субпиксельное округление шрифтов и flex.
        // Реальное переполнение определяем по геометрии видимых пунктов:
        // scrollWidth меню может включать скрытые абсолютные подменю.
        var fitTolerance = 4;
        var frame = 0;

        var isMenuItem = function (item) {
            return item.classList.contains('site-menu__link')
                || item.classList.contains('site-menu__item')
                || item.classList.contains('site-menu__divider');
        };
        var directMenuItems = function (menu) {
            return Array.prototype.filter.call(menu.children, function (item) {
                return isMenuItem(item);
            });
        };
        var menuParts = function (menu) {
            var overflow = Array.prototype.find.call(menu.children, function (item) {
                return item.hasAttribute('data-priority-overflow');
            });
            return {
                overflow: overflow,
                toggle: overflow ? overflow.querySelector('[data-priority-overflow-toggle]') : null,
                panel: overflow ? overflow.querySelector('[data-priority-overflow-panel]') : null
            };
        };
        var setOverflowOpen = function (parts, open) {
            if (!parts.overflow || !parts.toggle || !parts.panel) { return; }
            parts.panel.hidden = !open;
            parts.overflow.classList.toggle('is-open', open);
            parts.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        var restoreItems = function (menu, parts) {
            if (!parts.overflow || !parts.panel) { return; }
            setOverflowOpen(parts, false);
            while (parts.panel.firstElementChild) {
                menu.insertBefore(parts.panel.firstElementChild, parts.overflow);
            }
            parts.overflow.classList.remove('is-active');
            parts.overflow.hidden = true;
        };
        var lineItems = function (menu, parts) {
            var items = directMenuItems(menu);
            if (parts.overflow && !parts.overflow.hidden) {
                items.push(parts.overflow);
            }
            return items;
        };
        var doesNotFit = function (header, menu, parts) {
            if (!menu.offsetParent) { return false; }
            var items = lineItems(menu, parts);
            var boundary = menu.closest('.site-header__zone, .site-nav__inner, .site-topbar__zone') || header;
            var boundaryRect = boundary.getBoundingClientRect();
            var menuRect = menu.getBoundingClientRect();
            var outsideBoundary = menuRect.left < boundaryRect.left - fitTolerance
                || menuRect.right > boundaryRect.right + fitTolerance
                || menuRect.width > boundaryRect.width + fitTolerance;
            var itemOutsideMenu = items.some(function (item) {
                var itemRect = item.getBoundingClientRect();
                return itemRect.left < menuRect.left - fitTolerance
                    || itemRect.right > menuRect.right + fitTolerance;
            });
            if (items.length < 2) {
                return outsideBoundary || itemOutsideMenu;
            }
            var firstTop = items[0].offsetTop;
            var wrapped = items.some(function (item) {
                return Math.abs(item.offsetTop - firstTop) > 2;
            });
            return outsideBoundary || itemOutsideMenu || wrapped;
        };
        var fitMenu = function (header, menu) {
            var parts = menuParts(menu);
            if (!parts.overflow || !parts.toggle || !parts.panel) { return; }

            restoreItems(menu, parts);
            if (!desktop.matches || !menu.offsetParent || !doesNotFit(header, menu, parts)) {
                return;
            }

            parts.overflow.hidden = false;
            var items = directMenuItems(menu);
            // Даже в самой узкой desktop-зоне первый основной пункт остаётся
            // видимым; «Ещё» продолжает горизонтальную строку, а не заменяет её.
            while (items.length > 1 && doesNotFit(header, menu, parts)) {
                var item = items[items.length - 1];
                // prepend сохраняет исходный порядок при переносе с конца.
                parts.panel.insertBefore(item, parts.panel.firstChild);
                items = directMenuItems(menu);
            }

            if (!parts.panel.children.length) {
                parts.overflow.hidden = true;
                return;
            }
            parts.overflow.classList.toggle(
                'is-active',
                !!parts.panel.querySelector('.is-active, [aria-current="page"]')
            );
        };
        var measure = function () {
            frame = 0;
            menus.forEach(function (menu) {
                var header = menu.closest('[data-header-menu-adaptive]')
                    || menu.closest('.site-topbar')
                    || document.documentElement;
                fitMenu(header, menu);
            });
        };
        var schedule = function () {
            if (frame) { window.cancelAnimationFrame(frame); }
            frame = window.requestAnimationFrame(measure);
        };

        window.addEventListener('resize', schedule, { passive: true });
        if (desktop.addEventListener) { desktop.addEventListener('change', schedule); }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(schedule).catch(function () {});
        }

        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-priority-overflow-toggle]');
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                var wrapper = toggle.closest('[data-priority-overflow]');
                if (!wrapper || !wrapper.parentElement) { return; }
                var parts = menuParts(wrapper.parentElement);
                var open = parts.panel ? parts.panel.hidden : false;
                menus.forEach(function (menu) {
                    var other = menuParts(menu);
                    if (other.overflow !== parts.overflow) { setOverflowOpen(other, false); }
                });
                setOverflowOpen(parts, open);
                return;
            }

            menus.forEach(function (menu) {
                var parts = menuParts(menu);
                if (!parts.overflow || parts.overflow.contains(e.target)) {
                    if (parts.panel && parts.panel.contains(e.target) && e.target.closest('.site-menu__link')) {
                        setOverflowOpen(parts, false);
                    }
                    return;
                }
                setOverflowOpen(parts, false);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' && e.key !== 'Esc') { return; }
            menus.forEach(function (menu) {
                var parts = menuParts(menu);
                if (!parts.panel || parts.panel.hidden) { return; }
                setOverflowOpen(parts, false);
                if (parts.toggle) { parts.toggle.focus(); }
            });
        });

        schedule();
    })();

    // Плавное раскрытие/сворачивание поля поиска при клике на безрамочную иконку
    var searchToggles = document.querySelectorAll('[data-search-toggle]');
    var searchOverlay = document.querySelector('[data-search-overlay]');

    searchToggles.forEach(function (toggle) {
        if (!toggle.closest('.site-search-wrap')) { return; }
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wrap = toggle.closest('.site-search-wrap') || toggle.parentElement;
            if (!wrap) { return; }
            var form = wrap.querySelector('.site-search');
            var isExpanded = wrap.classList.toggle('is-expanded');
            toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            if (form) {
                form.classList.toggle('is-open', isExpanded);
                if (isExpanded) {
                    var input = form.querySelector('input[type="search"]');
                    if (input) {
                        setTimeout(function () { input.focus(); input.select(); }, 50);
                    }
                }
            }
        });
    });

    document.querySelectorAll('[data-search-close]').forEach(function (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wrap = closeBtn.closest('.site-search-wrap');
            if (wrap) {
                wrap.classList.remove('is-expanded');
                var toggle = wrap.querySelector('[data-search-toggle]');
                if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
                var form = wrap.querySelector('.site-search');
                if (form) { form.classList.remove('is-open'); }
            }
        });
    });

    // Клик вне поля поиска — закрыть выезжающий поиск
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.site-search-wrap')) {
            document.querySelectorAll('.site-search-wrap.is-expanded').forEach(function (wrap) {
                wrap.classList.remove('is-expanded');
                var toggle = wrap.querySelector('[data-search-toggle]');
                if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
                var form = wrap.querySelector('.site-search');
                if (form) { form.classList.remove('is-open'); }
            });
        }
    });

    if (searchToggles.length && searchOverlay) {
        var searchInput = searchOverlay.querySelector('[data-search-input]');
        var searchForm = searchOverlay.querySelector('.site-search-overlay__form');
        var activeSearchToggle = null;
        var searchCloseTimer = null;
        var positionSearch = function (toggle) {
            if (!toggle || !searchForm) { return; }
            var toggleRect = toggle.getBoundingClientRect();
            var header = toggle.closest('.site-header');
            var anchorRect = header ? header.getBoundingClientRect() : toggleRect;
            var desiredTop = anchorRect.bottom + 10;
            var maxTop = Math.max(12, window.innerHeight - searchForm.offsetHeight - 12);
            var top = Math.max(12, Math.min(desiredTop, maxTop));
            var desiredRight = Math.max(12, window.innerWidth - toggleRect.right);
            var maxRight = Math.max(12, window.innerWidth - searchForm.offsetWidth - 12);
            var right = Math.min(desiredRight, maxRight);
            searchOverlay.style.setProperty('--search-popover-top', top + 'px');
            searchOverlay.style.setProperty('--search-popover-right', right + 'px');
        };
        var openSearch = function (toggle) {
            if (searchOverlay.classList.contains('is-open') && activeSearchToggle === toggle) {
                closeSearch(true);
                return;
            }
            if (searchCloseTimer) { clearTimeout(searchCloseTimer); searchCloseTimer = null; }
            activeSearchToggle = toggle;
            searchOverlay.hidden = false;
            document.body.classList.add('site-search-open');
            positionSearch(toggle);
            searchToggles.forEach(function (t) { t.setAttribute('aria-expanded', 'true'); });
            requestAnimationFrame(function () {
                searchOverlay.classList.add('is-open');
                if (searchInput) { searchInput.focus(); }
            });
        };
        var closeSearch = function (restoreFocus) {
            var focusTarget = activeSearchToggle;
            searchOverlay.classList.remove('is-open');
            document.body.classList.remove('site-search-open');
            searchToggles.forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
            searchCloseTimer = setTimeout(function () {
                searchOverlay.hidden = true;
                searchCloseTimer = null;
                if (restoreFocus && focusTarget) { focusTarget.focus(); }
                activeSearchToggle = null;
            }, 180);
        };
        searchToggles.forEach(function (t) {
            if (t.closest('.site-search-wrap')) { return; }
            t.addEventListener('click', function () { openSearch(t); });
        });
        searchOverlay.addEventListener('click', function (e) {
            if (e.target === searchOverlay || e.target.closest('[data-search-close]')) {
                closeSearch(true);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (searchOverlay.hidden) { return; }
            if (e.key === 'Escape') {
                closeSearch(true);
                return;
            }
            if (e.key === 'Tab' && searchForm) {
                var focusable = Array.prototype.slice.call(searchForm.querySelectorAll('input, button, [href], [tabindex]:not([tabindex="-1"])'))
                    .filter(function (element) { return !element.disabled && element.offsetParent !== null; });
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
        window.addEventListener('resize', function () {
            if (!searchOverlay.hidden && activeSearchToggle) { positionSearch(activeSearchToggle); }
        });
        window.addEventListener('a11y:panelchange', function () {
            if (!searchOverlay.hidden && activeSearchToggle) { positionSearch(activeSearchToggle); }
        });
    }

    // Выпадающее подменю: клик по стрелке раскрывает (мобильные/клавиатура).
    // На desktop работает и hover/focus-within (CSS), клик — дополнительно.
    document.querySelectorAll('.site-menu__item--has-children .site-menu__toggle').forEach(function (toggle) {
        var item = toggle.closest('.site-menu__item');
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var open = item.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
    // Клик вне меню — закрыть все раскрытые подменю.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.site-menu__item.is-open').forEach(function (item) {
            if (!item.contains(e.target)) {
                item.classList.remove('is-open');
                var t = item.querySelector('.site-menu__toggle');
                if (t) { t.setAttribute('aria-expanded', 'false'); }
            }
        });
        document.querySelectorAll('details.site-lang-dropdown[open]').forEach(function (dropdown) {
            if (!dropdown.contains(e.target)) {
                dropdown.removeAttribute('open');
            }
        });
    });

    // Переключатель светлой/тёмной темы с сохранением выбора в localStorage.
    // Тем же способом тему меняет панель настроек отображения, поэтому выбор
    // темы живёт в одном месте, а не в двух похожих обработчиках.
    var themeSwitchTimer = null;
    window.asdrSetTheme = function (next) {
        var root = document.documentElement;
        // Переход цвета включаем только на время самой смены темы: постоянный
        // transition на всём документе стоил бы перерисовки при каждой смене
        // класса (шапка при скролле, открытие меню и т.д.).
        root.classList.add('is-theme-switching');
        if (themeSwitchTimer) { window.clearTimeout(themeSwitchTimer); }
        themeSwitchTimer = window.setTimeout(function () {
            root.classList.remove('is-theme-switching');
            themeSwitchTimer = null;
        }, 400);
        root.setAttribute('data-theme', next);
        if (document.body) {
            document.body.setAttribute('data-theme', next);
        }
        try {
            localStorage.setItem('theme', next);
            // Помним, при какой серверной настройке сделан выбор: сменят её в
            // админке — выбор посетителя сбросится (см. theme-init.js).
            localStorage.setItem('theme-base', root.getAttribute('data-theme-base') || '');
        } catch (err) {}
    };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.site-theme-toggle');
        if (!btn) { return; }
        e.preventDefault();
        window.asdrSetTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });

    // Счётчики (группа 4): анимация инкремента числа при попадании в зону
    // видимости. Переиспользуем IntersectionObserver. Уважает reduced-motion.
    (function () {
        var counters = document.querySelectorAll('.counter__value[data-counter-target]');
        if (!counters.length) { return; }
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce || !('IntersectionObserver' in window)) {
            counters.forEach(function (el) { el.textContent = el.getAttribute('data-counter-target'); });
            return;
        }
        function animate(el) {
            var target = parseInt(el.getAttribute('data-counter-target'), 10) || 0;
            var start = null, dur = 1400;
            function step(ts) {
                if (start === null) { start = ts; }
                var p = Math.min((ts - start) / dur, 1);
                el.textContent = Math.round(p * target).toString();
                if (p < 1) { requestAnimationFrame(step); }
            }
            requestAnimationFrame(step);
        }
        var cio = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { animate(e.target); obs.unobserve(e.target); }
            });
        }, { threshold: 0.4 });
        counters.forEach(function (el) {
            el.textContent = '0'; // start from 0 to avoid jumping
            cio.observe(el);
        });
    })();

    // Переход не запускается, если начальное состояние элемента ни разу не
    // нарисовано: браузер считает конечное значение первым. Скрипт с defer
    // выполняется до первой отрисовки, поэтому блок в первом экране получал
    // `is-visible` в том же кадре, где впервые получил стили, и появлялся
    // мгновенно — настройка «анимация появления» на верхних блоках не
    // работала вовсе. Ждём кадр после первой отрисовки и только потом
    // включаем наблюдение.
    function afterFirstPaint(fn) {
        if (typeof requestAnimationFrame !== 'function') { fn(); return; }
        requestAnimationFrame(function () { requestAnimationFrame(fn); });
    }

    // Микро-движок анимаций появления при скролле на Intersection Observer.
    (function () {
        var reveals = document.querySelectorAll('[data-reveal]');
        if (!('IntersectionObserver' in window)) {
            reveals.forEach(function (el) { el.classList.add('is-visible'); });
            window.asdrRevealObserve = function (list) {
                list.forEach(function (el) { el.classList.add('is-visible'); });
            };
            return;
        }
        var io = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
        window.asdrRevealObserve = function (list) {
            afterFirstPaint(function () {
                list.forEach(function (el) { io.observe(el); });
            });
        };
        window.asdrRevealObserve(Array.prototype.slice.call(reveals));
    })();

    // Проявление заголовков (Дизайн → Типографика). Заголовок секции ждёт
    // прокрутки бледным и набирает свой цвет. Бледное состояние вешает сам
    // скрипт: без него, при «меньше движения» и в режимах контраста заголовок
    // остаётся обычным — это украшение, а не содержимое. Заголовки секций —
    // это h2 (у карточек h3/h4), поэтому список ровно такой.
    (function () {
        var body = document.body;
        if (!body || !/\bdesign-title-(fade|wipe)\b/.test(body.className)) { return; }
        if (!('IntersectionObserver' in window)) { return; }
        if (typeof window.asdrReduceMotion === 'function' && window.asdrReduceMotion()) { return; }
        var contrast = document.documentElement.getAttribute('data-a11y-contrast');
        if (contrast && contrast !== 'normal') { return; }

        var titles = Array.prototype.filter.call(
            document.querySelectorAll('.cms-block h2'),
            function (el) { return (el.textContent || '').trim() !== ''; }
        );
        if (!titles.length) { return; }

        // Класс снимается сразу после показа: пока он висит, заливка текста
        // прозрачная, и выделение мышью, печать и цвет выбранного слова живут
        // по правилам анимации, а не по своим.
        var settle = function (el) { el.classList.remove('is-title-reveal'); };
        var io = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                var el = entry.target;
                obs.unobserve(el);
                el.addEventListener('animationend', function () { settle(el); }, { once: true });
                // Анимация могла не начаться (вкладка в фоне, тема без правил):
                // заголовок обязан вернуться к своим стилям в любом случае.
                setTimeout(function () { settle(el); }, 2500);
            });
        }, { threshold: 0.25, rootMargin: '0px 0px -5% 0px' });

        titles.forEach(function (el) { el.classList.add('is-title-reveal'); });
        afterFirstPaint(function () {
            titles.forEach(function (el) { io.observe(el); });
        });
    })();

    // Медиа-галерея: переключатели «Видео / Фото».
    document.querySelectorAll('[data-media-gallery]').forEach(function (gallery) {
        var tabs = gallery.querySelectorAll('[data-media-tab]');
        if (!tabs.length) { return; }
        var cards = gallery.querySelectorAll('[data-media-kind]');
        var grid = gallery.querySelector('[data-media-grid]');
        // У каждой вкладки своя полоса страниц: списки видео и фото разной
        // длины, и одна полоса на обе показывала бы чужие страницы.
        var pagers = gallery.querySelectorAll('[data-media-pager]');
        var apply = function (kind) {
            var visibleCount = 0;
            cards.forEach(function (c) {
                var visible = c.getAttribute('data-media-kind') === kind;
                c.hidden = !visible;
                if (visible) { visibleCount += 1; }
            });
            if (grid) {
                grid.classList.remove('mediagallery-grid--cols-1', 'mediagallery-grid--cols-2', 'mediagallery-grid--cols-3', 'mediagallery-grid--cols-4');
                grid.classList.add('mediagallery-grid--cols-' + Math.max(1, Math.min(4, visibleCount)));
            }
            pagers.forEach(function (p) {
                p.hidden = p.getAttribute('data-media-pager') !== kind;
            });
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-media-tab') === kind;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        };
        tabs.forEach(function (t) { t.addEventListener('click', function () { apply(t.getAttribute('data-media-tab')); }); });
        // Открытую вкладку выбирает сервер (она приходит в адресе вместе с
        // номером страницы) — иначе переход по полосе страниц «Фото»
        // возвращал бы посетителя на «Видео».
        apply(gallery.getAttribute('data-media-active') === 'photo' ? 'photo' : 'video');
    });

    // Якорная навигация: активный пункт следует за видимым разделом,
    // а не остаётся навсегда на первом элементе.
    document.querySelectorAll('.block-anchornav').forEach(function (nav) {
        var links = Array.prototype.filter.call(nav.querySelectorAll('a[href^="#"]'), function (link) {
            var id = decodeURIComponent((link.getAttribute('href') || '').slice(1));
            return id !== '' && document.getElementById(id);
        });
        if (!links.length) { return; }

        var activate = function (activeLink) {
            links.forEach(function (link) {
                var active = link === activeLink;
                link.classList.toggle('is-active', active);
                if (active) { link.setAttribute('aria-current', 'location'); }
                else { link.removeAttribute('aria-current'); }
            });
        };
        links.forEach(function (link) {
            link.addEventListener('click', function () { activate(link); });
        });

        var hashLink = links.find(function (link) {
            return link.getAttribute('href') === window.location.hash;
        });
        activate(hashLink || links[0]);

        if (!('IntersectionObserver' in window)) { return; }
        var targets = new Map();
        links.forEach(function (link) {
            var target = document.getElementById(decodeURIComponent(link.getAttribute('href').slice(1)));
            if (target) { targets.set(target, link); }
        });
        var observer = new IntersectionObserver(function (entries) {
            var visible = entries.filter(function (entry) { return entry.isIntersecting; })
                .sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });
            if (visible.length && targets.has(visible[0].target)) {
                activate(targets.get(visible[0].target));
            }
        }, { rootMargin: '-20% 0px -65% 0px', threshold: 0 });
        targets.forEach(function (_link, target) { observer.observe(target); });
    });

    // Единая адаптивная карусель для равноправных визуальных элементов.
    // CSS решает, когда сетка становится полосой; JS показывает управление
    // только при реальном переполнении. Без JS остаётся нативный touch-scroll.
    document.querySelectorAll('[data-carousel]').forEach(function (root) {
        var track = root.querySelector('[data-carousel-track]');
        var nav = root.querySelector('[data-carousel-nav]');
        var prev = root.querySelector('[data-carousel-prev]');
        var next = root.querySelector('[data-carousel-next]');
        var dots = root.querySelector('[data-carousel-dots]');
        var items = Array.prototype.slice.call(track ? track.querySelectorAll('[data-carousel-item]') : []);
        if (!track || !nav || !prev || !next || items.length < 2) { return; }

        // Не кэшируем: настройку «остановка анимаций» переключают во время
        // просмотра, и запомненное значение оставило бы карусель ехать.
        var motionPreference = {
            get matches() {
                return window.asdrReduceMotion
                    ? window.asdrReduceMotion()
                    : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            },
        };
        var positions = [0];
        var renderedDotsKey = '';
        var frame = null;
        var motionFrame = null;
        var MAX_PROGRESS_DOTS = 7;

        var stopMotion = function () {
            if (motionFrame !== null) {
                window.cancelAnimationFrame(motionFrame);
                motionFrame = null;
            }
        };

        // Привязка карточек (scroll-snap) и своя анимация тянут полосу порознь:
        // при обязательной привязке браузер подтягивает к ближайшей карточке
        // каждое присвоение scrollLeft, и ход распадался на прыжки от карточки
        // к карточке — замерено 0 → 205 → 410 → 615 → 820 вместо плавной дуги.
        // Поэтому на время своей анимации привязка снимается. Вернуть её сразу
        // по окончании нельзя: последний кадр полосы стоит у её конца, а это не
        // точка привязки — браузер отдёрнул бы полосу назад. Возвращается она
        // перед прокруткой самого посетителя, чтобы свайп по-прежнему доводил
        // карточку до края.
        var snapReleased = false;

        var releaseSnap = function () {
            if (snapReleased) { return; }
            snapReleased = true;
            track.style.scrollSnapType = 'none';
        };

        var yieldToUser = function () {
            stopMotion();
            if (!snapReleased) { return; }
            snapReleased = false;
            track.style.scrollSnapType = '';
        };

        // Ход считает JS: у нативной плавной прокрутки время и кривая
        // различаются от браузера к браузеру. Отсюда требование к CSS: у полосы
        // не должно быть `scroll-behavior: smooth` — каждое присвоение
        // scrollLeft запускало бы вторую, браузерную анимацию к тому же кадру,
        // и наша ползла бы по 2px за кадр, не доходя до цели (замерено: 638 из
        // 820). При «меньше движения» переход мгновенный.
        var scrollToPosition = function (target) {
            var max = Math.max(0, track.scrollWidth - track.clientWidth);
            var destination = Math.max(0, Math.min(max, Number(target) || 0));
            stopMotion();
            releaseSnap();
            if (motionPreference.matches || Math.abs(destination - track.scrollLeft) < 2) {
                track.scrollLeft = destination;
                return;
            }

            var start = track.scrollLeft;
            var distance = destination - start;
            var startedAt = null;
            var duration = 620;
            var step = function (timestamp) {
                if (startedAt === null) { startedAt = timestamp; }
                var progress = Math.min(1, (timestamp - startedAt) / duration);
                var eased = 0.5 - Math.cos(progress * Math.PI) / 2;
                track.scrollLeft = start + distance * eased;
                if (progress < 1) {
                    motionFrame = window.requestAnimationFrame(step);
                } else {
                    motionFrame = null;
                    track.scrollLeft = destination;
                }
            };
            motionFrame = window.requestAnimationFrame(step);
        };

        var pagePositions = function () {
            var max = Math.max(0, track.scrollWidth - track.clientWidth);
            if (max < 2 || track.clientWidth < 1) { return [0]; }

            var trackRect = track.getBoundingClientRect();
            var candidates = items.map(function (item) {
                var rect = item.getBoundingClientRect();
                return Math.max(0, Math.min(max, rect.left - trackRect.left + track.scrollLeft));
            });
            var pages = [0];
            var pageWidth = Math.max(1, track.clientWidth * 0.72);
            candidates.forEach(function (candidate) {
                if (candidate - pages[pages.length - 1] >= pageWidth) {
                    pages.push(candidate);
                }
            });
            if (max - pages[pages.length - 1] > 4) { pages.push(max); }
            else { pages[pages.length - 1] = max; }
            return pages;
        };

        var closestPage = function () {
            var current = 0;
            var distance = Infinity;
            positions.forEach(function (position, index) {
                var candidateDistance = Math.abs(track.scrollLeft - position);
                if (candidateDistance < distance) {
                    current = index;
                    distance = candidateDistance;
                }
            });
            return current;
        };

        var progressDotIndexes = function () {
            if (positions.length <= MAX_PROGRESS_DOTS) {
                return positions.map(function (_position, index) { return index; });
            }
            return Array.from({ length: MAX_PROGRESS_DOTS }, function (_item, index) {
                return Math.round(index * (positions.length - 1) / (MAX_PROGRESS_DOTS - 1));
            });
        };

        var renderDots = function () {
            if (!dots) { return []; }
            var indexes = progressDotIndexes();
            var key = indexes.join(',');
            if (renderedDotsKey === key) { return indexes; }
            renderedDotsKey = key;
            dots.textContent = '';
            indexes.forEach(function (positionIndex) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'carousel-nav__dot';
                dot.setAttribute('aria-label', label('goToSlide', 'Перейти к слайду') + ' ' + (positionIndex + 1));
                dot.addEventListener('click', function () {
                    scrollToPosition(positions[positionIndex] || 0);
                });
                dots.appendChild(dot);
            });
            return indexes;
        };

        var sync = function (remeasure) {
            if (remeasure) { positions = pagePositions(); }
            var active = positions.length > 1;
            root.classList.toggle('is-carousel-active', active);
            nav.hidden = !active;
            if (!active) {
                track.scrollLeft = 0;
                return;
            }
            var dotIndexes = renderDots();
            var current = closestPage();
            prev.disabled = track.scrollLeft <= 2;
            next.disabled = track.scrollLeft >= positions[positions.length - 1] - 2;
            if (dots) {
                var activeDot = 0;
                var activeDistance = Infinity;
                dotIndexes.forEach(function (positionIndex, dotIndex) {
                    var candidateDistance = Math.abs(current - positionIndex);
                    if (candidateDistance < activeDistance) {
                        activeDot = dotIndex;
                        activeDistance = candidateDistance;
                    }
                });
                Array.prototype.forEach.call(dots.children, function (dot, index) {
                    var selected = index === activeDot;
                    dot.classList.toggle('is-active', selected);
                    if (selected) { dot.setAttribute('aria-current', 'true'); }
                    else { dot.removeAttribute('aria-current'); }
                });
            }
        };

        var go = function (direction) {
            var current = closestPage();
            var target = Math.max(0, Math.min(positions.length - 1, current + direction));
            scrollToPosition(positions[target] || 0);
        };

        prev.addEventListener('click', function () { go(-1); });
        next.addEventListener('click', function () { go(1); });
        track.addEventListener('keydown', function (event) {
            if (event.target !== track) { return; }
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                go(-1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                go(1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                scrollToPosition(0);
            } else if (event.key === 'End') {
                event.preventDefault();
                scrollToPosition(positions[positions.length - 1] || 0);
            }
        });
        track.addEventListener('pointerdown', yieldToUser, { passive: true });
        track.addEventListener('touchstart', yieldToUser, { passive: true });
        track.addEventListener('wheel', yieldToUser, { passive: true });
        track.addEventListener('scroll', function () {
            if (frame !== null) { return; }
            frame = window.requestAnimationFrame(function () {
                frame = null;
                sync(false);
            });
        }, { passive: true });

        var measure = function () {
            window.requestAnimationFrame(function () { sync(true); });
        };
        if ('ResizeObserver' in window) {
            var resizeObserver = new ResizeObserver(measure);
            resizeObserver.observe(track);
        } else {
            window.addEventListener('resize', measure);
        }
        sync(true);

        // Автопрокрутка: секунды в data-carousel-autoplay, пусто или 0 —
        // выключена. Полоса, которая едет сама, мешает читать, поэтому она
        // замирает под курсором, при фокусе внутри, во время ручной прокрутки
        // и у тех, кто просил меньше движения. Дойдя до конца, возвращается в
        // начало: иначе карусель просто останавливалась бы на последней карточке.
        var autoplayDelay = Number(root.getAttribute('data-carousel-autoplay')) * 1000;
        var autoplayTimer = null;

        var stopAutoplay = function () {
            if (autoplayTimer === null) { return; }
            window.clearInterval(autoplayTimer);
            autoplayTimer = null;
        };

        var startAutoplay = function () {
            if (autoplayTimer !== null || !autoplayDelay || motionPreference.matches || document.hidden) { return; }
            autoplayTimer = window.setInterval(function () {
                if (positions.length < 2) { return; }
                if (closestPage() >= positions.length - 1) {
                    scrollToPosition(0);
                } else {
                    go(1);
                }
            }, autoplayDelay);
        };

        if (autoplayDelay && !motionPreference.matches) {
            root.addEventListener('mouseenter', stopAutoplay);
            root.addEventListener('mouseleave', startAutoplay);
            root.addEventListener('focusin', stopAutoplay);
            root.addEventListener('focusout', function (event) {
                if (!root.contains(event.relatedTarget)) { startAutoplay(); }
            });
            track.addEventListener('pointerdown', stopAutoplay, { passive: true });
            track.addEventListener('wheel', stopAutoplay, { passive: true });
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) { stopAutoplay(); } else { startAutoplay(); }
            });
            startAutoplay();
        }
    });

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

    // «Скопировать ссылку» в блоке «Поделиться».
    document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-copy-link');
            var done = function () {
                btn.classList.add('is-copied');
                var prevLabel = btn.getAttribute('aria-label');
                btn.setAttribute('aria-label', label('linkCopied', 'Ссылка скопирована'));
                setTimeout(function () { btn.classList.remove('is-copied'); btn.setAttribute('aria-label', prevLabel); }, 1600);
            };
            var copyFallback = function () {
                var ta = document.createElement('textarea');
                ta.value = url; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(copyFallback);
            } else {
                copyFallback();
            }
        });
    });

    // Кнопка «Печать».
    document.querySelectorAll('[data-print-page]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.print();
        });
    });

    // Липкая/прозрачная шапка: класс is-scrolled после небольшой прокрутки.
    (function () {
        var hdr = document.querySelector('[data-header-scroll]');
        if (!hdr) { return; }
        // Прозрачная шапка стартует сразу под верхней полосой (если есть).
        var topbar = document.querySelector('.site-topbar');
        var a11yPanel = document.querySelector('.a11y-panel');
        var offset = function () {
            var panelHeight = a11yPanel && a11yPanel.classList.contains('is-open') ? a11yPanel.offsetHeight : 0;
            hdr.style.setProperty('--hdr-panel-height', panelHeight + 'px');
            if (hdr.classList.contains('site-header--transparent')) {
                var topbarHeight = topbar ? topbar.offsetHeight : 0;
                hdr.style.setProperty('--hdr-top', (topbarHeight + panelHeight) + 'px');
                // Верхняя полоса тоже наложена (absolute) — держим её под a11y-панелью.
                if (topbar) { topbar.style.setProperty('--hdr-panel-height', panelHeight + 'px'); }
            }
        };
        // Высота шапки уходит в --scroll-offset: на неё опирается
        // scroll-padding-top, иначе якорная ссылка прячет заголовок раздела
        // под шапкой. Меряем, а не подбираем константу: макетов шапки четыре.
        var syncScrollOffset = function () {
            var stuck = getComputedStyle(hdr).position === 'sticky';
            var height = stuck ? Math.round(hdr.getBoundingClientRect().height) : 0;
            document.documentElement.style.setProperty('--scroll-offset', height + 'px');
        };
        var apply = function () {
            hdr.classList.toggle('is-scrolled', window.scrollY > 12);
        };
        window.addEventListener('scroll', apply, { passive: true });
        window.addEventListener('resize', offset);
        window.addEventListener('resize', syncScrollOffset, { passive: true });
        window.addEventListener('a11y:panelchange', offset);
        offset();
        apply();
        syncScrollOffset();
    })();

    // Плавающая кнопка «Наверх»: активна только при включённом тумблере
    // (body.design-scrolltop), появляется после прокрутки, скроллит вверх.
    (function () {
        if (!document.body.classList.contains('design-scrolltop')) { return; }
        var btn = document.querySelector('[data-scroll-top]');
        if (!btn) { return; }
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var shown = false;
        var toggle = function () {
            var need = window.scrollY > 600;
            if (need === shown) { return; }
            shown = need;
            btn.classList.toggle('is-visible', need);
        };
        window.addEventListener('scroll', toggle, { passive: true });
        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
        });
        toggle();
    })();

    // Делегированные обработчики вместо инлайн-атрибутов (CSP без 'unsafe-inline'):
    // [data-auto-submit] — селект отправляет свою форму; [data-captcha-refresh] —
    // кнопка обновляет картинку капчи рядом с собой.
    document.addEventListener('change', function (e) {
        var el = e.target;
        // Форму списка обслуживает AJAX-модуль ниже — иначе селект сортировки
        // сработал бы дважды и перезагрузил страницу поверх подгрузки.
        if (el && el.matches && el.matches('select[data-auto-submit]') && el.form
            && !el.form.hasAttribute('data-listing-form')) {
            el.form.submit();
        }
    });
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-captcha-refresh]') : null;
        if (!btn) { return; }
        var img = btn.parentNode.querySelector('img');
        if (img) { img.src = '/captcha.png?ts=' + Date.now(); }
    });

    // Раскрытием формы поиска заведует [data-search-toggle] выше: он ставит
    // `is-expanded` на обёртку и `is-open` на форму. Прежний механизм на классе
    // `is-active` отсюда убран — он отменял отправку. Enter (и кнопка «поиск»
    // на экранной клавиатуре) шлёт клик по submit-кнопке, а тот обработчик
    // звал preventDefault, пока не увидит `is-active`, которого новый механизм
    // не ставит: поле было открыто, а поиск не запускался.

    // Лайтбокс: фото (альбомы, медиагалерея, фотолента новости)
    // и видео YouTube (карточки на главной/страницах, «Смотреть видео» в новостях).
    (function () {
        var IMG_RE = /\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i;
        // Блок «Изображение» попадает сюда только с включённым увеличением:
        // класс ставит шаблон, поэтому обычный снимок остаётся обычным.
        var PHOTO_SCOPES = '.album-photos, .newsdetail-photos__grid, .mediagallery-grid, .block-image--zoomable';

        function ytId(url) {
            var patterns = [
                /youtu\.be\/([\w-]{11})/,
                /youtube\.com\/watch\?[^\s]*v=([\w-]{11})/,
                /youtube\.com\/embed\/([\w-]{11})/,
                /youtube\.com\/shorts\/([\w-]{11})/
            ];
            for (var i = 0; i < patterns.length; i++) {
                var m = String(url || '').match(patterns[i]);
                if (m) { return m[1]; }
            }
            return null;
        }

        var box = null, stage = null, captionEl = null, prevBtn = null, nextBtn = null;
        var items = [], index = 0, lastFocus = null;

        function ensure() {
            if (box) { return; }
            box = document.createElement('div');
            box.className = 'cms-lightbox';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-label', label('mediaViewer', 'Просмотр медиа'));
            box.innerHTML =
                '<button type="button" class="cms-lightbox__close" aria-label="' + label('close', 'Закрыть') + '">' + asdrIcon('x', 20) + '</button>' +
                '<button type="button" class="cms-lightbox__nav cms-lightbox__nav--prev" aria-label="' + label('previous', 'Предыдущее') + '">' + asdrIcon('chevron-left', 24) + '</button>' +
                '<div class="cms-lightbox__stage"></div>' +
                '<button type="button" class="cms-lightbox__nav cms-lightbox__nav--next" aria-label="' + label('next', 'Следующее') + '">' + asdrIcon('chevron-right', 24) + '</button>' +
                '<div class="cms-lightbox__caption"></div>';
            document.body.appendChild(box);
            stage = box.querySelector('.cms-lightbox__stage');
            captionEl = box.querySelector('.cms-lightbox__caption');
            prevBtn = box.querySelector('.cms-lightbox__nav--prev');
            nextBtn = box.querySelector('.cms-lightbox__nav--next');

            box.querySelector('.cms-lightbox__close').addEventListener('click', close);
            box.addEventListener('click', function (e) {
                if (e.target === box || e.target === stage) { close(); }
            });
            prevBtn.addEventListener('click', function () { go(-1); });
            nextBtn.addEventListener('click', function () { go(1); });
            document.addEventListener('keydown', function (e) {
                if (!box.classList.contains('is-open')) { return; }
                if (e.key === 'Escape') { close(); return; }
                if (e.key === 'ArrowLeft') { go(-1); return; }
                if (e.key === 'ArrowRight') { go(1); return; }
                // Focus-trap: Tab не выпускает фокус за пределы модалки (WCAG 2.4.3).
                if (e.key === 'Tab') {
                    var focusable = Array.prototype.filter.call(
                        box.querySelectorAll('button:not([hidden]), a[href], iframe'),
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
        }

        function render() {
            var item = items[index];
            if (!item) { return; }
            if (item.type === 'video') {
                stage.innerHTML = '<iframe class="cms-lightbox__video" src="https://www.youtube-nocookie.com/embed/'
                    + item.id + '?rel=0&modestbranding=1&autoplay=1" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="'
                    + label('video', 'Видео') + '"></iframe>';
            } else {
                var img = document.createElement('img');
                img.src = item.src;
                img.alt = item.caption || '';
                stage.innerHTML = '';
                stage.appendChild(img);
            }
            captionEl.textContent = item.caption || '';
            captionEl.hidden = !item.caption;
            var many = items.length > 1;
            prevBtn.hidden = !many;
            nextBtn.hidden = !many;
        }

        function open(list, i, trigger) {
            ensure();
            items = list;
            index = i;
            lastFocus = trigger || document.activeElement;
            render();
            box.classList.add('is-open');
            document.body.classList.add('lightbox-active');
            box.querySelector('.cms-lightbox__close').focus();
        }

        function close() {
            if (!box) { return; }
            box.classList.remove('is-open');
            stage.innerHTML = ''; // останавливает видео
            document.body.classList.remove('lightbox-active');
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        }

        function go(step) {
            if (items.length < 2) { return; }
            index = (index + step + items.length) % items.length;
            render();
        }

        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[href]');
            if (!a || e.defaultPrevented) { return; }
            var href = a.getAttribute('href') || '';

            // Видео YouTube — в лайтбокс на любой публичной странице.
            var id = ytId(href);
            if (id) {
                e.preventDefault();
                open([{ type: 'video', id: id }], 0, a);
                return;
            }

            // Фото: только в известных контейнерах, группой с листанием.
            var scope = a.closest(PHOTO_SCOPES);
            if (!scope || !IMG_RE.test(href)) { return; }
            var links = Array.prototype.filter.call(scope.querySelectorAll('a[href]'), function (el) {
                return IMG_RE.test(el.getAttribute('href') || '');
            });
            var list = links.map(function (el) {
                var fig = el.closest('figure');
                var cap = fig ? fig.querySelector('figcaption') : null;
                return {
                    type: 'image',
                    src: el.getAttribute('href'),
                    caption: (cap && cap.textContent) || el.getAttribute('aria-label') || (el.querySelector('img') && el.querySelector('img').alt) || ''
                };
            });
            e.preventDefault();
            open(list, Math.max(0, links.indexOf(a)), a);
        });
    })();

    // Мягкий каскад появления карточек в сетках при прокрутке.
    // Начальное скрытие навешивает сам JS (.anim-card), поэтому при отсутствии
    // JS, старом браузере или reduced-motion карточки остаются видимыми.
    (function () {
        'use strict';
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        if (!('IntersectionObserver' in window)) { return; }
        var GRIDS = '.imgcards-grid, .newslist-grid, .mediagallery-grid, .albums-grid, .persons-grid, .cards-grid, .cat-grid, .block-news__grid, .block-advantages__grid, .block-counters__grid, .docslist-grid, .docslist-acts, .contact-cards, .block-partners__grid, .block-team__grid, .block-projects__grid, .block-faq__list, .stages, .timeline-list, .featband, .media-list, .newsdocs-news, .newsdocs-docs';
        var sections = Array.prototype.slice.call(document.querySelectorAll('[data-reveal-items]'));
        if (!sections.length) { return; }
        var grids = document.querySelectorAll('[data-reveal-items] ' + GRIDS.split(', ').join(', [data-reveal-items] '));
        var io = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                (entry.target.__animCards || []).forEach(function (card, i) {
                    card.style.setProperty('--card-reveal-delay', Math.min(i * 60, 360) + 'ms');
                    card.classList.add('is-inview');
                });
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -6% 0px' });
        var staged = [];
        grids.forEach(function (grid) {
            var cards = Array.prototype.filter.call(grid.children, function (c) { return c.nodeType === 1; });
            if (cards.length < 2) { return; }
            var section = grid.closest('[data-reveal-items]');
            if (section) { section.classList.add('is-motion-ready'); }
            cards.forEach(function (c) { c.classList.add('anim-card'); });
            grid.__animCards = cards;
            staged.push(grid);
        });
        // Наблюдение — только после первой отрисовки: иначе для сетки в первом
        // экране `.anim-card` и `is-inview` появляются в одном кадре, начальное
        // скрытие ни разу не нарисовано и каскад не запускается.
        afterFirstPaint(function () {
            staged.forEach(function (grid) { io.observe(grid); });
        });

        // Секции без подходящей сетки (текст, баннер, одиночная карточка)
        // очередь строить не из чего, и раньше выбор «карточки по очереди»
        // не давал им ровно ничего. Настройка обязана что-то менять, поэтому
        // такая секция появляется целиком — обычным плавным проявлением.
        var plain = sections.filter(function (s) { return !s.classList.contains('is-motion-ready'); });
        if (plain.length && typeof window.asdrRevealObserve === 'function') {
            // Секция сейчас видима, а `data-reveal` задаёт ей opacity: 0 —
            // без сброса перехода она на глазах гасла бы за 0.75s, чтобы потом
            // проявиться снова. Прячем мгновенно и возвращаем переход кадром
            // позже, уже к настоящему появлению.
            plain.forEach(function (s) {
                s.style.transition = 'none';
                s.setAttribute('data-reveal-type', 'fade');
                s.setAttribute('data-reveal', '');
            });
            void document.body.offsetHeight;
            requestAnimationFrame(function () {
                plain.forEach(function (s) { s.style.transition = ''; });
                window.asdrRevealObserve(plain);
            });
        }

        // Страховка: если IntersectionObserver почему-то не сработал, любая
        // карточка, попавшая в область просмотра (скролл/ресайз/через 2.5с),
        // всё равно проявляется — контент никогда не остаётся скрытым.
        var failsafe = function () {
            var hidden = document.querySelectorAll('.anim-card:not(.is-inview)');
            if (!hidden.length) {
                window.removeEventListener('scroll', onScroll);
                window.removeEventListener('resize', onScroll);
                return;
            }
            hidden.forEach(function (c) {
                var r = c.getBoundingClientRect();
                if (r.top < window.innerHeight - 20 && r.bottom > 0) { c.classList.add('is-inview'); }
            });
        };
        var pending = false;
        var onScroll = function () {
            if (pending) { return; }
            pending = true;
            window.requestAnimationFrame(function () { pending = false; failsafe(); });
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        setTimeout(failsafe, 2500);
    })();

    // AJAX-фильтрация списков (новости, каталоги). Прогрессивное улучшение:
    // ссылки фильтров/пагинации и форма поиска остаются рабочими без JS, а
    // здесь мы лишь перехватываем их и подменяем область результатов.
    // Сервер отдаёт тот же список фрагментом по параметру _fragment=1.
    (function () {
        var listings = document.querySelectorAll('[data-listing]');
        if (!listings.length || !window.fetch || !window.history || !history.pushState) { return; }

        var FRAGMENT_PARAM = '_fragment';

        var fragmentUrl = function (url) {
            var u = new URL(url, location.href);
            u.searchParams.set(FRAGMENT_PARAM, '1');
            return u.toString();
        };

        // Адрес для истории браузера — без служебного параметра фрагмента.
        var publicUrl = function (url) {
            var u = new URL(url, location.href);
            u.searchParams.delete(FRAGMENT_PARAM);
            return u.pathname + (u.search === '?' ? '' : u.search);
        };

        listings.forEach(function (root) {
            var results = root.querySelector('[data-listing-results]');
            if (!results) { return; }

            var controller = null;
            var timer = null;

            var setBusy = function (busy) {
                results.setAttribute('aria-busy', busy ? 'true' : 'false');
                root.classList.toggle('listing--loading', busy);
            };

            // Активная «таблетка» рубрики подсвечивается сразу: ответ сервера
            // касается только результатов, состояние фильтров — на нас.
            // syncForm=true только когда адрес пришёл не из самой формы (клик по
            // «Сбросить», кнопка «назад»): иначе ответ затёр бы текст, который
            // посетитель успел дописать, пока летел запрос.
            var syncFilters = function (url, syncForm) {
                var target = new URL(url, location.href);
                root.querySelectorAll('.listing-filter__item').forEach(function (link) {
                    var href = new URL(link.getAttribute('href'), location.href);
                    var same = href.pathname === target.pathname
                        && (href.searchParams.get('category') || '') === (target.searchParams.get('category') || '');
                    link.classList.toggle('is-active', same);
                });
                var reset = root.querySelector('[data-listing-reset]');
                if (reset) { reset.hidden = !(target.searchParams.get('q') || ''); }

                if (!syncForm) { return; }
                var search = root.querySelector('[data-listing-form] input[type="search"]');
                if (search) { search.value = target.searchParams.get('q') || ''; }
                var sort = root.querySelector('[data-listing-form] select[name="sort"]');
                if (sort) { sort.value = target.searchParams.get('sort') || 'new'; }
            };

            var load = function (url, push, syncForm) {
                if (controller) { controller.abort(); }
                controller = ('AbortController' in window) ? new AbortController() : null;
                setBusy(true);

                fetch(fragmentUrl(url), {
                    credentials: 'same-origin',
                    signal: controller ? controller.signal : undefined
                }).then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.text();
                }).then(function (html) {
                    results.innerHTML = html;
                    syncFilters(url, syncForm);
                    if (push) { history.pushState({ listing: true }, '', publicUrl(url)); }
                    setBusy(false);
                }).catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    // Любая ошибка — обычный переход: посетитель не должен
                    // остаться со старым списком и без объяснений.
                    location.href = publicUrl(url);
                });
            };

            // Клики по рубрикам и страницам.
            root.addEventListener('click', function (e) {
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
                var link = e.target.closest ? e.target.closest('.listing-filter__item, .listing-pager__item, [data-listing-reset]') : null;
                if (!link || link.tagName !== 'A' || !link.getAttribute('href')) { return; }
                e.preventDefault();
                load(link.getAttribute('href'), true, true);
                // Пагинация уводит взгляд наверх списка, фильтры — нет.
                if (link.classList.contains('listing-pager__item')) {
                    root.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });

            // Форма поиска/сортировки каталога.
            var form = root.querySelector('[data-listing-form]');
            if (form) {
                var formUrl = function () {
                    var params = new URLSearchParams(new FormData(form));
                    // Пустые значения и сортировку по умолчанию в адрес не тащим.
                    if (!params.get('q')) { params.delete('q'); }
                    if (params.get('sort') === 'new') { params.delete('sort'); }
                    var qs = params.toString();
                    return form.getAttribute('action') + (qs ? '?' + qs : '');
                };
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    load(formUrl(), true, false);
                });
                form.addEventListener('change', function (e) {
                    if (e.target && e.target.name === 'sort') { load(formUrl(), true, false); }
                });
                form.addEventListener('input', function (e) {
                    if (!e.target || e.target.type !== 'search') { return; }
                    if (timer) { clearTimeout(timer); }
                    timer = setTimeout(function () { load(formUrl(), true, false); }, 400);
                });
            }

            window.addEventListener('popstate', function () {
                load(location.href, false, true);
            });
        });
    })();

    // Живой поиск: под полем в шапке показываем несколько найденных страниц,
    // не уходя на страницу результатов. Прогрессивное улучшение — форма
    // остаётся обычной формой и без JS работает как раньше.
    (function () {
        var inputs = document.querySelectorAll('.site-search input[type="search"], .site-search-overlay__form input[type="search"]');
        if (!inputs.length || !window.fetch) { return; }

        inputs.forEach(function (input, inputIndex) {
            var form = input.form;
            if (!form) { return; }

            var panel = document.createElement('div');
            panel.className = 'search-suggest';
            panel.id = 'search-suggest-' + inputIndex;
            panel.setAttribute('aria-live', 'polite');
            panel.hidden = true;
            form.appendChild(panel);
            form.classList.add('has-suggest');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', panel.id);

            var timer = null;
            var controller = null;
            var suggestionLinks = function () {
                return Array.prototype.slice.call(panel.querySelectorAll('a[href]'));
            };

            var close = function () {
                panel.hidden = true;
                panel.innerHTML = '';
                input.setAttribute('aria-expanded', 'false');
            };

            var load = function (query) {
                if (controller) { controller.abort(); }
                controller = ('AbortController' in window) ? new AbortController() : null;

                // Адрес подсказок наследует язык от action формы (/uz/search).
                var url = form.getAttribute('action') + '/suggest?q=' + encodeURIComponent(query);
                fetch(url, {
                    credentials: 'same-origin',
                    signal: controller ? controller.signal : undefined
                }).then(function (r) {
                    return r.ok ? r.text() : '';
                }).then(function (html) {
                    if (!html) { close(); return; }
                    panel.innerHTML = html;
                    panel.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }).catch(function (err) {
                    // Отмена — норма; всё остальное просто оставляет форму
                    // обычной формой, поиск по Enter продолжает работать.
                    if (!err || err.name !== 'AbortError') { close(); }
                });
            };

            input.addEventListener('input', function () {
                var query = input.value.trim();
                if (timer) { clearTimeout(timer); }
                if (query.length < 2) { close(); return; }
                timer = setTimeout(function () { load(query); }, 250);
            });

            input.addEventListener('focus', function () {
                if (input.value.trim().length >= 2 && panel.innerHTML !== '') {
                    panel.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                }
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown' && !panel.hidden) {
                    var firstLink = suggestionLinks()[0];
                    if (firstLink) {
                        e.preventDefault();
                        firstLink.focus();
                    }
                } else if (e.key === 'Escape' && !panel.hidden) {
                    e.preventDefault();
                    close();
                }
            });

            panel.addEventListener('keydown', function (e) {
                var links = suggestionLinks();
                var current = links.indexOf(document.activeElement);
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close();
                    input.focus();
                } else if (e.key === 'ArrowDown' && links.length) {
                    e.preventDefault();
                    links[(current + 1 + links.length) % links.length].focus();
                } else if (e.key === 'ArrowUp' && links.length) {
                    e.preventDefault();
                    if (current <= 0) {
                        input.focus();
                    } else {
                        links[current - 1].focus();
                    }
                }
            });

            document.addEventListener('click', function (e) {
                if (!form.contains(e.target)) { close(); }
            });
        });
    })();

    // Интерактивный «прожектор» нужен только точному указателю. Обновление
    // ограничено одним разом за кадр, чтобы mousemove не перегружал страницу.
    (function () {
        if (!window.matchMedia
            || !window.matchMedia('(hover: hover) and (pointer: fine)').matches
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        var pending = false;
        var lastEvent = null;
        document.addEventListener('mousemove', function (event) {
            lastEvent = event;
            if (pending) { return; }
            pending = true;
            window.requestAnimationFrame(function () {
                pending = false;
                var e = lastEvent;
                if (!e || !e.target || !e.target.closest) { return; }
                var el = e.target.closest(
                    '.cat-tile, .contact-card, .project-card, .team-card, .feature-card, .news-card, .person-card, .album-card, .doc-card, .act-card, .catcard, .testimonial, .block-advantages__item, .mediacard, .imgcard, .faq-item, .stage, .timeline-item, ' +
                    '.btn, .block-cta__button, .btn-cta, .block-hero__button, .timeline-card__button, .timeline-cta__button, ' +
                    '.a11y-toggle, .site-theme-toggle, .site-search-toggle'
                );
                if (!el) { return; }
                var rect = el.getBoundingClientRect();
                el.style.setProperty('--mouse-x', (e.clientX - rect.left) + 'px');
                el.style.setProperty('--mouse-y', (e.clientY - rect.top) + 'px');
            });
        }, { passive: true });
    })();

    // Индикатор прогресса прокрутки страницы
    (function () {
        var bar = document.getElementById('site-scroll-progress-bar');
        if (!bar) { return; }
        // scrollHeight читается не на каждом событии прокрутки, а раз в кадр:
        // это чтение заставляет браузер пересчитать раскладку, и на инерционной
        // прокрутке (несколько событий в кадр) оно и делало ход рваным.
        var height = 0;
        var ticking = false;
        var measure = function () {
            height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        };
        var paint = function () {
            ticking = false;
            var winScroll = window.scrollY || document.documentElement.scrollTop || 0;
            var scrolled = height > 0 ? (winScroll / height) * 100 : 0;
            bar.style.setProperty('--scroll-progress', Math.min(Math.max(scrolled, 0), 100) + '%');
        };
        var update = function () {
            if (ticking) { return; }
            ticking = true;
            window.requestAnimationFrame(paint);
        };
        window.addEventListener('scroll', update, { passive: true });
        // Высота документа меняется от ресайза и от догруженных картинок,
        // а не от самой прокрутки.
        window.addEventListener('resize', function () { measure(); update(); }, { passive: true });
        window.addEventListener('load', function () { measure(); update(); });
        measure();
        paint();
    })();

    // Движок Toast-уведомлений
    window.showToast = function (message, type, duration) {
        var container = document.getElementById('site-toast-container');
        if (!container) { return; }
        type = type || 'info';
        duration = duration || 3500;
        if (['info', 'success', 'warning', 'error'].indexOf(type) === -1) {
            type = 'info';
        }

        var toast = document.createElement('div');
        toast.className = 'site-toast site-toast--' + type;
        
        var icon = asdrIcon('circle-check', 18);
        if (type === 'error') {
            icon = asdrIcon('circle-x', 18);
        }

        toast.innerHTML = icon;
        var messageEl = document.createElement('span');
        messageEl.className = 'site-toast__msg';
        messageEl.textContent = String(message == null ? '' : message);
        toast.appendChild(messageEl);
        container.appendChild(toast);

        setTimeout(function () {
            toast.classList.add('is-leaving');
            setTimeout(function () {
                if (toast.parentNode) { toast.parentNode.removeChild(toast); }
            }, 300);
        }, duration);
    };

    // Быстрый поиск по сочетанию клавиш Ctrl + K / Cmd + K
    (function () {
        var modal = document.getElementById('site-quick-search-modal');
        var input = document.getElementById('site-quick-search-input');
        if (!modal || !input) { return; }
        var lastFocus = null;
        var closeTimer = null;

        var open = function () {
            if (closeTimer) {
                clearTimeout(closeTimer);
                closeTimer = null;
            }
            lastFocus = document.activeElement;
            modal.hidden = false;
            modal.classList.add('is-open');
            document.body.classList.add('quick-search-active');
            setTimeout(function () { input.focus(); }, 50);
        };
        var close = function () {
            if (modal.hidden) { return; }
            modal.classList.remove('is-open');
            document.body.classList.remove('quick-search-active');
            closeTimer = setTimeout(function () {
                modal.hidden = true;
                closeTimer = null;
                if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
            }, 200);
        };

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (modal.hidden) { open(); } else { close(); }
            } else if (e.key === 'Escape' && !modal.hidden) {
                e.preventDefault();
                close();
            } else if (e.key === 'Tab' && !modal.hidden) {
                var focusable = Array.prototype.filter.call(
                    modal.querySelectorAll('button:not([disabled]), input:not([disabled]), a[href]'),
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

        modal.querySelectorAll('[data-quick-search-close]').forEach(function (btn) {
            btn.addEventListener('click', close);
        });
    })();

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

    // === Универсальный Lightbox для фото в новостях и статьях ===
    (function () {
        // В галерее берём только большие слайды. Миниатюры повторяют те же
        // файлы и раньше удваивали счётчик lightbox (2 фото отображались как 4).
        var images = Array.prototype.slice.call(document.querySelectorAll('.rich-content img, .newsdetail-article img, .newsdetail-gallery__main img'));
        if (!images.length) { return; }

        var modal = document.createElement('div');
        modal.className = 'rich-lightbox-modal';
        modal.hidden = true;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', label('imageViewer', 'Просмотр изображения'));

        modal.innerHTML =
            '<div class="rich-lightbox-backdrop" data-lightbox-close></div>' +
            '<div class="rich-lightbox-bar">' +
                '<span class="rich-lightbox-counter" data-lightbox-counter>1 / 1</span>' +
                '<div class="rich-lightbox-actions">' +
                    '<a class="rich-lightbox-btn" data-lightbox-download download target="_blank" rel="noopener" title="' + label('downloadPhoto', 'Скачать фото') + '" aria-label="' + label('download', 'Скачать') + '">' +
                        asdrIcon('download', 18) +
                    '</a>' +
                    '<button type="button" class="rich-lightbox-btn" data-lightbox-close aria-label="' + label('close', 'Закрыть') + '">' + asdrIcon('x', 18) + '</button>' +
                '</div>' +
            '</div>' +
            '<div class="rich-lightbox-stage">' +
                '<button type="button" class="rich-lightbox-nav rich-lightbox-nav--prev" data-lightbox-prev aria-label="' + label('previousPhoto', 'Предыдущее фото') + '">' + asdrIcon('chevron-left', 24) + '</button>' +
                '<div class="rich-lightbox-content">' +
                    '<img class="rich-lightbox-img" data-lightbox-img src="" alt="">' +
                    '<div class="rich-lightbox-caption" data-lightbox-caption></div>' +
                '</div>' +
                '<button type="button" class="rich-lightbox-nav rich-lightbox-nav--next" data-lightbox-next aria-label="' + label('nextPhoto', 'Следующее фото') + '">' + asdrIcon('chevron-right', 24) + '</button>' +
            '</div>';

        document.body.appendChild(modal);

        var modalImg = modal.querySelector('[data-lightbox-img]');
        var modalCaption = modal.querySelector('[data-lightbox-caption]');
        var modalCounter = modal.querySelector('[data-lightbox-counter]');
        var modalDownload = modal.querySelector('[data-lightbox-download]');
        var prevBtn = modal.querySelector('[data-lightbox-prev]');
        var nextBtn = modal.querySelector('[data-lightbox-next]');

        var currentIndex = 0;
        var validImages = [];
        var modalImages = [];
        var lightboxLastFocus = null;

        // Галерея и изображения внутри текста — разные смысловые наборы.
        // Поэтому счётчик галереи не должен включать фото из текста новости.
        var selectImageGroup = function (img) {
            var gallery = img.closest('[data-ndgallery]');
            if (gallery) {
                return validImages.filter(function (candidate) {
                    return candidate.closest('[data-ndgallery]') === gallery;
                });
            }
            return validImages.filter(function (candidate) {
                return !candidate.closest('[data-ndgallery]');
            });
        };

        var openModal = function (trigger) {
            lightboxLastFocus = trigger || document.activeElement;
            modal.hidden = false;
            document.body.classList.add('lightbox-active');
            var closeButton = modal.querySelector('button[data-lightbox-close]');
            if (closeButton) { closeButton.focus(); }
        };

        images.forEach(function (img) {
            if (img.width > 0 && img.width < 80 && img.height > 0 && img.height < 80) { return; }

            var trigger = img.closest('a[href]') || img;
            img.classList.add('is-lightboxable');
            img.setAttribute('title', label('zoomImage', 'Нажмите для увеличения'));
            if (trigger === img) {
                img.setAttribute('role', 'button');
                img.setAttribute('tabindex', '0');
                img.setAttribute(
                    'aria-label',
                    (img.getAttribute('alt') ? img.getAttribute('alt') + '. ' : '')
                        + label('zoomImage', 'Нажмите для увеличения')
                );
            }
            validImages.push(img);

            var triggerTarget = function () {
                var gallery = img.closest('[data-ndgallery]');
                if (gallery) {
                    var activeImg = gallery.querySelector('.newsdetail-gallery__slide.is-active');
                    if (activeImg) { return activeImg; }
                }
                return img;
            };

            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                var targetImg = triggerTarget();
                modalImages = selectImageGroup(targetImg);
                var idx = modalImages.indexOf(targetImg);
                if (idx !== -1) {
                    showIndex(idx);
                    openModal(trigger);
                }
            });
            trigger.addEventListener('keydown', function (e) {
                // Ссылка сама создаст click по Enter; для изображения обрабатываем
                // Enter и Space, для ссылки — только Space.
                if (e.key !== ' ' && !(trigger === img && e.key === 'Enter')) { return; }
                e.preventDefault();
                var targetImg = triggerTarget();
                modalImages = selectImageGroup(targetImg);
                var idx = modalImages.indexOf(targetImg);
                if (idx !== -1) {
                    showIndex(idx);
                    openModal(trigger);
                }
            });
        });

        document.querySelectorAll('[data-ndgallery] .newsdetail-gallery__main').forEach(function (main) {
            main.addEventListener('click', function (e) {
                if (e.target.closest('button, [data-ndg-prev], [data-ndg-next], [data-ndg-thumb], [data-ndg-counter]')) {
                    return;
                }
                var gallery = main.closest('[data-ndgallery]');
                var activeImg = (gallery ? gallery.querySelector('.newsdetail-gallery__slide.is-active') : null)
                    || main.querySelector('.newsdetail-gallery__slide');
                if (!activeImg) { return; }
                e.preventDefault();
                modalImages = selectImageGroup(activeImg);
                var idx = modalImages.indexOf(activeImg);
                if (idx !== -1) {
                    showIndex(idx);
                    openModal(activeImg);
                }
            });
        });

        if (!validImages.length) { return; }

        var showIndex = function (idx) {
            if (!modalImages.length) { modalImages = validImages.slice(); }
            if (idx < 0) { idx = modalImages.length - 1; }
            if (idx >= modalImages.length) { idx = 0; }
            currentIndex = idx;

            var target = modalImages[currentIndex];
            var src = target.currentSrc || target.getAttribute('src');
            var alt = target.getAttribute('alt') || '';

            var fig = target.closest('figure');
            var figCap = fig ? fig.querySelector('figcaption') : null;
            var captionText = figCap ? figCap.innerText : alt;
            var gallery = target.closest('[data-ndgallery]');
            if (gallery) {
                var gallerySlides = Array.prototype.slice.call(gallery.querySelectorAll('.newsdetail-gallery__slide'));
                var galleryIndex = gallerySlides.indexOf(target);
                if (typeof gallery.__ndgShow === 'function' && galleryIndex !== -1) {
                    gallery.__ndgShow(galleryIndex);
                }
                var galleryCaption = gallery.querySelector('[data-ndg-captions]');
                var galleryCaptions = [];
                if (galleryCaption) {
                    try {
                        galleryCaptions = JSON.parse(galleryCaption.getAttribute('data-ndg-captions') || '[]');
                    } catch (e) {
                        galleryCaptions = [];
                    }
                }
                if (galleryIndex !== -1 && galleryCaptions[galleryIndex]) {
                    var itemCaption = galleryCaptions[galleryIndex].caption || '';
                    var itemCredit = galleryCaptions[galleryIndex].credit || '';
                    captionText = itemCaption;
                    if (itemCredit) {
                        captionText += (captionText ? ' · ' : '') + label('photoCredit', 'Фото:') + ' ' + itemCredit;
                    }
                }
            }

            modalImg.src = src;
            modalImg.alt = alt;
            modalDownload.href = src;

            if (captionText && captionText.trim() !== '') {
                modalCaption.innerText = captionText.trim();
                modalCaption.hidden = false;
            } else {
                modalCaption.hidden = true;
                modalCaption.innerText = '';
            }

            modalCounter.innerText = (currentIndex + 1) + ' / ' + modalImages.length;
            prevBtn.hidden = modalImages.length <= 1;
            nextBtn.hidden = modalImages.length <= 1;
        };

        var closeModal = function () {
            if (modal.hidden) { return; }
            modal.hidden = true;
            document.body.classList.remove('lightbox-active');
            if (lightboxLastFocus && lightboxLastFocus.focus) { lightboxLastFocus.focus(); }
        };

        modal.querySelectorAll('[data-lightbox-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });

        prevBtn.addEventListener('click', function () { showIndex(currentIndex - 1); });
        nextBtn.addEventListener('click', function () { showIndex(currentIndex + 1); });

        document.addEventListener('keydown', function (e) {
            if (modal.hidden) { return; }
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeModal();
            } else if (e.key === 'ArrowLeft' || e.keyCode === 37) {
                showIndex(currentIndex - 1);
            } else if (e.key === 'ArrowRight' || e.keyCode === 39) {
                showIndex(currentIndex + 1);
            } else if (e.key === 'Tab') {
                var focusable = Array.prototype.filter.call(
                    modal.querySelectorAll('button:not([disabled]), a[href]'),
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

    /* ==========================================================================
       1. Быстрый шеринг выделенной цитаты (Quote-Share Toolbar)
       ========================================================================== */
    (function () {
        var popover = document.createElement('div');
        popover.className = 'quote-share-popover';
        popover.hidden = true;
        popover.innerHTML =
            '<button type="button" class="quote-share-btn" data-action="tg">' +
                asdrIcon('brand-telegram', 14) + ' Telegram' +
            '</button>' +
            '<button type="button" class="quote-share-btn" data-action="copy">' +
                asdrIcon('copy', 14) + ' ' + label('copy', 'Копировать') +
            '</button>';
        document.body.appendChild(popover);

        var currentSelectedText = '';

        var handleSelection = function () {
            var sel = window.getSelection();
            if (!sel || sel.isCollapsed) {
                popover.hidden = true;
                return;
            }
            var text = sel.toString().trim();
            if (text.length < 8) {
                popover.hidden = true;
                return;
            }
            var anchor = sel.anchorNode;
            if (!anchor) { return; }
            var parent = anchor.nodeType === 3 ? anchor.parentNode : anchor;
            if (!parent.closest('.rich-content, .newsdetail-article, .newsdetail')) {
                popover.hidden = true;
                return;
            }

            currentSelectedText = text;
            var range = sel.getRangeAt(0);
            var rect = range.getBoundingClientRect();

            popover.style.setProperty('--quote-popover-top', (window.scrollY + rect.top - 48) + 'px');
            popover.style.setProperty('--quote-popover-left', (window.scrollX + rect.left + (rect.width / 2)) + 'px');
            popover.hidden = false;
        };

        document.addEventListener('mouseup', function () { setTimeout(handleSelection, 10); });
        document.addEventListener('keyup', function () { setTimeout(handleSelection, 10); });

        popover.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) { return; }
            var action = btn.getAttribute('data-action');
            if (action === 'tg') {
                var url = 'https://t.me/share/url?url=' + encodeURIComponent(window.location.href) + '&text=' + encodeURIComponent('«' + currentSelectedText + '»');
                window.open(url, '_blank', 'noopener');
            } else if (action === 'copy') {
                var copyText = '«' + currentSelectedText + '» — ' + window.location.href;
                navigator.clipboard.writeText(copyText).then(function () {
                    btn.innerHTML = asdrIcon('check', 14) + ' ' + label('copied', 'Скопировано');
                    setTimeout(function () {
                        btn.innerHTML = asdrIcon('copy', 14) + ' '
                            + label('copy', 'Копировать');
                    }, 2000);
                });
            }
        });
    })();

    /* ==========================================================================
       2. Интерактивные опросы (Poll AJAX)
       ========================================================================== */
    document.querySelectorAll('[data-poll-form]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var card = form.closest('.news-poll-card');
            if (!card) { return; }
            var pollId = card.getAttribute('data-poll-id');
            var selected = form.querySelector('input[name="poll_option"]:checked');
            if (!selected) { return; }

            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }

            var body = new URLSearchParams();
            body.append('option', selected.value);
            var csrf = form.querySelector('input[name="csrf_token"]');
            if (csrf) { body.append('csrf_token', csrf.value); }

            fetch('/api/polls/' + pollId + '/vote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || 'HTTP ' + res.status);
                    }
                    return data;
                });
            }).then(function (data) {
                if (!data.results) { throw new Error('Invalid poll response'); }
                var resDiv = card.querySelector('.news-poll-card__results');
                if (!resDiv) { return; }

                resDiv.textContent = '';
                (Array.isArray(data.results.items) ? data.results.items : []).forEach(function (item) {
                    var percent = Number(item.percent);
                    percent = Number.isFinite(percent) ? Math.min(100, Math.max(0, percent)) : 0;
                    var votes = Number.parseInt(item.votes, 10);
                    votes = Number.isFinite(votes) ? Math.max(0, votes) : 0;

                    var row = document.createElement('div');
                    row.className = 'news-poll-res-row';
                    var info = document.createElement('div');
                    info.className = 'news-poll-res-info';
                    var resultLabel = document.createElement('span');
                    resultLabel.className = 'news-poll-res-label';
                    resultLabel.textContent = String(item.label == null ? '' : item.label);
                    var value = document.createElement('span');
                    value.className = 'news-poll-res-val';
                    value.textContent = percent + '% (' + votes + ')';
                    info.appendChild(resultLabel);
                    info.appendChild(value);

                    var track = document.createElement('div');
                    track.className = 'news-poll-bar-track';
                    track.setAttribute('role', 'progressbar');
                    track.setAttribute('aria-valuemin', '0');
                    track.setAttribute('aria-valuemax', '100');
                    track.setAttribute('aria-valuenow', String(percent));
                    track.setAttribute('aria-label', resultLabel.textContent);
                    var fill = document.createElement('div');
                    fill.className = 'news-poll-bar-fill';
                    fill.style.setProperty('--poll-percent', percent + '%');
                    track.appendChild(fill);

                    row.appendChild(info);
                    row.appendChild(track);
                    resDiv.appendChild(row);
                });

                var meta = document.createElement('div');
                meta.className = 'news-poll-card__meta';
                meta.appendChild(document.createTextNode(label('totalVotes', 'Всего голосов:') + ' '));
                var total = document.createElement('strong');
                var totalValue = Number.parseInt(data.results.total, 10);
                total.textContent = String(Number.isFinite(totalValue) ? Math.max(0, totalValue) : 0);
                meta.appendChild(total);
                resDiv.appendChild(meta);
                resDiv.hidden = false;
                form.hidden = true;
            }).catch(function (err) {
                if (btn) { btn.disabled = false; }
                if (window.showToast) {
                    window.showToast(err && err.message ? err.message : 'Request failed', 'error');
                }
            });
        });
    });

    // Документы: локальный поиск и фильтр форматов без сетевого запроса.
    document.querySelectorAll('[data-document-list]').forEach(function (list) {
        var query = list.querySelector('[data-document-query]');
        var kind = list.querySelector('[data-document-kind-filter]');
        var cards = Array.prototype.slice.call(list.querySelectorAll('[data-document-card]'));
        var empty = list.querySelector('[data-document-empty]');
        var count = list.querySelector('[data-document-count]');
        if (!cards.length || (!query && !kind)) { return; }

        var apply = function () {
            var needle = query ? query.value.trim().toLocaleLowerCase() : '';
            var selectedKind = kind ? kind.value : '';
            var visible = 0;
            cards.forEach(function (card) {
                var matchesText = needle === '' || (card.getAttribute('data-document-search') || '').indexOf(needle) !== -1;
                var matchesKind = selectedKind === '' || card.getAttribute('data-document-kind') === selectedKind;
                card.hidden = !(matchesText && matchesKind);
                if (!card.hidden) { visible++; }
            });
            if (empty) { empty.hidden = visible !== 0; }
            if (count) { count.textContent = visible + ' / ' + cards.length; }
        };
        if (query) { query.addEventListener('input', apply); }
        if (kind) { kind.addEventListener('change', apply); }
        apply();
    });

    // FAQ: поиск, категории, одиночный режим и прямые ссылки на ответы.
    document.querySelectorAll('[data-faq-list]').forEach(function (list) {
        var query = list.querySelector('[data-faq-query]');
        var category = list.querySelector('[data-faq-category]');
        var items = Array.prototype.slice.call(list.querySelectorAll('[data-faq-item]'));
        var empty = list.querySelector('[data-faq-empty]');

        var apply = function () {
            var needle = query ? query.value.trim().toLocaleLowerCase() : '';
            var selectedCategory = category ? category.value : '';
            var visible = 0;
            items.forEach(function (item) {
                var matchesText = needle === '' || (item.getAttribute('data-faq-search') || '').indexOf(needle) !== -1;
                var matchesCategory = selectedCategory === '' || item.getAttribute('data-faq-category-value') === selectedCategory;
                item.hidden = !(matchesText && matchesCategory);
                if (!item.hidden) { visible++; }
            });
            if (empty) { empty.hidden = visible !== 0; }
        };
        if (query) { query.addEventListener('input', apply); }
        if (category) { category.addEventListener('change', apply); }

        items.forEach(function (item) {
            item.addEventListener('toggle', function () {
                if (!item.open) { return; }
                if (list.hasAttribute('data-faq-single')) {
                    items.forEach(function (other) {
                        if (other !== item) { other.open = false; }
                    });
                }
                if (item.id && window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + item.id);
                }
            });
        });

        if (window.location.hash) {
            var target = null;
            try {
                target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
            } catch (e) {
                target = null;
            }
            if (target && list.contains(target) && target.matches('[data-faq-item]')) { target.open = true; }
        }
        apply();
    });

    // Карта по нажатию: внешний iframe не получает запрос до согласия.
    document.querySelectorAll('[data-map-embed]').forEach(function (container) {
        var button = container.querySelector('[data-map-load]');
        var src = container.getAttribute('data-map-src') || '';
        if (!button || !src) { return; }
        button.addEventListener('click', function () {
            var frame = document.createElement('iframe');
            frame.className = 'block-map__frame';
            frame.src = src;
            frame.loading = 'lazy';
            frame.referrerPolicy = 'no-referrer-when-downgrade';
            frame.title = button.textContent.trim();
            container.replaceChildren(frame);
        });
    });

    var copyWithFallback = function (value, done) {
        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.className = 'clipboard-copy-proxy';
        document.body.appendChild(input);
        input.select();
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (e) {
            copied = false;
        }
        input.remove();
        if (copied) { done(); }
    };

    document.querySelectorAll('[data-copy-text]').forEach(function (button) {
        button.addEventListener('click', function () {
            var value = button.getAttribute('data-copy-text') || '';
            var done = function () {
                if (window.showToast) { window.showToast(button.getAttribute('data-copy-success') || value, 'success'); }
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done).catch(function () {
                    copyWithFallback(value, done);
                });
            } else {
                copyWithFallback(value, done);
            }
        });
    });

    // Оргструктура остаётся полной на desktop и становится аккордеоном на
    // узком экране. Если в блоке включено сворачивание, аккордеон работает на
    // любой ширине. Без JavaScript все подразделения по-прежнему видны.
    document.querySelectorAll('[data-org-branch]').forEach(function (branch, index) {
        var button = branch.querySelector('[data-org-toggle]');
        var units = branch.querySelector('[data-org-units]');
        if (!button || !units) { return; }
        var always = branch.closest('[data-org-collapsible]') !== null;
        var mobile = window.matchMedia('(max-width: 700px)');
        var expanded = index === 0;
        var sync = function () {
            var collapse = always || mobile.matches;
            units.hidden = collapse && !expanded;
            button.setAttribute('aria-expanded', String(!collapse || expanded));
        };
        button.addEventListener('click', function () {
            expanded = !expanded;
            sync();
        });
        if (mobile.addEventListener) { mobile.addEventListener('change', sync); }
        sync();
    });
})();
