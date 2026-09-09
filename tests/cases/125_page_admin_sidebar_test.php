<?php

declare(strict_types=1);

test('Настройки страницы находятся в правой колонке без дублирования полей', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/form.php');
    $sidebar = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/_settings_sidebar.php');

    $gridPos = strpos($form, '<div class="entry-grid">');
    $mainPos = strpos($form, '<div class="entry-main">');
    $asidePos = strpos($form, '<aside class="entry-side">');
    $partialPos = strpos($form, "require __DIR__ . '/_settings_sidebar.php'");

    assert_true($gridPos !== false && $mainPos !== false && $asidePos !== false, 'редактор должен использовать двухколоночную раскладку');
    assert_true($gridPos < $mainPos && $mainPos < $asidePos && $asidePos < $partialPos, 'настройки должны подключаться внутри правой колонки');

    foreach (['slug', 'layout_type', 'status', 'is_home', 'hide_chrome', 'transparent_header'] as $field) {
        assert_same(1, substr_count($sidebar, 'name="' . $field . '"'), $field . ' выводится в сайдбаре ровно один раз');
        assert_not_contains('name="' . $field . '"', $form, $field . ' не дублируется в основной колонке');
    }

    assert_contains('form-actions form-actions--sticky', $form, 'действия сохранения находятся в шаблоне');
    assert_contains('/preview?block_lang=', $form, 'предпросмотр сохраняет выбранный язык блоков');
});
