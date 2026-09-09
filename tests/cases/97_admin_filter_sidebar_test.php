<?php

declare(strict_types=1);

test('admin filters wrap inside their panel and sidebar uses a light accessible palette', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');

    assert_true(is_string($css));
    assert_contains('--admin-sidebar-bg: #f6f7f7', $css);
    assert_contains('--admin-sidebar-text: #2c3338', $css);
    assert_contains('--admin-sidebar-active: #dcecf7', $css);
    // Панель фильтров — сетка одинаковых дорожек: при переносе flex-строкой
    // вторая строка вставала своими ширинами и не совпадала с первой.
    assert_contains('.list-filters--panel { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));', $css);
    assert_contains('.list-filters__actions { display: flex; flex: 0 0 auto;', $css);
    assert_contains('.list-filters__actions { width: 100%; margin-left: 0; }', $css);
});

test('header settings group behavior controls into spacious responsive cards', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/header/index.php');
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');

    assert_true(is_string($view));
    assert_true(is_string($css));
    // К классам блока может добавляться utility-класс, сгенерированный при
    // выносе inline-стилей (`u-inline-…`), поэтому проверяем наличие класса в
    // атрибуте, а не точное совпадение всей строки class="…".
    foreach (['hb-behavior__options', 'hb-behavior-card', 'hb-behavior__media'] as $class) {
        assert_true(
            preg_match('/class="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"/', (string) $view) === 1,
            "в конструкторе шапки нет элемента с классом {$class}"
        );
    }
    // Проверяем действующее правило, а не первое попавшееся: у этого селектора
    // было два семейства, и нижнее перекрывало верхнее целиком — тест держался
    // за мёртвую копию и прошёл бы даже с испорченной сеткой.
    // Без !important: слой переопределений разобран, правило действует весом
    // селектора. Пиноваться к !important в тесте значит закреплять его насовсем.
    $normalizedCss = str_replace("\r\n", "\n", $css);
    assert_contains(".hb-behavior__options {\n    display: grid;", $normalizedCss);
    assert_contains('@media (max-width: 720px)', $normalizedCss);
});

test('long admin editors expose a fixed save bar without covering their content', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');
    $layout = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/layout/footer.php');
    $header = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/header/index.php');
    $block = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/pages/block_form.php');

    assert_true(is_string($css));
    assert_true(is_string($layout));
    assert_true(is_string($header));
    assert_true(is_string($block));
    assert_contains('.form-actions--sticky {', $css);
    assert_contains('position: fixed; bottom: 0;', $css);
    assert_contains('padding-bottom: 120px', $css);
    assert_contains("document.body.classList.add('has-sticky-actions')", $layout);
    assert_contains("actions.classList.remove('is-context-hidden')", $layout);
    assert_contains('form-actions form-actions--sticky', $header);
    assert_contains('form-actions form-actions--sticky', $block);
});

test('installer buttons have consistent states and prevent duplicate submission', function (): void {
    $header = file_get_contents(dirname(__DIR__, 2) . '/app/Views/install/_header.php');
    $footer = file_get_contents(dirname(__DIR__, 2) . '/app/Views/install/_footer.php');
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');

    assert_true(is_string($header));
    assert_true(is_string($footer));
    assert_true(is_string($css));
    assert_contains('install-card', $header);
    // Стили установщика переехали из inline-<style> во вьюхе в admin.css —
    // этого же требует проверка «Reject new static inline styles» в CI.
    assert_contains('.install-card .btn--primary:hover', $css);
    assert_not_contains('<style', (string) $header);
    assert_contains("button.setAttribute('aria-busy', 'true')", $footer);
    assert_contains("button.textContent = 'Подождите…'", $footer);
});

test('header and footer builders use the shared wide workspace', function (): void {
    $root = dirname(__DIR__, 2);
    $header = file_get_contents($root . '/app/Views/admin/header/index.php');
    $footer = file_get_contents($root . '/app/Views/admin/footer/index.php');
    $css = file_get_contents($root . '/public/assets/css/admin.css');

    assert_true(is_string($header));
    assert_true(is_string($footer));
    assert_true(is_string($css));
    assert_contains('class="admin-builder-workspace"', $header);
    assert_contains('class="admin-builder-workspace"', $footer);
    // Ширину рабочей области задаёт общий блок «во всю ширину» ниже по файлу;
    // отдельное правило было его мёртвой копией.
    assert_contains('.admin-builder-workspace,', $css);
    assert_contains('width: 100% !important;', $css);
    assert_contains('grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));', $css);
});

test('repository source does not contain the retired external product name', function (): void {
    $root = dirname(__DIR__, 2);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        // Проверяем только исходники репозитория. Каталоги зависимостей и
        // артефактов перечислены в .gitignore и в поставку не входят: в наборе
        // иконок Tabler, например, есть бренд-иконка с этим названием, из-за
        // которой тест падал у любого, кто выполнил `npm ci` перед прогоном.
        $skipped = ['.git', 'vendor', 'node_modules', 'storage', 'playwright-report', 'test-results', 'coverage'];
        foreach ($skipped as $dir) {
            if (str_contains($path, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)) {
                continue 2;
            }
        }
        // Индекс спрайта — производная вендорного набора Tabler: там же лежит
        // бренд-иконка с этим названием, а сам спрайт уже исключён как vendor.
        if (basename($path) === 'tabler-sprite-index.php') {
            continue;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            continue;
        }
        assert_true(stripos($contents, 'word' . 'press') === false, 'Найдено запрещённое название: ' . $path);
    }
});
