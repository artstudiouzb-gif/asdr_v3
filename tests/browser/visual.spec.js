const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

/*
 * Визуальные регрессы по вычисленным стилям.
 *
 * Пиксельные снимки в этом проекте не годятся: рендер отличается между
 * машиной разработчика и раннером CI (сглаживание, версия Chromium), и тест
 * краснел бы без причины. Поэтому эталон — не картинка, а срез вычисленных
 * стилей ключевых элементов: цвет, размер шрифта, отступы, рамки, тень. Он
 * ловит ровно то, ради чего тест затевался: правку оформления, которую никто
 * не заметил. И читается в diff как текст, а не как «изменилась картинка».
 *
 * Снимается витрина /visual-regression — её содержимое фиксировано
 * (tests/browser/seed_visual.php), поэтому расхождение означает стили.
 *
 * Обновлять эталон осознанно, вместе с правкой оформления:
 *   npm run test:visual -- --update-snapshots
 */

const PAGE = '/visual-regression';
const BASELINE = path.join(__dirname, 'visual-baseline');

/** Что снимаем: элемент → интересующие свойства. */
const PROBES = [
    ['.site-header', ['backgroundColor', 'borderBottomColor', 'boxShadow', 'height']],
    ['.site-header__logo', ['color', 'fontSize', 'fontWeight', 'gap']],
    ['.section-head__title', ['color', 'fontSize', 'fontWeight', 'letterSpacing', 'paddingLeft', 'marginBottom']],
    ['.counter__value', ['color', 'fontSize', 'fontWeight', 'lineHeight']],
    ['.counter__label', ['color', 'fontSize', 'lineHeight']],
    ['.feature-card', ['backgroundColor', 'borderRadius', 'borderTopWidth', 'borderTopColor', 'boxShadow', 'padding']],
    ['.act-card', ['backgroundColor', 'borderRadius', 'padding', 'minHeight']],
    ['.act-card__emblem', ['display', 'width', 'height', 'opacity', 'insetBlockEnd']],
    ['.faq-item', ['backgroundColor', 'borderRadius', 'borderTopColor']],
    ['.faq-item__q', ['color', 'fontSize', 'fontWeight', 'padding']],
    ['.stage', ['gap', 'paddingTop', 'display']],
    ['.contact-card', ['backgroundColor', 'borderRadius', 'padding']],
    ['.cms-block--cta', ['backgroundColor', 'paddingTop', 'paddingBottom']],
    ['.block-advantages__item', ['backgroundColor', 'borderRadius', 'padding', 'borderTopColor']],
    ['.act-card__number', ['color', 'fontSize', 'fontWeight']],
    ['.site-footer', ['backgroundColor', 'color', 'paddingTop']],
];

async function collect(page) {
    return page.evaluate((probes) => {
        const out = {};
        for (const [selector, props] of probes) {
            const el = document.querySelector(selector);
            if (!el) {
                out[selector] = 'нет на странице';
                continue;
            }
            const cs = getComputedStyle(el);
            const row = {};
            for (const prop of props) {
                row[prop] = cs[prop];
            }
            out[selector] = row;
        }
        return out;
    }, probes(PROBES));
}

// Playwright сериализует аргумент, поэтому массив передаём как есть.
function probes(list) {
    return list;
}

function compare(actual, name, updating) {
    const file = path.join(BASELINE, name + '.json');
    const text = JSON.stringify(actual, null, 2) + '\n';

    if (updating || !fs.existsSync(file)) {
        fs.mkdirSync(BASELINE, { recursive: true });
        fs.writeFileSync(file, text);
        return;
    }

    // Порядок важен: actual первым, иначе в отчёте «Expected» и «Received»
    // меняются местами и diff читается наоборот.
    expect(actual, 'оформление изменилось — сверьте правку и обновите эталон')
        .toEqual(JSON.parse(fs.readFileSync(file, 'utf8')));
}

/**
 * Режим обновления эталона. Playwright сообщает его в конфиге ('all',
 * 'changed', 'missing', 'none') — argv воркера этого флага не видит.
 * UPDATE_VISUAL=1 остаётся запасным путём.
 */
function isUpdating(testInfo) {
    const mode = testInfo.config.updateSnapshots;

    return process.env.UPDATE_VISUAL === '1' || (mode !== undefined && mode !== 'none' && mode !== 'missing');
}

/**
 * Тема меняется с анимацией — снимок посреди перехода даёт промежуточные
 * цвета, и тест краснеет на ровном месте. Гасим переходы до переключения.
 */
async function freezeTransitions(page) {
    await page.addStyleTag({
        content: '*, *::before, *::after { transition: none !important; animation: none !important; }',
    });
}

test.describe('@visual витрина компонентов', () => {
    test('светлая тема', async ({ page }, testInfo) => {
        await page.goto(PAGE, { waitUntil: 'networkidle' });
        await freezeTransitions(page);
        await page.waitForTimeout(300);
        compare(await collect(page), `${testInfo.project.name}-light`, isUpdating(testInfo));
    });

    test('тёмная тема', async ({ page }, testInfo) => {
        // Тему выставляем ДО загрузки: переключение на живой странице идёт с
        // анимацией, и снимок ловил промежуточные цвета перехода.
        await page.addInitScript(() => {
            localStorage.setItem('theme', 'dark');
            localStorage.setItem('theme-base', 'light');
        });
        await page.goto(PAGE, { waitUntil: 'networkidle' });
        await freezeTransitions(page);
        await page.waitForTimeout(300);
        compare(await collect(page), `${testInfo.project.name}-dark`, isUpdating(testInfo));
    });
});
