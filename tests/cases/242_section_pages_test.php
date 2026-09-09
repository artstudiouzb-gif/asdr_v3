<?php

declare(strict_types=1);

use App\Core\SectionPage;
use App\Models\Page;

// Страница-раздел: обычная страница отдаёт лентам `/news` и `/projects`
// заголовок, лид, SEO и блоки под списком. Раньше эти строки жили в шаблонах
// и в админке не редактировались.

test('Страница-раздел: одна на язык, находится по разделу (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    $first = Page::create([
        'title' => 'Пресс-центр', 'slug' => 'sec-a-' . $suffix, 'section' => 'news',
        'lead' => 'Официальные сообщения', 'status' => 'published', 'lang' => 'ru',
    ]);
    assert_same('Пресс-центр', (string) Page::forSection('news', 'ru')['title']);

    // Вторая страница того же раздела забирает роль себе: иначе на сайте
    // выигрывала бы случайная запись, а редактор об этом не узнал бы.
    $second = Page::create([
        'title' => 'Новости и аналитика', 'slug' => 'sec-b-' . $suffix, 'section' => 'news',
        'status' => 'published', 'lang' => 'ru',
    ]);
    assert_same('Новости и аналитика', (string) Page::forSection('news', 'ru')['title']);
    assert_same('', (string) Page::findById($first)['section'], 'у прежней страницы роль снята');

    // Черновик разделом не управляет: на сайте его нет.
    Page::update($second, [
        'title' => 'Новости и аналитика', 'slug' => 'sec-b-' . $suffix, 'section' => 'news',
        'status' => 'draft', 'lang' => 'ru',
    ]);
    assert_true(Page::forSection('news', 'ru') === null, 'черновик разделом не управляет');

    // Чужой ключ раздела — обычная страница.
    Page::update($second, [
        'title' => 'Новости и аналитика', 'slug' => 'sec-b-' . $suffix, 'section' => 'мусор',
        'status' => 'published', 'lang' => 'ru',
    ]);
    assert_same('', (string) Page::findById($second)['section']);
    assert_true(Page::forSection('нет-такого', 'ru') === null);

    $pdo->exec('DELETE FROM pages WHERE id IN (' . (int) $first . ',' . (int) $second . ')');
});

test('Страница-раздел отдаёт заголовок, SEO и блоки под списком (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    $id = Page::create([
        'title' => 'Проекты Агентства', 'slug' => 'sec-p-' . $suffix, 'section' => 'projects',
        'lead' => 'Что мы делаем', 'meta_description' => 'Описание для поиска',
        'status' => 'published', 'lang' => 'ru',
    ]);
    $pdo->prepare('INSERT INTO blocks (page_id, type, data, sort_order, is_active, lang) VALUES (?,?,?,1,1,?)')
        ->execute([$id, 'text', json_encode(['content' => 'Текст под списком'], JSON_UNESCAPED_UNICODE), 'ru']);

    $section = SectionPage::render('projects', 'ru');
    assert_same('Проекты Агентства', $section['title']);
    assert_same('Что мы делаем', $section['lead']);
    assert_same('Описание для поиска', $section['meta_description']);
    assert_contains('Текст под списком', $section['content']);

    // Раздела нет — вью остаётся на строках из шаблона.
    assert_true(SectionPage::render('news', 'ru') === null);

    // Вьюхи выводят блоки под списком, а не над ним.
    foreach (['news_index.php', 'projects_index.php'] as $file) {
        $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/' . $file);
        assert_contains('listing__section-blocks', $view);
        assert_true(
            strpos($view, 'listing__section-blocks') > strpos($view, 'listing__title'),
            $file . ': блоки раздела должны идти после шапки и списка'
        );
    }

    $pdo->exec('DELETE FROM blocks WHERE page_id = ' . (int) $id);
    $pdo->exec('DELETE FROM pages WHERE id = ' . (int) $id);
});

