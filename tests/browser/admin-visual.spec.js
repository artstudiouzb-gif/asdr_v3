const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

/*
 * Визуальные регрессы админки.
 *
 * Устроено так же, как витрина публички (visual.spec.js): эталон — не
 * картинка, а срез вычисленных стилей. Пиксельный снимок расходится между
 * машиной и раннером, а срез читается в diff и краснеет ровно на правке
 * оформления.
 *
 * Зачем он админке: её CSS собран слоями — базовый файл, слой переопределений
 * поверх него и отдельные патч-файлы, которые подключает загрузчик. Правка в
 * одном слое молча меняет вид в другом; без эталона это замечали только
 * глазами и через раз.
 *
 * Что снимаем: оболочку (шапка, боковое меню), кнопки, поля формы, таблицу,
 * карточки и два переиспользуемых виджета — поле медиа и поле цвета. То есть
 * набор, из которого собраны все экраны панели, а не сами экраны.
 *
 * Данные фиксированы: tests/browser/seed_admin_visual.php.
 *
 * Обновлять эталон осознанно, вместе с правкой оформления:
 *   npm run test:visual -- --update-snapshots
 */

const BASELINE = path.join(__dirname, 'visual-baseline');

// Те же значения, что в tests/browser/seed_admin_visual.php. Живут только в
// одноразовой тестовой базе; фикстура отказывается работать вне testing.
const ADMIN_USER = 'visual';
const ADMIN_PASSWORD = 'Visual-regression-1';
const ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Темы панели: светлая по умолчанию и единственная тёмная. */
const THEMES = ['default', 'dark_emerald'];

/**
 * Экраны и то, что на каждом из них интересно. Селектор → свойства.
 * «нет на странице» в эталоне — тоже факт: он ловит пропавший компонент.
 */
