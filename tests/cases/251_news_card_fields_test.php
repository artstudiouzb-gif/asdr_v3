<?php

declare(strict_types=1);

use App\Core\NewsCard;
use App\Models\News;

// Поля обложки-карточки: своя строка заголовка, вторая плашка, показатели,
// подпись и сноска. Это оформление обложки, а не содержание новости, поэтому
// настоящий заголовок остаётся нетронутым — он уходит в ленту, RSS и поиск.

test('NewsCard: сборка полей из формы, границы и мусор', function () {
    $fields = NewsCard::fromInput([
        'card_title' => "  Стратегия *развития*  \n  региона  ",
        'card_badge' => ' Стратегический документ ',
        'card_signature' => ' Пресс-служба ',
        'card_note' => ' asdr.uz ',
        'card_stats' => [
            ['value' => ' 4 ', 'label' => ' направления '],
            ['value' => '34', 'label' => 'показателя'],
            ['value' => '', 'label' => ''],
            ['value' => '7', 'label' => 'дней в неделю'],
            ['value' => '99', 'label' => 'лишний, четвёртый'],
        ],
    ]);

    // Перенос строки в однострочном поле схлопывается: он приезжает вставкой
    // из документа и ломает раскладку карточки.
    assert_same('Стратегия *развития* региона', $fields['card_title']);
    assert_same('Стратегический документ', $fields['card_badge']);
    assert_same('Пресс-служба', $fields['card_signature']);
    assert_same('asdr.uz', $fields['card_note']);

    // Пустая пара выбрасывается, больше трёх показателей не берём.
    $stats = NewsCard::stats($fields['card_stats']);
    assert_same(NewsCard::MAX_STATS, count($stats));
    assert_same('дней в неделю', $stats[2]['label'], 'лишний четвёртый показатель не берётся');
    assert_same('4', $stats[0]['value']);
    assert_same('направления', $stats[0]['label']);

    // Пусто — пустая строка, а не «[]»: в колонке должно быть NULL-подобное
    // значение, иначе «есть ли показатели» приходится решать разбором JSON.
    assert_same('', NewsCard::fromInput([])['card_stats']);
    assert_same([], NewsCard::stats(''));
    assert_same([], NewsCard::stats('не json'));

    // Массив вместо строки не должен ронять сборку.
    assert_same('', NewsCard::fromInput(['card_title' => ['x']])['card_title']);
    assert_true(NewsCard::isEmpty([]));
    assert_true(!NewsCard::isEmpty(['card_note' => 'x']));
    assert_true(!NewsCard::isEmpty(['card_stats' => '[{"value":"4","label":"шт"}]']));
});

test('Поля обложки переводятся вместе с новостью', function () {
    // Иначе на узбекской версии карточка осталась бы с русскими словами.
    foreach (NewsCard::FIELDS as $field) {
        assert_contains(
            $field,
            (string) file_get_contents(APP_ROOT . '/app/Models/NewsTranslation.php'),
            'поле обложки не сохраняется в перевод: ' . $field
        );
    }

    // Наложение перевода идёт общим списком полей — новое поле обязано быть
    // в нём, иначе перевод сохранится, а на странице останется русский.
    assert_contains(
        'NewsCard::FIELDS',
        (string) file_get_contents(APP_ROOT . '/app/Models/News.php'),
        'поля обложки не участвуют в наложении перевода'
    );
    assert_true(class_exists(News::class));

    // Схема и миграция обязаны совпадать — иначе падает тест консистентности,
    // но здесь проверяем прицельно: обе таблицы получили все пять колонок.
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    foreach (NewsCard::FIELDS as $field) {
        assert_true(
            substr_count($schema, $field . ' ') >= 2,
            'колонка есть не в обеих таблицах: ' . $field
        );
    }
});

test('Карточка рисует поля обложки только у своего макета', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    // Всё завязано на $isCard: у «Премиум» тот же hero, и поля обложки там
    // появляться не должны.
    foreach (['card_badge', 'card_stats', 'card_signature', 'card_note'] as $field) {
        assert_contains('$isCard', $view);
        assert_contains($field, $view);
    }

    // Заголовок обложки — единственное место, где работают звёздочки: в
    // настоящем заголовке они протекли бы в RSS, Telegram и sitemap.
    assert_contains('TitleMarkup::html($cardTitle)', $view);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');
    foreach (['__tag', '__stats', '__stat-value', '__signature', '__note'] as $part) {
        assert_contains('.newsdetail-cover' . $part, $css);
    }
    // Подпись берёт рукописную роль из «Дизайна», а не жёсткий шрифт.
    assert_contains('var(--font-script', $css);
});
