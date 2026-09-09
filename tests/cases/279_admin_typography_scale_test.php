<?php

declare(strict_types=1);

/**
 * Типографика админки: одна шкала кегля и три начертания.
 *
 * Было 72 разных размера — от 9px до 26px, вперемешку px и rem, с половинками
 * (11.5, 12.5, 13.5, 14.5) и близнецами вроде 0.82rem = 13.1px рядом с 13px, —
 * и 15 начертаний, включая 450, 550, 650, 720, 750 и 850. Таких начертаний у
 * системных шрифтов нет: браузер подменял их синтетическим жирным, отчего текст
 * и выглядел грубым. Из 340 объявлений веса 285 стояли на 600 и выше.
 *
 * Теперь размер берётся только из `--admin-fs-*`, вес — только из
 * `--admin-fw-*`, семейство — из `--admin-font`. Новое значение мимо шкалы
 * роняет тест: подобрать «ещё один 13.5px» заново нельзя.
 */

/*
 * `admin_css_files()` объявлена в tests/budgets.php: по тому же списку считается
 * бюджет `!important` панели.
 */

/**
 * Значения одного свойства во всех файлах админки.
 *
 * @return array<string,string> значение => файл, где встретилось
 */
function admin_css_values(string $property): array
{
    $found = [];
    foreach (admin_css_files() as $file) {
        $css = (string) file_get_contents($file);
        if (!preg_match_all('/' . $property . '\s*:\s*([^;}]+)/i', $css, $m)) {
            continue;
        }
        foreach ($m[1] as $value) {
            $value = trim(str_replace('!important', '', $value));
            $found[$value] = basename($file);
        }
    }

    return $found;
}

test('Кегль в админке берётся из шкалы', function () {
    $values = admin_css_values('font-size');
    assert_true(count($values) > 0, 'объявления кегля найдены');

    $strays = [];
    foreach ($values as $value => $file) {
        // Ключ массива с числовым значением PHP приводит к int.
        $value = (string) $value;
        // `--live-*` и `--preview-*` — предпросмотр публичной страницы внутри
        // панели: он обязан повторять сайт, а не шкалу админки. `em` — размер
        // относительно родителя, у него другой смысл.
        if (str_starts_with($value, 'var(--admin-fs-')) { continue; }
        if (str_contains($value, 'var(--live-') || str_contains($value, 'var(--preview-')) { continue; }
        if ($value === '0' || preg_match('/^[0-9.]+em$/', $value)) { continue; }
        $strays[] = $value . ' (' . $file . ')';
    }

    sort($strays);
    assert_true($strays === [], 'кегль мимо шкалы: ' . implode(', ', $strays));
});

test('Начертаний в админке три', function () {
    $values = admin_css_values('font-weight');
    assert_true(count($values) > 0, 'объявления веса найдены');

    $strays = [];
    foreach ($values as $value => $file) {
        $value = (string) $value;
        if (str_starts_with($value, 'var(--admin-fw-')) { continue; }
        if (str_contains($value, 'var(--live-')) { continue; }
        $strays[] = $value . ' (' . $file . ')';
    }

    sort($strays);
    assert_true($strays === [], 'вес мимо набора: ' . implode(', ', $strays));

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    foreach (['--admin-fw-normal: 400', '--admin-fw-medium: 500', '--admin-fw-semibold: 600'] as $token) {
        assert_contains($token, $css, 'объявлено начертание ' . $token);
    }
    foreach (['--admin-fs-xs: 11px', '--admin-fs-base: 13px', '--admin-fs-2xl: 24px'] as $token) {
        assert_contains($token, $css, 'объявлена ступень ' . $token);
    }
});

test('Семейство шрифта в админке одно', function () {
    // Шрифты-исключения: три карточки выбора шрифта в «Дизайне» показывают
    // образец начертания, а превью выдачи Google набрано Arial, как в самой
    // выдаче. Остальное — общий токен.
    $allowed = [
        'var(--admin-font)',
        'var(--admin-font-mono)',
        'inherit',
        'monospace',
        'var(--font-mono, monospace)',
        "'Noto Sans', 'Noto Sans Fallback', system-ui, sans-serif",
        'system-ui, Arial, sans-serif',
        "Georgia, 'Times New Roman', serif",
        'arial,sans-serif',
    ];

    $strays = [];
    foreach (admin_css_values('font-family') as $value => $file) {
        $value = (string) $value;
        if (in_array($value, $allowed, true)) { continue; }
        if (str_contains($value, 'var(--live-')) { continue; }
        $strays[] = $value . ' (' . $file . ')';
    }

    sort($strays);
    assert_true($strays === [], 'семейство мимо токена: ' . implode(', ', $strays));
});

