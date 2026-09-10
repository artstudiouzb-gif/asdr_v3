<?php

declare(strict_types=1);

/*
 * Редактор слайда: свой слой стилей поверх общей админки.
 *
 * Прежде тест сторожил структуру, которой в форме уже нет: `details.form-section`
 * со сводкой состояния, трёхколоночную сетку `.form-section__body--grid` и
 * отдельный «legacy reset», гасивший стрелку старого disclosure. Форма давно
 * собрана из `.settings-card` и `.form-grid-12`, и все эти правила не совпадали
 * ни с одним элементом страницы — тест проверял наличие мёртвого CSS и потому
 * зеленел, пока слой ветшал. Замерено обходом страницы: из 51 селектора файла
 * 21 не находил ничего, а весь reset-файл целиком (12 `!important`) не менял ни
 * одного вычисленного свойства, кроме `flex-shrink` у превью, которому
 * `min-width` всё равно не даёт сжаться.
 *
 * Теперь сторожим то, что действительно живёт, и отдельно — что мёртвый слой
 * не вернулся.
 */
test('редактор слайда использует отдельную читаемую UI-систему', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-hero-slide-editor.css');
    $admin = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    $brand = (string) file_get_contents(APP_ROOT . '/app/Core/AdminBrand.php');
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/slide_form.php');
    $script = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-hero-settings.js');

    assert_contains('form[action*="/admin/heroes/"][action*="/slides/"]', $css, 'стили ограничены редактором слайда');
    assert_contains('.form-field--checkbox', $css, 'чекбоксы приведены к единой карточке');
    // Раскладка поля медиа принадлежит самому компоненту, а не этому слою:
    // прежде слой прибивал превью 108px через `!important`, и на ряде шириной
    // в форму слайда фоновая фотография 1920×1080 показывалась ноготком.
    assert_contains('.image-field__row {', $admin, 'ряд поля медиа описан в компоненте');
    assert_not_contains(
        'width: 108px',
        $css,
        'слой снова прибивает превью к фиксированной ширине'
    );
    assert_contains('position: sticky;', $css, 'действия сохранения остаются доступны на длинной форме');
    assert_contains('@media (max-width: 760px)', $css, 'редактор имеет мобильную раскладку');
    assert_contains('.slide-action-panel__header', $css, 'настройки кнопок оформлены самостоятельными панелями');
    assert_contains('[data-hero-dependent-group][hidden]', $css, 'пустые группы зависимых полей не занимают место');

    // Поле цвета живёт по своим размерам: общее правило полей задавало ему
    // отступ 10px, и значение уезжало под образец слева.
    assert_contains(':not(:where([data-coloris]))', $css, 'поле цвета исключено из общего правила размеров');
    assert_contains('label:not(:where(.colorfield__off))', $css, 'подпись галочки «по умолчанию» не разворачивается в строку');

    assert_contains('class="settings-card"', $form, 'секции формы — карточки настроек, а не старые details');
    assert_contains('class="slide-action-stack"', $form, 'кнопки и ссылка со слайда разделены по смыслу');
    assert_contains('data-hero-dependent-group', $form, 'условные поля остаются внутри своей группы');
    assert_contains('#forma или /page#forma', $form, 'формат якорных ссылок объяснён непосредственно в поле');

    assert_contains('function dependentGroups()', $script, 'пустые группы скрываются после пересчёта зависимых полей');
    assert_contains('function emptySections()', $script, 'секция без единого видимого поля прячется целиком');
    assert_contains('.settings-jump-nav a[href="#', $script, 'вместе с секцией уходит и ссылка на неё в навигации');
    assert_contains('.settings-jump-nav a[hidden]', $admin, 'скрытой ссылке навигации нужен явный display: none');

    assert_contains('/assets/css/admin-hero-slide-editor.css', $brand, 'слой подключён через версионируемый Asset::url');

    // Мёртвый слой не возвращается: этих структур в форме нет.
    foreach (['details.form-section', '.form-section__state', '.form-section__body--grid', '.form-field--wide'] as $gone) {
        assert_not_contains($gone, $css, 'слой описывает структуру, которой в форме слайда нет: ' . $gone);
    }
    assert_true(
        !is_file(APP_ROOT . '/public/assets/css/admin-hero-slide-editor-legacy-reset.css'),
        'отдельный reset-слой снят: он не менял ни одного вычисленного свойства'
    );
    assert_not_contains('legacy-reset', $brand, 'снятый слой не подключается');
});
