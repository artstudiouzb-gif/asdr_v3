// PDF книгой с листанием (страница /pdf?view=book, см. Site\PdfController).
// Страницы рисует PDF.js, листает StPageFlip (холст: лист гнётся под пальцем,
// с бликом и тенью). Оба приходят со своего домена.
//
// Память: страница книги — холст в разрешении экрана, и на сотню страниц их
// не напасёшься. Поэтому нарисованы только страницы рядом с открытым
// разворотом, остальные возвращаются к чистому листу и рисуются заново, когда
// до них долистают. Лупа рисует страницу заново в нужном масштабе, а не
// растягивает картинку — текст в ней такой же чёткий, как в документе.
const root = document.querySelector('[data-pdf-book]');

if (root && window.St && window.St.PageFlip) {
    const $ = (selector) => root.querySelector(selector);
    const stage = $('[data-book-stage]');
    const shift = $('[data-book-shift]');
    const host = $('[data-book]');
    const statusNode = $('[data-book-status]');
    const progressNode = $('[data-load-progress]');
    const percentNode = $('[data-load-percent]');
    const nav = $('[data-book-nav]');
    const countNode = $('[data-book-count]');
    const prevButton = $('[data-book-prev]');
    const nextButton = $('[data-book-next]');
    const slider = $('[data-book-slider]');
    const thumbs = $('[data-book-thumbs]');
    const zoomButton = $('[data-book-zoom-open]');
    const zoom = $('[data-book-zoom]');
    const zoomScroll = $('[data-zoom-scroll]');
    const zoomSpread = $('[data-zoom-spread]');
    const zoomCount = $('[data-zoom-count]');
    const zoomIn = $('[data-zoom-step="1"]');
    const zoomOut = $('[data-zoom-step="-1"]');
    const zoomClose = $('[data-zoom-close]');
    const fullscreenButton = $('[data-book-fullscreen]');
    const edgeLeft = $('[data-book-edge="left"]');
    const edgeRight = $('[data-book-edge="right"]');

    const label = (name, fallback) => root.getAttribute('data-label-' + name) || fallback;
    const format = (template, values) => template.replace(/%(\d)/g, (m, n) => String(values[Number(n) - 1]));
    // Ссылка на файл бывает переписана на CDN — файл берём со своего адреса:
    // запрос к чужому домену без CORS браузер не выполнит.
    const fileUrl = window.location.origin + new URL(root.getAttribute('data-file') || '', window.location.origin).pathname;
    const vendor = new URL('/assets/vendor/pdfjs/', window.location.origin).href;

    const PAPER = '#fdfcf9';
    const EDGE_MAX = 10;        // толщина стопки страниц по краю, px
    const NEAR = 3;             // сколько страниц рисуется по обе стороны разворота
    const MAX_PAGE_WIDTH = 1600;
    const MAX_CANVAS_PIXELS = 12e6;
    const ZOOM_STEPS = [1, 1.5, 2, 3];

    const reduceMotion = () => (typeof window.asdrReduceMotion === 'function'
        ? window.asdrReduceMotion()
        : window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    let pdf = null;
    let flip = null;
    let total = 0;
    let ratio = Math.SQRT2;
    let orientation = '';
    let blank = null;
    let zoomLevel = 1;
    let zoomJob = 0;
    const baked = new Map();     // номер страницы → ориентация, под которую она нарисована
    const pending = new Set();
    let drawing = false;

    const fail = (error) => {
        // Причина остаётся в консоли: на экране посетителю она ни к чему,
        // а без неё отказ на живом сайте нечем разобрать.
        console.error('PDF book:', error);
        stage.hidden = true;
        nav.hidden = true;
        thumbs.hidden = true;
        statusNode.hidden = false;
        statusNode.classList.add('pdf-book__status--error');
        statusNode.setAttribute('role', 'alert');
        statusNode.textContent = label('error', '');
    };

    const current = () => (flip ? flip.getCurrentPageIndex() : 0);
    const portrait = () => flip !== null && flip.getOrientation() === 'portrait';

    // Видимые страницы: обложка и последняя страница стоят одни, в книжной
    // ориентации — по одной, иначе разворот.
    const visible = () => {
        const index = current();
        if (portrait() || index === 0 || index === total - 1) {
            return [index];
        }
        const left = index % 2 ? index : index - 1;
        return left + 1 < total ? [left, left + 1] : [left];
    };

    // Сторона листа: от неё зависит, у какого края корешок.
    const side = (index) => (orientation === 'portrait' || index === 0 || index % 2 === 0 ? 'right' : 'left');
    const isCover = (index) => index === 0 || (index === total - 1 && total % 2 === 0);

    // Тени у корешка и глянец мягкой обложки рисуются прямо на странице:
    // тогда при перелистывании они изгибаются вместе с листом.
    const bake = (canvas, index) => {
        const ctx = canvas.getContext('2d');
        const w = canvas.width;
        const h = canvas.height;
        const right = side(index) === 'right';
        const spine = right ? ctx.createLinearGradient(0, 0, w, 0) : ctx.createLinearGradient(w, 0, 0, 0);
        if (isCover(index)) {
            // Мягкая обложка: продавленная линия-«петля» у корешка и блик.
            [[0, '.30'], [0.014, '.04']].forEach(([at, a]) => spine.addColorStop(at, 'rgba(0,0,0,' + a + ')'));
            spine.addColorStop(0.032, 'rgba(255,255,255,.20)');
            [[0.042, '.16'], [0.052, '.05'], [0.075, '0'], [1, '0']].forEach(([at, a]) => spine.addColorStop(at, 'rgba(0,0,0,' + a + ')'));
            ctx.fillStyle = spine;
            ctx.fillRect(0, 0, w, h);
            const gloss = right ? ctx.createLinearGradient(0, 0, w, h) : ctx.createLinearGradient(w, 0, 0, h);
            [[0, '.20'], [0.35, '0'], [0.75, '0'], [1, '.06']].forEach(([at, a]) => gloss.addColorStop(at, 'rgba(255,255,255,' + a + ')'));
            ctx.fillStyle = gloss;
        } else {
            [[0, '.15'], [0.025, '.05'], [0.09, '0'], [0.985, '0'], [1, '.04']].forEach(([at, a]) => spine.addColorStop(at, 'rgba(0,0,0,' + a + ')'));
            ctx.fillStyle = spine;
        }
        ctx.fillRect(0, 0, w, h);
    };

    const renderPage = async (index, cssWidth) => {
        const page = await pdf.getPage(index + 1);
        const unscaled = page.getViewport({ scale: 1 });
        let width = Math.min(MAX_PAGE_WIDTH, Math.max(600, Math.round(cssWidth * Math.min(window.devicePixelRatio || 1, 2))));
        if (width * width * (unscaled.height / unscaled.width) > MAX_CANVAS_PIXELS) {
            width = Math.floor(Math.sqrt(MAX_CANVAS_PIXELS * unscaled.width / unscaled.height));
        }
        const viewport = page.getViewport({ scale: width / unscaled.width });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        await page.render({ canvas, viewport }).promise;
        return canvas;
    };

    const setPageImage = (index, image) => {
        const page = flip.getPage(index);
        if (page) {
            page.image = image;
            page.isLoad = true;
        }
    };

    // Рисуем страницы у открытого разворота, ближние — первыми; дальние
    // возвращаем к чистому листу.
    const schedule = () => {
        if (!flip) {
            return;
        }
        const view = visible();
        const from = Math.max(0, view[0] - NEAR);
        const to = Math.min(total - 1, view[view.length - 1] + NEAR);
        baked.forEach((orient, index) => {
            if (index < from || index > to) {
                setPageImage(index, blank);
                baked.delete(index);
            }
        });
        pending.clear();
        for (let index = from; index <= to; index++) {
            if (baked.get(index) !== orientation) {
                pending.add(index);
            }
        }
        drain();
    };

    const drain = async () => {
        if (drawing) {
            return;
        }
        drawing = true;
        try {
            while (pending.size > 0) {
                const anchor = visible()[0];
                const index = [...pending].sort((a, b) => Math.abs(a - anchor) - Math.abs(b - anchor))[0];
                pending.delete(index);
                const orient = orientation;
                const canvas = await renderPage(index, flip.getRender().getRect().pageWidth);
                if (orient !== orientation) {
                    pending.add(index);
                    continue;
                }
                bake(canvas, index);
                setPageImage(index, canvas);
                baked.set(index, orient);
            }
        } catch (error) {
            fail(error);
        } finally {
            drawing = false;
        }
    };

    // Холст StPageFlip рисуется в CSS-пикселях — на плотном экране страница
    // мылится. Даём ему разрешение экрана, а фон вокруг книги оставляем
    // прозрачным: библиотека заливает весь холст белым, и у закрытой книги
    // рядом с обложкой стояло белое поле.
    const sharpen = () => {
        const ui = flip.getUI();
        const render = flip.getRender();
        const canvas = ui.getCanvas();
        const ctx = render.getContext();
        ui.resizeCanvas = () => {
            const style = getComputedStyle(canvas);
            const density = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.round(parseFloat(style.width) * density);
            canvas.height = Math.round(parseFloat(style.height) * density);
            ctx.setTransform(density, 0, 0, density, 0, 0);
            ctx.imageSmoothingQuality = 'high';
        };
        render.clear = () => {
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.restore();
        };
        ui.update();
    };

    // Закрытая книга стоит по центру стола. Сдвиг в целых пикселях: на
    // дробном холст мылится.
    const place = () => {
        const index = current();
        const landscape = !portrait();
        let dx = 0;
        if (landscape && index === 0) {
            dx = -Math.round(shift.offsetWidth / 4);
        } else if (landscape && index === total - 1 && total % 2 === 0) {
            dx = Math.round(shift.offsetWidth / 4);
        }
        shift.style.transform = dx ? 'translateX(' + dx + 'px)' : '';
        shift.classList.toggle('pdf-book__shift--closed-front', dx < 0);
        shift.classList.toggle('pdf-book__shift--closed-back', dx > 0);
    };

    const countText = (view) => (view.length > 1
        ? format(label('pages', 'Страницы %1–%2 из %3'), [view[0] + 1, view[1] + 1, total])
        : format(label('page', 'Страница %1 из %2'), [view[0] + 1, total]));

    const update = () => {
        if (!flip) {
            return;
        }
        const index = current();
        const view = visible();
        const landscape = !portrait();
        countNode.textContent = countText(view);
        prevButton.disabled = index <= 0;
        nextButton.disabled = view[view.length - 1] >= total - 1;
        slider.value = String(view[0] + 1);
        shift.classList.toggle('pdf-book__shift--portrait', !landscape);
        place();
        const read = landscape ? view[0] : index;
        const left = total - 1 - (landscape ? view[view.length - 1] : index);
        edgeLeft.style.setProperty('--pdf-book-edge', Math.round(EDGE_MAX * read / Math.max(1, total - 1)) + 'px');
        edgeRight.style.setProperty('--pdf-book-edge', Math.round(EDGE_MAX * left / Math.max(1, total - 1)) + 'px');
        thumbs.querySelectorAll('[data-page]').forEach((thumb) => {
            const on = view.includes(Number(thumb.getAttribute('data-page')));
            thumb.setAttribute('aria-current', on ? 'true' : 'false');
        });
        const first = thumbs.querySelector('[data-page="' + view[0] + '"]');
        // Миниатюра докручивается внутри своей ленты: scrollIntoView сдвинул
        // бы и саму страницу.
        if (first && (first.offsetLeft < thumbs.scrollLeft || first.offsetLeft + first.offsetWidth > thumbs.scrollLeft + thumbs.clientWidth)) {
            thumbs.scrollLeft = first.offsetLeft - thumbs.clientWidth / 2 + first.offsetWidth / 2;
        }
        try {
            history.replaceState(history.state, '', '#page-' + (view[0] + 1));
        } catch (error) {
            // адрес не обновился — книга работает и без него
        }
        schedule();
        if (!zoom.hidden) {
            fillZoom();
        }
    };

    const goTo = (target) => {
        if (!flip) {
            return;
        }
        const page = Math.max(0, Math.min(total - 1, target));
        if (reduceMotion() || Math.abs(page - current()) > 2) {
            flip.turnToPage(page);
            update();
        } else {
            flip.flip(page);
        }
    };

    const step = (direction) => {
        if (!flip) {
            return;
        }
        if (reduceMotion()) {
            const view = visible();
            goTo(direction > 0 ? view[view.length - 1] + 1 : view[0] - 1);
        } else if (direction > 0) {
            flip.flipNext();
        } else {
            flip.flipPrev();
        }
    };

    // Миниатюры рисуются, когда их видно в ленте.
    const buildThumbs = () => {
        const observer = 'IntersectionObserver' in window
            ? new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        observer.unobserve(entry.target);
                        drawThumb(entry.target);
                    }
                });
            }, { root: thumbs, rootMargin: '0px 300px' })
            : null;
        for (let index = 0; index < total; index++) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pdf-book__thumb';
            button.setAttribute('data-page', String(index));
            button.setAttribute('aria-label', format(label('thumb', 'Страница %1'), [index + 1]));
            const sheet = document.createElement('span');
            sheet.className = 'pdf-book__thumb-sheet';
            const number = document.createElement('span');
            number.textContent = String(index + 1);
            number.setAttribute('aria-hidden', 'true');
            button.append(sheet, number);
            thumbs.appendChild(button);
            if (observer) {
                observer.observe(button);
            } else {
                drawThumb(button);
            }
        }
    };

    const drawThumb = async (button) => {
        try {
            const page = await pdf.getPage(Number(button.getAttribute('data-page')) + 1);
            const unscaled = page.getViewport({ scale: 1 });
            const viewport = page.getViewport({ scale: 128 / unscaled.width });
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.className = 'pdf-book__thumb-sheet';
            canvas.setAttribute('aria-hidden', 'true');
            await page.render({ canvas, viewport }).promise;
            button.querySelector('.pdf-book__thumb-sheet').replaceWith(canvas);
        } catch (error) {
            // миниатюра осталась листом — книга работает и без неё
        }
    };

    // Лупа: открытый разворот крупно, страницы рисуются заново в этом масштабе.
    const fillZoom = async () => {
        const job = ++zoomJob;
        const view = visible();
        const fit = Math.min((zoomScroll.clientWidth - 32) / view.length, (zoomScroll.clientHeight - 32) / ratio);
        const width = Math.max(200, Math.round(fit * ZOOM_STEPS[zoomLevel]));
        zoomCount.textContent = countText(view);
        zoomOut.disabled = zoomLevel === 0;
        zoomIn.disabled = zoomLevel === ZOOM_STEPS.length - 1;
        try {
            const canvases = [];
            for (const index of view) {
                const page = await pdf.getPage(index + 1);
                const unscaled = page.getViewport({ scale: 1 });
                let density = window.devicePixelRatio || 1;
                const pixels = width * width * (unscaled.height / unscaled.width);
                if (pixels * density * density > MAX_CANVAS_PIXELS) {
                    density = Math.sqrt(MAX_CANVAS_PIXELS / pixels);
                }
                const viewport = page.getViewport({ scale: (width * density) / unscaled.width });
                const canvas = document.createElement('canvas');
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                canvas.style.width = width + 'px';
                canvas.className = 'pdf-book__zoom-page';
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', format(label('thumb', 'Страница %1'), [index + 1]));
                await page.render({ canvas, viewport }).promise;
                if (job !== zoomJob) {
                    return;
                }
                canvases.push(canvas);
            }
            zoomSpread.replaceChildren(...canvases);
        } catch (error) {
            console.error('PDF book zoom:', error);
        }
    };

    const openZoom = () => {
        zoom.hidden = false;
        document.documentElement.classList.add('pdf-book-zoom-open');
        zoomLevel = 1;
        zoomSpread.replaceChildren();
        fillZoom();
        zoomClose.focus();
    };

    const closeZoom = () => {
        zoom.hidden = true;
        document.documentElement.classList.remove('pdf-book-zoom-open');
        zoomSpread.replaceChildren();
        zoomButton.focus();
    };

    // Мышью увеличенную страницу удобно двигать перетаскиванием; палец и
    // так прокручивает.
    let drag = null;
    zoomScroll.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse' && event.button === 0) {
            drag = { x: event.clientX, y: event.clientY };
            zoomScroll.classList.add('is-dragging');
        }
    });
    window.addEventListener('pointermove', (event) => {
        if (drag) {
            zoomScroll.scrollLeft -= event.clientX - drag.x;
            zoomScroll.scrollTop -= event.clientY - drag.y;
            drag = { x: event.clientX, y: event.clientY };
        }
    });
    window.addEventListener('pointerup', () => {
        drag = null;
        zoomScroll.classList.remove('is-dragging');
    });

    const buildBook = () => {
        const sheet = document.createElement('canvas');
        sheet.width = 10;
        sheet.height = Math.round(10 * ratio);
        const sheetCtx = sheet.getContext('2d');
        sheetCtx.fillStyle = PAPER;
        sheetCtx.fillRect(0, 0, sheet.width, sheet.height);
        blank = sheet;
        const placeholder = sheet.toDataURL('image/png');

        stage.hidden = false;
        const base = 500;
        flip = new window.St.PageFlip(host, {
            width: base,
            height: Math.round(base * ratio),
            size: 'stretch',
            minWidth: 200,
            maxWidth: 1000,
            minHeight: Math.round(200 * ratio),
            maxHeight: Math.round(1000 * ratio),
            showCover: true,
            usePortrait: true,
            autoSize: true,
            maxShadowOpacity: 0.55,
            drawShadow: true,
            flippingTime: reduceMotion() ? 1 : 1000,
            mobileScrollSupport: false,
            showPageCorners: !reduceMotion(),
        });
        flip.loadFromImages(new Array(total).fill(placeholder));
        sharpen();
        orientation = flip.getOrientation();
        flip.on('flip', update);
        flip.on('changeOrientation', () => {
            orientation = flip.getOrientation();
            update();
        });
        slider.max = String(total);
        const hash = /^#page-(\d+)$/.exec(window.location.hash);
        if (hash) {
            flip.turnToPage(Math.max(0, Math.min(total - 1, Number(hash[1]) - 1)));
        }
        window.addEventListener('resize', place);
    };

    const start = async () => {
        const pdfjs = await import(root.getAttribute('data-pdfjs'));
        pdfjs.GlobalWorkerOptions.workerSrc = root.getAttribute('data-worker');
        const task = pdfjs.getDocument({
            url: fileUrl,
            cMapUrl: vendor + 'cmaps/',
            cMapPacked: true,
            standardFontDataUrl: vendor + 'standard_fonts/',
            wasmUrl: vendor + 'wasm/',
            iccUrl: vendor + 'iccs/',
            enableXfa: false,
        });
        // Ход загрузки: размер файла сервер называет не всегда (сжатие,
        // отдача кусками) — тогда полоса остаётся без значения, «идёт загрузка».
        task.onProgress = ({ loaded, total }) => {
            if (!total || !progressNode) {
                return;
            }
            const percent = Math.min(100, Math.round((loaded / total) * 100));
            progressNode.value = percent;
            if (percentNode) {
                percentNode.textContent = percent + '\u00a0%';
            }
        };
        pdf = await task.promise;
        total = pdf.numPages;
        const first = (await pdf.getPage(1)).getViewport({ scale: 1 });
        ratio = first.height / first.width;
        root.style.setProperty('--pdf-book-ratio', String(first.width / first.height));
        statusNode.hidden = true;
        buildBook();
        buildThumbs();
        nav.hidden = false;
        thumbs.hidden = false;
        update();
    };

    // Страница с документом не нашлась на сервере — ведём туда, откуда пришли,
    // если это свой сайт: чужой адрес в шапке книги был бы ссылкой наружу.
    const back = root.querySelector('[data-book-back]');
    if (back && back.hidden && document.referrer) {
        try {
            const from = new URL(document.referrer);
            if (from.origin === window.location.origin && from.pathname !== window.location.pathname) {
                back.href = from.pathname + from.search;
                back.hidden = false;
            }
        } catch (error) {
            // адрес не разобрался — кнопки нет
        }
    }

    prevButton.addEventListener('click', () => step(-1));
    nextButton.addEventListener('click', () => step(1));
    slider.addEventListener('input', () => goTo(Number(slider.value) - 1));
    thumbs.addEventListener('click', (event) => {
        const thumb = event.target.closest('[data-page]');
        if (thumb) {
            goTo(Number(thumb.getAttribute('data-page')));
        }
    });
    zoomButton.addEventListener('click', openZoom);
    zoomClose.addEventListener('click', closeZoom);
    zoomIn.addEventListener('click', () => {
        zoomLevel = Math.min(ZOOM_STEPS.length - 1, zoomLevel + 1);
        fillZoom();
    });
    zoomOut.addEventListener('click', () => {
        zoomLevel = Math.max(0, zoomLevel - 1);
        fillZoom();
    });

    // Полный экран бывает не везде (iPhone не даёт его странице) — кнопки
    // там нет вовсе.
    if (root.requestFullscreen && document.fullscreenEnabled) {
        fullscreenButton.hidden = false;
        fullscreenButton.addEventListener('click', () => {
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            } else {
                root.requestFullscreen().catch(() => {
                    fullscreenButton.hidden = true;
                });
            }
        });
        document.addEventListener('fullscreenchange', () => {
            const on = document.fullscreenElement === root;
            fullscreenButton.querySelector('[data-book-fullscreen-label]').textContent = on
                ? label('fullscreen-exit', 'Выйти из полного экрана')
                : label('fullscreen', 'Во весь экран');
            window.setTimeout(() => {
                if (flip) {
                    flip.update();
                    update();
                }
            }, 60);
        });
    }

    document.addEventListener('keydown', (event) => {
        if (!zoom.hidden) {
            if (event.key === 'Escape') {
                closeZoom();
            } else if (event.key === '+' || event.key === '=') {
                zoomIn.click();
            } else if (event.key === '-') {
                zoomOut.click();
            }
            return;
        }
        if (event.target.closest && event.target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }
        if (event.key === 'ArrowRight') {
            step(1);
            event.preventDefault();
        } else if (event.key === 'ArrowLeft') {
            step(-1);
            event.preventDefault();
        }
    });

    start().catch(fail);
}
