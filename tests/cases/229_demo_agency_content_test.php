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
    // Страницу директора теперь целиком даёт фикстура Агентства.
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
    // Пустой лид у страниц с «Профилем персоны» обязателен: иначе к заголовку
    // блока добавляется второй h1 из шапки страницы.
    foreach (['meta_title', 'meta_description', 'lead'] as $key) {
        assert_contains("array_key_exists('" . $key . "', \$data)", $seeder, 'лид/мета игнорируются: ' . $key);
    }

    $fixture = require APP_ROOT . '/database/content/agency_content.php';
    foreach ($fixture['pages'] as $slug => $langData) {
        foreach ($langData as $lang => $page) {
            $hasProfile = false;
            foreach ($page['blocks'] as $block) {
                $hasProfile = $hasProfile || $block[0] === 'person_profile';
            }
            if ($hasProfile) {
                assert_same('', trim((string) $page['lead']), "лид должен быть пустым: {$slug} [{$lang}]");
            }
        }
    }
});

test('Демо-меню: пункты руководства ведут на существующие страницы', function () {
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    $fixture = require APP_ROOT . '/database/content/agency_content.php';

    foreach (['o-nas', 'rukovodstvo', 'direktor', 'pervyy-zamestitel-direktora'] as $slug) {
        assert_true(isset($fixture['pages'][$slug]), 'нет страницы в фикстуре: ' . $slug);
        assert_contains("'page', '" . $slug . "'", $seeder, 'нет пункта меню: ' . $slug);
    }
});
