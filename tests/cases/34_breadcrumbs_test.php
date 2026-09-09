<?php

declare(strict_types=1);

use App\Core\View;

test('Хлебные крошки публичных шаблонов переводят Главную', function () {
    $views = [
        'news_show.php',
        'project_show.php',
        'content_show.php',
        'content_index.php',
        'calendar.php',
    ];

    foreach ($views as $view) {
        $source = file_get_contents(APP_ROOT . '/app/Views/site/' . $view);
        assert_true($source !== false, 'шаблон доступен: ' . $view);
        assert_contains("t('Главная')", (string) $source);
        assert_not_contains("['label' => 'Главная'", (string) $source);
    }
});

test('Ведущая новость на главной — цельная плитка с текстом на обложке', function () {
    $css = theme_css();
    assert_true($css !== false, 'CSS гос-темы доступен');
    // Текст лежит на обложке: рамка позиционирует затемняющую подложку.
    assert_contains('.newsfeat-lead__frame { position: relative;', (string) $css);
    assert_contains('.newsfeat-lead__over {', (string) $css);
    // Плитка тянется на высоту правой колонки — колонки заканчиваются вровень.
    assert_contains('.newsfeat-grid { align-items: stretch; }', (string) $css);
});

test('Метка на карточке новости выводится только когда заполнена', function () {
    $tpl = (string) file_get_contents(APP_ROOT . '/templates/blocks/news_feature.php');
    // Одинаковая метка у всех карточек — шум, а не рубрикация: фолбэка быть не должно.
    assert_not_contains("t('Новость')", $tpl);
    assert_contains('NewsBadge::render', $tpl, 'метка рендерится общим помощником');

    // Пустая метка не должна оставлять на карточке пустую плашку.
    assert_same('', \App\Core\NewsBadge::render('', '#c0392b'));
    assert_same('', \App\Core\NewsBadge::render('   '));
    assert_contains('news-badge', \App\Core\NewsBadge::render('Важно'));
});

test('Метка детальной новости: углом на фото, а в строке — только без фото', function () {
    $tpl = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    // В строке над заголовком метка была самым ярким элементом и спорила с
    // самим заголовком. Она уезжает на медиа, но исчезнуть не должна:
    // у новости без фото ей больше негде быть.
    assert_true(
        preg_match('/\$badgeInline\s*=\s*\$hasMedia\s*\?\s*\'\'\s*:/s', $tpl) === 1,
        'в строке метка остаётся только когда медиа нет'
    );
    assert_true(
        preg_match('/\$badgeOnMedia\s*=\s*\$hasMedia\s*\?\s*\\\\App\\\\Core\\\\NewsBadge::renderOverlay/s', $tpl) === 1,
        'при наличии медиа метка рендерится накладкой'
    );
    // Обе ветки медиа — галерея и видео — должны получить метку.
    assert_same(2, substr_count($tpl, '$badgeOnMedia ?>'), 'метка выводится и в галерее, и в видео');

    // Накладка обязана быть непрозрачной и лежать поверх снимка: полупрозрачная
    // плашка на светлом фото нечитаема, а фото редактор выбирает любое.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    assert_true(
        preg_match('/\.news-badge--overlay\s*\{[^}]*position:\s*absolute/', $css) === 1,
        'накладка позиционируется поверх медиа'
    );
    assert_contains('news-badge--overlay', \App\Core\NewsBadge::renderOverlay('Важно'));
    assert_same('', \App\Core\NewsBadge::renderOverlay('  '), 'пустая метка не оставляет плашку на фото');
});

test('Цвет метки: нормализация, контрастный текст и безопасное значение', function () {
    assert_same('#c0392b', \App\Core\NewsBadge::normalizeColor('#C0392B'));
    assert_same('#aabbcc', \App\Core\NewsBadge::normalizeColor('abc'), 'короткая запись разворачивается');
    // Мусор не попадает в разметку: пустой цвет = значение из темы.
    assert_same('', \App\Core\NewsBadge::normalizeColor('red; background:url(x)'));
    assert_same('', \App\Core\NewsBadge::normalizeColor(''));
    assert_same('', \App\Core\NewsBadge::styleAttr('не цвет'));

    // Цвет текста считается, а не задаётся: на тёмном фоне светлый, на светлом тёмный.
    assert_same('#ffffff', \App\Core\NewsBadge::textColor('#0b1a30'));
    assert_same('#0b1a30', \App\Core\NewsBadge::textColor('#ffe066'));

    $attr = \App\Core\NewsBadge::styleAttr('#0b1a30');
    assert_contains('--news-badge-bg:#0b1a30', $attr);
    assert_contains('--news-badge-fg:#ffffff', $attr);
});

test('Публичные календарь и альбомы не содержат непереведённых основных подписей', function () {
    $calendar = (string) file_get_contents(APP_ROOT . '/app/Views/site/calendar.php');
    $albums = (string) file_get_contents(APP_ROOT . '/app/Views/site/albums.php');
    assert_contains("t('Календарь мероприятий')", $calendar);
    assert_contains("t('Предыдущий месяц')", $calendar);
    assert_contains("t('Фотоальбомы')", $albums);
    assert_not_contains('>Главная</a>', $albums);
});

test('Хлебные крошки: рендерит навигацию со ссылками, последний — текст', function () {
    $html = View::renderPartial('site/_crumbs', [
        'crumbs' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Section', 'url' => '/section'],
            ['label' => 'Current'],
        ],
    ]);
    assert_contains('content-crumbs', $html);
    assert_contains('href="/"', $html);
    assert_contains('href="/section"', $html);
    assert_contains('<span>Current</span>', $html);
    // Текущий элемент не должен быть ссылкой.
    assert_not_contains('href="/current"', $html);
    // SEO: та же навигация в разметке Schema.org BreadcrumbList.
    assert_contains('application/ld+json', $html);
    assert_contains('BreadcrumbList', $html);
    assert_contains('"position":3', $html);
});

test('Хлебные крошки: скрываются при менее чем двух уровнях', function () {
    $html = View::renderPartial('site/_crumbs', ['crumbs' => [['label' => 'Only']]]);
    assert_not_contains('content-crumbs', $html);
    $empty = View::renderPartial('site/_crumbs', ['crumbs' => []]);
    assert_not_contains('content-crumbs', $empty);
});
