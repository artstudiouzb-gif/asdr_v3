<?php

declare(strict_types=1);

test('редактор слайда использует отдельную читаемую UI-систему', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-hero-slide-editor.css');
    $reset = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-hero-slide-editor-legacy-reset.css');
    $brand = (string) file_get_contents(APP_ROOT . '/app/Core/AdminBrand.php');

    assert_contains('form[action*="/admin/heroes/"][action*="/slides/"]', $css, 'стили ограничены редактором слайда');
    assert_contains('grid-template-columns: repeat(3, minmax(0, 1fr)) !important;', $css, 'широкий экран использует максимум три читаемые колонки');
    assert_contains('details.form-section > summary', $css, 'секции получают единый заголовок');
    assert_contains('.form-section__state', $css, 'сводка состояния секции оформлена отдельно');
    assert_contains('.form-field--checkbox', $css, 'чекбоксы приведены к единой карточке');
    assert_contains('.image-field__row', $css, 'поля изображений выровнены в общей сетке');
    assert_contains('position: sticky;', $css, 'действия сохранения остаются доступны на длинной форме');
    assert_contains('@media (max-width: 760px)', $css, 'редактор имеет мобильную раскладку');

    assert_contains('details.form-section > summary::before', $reset, 'старый disclosure-marker нейтрализуется только внутри Hero editor');
    assert_contains('content: none !important;', $reset, 'старую стрелку нельзя отрисовать одновременно с новой');
    assert_contains('margin-left: 0 !important;', $reset, 'flex-выравнивание старого state badge сброшено для grid summary');
    assert_contains('flex-direction: initial !important;', $reset, 'flex-наследие generic form-section не влияет на новую сетку');
    assert_contains('box-shadow: none !important;', $reset, 'generic image preview не протаскивает старую тень в новый editor');

    assert_contains('/assets/css/admin-hero-slide-editor.css', $brand, 'новый слой подключён через версионируемый Asset::url');
    assert_contains('/assets/css/admin-hero-slide-editor-legacy-reset.css', $brand, 'scoped reset загружается после основного editor layer');
    assert_contains('data-admin-hero-slide-editor-reset-css', $brand, 'compatibility reset можно однозначно проверить в DOM');
});
