<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Database;
use App\Core\Locale;

test('Блок person_cards: персона с фото и вакантная карточка', function () {
    $out = BlockRenderer::render(['id' => 40, 'type' => 'person_cards', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Руководство Агентства', 'all_text' => 'Все руководство', 'all_url' => '/o-nas',
        'items' => [
            ['photo' => '/uploads/public/d.jpg', 'name' => 'Элёр Ганиев', 'role' => 'Директор Агентства', 'url' => '/rukovoditel'],
            ['photo' => '', 'name' => '', 'role' => 'Заместитель директора', 'url' => ''],
        ],
    ])])['html'];
    assert_contains('cms-block--person_cards', $out);
    assert_contains('Элёр Ганиев', $out);
    assert_contains('person-card--vacant', $out);
    assert_contains('person-card__vacant', $out);
    assert_contains('href="/rukovoditel"', $out);
});

test('Блок timeline: события, кнопка и CTA-карточка', function () {
    $rendered = BlockRenderer::render(['id' => 41, 'type' => 'timeline', 'custom_css' => null, 'data' => json_encode([
        'title' => 'История Агентства',
        'description' => '<p>Как развивалась организация.</p>',
        'items' => [
            ['year' => '2017', 'text' => 'Создан центр', 'status' => 'done'],
            ['year' => '2023+', 'text' => 'Расширение функций', 'status' => 'active'],
        ],
        'button_text' => 'Вся история', 'button_url' => '/o-nas',
        'cta_title' => 'Работаем для целей развития', 'cta_text' => 'Экспертиза и данные',
        'cta_button_text' => 'Стратегия', 'cta_button_url' => '/strategy', 'cta_image' => '/uploads/public/t.jpg',
    ])]);
    $out = $rendered['html'] . "\n" . $rendered['css'];
    assert_contains('block-timeline--with-cta', $out);
    assert_contains('timeline-item__year', $out);
    assert_contains('timeline-item--done timeline-item--next-active', $out);
    assert_contains('timeline-item--active', $out);
    assert_contains('2023+', $out);
    assert_contains('timeline-cta__title', $out);
    assert_contains('block-timeline__description', $out);
    assert_contains('Как развивалась организация.', $out);
    assert_contains("url('/uploads/public/t.jpg')", $out);
    assert_not_contains(' style="', $rendered['html']);

    // Без CTA-заголовка карточка не выводится.
    $solo = BlockRenderer::render(['id' => 42, 'type' => 'timeline', 'custom_css' => null, 'data' => json_encode([
        'title' => 'История', 'items' => [['year' => '2017', 'text' => 'x']],
    ])])['html'];
    assert_true(!str_contains($solo, 'timeline-cta'), 'CTA-карточка скрыта без заголовка');
    assert_contains('timeline-item--active', $solo);
});

test('CTA-полоса: заголовок, текст, кнопка; иконка по умолчанию', function () {
    $out = BlockRenderer::render(['id' => 43, 'type' => 'cta', 'custom_css' => null, 'data' => json_encode([
        'variant' => 'band', 'title' => 'Есть вопросы или предложения?', 'text' => 'Свяжитесь с нами',
        'button_text' => 'Связаться с нами', 'button_url' => '/kontakty',
    ])])['html'];
    assert_contains('block-ctaband', $out);
    assert_contains('ctaband__icon', $out);
    assert_contains('href="/kontakty"', $out);
});

test('Блок person_profile: фото, контакты, кнопка', function () {
    $out = BlockRenderer::render(['id' => 44, 'type' => 'person_profile', 'custom_css' => null, 'data' => json_encode([
        'photo' => '/uploads/public/dir.jpg', 'name' => 'Элёр Ганиев',
        'position' => 'Директор Агентства', 'text' => 'Руководит деятельностью.',
        'phone' => '+998 71 203 10 00', 'email' => 'info@strategy.uz',
        'button_text' => 'Обратиться', 'button_url' => '/kontakty',
    ])])['html'];
    assert_contains('block-profile', $out);
    assert_contains('tel:+998712031000', $out);
    assert_contains('mailto:info@strategy.uz', $out);
    assert_contains('profile__button', $out);
});

test('Преимущества-полоса: элементы с иконками', function () {
    $out = BlockRenderer::render(['id' => 45, 'type' => 'advantages', 'custom_css' => null, 'data' => json_encode([
        'variant' => 'band', 'title' => 'Преимущества', 'description' => '<p>Почему это удобно.</p>', 'items' => [
            ['icon_svg' => 'target-arrow', 'title' => 'Стратегическое управление', 'text' => 'Определение приоритетов'],
            ['icon_svg' => '', 'title' => 'Координация реформ', 'text' => ''],
        ],
    ])])['html'];
    assert_contains('featband__item', $out);
    assert_contains('block-featband__description', $out);
    assert_contains('Почему это удобно.', $out);
    assert_contains('Стратегическое управление', $out);
});

