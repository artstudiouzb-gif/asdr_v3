const path = require('node:path');
const { test, expect } = require('@playwright/test');

const projectRoot = path.resolve(__dirname, '../..');
const pixel = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"><rect width="1200" height="675" fill="#155182"/></svg>')}`;

async function mountNews(page) {
    await page.setContent(`
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { margin: 0; }
            .newsdetail { width: min(1100px, 100%); margin-inline: auto; }
        </style>
        <script type="application/json" id="frontend-labels">
            {"linkCopied":"Ссылка скопирована","imageViewer":"Просмотр изображения","downloadPhoto":"Скачать фото","download":"Скачать","close":"Закрыть","previousPhoto":"Предыдущее фото","nextPhoto":"Следующее фото","zoomImage":"Увеличить","photoCredit":"Фото:"}
        </script>
        <article class="newsdetail newsdetail--layout-gallery">
            <div class="newsdetail-head">
                <div class="newsdetail-head__info"><h1 class="newsdetail__title">Проверка новости</h1></div>
                <div class="newsdetail-gallery" data-ndgallery>
                    <div class="newsdetail-gallery__main">
                        <img class="newsdetail-gallery__slide is-active" src="${pixel}" width="1200" height="675" alt="Первое фото">
                        <img class="newsdetail-gallery__slide" src="${pixel}#second" width="1200" height="675" alt="Второе фото">
                        <button type="button" class="newsdetail-gallery__nav newsdetail-gallery__nav--prev" data-ndg-prev aria-label="Предыдущее фото">←</button>
                        <button type="button" class="newsdetail-gallery__nav newsdetail-gallery__nav--next" data-ndg-next aria-label="Следующее фото">→</button>
                        <span class="newsdetail-gallery__counter"><span data-ndg-current>1</span> / 2</span>
                    </div>
                    <figcaption class="newsdetail-gallery__caption" data-ndg-captions='[{"caption":"Первая подпись","credit":"Автор 1"},{"caption":"Вторая подпись","credit":"Автор 2"}]'>
                        <span data-ndg-caption-text>Первая подпись</span>
                        <span data-ndg-caption-credit>Фото: Автор 1</span>
                    </figcaption>
                    <div class="newsdetail-gallery__thumbs">
                        <button type="button" class="newsdetail-gallery__thumb is-active" data-ndg-thumb="0" aria-label="Фото 1"><img src="${pixel}" alt=""></button>
                        <button type="button" class="newsdetail-gallery__thumb" data-ndg-thumb="1" aria-label="Фото 2"><img src="${pixel}#second" alt=""></button>
                    </div>
                </div>
            </div>
            <div class="newsdetail-body">
                <div class="newsdetail-article">
                    <div class="newsdetail-share newsdetail-share--article-top">
                        <button type="button" class="newsdetail-share__btn" data-copy-link="https://example.test/news" aria-label="Скопировать ссылку">Ссылка</button>
                    </div>
                    <div class="news-audio-player"><span class="news-audio-player__icon">A</span><audio controls></audio></div>
                    <div class="newsdetail-article__content rich-content"><p>Текст новости</p><figure><img src="${pixel}" width="1200" height="675" alt="Фото в тексте"><figcaption>Подпись</figcaption></figure></div>
                </div>
                <aside class="newsdetail-side newsdetail-side--right">
                    <section class="newsdetail-card">Карточка</section>
                    <a class="newsdetail-doc" href="#">Документ</a>
                    <section class="newsdetail-subscribe">Подписка</section>
                </aside>
            </div>
            <section class="newsdetail-related"><article class="relnews-card"><div class="relnews-card__media">Новость</div></article></section>
        </article>
        <button type="button" data-reader-mode-toggle>Режим чтения</button>
        <div id="reader-mode-overlay" hidden>
            <div id="reader-progress"></div>
            <button type="button" data-reader-close>Закрыть чтение</button>
            <button type="button" data-reader-theme="light">Светлая тема</button>
            <button type="button" data-reader-font="inc">Увеличить текст</button>
            <div class="reader-mode-container">Текст для чтения</div>
        </div>
    `);
    // Повторяем реальный порядок CSS страницы: базовая сетка, тема, вынесенная
    // часть темы, контент. Стили новостного раздела живут отдельным файлом и на
    // странице подключаются сразу после бандла — до пользовательских переменных
    // дизайна (AssetCollector::renderThemeStyles).
    await page.addStyleTag({ path: path.join(projectRoot, 'public/assets/css/public.min.css') });
    await page.addStyleTag({ path: path.join(projectRoot, 'public/assets/css/gov-theme.css') });
    await page.addStyleTag({ path: path.join(projectRoot, 'public/assets/css/blocks/news-detail.css') });
    await page.addStyleTag({ path: path.join(projectRoot, 'public/assets/css/rich-content.css') });
    // Пользовательские переменные дизайна подключаются после базовой темы.
    // Повторяем тот же порядок, чтобы значения 0px не перезаписывались :root из CSS.
    await page.addStyleTag({ content: ':root { --radius: 0px; --radius-sm: 0px; --btn-radius: 0px; }' });
    // about:blank не гарантирует рабочий Clipboard API в headless Chromium.
    // Стаб гарантирует успешное копирование, а тест проверяет реакцию интерфейса.
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: () => Promise.resolve() }
        });
    });
    await page.addScriptTag({ path: path.join(projectRoot, 'public/assets/js/frontend.js') });
}