const SCREENS = [
    {
        name: 'shell',
        url: '/admin',
        probes: [
            // Семейство и базовый кегль: панель набрана своим Noto Sans из
            // поставки, и откат на системный стек надо замечать сразу.
            ['body', ['fontFamily', 'fontSize', 'fontWeight']],
            ['.admin-topbar', ['backgroundColor', 'color', 'height', 'borderBottomColor']],
            ['.admin-sidebar', ['backgroundColor', 'color', 'width', 'borderRightColor']],
            ['.admin-nav-item', ['color', 'fontSize', 'fontWeight', 'padding', 'borderRadius']],
            ['.admin-welcome', ['backgroundColor', 'borderColor', 'padding']],
            ['.admin-welcome h2', ['color', 'fontSize', 'fontWeight']],
            ['.admin-welcome p', ['color', 'fontSize']],
            ['.form-card', ['backgroundColor', 'borderColor', 'borderRadius', 'boxShadow', 'padding']],
            ['.stat-card', ['backgroundColor', 'borderColor', 'borderRadius', 'padding']],
            ['.stat-card__value', ['color', 'fontSize', 'fontWeight']],
            ['.stat-card__label', ['color', 'fontSize', 'fontWeight']],
            // Уменьшенный бейдж: на дашборде он стоял ad-hoc классом с
            // хешем, а размер ему всё равно перебивал слой переопределений.
            // Обычный `.badge` снимается на экране list.
            ['.badge--small', ['padding', 'fontSize', 'fontWeight', 'borderRadius']],
            // Высота кнопок — часть шкалы (28/34/38/40). Без этого зонда
            // разнобой в один-два пикселя между .btn--small и .btn--sm никто
            // не замечал.
            ['.btn', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'fontSize', 'padding', 'height', 'minHeight']],
            ['.btn--primary', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'height']],
        ],
    },
    {
        name: 'list',
        url: '/admin/news',
        probes: [
            ['.data-table', ['backgroundColor', 'borderColor']],
            ['.data-table th', ['backgroundColor', 'color', 'fontSize', 'fontWeight', 'padding', 'borderBottomColor']],
            ['.data-table td', ['color', 'fontSize', 'padding', 'borderBottomColor']],
            // Полоска чётной строки — тоже подложка под текстом: пока её не
            // снимали, белые строки на тёмной теме никто не замечал.
            ['.data-table tbody tr:nth-child(even)', ['backgroundColor']],
            ['.badge', ['backgroundColor', 'color', 'borderRadius', 'fontSize', 'padding']],
            ['.list-filters--panel', ['backgroundColor', 'borderColor', 'padding']],
            ['.list-filter label', ['color', 'fontSize', 'fontWeight']],
            ['.list-filter input', ['backgroundColor', 'color', 'borderColor', 'height']],
            ['.bulk-bar', ['backgroundColor', 'borderColor', 'padding']],
            ['.bulk-bar__count', ['color', 'fontSize']],
            ['.lang-filter-count', ['backgroundColor', 'color']],
            ['.list-filter', ['color', 'fontSize', 'borderRadius', 'padding']],
            ['.btn--small', ['fontSize', 'padding', 'borderRadius', 'height', 'minHeight']],
            ['.bulk-bar select', ['width', 'height']],
            ['.bulk-bar .btn', ['height', 'minHeight']],
        ],
    },
    {
        name: 'form',
        url: '/admin/news/create',
        probes: [
            ['.form-field > label', ['color', 'fontSize', 'fontWeight', 'marginBottom']],
            ['input[type="text"]', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'height', 'fontSize']],
            ['textarea', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'fontSize']],
            ['select', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'height']],
            ['.form-hint', ['color', 'fontSize']],
            ['.form-card', ['backgroundColor', 'borderColor', 'borderRadius', 'padding']],
        ],
    },
    {
        // «Настройки сайта»: четыре поля медиа видны сразу, без раскрытия
        // секций — в конструкторе шапки те же поля лежат в свёрнутых панелях,
        // и снимок ловил бы стили скрытого элемента.
        name: 'image-field',
        url: '/admin/settings',
        probes: [
            ['.image-field', ['backgroundColor', 'borderColor', 'borderRadius', 'padding']],
            ['.image-field > label', ['color', 'fontSize', 'fontWeight', 'marginBottom']],
            ['.image-field__row', ['display', 'gap', 'alignItems']],
            ['.image-field__preview', ['width', 'height', 'borderColor', 'borderRadius', 'backgroundColor']],
            ['.image-field__name', ['color', 'fontSize', 'fontWeight']],
            ['.image-field__controls', ['display', 'gap', 'flexWrap']],
            ['.image-field__url', ['fontFamily', 'height', 'borderRadius']],
        ],
    },
    {
        // Конструктор подвала: цвета фона и градиента — поля
        // AdminUi::colorField. Панель показывается при режиме фона «Свой
        // цвет», поэтому перед снимком выбираем его — как это делает редактор.
        name: 'color-field',
        url: '/admin/footer',
        prepare: async (page) => {
            // Конструктор перерисовывает форму после инициализации, поэтому
            // ждём поле явно, а не полагаемся на момент загрузки. Видимость
            // панели проверяем следом: снимок скрытого элемента отдал бы
            // «auto» вместо размеров и молча испортил бы эталон.
            const mode = page.locator('#bg_mode');
            await expect(mode).toBeVisible();
            await mode.selectOption('color');
            await expect(page.locator('.colorfield').first()).toBeVisible();
        },
        probes: [
            ['.colorfield', ['minWidth']],
            ['.colorfield > label', ['color', 'fontSize', 'fontWeight']],
            ['.colorfield .clr-field', ['display', 'width']],
            ['.colorfield .clr-field input', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'paddingLeft', 'fontFamily']],
            ['.colorfield__off', ['color', 'fontSize', 'gap', 'marginTop']],
        ],
    },
    {
        // Таблицы разделов — не тот компонент, что списки материалов: списки
        // рисует .data-table (экран list выше), а разделы вроде «Базы данных» —
        // .admin-table / table.table со своим набором правил. Пока их никто не
        // снимал, разбор трёх наслаивавшихся блоков правил не краснел бы ни в
        // одном тесте.
        name: 'section-table',
        url: '/admin/database',
        prepare: async (page) => {
            // Таблицы лежат внутри <details>: у закрытого содержимое не
            // отрисовано, и срез отдал бы «auto» вместо размеров.
            await page.evaluate(() => {
                document.querySelectorAll('.admin-main details').forEach((node) => { node.open = true; });
            });
            await expect(page.locator('table.table').first()).toBeVisible();
        },
        probes: [
            ['table.table th', ['backgroundColor', 'color', 'fontSize', 'fontWeight', 'letterSpacing', 'textTransform', 'padding', 'borderBottomColor']],
            ['table.table td', ['color', 'fontSize', 'lineHeight', 'padding', 'borderBottomColor']],
            // Полосатость и плотность — отдельные классы, и оба не работали:
            // у общего правила вес (0,1,2), у них был (0,1,1).
            ['.table--striped tbody tr:nth-child(even)', ['backgroundColor']],
        ],
    },
    {
        // Страница медиабиблиотеки: карточка файла здесь и в окне выбора —
        // один компонент, и снимать надо оба конца этого контракта.
        name: 'media-library',
        url: '/admin/files',
        probes: [
            ['.media-grid', ['gridTemplateColumns', 'gap']],
            ['.media-card', ['backgroundColor', 'borderColor', 'borderRadius', 'aspectRatio']],
            // Рамку и пропорцию держит кадр, а не карточка: подпись лежит под
            // ним, и на самой карточке 16:10 выталкивало её поверх снимка.
            ['.media-card__thumb', ['objectFit', 'width', 'height', 'aspectRatio', 'borderColor', 'borderRadius']],
            ['.media-card__caption', ['backgroundColor', 'color', 'fontSize', 'padding']],
            ['.media-card__ext', ['color', 'fontSize', 'fontWeight']],
            ['.media-card__icon-wrap', ['backgroundColor', 'color', 'aspectRatio', 'borderColor']],
            ['.media-toolbar', ['backgroundColor', 'borderColor', 'padding']],
            // Списки тулбара по содержимому: при ширине во всю строку каждый
            // вставал на свой этаж и полоса управления разъезжалась.
            ['.media-toolbar select', ['width', 'minWidth']],
            ['.media-toolbar input[type=\"search\"]', ['width', 'minWidth']],
        ],
    },
    {
        // Конструктор шапки: зоны, перетаскиваемые элементы и вкладки макетов —
        // своё семейство правил, которого не касается ни один другой экран.
        name: 'header-builder',
        url: '/admin/header',
        probes: [
            ['.hdr-tab-btn', ['backgroundColor', 'color', 'borderColor', 'padding']],
            ['.hb-zone', ['backgroundColor', 'borderColor', 'borderRadius', 'padding']],
            ['.hb-zone__label', ['color', 'fontSize', 'fontWeight']],
            ['.hb-el', ['backgroundColor', 'borderColor', 'borderRadius', 'color']],
            ['.hb-el__label', ['color', 'fontSize']],
            ['.hdr-chip', ['backgroundColor', 'color', 'borderRadius', 'fontSize']],
        ],
    },
    {
        name: 'footer-builder',
        url: '/admin/footer',
        probes: [
            ['.header-builder__group', ['backgroundColor', 'borderColor', 'padding']],
            ['.fb-card', ['backgroundColor', 'borderColor', 'borderRadius']],
            ['.fb-card__head', ['backgroundColor', 'color', 'padding']],
            ['.fb-card__badge', ['backgroundColor', 'color', 'fontSize']],
            ['.repeater-row', ['backgroundColor', 'borderColor', 'padding']],
        ],
    },
    {
        // Конструктор блоков — самое большое семейство правил панели.
        // Адрес зависит от id страницы, поэтому доходим до него так же, как
        // редактор: из списка страниц по названию витрины.
        name: 'block-editor',
        navigate: async (page, expectVisible) => {
            await page.goto('/admin/pages', { waitUntil: 'networkidle' });
            const link = page.getByRole('link', { name: /Витрина компонентов/ }).first();
            await expectVisible(link);
            await link.click();
            await page.waitForURL(/\/admin\/pages\/\d+\/edit$/, { timeout: 15000 });
            await page.waitForLoadState('networkidle');
        },
        probes: [
            ['.block-list-item', ['backgroundColor', 'borderColor', 'borderRadius', 'padding']],
            ['.block-list-item__type', ['color', 'fontSize', 'fontWeight']],
            ['.block-list-item__num', ['backgroundColor', 'color', 'fontSize']],
            ['.block-list-item__sub', ['color', 'fontSize']],
            ['.preset-card', ['backgroundColor', 'borderColor', 'borderRadius']],
            ['.preset-card__title', ['color', 'fontSize', 'fontWeight']],
            ['.btn--danger', ['backgroundColor', 'color', 'borderColor']],
        ],
    },
    {
        name: 'design',
        url: '/admin/design',
        probes: [
            ['.admin-tab-btn', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'fontSize', 'padding']],
            ['.design-card', ['backgroundColor', 'borderColor', 'borderRadius', 'padding']],
            ['.design-card__label', ['color', 'fontSize', 'fontWeight']],
            ['.design-opt__label', ['color', 'fontSize', 'fontWeight']],
            ['.clr-field', ['width', 'color']],
        ],
    },
];

