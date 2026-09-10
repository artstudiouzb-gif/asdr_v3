<?php

declare(strict_types=1);

use App\Core\FooterConfig;

/**
 * Повторители строк в админке (колонки подвала, поля форм, повторяемые
 * группы блоков) нумеруют новую строку по имени поля. Нумерация по числу
 * строк ломается после удаления строки из середины: индекс повторяется,
 * и в POST остаётся только последняя из двух одноимённых строк — введённое
 * пропадает без сообщения.
 */

test('Репитер: номер новой строки считается по именам полей, а не по их числу', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');

    assert_contains('function nextRepeaterIndex(', $js);
    assert_contains('nextRepeaterIndex(container, template)', $js);
    // Образец имени берётся из шаблона: индекс стоит по-разному
    // («columns[0][heading]» и «custom_fields[cf_0][key]»).
    assert_contains('[name*="__INDEX__"]', $js);
    // Прежний счёт по числу строк давал дубль индекса.
    assert_not_contains(': container.children.length;', $js);
});

test('Репитер: лимит строк не даёт молча потерять лишнее', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/footer/index.php');

    assert_contains("getAttribute('data-repeater-max')", $js);
    assert_contains('Максимум строк:', $js);

    // Подвал рисуется максимум в 4 колонки, лишние отбрасывались при
    // сохранении — теперь их нельзя и добавить.
    assert_contains('data-repeater-max="<?= FooterConfig::MAX_COLUMNS ?>"', $view);
    assert_same(4, FooterConfig::MAX_COLUMNS);
});

test('Редактор блоков собирает поля и действия репитера в компактную карточку', function () {
    $root = dirname(__DIR__, 2);
    $js = (string) file_get_contents($root . '/public/assets/js/admin.js');
    $css = (string) file_get_contents($root . '/public/assets/css/admin.css');
    $view = (string) file_get_contents($root . '/app/Views/admin/pages/block_form.php');

    assert_contains('class="form-card block-editor-card"', $view);
    assert_contains('form-grid block-editor-form', $view);
    assert_contains('function arrangeBlockRepeaterRow(', $js);
    assert_contains("actions.className = 'repeater-row__actions'", $js);
    assert_contains('.block-editor-form .repeater-row:not(.fb-card):not(.widget-slot-row)', $css);
    assert_contains('grid-template-columns: repeat(12, minmax(0, 1fr));', $css);
    assert_contains('.block-editor-form .repeater-row__actions', $css);
});
