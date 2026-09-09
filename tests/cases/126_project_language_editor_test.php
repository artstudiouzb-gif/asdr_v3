<?php

declare(strict_types=1);

test('Редактор проекта поддерживает независимые языковые записи', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/projects/form.php');

    assert_contains('renderSidebarMetaBox', $form, 'форма использует блок независимых переводов');
    // Тело проекта собирается конструктором блоков (проект — страница с
    // подтипом), а в поле description остаётся короткий анонс для карточки.
    assert_contains('name="description"', $form, 'у проекта есть поле анонса');
    assert_not_contains('name="description" data-wysiwyg', $form, 'анонс — простой текст, без визуального редактора');
    assert_contains("_block_editor.php", $form, 'форма подключает конструктор блоков');
    // Одно место для содержимого: галерея и свободные поля переехали в блоки,
    // их разделов в форме больше нет — иначе редактор не знает, куда класть.
    assert_not_contains('custom_fields[', $form, 'свободных полей в форме проекта нет');
    assert_not_contains('gallery[', $form, 'галереи в форме проекта нет');
    assert_contains('cover_image_url', $form, 'обложка проекта осталась в форме');
    assert_same(1, substr_count($form, 'name="title"'), 'основное название не дублируется');
    assert_same(1, substr_count($form, 'name="description"'), 'основное описание не дублируется');
});

test('Конструктор: переключатель языка блоков есть у страницы и не мешает проекту', function (): void {
    $editor = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/_block_editor.php');

    // У страницы стек блоков свой на каждый язык — до него нужна кнопка, иначе
    // второй язык доступен только правкой адреса.
    assert_contains('block-lang-switch', $editor);
    assert_contains('block_lang=', $editor);
    // У проекта языковая версия это отдельная запись, её переключает сайдбар:
    // второй переключатель на той же форме путал бы.
    assert_contains("entity_type'] ?? 'page') === 'page'", $editor);
});
