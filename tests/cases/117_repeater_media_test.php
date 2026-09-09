<?php

declare(strict_types=1);

test('Поля картинок в повторителях получают выбор из медиабиблиотеки', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');

    // Кнопка навешивается по имени поля, а не по каждому месту в разметке:
    // логотипы, фото и изображения строк повторителя раньше приходилось
    // вписывать путём вручную.
    assert_contains('NAME_RE = /\\[(image|logo|photo|cover|media)\\]$/i', $js);
    assert_contains("setAttribute('data-media-pick'", $js);
    assert_contains("setAttribute('data-media-target', '#' + input.id)", $js);

    // Строки, добавленные после загрузки страницы (шаблон __INDEX__),
    // тоже должны получать кнопку.
    assert_contains('MutationObserver', $js);

    // Поля, уже обёрнутые общим компонентом изображения, не трогаем.
    assert_contains("input.closest('[data-image-field]')", $js);
});

test('Разметка блоков: у полей картинок в повторителях единый формат имени', function () {
    $form = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/pages/block_form.php');

    // Именно на эти имена опирается автоматическая кнопка медиабиблиотеки.
    foreach (['[logo]', '[photo]', '[image]'] as $needle) {
        assert_contains($needle, $form, "в форме нет полей {$needle}");
    }

    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');
    assert_contains('.repeater-media', $css, 'нет стилей для строки «поле + кнопка»');
});

test('Поля картинок вне повторителей обёрнуты компонентом, а не голым URL', function () {
    // Автокнопка из admin.js ловит только имена вида «items[3][photo]» —
    // конец имени с [image]/[logo]/[photo]/[cover]/[media]. Поля с плоским
    // именем («photo», «cta_image») она не видит, и такие поля оставались
    // с вводом адреса вручную: у блоков «Профиль» и «Хронология» из-за этого
    // не было выбора из медиабиблиотеки вовсе.
    $views = glob(APP_ROOT . '/app/Views/admin/{,*/,*/*/}*.php', GLOB_BRACE) ?: [];
    assert_true($views !== [], 'вьюхи админки не найдены');

    $keyword = '/(image|photo|logo|cover|avatar|picture|banner)/i';
    $autoEnhanced = '/\[(image|logo|photo|cover|media)\]$/i';
    // Подписи, авторы и заголовки картинок — это текст, а не адрес файла.
    $textual = '/icon_name|icon_set|_alt$|_title$|_text$|caption|credit/i';

    $bare = [];
    foreach ($views as $view) {
        $lines = explode("\n", (string) file_get_contents($view));
        foreach ($lines as $index => $line) {
            if (!str_contains($line, '<input') || !str_contains($line, 'type="text"')) {
                continue;
            }
            if (preg_match('/name="([^"]+)"/', $line, $m) !== 1) {
                continue;
            }
            $name = $m[1];
            if (preg_match($keyword, $name) !== 1
                || preg_match($textual, $name) === 1
                || preg_match($autoEnhanced, $name) === 1) {
                continue;
            }
            // Компонент AdminUi::imageField и ручные кнопки живут рядом с полем.
            $context = implode("\n", array_slice($lines, max(0, $index - 20), 28));
            if (str_contains($context, 'data-media-pick') || str_contains($context, 'data-image-input')) {
                continue;
            }
            $bare[] = basename($view) . ':' . ($index + 1) . ' — ' . $name;
        }
    }

    assert_same(
        [],
        $bare,
        "поля картинок без выбора из медиабиблиотеки:\n" . implode("\n", $bare)
            . "\nОберните их в AdminUi::imageField() — он даёт превью, кнопку и очистку"
    );
});