test('медиазона не схлопывается, а скругление управляет всеми блоками новости', async ({ page }) => {
    await mountNews(page);

    const widths = await page.evaluate(() => ({
        head: document.querySelector('.newsdetail-head').getBoundingClientRect().width,
        gallery: document.querySelector('.newsdetail-gallery').getBoundingClientRect().width,
        media: document.querySelector('.newsdetail-gallery__main').getBoundingClientRect().width
    }));
    expect(Math.abs(widths.gallery - widths.head)).toBeLessThanOrEqual(1);
    expect(Math.abs(widths.media - widths.head)).toBeLessThanOrEqual(1);
    expect(widths.media).toBeGreaterThan(300);

    for (const selector of ['.newsdetail-gallery__main', '.news-audio-player', '.newsdetail-card', '.newsdetail-doc', '.newsdetail-subscribe', '.relnews-card', '.rich-content img']) {
        await expect(page.locator(selector).first()).toHaveCSS('border-radius', '0px');
    }

    await page.evaluate(() => {
        document.documentElement.style.setProperty('--radius', '22px');
        document.documentElement.style.setProperty('--radius-sm', '13.2px');
        document.documentElement.style.setProperty('--btn-radius', '22px');
    });
    await expect(page.locator('.newsdetail-gallery__main')).toHaveCSS('border-radius', '22px');
    await expect(page.locator('.news-audio-player')).toHaveCSS('border-radius', '22px');
    await expect(page.locator('.newsdetail-card')).toHaveCSS('border-radius', '22px');
    await expect(page.locator('.rich-content img')).toHaveCSS('border-radius', '22px');
    await expect(page.locator('.newsdetail-doc')).toHaveCSS('border-radius', '13.2px');
    await expect(page.locator('.newsdetail-gallery__thumb').first()).toHaveCSS('border-radius', '13.2px');
});

test('галерея, подписи, lightbox, копирование и режим чтения работают вместе', async ({ page }) => {
    await mountNews(page);

    await page.getByRole('button', { name: 'Следующее фото' }).first().click();
    await expect(page.locator('[data-ndg-current]')).toHaveText('2');
    await expect(page.locator('[data-ndg-caption-text]')).toHaveText('Вторая подпись');
    await expect(page.locator('[data-ndg-caption-credit]')).toHaveText('Фото: Автор 2');

    await page.locator('.newsdetail-gallery__slide.is-active').click();
    await expect(page.locator('[data-lightbox-counter]')).toHaveText('2 / 2');
    await expect(page.locator('[data-lightbox-caption]')).toContainText('Вторая подпись · Фото: Автор 2');
    await page.getByRole('button', { name: 'Закрыть', exact: true }).click();
    await expect(page.locator('.rich-lightbox-modal')).toBeHidden();

    await page.locator('.rich-content img').click();
    await expect(page.locator('[data-lightbox-counter]')).toHaveText('1 / 1');
    await expect(page.locator('[data-lightbox-caption]')).toHaveText('Подпись');
    await page.getByRole('button', { name: 'Закрыть', exact: true }).click();

    // Селектор остаётся стабильным после изменения aria-label на «Ссылка скопирована».
    const copy = page.locator('[data-copy-link]');
    await copy.click();
    await expect(copy).toHaveClass(/is-copied/);
    await expect(copy).toHaveAttribute('aria-label', 'Ссылка скопирована');

    const readerTrigger = page.getByRole('button', { name: 'Режим чтения' });
    await readerTrigger.click();
    await expect(page.locator('#reader-mode-overlay')).toBeVisible();
    await expect(page.locator('body')).toHaveClass(/reader-mode-active/);
    await page.keyboard.press('Escape');
    await expect(page.locator('#reader-mode-overlay')).toBeHidden();
    await expect(readerTrigger).toBeFocused();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
});

test('клик по контейнеру галереи открывает текущий активный слайд в lightbox и синхронизирует навигацию', async ({ page }) => {
    await mountNews(page);

    // Переключаем на фото 2 через миниатюру
    await page.locator('[data-ndg-thumb="1"]').click();
    await expect(page.locator('[data-ndg-current]')).toHaveText('2');

    // Кликаем по главному контейнеру фото
    await page.locator('.newsdetail-gallery__main').click({ position: { x: 100, y: 100 } });
    await expect(page.locator('.rich-lightbox-modal')).toBeVisible();
    await expect(page.locator('[data-lightbox-counter]')).toHaveText('2 / 2');
    await expect(page.locator('[data-lightbox-caption]')).toContainText('Вторая подпись · Фото: Автор 2');

    // Внутри lightbox листаем назад
    await page.locator('[data-lightbox-prev]').click();
    await expect(page.locator('[data-lightbox-counter]')).toHaveText('1 / 2');
    await expect(page.locator('[data-lightbox-caption]')).toContainText('Первая подпись · Фото: Автор 1');

    // Закрываем — галерея на странице синхронизирована с фото 1
    await page.getByRole('button', { name: 'Закрыть', exact: true }).click();
    await expect(page.locator('.rich-lightbox-modal')).toBeHidden();
    await expect(page.locator('[data-ndg-current]')).toHaveText('1');
    await expect(page.locator('.newsdetail-gallery__slide.is-active')).toHaveAttribute('alt', 'Первое фото');
});