test('Панель подключает свой шрифт на каждом экране', function () {
    // Файл переменный: одна woff2 покрывает ось 400–700, поэтому три начертания
    // шкалы не стоят ни одного лишнего байта. Лицо обязано объявлять диапазон —
    // при трёх фиксированных весах любой невыписанный (например 500) браузер
    // подделывал бы синтетическим жирным, хотя настоящий лежит в том же файле.
    $fontCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/noto-sans.css');
    assert_contains('font-weight: 400 700;', $fontCss, 'лицо объявляет диапазон весов');
    assert_true(
        substr_count($fontCss, '@font-face') === 3,
        'три лица — по одному на подмножество, а не по одному на вес'
    );

    $admin = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    assert_contains("--admin-font: 'Noto Sans',", $admin, 'панель набрана Noto Sans, системный стек — запасной');

    // Экран входа и второго фактора рисуются мимо общей шапки: без подключения
    // шрифта они выглядели бы другой гарнитурой, чем остальная панель.
    $heads = [
        'app/Views/admin/layout/header.php',
        'app/Views/admin/auth/login.php',
        'app/Views/admin/auth/2fa.php',
        'app/Views/admin/auth/forgot.php',
        'app/Views/admin/auth/reset.php',
    ];
    foreach ($heads as $head) {
        $html = (string) file_get_contents(APP_ROOT . '/' . $head);
        assert_contains('AdminUi::fontLinks()', $html, basename($head) . ' подключает шрифт панели');
    }

    $links = \App\Core\AdminUi::fontLinks();
    assert_contains('rel="preload"', $links, 'подмножества предзагружаются');
    assert_contains('noto-sans-var-cyrillic.woff2', $links, 'кириллица предзагружается');
    assert_contains('noto-sans-var-latin.woff2', $links, 'латиница — тоже: цифры и даты живут в ней');
    // Адрес preload обязан совпадать с адресом в @font-face — иначе браузер
    // считает это разными ресурсами и качает файл дважды.
    assert_not_contains('woff2?v=', $links, 'адрес шрифта без версии');
});

test('Число !important в CSS админки не растёт', function () {
    // Слой «Enterprise Scale» перебивает компоненты через !important, и каждая
    // такая строка делает следующую правку непредсказуемой: так поле Coloris
    // получало HEX под образцом, кнопки массовых действий — вторую строку,
    // модификаторы бейджа не могли изменить форму, а базовый кегль body был
    // на пиксель крупнее полей вокруг. Разбирается семьями: сначала правило
    // переносится в компонент или получает честный вес селектора, потом
    // снимается !important, и срез вычисленных стилей доказывает, что вид не
    // поехал. Бюджет только уменьшается — как у публичного CSS: было 961,
    // после полей форм — 895, таблиц — 867, бейджей — 860, заголовков — 845,
    // остальных секций слоя (вход, конструктор шапки, слайды обложек, панель
    // действий, тосты, сайдбар, фильтры, меню действий) — 372.
    // Потолок и замер — в tests/budgets.php, запас показывает отчёт
    // `php .claude/skills/quality-budgets/report.php`.
    $budget = quality_budget('admin_important');

    assert_true(
        $budget['value'] <= $budget['ceiling'],
        'было ' . $budget['ceiling'] . ', стало ' . $budget['value']
            . ' — новый !important в админке не проходит; больше всего: ' . $budget['detail']
    );
});

test('Ход перехода: полоса объявлена в CSS и живёт до конца перехода', function (): void {
    // Админка отвечает полной перезагрузкой на каждое сохранение и переход.
    // AJAX-сохранения здесь нет и не было: формы уходят обычным POST, а flash
    // и тосты устроены под редирект. Но между нажатием и новой страницей
    // раньше не происходило ничего — ожидание читалось как «не сработало».
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains('.admin-route-progress', $css, 'полоса без правил ничего не покажет');
    assert_contains("bar.className = 'admin-route-progress'", $js);
    // Цвет — из темы панели: у светлой и тёмной он разный.
    assert_contains('background: var(--admin-accent)', $css);

    // Переход бывает и не доходит до новой страницы (скачивание файла,
    // отменённая отправка, ошибка сети). Бесконечная полоса врёт хуже, чем её
    // отсутствие, поэтому у неё есть предел ожидания и снятие из кэша «назад».
    assert_contains('window.setTimeout(finishProgress, 20000)', $js);
    assert_contains("window.addEventListener('pageshow', finishProgress)", $js);
});

test('Возврат к месту правки после сохранения', function (): void {
    // Сохранение уводит в начало страницы, и на длинной форме («Настройки
    // сайта» — шесть с лишним тысяч пикселей) редактор терял место правки.
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains('artstudio:admin:scroll:', $js);
    assert_contains('function rememberScroll', $js);
    assert_contains('function restoreScroll', $js);
    // Якорь в адресе — осознанная цель редиректа (/admin/telegram#telegram-channel),
    // спорить с ней нельзя: иначе сообщение снова окажется выше экрана.
    assert_contains('if (window.location.hash) { return; }', $js);
});

test('Отступ якоря под липкой шапкой админки объявлен один раз', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');

    // Прокрутка к якорю обязана останавливаться ниже липкой шапки и липкой
    // полосы переходов раздела. Пока отступ раздавался перечислением целей,
    // восемь разделов «Настроек сайта» (они размечены `.settings-card`, а не
    // `.settings-group`) в список не попадали: переход из верхней навигации
    // ставил карточку раздела на y=0, то есть ровно под шапку — замерено в
    // Chromium, заголовок раздела не виден вовсе.
    assert_contains('html { scroll-padding-top: calc(var(--admin-topbar-h) + 64px); }', $css);

    // Вторая константа того же назначения — повод для расхождения: цели
    // добавляются, а список нет. Ровно так приём и сломался.
    assert_true(
        !str_contains($css, 'scroll-margin-top'),
        'отступ якоря задаётся только scroll-padding-top у html, без списка целей'
    );
});
