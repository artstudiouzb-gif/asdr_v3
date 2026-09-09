<?php

declare(strict_types=1);

use App\Core\SliderRatio;

/*
 * Пропорции кадра карусели. Блок «Слайдер» и виджет «Фотокарусель» делят
 * разметку и стили, поэтому и набор пропорций у них общий: раздельные списки
 * разъезжались молча — в одной форме появлялся формат, которого нет в другой.
 *
 * Вертикальные форматы нужны не «на всякий случай»: карточки для соцсетей
 * приходят в 4:5, 1:1 и 9:16, а при широкой пропорции `object-fit: cover`
 * срезает у них верх и низ — от карточки остаётся полоса по центру.
 */

test('Пропорции: вертикальные форматы есть, значение чужое откатывается', function () {
    foreach (['21-9', '16-9', '4-3', '1-1', '4-5', '9-16', 'auto'] as $key) {
        assert_true(isset(SliderRatio::ALL[$key]), 'пропала пропорция: ' . $key);
    }

    assert_same('16-9', SliderRatio::normalize('какая-нибудь'), 'чужое значение должно откатываться');
    assert_same('16-9', SliderRatio::normalize(null));
    assert_same('16-9', SliderRatio::normalize(''));
    assert_same('9-16', SliderRatio::normalize('9-16'));
    assert_same('4-5', SliderRatio::normalize(' 4-5 '), 'пробелы по краям не должны мешать');
});

test('Каждой пропорции отвечает правило в CSS', function () {
    // Ключ пропорции — это имя класса. Настройка без правила ничего не меняет
    // на выводе: слайдер молча остаётся с умолчанием.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    foreach (SliderRatio::keys() as $key) {
        if ($key === 'auto') {
            // «auto» — отсутствие пропорции: у него своё правило-исключение.
            assert_contains('.block-slider:not(.block-slider--ratio-auto)', $css, 'нет правила для «как у изображения»');
            continue;
        }
        assert_contains('.block-slider--ratio-' . $key . ' {', $css, 'нет правила для пропорции ' . $key);
    }
});

test('Список пропорций один на обе поверхности', function () {
    // Свой список у каждой поверхности разъезжается молча — все обязаны
    // спрашивать SliderRatio. Блок «Слайдер» спрашивает его через схему полей,
    // поэтому ветки в контроллере у него больше нет.
    $files = [
        'templates/widgets/photo_slider.php',
        // Поля блока «Слайдер» рисует схема — список пропорций объявлен там,
        // а шаблон блока читает уже проверенное значение.
        'app/Core/BlockData/BlockFieldSchema.php',
        'app/Views/admin/widgets/form.php',
        'app/Controllers/Admin/WidgetController.php',
    ];
    foreach ($files as $file) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $file);
        assert_contains('SliderRatio', $src, 'свой список пропорций в ' . $file);
        // «21-9» встречается только в перечнях карусели: у плитки
        // «Медиагалереи» свой набор (16-9, 4-3, 1-1) и свои правила CSS —
        // это другой блок, сводить их вместе незачем.
        assert_true(
            !str_contains($src, "'21-9'"),
            'в ' . $file . ' остался свой перечень пропорций карусели'
        );
    }

    // Шаблон блока своего списка тоже не заводит: пропорция приезжает готовой.
    $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/slider.php');
    assert_true(!str_contains($template, "'21-9'"), 'в шаблоне слайдера остался свой перечень пропорций');
    assert_contains('block-slider--ratio-', $template, 'ключ пропорции уходит в класс');
});
