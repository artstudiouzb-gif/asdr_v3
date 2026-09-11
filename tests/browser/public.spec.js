const { test, expect } = require('@playwright/test');
const { PUBLIC_PAGES } = require('./pages');

/**
 * Горизонтальная прокрутка проверялась только на главной, хотя ломают её
 * обычно не там: широкая таблица, длинное слово в заголовке, карточка,
 * растянутая на две ячейки сетки, полоса прокрутки «Хронологии». Обход идёт
 * по тому же списку страниц, что и аудит доступности, и на обоих проектах
 * Playwright — то есть и на телефоне, где переполнение как раз и заметно.
 *
 * В CI фактически проверяются те страницы, что существуют без демо-контента:
 * главная, лента, поиск, узбекская версия и каталог. Остальные пропускаются
 * (см. ниже) — чтобы покрыть и их, фикстуре CI нужен демо-комплект.
 *
 * Допуск 15px — тот же, что стоял у проверки главной: он покрывает
 * округление ширины полосы прокрутки, но не настоящее переполнение.
 */
for (const [name, url] of PUBLIC_PAGES) {
    test(`нет горизонтальной прокрутки: ${name}`, async ({ page }) => {
        const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
        expect(response).not.toBeNull();

        // Минимальная фикстура CI содержит только главную и витрину блоков:
        // новость, проект, контакты и страница со слайдером приезжают с
        // демо-комплектом, которого в CI нет. Пропуск ровно такой же, как в
        // аудите доступности, и по той же причине — мерить ширину страницы
        // ошибки бессмысленно. Пропускаем только 404: 500 обязан ронять тест,
        // иначе сломанная страница выглядела бы как отсутствующая.
        test.skip(
            response.status() === 404,
            `${url} — нет в этой сборке контента`
        );
        expect(response.status()).toBe(200);
        await expect(page.locator('main#main-content')).toBeVisible();

        const overflow = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            content: document.documentElement.scrollWidth,
            // Имя самого широкого элемента: без него отчёт говорит «шире на
            // 40px» и не говорит, кто именно вылез.
            widest: (() => {
                let worst = null;
                let width = 0;
                for (const el of document.querySelectorAll('body *')) {
                    const box = el.getBoundingClientRect();
                    if (box.right > width) {
                        width = box.right;
                        worst = el.tagName.toLowerCase()
                            + (el.className && typeof el.className === 'string'
                                ? '.' + el.className.trim().split(/\s+/).join('.')
                                : '');
                    }
                }
                return worst;
            })()
        }));

        expect(
            overflow.content,
            `${url}: содержимое шире экрана, самый правый элемент — ${overflow.widest}`
        ).toBeLessThanOrEqual(overflow.viewport + 15);
    });
}

test('поиск в шапке отправляется с клавиатуры и с первого нажатия кнопки', async ({ page }) => {
    await page.goto('/');
    const toggle = page.locator('[data-search-toggle]').first();
    test.skip(!(await toggle.isVisible()), 'в этой сборке шапки нет поля поиска');

    // Раскрытие ставит `is-open`/`is-expanded`. Прежний обработчик ждал класса
    // `is-active`, которого никто не ставил, и отменял отправку: поле было
    // открыто, а Enter (он же кнопка «поиск» на экранной клавиатуре) молчал.
    await toggle.click();
    const input = page.locator('.site-search input[type="search"]').first();
    await expect(input).toBeVisible();

    await input.fill('Sardor');
    await input.press('Enter');
    await expect(page).toHaveURL(/[?&]q=Sardor/);

    // Кнопка отправки обязана срабатывать сразу, а не со второго нажатия.
    await page.goto('/');
    await toggle.click();
    await input.fill('Sardor');
    await page.locator('.site-search__submit').first().click();
    await expect(page).toHaveURL(/[?&]q=Sardor/);
});

test('пустой запрос из шапки не уводит со страницы', async ({ page }) => {
    await page.goto('/');
    const toggle = page.locator('[data-search-toggle]').first();
    test.skip(!(await toggle.isVisible()), 'в этой сборке шапки нет поля поиска');

    await toggle.click();
    const before = page.url();
    await page.locator('.site-search input[type="search"]').first().press('Enter');
    await page.waitForTimeout(500);
    expect(page.url()).toBe(before);
});

