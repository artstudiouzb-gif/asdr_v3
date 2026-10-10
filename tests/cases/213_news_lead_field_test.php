<?php

declare(strict_types=1);

use App\Core\NewsLead;
use App\Core\TelegramRichMessage;

test('Форматированный лид очищается и одновременно даёт обычный текст', function () {
    $lead = NewsLead::fromInput(
        '<p onclick="bad()"><strong>Главный факт</strong> и <em>деталь</em>.</p>'
        . '<script>alert(1)</script><ul><li>Пункт</li></ul>'
        . '<p><a href="javascript:alert(2)">опасная ссылка</a></p>',
        null,
        'ru'
    );

    assert_contains('<strong>Главный факт</strong>', (string) $lead['html']);
    assert_contains('<em>деталь</em>', (string) $lead['html']);
    assert_contains('<ul><li>Пункт</li></ul>', (string) $lead['html']);
    assert_not_contains('<script', (string) $lead['html']);
    assert_not_contains('onclick', (string) $lead['html']);
    assert_not_contains('javascript:', (string) $lead['html']);
    assert_same('Главный факт и деталь. Пункт опасная ссылка', $lead['text']);
});

test('Старый обычный лид продолжает отображаться без миграции содержимого', function () {
    $html = NewsLead::html(null, "Первый абзац.\n\nВторой <текст>.");

    assert_contains('<p>Первый абзац.</p>', $html);
    assert_contains('<p>Второй &lt;текст&gt;.</p>', $html);
});

test('Форма даёт компактный редактор и три честных предпросмотра', function () {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    $editor = (string) file_get_contents(APP_ROOT . '/public/assets/js/vendor/editor.js');
    $admin = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains('name="lead_html"', $form);
    assert_contains('data-lead-editor', $form);
    assert_contains('Рекомендуемая длина — 180–360', $form);
    assert_contains('жёсткого лимита нет', $form);
    assert_contains('data-lead-preview-tab="card"', $form);
    assert_contains('data-lead-preview-tab="telegram"', $form);
    assert_contains('data-lead-preview-tab="seo"', $form);
    assert_contains('7. Предпросмотр публикации', $form);
    assert_true(
        strpos($form, 'data-lead-previews') > strpos($form, '6. SEO Оптимизация'),
        'предпросмотр расположен после заполнения текста и SEO'
    );
    assert_contains("textarea.hasAttribute('data-lead-editor')", $editor);
    assert_contains("len < 180", $admin);
    assert_contains("len > 360", $admin);
    assert_contains("text.length > 160", $admin, 'SEO остаётся отдельным кратким предпросмотром');
    assert_contains('plainTextFromLeadMarkup', $admin);
    assert_not_contains('new DOMParser()', $admin, 'пользовательский текст не должен повторно интерпретироваться как HTML');
});

test('Полный лид хранится, а карточки используют уместную для макета плотность', function () {
    // Хранится лид целиком, а режет его каждый макет по своей плотности:
    // карточка последних новостей до 180 знаков, крупная подборки — до 260.
    $longLead = trim(str_repeat('Слово лида для проверки длины. ', 20));
    $excerptOf = static function (string $html, string $class): string {
        preg_match('~<span class="' . $class . '">(.*?)</span>~s', $html, $m);

        return html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES);
    };
    $rows = array_map(
        static fn (array $r): array => ['url' => '/news/' . $r['slug'], 'title' => $r['title'], 'cover' => $r['image'],
            'image' => $r['image'], 'published_at' => $r['published_at'], 'excerpt' => $longLead],
        sample_news_rows(3)
    );
    $latest = $excerptOf(render_view('templates/blocks/news_latest.php', ['blockId' => 1, 'data' => ['news' => $rows]]), 'news-card__excerpt');
    $feature = $excerptOf(render_view('templates/blocks/news_feature.php', ['data' => [
        'title' => '', 'all_text' => '', 'all_url' => '', 'variant' => 'cards', 'news' => $rows,
    ]]), 'newslist-lead__excerpt');
    assert_true(mb_strlen($latest) > 120 && mb_strlen($latest) <= 181, 'карточка последних новостей — до 180 знаков: ' . mb_strlen($latest));
    assert_true(mb_strlen($feature) > 180 && mb_strlen($feature) <= 261, 'крупная карточка подборки — до 260 знаков: ' . mb_strlen($feature));
    assert_true(mb_strlen($longLead) > 261, 'полный лид длиннее обоих пределов');

    // Лента проверяется готовой разметкой: 14 карточек — один цикл ритма.
    $listing = render_view('app/Views/site/_news_list.php', ['items' => sample_news_rows(14), 'page' => 1, 'pages' => 1, 'category' => '']);
    preg_match_all('~<a class="relnews-card relnews-card--(\w+)".*?</a>~s', $listing, $cards, PREG_SET_ORDER);
    assert_same(14, count($cards));

    assert_not_contains('newslist-lead__excerpt', $listing, 'лента /news не дублирует лид описанием');
    // Анонс есть у обоих крупных видов ритма — в компактную карточку он не
    // помещается, а обрезанный до строки ничего не сообщает. Класс карточки —
    // это её слот из ритма (App\Core\NewsFeedRhythm::slot()).
    $slots = [];
    foreach ($cards as $card) {
        $slots[] = $card[1];
        assert_same($card[1] !== 'compact', str_contains($card[0], 'Анонс новости'), 'анонс — только у крупных карточек: ' . $card[1]);
    }
    assert_same(1, count(array_keys($slots, 'hero', true)), 'обложка одна на цикл');
    assert_same(1, count(array_keys($slots, 'wide', true)), 'широкая карточка отличается от обложки');
    // «Читать подробнее» в ленте нет вовсе: карточка сама является ссылкой,
    // диктору надпись была скрыта (aria-hidden), то есть не сообщала ничего и
    // ему, а на четырнадцати карточках страницы рисовала лишнюю строку.
    assert_not_contains('card-more', $listing, 'карточка сама является ссылкой');
});

test('Сайт и Telegram получают одинаковую безопасную разметку лида', function () {
    $rich = '<p><strong>Важное</strong> и <u>подчёркнутое</u>.</p><blockquote>Цитата</blockquote>';
    $doc = TelegramRichMessage::build(
        [[
            'code' => 'ru', 'label' => 'Русский', 'title' => 'Заголовок',
            'excerpt' => 'Важное и подчёркнутое. Цитата', 'lead_html' => $rich,
            'link' => '', 'read_more' => '',
        ]],
        []
    );
    $site = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    assert_contains($rich, $doc['html']);
    assert_contains('NewsLead::html', $site);
    assert_contains('news-lead-rich', $site);
});

test('Схема и модели сохраняют HTML лида отдельно от excerpt', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_02_news_rich_lead.sql');
    $newsModel = (string) file_get_contents(APP_ROOT . '/app/Models/News.php');
    $translationModel = (string) file_get_contents(APP_ROOT . '/app/Models/NewsTranslation.php');

    assert_contains('lead_html       LONGTEXT NULL', $schema);
    assert_contains("TABLE_NAME = 'news' AND COLUMN_NAME = 'lead_html'", $migration);
    assert_contains("TABLE_NAME = 'news_translations' AND COLUMN_NAME = 'lead_html'", $migration);
    assert_contains(':lead_html', $newsModel);
    assert_contains(':lead_html', $translationModel);
});