test('Блок bio_education: карьера, образование, доп. список и цитата', function () {
    $out = BlockRenderer::render(['id' => 46, 'type' => 'bio_education', 'custom_css' => null, 'data' => json_encode([
        'bio_title' => 'Биография', 'bio_text' => 'Более 15 лет опыта.',
        'career' => [['years' => '2023 – н.в.', 'text' => 'Директор Агентства']],
        'edu_title' => 'Образование',
        'edu_items' => [['years' => '2011 – 2013', 'title' => 'Магистр (MPA)', 'org' => 'NUS, Сингапур']],
        'extra_title' => 'Дополнительное образование', 'extra_text' => "Executive Program\nCertificate in Strategic Leadership",
        'quote_text' => 'Наша цель — устойчивая экономика.', 'quote_author' => 'Элёр Ганиев',
    ])])['html'];
    assert_contains('bio-career__years', $out);
    assert_contains('bio-edu__degree', $out);
    assert_contains('<li>Executive Program</li>', $out);
    assert_contains('bio-quote__text', $out);
    assert_contains('Элёр Ганиев', $out);
});

test('Блок bio_education: выбранные виджеты выводятся над и под образованием', function () {
    ensure_test_db();
    Locale::set('ru');
    $pdo = Database::pdo();

    $insert = $pdo->prepare(
        "INSERT INTO widgets (sidebar, type, title, lang, data, sort_order, is_active, created_at)
         VALUES ('right', 'custom_html', :title, :lang, :data, :sort_order, :is_active, NOW())"
    );
    $ids = [];
    foreach ([
        ['До образования', '', '{"html":"<p>WIDGET-BEFORE</p><script>window.__bioWidget=1;<\\/script>"}', 951, 1],
        ['После образования', 'ru', '{"html":"<p>WIDGET-AFTER</p>"}', 952, 1],
        ['Только UZ', 'uz', '{"html":"<p>WIDGET-UZ</p>"}', 953, 1],
        ['Выключенный', '', '{"html":"<p>WIDGET-OFF</p>"}', 954, 0],
    ] as [$title, $lang, $data, $order, $active]) {
        $insert->execute([
            ':title' => $title,
            ':lang' => $lang,
            ':data' => $data,
            ':sort_order' => $order,
            ':is_active' => $active,
        ]);
        $ids[] = (int) $pdo->lastInsertId();
    }

    try {
        $out = BlockRenderer::render(['id' => 146, 'type' => 'bio_education', 'custom_css' => null, 'data' => json_encode([
            'bio_title' => 'Биография',
            'bio_text' => 'Текст биографии.',
            'edu_title' => 'Образование',
            'edu_items' => [['years' => '2010', 'title' => 'Магистр', 'org' => 'Университет']],
            'widgets_before' => [$ids[0], $ids[2], $ids[3]],
            'widgets_after' => [$ids[1]],
            'quote_text' => 'Цитата после виджетов.',
        ], JSON_UNESCAPED_UNICODE)])['html'];

        assert_contains('bio-side__widgets--before', $out);
        assert_contains('bio-side__widgets--after', $out);
        assert_contains('WIDGET-BEFORE', $out);
        assert_contains('WIDGET-AFTER', $out);
        assert_not_contains('WIDGET-UZ', $out, 'виджет другого языка скрывается');
        assert_not_contains('WIDGET-OFF', $out, 'выключенный виджет скрывается');
        assert_contains('<script nonce="', $out, 'custom_html внутри блока получает CSP nonce');

        $before = strpos($out, 'WIDGET-BEFORE');
        $education = strpos($out, 'bio-edu');
        $after = strpos($out, 'WIDGET-AFTER');
        $quote = strpos($out, 'bio-quote');
        assert_true(is_int($before) && is_int($education) && is_int($after) && is_int($quote));
        assert_true($before < $education && $education < $after && $after < $quote, 'порядок: верхние виджеты → образование → нижние виджеты → цитата');
    } finally {
        $pdo->exec('DELETE FROM widgets WHERE id IN (' . implode(',', $ids) . ')');
    }
});

test('Блок bio_education: биография разбита на абзацы, а не склеена <br><br>', function () {
    $out = BlockRenderer::render(['id' => 47, 'type' => 'bio_education', 'custom_css' => null, 'data' => json_encode([
        'bio_title' => 'Биография',
        'bio_text' => "Родился в Ташкенте.\n\nОкончил университет.\nПродолжил обучение за рубежом.\n\n\nРаботал в банке.",
    ])])['html'];

    // Пустая строка = новый абзац, одиночный перенос остаётся переносом внутри него.
    assert_same(3, substr_count($out, '<p>'), 'три абзаца биографии');
    assert_contains('<p>Родился в Ташкенте.</p>', $out);
    assert_contains('Окончил университет.<br />', $out);
    assert_not_contains('<br /><br />', $out);

    // pre-line удвоил бы переносы: абзацы приходят разметкой.
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/public-layout-polish.css');
    assert_not_contains('white-space: pre-line', $css);
    assert_contains('.bio__text p {', $css);
});

test('Блок news_docs: документы и заглушки; ссылка «Все» у документов', function () {
    ensure_test_db();
    $out = BlockRenderer::render(['id' => 47, 'type' => 'news_docs', 'custom_css' => null, 'data' => json_encode([
        'news_title' => 'Актуальные новости', 'docs_title' => 'Документы',
        'docs_all_text' => 'Все документы', 'docs_all_url' => '/docs',
        'docs' => [['title' => 'Стратегия «Узбекистан–2030»', 'meta' => 'PDF · 2.4 МБ', 'url' => '/uploads/public/s.pdf']],
    ])])['html'];
    assert_contains('cms-block--news_docs', $out);
    assert_contains('doc-card__title', $out);
    assert_contains('PDF · 2.4 МБ', $out);
    assert_contains('href="/uploads/public/s.pdf"', $out);
    assert_contains('href="/docs"', $out);
});