/** Окно выбора медиа: снимается отдельно — оно открывается поверх формы. */
const MEDIA_MODAL = {
    name: 'media-modal',
    url: '/admin/settings',
    probes: [
        ['.media-modal', ['backgroundColor']],
        ['.media-modal__dialog', ['backgroundColor', 'borderColor', 'borderRadius', 'boxShadow', 'gridTemplateColumns']],
        ['.media-modal__rail', ['backgroundColor', 'borderRightColor', 'padding']],
        ['.media-modal__navitem', ['color', 'fontSize', 'fontWeight', 'padding', 'borderRadius']],
        ['.media-modal__toolbar', ['backgroundColor', 'borderBottomColor', 'padding', 'gap']],
        ['.media-modal__search', ['backgroundColor', 'color', 'borderColor', 'borderRadius', 'height']],
        ['.media-modal__grid', ['backgroundColor', 'gridTemplateColumns', 'gap', 'padding']],
        ['.media-modal__dropcard', ['backgroundColor', 'borderColor', 'borderRadius']],
        ['.media-modal__item', ['display', 'gap']],
        ['.media-modal__thumb', ['backgroundColor', 'borderColor', 'borderRadius', 'aspectRatio']],
        ['.media-modal__caption-name', ['color', 'fontSize', 'fontWeight']],
        ['.media-modal__caption-meta', ['color', 'fontSize']],
        ['.media-modal__footer', ['backgroundColor', 'borderTopColor', 'padding']],
    ],
};