test('quick search traps keyboard focus and restores the trigger', async ({ page }) => {
    const response = await page.goto('/');
    expect(response).not.toBeNull();
    expect(response.status()).toBe(200);

    await page.keyboard.press('Tab');
    const trigger = page.locator('.skip-link');
    await expect(trigger).toBeFocused();

    await page.keyboard.press('Control+KeyK');
    const modal = page.locator('#site-quick-search-modal');
    await expect(modal).toBeVisible();
    await expect(page.locator('#site-quick-search-input')).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('Uzbek home uses localized title and secure language URL', async ({ page }) => {
    const response = await page.goto('/uz');
    expect(response).not.toBeNull();
    expect(response.status()).toBe(200);
    await expect(page).toHaveTitle(/Bosh sahifa/);
    expect(page.url()).toMatch(/^http:\/\/127\.0\.0\.1:8080\/uz\/?$/);
});

test('selected language persists until the visitor explicitly changes it', async ({ page }) => {
    await page.goto('/uz');

    let cookies = await page.context().cookies();
    expect(cookies.find((cookie) => cookie.name === 'site_lang')?.value).toBe('uz');

    // Сохранённый язык перебивает адрес только на корне сайта: заход на «/»
    // уводит на «/uz». На конкретной странице выигрывает язык из URL — иначе
    // ответ каждой страницы зависел бы от cookie и не кешировался бы на CDN,
    // а присланная ссылка уводила бы читателя на другую языковую версию.
    await page.goto('/');
    expect(page.url()).toMatch(/^http:\/\/127\.0\.0\.1:8080\/uz\/?$/);

    await page.goto('/projects');
    expect(page.url()).toMatch(/^http:\/\/127\.0\.0\.1:8080\/projects\/?$/);

    cookies = await page.context().cookies();
    expect(cookies.find((cookie) => cookie.name === 'site_lang')?.value).toBe('uz');

    await page.goto('/projects?_lang=ru');
    expect(page.url()).toMatch(/^http:\/\/127\.0\.0\.1:8080\/projects\/?$/);
    cookies = await page.context().cookies();
    expect(cookies.find((cookie) => cookie.name === 'site_lang')?.value).toBe('ru');

    // Открыть страницу на другом языке по прямой ссылке можно всегда, и
    // сохранённый выбор от этого не меняется: язык переключают явно —
    // параметром _lang или переключателем в шапке.
    await page.goto('/uz/projects');
    expect(page.url()).toMatch(/^http:\/\/127\.0\.0\.1:8080\/uz\/projects\/?$/);
    cookies = await page.context().cookies();
    expect(cookies.find((cookie) => cookie.name === 'site_lang')?.value).toBe('ru');

    // Корень по-прежнему уводит на сохранённый язык.
    await page.goto('/uz');
    expect(page.url()).toMatch(/^http:\/\/127\.0\.0\.1:8080\/?$/);
});

test('health endpoint and admin login are reachable', async ({ page, request }) => {
    const health = await request.get('/health');
    expect(health.status()).toBe(200);
    const healthPayload = await health.json();
    expect(healthPayload.status).toMatch(/^(ok|degraded)$/);

    const response = await page.goto('/admin/login');
    expect(response).not.toBeNull();
    expect(response.status()).toBe(200);
    await expect(page.locator('input[name="username"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
});

test('mobile drawer controls focus trap and restores trigger on escape', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/');
    const burger = page.locator('.site-burger');

    // Раньше проверки прятались в `if (isVisible())` и на фикстуре без бургера
    // тест молча зеленел, ничего не проверив. Пропуск должен быть виден.
    test.skip(!(await burger.isVisible()), 'в этой сборке шапки бургера нет');

    await burger.click();
    const drawer = page.locator('#site-drawer');
    await expect(drawer).toBeVisible();
    // Открытая шторка не помечается `aria-hidden="false"` — атрибут снимается:
    // явное "false" считается плохой практикой и сбивает часть скринридеров.
    expect(await drawer.getAttribute('aria-hidden')).toBeNull();

    const closeBtn = page.locator('.site-drawer__close');
    await expect(closeBtn).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(drawer).toHaveAttribute('aria-hidden', 'true');
    await expect(burger).toBeFocused();
});

test('theme toggle and accessibility panel toggle data attributes correctly', async ({ page }) => {
    await page.goto('/');
    const themeBtn = page.locator('.site-theme-toggle');
    if (await themeBtn.isVisible()) {
        const initialTheme = await page.locator('html').getAttribute('data-theme');
        await themeBtn.click();
        const newTheme = await page.locator('html').getAttribute('data-theme');
        expect(newTheme).not.toBe(initialTheme);
    }
});

test('explicit block paddings override page rhythm and keep print/a11y modes intact', async ({ page }) => {
    await page.setContent(`
        <main class="site-content editorial-page">
            <div class="content-pagehead"></div>
            <div class="page-blocks editorial-page__content">
                <section id="block-first" class="cms-block cms-block--text cms-block--space-premium cms-block--pad-top-custom cms-block--pad-bottom-custom">Первый</section>
                <section id="block-second" class="cms-block cms-block--text cms-block--space-premium cms-block--pad-top-custom cms-block--pad-bottom-custom">Второй</section>
                <section id="block-bio" class="cms-block cms-block--bio_education cms-block--space-premium cms-block--pad-top-custom cms-block--pad-bottom-custom"><div class="block-bio">Биография</div></section>
                <section class="cms-block cms-block--hero cms-block--space-none">Hero</section>
                <section id="block-counters" class="cms-block cms-block--counters cms-block--space-premium cms-block--pad-top-custom cms-block--pad-bottom-custom">Счётчики</section>
            </div>
        </main>
        <style>
            #block-first { --block-pad-top: 0; --block-pad-bottom: 96px; }
            #block-second { --block-pad-top: 96px; --block-pad-bottom: 0; }
            #block-bio { --block-pad-top: 0; --block-pad-bottom: 96px; }
            #block-counters { --block-pad-top: 96px; --block-pad-bottom: 0; }
        </style>
    `);
    await page.addStyleTag({ path: require('node:path').resolve(__dirname, '../../public/assets/css/public.min.css') });

    const paddings = async (selector) => page.locator(selector).evaluate((element) => {
        const style = getComputedStyle(element);
        return [style.paddingTop, style.paddingBottom];
    });

    expect(await paddings('#block-first')).toEqual(['0px', '96px']);
    expect(await paddings('#block-second')).toEqual(['96px', '0px']);
    expect(await paddings('#block-bio')).toEqual(['0px', '96px']);
    expect(await paddings('#block-counters')).toEqual(['96px', '0px']);

    await page.locator('html').evaluate((element) => element.setAttribute('data-a11y-reading', 'on'));
    expect(await paddings('#block-bio')).toEqual(['18px', '18px']);
    await page.locator('html').evaluate((element) => element.removeAttribute('data-a11y-reading'));

    await page.emulateMedia({ media: 'print' });
    expect(await paddings('#block-bio')).toEqual(['12px', '12px']);
});

test('анимация появления работает и в первом экране, и без сетки', async ({ page }) => {
    // Переход не запускается, если начальное состояние ни разу не нарисовано.
    // Скрипт с defer выполняется до первой отрисовки, поэтому блок первого
    // экрана получал `is-visible` в том же кадре, где впервые получил стили,
    // и появлялся мгновенно: настройка «анимация появления» на верхних блоках
    // не работала вовсе. Ловим промежуточную прозрачность — она и означает,
    // что переход идёт.
    await page.addInitScript(() => {
        window.__revealSamples = [];
        const tick = () => {
            const el = document.querySelector('[data-reveal-type="slide-up"]');
            if (el) { window.__revealSamples.push(Number(getComputedStyle(el).opacity)); }
            if (performance.now() < 2500) { requestAnimationFrame(tick); }
        };
        requestAnimationFrame(tick);
    });
    const response = await page.goto('/reveal-check');
    expect(response.status()).toBe(200);
    await page.waitForTimeout(2600);

    const samples = await page.evaluate(() => window.__revealSamples);
    const partial = samples.filter((o) => o > 0.02 && o < 0.98);
    expect(partial.length).toBeGreaterThan(3);
    expect(samples[samples.length - 1]).toBe(1);

    // Карточки сетки проявляются по очереди: у каждой своя задержка.
    const delays = await page.evaluate(() => Array.from(
        document.querySelectorAll('.cards-grid .anim-card'),
        (c) => c.style.getPropertyValue('--card-reveal-delay')
    ));
    expect(delays.length).toBeGreaterThan(1);
    expect(new Set(delays).size).toBe(delays.length);

    // «Карточки по очереди» у блока без сетки очередь строить не из чего —
    // раньше выбор не давал ровно ничего. Секция обязана появиться целиком.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(1500);
    const gridless = await page.evaluate(() => {
        const section = Array.from(document.querySelectorAll('[data-reveal-items]'))
            .find((s) => !s.classList.contains('is-motion-ready'));
        if (!section) { return null; }
        return { reveal: section.hasAttribute('data-reveal'), opacity: getComputedStyle(section).opacity };
    });
    expect(gridless).not.toBeNull();
    expect(gridless.reveal).toBe(true);
    expect(Number(gridless.opacity)).toBe(1);
});
