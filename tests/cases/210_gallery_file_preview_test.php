<?php

declare(strict_types=1);

/**
 * Превью выбранных снимков галереи. До этого редактор выбирал файлы и не видел
 * ничего до сохранения: ни списка, ни счётчика, ни подтверждения, что выбор
 * вообще сработал.
 */

test('Поле галереи размечено под превью выбранных файлов', function () {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    $pos = strpos($form, 'news_gallery[]');
    assert_true($pos !== false, 'поле загрузки галереи на месте');

    $block = substr($form, max(0, $pos - 400), 1400);
    assert_contains('data-file-preview-input', $block);
    assert_contains('data-file-preview-list', $block);
    // Контейнер скрыт, пока ничего не выбрано: пустая рамка в форме — мусор.
    assert_contains('hidden', $block);
});

test('Скрипт админки собирает превью и умеет убрать кадр из выбора', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains('data-file-preview-input', $js);
    assert_contains('data-file-preview-drop', $js);
    // FileList только на чтение — без DataTransfer убрать один файл нельзя.
    assert_contains('new DataTransfer()', $js);

    // Превью — data:-адресом. createObjectURL() отдаёт ссылку в нашем origin:
    // открытая как страница, она исполнит скрипт из SVG. Заодно миниатюра
    // рисуется только для растровых форматов.
    assert_contains('readAsDataURL', $js);
    assert_not_contains('createObjectURL', $js);
    assert_contains("image\\/(jpeg|png|webp|gif|avif|bmp)", $js);
});

test('Стили превью описаны своими классами, а не инлайном', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    foreach (['.file-preview', '.file-preview__item', '.file-preview__drop', '.file-preview__note'] as $selector) {
        assert_contains($selector . ' {', $css);
    }

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    $pos = (int) strpos($form, 'data-file-preview-list');
    assert_not_contains('style=', substr($form, $pos - 200, 400));
});