/**
 * Код приложения-аутентификатора. Считаем сами, чтобы вход шёл обычным путём,
 * со вторым фактором: подсовывать тесту особый режим авторизации значило бы
 * снимать не тот экран, который видит редактор.
 */
function totp(secret, timeSlice = Math.floor(Date.now() / 1000 / 30)) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.toUpperCase().replace(/=+$/, '')) {
        const index = alphabet.indexOf(char);
        if (index === -1) { continue; }
        bits += index.toString(2).padStart(5, '0');
    }
    const key = Buffer.from((bits.match(/.{8}/g) || []).map((byte) => parseInt(byte, 2)));

    const counter = Buffer.alloc(8);
    counter.writeUInt32BE(Math.floor(timeSlice / 2 ** 32), 0);
    counter.writeUInt32BE(timeSlice >>> 0, 4);

    const digest = crypto.createHmac('sha1', key).update(counter).digest();
    const offset = digest[digest.length - 1] & 0x0f;
    const binary = ((digest[offset] & 0x7f) << 24)
        | ((digest[offset + 1] & 0xff) << 16)
        | ((digest[offset + 2] & 0xff) << 8)
        | (digest[offset + 3] & 0xff);

    return String(binary % 1000000).padStart(6, '0');
}

async function login(page) {
    // Шаг TOTP одноразовый и принимается только вперёд (users.totp_last_step),
    // а сервер держит окно ±1 шаг. Поэтому при отказе сразу предъявляем код
    // следующего окна: он и в допуске сервера, и заведомо больше уже
    // потраченного шага. Третья попытка — после смены окна, на случай, когда
    // израсходован и он.
    const step = () => Math.floor(Date.now() / 1000 / 30);

    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt === 2) { await page.waitForTimeout(31000); }

        await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
        await page.fill('#username', ADMIN_USER);
        await page.fill('#password', ADMIN_PASSWORD);
        await page.click('form button[type="submit"], form input[type="submit"]');
        await page.waitForLoadState();

        if (/\/admin\/?$/.test(page.url())) { return; }
        if (!page.url().includes('/admin/login/2fa')) {
            // Не пароль и не второй фактор — фикстура не доехала. Молчать об
            // этом нельзя: дальше посыпались бы снимки, а не вход.
            throw new Error('Вход остановился на ' + page.url() + ' — проверьте tests/browser/seed_admin_visual.php');
        }

        await page.fill('#code', totp(ADMIN_TOTP_SECRET, step() + (attempt === 1 ? 1 : 0)));
        await page.click('form button[type="submit"], form input[type="submit"]');
        await page.waitForLoadState();

        if (/\/admin\/?$/.test(page.url())) { return; }
    }

    throw new Error('Второй фактор не принят за три попытки: шаг TOTP занят другим входом?');
}

/**
 * Тема панели — атрибут на <html>, весь остальной CSS смотрит только на него.
 * Поэтому переключаем атрибутом: сохранение настройки в базе снимало бы тот
 * же самый вид, но за две лишние загрузки страницы.
 */
async function applyTheme(page, theme) {
    await page.evaluate((value) => {
        document.documentElement.setAttribute('data-admin-theme', value);
    }, theme);
}

/**
 * Гасим переходы перед снимком. Одного тега стилей мало: слой переопределений
 * панели объявляет свои transition через !important и побеждает его по
 * специфичности, поэтому снимок ловил цвет посреди перехода темы — рамка поля
 * фильтра приходила промежуточной, и тест краснел через раз. Инлайн-стиль с
 * !important сильнее любого правила таблицы стилей, поэтому проходим по узлам.
 */