test('Страница-раздел: язык берётся из группы перевода, как у главной (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    // Русская запись с ролью и её узбекская версия — отдельной записью со
    // своим заголовком и своими блоками (механизм Б, как у всех страниц).
    $ru = Page::create([
        'title' => 'Пресс-центр', 'slug' => 'sec-g-' . $suffix, 'section' => 'news',
        'lead' => 'Официальные сообщения', 'status' => 'published', 'lang' => 'ru',
    ]);
    $uz = Page::create([
        'title' => 'Matbuot markazi', 'slug' => 'sec-g-' . $suffix, 'section' => '',
        'lead' => 'Rasmiy xabarlar', 'status' => 'published', 'lang' => 'uz',
    ]);
    $pdo->prepare('UPDATE pages SET translation_group_id = :gid WHERE id IN (:ru, :uz)')
        ->execute([':gid' => $ru, ':ru' => $ru, ':uz' => $uz]);

    // Роль назначена только русской записи. Прежняя реализация искала запись с
    // заполненной колонкой section, не находила её на узбекском и откатывалась
    // на page_translations — на узбекской ленте выходил русский заголовок при
    // живой узбекской странице.
    assert_same('Пресс-центр', (string) Page::forSection('news', 'ru')['title']);
    $uzHead = Page::forSection('news', 'uz');
    assert_same('Matbuot markazi', (string) $uzHead['title'], 'узбекская лента берёт узбекскую запись');
    assert_same($uz, (int) $uzHead['id'], 'блоки должны браться у записи своего языка');
    assert_same('news', (string) $uzHead['section'], 'роль принадлежит группе, а не одной записи');

    // Обе записи группы считаются шапкой раздела — от этого зависит редирект
    // со своего адреса.
    assert_same('news', Page::sectionOf(Page::findById($ru), 'ru'));
    assert_same('news', Page::sectionOf(Page::findById($uz), 'uz'));
    assert_contains('/news', Page::sectionUrl('news', 'ru'));
    assert_same('', Page::sectionUrl('нет-такого', 'ru'));

    $pdo->exec('DELETE FROM pages WHERE id IN (' . (int) $ru . ',' . (int) $uz . ')');
});

test('Страница-раздел: свой адрес отдаёт 301 на раздел, как у главной', function () {
    // Заголовок, лид и блоки этой страницы выводятся на /news, и без редиректа
    // получались два адреса с одинаковым содержимым и canonical на самих себя.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/PageController.php');
    assert_contains('Page::sectionOf($page, $lang)', $controller, 'контроллер спрашивает раздел с учётом группы');
    assert_contains('Page::sectionUrl($pageSection, $lang)', $controller);
    assert_true(
        preg_match('/sectionUrl\(\$pageSection, \$lang\), true, 301\)/', $controller) === 1,
        'редирект должен быть постоянным (301), иначе поиск оставит оба адреса'
    );

    // Обычная страница остаётся на своём адресе: раздел ищется по группе, а не
    // по факту «у страницы вообще есть блоки».
    assert_same('', Page::sectionOf(['id' => 0, 'section' => '']));
    assert_same('news', Page::sectionOf(['id' => 0, 'section' => 'news']));
});

test('Переключатель языков показывает короткое название, а не код', function () {
    // Заполненное поле берётся как есть.
    assert_same('Рус', \App\Models\Language::shortLabel(['code' => 'ru', 'name' => 'Русский', 'short_name' => 'Рус']));
    // Пусто — первые буквы названия: «Eng», а не «EN».
    assert_same('Eng', \App\Models\Language::shortLabel(['code' => 'en', 'name' => 'English', 'short_name' => '']));
    assert_same('Oʻz', \App\Models\Language::shortLabel(['code' => 'uz', 'name' => 'Oʻzbekcha']));
    // Названия нет вовсе — остаётся код, иначе метка была бы пустой.
    assert_same('KK', \App\Models\Language::shortLabel(['code' => 'kk', 'name' => '']));

    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    assert_contains('Language::shortLabel($l)', $header);
    assert_not_contains("'short' => strtoupper(\$code)", $header, 'код языка вместо названия не возвращаем');
});

test('Тёмная тема: одна гамма у базы и темы, ступени различимы', function () {
    $base = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    // Нейтрально-серая база рядом с синими карточками темы и давала «грязь».
    assert_not_contains('--bg-primary: #14161c', $base, 'у базы и темы должна быть одна тёмная гамма');
    assert_contains('--bg-primary: #1a2230', $base);
    assert_contains('--gov-bg: #1a2230', $theme);
    assert_contains('--bg-surface: #273143', $base);
    assert_contains('--gov-surface: #273143', $theme);

    // Альтернативная подложка отличалась от карточки только тоном (1.01:1).
    assert_not_contains('--gov-bg-alt: #1c2942', $theme);

    $lum = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $channel = static function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((float) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((float) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((float) hexdec(substr($hex, 4, 2)));
    };
    $ratio = static function (string $a, string $b) use ($lum): float {
        $la = $lum($a);
        $lb = $lum($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    };

    // Мелкий текст на всех тёмных уровнях остаётся читаемым.
    foreach (['#1a2230', '#273143', '#303c50'] as $background) {
        assert_true(
            $ratio('#adb8c7', $background) >= 4.5,
            'приглушённый текст на ' . $background . ': ' . round($ratio('#adb8c7', $background), 2) . ':1'
        );
    }
    // Уровни различаются светлотой, а не только тоном.
    assert_true($ratio('#273143', '#303c50') >= 1.1, 'подложка не отличается от карточки');
});
