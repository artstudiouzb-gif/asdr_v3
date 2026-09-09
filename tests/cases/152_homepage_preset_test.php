<?php

declare(strict_types=1);

use App\Core\PagePresets;

test('Готовые сборки: сборка «Главная страница» существует и содержит нужные блоки', function (): void {
    $all = PagePresets::all();
    assert_true(isset($all['home']), 'Сборка «home» присутствует в общем списке');

    $home = PagePresets::find('home');
    assert_true($home !== null, 'Сборка «home» находится по ключу');
    assert_same('Главная страница (Демо-макет)', $home['name'], 'Правильное имя сборки');
    assert_true(count($home['blocks']) >= 5, 'Содержит 5 и более блоков');

    $types = array_column($home['blocks'], 'type');
    assert_true(in_array('hero', $types, true), 'Содержит баннер Hero');
    assert_true(in_array('counters', $types, true), 'Содержит блок счётчиков');
    assert_true(in_array('news_feature', $types, true), 'Содержит блок новостей');
    assert_true(in_array('cards_grid', $types, true), 'Содержит блок приоритетных направлений');
    assert_true(in_array('media_gallery', $types, true), 'Содержит медиагалерею');
});
