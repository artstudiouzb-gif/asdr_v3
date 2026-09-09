<?php

declare(strict_types=1);

/**
 * CSP админки и портала не разрешает inline-скрипты: script-src задаёт
 * 'self' и nonce. Атрибуты вида onclick/onchange браузер отказывается
 * выполнять — элемент выглядит рабочим, но не делает ничего. Так молча
 * ломались выбор файла в медиабиблиотеке, фильтры и загрузка в /repo.
 */

test('CSP: шаблоны не используют inline-обработчики событий', function () {
    $root = dirname(__DIR__, 2) . '/app/Views';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $offenders = [];
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        if (preg_match('/\son(?:click|change|submit|input|load|error|focus|blur)\s*=\s*["\']/i', $contents) === 1) {
            $offenders[] = str_replace($root . '/', '', $file->getPathname());
        }
    }

    assert_same([], $offenders, 'inline-обработчики не выполняются при действующей CSP');
});

test('CSP: портал репозитория подключает скрипт файлом, а не инлайном', function () {
    $bottom = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/repo/layout/bottom.php');

    assert_contains('/assets/js/repo.js', $bottom);
    // Инлайновый блок с логикой портала не должен вернуться.
    assert_false(
        (bool) preg_match('/<script(?![^>]*\ssrc=)[^>]*>\s*\S/', $bottom),
        'исполняемый inline-скрипт в шаблоне портала'
    );
});

test('CSP: заменённые действия обслуживаются скриптами', function () {
    $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
    foreach (['data-file-pick', 'data-autosubmit', 'data-close-target', 'data-remove-closest', 'data-admin-theme-preview'] as $hook) {
        assert_contains($hook, $admin, "admin.js не обрабатывает {$hook}");
    }

    $repo = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/repo.js');
    foreach (['data-open-details', 'data-close-dialog', 'data-autosubmit', 'data-confirm-submit', 'data-view-mode', 'data-copy-link'] as $hook) {
        assert_contains($hook, $repo, "repo.js не обрабатывает {$hook}");
    }
});

/**
 * Сортировка в портале — GET-форма. Скрипт не должен брать адрес перехода
 * из разметки: значение элемента — это текст из DOM, и переход по нему
 * CodeQL справедливо считает открытым редиректом (js/xss-through-dom).
 */
test('Портал: сортировка отправляет форму, а не переходит по адресу из option', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/repo/index.php');
    $repo = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/repo.js');

    assert_contains('<form class="rd-sort-group" method="get" action="/repo">', $view);
    assert_contains('<select class="rd-sort-select" name="sort" data-autosubmit>', $view);
    // Без JS выбор всё равно применяется кнопкой.
    assert_contains('<noscript>', $view);

    assert_not_contains('data-nav-select', $repo);
    assert_not_contains('window.location.assign', $repo);
    assert_contains('select.form.requestSubmit()', $repo);
});

test('CSP: разметка медиабиблиотеки использует новые хуки', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/files/index.php');

    assert_contains('data-file-pick="media_file_input"', $view);
    assert_contains('data-close-target="media_modal"', $view);
    assert_same(3, substr_count($view, 'data-autosubmit'), 'фильтры типа, даты и сортировки');
    // Форма загрузки осталась обычной multipart-формой.
    assert_contains('action="/admin/files/upload" enctype="multipart/form-data"', $view);
});

/**
 * Обход админки в smoke раньше засчитывал редирект на профиль как успех:
 * сессия без второго фактора пускает только туда, и все админ-страницы
 * «проходили», хотя проверялся один и тот же профиль.
 */
test('Smoke: ограниченная сессия не выдаётся за пройденную админку', function () {
    $smoke = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/smoke.php');

    // Вход, который привёл в профиль, помечается провалом с подсказкой.
    assert_contains("stripos(\$auth['final'], '/admin/profile') !== false", $smoke);
    assert_contains('Вход ограничен настройкой второго фактора', $smoke);

    // Отдельные страницы, редиректящие в профиль, тоже не считаются пройденными.
    assert_contains('$bouncedToProfile', $smoke);
    assert_contains("\$path !== '/admin/profile'", $smoke);
    assert_contains('редирект на профиль: доступ ограничен', $smoke);
});

/**
 * Данные, пришедшие с сервера, не должны собираться в HTML-строку: имя файла
 * или адрес записи может содержать кавычки и угловые скобки, и тогда строка
 * перестаёт быть данными (CodeQL: DOM text reinterpreted as HTML).
 */
test('XSS: результаты поиска и карточки медиабиблиотеки строятся узлами', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');

    // Быстрый поиск: ни адрес, ни тип записи не склеиваются с разметкой.
    assert_not_contains("'<a class=\"admin-search__item\" href=\"' + r.url", $js);
    assert_not_contains("'<span class=\"admin-search__type\">' + r.type", $js);
    assert_contains("link.setAttribute('href'", $js);
    assert_contains("type.textContent = r.type", $js);

    // Медиабиблиотека: расширение файла подставляется текстом.
    assert_not_contains("'<span class=\"media-modal__fileicon\">' + ext", $js);
    assert_contains("badge.textContent = extOf(it.name);", $js);

    // Репитер разворачивает <template> клонированием, а не повторным разбором
    // разметки: чтение innerHTML с обратной записью теряет экранирование.
    assert_not_contains('template.innerHTML.replace', $js);
    assert_contains('instantiateRepeaterTemplate', $js);
    assert_contains('template.content.cloneNode(true)', $js);

    // Прочитанный из DOM текст не возвращается обратно как разметка: ни при
    // подсчёте длины подписи, ни при восстановлении содержимого кнопок.
    assert_not_contains('decoder.innerHTML', $js);
    assert_contains('function decodeEntities(', $js);
    assert_false(
        (bool) preg_match('/=\s*\w+\.innerHTML\b/', $js),
        'чтение innerHTML с обратной записью — источник алерта CodeQL'
    );

    // Теги вырезаются одним проходом по строке: одиночный replace оставляет
    // «<script», если теги вложены друг в друга (CodeQL: incomplete
    // multi-character sanitization).
    assert_not_contains("replace(/<[^>]*>/g", $js);
    assert_contains('function stripTags(', $js);
});
