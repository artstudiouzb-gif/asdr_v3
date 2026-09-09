<?php

declare(strict_types=1);

test('public editorial surfaces use the shared rich content class', function (): void {
    $root = dirname(__DIR__, 2);
    // Страницы проектов в списке нет намеренно: их тело собирается блоками,
    // а rich-content живёт в шаблоне блока «Текст».
    $files = [
        '/templates/blocks/text.php',
        '/templates/blocks/text_image.php',
        '/templates/blocks/faq.php',
        '/app/Views/site/news_show.php',
        '/app/Views/site/content_show.php',
    ];

    foreach ($files as $file) {
        $view = file_get_contents($root . $file);
        assert_true(is_string($view));
        assert_contains('rich-content', $view);
    }
});

test('text_image поддерживает безопасный HTML и старый простой текст', function (): void {
    $template = dirname(__DIR__, 2) . '/templates/blocks/text_image.php';

    $render = static function (string $text) use ($template): string {
        $data = \App\Core\BlockData\BlockFieldSchema::apply('text_image', ['text' => $text]);
        ob_start();
        require $template;
        return (string) ob_get_clean();
    };

    $html = $render('<p>Абзац <strong>важный</strong></p><script>alert(1)</script>');
    assert_contains('<p>Абзац <strong>важный</strong></p>', $html);
    assert_not_contains('<script', $html);
    assert_contains('rich-content', $html);

    $plain = $render("Первая строка\nВторая строка");
    assert_contains('Первая строка<br', $plain);
    assert_contains('Вторая строка', $plain);
});

test('text_image адаптируется к ширине контейнера с viewport fallback', function (): void {
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/public-layout-polish.css');
    $fallback = theme_css();

    assert_contains('.cms-block--text_image {', $css);
    assert_contains('container-type: inline-size;', $css);
    assert_contains('container-name: text-image;', $css);
    assert_contains('@container text-image (max-width: 900px)', $css);
    assert_contains('@media (max-width: 900px)', $fallback);
});

test('форма и обработчик text_image используют WYSIWYG и текстовый санитайзер', function (): void {
    // Поле текста рисует схема: у richtext-поля редактор включается атрибутом
    // data-wysiwyg, а содержимое чистится санитайзером на входе.
    assert_contains('name="text" rows="3" data-wysiwyg', block_editor_markup());

    $textField = \App\Core\BlockData\BlockFieldSchema::fields('text_image')['text'] ?? null;
    assert_true($textField !== null && $textField->kind === 'richtext', 'текст блока — форматируемый');
    assert_contains(
        '<em>курсив</em>',
        \App\Core\BlockData\BlockFieldSchema::normalize('text_image', ['text' => '<em>курсив</em><script>alert(1)</script>'], 'ru')['text'],
        'безопасная разметка сохраняется'
    );
    assert_not_contains(
        '<script',
        \App\Core\BlockData\BlockFieldSchema::normalize('text_image', ['text' => '<em>курсив</em><script>alert(1)</script>'], 'ru')['text'],
        'скрипт вырезается санитайзером'
    );
});

test('rich content stylesheet covers editorial elements and responsive tables', function (): void {
    $root = dirname(__DIR__, 2);
    $css = file_get_contents($root . '/public/assets/css/rich-content.css');
    $header = file_get_contents($root . '/app/Views/site/_header.php');

    assert_true(is_string($css));
    assert_true(is_string($header));
    assert_contains(':where(.rich-content) blockquote {', $css);
    assert_contains(':where(.rich-content) table {', $css);
    assert_contains(':where(.rich-content) pre {', $css);
    assert_contains('@media (max-width: 720px)', $css);
    assert_contains('overflow-x: auto;', $css);
    assert_contains('FrontendAssets::styles()', $header);
});

test('детальный проект использует всю ширину контейнера для rich-content', function (): void {
    $css = theme_css();

    assert_contains('.projdetail__content { width: 100%; max-width: none; }', $css);
    assert_false(str_contains($css, '.projdetail__content { max-width: 76ch; }'));
});

test('TinyMCE: стандартный вид без узкой колонки и без редакционных стилей', function (): void {
    $editor = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/vendor/editor.js');

    assert_true(is_string($editor));
    assert_false(str_contains($editor, 'rich-content'), 'редакционные стили убраны из редактора');
    assert_contains('max-width: none', $editor);
});

test('TinyMCE: самохостинг без внешнего CDN', function (): void {
    $root = dirname(__DIR__, 2);
    $editor = (string) file_get_contents($root . '/public/assets/js/vendor/editor.js');

    assert_false(str_contains($editor, 'cdn.jsdelivr.net'), 'нет ссылок на внешний CDN');
    assert_contains("script.src = '/assets/js/vendor/tinymce/tinymce.min.js'", $editor);
    assert_contains("base_url: '/assets/js/vendor/tinymce'", $editor);
    // Локальный движок и язык физически присутствуют.
    assert_true(is_file($root . '/public/assets/js/vendor/tinymce/tinymce.min.js'), 'движок на месте');
    assert_true(is_file($root . '/public/assets/js/vendor/tinymce/langs/ru.js'), 'русский язык на месте');
    assert_true(is_file($root . '/public/assets/js/vendor/tinymce/themes/silver/theme.min.js'), 'тема на месте');
});
