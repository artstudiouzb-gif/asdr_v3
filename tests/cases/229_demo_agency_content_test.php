<?php

declare(strict_types=1);

// Демо-пакет, который ставится сразу после установки, обязан содержать
// реальные страницы Агентства. Раньше он создавал вымышленного директора,
// а настоящий контент появлялся только после отдельного скрипта — и на свежем
// сайте висел несуществующий руководитель.

test('Демо-пакет: страницы руководства берутся из фикстуры Агентства', function () {
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');

    assert_contains("/database/content/agency_content.php", $seeder);
    // Наложение идёт после демо-страниц и прототипов, иначе демо перекроет
    // реальный контент обратно.
    $agencyAt = strpos($seeder, "'/database/content/agency_content.php'");
    $prototypeAt = strpos($seeder, 'prototype_pages.json');
    assert_true($prototypeAt !== false && $agencyAt !== false && $agencyAt > $prototypeAt, 'фикстура Агентства применяется последней');
});

test('Демо-пакет: вымышленного руководителя не осталось', function () {
    foreach (['app/Core/DemoSeeder.php', 'database/demo_assets/prototype_pages.json'] as $relative) {
        $content = (string) file_get_contents(APP_ROOT . '/' . $relative);
        assert_not_contains('Нуриддинов', $content, 'вымышленный директор в ' . $relative);
        assert_not_contains('Nuriddinov', $content, 'вымышленный директор в ' . $relative);
    }

    $prototype = json_decode((string) file_get_contents(APP_ROOT . '/database/demo_assets/prototype_pages.json'), true);
    assert_true(is_array($prototype));
    // Страницы директора нет ни в демо, ни в фикстуре: её собирает владелец.
    assert_false(isset($prototype['direktor']), 'демо-прототип не должен подменять страницу директора');
});

test('Демо-пакет: в команде реальное руководство', function () {
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    $fixture = require APP_ROOT . '/database/content/agency_content.php';

    foreach ($fixture['team'] as $member) {
        assert_contains((string) $member['name'], $seeder, 'нет в демо-команде: ' . $member['name']);
    }
});

test('Демо-пакет: мета и лид берутся из фикстуры, когда заданы', function () {
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    // Лид и мета берутся из фикстуры ровно тогда, когда ключ в ней есть:
    // пустая строка — это «очистить», а отсутствие ключа — «не трогать».
    foreach (['meta_title', 'meta_description', 'lead'] as $key) {
        assert_contains("array_key_exists('" . $key . "', \$data)", $seeder, 'лид/мета игнорируются: ' . $key);
    }
});

test('Демо-меню: пункт ведёт на страницу, которую кто-то создаёт', function () {
    // Пункт меню на несуществующий slug — это 404 сразу после установки демо,
    // и заметен он только кликом. Страницу даёт либо фикстура Агентства, либо
    // сам демо-посев; проверяем оба источника разом.
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    $fixture = require APP_ROOT . '/database/content/agency_content.php';
    $prototype = (array) json_decode(
        (string) file_get_contents(APP_ROOT . '/database/demo_assets/prototype_pages.json'),
        true
    );

    preg_match_all("/\['[^']*', 'page', '([a-z0-9-]+)'/u", $seeder, $matches);
    assert_true($matches[1] !== [], 'пункты меню не разобрались — поменялся формат');

    // Страницу демо создаёт один из трёх источников: фикстура Агентства,
    // прототипы из JSON или блоки самого посева.
    foreach (array_unique($matches[1]) as $slug) {
        $known = isset($fixture['pages'][$slug])
            || isset($prototype[$slug])
            || str_contains($seeder, "'" . $slug . "' => [");
        assert_true($known, 'пункт меню ведёт в никуда: ' . $slug);
    }
});