async function freezeTransitions(page) {
    await page.addStyleTag({
        content: '*, *::before, *::after { transition: none !important; animation: none !important; }',
    });
    await page.evaluate(() => {
        document.querySelectorAll('*').forEach((el) => {
            // У части узлов внутри встроенного SVG инлайн-стиля нет вовсе.
            if (!el.style || typeof el.style.setProperty !== 'function') { return; }
            el.style.setProperty('transition', 'none', 'important');
            el.style.setProperty('animation', 'none', 'important');
        });
    });
}

async function collect(page, probes) {
    return page.evaluate((list) => {
        const out = {};
        for (const [selector, props] of list) {
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
    }, probes);
}

function compare(actual, name, updating) {
    const file = path.join(BASELINE, name + '.json');
    const text = JSON.stringify(actual, null, 2) + '\n';

    if (updating || !fs.existsSync(file)) {
        fs.mkdirSync(BASELINE, { recursive: true });
        fs.writeFileSync(file, text);
        return;
    }

    expect(actual, 'оформление админки изменилось — сверьте правку и обновите эталон')
        .toEqual(JSON.parse(fs.readFileSync(file, 'utf8')));
}

function isUpdating(testInfo) {
    const mode = testInfo.config.updateSnapshots;

    return process.env.UPDATE_VISUAL === '1' || (mode !== undefined && mode !== 'none' && mode !== 'missing');
}

// Панель — инструмент за большим экраном: мобильный проект её не снимает,
// иначе эталон удваивается ради раскладки, которой редактор не пользуется.
//
// Вход делаем один раз на весь файл и работаем в одной вкладке. Шаг TOTP
// одноразовый (users.totp_last_step): пять параллельных входов в одно
// тридцатисекундное окно предъявили бы один и тот же код, и прошёл бы только
// первый — остальные висли бы на второй форме.
test.describe('@visual админка', () => {
    test.describe.configure({ mode: 'serial' });

    let context = null;
    let page = null;

    test.beforeAll(async ({ browser }, testInfo) => {
        // Хуки выполняются раньше, чем срабатывает test.skip ниже, поэтому
        // мобильный проект отсекается здесь же: иначе он тоже заходил бы в
        // панель и забирал тот самый одноразовый шаг TOTP, из-за чего вход
        // десктопного проекта падал в параллельном воркере.
        if (testInfo.project.name !== 'desktop-chromium') { return; }
        // Вход умеет ждать следующего окна кода — хуку нужен запас времени.
        testInfo.setTimeout(120000);
        context = await browser.newContext({ viewport: testInfo.project.use.viewport || { width: 1440, height: 900 } });
        page = await context.newPage();
        await login(page);
    });

    test.afterAll(async () => {
        if (context) { await context.close(); }
        context = null;
        page = null;
    });

    test.skip(({ isMobile }) => Boolean(isMobile), 'админку снимаем только на десктопе');

    for (const screen of SCREENS) {
        test(screen.name, async ({}, testInfo) => {
            if (screen.navigate) {
                await screen.navigate(page, (locator) => expect(locator).toBeVisible());
            } else {
                await page.goto(screen.url, { waitUntil: 'networkidle' });
            }
            await freezeTransitions(page);
            if (screen.prepare) { await screen.prepare(page); }

            for (const theme of THEMES) {
                await applyTheme(page, theme);
                await page.waitForTimeout(120);
                compare(
                    await collect(page, screen.probes),
                    `admin-${screen.name}-${theme}`,
                    isUpdating(testInfo)
                );
            }
        });
    }

    test(MEDIA_MODAL.name, async ({}, testInfo) => {
        await page.goto(MEDIA_MODAL.url, { waitUntil: 'networkidle' });
        await freezeTransitions(page);

        // Часть полей лежит в свёрнутых секциях конструктора: берём видимую
        // кнопку, иначе клик уходит в скрытый элемент и висит до таймаута.
        await page.locator('[data-media-pick]:visible').first().click();
        await expect(page.locator('[data-media-modal]')).toBeVisible();
        // Ждём ответа библиотеки: до него сетка занята сообщением «Загрузка…».
        await expect(page.locator('[data-media-grid]')).toHaveAttribute('aria-busy', 'false');
        // Окно и карточки библиотеки появились уже после первой заморозки.
        await freezeTransitions(page);

        for (const theme of THEMES) {
            await applyTheme(page, theme);
            await page.waitForTimeout(120);
            compare(
                await collect(page, MEDIA_MODAL.probes),
                `admin-${MEDIA_MODAL.name}-${theme}`,
                isUpdating(testInfo)
            );
        }
    });
});
