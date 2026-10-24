// Просмотр PDF на телефонах (страница /pdf, см. Site\PdfController).
// Страницы рисуются в холсты по мере прокрутки и освобождаются, когда уходят
// далеко с экрана: на телефоне память холстов кончается раньше, чем документ
// в сотню страниц. PDF.js и его воркер приходят со своего домена.
const root = document.querySelector('[data-pdf-viewer]');

if (root) {
    const pagesBox = root.querySelector('[data-pdf-pages]');
    const statusNode = root.querySelector('[data-pdf-status]');
    const countNode = root.querySelector('[data-pdf-count]');
    const zoomButtons = root.querySelectorAll('[data-pdf-zoom]');
    const pageLabel = root.getAttribute('data-label-page') || 'Страница';
    const vendor = new URL('/assets/vendor/pdfjs/', window.location.origin).href;
    // Ссылка на файл бывает переписана на CDN — файл берём со своего адреса:
    // запрос к чужому домену без CORS браузер не выполнит.
    const fileUrl = new URL(root.getAttribute('data-file') || '', window.location.origin);
    const sameOrigin = window.location.origin + fileUrl.pathname;

    // Крупнее этого числа пикселей холст на телефоне не рисуется вовсе
    // (у Safari предел около 16 млн), поэтому плотность снижается.
    const MAX_CANVAS_PIXELS = 12e6;
    const ZOOM_STEPS = [1, 1.5, 2, 3];
    let zoomIndex = 0;
    let pdf = null;
    let baseWidth = 0;
    const pages = [];

    const fail = () => {
        pagesBox.innerHTML = '';
        const message = document.createElement('p');
        message.className = 'pdf-viewer__status pdf-viewer__status--error';
        message.setAttribute('role', 'alert');
        message.textContent = root.getAttribute('data-label-error') || '';
        pagesBox.appendChild(message);
    };

    const fitScale = (viewport) => {
        const width = pagesBox.clientWidth || window.innerWidth;
        return (width / viewport.width) * ZOOM_STEPS[zoomIndex];
    };

    const clear = (entry) => {
        if (entry.task) {
            entry.task.cancel();
            entry.task = null;
        }
        if (entry.canvas) {
            entry.canvas.width = 0;
            entry.canvas.height = 0;
            entry.canvas.remove();
            entry.canvas = null;
        }
        entry.renderedScale = 0;
    };

    const render = async (entry) => {
        const page = entry.page || (entry.page = await pdf.getPage(entry.number));
        const unscaled = page.getViewport({ scale: 1 });
        const scale = fitScale(unscaled);
        if (entry.renderedScale === scale || !entry.visible) {
            return;
        }
        const viewport = page.getViewport({ scale });
        entry.box.style.aspectRatio = unscaled.width + ' / ' + unscaled.height;
        entry.box.style.width = Math.floor(viewport.width) + 'px';

        let ratio = window.devicePixelRatio || 1;
        if (viewport.width * viewport.height * ratio * ratio > MAX_CANVAS_PIXELS) {
            ratio = Math.sqrt(MAX_CANVAS_PIXELS / (viewport.width * viewport.height));
        }
        const canvas = document.createElement('canvas');
        canvas.className = 'pdf-viewer__canvas';
        canvas.width = Math.floor(viewport.width * ratio);
        canvas.height = Math.floor(viewport.height * ratio);
        canvas.setAttribute('aria-hidden', 'true');

        if (entry.task) {
            entry.task.cancel();
        }
        const task = page.render({
            canvas,
            viewport,
            transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null,
        });
        entry.task = task;
        try {
            await task.promise;
        } catch (error) {
            return; // отменено прокруткой или сменой масштаба
        }
        entry.task = null;
        if (!entry.visible) {
            return;
        }
        if (entry.canvas) {
            entry.canvas.remove();
        }
        entry.canvas = canvas;
        entry.renderedScale = scale;
        entry.box.appendChild(canvas);
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((item) => {
            const entry = pages[Number(item.target.getAttribute('data-page')) - 1];
            entry.visible = item.isIntersecting;
            if (entry.visible) {
                render(entry).catch(() => {});
            } else {
                clear(entry);
            }
        });
    }, { rootMargin: '1200px 0px' });

    let countFrame = 0;
    const updateCount = () => {
        countFrame = 0;
        const middle = window.innerHeight / 2;
        let current = 1;
        for (const entry of pages) {
            if (entry.box.getBoundingClientRect().top > middle) {
                break;
            }
            current = entry.number;
        }
        // На экране 320px полный «Страница 4 / 10» не помещается в одну строку
        // с кнопками; слово остаётся диктору и подсказке.
        countNode.textContent = current + ' / ' + pages.length;
        countNode.title = pageLabel + ' ' + current + ' / ' + pages.length;
    };
    const scheduleCount = () => {
        if (!countFrame) {
            countFrame = window.requestAnimationFrame(updateCount);
        }
    };

    const relayout = () => {
        // Заглушки получают ширину по первой странице: размеры остальных
        // становятся известны, только когда до них дойдёт очередь.
        const width = Math.floor(fitScale({ width: baseWidth }) * baseWidth);
        pages.forEach((entry) => {
            entry.box.style.width = width + 'px';
            if (entry.visible) {
                render(entry).catch(() => {});
            }
        });
        zoomButtons.forEach((button) => {
            const step = Number(button.getAttribute('data-pdf-zoom'));
            button.disabled = zoomIndex + step < 0 || zoomIndex + step >= ZOOM_STEPS.length;
        });
        scheduleCount();
    };

    const start = async () => {
        const pdfjs = await import(root.getAttribute('data-pdfjs'));
        pdfjs.GlobalWorkerOptions.workerSrc = root.getAttribute('data-worker');
        pdf = await pdfjs.getDocument({
            url: sameOrigin,
            cMapUrl: vendor + 'cmaps/',
            cMapPacked: true,
            standardFontDataUrl: vendor + 'standard_fonts/',
            wasmUrl: vendor + 'wasm/',
            iccUrl: vendor + 'iccs/',
            enableXfa: false,
        }).promise;

        const first = await pdf.getPage(1);
        const viewport = first.getViewport({ scale: 1 });
        baseWidth = viewport.width;
        pagesBox.innerHTML = '';
        for (let number = 1; number <= pdf.numPages; number += 1) {
            const box = document.createElement('div');
            box.className = 'pdf-viewer__page';
            box.setAttribute('data-page', String(number));
            box.setAttribute('role', 'img');
            box.setAttribute('aria-label', pageLabel + ' ' + number);
            box.style.aspectRatio = viewport.width + ' / ' + viewport.height;
            pagesBox.appendChild(box);
            pages.push({ number, box, page: number === 1 ? first : null, canvas: null, task: null, visible: false, renderedScale: 0 });
        }
        zoomButtons.forEach((button) => { button.hidden = false; });
        relayout();
        pages.forEach((entry) => observer.observe(entry.box));
    };

    zoomButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const next = zoomIndex + Number(button.getAttribute('data-pdf-zoom'));
            if (next >= 0 && next < ZOOM_STEPS.length) {
                zoomIndex = next;
                relayout();
            }
        });
    });
    window.addEventListener('scroll', scheduleCount, { passive: true });
    let resizeTimer = 0;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => { if (pdf) { relayout(); } }, 150);
    });

    if (statusNode) {
        start().catch(fail);
    }
}
